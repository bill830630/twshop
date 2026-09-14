<?php
/**
 * 介面 3：折扣與贈品管理（獨立 AJAX 儲存與拖曳排序）
 *
 * 自 twshop.php 拆出（Phase 4 拆檔重構），之後的修正見 CLAUDE.md。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// -------------------------------------------------------------------------
// 介面 3：折扣與贈品管理 (獨立 AJAX 儲存與拖曳排序)
// -------------------------------------------------------------------------
function twshop_marketing_rules_tab() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足。' );
    $rules = twshop_get_rules(); // 用這個而非直接 get_option()，確保缺 rule_id 的舊規則已補上唯一值（見 twshop_backfill_missing_rule_ids()）
    $tiers = get_option( 'wc_member_tiers_settings', array() );

    $product_cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
    $product_tags = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
    if ( is_wp_error( $product_cats ) ) $product_cats = array();
    if ( is_wp_error( $product_tags ) ) $product_tags = array();

    $addon_title      = twshop_option( 'wc_addon_section_title' );
    $addon_btn_add    = twshop_option( 'wc_addon_btn_add_text' );
    $addon_btn_incart = twshop_option( 'wc_addon_btn_incart_text' );
    ?>
        <form action="options.php" method="post">
            <?php settings_fields( 'wc_marketing_rules_group' ); ?>
            <div class="twshop-panel">
                <?php twshop_panel_head( 'tag', '加購商品顯示文字' ); ?>
                <div class="twshop-panel-body">
                    <table class="form-table">
                        <tr><th scope="row">加購區塊標題</th><td><input type="text" name="wc_addon_section_title" value="<?php echo esc_attr( $addon_title ); ?>" class="regular-text" /></td></tr>
                        <tr><th scope="row">加入加購按鈕文字</th><td><input type="text" name="wc_addon_btn_add_text" value="<?php echo esc_attr( $addon_btn_add ); ?>" class="regular-text" /></td></tr>
                        <tr>
                            <th scope="row">移除按鈕文字（已在購物車時）</th>
                            <td>
                                <input type="text" name="wc_addon_btn_incart_text" value="<?php echo esc_attr( $addon_btn_incart ); ?>" class="regular-text" />
                                <p class="description">商品已在購物車時，按鈕將變為紅色移除鍵，顯示此文字。</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            <?php submit_button( '儲存加購文字' ); ?>
        </form>

        <p class="twshop-admin-intro">每一筆規則都可以單獨編輯與儲存，利用卡片標題左側圖示可拖曳變更優先順序！</p>

        <?php echo twshop_render_rule_overlap_warnings( $rules ); ?>

        <p class="twshop-rule-status" id="twshop-rule-toolbar-status" aria-live="polite"></p>

        <div id="discount-repeater-container" style="margin-top:10px;">
            <?php foreach ( $rules as $rule ) echo twshop_get_rule_row_html( $rule, $tiers, $product_cats, $product_tags ); ?>
        </div>

        <div id="discount-rule-template" style="display:none;">
            <?php echo twshop_get_rule_row_html(array(), $tiers, $product_cats, $product_tags); ?>
        </div>
        <template id="twshop-tier-row-template"><?php echo twshop_get_rule_tier_row_html(); ?></template>

        <p><button type="button" class="button" id="add-rule-row">新增規則表單</button></p>
    <?php twshop_render_chip_field_assets(); ?>
    <?php twshop_enqueue_asset_script( 'admin/discount-rules', array(
        'twshopDiscountRules' => array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'twshop_admin_action' ),
        ),
    ) ); ?>
    <?php
}

function twshop_get_rule_row_html( $r = array(), $tiers = array(), $cats = array(), $tags = array() ) {
    $r_id = $r['rule_id'] ?? ''; $name = $r['name'] ?? ''; $role = $r['role'] ?? 'all'; $type = $r['type'] ?? 'percent';
    $val = $r['value'] ?? ''; $gift_id = $r['gift_product_id'] ?? ''; $logic = $r['logic'] ?? 'and';
    $min = $r['min_amount'] ?? '';
    $limit = $r['usage_limit'] ?? ''; $u_limit = $r['user_limit'] ?? '';
    $s_time = $r['start_time'] ?? ''; $e_time = $r['end_time'] ?? '';
    $is_c = $r['is_coupon'] ?? 'no'; $c_code = $r['c_code'] ?? ''; $c_title = $r['c_title'] ?? '';
    $c_desc = $r['c_desc'] ?? ''; $c_exclusive = $r['c_exclusive'] ?? 'no';
    $enabled = $r['enabled'] ?? 'yes'; $stack_exclusive = $r['stack_exclusive'] ?? 'no';
    $buy_qty = $r['buy_qty'] ?? ''; $free_qty = $r['free_qty'] ?? '';
    // 注意：命名為 $rule_tiers 以跟本函式第二參數 $tiers（會員等級清單，供「套用對象」下拉使用）區分開來。
    $rule_tiers = is_array( $r['tiers'] ?? null ) ? $r['tiers'] : array();
    $shipping_methods = $r['shipping_methods'] ?? array();
    if ( ! is_array( $shipping_methods ) ) $shipping_methods = array();
    $shipping_method_options = twshop_get_shipping_method_options();

    // 限制條件：類型 (單一商品/商品分類/商品標籤) + 該類型底下的複選項目
    $cond_type = $r['condition_type'] ?? '';
    $cond_values = $r['condition_values'] ?? array();
    if ( empty( $cond_type ) && ! empty( $r['category'] ) ) { $cond_type = 'category'; $cond_values = array( $r['category'] ); }
    if ( empty( $cond_type ) && ! empty( $r['tag'] ) )      { $cond_type = 'tag'; $cond_values = array( $r['tag'] ); }
    if ( ! is_array( $cond_values ) ) $cond_values = array();
    $cond_products = ( $cond_type === 'product' ) ? array_map( 'strval', $cond_values ) : array();
    $cond_cats     = ( $cond_type === 'category' ) ? $cond_values : array();
    $cond_tags     = ( $cond_type === 'tag' ) ? $cond_values : array();

    $cat_options = array();
    foreach ( $cats as $term ) { $cat_options[ $term->slug ] = $term->name; }
    $tag_options = array();
    foreach ( $tags as $term ) { $tag_options[ $term->slug ] = $term->name; }

    $label_style = 'font-weight:bold; display:block; margin-bottom:5px;';
    $sub_label   = 'font-size:13px; display:block; margin-bottom:4px;';

    ob_start();
    ?>
    <?php // novalidate：卡片裡有依類型隱藏的欄位（例如買N送N 數量存著 0），瀏覽器內建驗證會因為隱藏欄位不合格而靜默擋下送出、又無法顯示提示；改由 discount-rules.js 自行檢查。 ?>
    <form novalidate class="twshop-rule-form twshop-rule-card<?php echo $enabled === 'no' ? ' twshop-rule-disabled' : ''; ?>" data-rule-name="<?php echo esc_attr( $name ); ?>" data-rule-type="<?php echo esc_attr( $type ); ?>" data-rule-enabled="<?php echo esc_attr( $enabled ); ?>" style="background:#fff; border:1px solid #ccd0d4; margin-bottom:20px; border-radius:5px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <input type="hidden" name="rule_id" value="<?php echo esc_attr($r_id); ?>" />
        <?php wp_nonce_field( 'twshop_admin_action', 'twshop_nonce' ); ?>

        <div class="twshop-card-header">
            <span class="drag-handle" title="拖曳排序（越前面越先套用）"><?php echo twshop_get_account_tab_icon_svg( 'grip-vertical' ); ?></span>
            <span class="twshop-card-header-controls" title="切換後立即生效">
                <label class="twshop-switch">
                    <input type="checkbox" class="twshop-rule-enabled-toggle" name="enabled" value="yes" <?php checked( $enabled, 'yes' ); ?> />
                    <span class="twshop-switch-slider" aria-hidden="true"></span>
                    <span class="twshop-switch-text"><?php echo $enabled === 'no' ? '停用' : '啟用'; ?></span>
                </label>
            </span>
            <input type="text" name="name" class="twshop-rule-name-input" value="<?php echo esc_attr( $name ); ?>" placeholder="規則名稱（依設定自動產生）" aria-label="規則名稱" title="依下方設定自動產生，可直接修改；修改後不再自動更新，清空即恢復自動命名" required />
            <span class="twshop-badge twshop-badge--warn twshop-rule-dirty-badge" style="display:none;">未儲存</span>
            <span class="twshop-card-header-controls" title="選好或清除後立即生效">
                <span class="twshop-rule-schedule">
                    <label>開始 <input type="text" class="twshop-datetime-picker" name="start_time" value="<?php echo esc_attr( $s_time ); ?>" placeholder="立即" /></label>
                    <label>結束 <input type="text" class="twshop-datetime-picker" name="end_time" value="<?php echo esc_attr( $e_time ); ?>" placeholder="不限" /></label>
                    <a href="#" class="twshop-clear-datetime" title="清除開始與結束時間">清除</a>
                </span>
            </span>
            <span class="twshop-rule-status" aria-live="polite"></span>
            <span class="twshop-card-toggle-icon" title="點擊收合或展開"><?php echo twshop_get_account_tab_icon_svg( 'chevron-down' ); ?></span>
        </div>

        <div class="twshop-card-body" style="padding:20px; display:none;">

            <h4 class="twshop-rule-section-title">1. 規則類型與折扣</h4>
            <div style="display:flex; flex-wrap:wrap; gap:15px; margin-bottom:15px;">
                <div style="flex:1.5; min-width:220px;">
                    <label style="<?php echo $label_style; ?>">折扣與贈品類型</label>
                    <select name="type" class="twshop-rule-type" style="width:100%;">
                        <option value="percent" <?php selected($type, 'percent'); ?>>商品單價打折 (%)</option>
                        <option value="fixed_product" <?php selected($type, 'fixed_product'); ?>>商品單價折抵 ($)</option>
                        <option value="cart_percent" <?php selected($type, 'cart_percent'); ?>>整筆訂單打折 (%)</option>
                        <option value="cart_discount" <?php selected($type, 'cart_discount'); ?>>整筆訂單折抵 ($)</option>
                        <option value="free_shipping" <?php selected($type, 'free_shipping'); ?>>整單免運費</option>
                        <option value="free_gift" <?php selected($type, 'free_gift'); ?>>滿額/條件贈品 (自動加入購物車)</option>
                        <option value="addon_product" <?php selected($type, 'addon_product'); ?>>加購商品 (符合條件以特價購買)</option>
                        <option value="buy_x_get_y" <?php selected($type, 'buy_x_get_y'); ?>>買N送N (指定範圍內最便宜M件免費)</option>
                        <option value="tiered_cart" <?php selected($type, 'tiered_cart'); ?>>階梯式訂單折扣 (多門檻)</option>
                    </select>
                </div>
                <div style="flex:1; min-width:150px;"><label style="<?php echo $label_style; ?>">套用對象</label><select name="role" style="width:100%;"><option value="all" <?php selected($role, 'all'); ?>>所有顧客</option><?php foreach($tiers as $tier): ?><option value="<?php echo esc_attr($tier['slug']); ?>" <?php selected($role, $tier['slug']); ?>><?php echo esc_html($tier['name']); ?></option><?php endforeach; ?></select></div>
            </div>

            <div style="display:flex; flex-wrap:wrap; gap:15px; margin-bottom:15px;">
                <div class="rule-value-wrap" style="flex:1; min-width:180px; max-width:320px;">
                    <label class="rule-value-label" style="<?php echo $label_style; ?>">折扣數值</label>
                    <input type="number" step="1" name="value" class="twshop-rule-value" value="<?php echo esc_attr( $val ); ?>" style="width:100%;" />
                    <p class="twshop-rule-value-hint"></p>
                </div>
                <div class="rule-gift-wrap" style="flex:1; min-width:220px; display:none;">
                    <label style="<?php echo $label_style; ?>">指定商品 (贈品/加購品)</label>
                    <?php // 排除可變商品：free_gift 型別是直接 WC()->cart->add_to_cart( $id, 1, 0, ... )
                    // （不含 variation_id）自動加入購物車，選到可變商品的父商品會讓贈品必定加不進去
                    // （核心要求可變商品一定要指定規格），且這段是掛在 woocommerce_before_calculate_totals，
                    // 失敗時完全沒有任何錯誤訊息浮現，管理員很難發現。addon_product 型別雖然不是主動
                    // 加入購物車，但同一個欄位語意是「指定商品」，一併排除避免混淆。
                    echo twshop_render_product_search_field( 'gift_product_id', $gift_id ? array( $gift_id ) : array(), false, '— 請選擇商品 —', array( 'variable' ) ); ?>
                </div>
                <div class="rule-bxgy-wrap" style="flex:1; min-width:220px; display:none;">
                    <label style="<?php echo $label_style; ?>">買N送N 數量設定</label>
                    <div style="display:flex; flex-wrap:wrap; gap:10px;">
                        <span style="flex:1; min-width:100px;"><label style="font-size:12px; display:block;">買滿件數 (N)</label><input type="number" step="1" min="1" name="buy_qty" value="<?php echo esc_attr( $buy_qty ); ?>" style="width:100%;" /></span>
                        <span style="flex:1; min-width:150px;"><label style="font-size:12px; display:block;">送出件數 (M，須小於 N)</label><input type="number" step="1" min="1" name="free_qty" value="<?php echo esc_attr( $free_qty ); ?>" style="width:100%;" /></span>
                    </div>
                    <p class="description" style="margin:4px 0 0;">數量以「適用範圍」選的商品/分類/標籤為準（此型別必填），每筆訂單最多套用一次。</p>
                </div>
            </div>

            <div class="rule-tiers-wrap" style="margin-bottom:15px; display:none; background:#f9f9f9; padding:15px; border-radius:4px; border:1px solid #eee;">
                <label style="font-weight:bold; display:block; margin-bottom:8px;">門檻階梯（消費滿多少 → 打折/折抵多少，套用符合的最高門檻）</label>
                <div class="twshop-tiers-rows">
                    <?php foreach ( $rule_tiers as $tier ) echo twshop_get_rule_tier_row_html( $tier ); ?>
                </div>
                <button type="button" class="button twshop-add-tier-row">新增階梯</button>
            </div>

            <div class="rule-shipping-methods-wrap" style="margin-bottom:15px; display:none;">
                <label style="<?php echo $label_style; ?>">適用運送方式 <span style="font-weight:normal; color:#888;">（都不勾 = 全部運送方式皆免運）</span></label>
                <div style="display:flex; flex-wrap:wrap; gap:8px 20px; padding:10px; background:#f9f9f9; border:1px solid #eee; border-radius:4px;">
                    <?php if ( empty( $shipping_method_options ) ) : ?>
                        <span style="color:#999; font-size:13px;">尚未設定任何運送方式（請先至 WooCommerce → 設定 → 運送 建立運送區域與方式）</span>
                    <?php else : foreach ( $shipping_method_options as $sm_key => $sm_label ) : ?>
                        <label style="font-weight:normal; font-size:13px;">
                            <input type="checkbox" name="shipping_methods[]" value="<?php echo esc_attr( $sm_key ); ?>" <?php checked( in_array( $sm_key, $shipping_methods, true ) ); ?> />
                            <?php echo esc_html( $sm_label ); ?>
                        </label>
                    <?php endforeach; endif; ?>
                </div>
            </div>


            <div class="rule-scope-section">
                <h4 class="twshop-rule-section-title">2. 適用範圍與門檻</h4>
                <div class="rule-condition-block" style="background:#f9f9f9; padding:15px; border-radius:4px; margin-bottom:20px; border: 1px solid #eee;">
                    <p class="twshop-rule-hint rule-condition-hint" style="margin:0 0 10px;"></p>
                    <div class="twshop-condition-scope" style="display:flex; flex-wrap:wrap; gap:15px;">
                        <div style="flex:1; min-width:150px;">
                            <label style="<?php echo $sub_label; ?>">適用範圍</label>
                            <select name="condition_type" class="twshop-condition-type" style="width:100%;">
                                <option value="">不限商品</option>
                                <option value="product" <?php selected($cond_type, 'product'); ?>>指定商品</option>
                                <option value="category" <?php selected($cond_type, 'category'); ?>>指定商品分類</option>
                                <option value="tag" <?php selected($cond_type, 'tag'); ?>>指定商品標籤</option>
                            </select>
                        </div>
                        <div class="condition-values-wrap condition-values-product" style="flex:2; min-width:220px; display:none;">
                            <label style="<?php echo $sub_label; ?>">選擇商品 <span style="font-weight:normal; color:#888;">(可複選)</span></label>
                            <?php echo twshop_render_product_search_field( 'condition_values_product', $cond_products, true, '搜尋商品名稱或商品編號…' ); ?>
                        </div>
                        <div class="condition-values-wrap condition-values-category" style="flex:2; min-width:220px; display:none;">
                            <label style="<?php echo $sub_label; ?>">選擇商品分類 <span style="font-weight:normal; color:#888;">(可複選)</span></label>
                            <?php echo twshop_render_chip_field( 'condition_values_category', $cond_cats, $cat_options ); ?>
                        </div>
                        <div class="condition-values-wrap condition-values-tag" style="flex:2; min-width:220px; display:none;">
                            <label style="<?php echo $sub_label; ?>">選擇商品標籤 <span style="font-weight:normal; color:#888;">(可複選)</span></label>
                            <?php echo twshop_render_chip_field( 'condition_values_tag', $cond_tags, $tag_options ); ?>
                        </div>
                        <div class="rule-min-amount-wrap" style="flex:1; min-width:150px;"><label style="<?php echo $sub_label; ?>">訂單小計滿 ($) <span style="font-weight:normal; color:#888;">(留空不限)</span></label><input type="number" step="1" min="0" name="min_amount" class="twshop-rule-min-amount" value="<?php echo esc_attr( $min ); ?>" style="width:100%;" /></div>
                    </div>
                    <div class="twshop-rule-logic-wrap" style="margin-top:12px; font-size:13px;">
                        <span style="margin-right:10px;">範圍與滿額要：</span>
                        <label style="font-weight:normal; margin-right:15px; white-space:nowrap;"><input type="radio" name="logic" value="and" <?php checked($logic, 'and'); ?>> 兩者都符合</label>
                        <label style="font-weight:normal; white-space:nowrap;"><input type="radio" name="logic" value="or" <?php checked($logic, 'or'); ?>> 符合其中一個即可</label>
                    </div>
                </div>
            </div>

            <h4 class="twshop-rule-section-title">3. 使用次數</h4>
            <div style="display:flex; flex-wrap:wrap; gap:15px; background:#f9f9f9; padding:15px; border-radius:4px; margin-bottom:20px; border:1px solid #eee;">
                <div style="flex:1; min-width:150px; max-width:320px;"><label style="<?php echo $sub_label; ?>">總共可使用次數 <span style="font-weight:normal; color:#888;">(留空不限)</span></label><input type="number" min="0" name="usage_limit" value="<?php echo esc_attr( $limit ); ?>" style="width:100%;" /></div>
                <div style="flex:1; min-width:150px; max-width:320px;"><label style="<?php echo $sub_label; ?>">每位會員限用次數 <span style="font-weight:normal; color:#888;">(留空不限)</span></label><input type="number" min="0" name="user_limit" value="<?php echo esc_attr( $u_limit ); ?>" style="width:100%;" /></div>
            </div>

            <h4 class="twshop-rule-section-title">4. 顯示方式</h4>
            <div style="background:#eaf5fa; padding:15px; border-radius:4px; margin-bottom:15px; border: 1px solid #b8e0f5;">
                <label style="font-weight:normal;"><input type="checkbox" name="is_coupon" value="yes" class="twshop-coupon-toggle" <?php checked($is_c, 'yes'); ?>> 作為優惠卡券供會員點擊套用 <span style="color:#888;">(不勾 = 符合條件時自動套用)</span></label>
                <div class="virtual-coupon-wrap" style="display:<?php echo $is_c==='yes'?'block':'none'; ?>; margin-top:15px; padding-top:15px; border-top:1px dashed #007cba;">
                    <div style="margin-bottom:10px;"><label><input type="checkbox" name="c_exclusive" value="yes" <?php checked($c_exclusive, 'yes'); ?>> <strong>單獨使用</strong> (勾選後，此券不可與其他優惠券同時套用)</label></div>
                    <?php
                    $auto_c_title = ( $type && $val !== '' ) ? wp_strip_all_tags( twshop_format_rule_discount( $type, $val, $r ) ) : '';
                    $auto_c_desc  = twshop_build_rule_coupon_restrictions( $r );
                    ?>
                    <div style="display:flex; flex-wrap:wrap; gap:15px;">
                        <div style="flex:1; min-width:150px;"><label style="<?php echo $sub_label; ?>">領取用代碼 <span style="font-weight:normal; color:#888;">(英文、數字、- 或 _)</span></label><input type="text" name="c_code" value="<?php echo esc_attr( $c_code ); ?>" pattern="[A-Za-z0-9_\-]+" title="只能使用英文、數字、- 或 _" style="width:100%;" /></div>
                        <div style="flex:1; min-width:150px;"><label style="<?php echo $sub_label; ?>">優惠券卡片標題 <span style="font-weight:normal; color:#888;">(留空自動產生)</span></label><input type="text" name="c_title" value="<?php echo esc_attr( $c_title ); ?>" placeholder="<?php echo esc_attr( $auto_c_title ?: '依折扣自動產生' ); ?>" style="width:100%;" /></div>
                        <div style="flex:2; min-width:200px;"><label style="<?php echo $sub_label; ?>">卡片說明 <span style="font-weight:normal; color:#888;">(留空自動產生)</span></label><input type="text" name="c_desc" value="<?php echo esc_attr( $c_desc ); ?>" placeholder="<?php echo esc_attr( $auto_c_desc ?: '依限制條件自動產生' ); ?>" style="width:100%;" /></div>
                    </div>
                </div>
            </div>

            <div class="twshop-rule-footer" style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; padding-top:15px; border-top:1px solid #eee;">
                <span style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="button" class="button twshop-duplicate-rule" <?php disabled( '' === $r_id ); ?> title="<?php echo '' === $r_id ? '請先儲存規則' : '複製一份（預設停用）'; ?>">複製規則</button>
                    <button type="button" class="button remove-rule-row" style="color:#b32d2e; border-color:#b32d2e;">刪除規則</button>
                </span>
                <span style="display:flex; gap:10px 14px; align-items:center; flex-wrap:wrap;">
                    <span class="twshop-rule-status twshop-rule-footer-status" aria-live="polite"></span>
                    <label class="rule-stack-wrap" style="font-weight:normal;" title="勾選後，排序在這條規則後面的同類折扣規則不會再套用">
                        <input type="checkbox" name="stack_exclusive" value="yes" <?php checked( $stack_exclusive, 'yes' ); ?> /> 不與同類折扣疊加
                    </label>
                    <button type="submit" class="button button-primary save-rule-btn">儲存規則</button>
                </span>
            </div>
        </div>
    </form>
    <?php return ob_get_clean();
}

/**
 * 階梯式折扣的單一門檻列。PHP 渲染既有列與 JS「新增階梯」共用同一份 HTML（JS 從
 * 頁面上的範本讀取），避免兩邊各寫一份、欄位或樣式漂移。
 */
