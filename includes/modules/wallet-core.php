<?php
/**
 * 儲值金核心：資料表建立與升級、帳本（ledger）唯一寫入入口。
 *
 * 儲值金是真錢，不能像 twshop_add_points_log()（points-engine.php，read-then-write、
 * 完全無鎖、用 max(0,…) 靜默吃掉透支）那樣寫，見 CLAUDE.md「儲值金模組」一節。所有餘額
 * 異動一律經過 twshop_wallet_apply()，用資料庫交易＋SELECT...FOR UPDATE 鎖住該會員的
 * 餘額列，同一會員的並發異動會排隊依序執行、不會互相覆蓋；ref 欄位是冪等鍵（例如
 * `topup:{order_id}`），同一個 ref 重複呼叫只會真正執行一次，之後直接回傳第一次的結果——
 * 用於「付款完成的 webhook 因網路重試被觸發兩次」這類情境，避免重複入帳。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TWSHOP_WALLET_DB_VERSION', '1.0.0' );

function twshop_wallet_balances_table() {
    global $wpdb;
    return $wpdb->prefix . 'twshop_wallet_balances';
}

function twshop_wallet_ledger_table() {
    global $wpdb;
    return $wpdb->prefix . 'twshop_wallet_ledger';
}

/**
 * 建表／升級表結構。比照蝦皮模組 twshop_shopee_install_tables() 的既有慣例：
 * register_activation_hook 涵蓋全新安裝，admin_init 的版本比對涵蓋既有站台的外掛更新
 * （更新外掛不會重新觸發 activation hook，光靠它表結構升級不會套用，且不會有任何錯誤訊息）。
 * dbDelta() 本身是冪等的，重複執行安全。
 *
 * 這裡不受 wallet 模組開關限制——模組關閉只是不掛載功能 hook，建表與否無關，
 * 跟蝦皮模組的既有慣例一致。
 */
function twshop_wallet_install_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $balances_table  = twshop_wallet_balances_table();
    $ledger_table    = twshop_wallet_ledger_table();

    $sql_balances = "CREATE TABLE {$balances_table} (
        user_id BIGINT UNSIGNED NOT NULL,
        balance_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
        balance_bonus DECIMAL(15,2) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (user_id)
    ) {$charset_collate};";
    dbDelta( $sql_balances );

    // bonus_expire_at 目前只保留欄位、沒有任何程式碼讀寫它——加贈金有效期限是刻意
    // 留到日後版本才做的功能（見 CLAUDE.md「儲值金模組」一節），先把欄位定好避免
    // 日後又要跑一次資料庫升級。
    $sql_ledger = "CREATE TABLE {$ledger_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        type VARCHAR(20) NOT NULL,
        amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
        amount_bonus DECIMAL(15,2) NOT NULL DEFAULT 0,
        balance_paid_after DECIMAL(15,2) NOT NULL DEFAULT 0,
        balance_bonus_after DECIMAL(15,2) NOT NULL DEFAULT 0,
        order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        ref VARCHAR(191) NOT NULL,
        note TEXT NULL,
        bonus_expire_at DATETIME NULL,
        created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY ref (ref),
        KEY user_id (user_id),
        KEY order_id (order_id)
    ) {$charset_collate};";
    dbDelta( $sql_ledger );

    update_option( 'twshop_wallet_db_version', TWSHOP_WALLET_DB_VERSION );
}
register_activation_hook( TWSHOP_PLUGIN_FILE, 'twshop_wallet_install_tables' );

function twshop_wallet_maybe_upgrade_db() {
    if ( get_option( 'twshop_wallet_db_version' ) !== TWSHOP_WALLET_DB_VERSION ) {
        twshop_wallet_install_tables();
    }
}
add_action( 'admin_init', 'twshop_wallet_maybe_upgrade_db' );

/**
 * 讀取某會員目前餘額。查不到資料列（從未有過任何異動）視為 0，不主動建立資料列——
 * 建立資料列的時機交給 twshop_wallet_apply() 在第一次真正異動時處理，維持「這支只讀、
 * 不寫」的單純語意。
 */
function twshop_wallet_get_balance( $user_id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT balance_paid, balance_bonus FROM " . twshop_wallet_balances_table() . " WHERE user_id = %d",
        (int) $user_id
    ), ARRAY_A );

    $paid  = $row ? (float) $row['balance_paid'] : 0.0;
    $bonus = $row ? (float) $row['balance_bonus'] : 0.0;
    return array( 'paid' => $paid, 'bonus' => $bonus, 'total' => round( $paid + $bonus, 2 ) );
}

