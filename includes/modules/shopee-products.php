<?php
/**
 * 蝦皮串接模組：商品對應表 CRUD、蝦皮商品清單抓取、SKU 自動配對、庫存/價格推送。
 *
 * 商品不做跨平台上架，只做「已存在的雙邊商品依 SKU 對應綁定」，綁定後才推價格/庫存。
 * 方向固定是 Woo → 蝦皮（Woo 為主權），永遠不從蝦皮回寫 Woo 庫存/價格。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// Phase 2：商品對應
// =========================================================================

/**
 * 分頁抓蝦皮商品清單、補基本資料、多規格商品再取 model/SKU，寫進對應表（status=unlinked）。
 * 需要已完成授權（access_token）才能真正打 API；未授權時回傳 WP_Error。
 */
function twshop_shopee_fetch_items() {
    $shop = twshop_shopee_shop();
    if ( empty( $shop['access_token'] ) ) {
        return new WP_Error( 'twshop_shopee_not_authorized', '尚未完成蝦皮賣場授權' );
    }

    $offset    = 0;
    $page_size = 100;
    $all_rows  = array();

    do {
        $list_result = twshop_shopee_request( '/api/v2/product/get_item_list', array(
            'offset'      => $offset,
            'page_size'   => $page_size,
            'item_status' => array( 'NORMAL' ),
        ), 'GET' );

        if ( is_wp_error( $list_result ) ) return $list_result;

        $response = $list_result['response'] ?? array();
        $items    = $response['item'] ?? array();
        $item_ids = wp_list_pluck( $items, 'item_id' );

        foreach ( array_chunk( $item_ids, 50 ) as $chunk ) {
            $base_info_result = twshop_shopee_request( '/api/v2/product/get_item_base_info', array(
                'item_id_list' => $chunk,
            ), 'POST' );

            if ( is_wp_error( $base_info_result ) ) continue;

            $item_list = ( $base_info_result['response'] ?? array() )['item_list'] ?? array();

            foreach ( $item_list as $item ) {
                $item_id   = (int) ( $item['item_id'] ?? 0 );
                if ( ! $item_id ) continue;

                $has_model = ! empty( $item['has_model'] );

                if ( $has_model ) {
                    $models_result = twshop_shopee_request( '/api/v2/product/get_model_list', array(
                        'item_id' => $item_id,
                    ), 'GET' );

                    $models = is_wp_error( $models_result ) ? array() : ( ( $models_result['response'] ?? array() )['model'] ?? array() );

                    foreach ( $models as $model ) {
                        $row = array(
                            'shop_id'  => (int) $shop['shop_id'],
                            'item_id'  => $item_id,
                            'model_id' => (int) ( $model['model_id'] ?? 0 ),
                            'sku'      => sanitize_text_field( $model['model_sku'] ?? '' ),
                        );
                        twshop_shopee_upsert_item_row( $row );
                        $all_rows[] = $row;
                    }
                } else {
                    $row = array(
                        'shop_id'  => (int) $shop['shop_id'],
                        'item_id'  => $item_id,
                        'model_id' => 0,
                        'sku'      => sanitize_text_field( $item['item_sku'] ?? '' ),
                    );
                    twshop_shopee_upsert_item_row( $row );
                    $all_rows[] = $row;
                }
            }
        }

        $has_next = ! empty( $response['has_next_page'] );
        $offset  += $page_size;
    } while ( $has_next );

    return $all_rows;
}

/**
 * 依唯一索引 (shop_id, item_id, model_id) upsert 一筆對應表列。新列預設 status=unlinked，
 * 既有列只更新 SKU（不動 status/product_id，避免覆蓋管理員已手動處理的綁定狀態）。
 */