function twshop_get_rule_tier_row_html( $tier = array() ) {
    ob_start();
    ?>
    <div class="twshop-tier-row" style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-start; margin-bottom:8px;">
        <span style="flex:1; min-width:110px;"><label style="font-size:12px; display:block;">消費滿 ($)</label><input type="number" step="1" min="0" name="tiers_min[]" value="<?php echo esc_attr( $tier['min_amount'] ?? '' ); ?>" style="width:100%;" /></span>
        <span style="flex:1; min-width:110px;"><label style="font-size:12px; display:block;">折扣類型</label><select name="tiers_type[]" class="twshop-tier-type" style="width:100%;"><option value="percent" <?php selected( $tier['discount_type'] ?? '', 'percent' ); ?>>打折 (%)</option><option value="fixed" <?php selected( $tier['discount_type'] ?? '', 'fixed' ); ?>>折抵 ($)</option></select></span>
        <span style="flex:1; min-width:140px;"><label style="font-size:12px; display:block;">數值</label><input type="number" step="1" min="0" name="tiers_value[]" class="twshop-tier-value" value="<?php echo esc_attr( $tier['value'] ?? '' ); ?>" style="width:100%;" /><span class="twshop-rule-value-hint twshop-tier-hint"></span></span>
        <button type="button" class="button twshop-remove-tier-row" style="color:#b32d2e; border-color:#b32d2e; margin-top:17px;">移除</button>
    </div>
    <?php
    return ob_get_clean();
}

