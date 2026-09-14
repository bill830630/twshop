<?php
/**
 * 蝦皮串接模組：HTTP 客戶端、簽章、host 切換、token 生命週期、節流、呼叫紀錄、建表。
 *
 * 台灣蝦皮 Open API 目前只開放給商城賣家（Shopee Mall）或第三方系統供應商（ERP）申請
 * partner_id/partner_key，一般賣場帳號多半申請不過。本檔的簽章產生、token 生命週期、
 * 建表邏輯全部可用假資料離線驗證；實際打蝦皮端點要等使用者取得正式 partner key 才能實測。
 *
 * 蝦皮官方文件在登入牆後，本檔端點路徑與參數是依公開資料整理的，正式串接前務必對照官方
 * 文件再次確認每支端點的請求/回應欄位。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// 本外掛第一次使用 dbDelta() 建表，版本號獨立於外掛版本，只在資料表結構真的改變時才調整。
define( 'TWSHOP_SHOPEE_DB_VERSION', '1.0.0' );

// partner_key 是密鑰，後台輸入框已設定過時顯示這個遮罩字串而非真實金鑰；
// 儲存時若送出的仍是這個遮罩字串（使用者沒有更改），sanitize callback 會沿用舊值、不覆寫。
define( 'TWSHOP_SHOPEE_KEY_MASK', '••••••••••••' );

// =========================================================================
// Options getter（陣列型 option，各自單一入口，預設值收斂在這裡——不登記進
// twshop_get_option_defaults()，理由見 CLAUDE.md「蝦皮串接」章節）
// =========================================================================

function twshop_shopee_credentials() {
    return wp_parse_args( get_option( 'twshop_shopee_credentials', array() ), array(
        'partner_id'  => '',
        'partner_key' => '',
        'env'         => 'sandbox',
    ) );
}

function twshop_shopee_shop() {
    return wp_parse_args( get_option( 'twshop_shopee_shop', array() ), array(
        'shop_id'       => 0,
        'access_token'  => '',
        'refresh_token' => '',
        'expire_at'     => 0,
        'refreshed_at'  => 0,
        'authorized_at' => 0,
    ) );
}

function twshop_shopee_sync_settings() {
    return wp_parse_args( get_option( 'twshop_shopee_sync_settings', array() ), array(
        'stock_push_enabled'   => 'yes',
        'price_push_enabled'   => 'yes',
        'order_import_enabled' => 'yes',
        'order_import_status'  => array( 'READY_TO_SHIP', 'PROCESSED' ),
        'default_order_status' => 'processing',
        'stock_buffer'         => 0,
        'log_retention_days'   => 30,
    ) );
}

function twshop_shopee_has_credentials() {
    $creds = twshop_shopee_credentials();
    return ! empty( $creds['partner_id'] ) && ! empty( $creds['partner_key'] );
}

// =========================================================================
// 資料表：dbDelta 建表 + 版本升級校正
// =========================================================================

function twshop_shopee_items_table() {
    global $wpdb;
    return $wpdb->prefix . 'twshop_shopee_items';
}

function twshop_shopee_log_table() {
    global $wpdb;
    return $wpdb->prefix . 'twshop_shopee_log';
}

/**
 * 建表/升級表結構。掛在 register_activation_hook 與 admin_init 版本校正兩處觸發（見下方），
 * 不受模組開關限制——模組關閉只停 hook，不該連資料表都消失或停止升級。
 */
function twshop_shopee_install_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $items_table     = twshop_shopee_items_table();
    $log_table       = twshop_shopee_log_table();

    $sql = "CREATE TABLE {$items_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        shop_id BIGINT UNSIGNED NOT NULL,
        item_id BIGINT UNSIGNED NOT NULL,
        model_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sku VARCHAR(191) NOT NULL DEFAULT '',
        product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'unlinked',
        last_pushed_stock INT NULL,
        last_pushed_price DECIMAL(15,2) NULL,
        last_synced_at DATETIME NULL,
        last_error TEXT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY shop_item_model (shop_id, item_id, model_id),
        KEY product_id (product_id)
    ) {$charset_collate};";
    dbDelta( $sql );

    $sql_log = "CREATE TABLE {$log_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL,
        direction VARCHAR(10) NOT NULL,
        endpoint VARCHAR(191) NOT NULL,
        subject VARCHAR(191) NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        message TEXT NULL,
        PRIMARY KEY  (id),
        KEY created_at (created_at)
    ) {$charset_collate};";
    dbDelta( $sql_log );

    update_option( 'twshop_shopee_db_version', TWSHOP_SHOPEE_DB_VERSION );
}
register_activation_hook( TWSHOP_PLUGIN_FILE, 'twshop_shopee_install_tables' );

