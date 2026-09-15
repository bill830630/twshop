<?php
/**
 * 儲值金：線上儲值改用「儲值金商品」（v25.8.67 起，取代原本的「儲值方案」機制）。
 *
 * 顧客把標記為「儲值金商品」的商品加進購物車、走正常結帳流程（可以跟其他一般商品
 * 同一張訂單一起買），付款完成後依商品設定的「儲值金額度」逐項入帳。跟結帳折抵
 * （wallet-checkout.php）是相反方向：那邊是「花掉」儲值金，這裡是「儲值金怎麼進來」。
 *
 * 商品 meta `_twshop_wallet_product`（yes/no）是「這是儲值金商品」的唯一判斷依據；
 * `_twshop_wallet_credit_amount` 是每件實際入帳多少（面額）。訂單不再有「整張訂單是
 * 儲值訂單」這個概念——一張訂單可能同時有儲值金商品與一般商品，逐項處理。
 *
 * 加贈金不是後台另外設定的欄位，是「顧客實付金額」與「商品面額」的差額自動算出來的
 * （面額 1000、售價 900 → 本金 900 + 加贈 100；面額等於售價則沒有加贈）。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 這張訂單裡所有「儲值金商品」項目的金額加總（`get_total()+get_total_tax()`）。
 * 供 `twshop_award_points_on_order_complete()`（points-engine.php）與
 * `twshop_get_order_total_for_tier_spend()`（membership.php）共用，兩處都要從
 * 「這張訂單算多少消費額/點數基準」裡扣掉儲值金商品的金額——買儲值金本身不是消費，
 * 真正花掉這筆儲值金買東西時，該筆消費訂單自然會計入，不能兩邊都算。
 */
function twshop_get_order_wallet_product_total( $order ) {
    $total = 0.0;
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( ! $product || 'yes' !== $product->get_meta( '_twshop_wallet_product' ) ) continue;
        $total += (float) $item->get_total() + (float) $item->get_total_tax();
    }
    return round( $total, 2 );
}

/**
 * 商品編輯頁欄位：標記商品為「儲值金商品」＋設定面額。比照
 * `twshop_add_badge_product_fields()`（discount-engine.php）的既有寫法，只是換一組欄位。
 * 只在簡單商品顯示——多個面額用多個簡單商品表示，不支援可變商品規格。
 */
function twshop_add_wallet_product_fields() {
    echo '<div class="options_group show_if_simple twshop-wallet-product-fields">';
    woocommerce_wp_checkbox( array(
        'id'          => '_twshop_wallet_product',
        'label'       => '儲值金商品',
        'description' => '勾選後，顧客購買這個商品會依下方「儲值金額度」入帳到會員的儲值金餘額，不是把商品本身出貨給顧客。儲存後會自動勾選「虛擬商品」，結帳不會要求填運送資訊。',
    ) );
    woocommerce_wp_text_input( array(
        'id'                => '_twshop_wallet_credit_amount',
        'label'             => '儲值金額度（每件）',
        'placeholder'       => '例如 1000',
        'desc_tip'          => true,
        'description'       => '購買 1 件這個商品，會員的儲值金餘額增加多少。若高於商品售價，差額會自動變成「加贈金」；等於售價則沒有加贈。',
        'type'              => 'number',
        'custom_attributes' => array( 'step' => '1', 'min' => '0' ),
    ) );
    echo '</div>';
}

function twshop_save_wallet_product_fields( $post_id ) {
    $is_wallet_product = isset( $_POST['_twshop_wallet_product'] ) ? 'yes' : 'no';
    update_post_meta( $post_id, '_twshop_wallet_product', $is_wallet_product );

    $credit_amount = isset( $_POST['_twshop_wallet_credit_amount'] )
        ? round( (float) wp_unslash( $_POST['_twshop_wallet_credit_amount'] ), 2 )
        : 0.0;
    update_post_meta( $post_id, '_twshop_wallet_credit_amount', $credit_amount );

    // 儲值金商品不需要出貨，強制勾選「虛擬商品」——避免管理員忘記勾 WooCommerce 原生的
    // 虛擬商品欄位，導致結帳被要求填運送地址、混合購買時整張訂單卡在等出貨。
    if ( 'yes' === $is_wallet_product ) {
        $product = wc_get_product( $post_id );
        if ( $product && ! $product->is_virtual() ) {
            $product->set_virtual( true );
            $product->save();
        }
    }
}

