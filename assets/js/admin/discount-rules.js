/**
 * 折扣與贈品管理頁：規則卡片的新增/儲存/刪除/批次操作/拖曳排序（全部走 admin-ajax）。
 *
 * 自 page-discount-rules.php 的內嵌 <script> 抽出（2026-08-21）。
 */
var twshopAdminNonce = twshopDiscountRules.nonce;
jQuery(document).ready(function($) {
    function initUI() {
        $('.twshop-datetime-picker').flatpickr({ enableTime: true, time_24hr: true, dateFormat: "Y-m-d H:i" });

        // 讓「新增規則表單」複製出來的 wc-product-search 欄位（指定商品／限制條件-商品）
        // 也套用 selectWoo：這個事件只在頁面載入當下自動觸發一次，動態插入的新 <select>
        // 不會自動被接管。wc-enhanced-select.js 內部用 :not(.enhanced) 過濾已初始化過的
        // 元素，重複觸發對既有欄位無副作用。
        $(document.body).trigger('wc-enhanced-select-init');

        $('.twshop-rule-type').off('change').on('change', function(){
            var type = $(this).val();
            var $card = $(this).closest('.twshop-rule-card');
            var valField = $card.find('.rule-value-wrap');
            var giftField = $card.find('.rule-gift-wrap');
            var shippingField = $card.find('.rule-shipping-methods-wrap');
            var bxgyField = $card.find('.rule-bxgy-wrap');
            var tiersField = $card.find('.rule-tiers-wrap');
            var conditionBlock = $card.find('.rule-condition-block');
            var minAmountField = $card.find('.rule-min-amount-wrap');
            var conditionTitle = $card.find('.rule-condition-title');

            valField.hide(); giftField.hide(); shippingField.hide(); bxgyField.hide(); tiersField.hide();
            conditionBlock.show(); minAmountField.show();
            conditionTitle.text('限制條件區塊 (選填)');

            if(type === 'free_shipping') {
                shippingField.show();
            } else if (type === 'free_gift') {
                giftField.show();
            } else if (type === 'addon_product') {
                valField.show(); giftField.show();
                valField.find('label').text('加購特價金額 ($)');
            } else if (type === 'buy_x_get_y') {
                bxgyField.show();
                conditionTitle.text('限制條件區塊（此型別必填：決定哪些商品的購買數量算進 N）');
            } else if (type === 'tiered_cart') {
                tiersField.show();
                conditionBlock.hide(); // min_amount/condition_type 對這個型別無意義，改用下方 tiers 各自的門檻
            } else {
                valField.show();
                valField.find('label').text('折扣數值');
            }
        }).trigger('change');

        $('.twshop-coupon-toggle').off('change').on('change', function(){
            var cWrap = $(this).closest('.twshop-rule-card').find('.virtual-coupon-wrap');
            if($(this).is(':checked')) cWrap.slideDown(); else cWrap.slideUp();
        });

        $('.twshop-rule-enabled-toggle').off('change').on('change', function(){
            var $card = $(this).closest('.twshop-rule-card');
            var isEnabled = $(this).is(':checked');
            $card.toggleClass('twshop-rule-disabled', !isEnabled);
            $card.find('.twshop-rule-disabled-badge').toggle(!isEnabled);
            $card.attr('data-rule-enabled', isEnabled ? 'yes' : 'no');
        });
        // 限制條件類型的顯示/隱藏切換由共用元件 (twshop_render_chip_field_assets) 的委派事件處理，這裡不需重複綁定
    }
    initUI();

    $('#discount-repeater-container').sortable({
        handle: '.drag-handle',
        axis: 'y',
        opacity: 0.8,
        update: function(event, ui) {
            let order = [];
            $('.twshop-rule-form').each(function() {
                let id = $(this).find('input[name="rule_id"]').val();
                if (id) order.push(id);
            });
            if(order.length > 0) {
                $.post(twshopDiscountRules.ajaxUrl, { action: 'twshop_reorder_rules', order: order, twshop_nonce: twshopAdminNonce });
            }
        }
    });

    $('#add-rule-row').on('click', function() {
        var templateHtml = document.getElementById('discount-rule-template').innerHTML;
        $('#discount-repeater-container').append(templateHtml);
        var $newRow = $('#discount-repeater-container').children('.twshop-rule-form').last();
        initUI();
        $newRow.find('.twshop-card-body').show();
        $newRow.find('.twshop-card-toggle-icon').addClass('is-open');
    });

    $(document).on('click', '.twshop-card-header', function(e) {
        if($(e.target).is('input, button, select, span.drag-handle')) return;
        $(this).next('.twshop-card-body').slideToggle();
        $(this).find('.twshop-card-toggle-icon').toggleClass('is-open');
    });

    $(document).on('submit', '.twshop-rule-form', function(e) {
        e.preventDefault();
        let $form = $(this), $btn = $form.find('.save-rule-btn');
        $btn.text('儲存中').prop('disabled', true);
        $.post(twshopDiscountRules.ajaxUrl, $form.serialize() + '&action=twshop_save_rule', function(res){
            $btn.text('儲存').prop('disabled', false);
            if(res.success) {
                $form.find('input[name="rule_id"]').val(res.data.rule_id);
                let name = $form.find('input[name="name"]').val();
                let type = $form.find('select[name="type"]').val();
                $form.find('.rule-title-display').text((name ? name : '新規則'));
                $form.attr('data-rule-name', name).attr('data-rule-type', type);
                alert(res.data.msg);
            } else alert((res.data && res.data.msg) || '儲存失敗');
        });
    });

    $(document).on('click', '.twshop-add-tier-row', function() {
        let $wrap = $(this).closest('.rule-tiers-wrap').find('.twshop-tiers-rows');
        $wrap.append(
            '<div class="twshop-tier-row" style="display:flex; gap:10px; align-items:center; margin-bottom:8px;">' +
            '<span style="flex:1;"><label style="font-size:12px; display:block;">消費滿 ($)</label><input type="number" step="1" name="tiers_min[]" style="width:100%;" /></span>' +
            '<span style="flex:1;"><label style="font-size:12px; display:block;">折扣類型</label><select name="tiers_type[]" style="width:100%;"><option value="percent">打折 (%)</option><option value="fixed">折抵 ($)</option></select></span>' +
            '<span style="flex:1;"><label style="font-size:12px; display:block;">數值</label><input type="number" step="1" name="tiers_value[]" style="width:100%;" /></span>' +
            '<button type="button" class="button twshop-remove-tier-row" style="color:#b32d2e; border-color:#b32d2e;">移除</button>' +
            '</div>'
        );
    });
    $(document).on('click', '.twshop-remove-tier-row', function() {
        $(this).closest('.twshop-tier-row').remove();
    });

    // 規則列表搜尋/篩選：純前端比對，規則本來就整頁一次全部渲染，不需要 AJAX
    function applyRuleFilters() {
        let keyword = $('#twshop-rule-search').val().toLowerCase().trim();
        let typeFilter = $('#twshop-rule-filter-type').val();
        let statusFilter = $('#twshop-rule-filter-status').val();
        $('#discount-repeater-container .twshop-rule-form').each(function() {
            let $card = $(this);
            let matches = true;
            if (keyword && (($card.attr('data-rule-name') || '').toLowerCase().indexOf(keyword) === -1)) matches = false;
            if (typeFilter && $card.attr('data-rule-type') !== typeFilter) matches = false;
            if (statusFilter && $card.attr('data-rule-enabled') !== statusFilter) matches = false;
            $card.toggle(matches);
        });
    }
    $('#twshop-rule-search').on('input', applyRuleFilters);
    $('#twshop-rule-filter-type, #twshop-rule-filter-status').on('change', applyRuleFilters);

    // 批次操作：啟用/停用/刪除
    $('#twshop-rule-select-all').on('change', function() {
        let checked = $(this).is(':checked');
        $('#discount-repeater-container .twshop-rule-form:visible .twshop-rule-select').prop('checked', checked);
    });

    function getSelectedRuleIds() {
        let ids = [];
        $('#discount-repeater-container .twshop-rule-form').each(function() {
            if ($(this).find('.twshop-rule-select').is(':checked')) {
                let id = $(this).find('input[name="rule_id"]').val();
                if (id) ids.push(id);
            }
        });
        return ids;
    }

    function runBatchAction(actionType, confirmMsg) {
        let ids = getSelectedRuleIds();
        if (!ids.length) { alert('請先選取要操作的規則。'); return; }
        if (confirmMsg && !confirm(confirmMsg)) return;
        $.post(twshopDiscountRules.ajaxUrl, { action: 'twshop_batch_update_rules', action_type: actionType, rule_ids: ids, twshop_nonce: twshopAdminNonce }, function(res) {
            if (!res.success) { alert((res.data && res.data.msg) || '批次操作失敗'); return; }
            ids.forEach(function(id) {
                let $card = $('#discount-repeater-container .twshop-rule-form').filter(function() {
                    return $(this).find('input[name="rule_id"]').val() === id;
                });
                if (actionType === 'delete') {
                    $card.remove();
                } else {
                    let isEnabled = actionType === 'enable';
                    $card.find('.twshop-rule-enabled-toggle').prop('checked', isEnabled).trigger('change');
                }
            });
        });
    }
    $('#twshop-batch-enable').on('click', function(){ runBatchAction('enable', false); });
    $('#twshop-batch-disable').on('click', function(){ runBatchAction('disable', false); });
    $('#twshop-batch-delete').on('click', function(){ runBatchAction('delete', '確定要刪除選取的規則嗎？此操作無法復原。'); });

    $(document).on('click', '.twshop-clear-datetime', function(e) {
        e.preventDefault();
        $(this).closest('.twshop-rule-form').find('.twshop-datetime-picker').each(function() {
            if (this._flatpickr) this._flatpickr.clear();
        });
    });

    $(document).on('click', '.remove-rule-row', function() {
        if(!confirm('確定要刪除這筆規則嗎')) return;
        let $form = $(this).closest('.twshop-rule-form');
        let rule_id = $form.find('input[name="rule_id"]').val();
        if(!rule_id) { $form.remove(); return; }
        let deleteNonce = $form.find('input[name="twshop_nonce"]').val() || twshopAdminNonce;
        $.post(twshopDiscountRules.ajaxUrl, { action: 'twshop_delete_rule', rule_id: rule_id, twshop_nonce: deleteNonce }, function(res){
            if(res.success) $form.remove();
        });
    });
});