/**
 * 光靠 activation hook 不夠：既有站台走「外掛更新」不會重新觸發 activation hook，
 * 表結構若跟著版本升級而改變也不會套用，而且完全不會有任何錯誤訊息。這裡在每次後台
 * 請求時比對版本號，不同就重跑一次 dbDelta()（dbDelta 本身是冪等的，重覆執行安全）。
 */
function twshop_shopee_maybe_upgrade_db() {
    if ( get_option( 'twshop_shopee_db_version' ) !== TWSHOP_SHOPEE_DB_VERSION ) {
        twshop_shopee_install_tables();
    }
}
add_action( 'admin_init', 'twshop_shopee_maybe_upgrade_db' );

/**
 * 寫一筆同步紀錄。
 *
 * **`$subject` 絕對不能收授權參數**：這張表整份會顯示在後台「蝦皮串接 ▸ 同步紀錄」頁籤上。
 * 目前呼叫端（`twshop_shopee_request()`）傳的是 `wp_json_encode( $args )`，而 `$args` 是純業務
 * 參數，跟裝著 `partner_id`/`sign`/`access_token`/`shop_id` 的 `$query` 是**兩個分開的陣列**——
 * 安全性靠的就是這個分離。哪天為了 debug 方便把兩者 `array_merge()` 之後再記 log，
 * access_token 與簽章就會落進資料庫、顯示在後台頁面上，而且不會有任何警訊。
 */
function twshop_shopee_log( $direction, $endpoint, $subject, $success, $message = '' ) {
    global $wpdb;
    $wpdb->insert(
        twshop_shopee_log_table(),
        array(
            'created_at' => current_time( 'mysql' ),
            'direction'  => sanitize_text_field( $direction ),
            'endpoint'   => sanitize_text_field( $endpoint ),
            'subject'    => is_string( $subject ) ? mb_substr( $subject, 0, 191 ) : wp_json_encode( $subject ),
            'success'    => $success ? 1 : 0,
            'message'    => is_string( $message ) ? $message : wp_json_encode( $message ),
        ),
        array( '%s', '%s', '%s', '%s', '%d', '%s' )
    );
}

// =========================================================================
// 排程：cron_schedules 註冊非內建間隔
// =========================================================================

add_filter( 'cron_schedules', function ( $schedules ) {
    $schedules['twshop_shopee_5min']  = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => '每 5 分鐘（蝦皮同步）' );
    $schedules['twshop_shopee_15min'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => '每 15 分鐘（蝦皮同步）' );
    return $schedules;
} );

/**
 * token 續期排程不受模組開關控制（理由：授權回呼與續期若跟著模組開關忽開忽關，
 * refresh_token 一旦超過 30 天沒續期就得整個重新授權，且畫面上不會有任何錯誤）。
 * 比照 includes/modules/membership.php 的 twshop_activation_cron() 既有寫法：
 * activation 時排程、deactivation 時清除；函式本身內部檢查有沒有憑證，而非模組開關。
 */
register_activation_hook( TWSHOP_PLUGIN_FILE, 'twshop_shopee_activation_cron' );
function twshop_shopee_activation_cron() {
    if ( ! wp_next_scheduled( 'twshop_shopee_refresh_token' ) ) {
        wp_schedule_event( time(), 'hourly', 'twshop_shopee_refresh_token' );
    }
}
register_deactivation_hook( TWSHOP_PLUGIN_FILE, 'twshop_shopee_deactivation_cron' );
function twshop_shopee_deactivation_cron() {
    wp_clear_scheduled_hook( 'twshop_shopee_refresh_token' );
    wp_clear_scheduled_hook( 'twshop_shopee_push_queue' );
    wp_clear_scheduled_hook( 'twshop_shopee_pull_orders' );
    wp_clear_scheduled_hook( 'twshop_shopee_cleanup_log' );
}
add_action( 'twshop_shopee_refresh_token', 'twshop_shopee_maybe_refresh_token' );

