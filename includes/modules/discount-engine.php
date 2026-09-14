<?php
/**
 * 6. 折扣規則引擎 (AND/OR、滿額贈與加購品防呆)
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構）。內容為原樣搬移，未做任何邏輯或排版變更。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =========================================================================
// 6. 折扣規則引擎 (AND/OR、滿額贈與加購品防呆)
// =========================================================================

/**
 * 全外掛呼叫頻率最高的函式（price / is_on_sale / fees / shipping / progress 全部經過）。
 * 加 per-request static cache 包一層外殼，實際判斷邏輯搬進 twshop_is_discount_rule_valid_compute()
 * 原封不動（純粹搬移，未改動任何判斷式），只在外層做記憶化。
 *
 * 快取 key 特別注意：呼叫端有 3 處（twshop_get_coupon_progress_items() 與
 * twshop_auto_display_coupons() 兩處）會複製一份 $rule 改寫 c_code/is_coupon 成 $rule_test 再傳入，
 * 目的是刻意繞過「是否已套用此優惠券」的判斷，但 rule_id 跟原始規則完全相同——若 key 只用 rule_id，
 * 會把「真規則」與「c_code/is_coupon 被改寫過的測試副本」兩種不同輸入的結果快取搞混（同一個
 * rule_id 卻可能得到不同答案）。因此 key 額外納入 is_coupon/c_code/c_exclusive 這三個唯一會被
 * 呼叫端改寫的欄位。product_id/user_id/user_roles/cart_total 為函式參數與當前使用者，皆為
 * 直接影響回傳值的輸入，一併納入 key。
 */
function twshop_is_discount_rule_valid( $rule, $user_roles, $cart_total = 0, $product_id = 0 ) {
    static $cache = array();
    // (array) 轉型：改版前 $user_roles 只在規則有 role 限制時才會被讀取，傳入非陣列時多半靜默無事；
    // 現在快取 key 無條件 implode() 它，非陣列會直接 TypeError。這支函式掛在 woocommerce_product_get_price
    // 等價格 filter 上，一次 fatal 就是整個商店頁白畫面，代價遠高於一次轉型，故保留這道防呆。
    // （目前 16 個呼叫端傳的都是 WP_User::$roles 或 array('customer')，必為陣列；這純粹是保險。）
    $user_roles = (array) $user_roles;
    $cache_key = ( $rule['rule_id'] ?? '' ) . '|' . ( $rule['is_coupon'] ?? '' ) . '|' . ( $rule['c_code'] ?? '' ) . '|' . ( $rule['c_exclusive'] ?? '' )
        . '|' . $product_id . '|' . get_current_user_id() . '|' . implode( ',', $user_roles ) . '|' . $cart_total;
    // 購物車層判斷（$product_id = 0）且規則有條件時，結果取決於購物車內容，同一請求內內容可能變動。
    if ( 0 === (int) $product_id ) {
        list( $cond_type, $cond_values ) = twshop_get_rule_condition( $rule );
        if ( ! empty( $cond_type ) && ! empty( $cond_values ) ) {
            $cache_key .= '|' . twshop_cart_condition_fingerprint();
        }
    }
    if ( array_key_exists( $cache_key, $cache ) ) return $cache[ $cache_key ];

    $result = twshop_is_discount_rule_valid_compute( $rule, $user_roles, $cart_total, $product_id );
    $cache[ $cache_key ] = $result;
    return $result;
}

/**
 * 商品在指定分類法下的 term 清單，per-request static cache（key: product_id|taxonomy）。
 * 內部作法與 WordPress has_term()/is_object_in_term() 讀取 term 清單的路徑完全一致
 * （先查 get_object_term_cache()，沒有才 wp_get_object_terms()），只是額外用自己的 static
 * 陣列記住結果，讓「同一商品 × 多條規則」不用每條規則都重新查一次。
 */
function twshop_get_product_terms_cached( $product_id, $taxonomy ) {
    static $cache = array();
    $key = $product_id . '|' . $taxonomy;
    if ( array_key_exists( $key, $cache ) ) return $cache[ $key ];

    $object_terms = get_object_term_cache( $product_id, $taxonomy );
    if ( false === $object_terms ) {
        $object_terms = wp_get_object_terms( $product_id, $taxonomy, array( 'update_term_meta_cache' => false ) );
        if ( is_wp_error( $object_terms ) ) $object_terms = array();
    }

    $cache[ $key ] = $object_terms;
    return $object_terms;
}

/**
 * has_term() 的等效替代，比對邏輯逐行照抄 WordPress is_object_in_term() 在取得 term 清單之後的
 * 比對演算法（term_id 整數比對／數字字串比對 term_id／字串比對 slug 或 name），確保輸出與原生
 * has_term() 完全一致，差別只在 term 清單改用上面的 twshop_get_product_terms_cached() 取得。
 */
