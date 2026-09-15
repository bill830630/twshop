<?php
/**
 * 儲值金：線上自助儲值。方案設定、建立儲值訂單、付款完成入帳、儲值訂單取消/退款追回。
 *
 * 跟結帳折抵（wallet-checkout.php）是相反方向：那邊是「花掉」儲值金，這裡是「儲值金
 * 怎麼進來」。儲值訂單刻意不經過購物車——只有一筆自訂費用項目（「儲值金 NT$X」，
 * 不課稅、不需運送），天然不會命中任何折扣規則/贈品/加購邏輯，不需要額外排除規則。
 *
 * 訂單 meta `_twshop_wallet_topup_order` 是這張訂單「是儲值訂單」的唯一判斷依據，
 * 也是 twshop_award_points_on_order_complete()（points-engine.php）與
 * twshop_get_user_spent_since()／twshop_find_qualifying_order_date()（membership.php）
 * 排除儲值訂單的依據，三處排除邏輯必須跟這裡的 meta key 保持一致。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 目前啟用中的儲值方案（金額 > 0 且 enabled=yes），依設定順序排列。
 * 會員中心「立即儲值」區塊與 AJAX 建立訂單時的方案查找共用這支。
 */
function twshop_wallet_get_plans() {
    $plans = get_option( 'wc_wallet_topup_plans', array() );
    if ( ! is_array( $plans ) ) return array();
    return array_values( array_filter( $plans, function ( $p ) {
        return isset( $p['enabled'] ) && 'yes' === $p['enabled'] && (float) ( $p['amount'] ?? 0 ) > 0;
    } ) );
}

/**
 * 這張儲值訂單目前已入帳多少（'topup' 型紀錄的本金 ＋ 'topup_bonus' 型紀錄的加贈金）。
 * 本金與加贈金分成兩種 type 各自一筆紀錄，對應 twshop_wallet_type_label() 既有的
 * 「線上儲值（本金）」／「線上儲值（加贈）」兩種標籤（wallet-core.php）。
 */
function twshop_wallet_get_order_topup_credited( $order_id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN type = 'topup' THEN amount_paid ELSE 0 END), 0) AS paid,
            COALESCE(SUM(CASE WHEN type = 'topup_bonus' THEN amount_bonus ELSE 0 END), 0) AS bonus
         FROM " . twshop_wallet_ledger_table() . " WHERE order_id = %d AND type IN ('topup','topup_bonus')",
        (int) $order_id
    ), ARRAY_A );
    return array(
        'paid'  => $row ? (float) $row['paid']  : 0.0,
        'bonus' => $row ? (float) $row['bonus'] : 0.0,
    );
}

/**
 * 這張儲值訂單目前已追回多少（'topup_revoke' 型紀錄，本金/加贈金合併一筆）。
 */
function twshop_wallet_get_order_topup_revoked( $order_id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT COALESCE(SUM(amount_paid), 0) AS paid, COALESCE(SUM(amount_bonus), 0) AS bonus
         FROM " . twshop_wallet_ledger_table() . " WHERE order_id = %d AND type = 'topup_revoke'",
        (int) $order_id
    ), ARRAY_A );
    // topup_revoke 存的 amount 皆為負數（扣除），取絕對值還原成正數的「追回了多少」
    return array(
        'paid'  => $row ? abs( (float) $row['paid'] )  : 0.0,
        'bonus' => $row ? abs( (float) $row['bonus'] ) : 0.0,
    );
}

/**
 * 會員中心「立即儲值」→ 建立儲值訂單，導向付款頁。不經過購物車：直接 wc_create_order()
 * 一張只有一筆費用項目的訂單，避免購物車層的折扣規則/優惠券/點數折抵/贈品邏輯碰到
 * 儲值訂單（那些邏輯比對的是商品項目，自訂費用項目天然不會命中，但更乾淨的做法是
 * 一開始就不讓儲值訂單經過購物車，不依賴「天生免疫」）。
 */
