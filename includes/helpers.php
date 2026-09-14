<?php
/**
 * 核心 helper：規則快取、規則使用次數、贈品優惠券、模組開關與定義
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構）。內容為原樣搬移，未做任何邏輯或排版變更。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 靜態快取規則列表：同一次請求內多次讀取只查一次資料庫。
 * 傳入 true 可強制重新讀取（規則儲存/刪除/排序後呼叫）。
 */
function twshop_get_rules( $force_refresh = false ) {
    static $rules_cache = null;
    if ( $force_refresh ) {
        $rules_cache = null;
    }
    if ( $rules_cache === null ) {
        $rules_cache = get_option( 'wc_discount_rules_settings', array() );
        $rules_cache = twshop_backfill_missing_rule_ids( $rules_cache );
    }
    return $rules_cache;
}

/**
 * 規則使用次數（全站累計）的 static cache 讀寫入口，供下方三個 twshop_*_rule_usage_total()
 * 共用同一份記憶體狀態，確保同一次請求內「遞增後立即再讀」也能拿到最新值。
 */
function twshop_rule_usage_totals_cache( $new_value = null ) {
    static $totals = null;
    if ( null !== $new_value ) {
        $totals = $new_value;
    }
    if ( null === $totals ) {
        $totals = get_option( 'wc_discount_rules_usage_totals', array() );
        if ( ! is_array( $totals ) ) $totals = array();
    }
    return $totals;
}

/**
 * 規則使用次數合併成單一陣列型 option（wc_discount_rules_usage_totals），取代改版前
 * 逐規則各自一個 twshop_rule_usage_total_{rule_id} option 的做法——規則數量多的站台會累積
 * 大量零散的動態 key option，這些通常會被 autoload 拉進每一次頁面載入的 alloptions
 * （不只影響折扣相關頁面），合併成一個 option 一次讀取即可涵蓋全部規則。
 * 相容舊資料：新格式尚無此規則紀錄時，回退讀取舊的動態 key option（不主動遷移舊 option，
 * 避免多執行緒/多請求同時寫入造成競態；只在下面「遞增」時才順手把該筆遷移過去並清掉舊值）。
 */
function twshop_get_rule_usage_total( $rule_id ) {
    $totals = twshop_rule_usage_totals_cache();
    if ( isset( $totals[ $rule_id ] ) ) return intval( $totals[ $rule_id ] );
    return intval( get_option( 'twshop_rule_usage_total_' . $rule_id, 0 ) );
}

function twshop_increment_rule_usage_total( $rule_id ) {
    $totals = twshop_rule_usage_totals_cache();
    $current = isset( $totals[ $rule_id ] ) ? intval( $totals[ $rule_id ] ) : intval( get_option( 'twshop_rule_usage_total_' . $rule_id, 0 ) );
    $totals[ $rule_id ] = $current + 1;
    update_option( 'wc_discount_rules_usage_totals', $totals, false );
    delete_option( 'twshop_rule_usage_total_' . $rule_id ); // 完成遷移，避免新舊兩份資料以後對不上
    twshop_rule_usage_totals_cache( $totals );
}

function twshop_delete_rule_usage_total( $rule_id ) {
    $totals = twshop_rule_usage_totals_cache();
    if ( isset( $totals[ $rule_id ] ) ) {
        unset( $totals[ $rule_id ] );
        update_option( 'wc_discount_rules_usage_totals', $totals, false );
        twshop_rule_usage_totals_cache( $totals );
    }
    delete_option( 'twshop_rule_usage_total_' . $rule_id ); // 相容舊資料殘留
}

/**
 * 修補沒有 rule_id（或值為空）的舊規則資料。
 *
 * 早期版本的規則陣列沒有 rule_id 這個欄位，這類規則在後台編輯表單裡的隱藏欄位會被
 * 渲染成 value=""；儲存時 twshop_ajax_save_rule() 一看到空字串就會用 uniqid('rule_')
 * 生一個全新 ID，導致比對永遠對不到原本那筆（原本那筆也還是沒有 rule_id），於是「更新」
 * 變成在陣列尾端多插入一筆新資料，畫面上原本那張卡片看起來就像「存了也沒用」；
 * 刪除同一筆舊規則時，前端 JS 甚至因為 rule_id 是空字串直接 `if(!rule_id){ $form.remove(); return; }`
 * 短路掉，根本沒送出刪除的 AJAX 請求，重新整理後又會原封不動地跑回來。
 *
 * 這裡在每次讀取規則列表時檢查一次，把缺 rule_id 的項目補上真正的唯一值並立即寫回，
 * 補過一次之後全部規則都有正常 rule_id，就不會再觸發寫入。
 */
