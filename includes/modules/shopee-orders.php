<?php
/**
 * 蝦皮串接模組：訂單輪詢、蝦皮訂單 → Woo 訂單轉換、狀態同步。
 *
 * 蝦皮訂單自動變成真實 Woo 訂單（wc_create_order()），進報表、進出貨流程、參與既有的
 * twshop 訂單物流/後台強化功能。冪等鍵是 _twshop_shopee_order_sn order meta——重複匯入
 * 會重複扣 Woo 庫存、重複推回蝦皮，錯誤會擴散，是整個模組最重要的一道防線。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 依 (item_id, model_id) 反查已綁定的 Woo product/variation ID，找不到回傳 0。
 */
function twshop_shopee_find_mapped_product( $item_id, $model_id ) {
    global $wpdb;
    $table = twshop_shopee_items_table();

    $product_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT product_id FROM {$table} WHERE item_id=%d AND model_id=%d AND status='linked' LIMIT 1",
        (int) $item_id, (int) $model_id
    ) );

    return $product_id ? (int) $product_id : 0;
}

/**
 * 核心轉換函式：接受一份蝦皮訂單 detail（get_order_detail 回應裡單筆 order）陣列，
 * 建立/更新對應的 Woo 訂單。**冪等**：先用 _twshop_shopee_order_sn 查有沒有匯入過，
 * 有就只同步狀態、不重建。
 *
 * 刻意設計成不呼叫任何 twshop_shopee_request()，方便離線用假 JSON 直接驗證整條轉換邏輯
 * （見 CLAUDE.md 驗證章節「訂單轉換與冪等」）。
 *
 * @param array $detail 單筆蝦皮訂單詳情
 * @return WC_Order|WP_Error
 */
function twshop_shopee_import_order( array $detail ) {
    $order_sn = sanitize_text_field( $detail['order_sn'] ?? '' );
    if ( '' === $order_sn ) {
        return new WP_Error( 'twshop_shopee_missing_order_sn', '缺少蝦皮訂單編號' );
    }

    $existing = wc_get_orders( array(
        'meta_key'   => '_twshop_shopee_order_sn',
        'meta_value' => $order_sn,
        'limit'      => 1,
        'return'     => 'ids',
    ) );

    if ( ! empty( $existing ) ) {
        $order = wc_get_order( $existing[0] );
        twshop_shopee_sync_order_status( $order, $detail['order_status'] ?? '' );
        return $order;
    }

    $settings = twshop_shopee_sync_settings();
    $order    = wc_create_order();
    $unmapped = array();

    foreach ( (array) ( $detail['item_list'] ?? array() ) as $item ) {
        $item_id    = (int) ( $item['item_id'] ?? 0 );
        $model_id   = (int) ( $item['model_id'] ?? 0 );
        $qty        = max( 1, (int) ( $item['model_quantity_purchased'] ?? $item['quantity'] ?? 1 ) );
        $item_price = (float) ( $item['model_discounted_price'] ?? $item['model_original_price'] ?? 0 );
        $line_total = round( $item_price * $qty, 2 );

        $mapped_product_id = twshop_shopee_find_mapped_product( $item_id, $model_id );
        $product            = $mapped_product_id ? wc_get_product( $mapped_product_id ) : null;

        if ( $product ) {
            // 用蝦皮實收價，不用 Woo 定價。
            $order->add_product( $product, $qty, array(
                'subtotal' => $line_total,
                'total'    => $line_total,
            ) );
        } else {
            // 對應不到 Woo 商品的品項：不靜默丟掉，改用純文字項目 + 訂單備註標明。
            $name       = sanitize_text_field( $item['item_name'] ?? ( '未知品項 #' . $item_id ) );
            $order_item = new WC_Order_Item_Product();
            $order_item->set_name( $name . '（未綁定 Woo 商品）' );
            $order_item->set_quantity( $qty );
            $order_item->set_subtotal( $line_total );
            $order_item->set_total( $line_total );
            $order->add_item( $order_item );
            $unmapped[] = $name;
        }
    }

    // 蝦皮的個資可能是遮罩過的，不假設拿得到完整姓名/電話。
    $recipient  = (array) ( $detail['recipient_address'] ?? array() );
    $full_name  = trim( (string) ( $recipient['name'] ?? '' ) );
    $name_parts = '' !== $full_name ? explode( ' ', $full_name, 2 ) : array( '', '' );
    $first_name = $name_parts[0] ?? '';
    $last_name  = $name_parts[1] ?? '';
    $phone      = sanitize_text_field( $recipient['phone'] ?? '' );
    $address    = sanitize_text_field( $recipient['full_address'] ?? '' );

    $order->set_billing_first_name( $first_name );
    $order->set_billing_last_name( $last_name );
    $order->set_billing_phone( $phone );
    if ( '' !== $address ) $order->set_billing_address_1( $address );

    $order->set_shipping_first_name( $first_name );
    $order->set_shipping_last_name( $last_name );
    if ( '' !== $address ) $order->set_shipping_address_1( $address );

    $order->set_payment_method_title( '蝦皮' );
    $order->add_order_note( sprintf( '蝦皮訂單匯入，蝦皮訂單編號：%s', $order_sn ) );

    if ( ! empty( $unmapped ) ) {
        $order->add_order_note( '以下品項未綁定 Woo 商品、庫存未扣，請至「蝦皮串接 ▸ 商品對應」手動處理：' . implode( '、', $unmapped ) );
    }

    $order->update_meta_data( '_twshop_shopee_order_sn', $order_sn );
    $order->update_meta_data( '_twshop_shopee_shop_id', (int) ( $detail['shop_id'] ?? twshop_shopee_shop()['shop_id'] ) );
    $order->update_meta_data( '_twshop_shopee_status', sanitize_text_field( $detail['order_status'] ?? '' ) );
    $order->update_meta_data( '_twshop_shopee_synced_at', current_time( 'mysql' ) );

    // 走 set_status() 而非 update_status()，一次存檔完成。
    $order->set_status( $settings['default_order_status'] );
    $order->calculate_totals( false );
    $order->save();

    return $order;
}