/**
 * push_queue／pull_orders／cleanup_log 三支排程**受模組開關控制**，但模組開關頁是獨立
 * <form> + 手動 $_POST 處理（不走 Settings API），沒有一個統一的「儲存後」掛勾可以攔。
 * 沿用 twshop_maybe_realign_daily_cron_to_midnight() 的既有寫法：掛 admin_init，每次後台
 * 請求校正一次「模組開關現況」與「排程現況」是否一致，該排的補排、該清的清掉。
 */
function twshop_shopee_reconcile_module_cron() {
    $enabled = twshop_module_enabled( 'shopee_sync' );

    $jobs = array(
        'twshop_shopee_push_queue'  => 'twshop_shopee_5min',
        'twshop_shopee_pull_orders' => 'twshop_shopee_15min',
        'twshop_shopee_cleanup_log' => 'daily',
    );

    foreach ( $jobs as $hook => $schedule ) {
        $scheduled = wp_next_scheduled( $hook );
        if ( $enabled && ! $scheduled ) {
            wp_schedule_event( time(), $schedule, $hook );
        } elseif ( ! $enabled && $scheduled ) {
            wp_clear_scheduled_hook( $hook );
        }
    }
}
add_action( 'admin_init', 'twshop_shopee_reconcile_module_cron' );

// =========================================================================
// 簽章與節流
// =========================================================================

function twshop_shopee_host() {
    $creds = twshop_shopee_credentials();
    return ( 'live' === ( $creds['env'] ?? 'sandbox' ) )
        ? 'https://partner.shopeemobile.com'
        : 'https://partner.test-stable.shopeemobile.com';
}

/**
 * 蝦皮 v2 簽章：
 * - 公用 API（授權、換 token）：base = partner_id + api_path + timestamp
 * - 賣場 API（商品、訂單）：base = partner_id + api_path + timestamp + access_token + shop_id
 * timestamp 是秒級 Unix time，蝦皮容忍誤差很小，本機時鐘偏移會讓全部簽章失敗。
 */
function twshop_shopee_sign( $path, $timestamp, $with_shop = true ) {
    $creds = twshop_shopee_credentials();
    $base  = $creds['partner_id'] . $path . $timestamp;

    if ( $with_shop ) {
        $shop  = twshop_shopee_shop();
        $base .= $shop['access_token'] . $shop['shop_id'];
    }

    return hash_hmac( 'sha256', $base, $creds['partner_key'] );
}

/**
 * 節流：用 transient 記錄「這一秒」的呼叫次數，超過閾值就 usleep()，避免撞蝦皮的頻率限制。
 */
function twshop_shopee_throttle() {
    $second = (int) floor( microtime( true ) );
    $key    = 'twshop_shopee_rate_' . $second;
    $count  = (int) get_transient( $key );

    if ( $count >= 8 ) {
        usleep( 250000 );
    }

    set_transient( $key, $count + 1, 2 );
}

/**
 * 唯一對外出口：自動附掛簽章與授權參數、處理節流、token 失效自動續期重試一次、
 * 每次呼叫寫進 twshop_shopee_log。
 *
 * @param string $path       API path，例如 '/api/v2/product/get_item_list'
 * @param array  $args       GET 為 query 參數；POST 為 JSON body
 * @param string $method     'GET' 或 'POST'
 * @param bool   $with_shop  是否為賣場 API（需要 access_token/shop_id）
 * @param bool   $_is_retry  內部用：是否為 token 續期後的重試，避免無限遞迴
 * @return array|WP_Error    成功回傳解碼後的回應陣列；失敗回傳 WP_Error
 */