/**
 * 對單一訂單項目追回「差額」金額，依這個項目已入帳的本金/加贈金比例分配 $delta、
 * 依可用餘額夾住（不透支）、記錄新的累計已追回量，餘額不足時留訂單備註。
 * `twshop_wallet_revoke_topup_order()`（整單取消/退款/失敗）與
 * `twshop_wallet_handle_topup_item_refund()`（部分退款）共用同一套邏輯，只是
 * 呼叫端算出來的 $delta 與 $ref 不同。
 *
 * 【設計取捨：一次性最佳努力，不自動重試】跟結帳折抵那邊的退回邏輯（只會加錢）不同，
 * 這裡是扣錢，可能因為餘額已經被花掉而扣不滿。扣不滿的差額只留訂單備註，不會在之後
 * 自動重試——若日後餘額回升想把差額追回，管理員要透過後台使用者個人資料頁的「手動
 * 增減儲值金」自行處理。
 */
function twshop_wallet_claw_back_item_amount( $order, $item, $user_id, $delta, $ref, $note ) {
    $credited_paid  = round( (float) $item->get_meta( '_twshop_wallet_topup_credited_paid' ), 2 );
    $credited_bonus = round( (float) $item->get_meta( '_twshop_wallet_topup_credited_bonus' ), 2 );
    $credited_total = round( $credited_paid + $credited_bonus, 2 );
    $ratio_paid     = $credited_total > 0 ? $credited_paid / $credited_total : 0;

    $want_paid  = round( $delta * $ratio_paid, 2 );
    $want_bonus = round( $delta - $want_paid, 2 );

    $balance    = twshop_wallet_get_balance( $user_id );
    $claw_paid  = min( $want_paid,  max( 0, $balance['paid'] ) );
    $claw_bonus = min( $want_bonus, max( 0, $balance['bonus'] ) );

    if ( $claw_paid > 0 || $claw_bonus > 0 ) {
        twshop_wallet_apply( $user_id, -$claw_paid, -$claw_bonus, 'topup_revoke', $ref, array(
            'order_id' => $order->get_id(),
            'note'     => $note,
        ) );

        $already_revoked_paid  = round( (float) $item->get_meta( '_twshop_wallet_topup_revoked_paid' ), 2 );
        $already_revoked_bonus = round( (float) $item->get_meta( '_twshop_wallet_topup_revoked_bonus' ), 2 );
        $item->update_meta_data( '_twshop_wallet_topup_revoked_paid', $already_revoked_paid + $claw_paid );
        $item->update_meta_data( '_twshop_wallet_topup_revoked_bonus', $already_revoked_bonus + $claw_bonus );
        $item->save_meta_data();
    }

    $shortfall_paid  = round( $want_paid  - $claw_paid,  2 );
    $shortfall_bonus = round( $want_bonus - $claw_bonus, 2 );
    if ( $shortfall_paid > 0 || $shortfall_bonus > 0 ) {
        $product = $item->get_product();
        $order->add_order_note( sprintf(
            '⚠️ 儲值金商品「%s」追回不足，會員餘額已被花用：本金差額 NT$%s／加贈金差額 NT$%s，需人工處理（後台使用者個人資料頁手動調整儲值金）。',
            $product ? $product->get_name() : ( '項目 #' . $item->get_id() ),
            number_format( $shortfall_paid, 2 ), number_format( $shortfall_bonus, 2 )
        ) );
    }

    return array( 'paid' => $claw_paid, 'bonus' => $claw_bonus );
}