function twshop_ajax_save_rule() {
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error();
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );
    $rule_id = sanitize_text_field( wp_unslash( $_POST['rule_id'] ?? '' ) );
    if(empty($rule_id)) $rule_id = uniqid('rule_');

    $condition_type = sanitize_text_field( wp_unslash( $_POST['condition_type'] ?? '' ) );
    if ( ! in_array( $condition_type, array( 'product', 'category', 'tag' ), true ) ) $condition_type = '';
    if ( $condition_type === 'product' ) {
        $condition_values = array_map( 'absint', (array) ( $_POST['condition_values_product'] ?? array() ) );
    } elseif ( $condition_type === 'category' ) {
        $condition_values = twshop_sanitize_term_slugs( wp_unslash( (array) ( $_POST['condition_values_category'] ?? array() ) ), 'product_cat' );
    } elseif ( $condition_type === 'tag' ) {
        $condition_values = twshop_sanitize_term_slugs( wp_unslash( (array) ( $_POST['condition_values_tag'] ?? array() ) ), 'product_tag' );
    } else {
        $condition_values = array();
    }
    $condition_values = array_values( array_filter( $condition_values ) );
    if ( empty( $condition_values ) ) $condition_type = '';

    $shipping_methods = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['shipping_methods'] ?? array() ) ) );
    $shipping_methods = array_values( array_filter( $shipping_methods ) );

    // 階梯式訂單折扣（tiered_cart）：三個平行陣列（同 index 對應同一組門檻）組回結構化陣列。
    $tiers_min = (array) ( $_POST['tiers_min'] ?? array() );
    $tiers_type = (array) ( $_POST['tiers_type'] ?? array() );
    $tiers_value = (array) ( $_POST['tiers_value'] ?? array() );
    $tiers = array();
    foreach ( $tiers_min as $i => $tier_min ) {
        $discount_type = in_array( $tiers_type[ $i ] ?? '', array( 'percent', 'fixed' ), true ) ? $tiers_type[ $i ] : 'fixed';
        $tiers[] = array(
            'min_amount'    => floatval( $tier_min ),
            'discount_type' => $discount_type,
            'value'         => floatval( $tiers_value[ $i ] ?? 0 ),
        );
    }

    $new_rule = array(
        'rule_id'           => $rule_id,
        'name'              => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
        'role'              => sanitize_text_field( wp_unslash( $_POST['role'] ?? '' ) ),
        'type'              => sanitize_text_field( wp_unslash( $_POST['type'] ?? '' ) ),
        'value'             => floatval( wp_unslash( $_POST['value'] ?? 0 ) ),
        'gift_product_id'   => absint( wp_unslash( $_POST['gift_product_id'] ?? 0 ) ),
        'shipping_methods'  => $shipping_methods,
        'logic'             => sanitize_text_field( wp_unslash( $_POST['logic'] ?? '' ) ),
        'condition_type'    => $condition_type,
        'condition_values'  => $condition_values,
        'min_amount'        => floatval( wp_unslash( $_POST['min_amount'] ?? 0 ) ),
        'usage_limit'       => absint( wp_unslash( $_POST['usage_limit'] ?? 0 ) ),
        'user_limit'        => absint( wp_unslash( $_POST['user_limit'] ?? 0 ) ),
        'start_time'        => sanitize_text_field( wp_unslash( $_POST['start_time'] ?? '' ) ),
        'end_time'          => sanitize_text_field( wp_unslash( $_POST['end_time'] ?? '' ) ),
        'is_coupon'         => sanitize_text_field( wp_unslash( $_POST['is_coupon'] ?? 'no' ) ),
        'c_code'            => sanitize_text_field( wp_unslash( $_POST['c_code'] ?? '' ) ),
        'c_title'           => sanitize_text_field( wp_unslash( $_POST['c_title'] ?? '' ) ),
        'c_desc'            => sanitize_text_field( wp_unslash( $_POST['c_desc'] ?? '' ) ),
        'c_exclusive'       => sanitize_text_field( wp_unslash( $_POST['c_exclusive'] ?? 'no' ) ),
        'enabled'           => sanitize_text_field( wp_unslash( $_POST['enabled'] ?? 'no' ) ),
        'stack_exclusive'   => sanitize_text_field( wp_unslash( $_POST['stack_exclusive'] ?? 'no' ) ),
        'buy_qty'           => absint($_POST['buy_qty'] ?? 0),
        'free_qty'          => absint($_POST['free_qty'] ?? 0),
        'tiers'             => $tiers,
    );

    $rules = twshop_get_rules();

    if ( 'yes' === $new_rule['is_coupon'] && '' !== $new_rule['c_code'] && ! preg_match( '/^[A-Za-z0-9_-]+$/', $new_rule['c_code'] ) ) {
        wp_send_json_error( array( 'msg' => '領取用代碼只能使用英文、數字、- 或 _。' ) );
    }

    // 優惠券代碼撞名檢查：套用優惠券時 (twshop_apply_visual_coupon()) 是逐筆規則比對、
    // 找到第一筆符合的就 break，重複代碼會讓後面那筆規則永遠套用不到卻不會有任何警告，
    // 所以在儲存當下就擋下來，而不是留到顧客套用時才發現規則悄悄失效。
    if ( 'yes' === $new_rule['is_coupon'] && '' !== $new_rule['c_code'] ) {
        foreach ( $rules as $r ) {
            if ( $r['rule_id'] === $rule_id ) continue; // 排除自己（更新既有規則的情況）
            if ( ! empty( $r['is_coupon'] ) && $r['is_coupon'] === 'yes'
                && isset( $r['c_code'] ) && strtolower( $r['c_code'] ) === strtolower( $new_rule['c_code'] ) ) {
                wp_send_json_error( array( 'msg' => '優惠券代碼「' . $new_rule['c_code'] . '」已被其他規則使用，請改用別的代碼。' ) );
            }
        }
        if ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $new_rule['c_code'] ) ) {
            wp_send_json_error( array( 'msg' => '優惠券代碼「' . $new_rule['c_code'] . '」已被既有的 WooCommerce 優惠券使用，請改用別的代碼。' ) );
        }
    }

    // 買N送N：限制條件範圍必填（決定哪些商品的購買數量算進 N），且 M 必須小於 N。
    if ( 'buy_x_get_y' === $new_rule['type'] ) {
        if ( empty( $new_rule['condition_type'] ) || empty( $new_rule['condition_values'] ) ) {
            wp_send_json_error( array( 'msg' => '「買N送N」規則必須在限制條件區塊選擇商品/分類/標籤範圍。' ) );
        }
        if ( $new_rule['buy_qty'] < 1 ) {
            wp_send_json_error( array( 'msg' => '買滿件數 (N) 必須至少為 1。' ) );
        }
        if ( $new_rule['free_qty'] < 1 || $new_rule['free_qty'] >= $new_rule['buy_qty'] ) {
            wp_send_json_error( array( 'msg' => '送出件數 (M) 必須至少為 1，且必須小於買滿件數 (N)。' ) );
        }
    }

    // 階梯式訂單折扣：至少一組門檻，且每組門檻與數值都必須大於 0。
    if ( 'tiered_cart' === $new_rule['type'] ) {
        if ( empty( $new_rule['tiers'] ) ) {
            wp_send_json_error( array( 'msg' => '「階梯式訂單折扣」規則至少需要一組門檻。' ) );
        }
        foreach ( $new_rule['tiers'] as $tier ) {
            if ( $tier['min_amount'] <= 0 || $tier['value'] <= 0 ) {
                wp_send_json_error( array( 'msg' => '每組門檻的「消費滿」與「數值」都必須大於 0。' ) );
            }
        }
    }

    $updated = false;
    foreach($rules as $k => $r) {
        if($r['rule_id'] === $rule_id) { $rules[$k] = $new_rule; $updated = true; break; }
    }
    if(!$updated) $rules[] = $new_rule;
    update_option('wc_discount_rules_settings', $rules);
    twshop_get_rules( true );
    wp_send_json_success(['rule_id' => $rule_id, 'msg' => '儲存成功']);
}