function twshop_ajax_create_wallet_topup_order() {
    check_ajax_referer( 'twshop_frontend_action', 'twshop_nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => '請先登入才能儲值。' ) );
    }

    $user_id = get_current_user_id();
    $plan_id = isset( $_POST['plan_id'] ) ? sanitize_key( wp_unslash( $_POST['plan_id'] ) ) : '';
    $amount  = 0.0;
    $bonus   = 0.0;

    if ( $plan_id ) {
        $plan = null;
        foreach ( twshop_wallet_get_plans() as $p ) {
            if ( $p['id'] === $plan_id ) { $plan = $p; break; }
        }
        if ( ! $plan ) {
            wp_send_json_error( array( 'message' => '找不到這個儲值方案，請重新整理頁面後再試。' ) );
        }
        $amount = (float) $plan['amount'];
        $bonus  = (float) $plan['bonus'];
    } else {
        if ( 'yes' !== twshop_option( 'wc_wallet_allow_custom_amount' ) ) {
            wp_send_json_error( array( 'message' => '目前不開放自訂儲值金額，請選擇方案。' ) );
        }
        $amount = isset( $_POST['custom_amount'] ) ? round( (float) wp_unslash( $_POST['custom_amount'] ), 2 ) : 0.0;
        $min = (float) get_option( 'wc_wallet_custom_min', 0 );
        $max = (float) get_option( 'wc_wallet_custom_max', 0 );
        if ( $amount <= 0 ) {
            wp_send_json_error( array( 'message' => '請輸入要儲值的金額。' ) );
        }
        if ( $min > 0 && $amount < $min ) {
            wp_send_json_error( array( 'message' => '儲值金額不可低於 NT$' . number_format( $min ) . '。' ) );
        }
        if ( $max > 0 && $amount > $max ) {
            wp_send_json_error( array( 'message' => '儲值金額不可高於 NT$' . number_format( $max ) . '。' ) );
        }
    }

    if ( $amount <= 0 ) {
        wp_send_json_error( array( 'message' => '儲值金額不正確。' ) );
    }

    $order = wc_create_order( array( 'customer_id' => $user_id, 'status' => 'pending' ) );
    if ( is_wp_error( $order ) ) {
        wp_send_json_error( array( 'message' => '建立訂單失敗，請稍後再試。' ) );
    }

    $customer = new WC_Customer( $user_id );
    $order->set_billing_first_name( $customer->get_billing_first_name() ?: $customer->get_first_name() );
    $order->set_billing_last_name( $customer->get_billing_last_name() ?: $customer->get_last_name() );
    $order->set_billing_email( $customer->get_billing_email() ?: $customer->get_email() );
    $order->set_billing_phone( $customer->get_billing_phone() );

    $fee = new WC_Order_Item_Fee();
    $fee->set_name( '儲值金 NT$' . number_format( $amount, 0 ) );
    $fee->set_amount( $amount );
    $fee->set_total( $amount );
    $fee->set_tax_status( 'none' );
    $order->add_item( $fee );

    $order->update_meta_data( '_twshop_wallet_topup_order', 'yes' );
    $order->update_meta_data( '_twshop_wallet_topup_paid', $amount );
    $order->update_meta_data( '_twshop_wallet_topup_bonus', $bonus );
    if ( $plan_id ) $order->update_meta_data( '_twshop_wallet_topup_plan_id', $plan_id );

    $order->set_created_via( 'twshop_wallet_topup' );
    $order->calculate_totals();
    $order->save();

    wp_send_json_success( array( 'redirect' => $order->get_checkout_payment_url() ) );
}

/**
 * 付款完成（涵蓋所有金流的統一 hook）：入帳並轉為 completed。本金／加贈金分兩筆各自
 * 呼叫 twshop_wallet_apply()，ref 分別為 topup:{order_id}／topup_bonus:{order_id}，
 * 天然冪等——同一張訂單的 woocommerce_payment_complete 若因網路重試等原因觸發兩次，
 * 第二次兩支呼叫都會撞到既有 ref 直接回傳第一次的結果，不會重複入帳。
 */
function twshop_wallet_credit_on_payment_complete( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    if ( 'yes' !== $order->get_meta( '_twshop_wallet_topup_order' ) ) return;

    $user_id = $order->get_customer_id();
    if ( ! $user_id ) return;

    $paid  = round( (float) $order->get_meta( '_twshop_wallet_topup_paid' ), 2 );
    $bonus = round( (float) $order->get_meta( '_twshop_wallet_topup_bonus' ), 2 );

    if ( $paid > 0 ) {
        twshop_wallet_apply( $user_id, $paid, 0, 'topup', 'topup:' . $order_id, array(
            'order_id' => $order_id,
            'note'     => '線上儲值訂單 #' . $order_id,
        ) );
    }
    if ( $bonus > 0 ) {
        twshop_wallet_apply( $user_id, 0, $bonus, 'topup_bonus', 'topup_bonus:' . $order_id, array(
            'order_id' => $order_id,
            'note'     => '線上儲值訂單 #' . $order_id . ' 加贈',
        ) );
    }

    // 無實體商品不需出貨，付款完成直接轉已完成（比照規劃需求）。WooCommerce 核心
    // payment_complete() 本身可能已經因為 needs_processing()（訂單只有費用項目、沒有
    // 任何 line_item）判斷為 false 而直接設成 completed，這裡明確再設一次是保險，
    // 不假設核心版本間的行為一致；update_status() 對已經是該狀態的訂單是安全的 no-op。
    if ( ! $order->has_status( 'completed' ) ) {
        $order->update_status( 'completed', '儲值訂單付款完成，無實體商品不需出貨，自動轉為已完成。' );
    }

    twshop_wallet_maybe_send_topup_email( $order, $user_id, $paid, $bonus );
}

/**
 * 儲值成功通知信，開關與範本見「儲值金 ▸ 設定」頁籤。跟會員等級的生日禮/升等禮通知信
 * 同一套做法（`str_replace` 套版＋直接同步 `wp_mail()`，不像點數到期提醒那樣走
 * `wp_schedule_single_event()` 排隊——這裡是使用者當下操作觸發的即時通知，不是批次
 * 掃描大量會員的排程情境，不需要非同步化）。
 */
