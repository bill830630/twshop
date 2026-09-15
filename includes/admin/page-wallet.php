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
            'balances' => '會員餘額',
            'ledger'   => '交易紀錄',
            'plans'    => '儲值方案',
            'settings' => '設定',
        );
        $current = twshop_get_current_admin_tab( $tabs );
        twshop_render_admin_tabs( $tabs, $current, 'twshop-wallet' );
        if ( 'balances' === $current ) twshop_wallet_balances_tab();
        elseif ( 'ledger' === $current ) twshop_wallet_ledger_tab();
        elseif ( 'plans' === $current ) twshop_wallet_plans_tab();
        elseif ( 'settings' === $current ) twshop_wallet_settings_tab();
    } );
}

/**
 * 會員搜尋欄位，重用 WooCommerce 核心已經註冊好的 `woocommerce_json_search_customers`
 * AJAX action（跟訂單編輯頁「客戶」欄位、訂單列表篩選同一套元件），不用自己另外寫
 * 搜尋後端。已選會員的顯示格式比照 WooCommerce 核心（`class-wc-meta-box-order-data.php`）
 * 「姓名 (#ID – Email)」的既有慣例，讓管理員在不同頁面看到的呈現方式一致。
 */
function twshop_render_wallet_customer_search_field( $name, $selected_user_id = 0 ) {
    $user_string = '';
    if ( $selected_user_id ) {
        $user = get_userdata( $selected_user_id );
        if ( $user ) {
            $customer    = new WC_Customer( $selected_user_id );
            $full_name   = trim( $customer->get_first_name() . ' ' . $customer->get_last_name() );
            $user_string = sprintf( '%s (#%d – %s)', $full_name ?: $user->display_name, $selected_user_id, $user->user_email );
        }
    }
    ?>
    <select class="wc-customer-search" name="<?php echo esc_attr( $name ); ?>" data-placeholder="搜尋會員姓名／Email" data-allow_clear="true" style="width:320px;">
        <?php if ( $selected_user_id && $user_string ) : ?>
            <option value="<?php echo esc_attr( $selected_user_id ); ?>" selected="selected"><?php echo esc_html( $user_string ); ?></option>
        <?php endif; ?>
    </select>
    <?php
}