/**
 * 複製一條已儲存的規則：新 rule_id、名稱加「（複本）」、預設停用、清空優惠券代碼（代碼不可重複），
 * 插在原規則後面，回傳新卡片 HTML 供前端直接插入。
 */
function twshop_ajax_duplicate_rule() {
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error();
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );
    $rule_id = sanitize_text_field( wp_unslash( $_POST['rule_id'] ?? '' ) );
    $rules   = twshop_get_rules();

    $new_rules = array();
    $copy      = null;
    foreach ( $rules as $r ) {
        $new_rules[] = $r;
        if ( null === $copy && $r['rule_id'] === $rule_id ) {
            $copy = $r;
            $copy['rule_id'] = uniqid( 'rule_' );
            $copy['name']    = ( $r['name'] ?? '' ) . '（複本）';
            $copy['enabled'] = 'no';
            $copy['c_code']  = '';
            $new_rules[]     = $copy;
        }
    }
    if ( null === $copy ) wp_send_json_error( array( 'msg' => '找不到要複製的規則，請重新整理頁面。' ) );

    update_option( 'wc_discount_rules_settings', $new_rules );
    twshop_get_rules( true );

    $product_cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
    $product_tags = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
    wp_send_json_success( array(
        'rule_id' => $copy['rule_id'],
        'html'    => twshop_get_rule_row_html(
            $copy,
            get_option( 'wc_member_tiers_settings', array() ),
            is_wp_error( $product_cats ) ? array() : $product_cats,
            is_wp_error( $product_tags ) ? array() : $product_tags
        ),
    ) );
}

