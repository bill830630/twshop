<?php
/**
 * 訂單物流資訊（讀取 ecpay-ecommerce-for-woocommerce 既有資料顯示給客戶）
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 訂單物流資訊（讀取 ecpay-ecommerce-for-woocommerce 既有資料顯示給客戶）
// =========================================================================

/**
 * 在客戶前台「查看訂單」頁面（woocommerce_order_details_after_order_table）顯示物流資訊。
 * 只讀取 ecpay-ecommerce-for-woocommerce 外掛已經寫入的 order meta／order note，
 * 不修改該外掛、也不重新實作它的 webhook 邏輯，避免跟第三方外掛耦合。
 * 若訂單完全沒有 ecpay 物流資料（例如非綠界物流、或外掛未啟用），整段不輸出。
 */
function twshop_render_order_logistics_info( $order ) {
    if ( ! is_a( $order, 'WC_Order' ) ) return;

    $store_name    = $order->get_meta( '_ecpay_logistic_cvs_store_name' );
    $store_address = $order->get_meta( '_ecpay_logistic_cvs_store_address' );
    $store_phone   = $order->get_meta( '_ecpay_logistic_cvs_store_telephone' );

    $logistics_id   = $order->get_meta( '_wooecpay_logistic_AllPayLogisticsID' );
    $cvs_payment_no = $order->get_meta( '_wooecpay_logistic_CVSPaymentNo' );
    $booking_note   = $order->get_meta( '_wooecpay_logistic_BookingNote' );

    $latest_status = twshop_get_latest_ecpay_logistic_status( $order->get_id() );

    if ( ! $store_name && ! $logistics_id && ! $cvs_payment_no && ! $booking_note && ! $latest_status ) return;
    ?>
    <h2>物流資訊</h2>
    <table class="woocommerce-table shop_table twshop-order-logistics">
        <tbody>
            <?php if ( $latest_status ) : ?>
                <tr>
                    <th>最新配送狀態</th>
                    <td><?php echo esc_html( $latest_status ); ?></td>
                </tr>
            <?php endif; ?>
            <?php if ( $store_name ) : ?>
                <tr>
                    <th>取貨門市</th>
                    <td>
                        <?php echo esc_html( $store_name ); ?>
                        <?php if ( $store_address ) : ?><br><?php echo esc_html( $store_address ); ?><?php endif; ?>
                        <?php if ( $store_phone ) : ?><br><?php echo esc_html( $store_phone ); ?><?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
            <?php if ( $logistics_id ) : ?>
                <tr>
                    <th>物流編號</th>
                    <td><?php echo esc_html( $logistics_id ); ?></td>
                </tr>
            <?php endif; ?>
            <?php if ( $cvs_payment_no ) : ?>
                <tr>
                    <th>寄貨編號</th>
                    <td><?php echo esc_html( $cvs_payment_no ); ?></td>
                </tr>
            <?php endif; ?>
            <?php if ( $booking_note ) : ?>
                <tr>
                    <th>托運單號</th>
                    <td><?php echo esc_html( $booking_note ); ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}

/**
 * 單一次 wc_get_order_notes() 查詢，供 twshop_get_latest_ecpay_logistic_status()（下方）與
 * twshop_get_ecpay_logistic_note_dates()（見「訂單物流資訊 metabox」段落）共用，避免同一次
 * 頁面請求（例如後台訂單編輯頁 metabox 同時要顯示狀態文字與更新時間）重複查詢同一批備註。
 * per-request static cache，key 為 order_id。
 */
function twshop_get_order_logistics_notes( $order_id ) {
    static $cache = array();
    if ( isset( $cache[ $order_id ] ) ) return $cache[ $order_id ];
    if ( ! function_exists( 'wc_get_order_notes' ) ) return array();

    $notes = wc_get_order_notes( array(
        'order_id' => $order_id,
        'type'     => 'internal',
        'orderby'  => 'date_created',
        'order'    => 'DESC',
    ) );
    return $cache[ $order_id ] = $notes;
}

/**
 * 讀出 ecpay-ecommerce-for-woocommerce 的 logistic_status_response() 寫入的最新一筆
 * 「物流貨態回傳:{RtnMsg} ({RtnCode})」系統備註，解析出 RtnMsg 顯示。找不到則回傳空字串。
 */