/**
 * 儲值金餘額異動唯一入口。
 *
 * @param int    $user_id
 * @param float  $paid_delta   本金異動（正數增加／負數扣除）
 * @param float  $bonus_delta  加贈金異動（正數增加／負數扣除）
 * @param string $type         topup／topup_bonus／spend／spend_return／topup_revoke／adjust
 * @param string $ref          冪等鍵，同一個 ref 只會真正執行一次（例如 topup:123、spend:456、adjust:<uuid>）
 * @param array  $args         order_id／note／created_by／bonus_expire_at（皆選填）
 * @return array|WP_Error      成功回傳這筆帳本紀錄（含 balance_*_after 與 id）；
 *                              餘額不足（本金＋加贈金扣完仍為負）回傳 WP_Error。
 */
function twshop_wallet_apply( $user_id, $paid_delta, $bonus_delta, $type, $ref, $args = array() ) {
    global $wpdb;

    $user_id     = (int) $user_id;
    $paid_delta  = round( (float) $paid_delta, 2 );
    $bonus_delta = round( (float) $bonus_delta, 2 );
    $ref         = sanitize_text_field( $ref );

    if ( $user_id <= 0 || '' === $ref ) {
        return new WP_Error( 'twshop_wallet_invalid_args', '缺少會員 ID 或冪等鍵（ref）。' );
    }

    $ledger_table   = twshop_wallet_ledger_table();
    $balances_table = twshop_wallet_balances_table();

    // 交易外的快速路徑：多數呼叫本來就不是重複請求，先省一次交易的開銷。
    // 這裡查不到不代表真的可以放行——底下拿到列鎖之後還會再查一次才是真正權威的判斷，
    // 見下方註解。
    $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ledger_table} WHERE ref = %s", $ref ), ARRAY_A );
    if ( $existing ) return $existing;

    $wpdb->query( 'START TRANSACTION' );

    // FOR UPDATE 鎖住這位會員的餘額列：同一會員的並發異動會在這裡排隊，後面的請求
    // 必須等前一個交易 COMMIT/ROLLBACK 才能往下走。
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$balances_table} WHERE user_id = %d FOR UPDATE", $user_id ), ARRAY_A );
    if ( ! $row ) {
        // 這位會員第一次有異動：用 INSERT ... ON DUPLICATE KEY UPDATE（而不是先判斷
        // 「不存在」才 INSERT）避免兩個並發請求都判斷「不存在」而各自嘗試 INSERT
        // 造成主鍵衝突——ON DUPLICATE KEY UPDATE 讓兩者都能安全執行，其中一個是
        // 真正建立、另一個等同無害的自我更新。
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$balances_table} (user_id, balance_paid, balance_bonus, updated_at) VALUES (%d, 0, 0, %s)
             ON DUPLICATE KEY UPDATE user_id = user_id",
            $user_id, current_time( 'mysql' )
        ) );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$balances_table} WHERE user_id = %d FOR UPDATE", $user_id ), ARRAY_A );
    }

    // 權威的冪等檢查：必須排在拿到列鎖「之後」，不能只靠交易外那道快速路徑。
    // 理由：如果兩個帶著相同 ref 的並發請求都在交易外查到「不存在」而進了交易，
    // 靠列鎖序列化後，後面那個請求會在這裡重新查到 ref 已經被前一個交易寫入並
    // COMMIT，正確地在「還沒動到餘額」之前就短路回傳，而不是等最後 INSERT 撞到
    // UNIQUE 索引才發現——那樣會變成先錯誤地把餘額異動了一次，才靠 ROLLBACK 撤銷，
    // 邏輯上雖然結果正確但完全依賴交易復原、比較脆弱。這裡假設同一個 ref 永遠對應
    // 同一個 user_id（本模組所有呼叫端的 ref 命名規則皆是如此，例如 topup:{order_id}
    // 綁定單一訂單即單一會員），才能靠這把鎖天然序列化。
    $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ledger_table} WHERE ref = %s", $ref ), ARRAY_A );
    if ( $existing ) {
        $wpdb->query( 'COMMIT' ); // 沒有任何異動，COMMIT 純粹釋放鎖
        return $existing;
    }

    $new_paid  = round( (float) $row['balance_paid'] + $paid_delta, 2 );
    $new_bonus = round( (float) $row['balance_bonus'] + $bonus_delta, 2 );

    if ( $new_paid < 0 || $new_bonus < 0 ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'twshop_wallet_insufficient_balance', '儲值金餘額不足，無法完成這筆異動。' );
    }

    $wpdb->update(
        $balances_table,
        array( 'balance_paid' => $new_paid, 'balance_bonus' => $new_bonus, 'updated_at' => current_time( 'mysql' ) ),
        array( 'user_id' => $user_id ),
        array( '%f', '%f', '%s' ),
        array( '%d' )
    );

    $insert = array(
        'user_id'             => $user_id,
        'type'                => sanitize_key( $type ),
        'amount_paid'         => $paid_delta,
        'amount_bonus'        => $bonus_delta,
        'balance_paid_after'  => $new_paid,
        'balance_bonus_after' => $new_bonus,
        'order_id'            => (int) ( $args['order_id'] ?? 0 ),
        'ref'                 => $ref,
        'note'                => sanitize_text_field( $args['note'] ?? '' ),
        'created_by'          => (int) ( $args['created_by'] ?? get_current_user_id() ),
        'created_at'          => current_time( 'mysql' ),
    );
    $formats = array( '%d', '%s', '%f', '%f', '%f', '%f', '%d', '%s', '%s', '%d', '%s' );
    if ( ! empty( $args['bonus_expire_at'] ) ) {
        $insert['bonus_expire_at'] = $args['bonus_expire_at'];
        $formats[] = '%s';
    }

    $inserted = $wpdb->insert( $ledger_table, $insert, $formats );
    if ( false === $inserted ) {
        // 理論上不會發生（上面的權威冪等檢查已經在同一把鎖底下排除了這個情況），
        // 保留這道防線只為了不要讓一次未預期的 DB 錯誤導致餘額被異動卻沒有留下帳本紀錄。
        $wpdb->query( 'ROLLBACK' );
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ledger_table} WHERE ref = %s", $ref ), ARRAY_A );
        return $existing ? $existing : new WP_Error( 'twshop_wallet_ledger_insert_failed', '寫入儲值金帳本失敗，請重新整理頁面後再試。' );
    }
    $insert['id'] = $wpdb->insert_id;

    $wpdb->query( 'COMMIT' );

    return $insert;
}