function twshop_has_term_cached( $terms, $taxonomy, $product_id ) {
    $object_terms = twshop_get_product_terms_cached( $product_id, $taxonomy );
    if ( empty( $object_terms ) || is_wp_error( $object_terms ) ) return false;

    $terms = (array) $terms;
    if ( empty( $terms ) ) return true;

    $ints = array_filter( $terms, 'is_int' );
    $strs = $ints ? array_diff( $terms, $ints ) : $terms;

    foreach ( $object_terms as $object_term ) {
        if ( $ints && in_array( $object_term->term_id, $ints, true ) ) return true;
        if ( $strs ) {
            $numeric_strs = array_map( 'intval', array_filter( $strs, 'is_numeric' ) );
            if ( in_array( $object_term->term_id, $numeric_strs, true ) ) return true;
            if ( in_array( $object_term->name, $strs, true ) ) return true;
            if ( in_array( $object_term->slug, $strs, true ) ) return true;
        }
    }
    return false;
}

function twshop_rule_condition_matches_product( $cond_type, $cond_values, $product_id ) {
    if ( $cond_type === 'product' ) {
        return in_array( (int) $product_id, array_map( 'intval', (array) $cond_values ), true );
    } elseif ( $cond_type === 'category' ) {
        return twshop_has_term_cached( $cond_values, 'product_cat', $product_id );
    } elseif ( $cond_type === 'tag' ) {
        return twshop_has_term_cached( $cond_values, 'product_tag', $product_id );
    }
    return false;
}

/**
 * twshop 自己加進購物車的特殊項目（贈品、買N送N 免費項目、點數兌換商品、加購項目）。
 * 這些項目不算「顧客購買的商品」，不能拿來滿足規則條件（否則贈品可以自己撐住自己的條件）。
 */
function twshop_is_twshop_special_cart_item( $cart_item ) {
    return isset( $cart_item['twshop_gift_rule_id'] )
        || isset( $cart_item['twshop_bxgy_rule_id'] )
        || isset( $cart_item['twshop_points_redeem_product_id'] )
        || isset( $cart_item['twshop_addon_rule_id'] );
}

function twshop_cart_condition_fingerprint() {
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) return 'nocart';
    $ids = array();
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( twshop_is_twshop_special_cart_item( $cart_item ) ) continue;
        $ids[] = (int) $cart_item['product_id'];
    }
    sort( $ids );
    return md5( implode( ',', array_unique( $ids ) ) );
}

function twshop_is_discount_rule_valid_compute( $rule, $user_roles, $cart_total = 0, $product_id = 0 ) {
    // 唯一判斷入口：所有型別的計算函式都經過這支函式判斷有效性，缺欄位（舊規則）一律視為啟用。
    if ( ( $rule['enabled'] ?? 'yes' ) === 'no' ) return false;

    $now = current_time('timestamp');

    if ( !empty($rule['start_time']) && $now < strtotime($rule['start_time']) ) return false;
    if ( !empty($rule['end_time']) && $now > strtotime($rule['end_time']) ) return false;
    if ( $rule['role'] !== 'all' && ! in_array( $rule['role'], $user_roles ) ) return false;
    
    if ( !empty($rule['is_coupon']) && $rule['is_coupon'] === 'yes' ) {
        $applied_rules = WC()->session ? WC()->session->get('twshop_applied_rules', array()) : array();
        if ( empty($rule['c_code']) || !in_array($rule['c_code'], $applied_rules) ) return false;
        if ( !empty($rule['c_exclusive']) && $rule['c_exclusive'] === 'yes' ) {
            if ( WC()->cart && !empty(WC()->cart->get_applied_coupons()) ) return false;
            if ( count($applied_rules) > 1 ) return false;
        }
    }

    $t_limit = intval($rule['usage_limit'] ?? 0);
    $u_limit = intval($rule['user_limit'] ?? 0);
    if ($t_limit > 0 && twshop_get_rule_usage_total( $rule['rule_id'] ) >= $t_limit) return false;
    if ($u_limit > 0 && is_user_logged_in() && intval(get_user_meta(get_current_user_id(), 'twshop_rule_usage_' . $rule['rule_id'], true)) >= $u_limit) return false;

    $logic = $rule['logic'] ?? 'and';
    $has_min = !empty($rule['min_amount']) && $rule['min_amount'] > 0;

    // 限制條件：condition_type (product/category/tag) + condition_values (可複選)。
    // 商品層（$product_id > 0）比對該商品；購物車層（$product_id = 0：免運/贈品/加購/整單折扣）
    // 改為「購物車內任一件一般商品符合即成立」——修正前購物車層一律略過條件，等於全站適用（v25.8.34）。
    list( $cond_type, $cond_values ) = twshop_get_rule_condition( $rule );
    $has_cond = ! empty( $cond_type ) && ! empty( $cond_values );

    if ( !$has_min && !$has_cond ) return true;

    $p_min = $has_min ? ($cart_total >= floatval($rule['min_amount'])) : false;
    $p_cond = false;
    if ( $has_cond ) {
        if ( $product_id > 0 ) {
            $p_cond = twshop_rule_condition_matches_product( $cond_type, $cond_values, $product_id );
        } elseif ( function_exists( 'WC' ) && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $cart_item ) {
                if ( twshop_is_twshop_special_cart_item( $cart_item ) ) continue;
                if ( twshop_rule_condition_matches_product( $cond_type, $cond_values, (int) $cart_item['product_id'] ) ) {
                    $p_cond = true;
                    break;
                }
            }
        }
    }

    if ( $logic === 'and' ) {
        if ( $has_min && !$p_min ) return false;
        if ( $has_cond && !$p_cond ) return false;
        return true;
    } else {
        if ( $p_min || $p_cond ) return true;
        return false;
    }
}