/**
 * 蝦皮端狀態變化同步到 Woo 訂單狀態。CANCELLED → cancelled（WooCommerce 會自動回補庫存，
 * 待推送佇列再推回蝦皮，閉環成立）；SHIPPED → order_checkout_enhancements 模組的自訂狀態
 * wc-twshop-shipped（該模組關閉、自訂狀態未註冊時退回 completed，避免 set_status() 收到
 * 一個系統不認得的狀態 slug）。
 */
function twshop_shopee_sync_order_status( $order, $shopee_status ) {
    if ( ! $order instanceof WC_Order ) return;

    $shopee_status = strtoupper( sanitize_text_field( (string) $shopee_status ) );
    if ( '' === $shopee_status ) return;

    $map = array(
        'CANCELLED' => 'cancelled',
        'SHIPPED'   => 'twshop-shipped',
    );

    if ( isset( $map[ $shopee_status ] ) ) {
        $target = $map[ $shopee_status ];

        if ( 'twshop-shipped' === $target && ! twshop_module_enabled( 'order_checkout_enhancements' ) ) {
            $target = 'completed';
        }

        if ( $order->get_status() !== str_replace( 'wc-', '', $target ) ) {
            $order->set_status( $target );
        }
    }

    $order->update_meta_data( '_twshop_shopee_status', $shopee_status );
    $order->update_meta_data( '_twshop_shopee_synced_at', current_time( 'mysql' ) );
    $order->save();
}

/**
 * 15 分鐘 cron：抓上次成功時間往前推 1 小時的重疊區間（避免邊界漏單，冪等鍵擋重複），
 * 蝦皮限制單次區間 ≤ 15 天，超過就收斂成最近 15 天。
 */
function twshop_shopee_pull_orders() {
    $shop = twshop_shopee_shop();
    if ( empty( $shop['access_token'] ) ) return;

    $settings = twshop_shopee_sync_settings();
    if ( 'yes' !== $settings['order_import_enabled'] ) return;

    $last_success = (int) get_option( 'twshop_shopee_orders_last_pull', 0 );
    $time_to       = time();
    $time_from     = $last_success > 0 ? ( $last_success - HOUR_IN_SECONDS ) : ( $time_to - 7 * DAY_IN_SECONDS );

    if ( $time_to - $time_from > 15 * DAY_IN_SECONDS ) {
        $time_from = $time_to - 15 * DAY_IN_SECONDS;
    }

    $cursor = '';

    do {
        $args = array(
            'time_range_field' => 'create_time',
            'time_from'        => $time_from,
            'time_to'          => $time_to,
            'page_size'        => 100,
            'order_status'     => implode( ',', (array) $settings['order_import_status'] ),
        );
        if ( '' !== $cursor ) $args['cursor'] = $cursor;

        $list_result = twshop_shopee_request( '/api/v2/order/get_order_list', $args, 'GET' );
        if ( is_wp_error( $list_result ) ) break;

        $response  = $list_result['response'] ?? array();
        $order_sns = wp_list_pluck( $response['order_list'] ?? array(), 'order_sn' );

        foreach ( array_chunk( $order_sns, 50 ) as $chunk ) {
            $detail_result = twshop_shopee_request( '/api/v2/order/get_order_detail', array(
                'order_sn_list'            => $chunk,
                'response_optional_fields' => array( 'item_list', 'recipient_address', 'total_amount', 'payment_method' ),
            ), 'POST' );

            if ( is_wp_error( $detail_result ) ) continue;

            $orders = ( $detail_result['response'] ?? array() )['order_list'] ?? array();
            foreach ( $orders as $order_detail ) {
                twshop_shopee_import_order( $order_detail );
            }
        }

        $cursor = $response['next_cursor'] ?? '';
        $more   = ! empty( $response['more'] );
    } while ( $more && '' !== $cursor );

    update_option( 'twshop_shopee_orders_last_pull', $time_to );
}

/**
 * 每日 cron：清理過期同步紀錄，保留天數走設定（預設 30 天）。
 */
function twshop_shopee_cleanup_log() {
    global $wpdb;
    $settings = twshop_shopee_sync_settings();
    $days     = max( 1, (int) $settings['log_retention_days'] );
    $table    = twshop_shopee_log_table();

    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$table} WHERE created_at < %s",
        gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
    ) );
}