/**
 * 付款完成（涵蓋所有金流的統一 hook）：逐項掃描這張訂單，找出儲值金商品項目入帳。
 * ref 用訂單項目 ID（`$item_id`，WooCommerce 全站唯一），天然冪等；另外用訂單項目
 * meta `_twshop_wallet_topup_credited`（及 `_credited_paid`/`_credited_bonus`）標記
 * 「這個項目已經處理過」，除了避免 `woocommerce_payment_complete` 因網路重試觸發兩次
 * 時重複寄送通知信（`twshop_wallet_apply()` 本身的 ref 冪等已經防止重複入帳，但無法
 * 防止這支函式重複判斷「本次有沒有真的入帳」），也是追回邏輯（見下方兩支函式）拿
 * 「這個項目當初入帳了多少」的權威來源——沒有另外查帳本 SUM，因為帳本的 `order_id`
 * 欄位不含項目層級的區分，一張訂單有多個儲值金商品項目時無法用 SQL 反查回單一項目。
 *
 * **不強制把整張訂單轉 completed**：訂單裡若還有其他一般商品需要出貨，維持
 * WooCommerce 原生的 processing/completed 判斷；全部都是虛擬商品時，核心的
 * `needs_processing()` 本來就會判定不需要處理、自動轉完成，不需要介入。
 */
function twshop_wallet_credit_on_payment_complete( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $user_id = $order->get_customer_id();
    if ( ! $user_id ) return;

    $total_paid   = 0.0;
    $total_bonus  = 0.0;
    $any_credited = false;

    foreach ( $order->get_items() as $item_id => $item ) {
        $product = $item->get_product();
        if ( ! $product || 'yes' !== $product->get_meta( '_twshop_wallet_product' ) ) continue;
        if ( 'yes' === $item->get_meta( '_twshop_wallet_topup_credited' ) ) continue; // 已處理過，不重複入帳、不重複算進本次通知信

        $credit_per_unit = round( (float) $product->get_meta( '_twshop_wallet_credit_amount' ), 2 );
        $qty             = max( 1, (int) $item->get_quantity() );
        $credit_total    = round( $credit_per_unit * $qty, 2 );
        if ( $credit_total <= 0 ) continue;

        $paid_for_item = round( (float) $item->get_total() + (float) $item->get_total_tax(), 2 );
        $paid  = max( 0, min( $paid_for_item, $credit_total ) );
        $bonus = round( $credit_total - $paid, 2 );

        if ( $paid > 0 ) {
            twshop_wallet_apply( $user_id, $paid, 0, 'topup', 'topup:' . $item_id, array(
                'order_id' => $order_id,
                'note'     => '訂單 #' . $order_id . ' 儲值金商品「' . $product->get_name() . '」',
            ) );
        }
        if ( $bonus > 0 ) {
            twshop_wallet_apply( $user_id, 0, $bonus, 'topup_bonus', 'topup_bonus:' . $item_id, array(
                'order_id' => $order_id,
                'note'     => '訂單 #' . $order_id . ' 儲值金商品「' . $product->get_name() . '」加贈',
            ) );
        }

        $item->update_meta_data( '_twshop_wallet_topup_credited', 'yes' );
        $item->update_meta_data( '_twshop_wallet_topup_credited_paid', $paid );
        $item->update_meta_data( '_twshop_wallet_topup_credited_bonus', $bonus );
        $item->save_meta_data();

        $total_paid  += $paid;
        $total_bonus += $bonus;
        $any_credited = true;
    }

    if ( $any_credited ) {
        twshop_wallet_maybe_send_topup_email( $order, $user_id, $total_paid, $total_bonus );
    }
}

/**
 * 儲值成功通知信，開關與範本見「儲值金 ▸ 設定」頁籤。跟會員等級的生日禮/升等禮通知信
 * 同一套做法（`str_replace` 套版＋直接同步 `wp_mail()`，不像點數到期提醒那樣走
 * `wp_schedule_single_event()` 排隊——這裡是使用者當下操作觸發的即時通知，不是批次
 * 掃描大量會員的排程情境，不需要非同步化）。$paid/$bonus 是這張訂單所有儲值金商品
 * 項目加總後的本金/加贈金（一張訂單可能買了不只一個儲值金商品）。
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
 * 訂單取消/退款/付款失敗：逐項掃描這張訂單裡已入帳的儲值金商品項目，追回尚未追回的
 * 剩餘部分。訂單項目 meta `_twshop_wallet_topup_revoke_processed` 是一次性旗標——這個
 * 項目只會被這支函式完整處理一次，即使 `cancelled`/`refunded`/`failed` 三個狀態的 hook
 * 都可能對同一張訂單各觸發一次，也不會重複追回或重複寫入差額備註。
 */