function twshop_shopee_request( $path, $args = array(), $method = 'GET', $with_shop = true, $_is_retry = false ) {
    twshop_shopee_throttle();

    $creds     = twshop_shopee_credentials();
    $timestamp = time();
    $sign      = twshop_shopee_sign( $path, $timestamp, $with_shop );

    $query = array(
        'partner_id' => $creds['partner_id'],
        'timestamp'  => $timestamp,
        'sign'       => $sign,
    );

    if ( $with_shop ) {
        $shop                  = twshop_shopee_shop();
        $query['access_token'] = $shop['access_token'];
        $query['shop_id']      = $shop['shop_id'];
    }

    $direction = $with_shop ? 'shop' : 'public';
    $url       = twshop_shopee_host() . $path;

    if ( 'GET' === $method ) {
        $response = wp_remote_get( add_query_arg( array_merge( $query, $args ), $url ), array( 'timeout' => 20 ) );
    } else {
        $response = wp_remote_post( add_query_arg( $query, $url ), array(
            'timeout' => 20,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $args ),
        ) );
    }

    if ( is_wp_error( $response ) ) {
        twshop_shopee_log( $direction, $path, wp_json_encode( $args ), false, $response->get_error_message() );
        return $response;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! is_array( $body ) ) {
        twshop_shopee_log( $direction, $path, wp_json_encode( $args ), false, '回應非合法 JSON' );
        return new WP_Error( 'twshop_shopee_bad_response', '蝦皮 API 回應格式錯誤' );
    }

    $error = $body['error'] ?? '';

    if ( '' !== $error ) {
        twshop_shopee_log( $direction, $path, wp_json_encode( $args ), false, $error . ': ' . ( $body['message'] ?? '' ) );

        $token_errors = array( 'error_auth', 'invalid_access_token', 'invalid_token' );
        if ( ! $_is_retry && $with_shop && in_array( $error, $token_errors, true ) ) {
            $refreshed = twshop_shopee_refresh_token();
            if ( ! is_wp_error( $refreshed ) ) {
                return twshop_shopee_request( $path, $args, $method, $with_shop, true );
            }
        }

        return new WP_Error( 'twshop_shopee_api_error', $body['message'] ?? $error, $body );
    }

    twshop_shopee_log( $direction, $path, wp_json_encode( $args ), true, '' );
    return $body;
}

// =========================================================================
// 授權
// =========================================================================

/**
 * 授權流程的一次性 `state` 存放位置，**key 綁 user ID**。
 *
 * 綁使用者是刻意的：`state` 要擋的正是「甲管理員被誘導去載入乙（攻擊者）準備好的回呼網址」，
 * 存成全站共用的話，甲自己正在進行的授權所產生的 state 會變成乙那條偽造網址的通行證。
 */
function twshop_shopee_auth_state_key() {
    return 'twshop_shopee_auth_state_' . get_current_user_id();
}

/**
 * 授權網址。`redirect` 上帶一個一次性隨機 `state`，回呼時比對——這是整個授權流程的
 * CSRF 防線，理由與攻擊情境見 `twshop_shopee_handle_auth_callback()`。
 *
 * **蝦皮會原樣帶回 `redirect` 上的查詢字串**：現行回呼的第一道判斷就是
 * `$_GET['page'] !== 'twshop-shopee'` 就 return，而 `page` 正是寫在 `redirect` 查詢字串裡的，
 * 也就是說「查詢參數會被保留」本來就是這個流程能運作的前提。
 *
 * **外層必須用 `http_build_query()`，不能用 `add_query_arg()`**（2026-09 加 `state` 時實測抓到）：
 * WordPress 的 `add_query_arg()` **不做 URL 編碼**（`build_query()` 傳給 `_http_build_query()` 的
 * `$urlencode` 是 false），`redirect` 的值會原樣拼進外層查詢字串。單看舊版沒事，是因為
 * `redirect=http://…/admin.php?page=twshop-shopee` 裡的 `?` 不是查詢分隔符，整段剛好活了下來；
 * 但只要 redirect 裡出現 `&`，後面那一截就會脫離 redirect、變成**蝦皮請求自己的頂層參數**，
 * 蝦皮直接忽略。也就是說 `state` 根本不會被帶去、回呼永遠拿不到，
 * 而畫面上只會顯示「授權驗證失敗」，看不出跟編碼有關。
 *
 * 15 分鐘 TTL：授權要跳去蝦皮登入、選賣場、按確認，給足時間；過期就重按一次授權。
 */