/**
 * 買N送N（buy_x_get_y）：判斷購物車項目是否落在規則的限制條件範圍內（商品/分類/標籤，此型別必填）。
 * 排除任何已被其他機制標記為 $0 的項目（贈品/本規則自己上一輪拆出的免費項目），避免自我循環計數。
 */
function twshop_bxgy_item_matches_rule( $cart_item, $rule ) {
    if ( isset( $cart_item['twshop_gift_rule_id'] ) || isset( $cart_item['twshop_bxgy_rule_id'] ) ) return false;
    $product_id = $cart_item['product_id'];
    $cond_type = $rule['condition_type'] ?? '';
    $cond_values = (array) ( $rule['condition_values'] ?? array() );
    if ( empty( $cond_type ) || empty( $cond_values ) ) return false;
    if ( $cond_type === 'product' ) {
        return in_array( $product_id, array_map( 'intval', $cond_values ), true );
    } elseif ( $cond_type === 'category' ) {
        return (bool) has_term( $cond_values, 'product_cat', $product_id );
    } elseif ( $cond_type === 'tag' ) {
        return (bool) has_term( $cond_values, 'product_tag', $product_id );
    }
    return false;
}

// 智能自動贈品與加購處理核心
function twshop_auto_manage_gifts_and_addons( $cart_obj ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    
    // 防呆鎖定，避免重複執行導致無限迴圈
    static $is_processing = false;
    if ( $is_processing ) return;
    $is_processing = true;

    $rules = twshop_get_rules();
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array('customer');

    // 計算不含當前贈品與負數手續費的純商品小計，作為達標基準
    $cart_total = 0;
    foreach ( $cart_obj->get_cart() as $cart_item ) {
        if ( ! isset($cart_item['twshop_gift_rule_id']) ) {
            $cart_total += $cart_item['line_subtotal'] ?? 0;
        }
    }

    $gifts_to_add = [];
    $gifts_to_remove = [];

    // 第零階段：收回孤兒贈品——規則已被刪除（不在目前 $rules 內），但購物車裡仍帶著
    // 該規則自動加入的 $0 贈品，foreach($rules) 找不到規則就永遠不會被下面的邏輯迭代到，
    // 贈品會卡在購物車直到顧客手動移除或購物車過期。這裡直接反查一次，跟規則是否還存在無關。
    $valid_gift_rule_ids = wp_list_pluck( $rules, 'rule_id' );
    foreach ( $cart_obj->get_cart() as $cart_item_key => $cart_item ) {
        if ( isset( $cart_item['twshop_gift_rule_id'] ) && ! in_array( $cart_item['twshop_gift_rule_id'], $valid_gift_rule_ids, true ) ) {
            $gifts_to_remove[] = $cart_item_key;
        }
    }

    // 第一階段：判斷哪些贈品該送、哪些該收回
    foreach($rules as $rule) {
        if ( $rule['type'] === 'free_gift' && !empty($rule['gift_product_id']) ) {
            $gift_id = (int)$rule['gift_product_id'];
            $is_valid = twshop_is_discount_rule_valid($rule, $user_roles, $cart_total, 0);

            $found_in_cart = false;
            $cart_item_key_to_remove = '';
            foreach ( $cart_obj->get_cart() as $cart_item_key => $cart_item ) {
                if ( isset($cart_item['twshop_gift_rule_id']) && $cart_item['twshop_gift_rule_id'] === $rule['rule_id'] ) {
                    $found_in_cart = true;
                    $cart_item_key_to_remove = $cart_item_key;
                    break;
                }
            }

            if ($is_valid && !$found_in_cart) {
                $gifts_to_add[] = ['id' => $gift_id, 'rule_id' => $rule['rule_id']];
            } elseif (!$is_valid && $found_in_cart) {
                $gifts_to_remove[] = $cart_item_key_to_remove;
            }
        }
    }

    // 執行新增與移除 (此動作可能會再次觸發 calculate_totals，因為被我們鎖住了所以安全)
    if ( !empty($gifts_to_add) || !empty($gifts_to_remove) ) {
        foreach($gifts_to_remove as $key) { WC()->cart->remove_cart_item($key); }
        // 贈品本身被 twshop_restrict_purchase_for_redeem_and_gift_products()
        // （includes/helpers.php）設成不可直接購買，這裡是唯一允許自動把它加入購物車的
        // 合法管道，用 bypass 旗標跳過那道限制，否則 add_to_cart() 會自己擋自己。
        foreach($gifts_to_add as $gift) {
            twshop_bypass_purchase_restriction( true );
            try {
                WC()->cart->add_to_cart($gift['id'], 1, 0, array(), array('twshop_gift_rule_id' => $gift['rule_id']));
            } finally {
                twshop_bypass_purchase_restriction( false );
            }
        }
    }

    // 第二階段：買N送N（buy_x_get_y）——重用同一套「add_to_cart + cart_item_data 標記 + set_price(0)」
    // 機制，差別是免費的是顧客自己已經在買的商品（挑最便宜的 free_qty 個單位拆成獨立一行），
    // 不是額外指定商品。門檻不可重複觸發：一律先還原上一輪的拆分，再依當下數量重新判斷一次。
    foreach ( $rules as $rule ) {
        if ( $rule['type'] !== 'buy_x_get_y' ) continue;

        // 2a：先把上一輪這條規則拆出的免費項目還原——併回同商品/同規格的一般價格項目，
        // 找不到就以原價重新加回購物車，確保接下來的數量計算是從「未拆分」的乾淨基準開始。
        //
        // 合併對象改用索引查表（O(1)）而非每還原一筆就重新掃描整個購物車（原本是
        // O(splits × cart_size)，購物車項目多、同一規則拆出的免費項目也多時會放大）。
        // 索引在還原迴圈開始前建一次、之後隨還原動作同步更新，不重建：兩個分割項目
        // 若剛好合併回同一個商品，第二個要接到第一個剛建立/更新的項目上，用同一份
        // 索引才能保證這一點。
        $item_index = array();
        foreach ( $cart_obj->get_cart() as $idx_key => $idx_item ) {
            if ( isset( $idx_item['twshop_gift_rule_id'] ) || isset( $idx_item['twshop_bxgy_rule_id'] ) ) continue;
            $item_index[ $idx_item['product_id'] . '|' . $idx_item['variation_id'] ] = $idx_key;
        }

        foreach ( $cart_obj->get_cart() as $split_key => $split_item ) {
            if ( ! isset( $split_item['twshop_bxgy_rule_id'] ) || $split_item['twshop_bxgy_rule_id'] !== $rule['rule_id'] ) continue;
            $restore_qty        = $split_item['quantity'];
            $restore_product_id = $split_item['product_id'];
            $restore_variation_id = $split_item['variation_id'];
            $index_key = $restore_product_id . '|' . $restore_variation_id;

            $cart_obj->remove_cart_item( $split_key );

            $sibling_key  = $item_index[ $index_key ] ?? null;
            $sibling_item = $sibling_key ? $cart_obj->get_cart_item( $sibling_key ) : null;
            if ( $sibling_item ) {
                $cart_obj->set_quantity( $sibling_key, $sibling_item['quantity'] + $restore_qty, false );
            } else {
                $new_key = $cart_obj->add_to_cart( $restore_product_id, $restore_qty, $restore_variation_id );
                $item_index[ $index_key ] = $new_key;
            }
        }

        if ( ! twshop_is_discount_rule_valid( $rule, $user_roles, $cart_total, 0 ) ) continue;

        $buy_qty  = max( 1, (int) ( $rule['buy_qty'] ?? 0 ) );
        $free_qty = max( 1, (int) ( $rule['free_qty'] ?? 0 ) );

        // 2b：加總符合限制條件範圍內的購買數量（已排除贈品/本規則免費分割項目）
        $matching_units = array(); // 每個購買單位一筆：['key' => cart_item_key, 'price' => 目前單價]
        foreach ( $cart_obj->get_cart() as $m_key => $m_item ) {
            if ( ! twshop_bxgy_item_matches_rule( $m_item, $rule ) ) continue;
            $unit_price = (float) $m_item['data']->get_price();
            for ( $i = 0; $i < (int) $m_item['quantity']; $i++ ) {
                $matching_units[] = array( 'key' => $m_key, 'price' => $unit_price );
            }
        }

        if ( count( $matching_units ) < $buy_qty ) continue; // 未達標，維持還原後的狀態，不重複觸發

        // 2c：取單價最低的 free_qty 個單位，依所屬購物車項目分組，各自扣除數量並拆出一筆 $0 項目
        usort( $matching_units, function( $a, $b ) { return $a['price'] <=> $b['price']; } );
        $free_qty_by_key = array();
        foreach ( array_slice( $matching_units, 0, $free_qty ) as $unit ) {
            $free_qty_by_key[ $unit['key'] ] = ( $free_qty_by_key[ $unit['key'] ] ?? 0 ) + 1;
        }

        foreach ( $free_qty_by_key as $src_key => $qty_to_split ) {
            $src_item = $cart_obj->get_cart_item( $src_key );
            if ( ! $src_item ) continue;
            $remaining_qty = $src_item['quantity'] - $qty_to_split;
            $product_id    = $src_item['product_id'];
            $variation_id  = $src_item['variation_id'];
            $variation     = $src_item['variation'];
            if ( $remaining_qty > 0 ) {
                $cart_obj->set_quantity( $src_key, $remaining_qty, false );
            } else {
                $cart_obj->remove_cart_item( $src_key );
            }
            $cart_obj->add_to_cart( $product_id, $qty_to_split, $variation_id, $variation, array( 'twshop_bxgy_rule_id' => $rule['rule_id'] ) );
        }
    }

    // 第三階段：將自動帶入的贈品／買N送N 免費項目強制售價改為 $0，並處理加購商品的售價
    foreach ( $cart_obj->get_cart() as $cart_item ) {
        if ( isset($cart_item['twshop_gift_rule_id']) || isset($cart_item['twshop_bxgy_rule_id']) ) {
            $cart_item['data']->set_price(0);
            continue;
        }

        $product_id = $cart_item['product_id'];
        foreach ( $rules as $rule ) {
            if ( $rule['type'] === 'addon_product' && (int)$rule['gift_product_id'] === $product_id ) {
                if ( twshop_is_discount_rule_valid($rule, $user_roles, $cart_total, 0) ) {
                    $cart_item['data']->set_price( floatval($rule['value']) );
                    break; 
                }
            }
        }
    }

    $is_processing = false;
}

