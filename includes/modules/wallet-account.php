<?php
/**
 * 儲值金：會員中心「我的儲值金」頁籤。
 *
 * 手動加扣 UI 原本在使用者個人資料頁（`profile_personal_options` 等 hook），v25.8.66 起
 * 搬到後台「儲值金 ▸ 會員餘額」頁籤（`twshop_wallet_balances_tab()`，`page-wallet.php`），
 * 管理員找會員餘額跟調整餘額現在是同一個地方，不用再跳去使用者編輯頁。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 「立即儲值」區塊：方案清單（點擊直接建單導向付款頁）＋可選的自訂金額輸入。
 * 沒有任何啟用中的方案、也不開放自訂金額時整段不輸出（模組雖然啟用，但管理員還沒去
 * 「儲值金」頁設定任何方案，此時不該讓顧客看到一個點了也儲不了值的空區塊）。
 */
function twshop_render_wallet_topup_section() {
    $plans        = twshop_wallet_get_plans();
    $allow_custom = 'yes' === twshop_option( 'wc_wallet_allow_custom_amount' );
    if ( empty( $plans ) && ! $allow_custom ) return;

    $custom_min = (float) get_option( 'wc_wallet_custom_min', 0 );
    $custom_max = (float) get_option( 'wc_wallet_custom_max', 0 );
    ?>
    <div class="twshop-wallet-topup" style="margin:16px 0; padding:16px; background:#f9f9f9; border:1px solid #eee; border-radius:6px;">
        <h4 style="margin-top:0;">立即儲值</h4>
        <?php if ( ! empty( $plans ) ) : ?>
            <div class="twshop-wallet-plan-list" style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                <?php foreach ( $plans as $plan ) : ?>
                    <button type="button" class="button twshop-wallet-plan-btn" data-plan_id="<?php echo esc_attr( $plan['id'] ); ?>">
                        NT$<?php echo esc_html( number_format( (float) $plan['amount'], 0 ) ); ?>
                        <?php if ( (float) $plan['bonus'] > 0 ) : ?>
                            <br><small style="color:#d63384;">送 NT$<?php echo esc_html( number_format( (float) $plan['bonus'], 0 ) ); ?></small>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ( $allow_custom ) : ?>
            <div class="twshop-wallet-custom-amount" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <input type="number" step="1" min="<?php echo esc_attr( $custom_min > 0 ? $custom_min : 1 ); ?>"
                       <?php echo $custom_max > 0 ? 'max="' . esc_attr( $custom_max ) . '"' : ''; ?>
                       id="twshop_wallet_custom_amount" placeholder="自訂金額">
                <button type="button" class="button" id="twshop_wallet_custom_topup_btn">前往儲值</button>
            </div>
            <?php if ( $custom_min > 0 || $custom_max > 0 ) : ?>
                <p class="description" style="margin:6px 0 0;">
                    <?php if ( $custom_min > 0 && $custom_max > 0 ) : ?>
                        金額需介於 NT$<?php echo esc_html( number_format( $custom_min ) ); ?> ～ NT$<?php echo esc_html( number_format( $custom_max ) ); ?> 之間
                    <?php elseif ( $custom_min > 0 ) : ?>
                        金額不可低於 NT$<?php echo esc_html( number_format( $custom_min ) ); ?>
                    <?php else : ?>
                        金額不可高於 NT$<?php echo esc_html( number_format( $custom_max ) ); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * 會員中心「我的儲值金」頁籤內容，掛 woocommerce_account_my-wallet_endpoint（見 init.php）。
 */
function twshop_my_wallet_endpoint_content() {
    $user_id = get_current_user_id();
    $balance = twshop_wallet_get_balance( $user_id );
    $history = twshop_wallet_get_ledger( $user_id, 30 );
    ?>
    <div class="twshop-wallet-account">
        <p style="font-size:22px; font-weight:bold; margin-bottom:4px;">
            NT$<?php echo esc_html( number_format( $balance['total'], 2 ) ); ?>
        </p>
        <p style="color:#666; margin-top:0;">
            本金 NT$<?php echo esc_html( number_format( $balance['paid'], 2 ) ); ?>／加贈金 NT$<?php echo esc_html( number_format( $balance['bonus'], 2 ) ); ?>
        </p>

        <?php twshop_render_wallet_topup_section(); ?>

        <h4>交易紀錄</h4>
        <?php if ( empty( $history ) ) : ?>
            <p>目前尚無交易紀錄。</p>
        <?php else : ?>
            <table class="woocommerce-table shop_table twshop-wallet-history">
                <thead>
                    <tr>
                        <th>時間</th><th>類型</th><th>本金</th><th>加贈金</th><th>備註</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $history as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row['created_at'] ); ?></td>
                        <td><?php echo esc_html( twshop_wallet_type_label( $row['type'] ) ); ?></td>
                        <td><?php echo esc_html( twshop_wallet_signed_amount( $row['amount_paid'] ) ); ?></td>
                        <td><?php echo esc_html( twshop_wallet_signed_amount( $row['amount_bonus'] ) ); ?></td>
                        <td><?php echo esc_html( $row['note'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