/**
 * 折抵／扣款專用：給定要扣的總額，依「先扣本金、本金不夠才扣加贈金」的固定順序
 * （使用者已確認，非設定項）拆成 paid／bonus 兩個負數（或 0）。純計算，不查資料庫、
 * 不驗證餘額是否足夠——是否透支交給 twshop_wallet_apply() 的交易本身把關，這支只負責
 * 「同一筆扣款金額該怎麼分配」。
 *
 * @return array{paid: float, bonus: float} 皆為 <= 0 的負數（或 0）
 */
function twshop_wallet_split_spend_amount( $amount, $balance_paid ) {
    $amount     = round( max( 0, (float) $amount ), 2 );
    $from_paid  = round( min( $amount, max( 0, (float) $balance_paid ) ), 2 );
    $from_bonus = round( $amount - $from_paid, 2 );
    return array( 'paid' => -$from_paid, 'bonus' => -$from_bonus );
}

/**
 * 讀取某會員的異動紀錄（新到舊），供會員中心「我的儲值金」與後台個人資料頁共用。
 */
function twshop_wallet_get_ledger( $user_id, $limit = 50, $offset = 0 ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM " . twshop_wallet_ledger_table() . " WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
        (int) $user_id, (int) $limit, (int) $offset
    ), ARRAY_A );
}

function twshop_wallet_type_label( $type ) {
    $labels = array(
        'topup'        => '線上儲值（本金）',
        'topup_bonus'  => '線上儲值（加贈）',
        'spend'        => '購物折抵',
        'spend_return' => '訂單退回',
        'topup_revoke' => '儲值訂單退款扣回',
        'adjust'       => '後台手動調整',
    );
    return $labels[ $type ] ?? $type;
}

function twshop_wallet_signed_amount( $amount ) {
    $amount = (float) $amount;
    if ( 0.0 === $amount ) return '—';
    return ( $amount > 0 ? '+' : '' ) . number_format( $amount, 2 );
}