function twshop_ajax_delete_rule() {
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error();
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );
    $rule_id = sanitize_text_field( wp_unslash( $_POST['rule_id'] ?? '' ) );
    $rules = twshop_get_rules();
    foreach($rules as $k => $r) { if($r['rule_id'] === $rule_id) unset($rules[$k]); }
    update_option('wc_discount_rules_settings', array_values($rules));
    twshop_get_rules( true );

    // 規則本身之外，還有兩筆用 rule_id 動態組 key 的使用次數統計，不會因為上面 update_option()
    // 而一併消失，須手動清掉，否則刪除後仍留著孤兒資料：
    // (1) 全站累計使用次數（option）(2) 每位會員的個人使用次數（user meta，跨所有會員）
    if ( '' !== $rule_id ) {
        twshop_delete_rule_usage_total( $rule_id );
        delete_metadata( 'user', 0, 'twshop_rule_usage_' . $rule_id, '', true );
    }

    wp_send_json_success();
}

/**
 * 批次啟用/停用/刪除多筆規則，以及卡片標題列啟用切換鈕（enable/disable）與
 * 開始/結束時間（schedule）的立即存檔。
 * 刪除時比照 twshop_ajax_delete_rule()，一併清理該規則的使用次數 option／user meta，
 * 避免又留下孤兒資料（兩處刪除邏輯刻意保持一致）。
 */