function twshop_wallet_revoke_topup_order( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $user_id = $order->get_customer_id();
    if ( ! $user_id ) return;

    foreach ( $order->get_items() as $item_id => $item ) {
        if ( 'yes' !== $item->get_meta( '_twshop_wallet_topup_credited' ) ) continue;
        if ( 'yes' === $item->get_meta( '_twshop_wallet_topup_revoke_processed' ) ) continue;

        $credited_paid  = round( (float) $item->get_meta( '_twshop_wallet_topup_credited_paid' ), 2 );
        $credited_bonus = round( (float) $item->get_meta( '_twshop_wallet_topup_credited_bonus' ), 2 );
        $already_paid   = round( (float) $item->get_meta( '_twshop_wallet_topup_revoked_paid' ), 2 );
        $already_bonus  = round( (float) $item->get_meta( '_twshop_wallet_topup_revoked_bonus' ), 2 );
        $remaining      = round( ( $credited_paid + $credited_bonus ) - ( $already_paid + $already_bonus ), 2 );

        if ( $remaining > 0 ) {
            $product = $item->get_product();
            twshop_wallet_claw_back_item_amount(
                $order, $item, $user_id, $remaining,
                'topup_revoke:' . $item_id,
                '訂單 #' . $order_id . ' 儲值金商品「' . ( $product ? $product->get_name() : '' ) . '」取消/退款，追回儲值金'
            );
        }

        $item->update_meta_data( '_twshop_wallet_topup_revoke_processed', 'yes' );
        $item->save_meta_data();
    }
}

/**
 * 部分退款（`woocommerce_order_refunded`）：只對「有部分退款到這個儲值金商品項目」的
 * 項目按比例追回，不影響同一張訂單裡其他沒有被退款的項目（也不影響一般商品項目）。
 *
 * 用 WooCommerce 原生的 `get_total_refunded_for_item()`（該項目**累計**已退款金額，
 * 天然涵蓋「同一項目被分好幾次退款」）算出這個項目目前應該追回到多少（比例 × 已入帳
 * 總額），跟 `_twshop_wallet_topup_revoked_*` meta 記錄的「已經追回多少」比對，只處理
 * 差額——比照 `twshop_deduct_wallet_on_checkout()`（wallet-checkout.php）既有的
 * 「目標值 vs 已處理，只處理差額」寫法，`ref` 帶目標金額，天然冪等且支援分批退款。
 */
function twshop_wallet_handle_topup_item_refund( $order_id, $refund_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $user_id = $order->get_customer_id();
    if ( ! $user_id ) return;

    foreach ( $order->get_items() as $item_id => $item ) {
        if ( 'yes' !== $item->get_meta( '_twshop_wallet_topup_credited' ) ) continue;

        $credited_paid  = round( (float) $item->get_meta( '_twshop_wallet_topup_credited_paid' ), 2 );
        $credited_bonus = round( (float) $item->get_meta( '_twshop_wallet_topup_credited_bonus' ), 2 );
        $credited_total = round( $credited_paid + $credited_bonus, 2 );
        if ( $credited_total <= 0 ) continue;

        $item_original_total = round( (float) $item->get_total() + (float) $item->get_total_tax(), 2 );
        if ( $item_original_total <= 0 ) continue;

        $refunded_for_item = abs( (float) $order->get_total_refunded_for_item( $item_id ) );
        $proportion   = min( 1, $refunded_for_item / $item_original_total );
        $target_total = round( $credited_total * $proportion, 2 );

        $already_paid  = round( (float) $item->get_meta( '_twshop_wallet_topup_revoked_paid' ), 2 );
        $already_bonus = round( (float) $item->get_meta( '_twshop_wallet_topup_revoked_bonus' ), 2 );
        $delta = round( $target_total - ( $already_paid + $already_bonus ), 2 );
        if ( $delta <= 0 ) continue;

        $product = $item->get_product();
        twshop_wallet_claw_back_item_amount(
            $order, $item, $user_id, $delta,
            'topup_partial_return:' . $item_id . ':' . number_format( $target_total, 2, '.', '' ),
            '訂單 #' . $order_id . ' 儲值金商品「' . ( $product ? $product->get_name() : '' ) . '」部分退款（' . round( $proportion * 100 ) . '%），追回儲值金'
        );
    }
}
