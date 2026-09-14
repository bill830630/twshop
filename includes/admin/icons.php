<?php
/**
 * 會員中心頁籤圖示清單與 SVG 讀取
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 會員中心頁籤圖示選單的可選清單（icon slug => 中文說明），供後台圖示選擇器與儲存時白名單驗證共用。
 * 圖示來源是 Lucide（assets/icons/*.svg，線條風 SVG，MIT/ISC 授權，見 assets/icons/README.txt），
 * 選用它而非 Dashicons：視覺上是細線條風格，跟本站 Blocksy 主題原生的會員中心圖示（同樣是細線條）更接近；
 * SVG 直接內嵌輸出、不透過字型檔，前台不需要額外 enqueue 任何樣式表。
 */
function twshop_get_account_tab_icon_choices() {
    return array(
        'user'            => '會員',
        'users'           => '會員群組',
        'user-check'      => '已驗證會員',
        'contact'         => '聯絡人',
        'shield-check'    => '安全保障',
        'award'           => '獎章',
        'medal'           => '勳章',
        'star'            => '星星',
        'heart'           => '愛心',
        'shopping-cart'   => '購物車',
        'store'           => '商店',
        'package'         => '商品',
        'tag'             => '標籤',
        'ticket'          => '票券／優惠券',
        'wallet'          => '錢包',
        'coins'           => '點數／金錢',
        'piggy-bank'      => '存錢筒',
        'pie-chart'       => '圓餅圖',
        'bar-chart-3'     => '長條圖',
        'clipboard-list'  => '訂單清單',
        'list'            => '清單',
        'archive'         => '歸檔',
        'briefcase'       => '公事包',
        'download'        => '下載',
        'upload'          => '上傳',
        'map-pin'         => '地址',
        'home'            => '首頁',
        'layout-dashboard'=> '控制台',
        'pencil'          => '編輯',
        'settings'        => '設定',
        'wrench'          => '工具',
        'mail'            => '信箱',
        'phone'           => '電話',
        'smartphone'      => '手機',
        'lock'            => '鎖定',
        'unlock'          => '解鎖',
        'shield'          => '安全防護',
        'calendar'        => '日曆',
        'clock'           => '時鐘',
        'bell'            => '通知',
        'flag'            => '旗標',
        'thumbs-up'       => '讚',
        'badge'           => '名牌',
        'network'         => '連結網路',
        'log-out'         => '登出',
        'gift'            => '禮物',
        'percent'         => '折扣',
        'crown'           => '皇冠',
        'gem'             => '寶石',
        'trophy'          => '獎盃',
    );
}

/**
 * 會員中心頁籤圖示的預設值（slug => icon slug），尚未儲存過 wc_account_tab_icons 時（含「恢復預設值」
 * 之後）套用，取代原本「完全沒有圖示」的空陣列。這裡不是隨便挑的初始值，是實際上線後調過、視覺上跟
 * Blocksy 主題搭配確認過的組合，直接內建成新站台/重設後的起始狀態。未列在這裡的頁籤（例如
 * edit-address/dashboard/downloads/customer-logout）維持不顯示圖示；`social-login` 是
 * wc-line-order-notify 外掛的頁籤，該外掛未安裝/未啟用時這筆設定不會被用到，不影響其他頁籤。
 */
function twshop_get_account_tab_icon_defaults() {
    return array(
        'my-membership' => 'award',
        'my-coupons'    => 'ticket',
        'social-login'  => 'lock',
        'orders'        => 'clipboard-list',
        'edit-account'  => 'contact',
    );
}

/**
 * 讀取單一圖示的 SVG 原始碼（assets/icons/{slug}.svg），含 static cache 避免同一請求重複讀檔
 * （後台每一個頁籤列都會重新輸出一次完整的圖示選單，若無 cache 會是「頁籤數 × 圖示數」次讀檔）。
 * $slug 只接受 [a-z0-9-] 字元（正規表達式本身就排除了 . 和 / ，不會有路徑穿越疑慮），
 * 額外包含 'ban'（「不顯示圖示」選項用的圖示）、'grip-vertical'（拖曳排序把手）、
 * 'chevron-down'（卡片收合/展開箭頭）——這三個都不列在 twshop_get_account_tab_icon_choices()
 * 白名單內，因為它們不是使用者可選來當作頁籤圖示的選項，純粹是後台介面元件本身要用的圖示。
 */
function twshop_get_account_tab_icon_svg( $slug ) {
    static $cache = array();
    if ( isset( $cache[ $slug ] ) ) return $cache[ $slug ];

    if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) return '';

    $path = TWSHOP_PLUGIN_DIR . 'assets/icons/' . $slug . '.svg';
    $svg  = file_exists( $path ) ? (string) file_get_contents( $path ) : '';

    $cache[ $slug ] = $svg;
    return $svg;
}