/**
 * 幫 WC_Product_Variable 的規格價格 transient 快取（wc_var_prices_{id}，預設快取 30 天）加上會
 * 影響 twshop 折扣規則計算結果的因子，避免不同角色的使用者共用到同一份快取、看到不該看到的折扣價格。
 *
 * 刻意不納入購物車小計（$cart_total，會影響 min_amount 門檻類規則）：小計是連續數值，幾乎每個訪客
 * 當下的購物車金額都不一樣，若也納入快取 key，會讓同一個商品在 30 天快取視窗內產生近乎無限多組
 * hash、每一組都各自佔用 wc_var_prices_{id} 這顆 transient 裡的一筆資料，永遠不會自然清掉，有讓單一
 * transient 越長越大的風險。取捨後只涵蓋角色限定的規則（最常見的會員分級折扣），min_amount 門檻類規則
 * 不會反映在「選規格前」的價格區間摘要，但購物車/結帳頁與選定規格後的價格不受影響，仍即時正確計算。
 *
 * 也加了以小時為顆粒度的時間區段，讓有排程起訖時間的規則至少在一小時內會反映到快取（此快取沒有其他
 * 會隨時間自動失效的機制，只靠 WC 商品版本號變動或滿 30 天才會重算，不加時間因子的話，排程規則的
 * 起訖時刻可能要等到有其他商品被儲存、間接刷新版本號才會生效，等待時間不可預期）。
 *
 * v25.8.33 加入規則內容的雜湊值：新增/修改/刪除任何一筆折扣規則都會讓這裡的雜湊值改變，使快取
 * 立即失效，不用再等到整點。跟 $cart_total 那種連續值不同，規則整體內容是離散、低頻異動（管理員
 * 手動操作才會變），不會有 transient 內部資料量無限增長的風險，所以可以直接整包納入 key。
 */