/**
 * 讀出折扣規則的限制條件（type ＋ values），並相容舊資料。
 *
 * 舊版規則只有單一的 `category` / `tag` 欄位，尚未重新儲存過的規則沒有
 * `condition_type`/`condition_values`，必須從舊欄位回退讀取。這段回退原本在三個地方
 * 各抄了一份（twshop_is_discount_rule_valid()、twshop_build_rule_coupon_restrictions()、
 * twshop_auto_display_coupons() 的規則優惠券迴圈），三份逐字相同；漏改一份的後果是
 * 「舊規則在某一條路徑上限制條件突然消失」——規則會變成無條件適用，不會有任何錯誤訊息。
 *
 * 回傳 array( $type, $values )，供 list() 解構。
 */
function twshop_get_rule_condition( $rule ) {
    $type   = $rule['condition_type'] ?? '';
    $values = $rule['condition_values'] ?? array();
    if ( empty( $type ) && ! empty( $rule['category'] ) ) return array( 'category', array( $rule['category'] ) );
    if ( empty( $type ) && ! empty( $rule['tag'] ) )      return array( 'tag', array( $rule['tag'] ) );
    return array( $type, $values );
}

function twshop_backfill_missing_rule_ids( $rules ) {
    if ( ! is_array( $rules ) || empty( $rules ) ) return $rules;
    $changed = false;
    foreach ( $rules as $k => $r ) {
        if ( empty( $r['rule_id'] ) ) {
            $rules[ $k ]['rule_id'] = uniqid( 'rule_' );
            $changed = true;
        }
    }
    if ( $changed ) {
        update_option( 'wc_discount_rules_settings', $rules );
    }
    return $rules;
}

function twshop_get_earn_base_amount( $items_data, $unrestricted_total ) {
    list( $restrict_type, $restrict_values ) = twshop_get_typed_restriction(
        'wc_points_earn_restrict_type', 'wc_points_earn_restrict_values',
        array( 'category' => 'wc_points_earn_restricted_category', 'tag' => 'wc_points_earn_restricted_tag' )
    );

    if ( empty( $restrict_type ) || empty( $restrict_values ) ) {
        return $unrestricted_total;
    }
    $taxonomy = $restrict_type === 'tag' ? 'product_tag' : 'product_cat';

    $total = 0;
    foreach ( $items_data as $item ) {
        if ( has_term( $restrict_values, $taxonomy, $item['product_id'] ) ) $total += $item['total'];
    }
    return $total;
}

function twshop_get_user_point_multiplier( $user ) {
    // member_tiers 模組停用時，等級加倍效果也應一併停止——這裡是唯一判斷入口，
    // 呼叫端（實際發點/購物車預估）都不需要各自重複檢查模組狀態（v25.5.82 修正）。
    if ( ! twshop_module_enabled( 'member_tiers' ) ) return 1;
    $settings = get_option( 'wc_member_tiers_settings', array() );
    if ( empty( $settings ) || ! is_array( $settings ) ) return 1;
    foreach ( $settings as $tier ) {
        if ( in_array( $tier['slug'], (array) $user->roles, true ) && ! empty( $tier['point_multiplier'] ) ) {
            return floatval( $tier['point_multiplier'] );
        }
    }
    return 1;
}

function twshop_create_gift_coupon( $code, $gift, $email, $validity_days, $title, $desc ) {
    $coupon = new WC_Coupon();
    $coupon->set_code( $code );
    $coupon->set_discount_type( $gift['type'] );
    $coupon->set_amount( $gift['amount'] );
    $coupon->set_date_expires( strtotime( '+' . $validity_days . ' days' ) );
    $coupon->set_usage_limit( 1 );
    $coupon->set_usage_limit_per_user( 1 );
    $coupon->set_email_restrictions( array( $email ) );
    $coupon->set_individual_use( true );
    $coupon->update_meta_data( '_visual_coupon_title', $title );
    $coupon->update_meta_data( '_visual_coupon_desc', $desc );
    $coupon->save();
}

/**
 * 前台文字與開關類 option 的預設值對照表。
 *
 * 這 43 個 option 的預設值原本在後台設定頁與前台渲染處各寫一次(部分寫了三到五次),
 * 兩邊逐字相同全靠人維護。改了前台忘了改後台的後果特別隱晦:後台輸入框顯示的是
 * 舊預設字串,使用者以為那就是目前生效的文字,實際上前台跑的是新的——兩邊都不會報錯,
 * 而且只有在「使用者從沒儲存過這個欄位」時才看得出來。
 *
 * 只收「有多處指定預設值」的 option。單一處使用的預設值留在原地,搬進來只是把
 * 上下文推遠,沒有一致性可言。
 */