function twshop_get_latest_ecpay_logistic_status( $order_id ) {
    $notes = twshop_get_order_logistics_notes( $order_id );

    foreach ( $notes as $note ) {
        if ( strpos( $note->content, '物流貨態回傳:' ) === 0 ) {
            $msg = substr( $note->content, strlen( '物流貨態回傳:' ) );
            $msg = preg_replace( '/\s*\([^)]*\)\s*$/', '', $msg ); // 去掉結尾的 (RtnCode)
            return trim( $msg );
        }
    }
    return '';
}

/**
 * 讓後台訂單列表的搜尋框可以用綠界物流單號（物流編號／寄貨編號／托運單號）找到訂單。
 * 同時掛載 legacy（CPT 訂單儲存）與 HPOS 兩種模式各自的搜尋欄位 filter，
 * 只讀取 ecpay-ecommerce-for-woocommerce 既有寫入的 meta key，不新增或修改資料。
 */
function twshop_add_logistics_search_fields( $search_fields ) {
    $search_fields[] = '_wooecpay_logistic_AllPayLogisticsID';
    $search_fields[] = '_wooecpay_logistic_CVSPaymentNo';
    $search_fields[] = '_wooecpay_logistic_BookingNote';
    return $search_fields;
}

/**
 * 讀出「建立物流訂單-...」與「物流貨態回傳:...」兩則備註各自的日期，供後台訂單編輯頁
 * metabox 顯示建立時間／更新時間。與 twshop_get_latest_ecpay_logistic_status() 共用同一份
 * twshop_get_order_logistics_notes() 查詢結果，同一次頁面請求只會實際查一次 DB。
 * 找不到對應備註時，對應的值為 null。
 */
function twshop_get_ecpay_logistic_note_dates( $order_id ) {
    $notes  = twshop_get_order_logistics_notes( $order_id );
    $result = array( 'created_at' => null, 'updated_at' => null );

    foreach ( $notes as $note ) {
        if ( null === $result['created_at'] && strpos( $note->content, '建立物流訂單' ) === 0 ) {
            $result['created_at'] = $note->date_created;
        }
        if ( null === $result['updated_at'] && strpos( $note->content, '物流貨態回傳:' ) === 0 ) {
            $result['updated_at'] = $note->date_created;
        }
        if ( $result['created_at'] && $result['updated_at'] ) break;
    }
    return $result;
}

/**
 * 後台訂單編輯頁「綠界物流資訊」metabox。只讀取 ecpay-ecommerce-for-woocommerce 已經寫入的
 * order meta／order note，不修改該外掛、也不重新實作其邏輯（同 twshop_render_order_logistics_info()
 * 的原則）。ecpay 外掛從不保留物流建立歷史——每次建立物流都會覆蓋同一組 meta key，因此本表格
 * 永遠只會有 0 或 1 列，不是可累積歷史的清單。
 *
 * 【HPOS 注意】站台已啟用 HPOS。legacy `shop_order` 貼文編輯畫面由 WP 核心
 * wp-admin/includes/meta-boxes.php 呼叫 do_action("add_meta_boxes_{$post_type}", $post)，
 * callback 收到 WP_Post；HPOS `woocommerce_page_wc-orders` 畫面則由 WooCommerce 自己的
 * src/Internal/Admin/Orders/Edit.php 呼叫 do_action('add_meta_boxes_'.$screen_id, $order)，
 * callback 收到的是 WC_Order 物件本身，不是 WP_Post。兩種畫面都要分開處理。
 *
 * 沒有 _wooecpay_logistic_AllPayLogisticsID 時直接不註冊 metabox（不是註冊了在渲染函式裡
 * 才 return 空字串），避免畫面出現一個空標題的空白區塊。
 */