function twshop_shopee_get_auth_url() {
    $creds     = twshop_shopee_credentials();
    $path      = '/api/v2/shop/auth_partner';
    $timestamp = time();
    $sign      = twshop_shopee_sign( $path, $timestamp, false );

    $state = wp_generate_password( 32, false );
    set_transient( twshop_shopee_auth_state_key(), $state, 15 * MINUTE_IN_SECONDS );

    $redirect = add_query_arg( 'state', $state, admin_url( 'admin.php?page=twshop-shopee' ) );

    return twshop_shopee_host() . $path . '?' . http_build_query(
        array(
            'partner_id' => $creds['partner_id'],
            'timestamp'  => $timestamp,
            'sign'       => $sign,
            'redirect'   => $redirect,
        ),
        '',
        '&',
        PHP_QUERY_RFC3986
    );
}

/**
 * 授權回呼**不放在模組開關內**：使用者若在授權流程中途去關掉模組，回呼會靜默落空、
 * 蝦皮那端已授權但站台沒存到 token，畫面上不會有任何錯誤。函式內部檢查有沒有憑證，
 * 而非檢查模組開關。
 */
function twshop_shopee_handle_auth_callback() {
    if ( ! is_admin() ) return;
    if ( ! isset( $_GET['page'] ) || 'twshop-shopee' !== $_GET['page'] ) return;
    if ( empty( $_GET['code'] ) || empty( $_GET['shop_id'] ) ) return;
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;

    // ── CSRF 防線：一次性 state ───────────────────────────────────────────
    // 光有 current_user_can() 擋不住這種攻擊：誘使一位已登入的管理員載入
    // /wp-admin/admin.php?page=twshop-shopee&code=<攻擊者的>&shop_id=<攻擊者的>，
    // 底下那段 update_option() 就會把本站綁到**攻擊者的蝦皮賣場**上。後果不只是資料外洩——
    // 之後庫存/價格會推去攻擊者的賣場，訂單輪詢還會把攻擊者控制的蝦皮訂單當成真實 Woo 訂單
    // 用 wc_create_order() 建進來、實際扣掉庫存。
    //
    // OAuth 回呼是外部服務的 GET 導回，沒辦法用一般的 nonce 往返，標準做法就是 state：
    // 送出授權時產生隨機值存起來（twshop_shopee_get_auth_url()），回呼時比對。
    // **比對完一律刪掉**（不論成敗，故意放在比對之前），讓 state 只能用一次、擋重放；
    // 代價是管理員重新整理這個回呼網址會失敗，所以下面的提示要明講「重按一次授權」。
    // 用 hash_equals() 而非 ===：這是祕密值比對，避免時序側通道。
    $expected = get_transient( twshop_shopee_auth_state_key() );
    delete_transient( twshop_shopee_auth_state_key() );
    $given = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

    if ( ! $expected || ! $given || ! hash_equals( (string) $expected, $given ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>蝦皮授權驗證失敗</strong>，未寫入任何授權資料。'
                . '請回到「授權」頁籤重新按一次授權按鈕（授權連結逾時、重複開啟同一個回呼網址，'
                . '或這個請求不是由你本人發起的授權流程，都會出現這個訊息）。</p></div>';
        } );
        return;
    }

    $creds = twshop_shopee_credentials();
    if ( empty( $creds['partner_id'] ) || empty( $creds['partner_key'] ) ) return;

    $code    = sanitize_text_field( wp_unslash( $_GET['code'] ) );
    $shop_id = absint( wp_unslash( $_GET['shop_id'] ) );

    $result = twshop_shopee_request( '/api/v2/auth/token/get', array(
        'code'       => $code,
        'shop_id'    => $shop_id,
        'partner_id' => (int) $creds['partner_id'],
    ), 'POST', false );

    if ( is_wp_error( $result ) ) {
        $message = $result->get_error_message();
        add_action( 'admin_notices', function () use ( $message ) {
            echo '<div class="notice notice-error"><p>蝦皮授權失敗：' . esc_html( $message ) . '</p></div>';
        } );
        return;
    }

    $response = $result['response'] ?? $result;
    $now      = time();

    update_option( 'twshop_shopee_shop', array(
        'shop_id'       => $shop_id,
        'access_token'  => $response['access_token'] ?? '',
        'refresh_token' => $response['refresh_token'] ?? '',
        'expire_at'     => $now + intval( $response['expire_in'] ?? 4 * HOUR_IN_SECONDS ),
        'refreshed_at'  => $now,
        'authorized_at' => $now,
    ) );

    wp_safe_redirect( admin_url( 'admin.php?page=twshop-shopee&tab=auth&shopee_authorized=1' ) );
    exit;
}
add_action( 'admin_init', 'twshop_shopee_handle_auth_callback' );