function twshop_add_discount_context_to_variation_price_hash( $price_hash ) {
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array( 'customer' );
    sort( $user_roles );
    $price_hash['twshop_roles'] = $user_roles;
    $price_hash['twshop_hour']  = current_time( 'Y-m-d H' );
    $price_hash['twshop_rules'] = md5( wp_json_encode( twshop_get_rules() ) );
    return $price_hash;
}

function twshop_get_calculated_discount_price( $price, $product, $user_roles ) {
    $rules = twshop_get_rules();
    if ( empty($rules) ) return false;
    $product_id = $product->get_id();
    $cart_total = WC()->cart ? WC()->cart->get_subtotal() : 0;

    // 限制條件（商品/分類/標籤）一律比對「父商品」ID：規格（variation）本身沒有自己的分類/標籤，
    // 後台選規則限制條件時選的也是父商品，用規格自己的 ID（$product_id）去比對永遠對不上。
    // 折扣計算與快取仍用規格自己的 ID/價格——同一個父商品底下不同規格售價可能不同。
    $condition_product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product_id;

    // 價格 filter（woocommerce_product_get_price）與促銷角標 filter（woocommerce_product_is_on_sale）
    // 常常在同一次請求內各自對同一商品呼叫本函式（商店頁一次商品渲染就可能觸發好幾次：價格、
    // price_html、促銷角標等），兩者傳入的 $price 可能不同（is_on_sale 特意傳「未打折的原價」），
    // 快取 key 納入 $price 才不會把不同輸入誤判成同一結果；購物車小計與角色在同一次請求內視為不變。
    static $cache = array();
    $cache_key = $product_id . '|' . $price . '|' . $cart_total . '|' . implode( ',', $user_roles );
    if ( array_key_exists( $cache_key, $cache ) ) return $cache[ $cache_key ];

    $final_price = floatval($price);
    $applied = false;

    // 疊加群組 A（product 層）：percent + fixed_product 依卡片排序（優先權）逐一套用；
    // 遇到第一筆 stack_exclusive='yes' 的有效規則就只套用它、不再套用同群組其餘規則。
    foreach ( $rules as $rule ) {
        if ( $rule['type'] === 'percent' || $rule['type'] === 'fixed_product' ) {
            if ( twshop_is_discount_rule_valid( $rule, $user_roles, $cart_total, $condition_product_id ) ) {
                $applied = true;
                if ( $rule['type'] === 'percent' ) $final_price = $final_price * ( floatval($rule['value']) / 100 );
                elseif ( $rule['type'] === 'fixed_product' ) $final_price = $final_price - floatval($rule['value']);
                if ( ( $rule['stack_exclusive'] ?? 'no' ) === 'yes' ) break;
            }
        }
    }

    $result = $applied ? max(0, $final_price) : false;
    $cache[ $cache_key ] = $result;
    return $result;
}

