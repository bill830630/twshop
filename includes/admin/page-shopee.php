<?php
/**
 * 蝦皮串接後台頁面（4 個頁籤）＋ 所有 AJAX handler。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 頁面外框
// =========================================================================

function twshop_shopee_render_page() {
    twshop_render_admin_page( '蝦皮串接', function () {
        $tabs = array(
            'auth'    => '授權',
            'mapping' => '商品對應',
            'sync'    => '同步設定',
            'log'     => '同步紀錄',
        );
        $current = twshop_get_current_admin_tab( $tabs );
        twshop_render_admin_tabs( $tabs, $current, 'twshop-shopee' );

        if ( 'auth' === $current ) {
            twshop_shopee_auth_tab();
        } elseif ( 'mapping' === $current ) {
            twshop_shopee_mapping_tab();
        } elseif ( 'sync' === $current ) {
            twshop_shopee_sync_tab();
        } elseif ( 'log' === $current ) {
            twshop_shopee_log_tab();
        }
    } );
}

function twshop_shopee_render_not_configured_notice() {
    ?>
    <div class="twshop-panel">
        <?php twshop_panel_head( 'lock', '請先完成授權' ); ?>
        <div class="twshop-panel-body" style="padding:4px 24px 20px;">
            <p>請先到「授權」頁籤設定 Partner ID / Partner Key，並完成蝦皮賣場授權。</p>
        </div>
    </div>
    <?php
}

// =========================================================================
// 頁籤：授權
// =========================================================================

function twshop_shopee_auth_tab() {
    $creds = twshop_shopee_credentials();
    $shop  = twshop_shopee_shop();
    ?>
    <?php if ( isset( $_GET['shopee_authorized'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>蝦皮賣場授權成功。</p></div>
    <?php elseif ( isset( $_GET['refresh'] ) ) : ?>
        <?php if ( 'success' === $_GET['refresh'] ) : ?>
            <div class="notice notice-success is-dismissible"><p>Token 續期成功。</p></div>
        <?php else : ?>
            <div class="notice notice-error is-dismissible"><p>Token 續期失敗，請檢查憑證或稍後再試。</p></div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'twshop_shopee_credentials_group' ); ?>
        <div class="twshop-panel">
            <?php
            twshop_panel_head(
                'lock',
                '蝦皮 API 憑證',
                // $hint 會被 twshop_panel_head() 包進 <p class="twshop-panel-hint">，
                // 這裡不能再自己包一層 <p>（瀏覽器遇到巢狀 <p> 會提前關閉外層，說明文字
                // 就拿不到 .twshop-panel-hint 的樣式）。
                '台灣蝦皮 Open API 目前只開放給商城賣家（Shopee Mall）或第三方系統供應商（ERP）申請，一般賣場帳號多半申請不過。尚未取得 <code>partner_id</code>/<code>partner_key</code> 前，以下設定僅供離線驗證架構，實際打蝦皮端點會失敗。<br>timestamp 是秒級 Unix time，蝦皮容忍誤差很小，本機（伺服器）時鐘偏移會讓全部簽章失敗。'
            );
            ?>
            <div class="twshop-panel-body" style="padding:4px 24px 20px;">
            <table class="form-table">
                <tr>
                    <th><label for="twshop_shopee_partner_id">Partner ID</label></th>
                    <td><input type="text" id="twshop_shopee_partner_id" name="twshop_shopee_credentials[partner_id]" value="<?php echo esc_attr( $creds['partner_id'] ); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="twshop_shopee_partner_key">Partner Key</label></th>
                    <td>
                        <input type="password" id="twshop_shopee_partner_key" name="twshop_shopee_credentials[partner_key]" value="<?php echo esc_attr( ! empty( $creds['partner_key'] ) ? TWSHOP_SHOPEE_KEY_MASK : '' ); ?>" class="regular-text" autocomplete="new-password">
                        <p class="description">已設定時顯示遮罩，不需更改請保持原樣直接儲存；要更換金鑰請整段清除後貼上新值。</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="twshop_shopee_env">環境</label></th>
                    <td>
                        <select id="twshop_shopee_env" name="twshop_shopee_credentials[env]">
                            <option value="sandbox" <?php selected( $creds['env'], 'sandbox' ); ?>>沙盒（測試，test-stable）</option>
                            <option value="live" <?php selected( $creds['env'], 'live' ); ?>>正式</option>
                        </select>
                    </td>
                </tr>
            </table>
            </div>
        </div>
        <?php submit_button( '儲存憑證' ); ?>
    </form>

    <div class="twshop-panel">
        <?php twshop_panel_head( 'network', '賣場授權狀態' ); ?>
        <div class="twshop-panel-body" style="padding:4px 24px 20px;">
        <?php if ( empty( $shop['access_token'] ) ) : ?>
            <p>尚未授權任何蝦皮賣場。</p>
            <?php if ( twshop_shopee_has_credentials() ) : ?>
                <a href="<?php echo esc_url( twshop_shopee_get_auth_url() ); ?>" class="button button-primary">前往蝦皮授權</a>
            <?php else : ?>
                <p class="description">請先儲存 Partner ID / Partner Key 才能開始授權。</p>
            <?php endif; ?>
        <?php else : ?>
            <table class="widefat" style="max-width:640px;">
                <tbody>
                    <tr><th style="width:160px;">賣場 ID</th><td><?php echo esc_html( $shop['shop_id'] ); ?></td></tr>
                    <tr><th>Token 到期時間</th><td><?php echo esc_html( $shop['expire_at'] ? date_i18n( 'Y-m-d H:i', $shop['expire_at'] ) : '—' ); ?></td></tr>
                    <tr><th>上次續期時間</th><td><?php echo esc_html( $shop['refreshed_at'] ? date_i18n( 'Y-m-d H:i', $shop['refreshed_at'] ) : '—' ); ?></td></tr>
                    <tr><th>授權時間</th><td><?php echo esc_html( $shop['authorized_at'] ? date_i18n( 'Y-m-d H:i', $shop['authorized_at'] ) : '—' ); ?></td></tr>
                </tbody>
            </table>
            <p style="margin-top:12px;">
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=twshop-shopee&tab=auth&twshop_shopee_manual_refresh=1' ), 'twshop_shopee_manual_refresh' ) ); ?>" class="button">手動續期 Token</a>
            </p>
        <?php endif; ?>
        </div>
    </div>
    <?php
}

// =========================================================================
// 頁籤：商品對應
// =========================================================================

function twshop_shopee_mapping_tab() {
    if ( ! twshop_shopee_has_credentials() ) {
        twshop_shopee_render_not_configured_notice();
        return;
    }

    global $wpdb;
    $table = twshop_shopee_items_table();

    $status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
    $where = '';
    if ( in_array( $status_filter, array( 'linked', 'unlinked', 'conflict' ), true ) ) {
        $where = $wpdb->prepare( ' WHERE status = %s', $status_filter );
    }
    $rows = $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT 200", ARRAY_A );

    twshop_enqueue_asset_script( 'admin/shopee-mapping', array(
        'twshopShopeeMapping' => array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'twshop_admin_action' ),
            'i18n'    => array(
                'working'       => '處理中…',
                'done'          => '完成',
                'error'         => '發生錯誤',
                'confirmUnlink' => '確定要解除這筆綁定嗎？',
                'confirmClear'  => '確定要清空所有同步紀錄嗎？此動作無法復原。',
                'needProductId' => '請輸入有效的 Woo 商品 ID',
            ),
        ),
    ) );
    ?>
    <div class="twshop-panel">
        <?php
        twshop_panel_head(
            'package',
            '商品對應表',
            // 同樣不能自己包 <p>，理由見「授權」頁籤那個 twshop_panel_head() 的註解。
            '只做「已存在的雙邊商品依 SKU 對應綁定」，不做跨平台上架。SKU 在 Woo 端出現重複時會標記為「衝突」，不會自動綁定。'
        );
        ?>
        <div class="twshop-panel-body" style="padding:4px 24px 20px;">
            <p>
                <button type="button" class="button" id="twshop-shopee-fetch-items">重新抓取蝦皮商品並自動配對</button>
                <button type="button" class="button" id="twshop-shopee-push-all">立即全量推送</button>
                <span id="twshop-shopee-mapping-status" style="margin-left:8px;"></span>
            </p>
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=twshop-shopee&tab=mapping' ) ); ?>">全部</a> |
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=twshop-shopee&tab=mapping&status=linked' ) ); ?>">已綁定</a> |
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=twshop-shopee&tab=mapping&status=unlinked' ) ); ?>">未綁定</a> |
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=twshop-shopee&tab=mapping&status=conflict' ) ); ?>">衝突</a>
            </p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>蝦皮商品 ID</th><th>規格 ID</th><th>SKU</th><th>狀態</th>
                        <th>對應 Woo 商品</th><th>上次同步</th><th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="7">目前沒有資料，請先按「重新抓取蝦皮商品並自動配對」。</td></tr>
                <?php else : foreach ( $rows as $row ) :
                    $product = $row['product_id'] ? wc_get_product( $row['product_id'] ) : null;
                ?>
                    <tr data-row-id="<?php echo esc_attr( $row['id'] ); ?>">
                        <td><?php echo esc_html( $row['item_id'] ); ?></td>
                        <td><?php echo esc_html( $row['model_id'] ); ?></td>
                        <td><?php echo esc_html( $row['sku'] ); ?></td>
                        <td><?php echo esc_html( $row['status'] ); ?></td>
                        <td><?php echo $product ? esc_html( $product->get_name() . ' (#' . $product->get_id() . ')' ) : '—'; ?></td>
                        <td><?php echo esc_html( $row['last_synced_at'] ?: '—' ); ?></td>
                        <td>
                            <?php if ( 'linked' === $row['status'] ) : ?>
                                <button type="button" class="button twshop-shopee-unlink" data-id="<?php echo esc_attr( $row['id'] ); ?>">解除綁定</button>
                            <?php else : ?>
                                <input type="number" min="1" class="small-text twshop-shopee-link-product-id" placeholder="Woo 商品ID">
                                <button type="button" class="button twshop-shopee-link" data-id="<?php echo esc_attr( $row['id'] ); ?>">綁定</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

// =========================================================================
// 頁籤：同步設定
// =========================================================================

function twshop_shopee_sync_tab() {
    if ( ! twshop_shopee_has_credentials() ) {
        twshop_shopee_render_not_configured_notice();
        return;
    }

    $settings       = twshop_shopee_sync_settings();
    $order_statuses = array( 'UNPAID', 'READY_TO_SHIP', 'PROCESSED', 'SHIPPED', 'COMPLETED', 'CANCELLED', 'TO_RETURN', 'INVOICE_PENDING' );

    twshop_enqueue_asset_script( 'admin/shopee-mapping', array(
        'twshopShopeeMapping' => array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'twshop_admin_action' ),
            'i18n'    => array(
                'working' => '處理中…',
                'done'    => '完成',
                'error'   => '發生錯誤',
            ),
        ),
    ) );
    ?>
    <form method="post" action="options.php">
        <?php settings_fields( 'twshop_shopee_sync_group' ); ?>
        <div class="twshop-panel">
            <?php twshop_panel_head( 'settings', '同步設定' ); ?>
            <div class="twshop-panel-body" style="padding:4px 24px 20px;">
            <table class="form-table">
                <tr>
                    <th>庫存推送</th>
                    <td><label><input type="checkbox" name="twshop_shopee_sync_settings[stock_push_enabled]" value="yes" <?php checked( $settings['stock_push_enabled'], 'yes' ); ?>> 啟用（Woo → 蝦皮，永遠不回寫）</label></td>
                </tr>
                <tr>
                    <th>價格推送</th>
                    <td><label><input type="checkbox" name="twshop_shopee_sync_settings[price_push_enabled]" value="yes" <?php checked( $settings['price_push_enabled'], 'yes' ); ?>> 啟用</label></td>
                </tr>
                <tr>
                    <th>訂單匯入</th>
                    <td><label><input type="checkbox" name="twshop_shopee_sync_settings[order_import_enabled]" value="yes" <?php checked( $settings['order_import_enabled'], 'yes' ); ?>> 啟用</label></td>
                </tr>
                <tr>
                    <th>要匯入的蝦皮訂單狀態</th>
                    <td>
                    <?php foreach ( $order_statuses as $status ) : ?>
                        <label style="display:inline-block;margin:2px 12px 2px 0;">
                            <input type="checkbox" name="twshop_shopee_sync_settings[order_import_status][]" value="<?php echo esc_attr( $status ); ?>" <?php checked( in_array( $status, (array) $settings['order_import_status'], true ) ); ?>>
                            <?php echo esc_html( $status ); ?>
                        </label>
                    <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="twshop_shopee_default_order_status">Woo 訂單初始狀態</label></th>
                    <td>
                        <select id="twshop_shopee_default_order_status" name="twshop_shopee_sync_settings[default_order_status]">
                            <?php foreach ( wc_get_order_statuses() as $key => $label ) : $slug = str_replace( 'wc-', '', $key ); ?>
                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['default_order_status'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="twshop_shopee_stock_buffer">安全庫存（緩衝量）</label></th>
                    <td><input type="number" min="0" id="twshop_shopee_stock_buffer" name="twshop_shopee_sync_settings[stock_buffer]" value="<?php echo esc_attr( $settings['stock_buffer'] ); ?>" class="small-text"> 推到蝦皮時，庫存會先扣掉這個緩衝量</td>
                </tr>
                <tr>
                    <th><label for="twshop_shopee_log_retention_days">紀錄保留天數</label></th>
                    <td><input type="number" min="1" id="twshop_shopee_log_retention_days" name="twshop_shopee_sync_settings[log_retention_days]" value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>" class="small-text"></td>
                </tr>
            </table>
            </div>
        </div>
        <?php submit_button( '儲存同步設定' ); ?>
    </form>

    <div class="twshop-panel">
        <?php twshop_panel_head( 'clipboard-list', '手動觸發' ); ?>
        <div class="twshop-panel-body" style="padding:4px 24px 20px;">
            <button type="button" class="button" id="twshop-shopee-pull-orders">立即匯入蝦皮訂單</button>
            <span id="twshop-shopee-sync-status" style="margin-left:8px;"></span>
        </div>
    </div>
    <?php
}

// =========================================================================
// 頁籤：同步紀錄
// =========================================================================

function twshop_shopee_log_tab() {
    if ( ! twshop_shopee_has_credentials() ) {
        twshop_shopee_render_not_configured_notice();
        return;
    }

    global $wpdb;
    $table = twshop_shopee_log_table();
    $rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A );

    twshop_enqueue_asset_script( 'admin/shopee-mapping', array(
        'twshopShopeeMapping' => array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'twshop_admin_action' ),
            'i18n'    => array(
                'working'      => '處理中…',
                'done'         => '完成',
                'error'        => '發生錯誤',
                'confirmClear' => '確定要清空所有同步紀錄嗎？此動作無法復原。',
            ),
        ),
    ) );
    ?>
    <div class="twshop-panel">
        <?php twshop_panel_head( 'clipboard-list', '同步紀錄' ); ?>
        <div class="twshop-panel-body" style="padding:4px 24px 20px;">
            <p>
                <button type="button" class="button" id="twshop-shopee-clear-log">清空紀錄</button>
                <span id="twshop-shopee-log-status" style="margin-left:8px;"></span>
            </p>
            <table class="widefat striped">
                <thead><tr><th>時間</th><th>方向</th><th>端點</th><th>對象</th><th>結果</th><th>訊息</th></tr></thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="6">目前沒有紀錄。</td></tr>
                <?php else : foreach ( $rows as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row['created_at'] ); ?></td>
                        <td><?php echo esc_html( $row['direction'] ); ?></td>
                        <td><?php echo esc_html( $row['endpoint'] ); ?></td>
                        <td><?php echo esc_html( $row['subject'] ); ?></td>
                        <td><?php echo $row['success'] ? '成功' : '失敗'; ?></td>
                        <td><?php echo esc_html( mb_substr( (string) $row['message'], 0, 120 ) ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

// =========================================================================
// AJAX handlers（全部 current_user_can('manage_woocommerce') + check_ajax_referer）
// =========================================================================

function twshop_shopee_ajax_guard() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'msg' => '權限不足' ) );
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );
}

function twshop_ajax_shopee_fetch_items() {
    twshop_shopee_ajax_guard();

    if ( ! twshop_shopee_has_credentials() ) wp_send_json_error( array( 'msg' => '尚未設定蝦皮憑證' ) );

    $result = twshop_shopee_fetch_items();
    if ( is_wp_error( $result ) ) wp_send_json_error( array( 'msg' => $result->get_error_message() ) );

    twshop_shopee_auto_match();

    wp_send_json_success( array( 'msg' => '已抓取並自動配對', 'count' => is_array( $result ) ? count( $result ) : 0 ) );
}

function twshop_ajax_shopee_link_item() {
    twshop_shopee_ajax_guard();

    $row_id = absint( $_POST['row_id'] ?? 0 );
    if ( ! $row_id ) wp_send_json_error( array( 'msg' => '缺少列 ID' ) );

    global $wpdb;
    $table = twshop_shopee_items_table();

    if ( ! empty( $_POST['unlink'] ) ) {
        $wpdb->update( $table, array( 'status' => 'unlinked', 'product_id' => 0 ), array( 'id' => $row_id ), array( '%s', '%d' ), array( '%d' ) );
        wp_send_json_success( array( 'msg' => '已解除綁定' ) );
    }

    $product_id = absint( $_POST['product_id'] ?? 0 );
    if ( ! $product_id || ! wc_get_product( $product_id ) ) {
        wp_send_json_error( array( 'msg' => '請輸入有效的 Woo 商品 ID' ) );
    }

    $wpdb->update( $table, array( 'status' => 'linked', 'product_id' => $product_id ), array( 'id' => $row_id ), array( '%s', '%d' ), array( '%d' ) );
    wp_send_json_success( array( 'msg' => '已綁定' ) );
}

function twshop_ajax_shopee_push_now() {
    twshop_shopee_ajax_guard();

    if ( ! twshop_shopee_has_credentials() ) wp_send_json_error( array( 'msg' => '尚未設定蝦皮憑證' ) );

    $product_id = absint( $_POST['product_id'] ?? 0 );

    if ( $product_id ) {
        twshop_shopee_queue_push( $product_id );
    } else {
        global $wpdb;
        $ids = $wpdb->get_col( "SELECT DISTINCT product_id FROM " . twshop_shopee_items_table() . " WHERE status='linked' AND product_id > 0" );
        foreach ( $ids as $id ) twshop_shopee_queue_push( (int) $id );
    }

    twshop_shopee_process_push_queue();

    wp_send_json_success( array( 'msg' => '已推送目前批次（剩餘會由排程接續處理）' ) );
}

function twshop_ajax_shopee_pull_orders() {
    twshop_shopee_ajax_guard();

    if ( ! twshop_shopee_has_credentials() ) wp_send_json_error( array( 'msg' => '尚未設定蝦皮憑證' ) );

    twshop_shopee_pull_orders();

    wp_send_json_success( array( 'msg' => '已觸發訂單匯入' ) );
}

function twshop_ajax_shopee_clear_log() {
    twshop_shopee_ajax_guard();

    global $wpdb;
    $wpdb->query( 'TRUNCATE TABLE ' . twshop_shopee_log_table() );

    wp_send_json_success( array( 'msg' => '已清空紀錄' ) );
}