function twshop_get_option_defaults() {
    static $defaults = null;
    if ( null !== $defaults ) return $defaults;

    $defaults = array(
        // 優惠券前台文字
        'wc_coupon_btn_apply_text'              => '點擊套用',
        'wc_coupon_btn_remove_text'             => '取消套用',
        'wc_coupon_btn_shop_text'               => '去購物',
        'wc_coupon_btn_unavailable_text'        => '暫不可用',
        'wc_coupon_btn_used_text'               => '已使用',
        'wc_coupon_dialog_heading'              => '可用優惠券',
        'wc_coupon_dialog_trigger_applied_text' => '已套用優惠券・點此查看或更換',
        'wc_coupon_dialog_trigger_none_text'    => '查看可用優惠券（{count}）',
        'wc_coupon_exclusive_error_text'        => '此為單獨使用之專屬優惠，不可與其他{noun}並用',
        'wc_general_coupon_noun'                => '優惠券',
        'wc_general_coupon_page_desc'           => '這裡展示您擁有的所有優惠，點擊按鈕即可前往購物選購',
        'wc_general_coupon_page_title'          => '專屬優惠券',
        'wc_general_no_coupon_msg'              => '目前沒有可用的專屬優惠券喔',

        // 加購區塊文字
        'wc_addon_btn_add_text'    => '加入加購',
        'wc_addon_btn_incart_text' => '已在購物車',
        'wc_addon_section_title'   => '🎉 專屬加購優惠',

        // 點數前台文字
        'wc_points_applied_text'      => '已套用 {amount} {term}，折抵 {discount} 元',
        'wc_points_balance_text'      => '您目前擁有 {amount} {term}可用',
        'wc_points_btn_apply_text'    => '套用折抵',
        'wc_points_btn_update_text'   => '更新或取消{term}',
        'wc_points_expiry_soon_text'  => '有 {amount} {term}將於 {date} 到期',
        'wc_points_input_placeholder' => '輸入欲使用{term}（{rate} 的倍數）',
        'wc_points_min_cart_text'     => '購物車需滿 {amount} 才可使用{term}折抵',
        'wc_points_no_balance_text'   => '您目前沒有可用的{term}',
        'wc_points_restricted_text'   => '購物車需包含「{names}」分類/標籤商品才可使用{term}',
        'wc_points_ui_heading'        => '使用{term}折抵',

        // 會員通知信與等級文字
        'wc_birthday_email_subject'        => '祝您生日快樂！專屬生日禮金',
        'wc_tier_change_email_body'        => '您好，您的會員等級已調整為：{tier}。',
        'wc_tier_change_email_subject'     => '【會員通知】等級調整',
        'wc_tier_max_reached_text'         => '您已達到最高會員等級 🎉',
        'wc_tier_not_configured_text'      => '目前尚未設定會員等級制度。',
        'wc_upgrade_email_body_no_gift'    => '您的會員等級已升級為：{tier}。',
        'wc_upgrade_email_subject'         => '恭喜升級！專屬升級回饋禮',
        'wc_upgrade_email_subject_no_gift' => '【會員通知】恭喜升級',

        // 會員中心頁籤名稱
        'wc_general_tab_name'    => '優惠券',
        'wc_membership_tab_name' => '會員權益',

        // 開關類（yes/no）
        'wc_account_tab_mobile_scroll'  => 'yes',
        'wc_badge_enabled'              => 'yes',
        'wc_classic_cart_show_addons'   => 'yes',
        'wc_classic_cart_show_coupons'  => 'yes',
        'wc_classic_cart_show_points'   => 'yes',
        'wc_classic_cart_show_progress' => 'yes',
    );
    return $defaults;
}

/**
 * 讀取上表涵蓋的 option,預設值統一由 twshop_get_option_defaults() 供應。
 *
 * 沿用 get_option() 的既有語意:只有 option 不存在時才回退預設值——已存在但為空字串
 * 的欄位仍然回傳空字串(使用者刻意清空該欄位的意思),跟改動前的行為完全一致。
 *
 * 對照表沒有的 key 會回退成空字串。這種打錯字的情況不會噴錯,但會讓對應的前台文字
 * 整段消失,渲染快照(.dev-tools/admin-render.php)與效能腳本的輸出雜湊都會抓到。
 */
function twshop_option( $option ) {
    $defaults = twshop_get_option_defaults();
    return get_option( $option, $defaults[ $option ] ?? '' );
}