function twshop_register_order_logistics_metabox( $post_or_order ) {
    $order = ( $post_or_order instanceof WC_Order )
        ? $post_or_order
        : wc_get_order( $post_or_order->ID ?? 0 );

    if ( ! $order instanceof WC_Order ) return;
    if ( ! $order->get_meta( '_wooecpay_logistic_AllPayLogisticsID' ) ) return;

    $screen = get_current_screen();
    if ( ! $screen ) return;

    add_meta_box(
        'twshop-order-logistics-info',
        '綠界物流資訊',
        'twshop_render_order_logistics_metabox',
        $screen->id,
        'side',
        'high',
        array( 'order' => $order )
    );
}

/**
 * $post_or_order：legacy 畫面 WP 傳入 WP_Post，HPOS 畫面 WooCommerce 傳入 WC_Order。
 * 優先使用註冊時已經解析好的 $box['args']['order']，缺漏時才自行判斷（防禦性寫法）。
 */
function twshop_render_order_logistics_metabox( $post_or_order, $box ) {
    $order = $box['args']['order'] ?? null;
    if ( ! $order instanceof WC_Order ) {
        $order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
    }
    if ( ! $order instanceof WC_Order ) return;

    $logistics_id = $order->get_meta( '_wooecpay_logistic_AllPayLogisticsID' );
    if ( ! $logistics_id ) return; // 雙重防呆，理論上不會走到這裡（註冊時已檢查過）

    $type_raw   = $order->get_meta( '_wooecpay_logistic_LogisticsType' );
    $type_map   = array( 'CVS' => '超商取貨', 'HOME' => '宅配' );
    $type_label = isset( $type_map[ $type_raw ] ) ? $type_map[ $type_raw ] : ( $type_raw ? esc_html( $type_raw ) : '—' );

    $shipping_no = twshop_get_order_shipping_no( $order );
    $store_id    = $order->get_meta( '_ecpay_logistic_cvs_store_id' );
    $status      = twshop_get_latest_ecpay_logistic_status( $order->get_id() );
    $dates       = twshop_get_ecpay_logistic_note_dates( $order->get_id() );
    $created_at  = $dates['created_at'] ? wc_format_datetime( $dates['created_at'], 'Y-m-d H:i' ) : '—';
    $updated_at  = $dates['updated_at'] ? wc_format_datetime( $dates['updated_at'], 'Y-m-d H:i' ) : '—';
    $is_cod      = ( $order->get_payment_method() === 'cod' ) ? '是' : '否';
    ?>
    <div class="twshop-order-logistics-info">
        <p><strong>綠界物流編號：</strong><?php echo esc_html( $logistics_id ); ?></p>
        <p><strong>物流類型：</strong><?php echo $type_label; // 已在上方轉義或為固定中文字串 ?></p>
        <p><strong>物流編號：</strong><?php echo esc_html( $shipping_no ?: '—' ); ?></p>
        <p><strong>門市編號：</strong><?php echo esc_html( $store_id ?: '—' ); ?></p>
        <p><strong>物流狀態：</strong><?php echo esc_html( $status ?: '—' ); ?></p>
        <p><strong>申報金額（約略值）：</strong><?php echo wc_price( $order->get_total() ); ?></p>
        <p><strong>代收貨款：</strong><?php echo esc_html( $is_cod ); ?></p>
        <p><strong>建立時間：</strong><?php echo esc_html( $created_at ); ?></p>
        <p><strong>更新時間：</strong><?php echo esc_html( $updated_at ); ?></p>
        <p class="twshop-order-logistics-actions">
            <button type="button" class="button" id="twshop-print-order-logistics">列印託運單</button>
        </p>
    </div>
    <?php
    // metabox 是在頁面主體渲染時才輸出的，這時 wp_head 早就跑完了。WordPress 對這種
    // 「晚到的」樣式有 print_late_styles()（掛在 admin_print_footer_scripts），會補印在
    // 頁尾，所以在這裡 enqueue 是有效的——但這件事只靠讀程式碼看不出來，
    // .dev-tools/asset-print.php 有一個 case 實測它真的被印出來。
    wp_enqueue_style(
        'twshop-order-admin',
        TWSHOP_PLUGIN_URL . 'assets/css/twshop-order-admin.css',
        array(),
        filemtime( TWSHOP_PLUGIN_DIR . 'assets/css/twshop-order-admin.css' )
    );
    twshop_enqueue_asset_script( 'admin/order-logistics' );
    ?>
    <?php
}