function twshop_wallet_maybe_send_topup_email( $order, $user_id, $paid, $bonus ) {
    if ( 'yes' !== twshop_option( 'wc_wallet_topup_email_enabled' ) ) return;

    $user = get_userdata( $user_id );
    if ( ! $user || ! is_email( $user->user_email ) ) return;

    $balance = twshop_wallet_get_balance( $user_id );
    $body    = get_option( 'wc_wallet_topup_email_body', "親愛的 {name}：\n\n您的儲值已完成！\n\n本次儲值：NT{amount}\n加贈金額：NT{bonus}\n目前餘額：NT{balance}\n\n感謝您的支持！" );
    $body    = str_replace(
        array( '{name}', '{amount}', '{bonus}', '{balance}', '{order_id}' ),
        array( $user->display_name, number_format( $paid, 2 ), number_format( $bonus, 2 ), number_format( $balance['total'], 2 ), $order->get_id() ),
        $body
    );

    wp_mail( $user->user_email, twshop_option( 'wc_wallet_topup_email_subject' ), $body );
}

/**
 * 儲值訂單取消/退款/付款失敗：追回尚未追回的本金＋加贈金。跟
 * twshop_refund_wallet_on_order_cancel()（wallet-checkout.php，退回「消費訂單」用掉的
 * 儲值金，只會加錢）方向相反——這裡是「扣錢」，追回顧客當初儲值進來、但訂單本身被
 * 取消/退款的金額，可能因為餘額已經被花掉而扣不滿。
 *
 * 【設計取捨：一次性最佳努力，不自動重試】ref 固定為 topup_revoke:{order_id}（不含
 * 金額），只會真正執行一次——若第一次追回不足額，差額寫進訂單備註，不會在之後狀態
 * 再次變化時自動重試追回剩餘差額（twshop_wallet_apply() 的冪等機制會直接回傳第一次
 * 的結果，不會真的再扣一次）。這是刻意的設計，對應規劃時確認的「差額需人工處理」：
 * 若日後餘額回升、想把差額追回，管理員要透過後台使用者個人資料頁的「手動增減儲值金」
 * 自行處理，此函式不做自動重試。
 *
 * 【踩坑：光靠 ref 冪等不夠，訂單備註會被重複加】ledger 的寫入靠 twshop_wallet_apply()
 * 的 ref 冪等機制天然不會重複扣款，但「差額」是每次呼叫都重新用 credited − 已追回
 * 現算的，發生過短缺（claw < remaining）之後，「已追回」不會再變動，remaining 會
 * 永遠停在同一個正數——若這個 hook 在同一張訂單上被觸發第二次（例如先轉 cancelled
 * 又轉 refunded，兩個狀態都掛了這支 callback），舊寫法會把同一則差額備註重複寫兩次。
 * 改用訂單 meta `_twshop_wallet_topup_revoke_processed` 當一次性旗標，整個函式的動作
 * （追回與備註）只在第一次呼叫時執行，第二次呼叫直接短路，才是真正符合上面「一次性
 * 最佳努力」設計意圖的寫法（2026-09-15 bootstrap 腳本測試時實際重現並修正）。
 */
function twshop_wallet_revoke_topup_order( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;
    if ( 'yes' !== $order->get_meta( '_twshop_wallet_topup_order' ) ) return;
    if ( 'yes' === $order->get_meta( '_twshop_wallet_topup_revoke_processed' ) ) return;

    $user_id = $order->get_customer_id();
    if ( ! $user_id ) return;

    $credited        = twshop_wallet_get_order_topup_credited( $order_id );
    $remaining_paid  = $credited['paid'];
    $remaining_bonus = $credited['bonus'];
    if ( $remaining_paid <= 0 && $remaining_bonus <= 0 ) return;

    $balance    = twshop_wallet_get_balance( $user_id );
    $claw_paid  = min( $remaining_paid,  max( 0, $balance['paid'] ) );
    $claw_bonus = min( $remaining_bonus, max( 0, $balance['bonus'] ) );

    if ( $claw_paid > 0 || $claw_bonus > 0 ) {
        twshop_wallet_apply( $user_id, -$claw_paid, -$claw_bonus, 'topup_revoke', 'topup_revoke:' . $order_id, array(
            'order_id' => $order_id,
            'note'     => '訂單 #' . $order_id . ' 儲值訂單取消/退款，追回儲值金',
        ) );
    }

    $shortfall_paid  = round( $remaining_paid  - $claw_paid,  2 );
    $shortfall_bonus = round( $remaining_bonus - $claw_bonus, 2 );
    if ( $shortfall_paid > 0 || $shortfall_bonus > 0 ) {
        $order->add_order_note( sprintf(
            '⚠️ 儲值金追回不足，會員餘額已被花用：本金差額 NT$%s／加贈金差額 NT$%s，需人工處理（後台使用者個人資料頁手動調整儲值金）。',
            number_format( $shortfall_paid, 2 ), number_format( $shortfall_bonus, 2 )
        ) );
    }

    $order->update_meta_data( '_twshop_wallet_topup_revoke_processed', 'yes' );
    $order->save();
}