/**
 * 載入 assets/js/ 底下的一支腳本,取代原本直接印在頁面裡的 <script> 區塊。
 *
 * $name 是相對於 assets/js/ 的路徑(不含 .js),handle 一律是 'twshop-' ＋ 檔名。
 * 版本號用 filemtime():改完檔案不必手動改版本,也不會讓瀏覽器拿到舊快取。
 *
 * $localize 是 { JS 全域變數名 => 資料陣列 },給原本靠 PHP 內插塞進 JS 的動態值
 * (nonce、admin-ajax 網址、可翻譯的文案)。走 wp_localize_script() 而不是繼續內插,
 * 資料會經過 wp_json_encode(),不需要在 JS 字串裡自己處理跳脫。
 *
 * 一律載入到頁尾($in_footer = true)。原本這些 <script> 印在頁面中段,只看得到自己
 * 上方的 DOM;移到頁尾後看得到的 DOM 只多不少,不會有「找不到元素」的新問題。
 */
function twshop_enqueue_asset_script( $name, array $localize = array(), array $deps = array( 'jquery' ) ) {
    $handle = 'twshop-' . basename( $name );
    $rel    = 'assets/js/' . $name . '.js';

    wp_enqueue_script( $handle, TWSHOP_PLUGIN_URL . $rel, $deps, filemtime( TWSHOP_PLUGIN_DIR . $rel ), true );

    foreach ( $localize as $object_name => $data ) {
        wp_localize_script( $handle, $object_name, $data );
    }
}

/**
 * 未設定的模組 key **預設停用**（v25.8.12 起，原本預設啟用）。改成預設關閉是為了
 * 讓打包出去的安裝包對客戶站台而言是「全部功能關閉」的乾淨初始狀態，客戶自行到
 * 「系統設定 ▸ 模組開關」逐一啟用需要的功能，而不是裝上去就啟用一整套不確定要不要用的東西。
 * 「系統設定 ▸ 模組開關」頁籤本身的 checkbox 預設值（`twshop_system_modules_tab()`，
 * `includes/admin/menus.php`）是獨立的另一份 `?? '0'`，兩處要一起改，改一邊忘了改
 * 另一邊只會讓頁籤畫面顯示的開關狀態跟實際生效的狀態對不上，不會有任何錯誤訊息。
 */
function twshop_module_enabled( $module ) {
    static $settings = null;
    if ( $settings === null ) {
        $settings = get_option( 'twshop_module_settings', array() );
    }
    return ( $settings[ $module ] ?? '0' ) === '1';
}

/**
 * 可開關模組的清單（label + 說明文字），供「系統設定 ▸ 模組開關」與「儀表板」共用，
 * 避免模組清單分散在兩處各自維護、改一邊忘了改另一邊。
 */
/**
 * 商品網址（slug）是否改用商品編號。**單一讀取入口**，所以刻意不登記進
 * twshop_get_option_defaults()——那張表的用途是「同一個純量 option 在多處被讀取、
 * 各處各寫一次預設值導致漂移」，只有一個入口的 option 硬塞進去反而讓那張表的語意變模糊
 * （跟蝦皮那三個 option 同樣的判斷）。
 *
 * **預設 no**：客戶更新外掛不該被靜默改掉全站商品網址。實作見
 * includes/modules/product-slug.php。
 */
function twshop_product_slug_use_id_enabled() {
    return 'yes' === get_option( 'wc_product_slug_use_id', 'no' );
}

function twshop_get_module_definitions() {
    return array(
        'member_tiers'    => array( 'label' => '會員分級', 'desc' => '會員等級升降、生日禮、升等禮' ),
        'discount_rules'  => array( 'label' => '折扣規則', 'desc' => '動態折扣、免運、自動贈品、加購品' ),
        'visual_coupons'  => array( 'label' => '優惠卡券', 'desc' => '卡片式優惠券、會員優惠券頁面' ),
        'points'          => array( 'label' => '紅利點數', 'desc' => '消費累點、點數折抵、異動紀錄' ),
        'order_checkout_enhancements' => array(
            'label' => '訂單強化',
            'desc'  => '台灣地址下拉選單、超商取貨免填地址、訂單物流資訊顯示與搜尋、自訂訂單狀態、批次操作、物流貨態自動完成訂單',
        ),
        'shopee_sync' => array(
            'label' => '蝦皮串接',
            'desc'  => 'Woo 庫存/價格推送蝦皮、蝦皮訂單自動匯入為 Woo 訂單、商品 SKU 對應',
        ),
    );
}