function twshop_wallet_render_ledger_table_rows( $rows, $show_user_column = false ) {
    if ( empty( $rows ) ) {
        $colspan = $show_user_column ? 7 : 6;
        echo '<tr><td colspan="' . esc_attr( $colspan ) . '" style="text-align:center; color:#666;">沒有符合條件的紀錄</td></tr>';
        return;
    }
    foreach ( $rows as $row ) {
        $order_id = (int) $row['order_id'];
        ?>
        <tr>
            <td><?php echo esc_html( $row['created_at'] ); ?></td>
            <?php if ( $show_user_column ) :
                $u = get_userdata( (int) $row['user_id'] ); ?>
                <td><?php echo $u ? esc_html( $u->display_name . '（' . $u->user_email . '）') : '#' . (int) $row['user_id']; ?></td>
            <?php endif; ?>
            <td><?php echo esc_html( twshop_wallet_type_label( $row['type'] ) ); ?></td>
            <td><?php echo esc_html( twshop_wallet_signed_amount( $row['amount_paid'] ) ); ?></td>
            <td><?php echo esc_html( twshop_wallet_signed_amount( $row['amount_bonus'] ) ); ?></td>
            <td><?php echo esc_html( number_format( (float) $row['balance_paid_after'], 2 ) . ' / ' . number_format( (float) $row['balance_bonus_after'], 2 ) ); ?></td>
            <td>
                <?php echo esc_html( $row['note'] ); ?>
                <?php if ( $order_id ) : ?>
                    <br><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ); ?>">訂單 #<?php echo esc_html( $order_id ); ?></a>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

function twshop_wallet_balances_tab() {
    $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
    ?>
    <div class="twshop-panel">
        <?php twshop_panel_head( 'search', '搜尋會員' ); ?>
        <div class="twshop-panel-body">
            <form method="get">
                <input type="hidden" name="page" value="twshop-wallet">
                <input type="hidden" name="tab" value="balances">
                <?php twshop_render_wallet_customer_search_field( 'user_id', $user_id ); ?>
                <button type="submit" class="button">查看</button>
            </form>
        </div>
    </div>

    <?php
    if ( $user_id ) {
        $user = get_userdata( $user_id );
        if ( $user ) {
            $balance = twshop_wallet_get_balance( $user_id );
            $history = twshop_wallet_get_ledger( $user_id, 20 );
            ?>
            <div class="twshop-panel">
                <?php twshop_panel_head(
                    'wallet',
                    esc_html( $user->display_name ) . '（' . esc_html( $user->user_email ) . '）的儲值金',
                    '',
                    array(
                        'url'   => admin_url( 'user-edit.php?user_id=' . $user_id . '#twshop-wallet-management' ),
                        'label' => '前往手動調整',
                    )
                ); ?>
                <div class="twshop-panel-body">
                    <p style="font-size:22px; font-weight:bold; margin-bottom:4px;">NT$<?php echo esc_html( number_format( $balance['total'], 2 ) ); ?></p>
                    <p style="color:#666; margin-top:0;">本金 NT$<?php echo esc_html( number_format( $balance['paid'], 2 ) ); ?>／加贈金 NT$<?php echo esc_html( number_format( $balance['bonus'], 2 ) ); ?></p>
                    <h4>最近異動（最新 20 筆）</h4>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr><th>時間</th><th>類型</th><th>本金異動</th><th>加贈異動</th><th>餘額（本金/加贈）</th><th>備註</th></tr>
                        </thead>
                        <tbody>
                            <?php twshop_wallet_render_ledger_table_rows( $history, false ); ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php
        } else {
            echo '<div class="notice notice-error"><p>找不到這位會員。</p></div>';
        }
    }
    ?>

    <div class="twshop-panel">
        <?php twshop_panel_head( 'list', '餘額總覽（依總額排序，前 50 名）' ); ?>
        <div class="twshop-panel-body">
            <?php $overview = twshop_wallet_get_balances_overview( 50 ); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr><th>會員</th><th>本金</th><th>加贈金</th><th>合計</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if ( empty( $overview ) ) : ?>
                        <tr><td colspan="5" style="text-align:center; color:#666;">目前沒有任何會員持有儲值金</td></tr>
                    <?php else : foreach ( $overview as $row ) :
                        $u = get_userdata( (int) $row['user_id'] );
                        if ( ! $u ) continue;
                        $total = (float) $row['balance_paid'] + (float) $row['balance_bonus'];
                        ?>
                        <tr>
                            <td><?php echo esc_html( $u->display_name . '（' . $u->user_email . '）' ); ?></td>
                            <td><?php echo esc_html( number_format( (float) $row['balance_paid'], 2 ) ); ?></td>
                            <td><?php echo esc_html( number_format( (float) $row['balance_bonus'], 2 ) ); ?></td>
                            <td><?php echo esc_html( number_format( $total, 2 ) ); ?></td>
                            <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=twshop-wallet&tab=balances&user_id=' . $row['user_id'] ) ); ?>">查看</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

function twshop_wallet_ledger_tab() {
    $user_id   = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
    $type      = isset( $_GET['ledger_type'] ) ? sanitize_key( wp_unslash( $_GET['ledger_type'] ) ) : '';
    $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
    $date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
    $paged     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $per_page  = 50;

    $result = twshop_wallet_query_ledger( array(
        'user_id'   => $user_id,
        'type'      => $type,
        'date_from' => $date_from,
        'date_to'   => $date_to,
        'limit'     => $per_page,
        'offset'    => ( $paged - 1 ) * $per_page,
    ) );
    $total_pages = max( 1, (int) ceil( $result['total'] / $per_page ) );
    ?>
    <div class="twshop-panel">
        <?php twshop_panel_head( 'filter', '篩選' ); ?>
        <div class="twshop-panel-body">
            <form method="get">
                <input type="hidden" name="page" value="twshop-wallet">
                <input type="hidden" name="tab" value="ledger">
                <div style="display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end;">
                    <div>
                        <label style="display:block; font-weight:bold; margin-bottom:5px;">會員</label>
                        <?php twshop_render_wallet_customer_search_field( 'user_id', $user_id ); ?>
                    </div>
                    <div>
                        <label style="display:block; font-weight:bold; margin-bottom:5px;">類型</label>
                        <select name="ledger_type">
                            <option value="">全部</option>
                            <?php foreach ( twshop_wallet_get_type_labels() as $slug => $label ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $type, $slug ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-weight:bold; margin-bottom:5px;">起始日期</label>
                        <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
                    </div>
                    <div>
                        <label style="display:block; font-weight:bold; margin-bottom:5px;">結束日期</label>
                        <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
                    </div>
                    <div>
                        <button type="submit" class="button button-primary">篩選</button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=twshop-wallet&tab=ledger' ) ); ?>" class="button">清除</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="twshop-panel">
        <?php twshop_panel_head( 'list', '交易紀錄（共 ' . number_format( $result['total'] ) . ' 筆）' ); ?>
        <div class="twshop-panel-body">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr><th>時間</th><th>會員</th><th>類型</th><th>本金異動</th><th>加贈異動</th><th>餘額（本金/加贈）</th><th>備註</th></tr>
                </thead>
                <tbody>
                    <?php twshop_wallet_render_ledger_table_rows( $result['rows'], true ); ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) :
                $base_args = array_filter( array(
                    'page'        => 'twshop-wallet',
                    'tab'         => 'ledger',
                    'user_id'     => $user_id ?: null,
                    'ledger_type' => $type ?: null,
                    'date_from'   => $date_from ?: null,
                    'date_to'     => $date_to ?: null,
                ) );
                ?>
                <div style="margin-top:12px;">
                    <?php echo paginate_links( array(
                        'base'      => add_query_arg( array_merge( $base_args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $total_pages,
                        'add_args'  => false,
                    ) ); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
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
    $full_amount   = twshop_option( 'wc_wallet_tier_spend_full_amount' );
    $email_enabled = twshop_option( 'wc_wallet_topup_email_enabled' );
    $email_subject = twshop_option( 'wc_wallet_topup_email_subject' );
    $email_body    = get_option( 'wc_wallet_topup_email_body', "親愛的 {name}：\n\n您的儲值已完成！\n\n本次儲值：NT{amount}\n加贈金額：NT{bonus}\n目前餘額：NT{balance}\n\n感謝您的支持！" );
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

        <div class="twshop-panel">
            <?php twshop_panel_head( 'mail', '儲值成功通知信' ); ?>
            <div class="twshop-panel-body">
                <table class="form-table">
                    <tr>
                        <th scope="row">寄送通知信</th>
                        <td><label><input type="checkbox" name="wc_wallet_topup_email_enabled" value="yes" <?php checked( $email_enabled, 'yes' ); ?>> 儲值訂單付款完成、入帳成功後寄送通知信給會員</label></td>
                    </tr>
                    <tr>
                        <th scope="row">主旨</th>
                        <td><input type="text" name="wc_wallet_topup_email_subject" value="<?php echo esc_attr( $email_subject ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row">內容</th>
                        <td>
                            <textarea name="wc_wallet_topup_email_body" rows="6" class="regular-text" style="width:100%; max-width:500px;"><?php echo esc_textarea( $email_body ); ?></textarea>
                            <p class="description">可用 <code>{name}</code>／<code>{amount}</code>（本次儲值本金）／<code>{bonus}</code>（本次加贈金額）／<code>{balance}</code>（目前總餘額）／<code>{order_id}</code>。</p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <?php submit_button( '儲存設定' ); ?>
    </form>
    <?php
}