function twshop_shopee_upsert_item_row( array $row ) {
    global $wpdb;
    $table = twshop_shopee_items_table();

    $existing_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE shop_id=%d AND item_id=%d AND model_id=%d",
        $row['shop_id'], $row['item_id'], $row['model_id']
    ) );

    if ( $existing_id ) {
        $wpdb->update( $table, array( 'sku' => $row['sku'] ), array( 'id' => $existing_id ), array( '%s' ), array( '%d' ) );
        return (int) $existing_id;
    }

    $wpdb->insert( $table, array(
        'shop_id'  => $row['shop_id'],
        'item_id'  => $row['item_id'],
        'model_id' => $row['model_id'],
        'sku'      => $row['sku'],
        'status'   => 'unlinked',
    ), array( '%d', '%d', '%d', '%s', '%s' ) );

    return (int) $wpdb->insert_id;
}

/**
 * 純邏輯配對：依 SKU 精確比對 Woo 商品/規格的 _sku。
 * 0 筆 → unlinked；1 筆 → linked；≥2 筆 → conflict（絕不自動綁，SKU 重複在 Woo 是允許的，
 * 猜錯會把庫存推到錯的商品上）。
 */
function twshop_shopee_match_sku_status( $sku ) {
    global $wpdb;
    $sku = trim( (string) $sku );

    if ( '' === $sku ) {
        return array( 'status' => 'unlinked', 'product_id' => 0 );
    }

    $product_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s",
        $sku
    ) );
    $product_ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );

    $count = count( $product_ids );

    if ( 0 === $count ) return array( 'status' => 'unlinked', 'product_id' => 0 );
    if ( 1 === $count ) return array( 'status' => 'linked', 'product_id' => $product_ids[0] );
    return array( 'status' => 'conflict', 'product_id' => 0 );
}

/**
 * 自動配對：$items 為 null 時從對應表讀取目前 unlinked/conflict 的列並寫回配對結果；
 * 也可直接傳入一組假的蝦皮商品陣列（每筆至少含 'sku'，測試/離線驗證用），此時只回傳
 * 計算結果、不寫入資料庫（陣列裡沒有 'id' 鍵代表不是資料庫列）。
 *
 * @param array|null $items
 * @return array 每筆為 $items 原始欄位 + 'status'/'product_id'
 */
function twshop_shopee_auto_match( $items = null ) {
    global $wpdb;
    $table = twshop_shopee_items_table();

    if ( null === $items ) {
        $rows = $wpdb->get_results( "SELECT id, sku FROM {$table} WHERE status IN ('unlinked','conflict')", ARRAY_A );
    } else {
        $rows = $items;
    }

    $results = array();

    foreach ( (array) $rows as $row ) {
        $match = twshop_shopee_match_sku_status( $row['sku'] ?? '' );

        if ( isset( $row['id'] ) ) {
            $wpdb->update(
                $table,
                array( 'status' => $match['status'], 'product_id' => $match['product_id'] ),
                array( 'id' => $row['id'] ),
                array( '%s', '%d' ),
                array( '%d' )
            );
        }

        $results[] = array_merge( $row, $match );
    }

    return $results;
}

// =========================================================================
// Phase 3：庫存與價格推送
// =========================================================================

/**
 * 待推送佇列，用 option 存唯一 product_id 陣列（避免結帳流程中同步打外部 API 拖慢結帳）。
 */
function twshop_shopee_queue_push( $product_id ) {
    $product_id = (int) $product_id;
    if ( $product_id <= 0 ) return;

    $queue = get_option( 'twshop_shopee_push_queue', array() );
    if ( ! in_array( $product_id, $queue, true ) ) {
        $queue[] = $product_id;
        update_option( 'twshop_shopee_push_queue', $queue, false );
    }
}

/**
 * woocommerce_product_set_stock / woocommerce_variation_set_stock / woocommerce_update_product /
 * woocommerce_update_product_variation 共用的佇列 callback——四個 hook 的第一個參數簽名不同
 * （有的是 WC_Product 物件、有的是 product ID），這裡統一容錯處理。
 */
