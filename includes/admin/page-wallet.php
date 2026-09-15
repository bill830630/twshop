<?php
/**
 * 介面：儲值金（儲值方案、與等級消費額相關的設定）。
 *
 * 會員餘額搜尋／交易紀錄列表／Email 通知仍在第四階段，尚未實作——目前這個頁面只有
 * 線上儲值需要的「方案」與「設定」兩個頁籤，見 CLAUDE.md「儲值金模組」一節的階段說明。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function twshop_wallet_render_page() {
    twshop_render_admin_page( '儲值金', function () {
        $tabs = array(
            'plans'    => '儲值方案',
            'settings' => '設定',
        );
        $current = twshop_get_current_admin_tab( $tabs );
        twshop_render_admin_tabs( $tabs, $current, 'twshop-wallet' );
        if ( 'plans' === $current ) twshop_wallet_plans_tab();
        elseif ( 'settings' === $current ) twshop_wallet_settings_tab();
    } );
}

function twshop_wallet_plans_tab() {
    $plans        = get_option( 'wc_wallet_topup_plans', array() );
    $allow_custom = twshop_option( 'wc_wallet_allow_custom_amount' );
    $custom_min   = (float) get_option( 'wc_wallet_custom_min', 0 );
    $custom_max   = (float) get_option( 'wc_wallet_custom_max', 0 );
    ?>
    <form action="options.php" method="post">
        <?php settings_fields( 'wc_wallet_plans_group' ); ?>

        <div class="twshop-panel">
            <?php twshop_panel_head( 'wallet', '儲值方案', '設定顧客可選擇的儲值金額與加贈方案，顧客會在會員中心「我的儲值金」頁看到這些方案。顧客實際付款的金額是「儲值金額」，「加贈金額」是店家額外贈送、不需要顧客付費的部分。' ); ?>
            <div class="twshop-panel-body">
                <div id="wallet-plan-repeater-container">
                    <?php
                    if ( ! empty( $plans ) ) { foreach ( $plans as $plan ) echo twshop_get_wallet_plan_row_html( $plan ); }
                    else { echo twshop_get_wallet_plan_row_html( array() ); }
                    ?>
                </div>
                <p><button type="button" class="button" id="add-wallet-plan-row">新增方案</button></p>
            </div>
        </div>

        <div class="twshop-panel">
            <?php twshop_panel_head( 'sliders', '自訂金額' ); ?>
            <div class="twshop-panel-body">
                <table class="form-table">
                    <tr>
                        <th scope="row">開放自訂金額</th>
                        <td>
                            <label><input type="checkbox" name="wc_wallet_allow_custom_amount" value="yes" <?php checked( $allow_custom, 'yes' ); ?>> 允許顧客自行輸入儲值金額（不限方案）</label>
                            <p class="description">自訂金額沒有加贈金，只有上方設定的方案才會有加贈。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">自訂金額上下限</th>
                        <td>
                            最低 <input type="number" step="1" min="0" name="wc_wallet_custom_min" value="<?php echo esc_attr( $custom_min ); ?>" class="small-text">
                            ～ 最高 <input type="number" step="1" min="0" name="wc_wallet_custom_max" value="<?php echo esc_attr( $custom_max ); ?>" class="small-text">
                            <p class="description">0 = 不限制。</p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <?php submit_button( '儲存設定' ); ?>
    </form>

    <div id="wallet-plan-template" style="display:none;">
        <?php echo twshop_get_wallet_plan_row_html( array() ); ?>
    </div>

    <?php twshop_enqueue_asset_script( 'admin/wallet-plans' ); ?>
    <?php
}

function twshop_get_wallet_plan_row_html( $p ) {
    $id      = $p['id'] ?? '';
    $amount  = $p['amount'] ?? '';
    $bonus   = $p['bonus'] ?? '0';
    $enabled = $p['enabled'] ?? 'yes';
    ob_start();
    ?>
    <div class="twshop-wallet-plan-row" style="background:#fff; border:1px solid #ccd0d4; margin-bottom:10px; border-radius:5px;">
        <div style="padding:15px; display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end;">
            <span class="drag-handle" style="cursor:move; color:#999;" title="拖曳排序"><?php echo twshop_get_account_tab_icon_svg( 'grip-vertical' ); ?></span>
            <input type="hidden" name="wc_wallet_topup_plans[id][]" value="<?php echo esc_attr( $id ); ?>">
            <div style="flex:1; min-width:140px;">
                <label style="font-weight:bold; display:block; margin-bottom:5px;">儲值金額（顧客實付）</label>
                <input type="number" step="1" min="1" name="wc_wallet_topup_plans[amount][]" value="<?php echo esc_attr( $amount ); ?>" class="regular-text" style="width:100%;" required>
            </div>
            <div style="flex:1; min-width:140px;">
                <label style="font-weight:bold; display:block; margin-bottom:5px;">加贈金額</label>
                <input type="number" step="1" min="0" name="wc_wallet_topup_plans[bonus][]" value="<?php echo esc_attr( $bonus ); ?>" class="regular-text" style="width:100%;">
            </div>
            <div>
                <input type="hidden" class="wallet-plan-enabled-input" name="wc_wallet_topup_plans[enabled][]" value="<?php echo esc_attr( $enabled ); ?>">
                <label><input type="checkbox" class="wallet-plan-enabled-checkbox" <?php checked( $enabled, 'yes' ); ?>> 啟用</label>
            </div>
            <div style="margin-left:auto;">
                <button type="button" class="button remove-wallet-plan-row" style="color:#b32d2e; border-color:#b32d2e;">刪除</button>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
}

function twshop_wallet_settings_tab() {
    $full_amount = twshop_option( 'wc_wallet_tier_spend_full_amount' );
    ?>
    <form action="options.php" method="post">
        <?php settings_fields( 'wc_wallet_settings_group' ); ?>
        <div class="twshop-panel">
            <?php twshop_panel_head( 'settings', '儲值金與等級消費額' ); ?>
            <div class="twshop-panel-body">
                <table class="form-table">
                    <tr>
                        <th scope="row">用儲值金折抵時，等級消費額計算方式</th>
                        <td>
                            <label><input type="checkbox" name="wc_wallet_tier_spend_full_amount" value="yes" <?php checked( $full_amount, 'yes' ); ?>> 計入商品全額（折抵掉的部分仍算進等級消費額）</label>
                            <p class="description">預設勾選：儲值金是顧客先前已經付過的真錢，折抵消費時仍視同全額消費計算會員等級門檻。取消勾選則只計入實際透過其他金流付款的部分（跟點數折抵的既有計算方式一致）。線上儲值訂單本身（不論金額大小）一律不計入消費額與紅利點數，不受這個設定影響。</p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php submit_button( '儲存設定' ); ?>
    </form>
    <?php
}
