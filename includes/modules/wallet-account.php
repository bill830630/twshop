<?php
/**
 * 儲值金：會員中心「我的儲值金」頁籤、後台使用者個人資料頁手動加扣。
 *
 * 手動加扣 UI 比照 twshop_user_profile_management_ui()（points-engine.php）的既有版面，
 * 但掛在 wallet 模組（不是 member_tiers）底下——儲值金是獨立功能，不該綁在會員分級模組
 * 的開關上，見 CLAUDE.md「儲值金模組」一節。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function twshop_wallet_user_profile_management_ui( $user ) {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;
    $balance = twshop_wallet_get_balance( $user->ID );
    $history = twshop_wallet_get_ledger( $user->ID, 20 );
    ?>
    <div id="twshop-wallet-management">
    <h3>儲值金管理</h3>
    <table class="form-table">
        <tr>
            <th><label>手動增減儲值金</label></th>
            <td>
                <label>本金 <input type="number" step="0.01" name="twshop_wallet_manual_paid" value="" class="regular-text" placeholder="例如 500 或 -100" style="width:160px;"></label>
                &nbsp;
                <label>加贈金 <input type="number" step="0.01" name="twshop_wallet_manual_bonus" value="" class="regular-text" placeholder="例如 50" style="width:160px;"></label>
                <br><br>
                備註原因：<input type="text" name="twshop_wallet_reason" value="" class="regular-text" placeholder="手動調整">
                <button type="submit" class="button button-primary" style="margin-left:8px;">儲存儲值金</button>
                <p class="description">本金／加贈金分開填寫，正數為增加、負數為扣除，留空視為 0；兩者皆為 0 時不會產生任何紀錄。</p>
            </td>
        </tr>
        <tr>
            <th><label>目前餘額</label></th>
            <td>
                <span style="font-size:20px; font-weight:bold; color:#d63384;">NT$<?php echo esc_html( number_format( $balance['total'], 2 ) ); ?></span>
                <span style="margin-left:10px; color:#666;">（本金 NT$<?php echo esc_html( number_format( $balance['paid'], 2 ) ); ?> ＋ 加贈金 NT$<?php echo esc_html( number_format( $balance['bonus'], 2 ) ); ?>）</span>
            </td>
        </tr>
        <tr>
            <th><label>最新異動紀錄</label></th>
            <td>
                <div style="max-height:220px; overflow-y:auto; border:1px solid #ccc; padding:10px; background:#f9f9f9;">
                    <?php if ( empty( $history ) ) : ?>
                        <p style="margin:0; color:#666;">目前尚無紀錄</p>
                    <?php else : ?>
                        <table style="width:100%; text-align:left; border-collapse: collapse;">
                            <thead>
                                <tr style="border-bottom:1px solid #ddd;">
                                    <th style="padding:5px;">時間</th>
                                    <th style="padding:5px;">類型</th>
                                    <th style="padding:5px;">本金異動</th>
                                    <th style="padding:5px;">加贈異動</th>
                                    <th style="padding:5px;">原因</th>
                                    <th style="padding:5px;">餘額（本金/加贈）</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $history as $row ) : ?>
                                <tr style="border-bottom:1px solid #eee;">
                                    <td style="padding:5px;"><?php echo esc_html( $row['created_at'] ); ?></td>
                                    <td style="padding:5px;"><?php echo esc_html( twshop_wallet_type_label( $row['type'] ) ); ?></td>
                                    <td style="padding:5px;"><?php echo esc_html( twshop_wallet_signed_amount( $row['amount_paid'] ) ); ?></td>
                                    <td style="padding:5px;"><?php echo esc_html( twshop_wallet_signed_amount( $row['amount_bonus'] ) ); ?></td>
                                    <td style="padding:5px;"><?php echo esc_html( $row['note'] ); ?></td>
                                    <td style="padding:5px;"><?php echo esc_html( number_format( (float) $row['balance_paid_after'], 2 ) . ' / ' . number_format( (float) $row['balance_bonus_after'], 2 ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    </table>
    </div>
    <?php
}

function twshop_wallet_save_user_profile_management( $user_id ) {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;

    $paid  = isset( $_POST['twshop_wallet_manual_paid'] ) ? (float) wp_unslash( $_POST['twshop_wallet_manual_paid'] ) : 0.0;
    $bonus = isset( $_POST['twshop_wallet_manual_bonus'] ) ? (float) wp_unslash( $_POST['twshop_wallet_manual_bonus'] ) : 0.0;
    if ( 0.0 === round( $paid, 2 ) && 0.0 === round( $bonus, 2 ) ) return;

    $reason = sanitize_text_field( wp_unslash( $_POST['twshop_wallet_reason'] ?? '' ) );
    if ( '' === $reason ) $reason = '管理員手動調整';

    $result = twshop_wallet_apply( $user_id, $paid, $bonus, 'adjust', 'adjust:' . wp_generate_uuid4(), array(
        'note'       => $reason,
        'created_by' => get_current_user_id(),
    ) );

    // 用 user_profile_update_errors 顯示錯誤（例如手動扣款金額超過目前餘額）；
    // WordPress 核心會在頁面頂端統一渲染這裡加進去的 WP_Error。
    if ( is_wp_error( $result ) ) {
        add_action( 'user_profile_update_errors', function ( $errors ) use ( $result ) {
            $errors->add( 'twshop_wallet_error', $result->get_error_message() );
        } );
    }
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