function twshop_shopee_queue_push_from_hook( $product ) {
    $id = is_object( $product ) && method_exists( $product, 'get_id' ) ? $product->get_id() : (int) $product;
    twshop_shopee_queue_push( $id );
}

/**
 * 5 分鐘 cron：批次把待推送佇列推出去。每批最多 50 筆，避免單次執行過久；
 * 剩餘的留在佇列，下一輪繼續處理。跟 last_pushed_stock/last_pushed_price 相同就跳過，
 * 不產生 API 呼叫（省額度，也是驗證第 7 項的判準）。
 */
function twshop_shopee_process_push_queue() {
    $shop = twshop_shopee_shop();
    if ( empty( $shop['access_token'] ) ) return;

    $settings = twshop_shopee_sync_settings();
    $queue    = get_option( 'twshop_shopee_push_queue', array() );
    if ( empty( $queue ) ) return;

    $batch     = array_slice( $queue, 0, 50 );
    $remaining = array_slice( $queue, 50 );
    update_option( 'twshop_shopee_push_queue', array_values( $remaining ), false );

    foreach ( $batch as $product_id ) {
        twshop_shopee_push_product( (int) $product_id, $settings );
    }
}

/**
 * 單一商品的推送邏輯（庫存/價格各自判斷是否跳過），供 cron 批次與「立即推送」AJAX 共用。
 */
function twshop_shopee_push_product( $product_id, $settings = null ) {
    global $wpdb;
    if ( null === $settings ) $settings = twshop_shopee_sync_settings();

    $table = twshop_shopee_items_table();
    $rows  = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE product_id=%d AND status='linked'", $product_id
    ), ARRAY_A );
    if ( empty( $rows ) ) return;

    $product = wc_get_product( $product_id );
    if ( ! $product ) return;

    foreach ( $rows as $row ) {
        if ( 'yes' === $settings['stock_push_enabled'] && $product->get_manage_stock() ) {
            $stock = max( 0, (int) $product->get_stock_quantity() - (int) $settings['stock_buffer'] );

            if ( null === $row['last_pushed_stock'] || (int) $row['last_pushed_stock'] !== $stock ) {
                $result = twshop_shopee_request( '/api/v2/product/update_stock', array(
                    'item_id'    => (int) $row['item_id'],
                    'stock_list' => array( array(
                        'model_id'     => (int) $row['model_id'],
                        'seller_stock' => array( array( 'stock' => $stock ) ),
                    ) ),
                ), 'POST' );

                if ( ! is_wp_error( $result ) ) {
                    $wpdb->update( $table, array(
                        'last_pushed_stock' => $stock,
                        'last_synced_at'    => current_time( 'mysql' ),
                        'last_error'        => null,
                    ), array( 'id' => $row['id'] ) );
                } else {
                    $wpdb->update( $table, array( 'last_error' => $result->get_error_message() ), array( 'id' => $row['id'] ) );
                }
            }
        }

        if ( 'yes' === $settings['price_push_enabled'] ) {
            $price = (float) $product->get_regular_price();

            if ( $price > 0 && ( null === $row['last_pushed_price'] || abs( (float) $row['last_pushed_price'] - $price ) > 0.001 ) ) {
                $result = twshop_shopee_request( '/api/v2/product/update_price', array(
                    'item_id'    => (int) $row['item_id'],
                    'price_list' => array( array(
                        'model_id'       => (int) $row['model_id'],
                        'original_price' => $price,
                    ) ),
                ), 'POST' );

                if ( ! is_wp_error( $result ) ) {
                    $wpdb->update( $table, array(
                        'last_pushed_price' => $price,
                        'last_synced_at'    => current_time( 'mysql' ),
                        'last_error'        => null,
                    ), array( 'id' => $row['id'] ) );
                } else {
                    $wpdb->update( $table, array( 'last_error' => $result->get_error_message() ), array( 'id' => $row['id'] ) );
                }
            }
        }
    }
}