function twshop_apply_product_discount_rules( $price, $product ) {
    if ( is_admin() || $price === '' ) return $price;
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array('customer');
    $discounted = twshop_get_calculated_discount_price( $price, $product, $user_roles );
    return ($discounted !== false) ? $discounted : $price;
}

function twshop_product_is_on_sale( $is_on_sale, $product ) {
    // 可變商品父層沒有自己的單一原價（get_regular_price() 回傳空字串），下面的算法直接 bail out，
    // 完全不會檢查 twshop 折扣規則；改成跟徽章計算共用同一支「取所有規格中折扣幅度最大者」的函式，
    // 只要有任一規格被規則打折就視為特價中（跟 twshop_get_variable_product_max_discount_percent()
    // 本身依賴的 $variation->get_price() 一樣，都需要 woocommerce_product_variation_get_price 這個
    // 規格專用 filter 有掛上 twshop_apply_product_discount_rules 才會生效）。
    if ( $product->is_type( 'variable' ) ) {
        return twshop_get_variable_product_max_discount_percent( $product ) > 0 ? true : $is_on_sale;
    }

    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array('customer');
    $regular_price = $product->get_regular_price();
    if ( ! $regular_price ) return $is_on_sale;

    $discounted = twshop_get_calculated_discount_price( $regular_price, $product, $user_roles );
    if ( $discounted !== false && $discounted < $regular_price ) return true;
    return $is_on_sale;
}

/**
 * 商品折扣徽章：覆寫 woocommerce_sale_flash 輸出的文字，預設顯示折扣百分比。
 * 只處理有明確原價/售價可比較的簡單商品；可變商品（浮動區間價）判斷不出單一折扣百分比，
 * 直接回傳原本的 $html（沿用主題/WC 原生角標），不強行湊出可能誤導的數字。
 *
 * 掛在很晚的優先權（999），$html 收到的已經是主題處理過的最終版本（例如 Blocksy 會組出
 * `<span class="onsale" data-shape="...">`），這裡只用 regex 換掉外層標籤中間的文字，
 * 保留主題自己加的屬性/class 不動，讓徽章外觀完全交給主題既有 CSS 決定。
 */
/**
 * 可變商品的折扣徽章百分比：逐一讀取「可見」規格（get_visible_children，跟 WC 內建
 * get_variation_prices() 篩選範圍一致，排除下架/未發布/庫存狀態不允許購買的規格——
 * 顧客本來就買不到的規格沒必要拿來算折扣，也可能造成庫存售完但仍在商店頁閃現折扣角標的怪異情況）
 * 的實際售價（$variation->get_price()，context 'view'，會套用 woocommerce_product_get_price
 * filter 鏈，因此**含 twshop 折扣規則模組**的折扣，跟簡單商品的計算基準一致），取其中折扣
 * 幅度最大的一個當作徽章要顯示的百分比。
 *
 * 刻意不用 WC_Product_Variable::get_variation_prices()：該函式內部讀規格價格時明確傳入
 * context 'edit'（繞過 woocommerce_product_get_price 顯示 filter，只給原始 meta 值），
 * 只反映 WooCommerce 原生特價（sale_price），讀不到 twshop 折扣規則模組造成的降價。
 *
 * 效能：不像 get_variation_prices() 有 WC 自己的 transient 快取，這裡逐一讀取規格會直接
 * 觸發 get_price() 的完整 filter 鏈。用 static cache（key 為商品 ID）確保同一次請求內
 * （商品彙整頁一次商品渲染常會對同一商品呼叫好幾次 woocommerce_sale_flash / is_on_sale
 * 等 filter）只實際計算一次；成本只發生在「有折扣且已被 WC 判定為特價中」的可變商品身上
 * （不在特價中的可變商品，WooCommerce 連 woocommerce_sale_flash 都不會觸發），且僅限單頁
 * 實際渲染出來的商品數量，不是全站每次請求都掃描。
 */
function twshop_get_variable_product_max_discount_percent( $product ) {
    static $cache = array();
    $product_id = $product->get_id();
    if ( array_key_exists( $product_id, $cache ) ) return $cache[ $product_id ];

    $max_percent = 0;
    foreach ( $product->get_visible_children() as $variation_id ) {
        $variation = wc_get_product( $variation_id );
        if ( ! $variation instanceof WC_Product ) continue;

        $regular_price = $variation->get_regular_price();
        if ( $regular_price === '' || ! is_numeric( $regular_price ) || (float) $regular_price <= 0 ) continue;

        $active_price = (float) $variation->get_price();
        $regular_price = (float) $regular_price;
        if ( $active_price >= $regular_price ) continue;

        $percent = (int) round( ( $regular_price - $active_price ) / $regular_price * 100 );
        if ( $percent > $max_percent ) $max_percent = $percent;
    }

    $cache[ $product_id ] = $max_percent;
    return $max_percent;
}