/**
 * refresh_token 呼叫走 $with_shop = false（公用 API 簽章），刻意避免跟
 * twshop_shopee_request() 的「token 失效自動續期重試」邏輯互相遞迴——
 * 該邏輯只在 $with_shop 為真時才會觸發續期。
 */
function twshop_shopee_refresh_token() {
    $creds = twshop_shopee_credentials();
    $shop  = twshop_shopee_shop();

    if ( empty( $creds['partner_id'] ) || empty( $creds['partner_key'] ) || empty( $shop['refresh_token'] ) || empty( $shop['shop_id'] ) ) {
        return new WP_Error( 'twshop_shopee_no_credentials', '尚未設定蝦皮憑證或尚未授權賣場' );
    }

    $result = twshop_shopee_request( '/api/v2/auth/access_token/get', array(
        'refresh_token' => $shop['refresh_token'],
        'shop_id'       => (int) $shop['shop_id'],
        'partner_id'    => (int) $creds['partner_id'],
    ), 'POST', false );

    if ( is_wp_error( $result ) ) return $result;

    $response = $result['response'] ?? $result;
    $now      = time();

    $shop['access_token']  = $response['access_token'] ?? $shop['access_token'];
    $shop['refresh_token'] = $response['refresh_token'] ?? $shop['refresh_token'];
    $shop['expire_at']     = $now + intval( $response['expire_in'] ?? 4 * HOUR_IN_SECONDS );
    $shop['refreshed_at']  = $now;

    update_option( 'twshop_shopee_shop', $shop );

    return $shop;
}

/**
 * 掛每小時排程（twshop_shopee_refresh_token hook，不受模組開關控制）。
 * access_token 4 小時到期、refresh_token 30 天到期，在到期前 30 分鐘就換。
 */
function twshop_shopee_maybe_refresh_token() {
    $creds = twshop_shopee_credentials();
    $shop  = twshop_shopee_shop();

    if ( empty( $creds['partner_id'] ) || empty( $creds['partner_key'] ) || empty( $shop['access_token'] ) ) return;

    $expire_at = intval( $shop['expire_at'] ?? 0 );
    if ( $expire_at - time() > 30 * MINUTE_IN_SECONDS ) return;

    twshop_shopee_refresh_token();
}

// =========================================================================
// 手動續期（後台「授權」頁籤按鈕，非 AJAX，比照 twshop_maybe_reset_account_tabs() 的
// nonce'd GET 參數 + admin_init 處理模式）
// =========================================================================

function twshop_shopee_handle_manual_refresh() {
    if ( ! isset( $_GET['page'] ) || 'twshop-shopee' !== $_GET['page'] ) return;
    if ( ! isset( $_GET['twshop_shopee_manual_refresh'] ) ) return;
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    check_admin_referer( 'twshop_shopee_manual_refresh' );

    $result = twshop_shopee_refresh_token();
    $status = is_wp_error( $result ) ? 'error' : 'success';

    wp_safe_redirect( admin_url( 'admin.php?page=twshop-shopee&tab=auth&refresh=' . $status ) );
    exit;
}
add_action( 'admin_init', 'twshop_shopee_handle_manual_refresh' );