function twshop_ajax_batch_update_rules() {
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error();
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );

    $action_type = sanitize_text_field( wp_unslash( $_POST['action_type'] ?? '' ) );
    if ( ! in_array( $action_type, array( 'enable', 'disable', 'delete', 'schedule' ), true ) ) {
        wp_send_json_error( array( 'msg' => '不明的批次操作。' ) );
    }
    $rule_ids = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['rule_ids'] ?? array() ) ) );
    if ( empty( $rule_ids ) ) wp_send_json_error( array( 'msg' => '未選取任何規則。' ) );

    $rules = twshop_get_rules();

    if ( 'schedule' === $action_type ) {
        $start = sanitize_text_field( wp_unslash( $_POST['start_time'] ?? '' ) );
        $end   = sanitize_text_field( wp_unslash( $_POST['end_time'] ?? '' ) );
        foreach ( array( $start, $end ) as $time ) {
            if ( '' !== $time && false === strtotime( $time ) ) wp_send_json_error( array( 'msg' => '時間格式不正確。' ) );
        }
        if ( '' !== $start && '' !== $end && strtotime( $end ) <= strtotime( $start ) ) {
            wp_send_json_error( array( 'msg' => '結束時間必須晚於開始時間。' ) );
        }
        foreach ( $rules as $k => $r ) {
            if ( in_array( $r['rule_id'], $rule_ids, true ) ) {
                $rules[ $k ]['start_time'] = $start;
                $rules[ $k ]['end_time']   = $end;
            }
        }
        update_option( 'wc_discount_rules_settings', $rules );
        twshop_get_rules( true );
        wp_send_json_success();
    }

    if ( 'delete' === $action_type ) {
        foreach ( $rules as $k => $r ) {
            if ( in_array( $r['rule_id'], $rule_ids, true ) ) {
                twshop_delete_rule_usage_total( $r['rule_id'] );
                delete_metadata( 'user', 0, 'twshop_rule_usage_' . $r['rule_id'], '', true );
                unset( $rules[ $k ] );
            }
        }
        update_option( 'wc_discount_rules_settings', array_values( $rules ) );
    } else {
        $new_enabled = ( 'enable' === $action_type ) ? 'yes' : 'no';
        foreach ( $rules as $k => $r ) {
            if ( in_array( $r['rule_id'], $rule_ids, true ) ) {
                $rules[ $k ]['enabled'] = $new_enabled;
            }
        }
        update_option( 'wc_discount_rules_settings', $rules );
    }

    twshop_get_rules( true );
    wp_send_json_success();
}

function twshop_ajax_reorder_rules() {
    if ( ! current_user_can('manage_woocommerce') ) wp_send_json_error();
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );
    $order = isset($_POST['order']) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['order'] ) ) : array();
    $rules = twshop_get_rules();
    $new_rules = array();
    foreach ( $order as $id ) {
        foreach ( $rules as $r ) {
            if ( $r['rule_id'] === $id ) {
                $new_rules[] = $r;
                break;
            }
        }
    }
    update_option('wc_discount_rules_settings', $new_rules);
    twshop_get_rules( true );
    wp_send_json_success();
}