function twshop_render_discount_badge( $html, $post, $product ) {
    if ( $html === '' || ! $product instanceof WC_Product ) return $html;

    $product_id = $product->get_id();

    // 兩個 get_post_meta() 合併為單次無 key 呼叫（取全部 meta），避免每個商品各查兩次。
    $all_meta   = get_post_meta( $product_id );
    $badge_hide = isset( $all_meta['_twshop_badge_hide'][0] ) ? $all_meta['_twshop_badge_hide'][0] : '';
    $badge_text = isset( $all_meta['_twshop_badge_text'][0] ) ? $all_meta['_twshop_badge_text'][0] : '';

    // 個別商品「隱藏徽章」是最強的覆寫，即使全站徽章開關是開的也優先套用，
    // 直接回傳空字串（連主題原生的角標一併移除），不是回傳 $html（那樣只是不客製文字，主題預設角標仍會顯示）。
    if ( $badge_hide === 'yes' ) return '';

    // 兩個 get_option() 是全站設定值，同一次請求內不會變動，static cache 只讀一次。
    static $site_options = null;
    if ( null === $site_options ) {
        $site_options = array(
            'wc_badge_enabled'      => twshop_option( 'wc_badge_enabled' ),
            'wc_badge_text_template' => get_option( 'wc_badge_text_template', '' ),
        );
    }

    if ( $site_options['wc_badge_enabled'] !== 'yes' ) return $html;

    if ( $product->is_type( 'variable' ) ) {
        $percent = twshop_get_variable_product_max_discount_percent( $product );
    } else {
        $regular_price = $product->get_regular_price();
        if ( $regular_price === '' || ! is_numeric( $regular_price ) || (float) $regular_price <= 0 ) return $html;

        $active_price = (float) $product->get_price();
        $regular_price = (float) $regular_price;
        if ( $active_price >= $regular_price ) return $html;

        $percent = (int) round( ( $regular_price - $active_price ) / $regular_price * 100 );
    }

    if ( $percent <= 0 ) return $html;

    // 文字樣板優先順序：個別商品自訂 > 全站樣板 > 寫死的預設值
    $template = trim( (string) $badge_text );
    if ( $template === '' ) $template = trim( (string) $site_options['wc_badge_text_template'] );
    if ( $template === '' ) $template = '-{percent}%';
    $text = str_replace( '{percent}', $percent, $template );

    if ( preg_match( '/^(<[^>]+>)(.*)(<\/[a-zA-Z0-9]+>)$/s', $html, $m ) ) {
        return $m[1] . esc_html( $text ) . $m[3];
    }

    return '<span class="onsale">' . esc_html( $text ) . '</span>';
}

/**
 * 商品編輯畫面「一般」頁籤新增此商品專屬的徽章文字/隱藏開關。
 * 簡單商品／外部商品／可變商品皆顯示（show_if_simple/show_if_external/show_if_variable，
 * 比照特價欄位在簡單/外部商品的顯示邏輯，額外加上可變商品——可變商品沒有單一售價，
 * 但仍套用同一組文字樣板/隱藏開關，百分比改由 twshop_get_variable_product_max_discount_percent()
 * 取所有規格中折扣幅度最大者）。分組商品（grouped）沒有自己的售價，不顯示。
 */
function twshop_add_badge_product_fields() {
    $default_template = trim( (string) get_option( 'wc_badge_text_template', '' ) );
    if ( $default_template === '' ) $default_template = '-{percent}%';

    echo '<div class="options_group show_if_simple show_if_external show_if_variable twshop-badge-product-fields">';
    woocommerce_wp_text_input( array(
        'id'          => '_twshop_badge_text',
        'label'       => '折扣徽章文字',
        'placeholder' => '留空則使用全站樣板：' . $default_template,
        'desc_tip'    => true,
        'description' => '此商品專屬的折扣徽章文字，可用 {percent} 代表折扣百分比數字，留空則沿用「一般設定」頁的全站樣板。可變商品會取所有規格中折扣幅度最大的百分比。',
    ) );
    woocommerce_wp_checkbox( array(
        'id'          => '_twshop_badge_hide',
        'label'       => '隱藏折扣徽章',
        'description' => '勾選後此商品即使有折扣，也完全不顯示折扣徽章（含主題原生角標）。',
    ) );
    echo '</div>';
}

function twshop_save_badge_product_fields( $post_id ) {
    $text = isset( $_POST['_twshop_badge_text'] ) ? sanitize_text_field( wp_unslash( $_POST['_twshop_badge_text'] ) ) : '';
    update_post_meta( $post_id, '_twshop_badge_text', $text );

    $hide = isset( $_POST['_twshop_badge_hide'] ) ? 'yes' : 'no';
    update_post_meta( $post_id, '_twshop_badge_hide', $hide );
}

/**
 * tiered_cart 規則：依 min_amount 由大到小找第一個購物車小計達標的門檻，回傳該階梯陣列；
 * 沒有任何門檻達標（或規則未設定任何 tiers）回傳 false。
 */
function twshop_get_matching_cart_tier( $rule, $cart_total ) {
    $tiers = is_array( $rule['tiers'] ?? null ) ? $rule['tiers'] : array();
    if ( empty( $tiers ) ) return false;
    usort( $tiers, function( $a, $b ) { return floatval( $b['min_amount'] ?? 0 ) <=> floatval( $a['min_amount'] ?? 0 ); } );
    foreach ( $tiers as $tier ) {
        if ( $cart_total >= floatval( $tier['min_amount'] ?? 0 ) ) return $tier;
    }
    return false;
}

function twshop_apply_cart_discount_rules( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    $rules = twshop_get_rules();
    if ( empty($rules) ) return;
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array('customer');
    $cart_total = $cart->get_subtotal();

    // 疊加群組 B（cart 層）：cart_percent + cart_discount + tiered_cart 依卡片排序（優先權）逐一套用；
    // 遇到第一筆 stack_exclusive='yes' 的有效規則就只套用它、不再套用同群組其餘規則（跟群組 A 同一套邏輯）。
    foreach ( $rules as $rule ) {
        if ( $rule['type'] === 'cart_discount' || $rule['type'] === 'cart_percent' ) {
            if ( twshop_is_discount_rule_valid( $rule, $user_roles, $cart_total, 0 ) ) {
                // 「打折 (%)」統一採「打N折＝付N%」慣例，跟商品層 percent 一致（value=90 代表打9折，
                // 折扣後應付原價 90%，即折抵掉 10%）；修法前這裡誤算成 value=90 折抵掉 90%（只收10%），
                // 跟商品層 percent 的算法方向剛好相反（v25.5.67 修正，見 CLAUDE.md）。
                $discount_amount = ($rule['type'] === 'cart_percent') ? ($cart_total * ( 1 - floatval($rule['value']) / 100 )) : abs(floatval($rule['value']));
                $cart->add_fee( esc_html($rule['name']), -1 * $discount_amount, true );
                if ( ( $rule['stack_exclusive'] ?? 'no' ) === 'yes' ) break;
            }
        } elseif ( $rule['type'] === 'tiered_cart' ) {
            if ( twshop_is_discount_rule_valid( $rule, $user_roles, $cart_total, 0 ) ) {
                $tier = twshop_get_matching_cart_tier( $rule, $cart_total );
                if ( $tier !== false ) {
                    // 同上，'percent' 階梯一律採「打N折＝付N%」慣例。
                    $discount_amount = ( ( $tier['discount_type'] ?? 'fixed' ) === 'percent' )
                        ? ( $cart_total * ( 1 - floatval( $tier['value'] ?? 0 ) / 100 ) )
                        : abs( floatval( $tier['value'] ?? 0 ) );
                    $fee_label = esc_html( $rule['name'] ) . '（滿 ' . wp_strip_all_tags( wc_price( floatval( $tier['min_amount'] ?? 0 ) ) ) . '）';
                    $cart->add_fee( $fee_label, -1 * $discount_amount, true );
                    if ( ( $rule['stack_exclusive'] ?? 'no' ) === 'yes' ) break;
                }
            }
        }
    }
}

function twshop_apply_free_shipping_rules( $rates, $package ) {
    $rules = twshop_get_rules();
    if ( empty($rules) ) return $rates;
    $user_roles = is_user_logged_in() ? wp_get_current_user()->roles : array('customer');

    // 以折扣後金額作為門檻判斷基準：
    // woocommerce_package_rates 觸發時，費用（含折扣負費用）已計算完畢，可直接讀取
    $subtotal        = WC()->cart->get_subtotal();
    $coupon_discount = WC()->cart->get_discount_total(); // WooCommerce 優惠券折扣（正數）
    $fee_discount    = 0;
    foreach ( WC()->cart->get_fees() as $fee ) {
        // 點數折抵視為付款方式，不計入商品折扣（不影響免運門檻）
        if ( $fee->total < 0 && $fee->name !== twshop_points_term() . '折抵' ) {
            $fee_discount += abs( $fee->total );
        }
    }
    $cart_total = max( 0.0, $subtotal - $coupon_discount - $fee_discount );

    $make_free = false; $free_rule_name = ''; $free_rule_methods = array();

    foreach ( $rules as $rule ) {
        if ( $rule['type'] === 'free_shipping' ) {
            if ( twshop_is_discount_rule_valid( $rule, $user_roles, $cart_total, 0 ) ) {
                $make_free = true; $free_rule_name = $rule['name'];
                $free_rule_methods = is_array( $rule['shipping_methods'] ?? null ) ? $rule['shipping_methods'] : array();
                break;
            }
        }
    }
    if ( $make_free ) {
        foreach ( $rates as $rate_id => $rate ) {
            // 未指定適用運送方式（舊規則、或管理員刻意不勾選任何項目）時，視為全部運送方式皆免運，維持既有行為
            if ( ! empty( $free_rule_methods ) && ! in_array( $rate_id, $free_rule_methods, true ) ) continue;
            $rates[$rate_id]->cost = 0; $rates[$rate_id]->taxes = array();
            $rates[$rate_id]->label = $rate->label . ' (' . $free_rule_name . ')';
        }
    }
    return $rates;
}

