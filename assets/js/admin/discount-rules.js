/**
 * 折扣與贈品管理頁：規則卡片的新增/複製/儲存/刪除/拖曳排序（全部走 admin-ajax）。
 *
 * 自 page-discount-rules.php 的內嵌 <script> 抽出（2026-08-21）；v25.8.38 改為就地顯示狀態文字
 * （不再跳 alert）、未儲存提示、複製規則、依型別顯示數值單位與即時說明。
 */
var twshopAdminNonce = twshopDiscountRules.nonce;
jQuery(document).ready(function($) {
    var $container = $('#discount-repeater-container');
    var STACKABLE_TYPES = ['percent', 'fixed_product', 'cart_percent', 'cart_discount', 'tiered_cart'];
    var PRODUCT_LEVEL_TYPES = ['percent', 'fixed_product'];

    // 初始化（含 selectWoo／條件類型的程式觸發 change）期間不算使用者修改
    var dirtyTrackingOn = false;

    // ── 狀態文字 ─────────────────────────────────────────────
    function showStatus($el, type, msg) {
        clearTimeout($el.data('twshopStatusTimer'));
        $el.removeClass('is-success is-error').addClass(type === 'error' ? 'is-error' : 'is-success').text(msg).show();
        if (type !== 'error') {
            $el.data('twshopStatusTimer', setTimeout(function(){ $el.fadeOut(); }, 6000));
        }
    }
    function showCardStatus($card, type, msg) { showStatus($card.find('.twshop-card-header .twshop-rule-status'), type, msg); }
    // 儲存按鈕在卡片最下方，儲存結果顯示在按鈕旁邊（卡片收合時看不到，所以另外同步到標題列）
    function showSaveStatus($card, type, msg) {
        showStatus($card.find('.twshop-rule-footer-status'), type, msg);
        showCardStatus($card, type, msg);
    }
    function showToolbarStatus(type, msg) { showStatus($('#twshop-rule-toolbar-status'), type, msg); }
    function ajaxErrorMsg(res, fallback) { return (res && res.data && res.data.msg) || fallback; }

    // ── 未儲存標記 ───────────────────────────────────────────
    function setDirty($card, dirty) {
        $card.toggleClass('is-dirty', dirty);
        $card.find('.twshop-rule-dirty-badge').toggle(dirty);
    }
    $container.on('input change', '.twshop-rule-form :input', function() {
        if (!dirtyTrackingOn) return;
        // 已儲存規則的標題列切換鈕會立刻存檔，不算未儲存的修改
        if ($(this).closest('.twshop-card-header-controls').length && $(this).closest('.twshop-rule-form').find('input[name="rule_id"]').val()) return;
        setDirty($(this).closest('.twshop-rule-form'), true);
    });
    $container.on('click', '.twshop-chip-remove, .twshop-add-tier-row, .twshop-remove-tier-row', function() {
        if (dirtyTrackingOn) setDirty($(this).closest('.twshop-rule-form'), true);
    });
    window.addEventListener('beforeunload', function(e) {
        if (!$container.children('.twshop-rule-form.is-dirty').length) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // ── 數值欄單位與即時說明 ─────────────────────────────────
    function percentHint(raw) {
        if (raw === '' || isNaN(parseFloat(raw))) return { text: '填顧客實付的比例，例如 90 = 打 9 折', warn: false };
        var v = parseFloat(raw);
        if (v >= 100) return { text: '= 沒有折扣（顧客付全額）', warn: true };
        if (v <= 0) return { text: '= 免費（顧客不用付錢）', warn: true };
        var zhe = (v % 10 === 0 || v < 10) ? v / 10 : v;
        return { text: '= 打 ' + zhe + ' 折（顧客付 ' + v + '%，省 ' + Math.round((100 - v) * 100) / 100 + '%）', warn: false };
    }
    function renderHint($hint, hint) {
        $hint.text(hint ? hint.text : '').toggleClass('is-warn', !!(hint && hint.warn)).toggle(!!hint);
    }
    function updateValueHint($card) {
        var type = $card.find('.twshop-rule-type').val();
        var raw = $card.find('.twshop-rule-value').val();
        var $hint = $card.find('.rule-value-wrap .twshop-rule-value-hint');
        if (type === 'percent' || type === 'cart_percent') {
            renderHint($hint, percentHint(raw));
        } else if (type === 'fixed_product') {
            renderHint($hint, { text: '每件符合的商品減去這個金額', warn: false });
        } else if (type === 'cart_discount') {
            renderHint($hint, { text: '整筆訂單減去這個金額', warn: false });
        } else if (type === 'addon_product') {
            renderHint($hint, { text: '符合條件時，顧客可用這個價格加購 1 件', warn: false });
        } else {
            renderHint($hint, null);
        }
    }
    function updateTierHint($row) {
        var isPercent = $row.find('.twshop-tier-type').val() === 'percent';
        renderHint($row.find('.twshop-tier-hint'), isPercent ? percentHint($row.find('.twshop-tier-value').val()) : null);
    }

    var VALUE_LABELS = {
        percent: '顧客實付比例 (%)',
        cart_percent: '顧客實付比例 (%)',
        fixed_product: '每件折抵金額 ($)',
        cart_discount: '整筆訂單折抵金額 ($)',
        addon_product: '加購特價金額 ($)'
    };
    var CONDITION_HINTS = {
        product: '只有符合範圍的商品會打折；有填小計滿額時，購物車小計也要達標。',
        cart: '購物車裡有符合範圍的商品（且小計達標）時，這條規則才成立。',
        buy_x_get_y: '必填：選擇哪些商品的購買數量算進 N。'
    };

    function applyTypeLayout($card) {
        var type = $card.find('.twshop-rule-type').val();
        var $valueWrap = $card.find('.rule-value-wrap');

        $valueWrap.toggle(!!VALUE_LABELS[type]);
        if (VALUE_LABELS[type]) $valueWrap.find('.rule-value-label').text(VALUE_LABELS[type]);
        $card.find('.rule-gift-wrap').toggle(type === 'free_gift' || type === 'addon_product');
        $card.find('.rule-shipping-methods-wrap').toggle(type === 'free_shipping');
        $card.find('.rule-bxgy-wrap').toggle(type === 'buy_x_get_y');
        $card.find('.rule-tiers-wrap').toggle(type === 'tiered_cart');
        $card.find('.rule-stack-wrap').toggle(STACKABLE_TYPES.indexOf(type) !== -1);
        // 階梯式折扣的門檻寫在每一階裡，適用範圍/小計滿額對它沒有意義
        $card.find('.rule-scope-section').toggle(type !== 'tiered_cart');

        var hintKey = type === 'buy_x_get_y' ? 'buy_x_get_y' : (PRODUCT_LEVEL_TYPES.indexOf(type) !== -1 ? 'product' : 'cart');
        $card.find('.rule-condition-hint').text(CONDITION_HINTS[hintKey]);

        updateValueHint($card);
        updateLogicVisibility($card);
    }

    // AND/OR 只在「有選範圍」且「有填小計滿額」兩個條件同時存在時才有意義
    function updateLogicVisibility($card) {
        var hasScope = !!$card.find('.twshop-condition-type').val();
        var hasMin = parseFloat($card.find('.twshop-rule-min-amount').val()) > 0;
        $card.find('.twshop-rule-logic-wrap').toggle(hasScope && hasMin);
    }

    // ── 初始化 ───────────────────────────────────────────────
    function initCards($cards) {
        $cards.find('.twshop-datetime-picker').each(function() {
            if (!this._flatpickr) $(this).flatpickr({ enableTime: true, time_24hr: true, dateFormat: "Y-m-d H:i" });
        });
        // 動態插入的 wc-product-search 欄位也要套用 selectWoo（wc-enhanced-select.js 會略過已初始化的元素）
        $(document.body).trigger('wc-enhanced-select-init');
        $cards.each(function() {
            var $card = $(this);
            applyTypeLayout($card);
            rememberSavedSchedule($card);
            $card.find('.twshop-tier-row').each(function() { updateTierHint($(this)); });
        });
    }

    $container.on('change', '.twshop-rule-type', function() { applyTypeLayout($(this).closest('.twshop-rule-form')); });
    $container.on('input change', '.twshop-rule-value', function() { updateValueHint($(this).closest('.twshop-rule-form')); });
    $container.on('input change', '.twshop-rule-min-amount', function() { updateLogicVisibility($(this).closest('.twshop-rule-form')); });
    $container.on('change', '.twshop-condition-type', function() { updateLogicVisibility($(this).closest('.twshop-rule-form')); });
    $container.on('input change', '.twshop-tier-type, .twshop-tier-value', function() { updateTierHint($(this).closest('.twshop-tier-row')); });

    $container.on('change', '.twshop-coupon-toggle', function() {
        var $wrap = $(this).closest('.twshop-rule-card').find('.virtual-coupon-wrap');
        if ($(this).is(':checked')) $wrap.slideDown(); else $wrap.slideUp();
    });

    function applyEnabledLook($card, isEnabled) {
        $card.toggleClass('twshop-rule-disabled', !isEnabled);
        $card.find('.twshop-switch-text').text(isEnabled ? '啟用' : '停用');
        $card.attr('data-rule-enabled', isEnabled ? 'yes' : 'no');
    }

    // 標題列的切換鈕（啟用、不疊加）：已儲存的規則切換後立刻存檔（只改這一個欄位，不會連帶送出
    // 卡片裡其他未儲存的修改）；還沒存過的新規則沒有 rule_id，維持跟著「儲存規則」一起送出。
    function saveHeaderToggle($toggle, onType, offType, onMsg, offMsg, applyLook) {
        var $card = $toggle.closest('.twshop-rule-card');
        var isOn = $toggle.is(':checked');
        if (applyLook) applyLook($card, isOn);

        var ruleId = $card.find('input[name="rule_id"]').val();
        if (!ruleId || !dirtyTrackingOn) return;

        var revert = function(msg) {
            $toggle.prop('checked', !isOn);
            if (applyLook) applyLook($card, !isOn);
            showCardStatus($card, 'error', msg);
        };
        $toggle.prop('disabled', true);
        $.post(twshopDiscountRules.ajaxUrl, { action: 'twshop_batch_update_rules', action_type: isOn ? onType : offType, rule_ids: [ruleId], twshop_nonce: twshopAdminNonce })
            .done(function(res) {
                if (res && res.success) showCardStatus($card, 'success', isOn ? onMsg : offMsg);
                else revert(ajaxErrorMsg(res, '切換失敗，請重新整理頁面後再試'));
            })
            .fail(function() { revert('切換失敗，請檢查網路連線後重試'); })
            .always(function() { $toggle.prop('disabled', false); });
    }

    // 標題列的開始/結束時間：選好或清除後立即存檔（兩個欄位一起送，短延遲合併「清除時間」同時觸發的兩次變更）
    function scheduleSave($card) {
        clearTimeout($card.data('twshopScheduleTimer'));
        $card.data('twshopScheduleTimer', setTimeout(function() {
            var ruleId = $card.find('input[name="rule_id"]').val();
            var start = $card.find('input[name="start_time"]').val();
            var end = $card.find('input[name="end_time"]').val();
            if (!ruleId) { setDirty($card, true); return; }
            if (start && end && end <= start) { showCardStatus($card, 'error', '結束時間必須晚於開始時間'); return; }
            if (start === $card.data('twshopSavedStart') && end === $card.data('twshopSavedEnd')) return;
            $.post(twshopDiscountRules.ajaxUrl, { action: 'twshop_batch_update_rules', action_type: 'schedule', rule_ids: [ruleId], start_time: start, end_time: end, twshop_nonce: twshopAdminNonce })
                .done(function(res) {
                    if (res && res.success) {
                        $card.data('twshopSavedStart', start).data('twshopSavedEnd', end);
                        showCardStatus($card, 'success', (start || end) ? '✓ 已更新時間' : '✓ 已清除時間');
                    } else {
                        showCardStatus($card, 'error', ajaxErrorMsg(res, '時間儲存失敗'));
                    }
                })
                .fail(function() { showCardStatus($card, 'error', '時間儲存失敗，請檢查網路連線後重試'); });
        }, 300));
    }
    function rememberSavedSchedule($card) {
        $card.data('twshopSavedStart', $card.find('input[name="start_time"]').val())
             .data('twshopSavedEnd', $card.find('input[name="end_time"]').val());
    }
    $container.on('change', '.twshop-card-header-controls .twshop-datetime-picker', function() {
        if (dirtyTrackingOn) scheduleSave($(this).closest('.twshop-rule-form'));
    });

    $container.on('change', '.twshop-rule-enabled-toggle', function() {
        saveHeaderToggle($(this), 'enable', 'disable', '✓ 已啟用', '✓ 已停用', applyEnabledLook);
    });
    $container.on('change', '.twshop-rule-stack-toggle', function() {
        saveHeaderToggle($(this), 'stack_on', 'stack_off', '✓ 已設為不疊加', '✓ 已取消不疊加', null);
    });

    function openAndScrollTo($card, focusSelector) {
        $card.find('.twshop-card-body').show();
        $card.find('.twshop-card-toggle-icon').addClass('is-open');
        $('html, body').animate({ scrollTop: $card.offset().top - 50 }, 300, function() {
            if (focusSelector) $card.find(focusSelector).first().trigger('focus');
        });
    }

    initCards($container.children('.twshop-rule-form'));
    // 其他檔案（chip-field.js 等）的 document ready 初始化也會觸發 change，等它們都跑完才開始追蹤修改
    setTimeout(function() { dirtyTrackingOn = true; }, 0);

    // ── 排序 ─────────────────────────────────────────────────
    $container.sortable({
        handle: '.drag-handle',
        axis: 'y',
        opacity: 0.8,
        update: function() {
            var order = [];
            $container.children('.twshop-rule-form').each(function() {
                var id = $(this).find('input[name="rule_id"]').val();
                if (id) order.push(id);
            });
            if (!order.length) return;
            $.post(twshopDiscountRules.ajaxUrl, { action: 'twshop_reorder_rules', order: order, twshop_nonce: twshopAdminNonce })
                .done(function(res) {
                    if (res && res.success) showToolbarStatus('success', '✓ 優先順序已儲存');
                    else showToolbarStatus('error', ajaxErrorMsg(res, '排序儲存失敗，請重新整理頁面後再試'));
                })
                .fail(function() { showToolbarStatus('error', '排序儲存失敗，請檢查網路連線後重試'); });
        }
    });

    // ── 新增 ─────────────────────────────────────────────────
    $('#add-rule-row').on('click', function() {
        dirtyTrackingOn = false;
        $container.append(document.getElementById('discount-rule-template').innerHTML);
        var $newRow = $container.children('.twshop-rule-form').last();
        initCards($newRow);
        setDirty($newRow, true);
        dirtyTrackingOn = true;
        openAndScrollTo($newRow, '.twshop-rule-name-input');
    });

    // ── 收合 ─────────────────────────────────────────────────
    $container.on('click', '.twshop-card-header', function(e) {
        if ($(e.target).closest('input, button, select, label, a, .drag-handle').length) return;
        $(this).next('.twshop-card-body').slideToggle();
        $(this).find('.twshop-card-toggle-icon').toggleClass('is-open');
    });

    // ── 儲存 ─────────────────────────────────────────────────
    $container.on('submit', '.twshop-rule-form', function(e) {
        e.preventDefault();
        var $form = $(this), $btn = $form.find('.save-rule-btn');
        $btn.text('儲存中…').prop('disabled', true);
        $.post(twshopDiscountRules.ajaxUrl, $form.serialize() + '&action=twshop_save_rule')
            .done(function(res) {
                if (res && res.success) {
                    $form.find('input[name="rule_id"]').val(res.data.rule_id);
                    $form.attr('data-rule-type', $form.find('select[name="type"]').val());
                    $form.find('.twshop-duplicate-rule').prop('disabled', false).attr('title', '複製一份（預設停用）');
                    setDirty($form, false);
                    rememberSavedSchedule($form);
                    showSaveStatus($form, 'success', '✓ 已儲存');
                } else {
                    showSaveStatus($form, 'error', ajaxErrorMsg(res, '儲存失敗'));
                }
            })
            .fail(function() { showSaveStatus($form, 'error', '儲存失敗，請檢查網路連線後重試'); })
            .always(function() { $btn.text('儲存規則').prop('disabled', false); });
    });

    // ── 複製 ─────────────────────────────────────────────────
    $container.on('click', '.twshop-duplicate-rule', function() {
        var $form = $(this).closest('.twshop-rule-form');
        var ruleId = $form.find('input[name="rule_id"]').val();
        if (!ruleId) return;
        if ($form.hasClass('is-dirty')) {
            showCardStatus($form, 'error', '這條規則有未儲存的修改，請先儲存再複製');
            return;
        }
        var $btn = $(this).prop('disabled', true);
        $.post(twshopDiscountRules.ajaxUrl, { action: 'twshop_duplicate_rule', rule_id: ruleId, twshop_nonce: twshopAdminNonce })
            .done(function(res) {
                if (!res || !res.success) { showCardStatus($form, 'error', ajaxErrorMsg(res, '複製失敗')); return; }
                dirtyTrackingOn = false;
                var $copy = $($.parseHTML(res.data.html, document, false)).filter('.twshop-rule-form');
                $form.after($copy);
                initCards($copy);
                $copy.find('.twshop-chip-field').trigger('twshop-chip-refresh');
                $copy.find('.twshop-condition-type').trigger('change');
                dirtyTrackingOn = true;
                showCardStatus($copy, 'success', '已複製（預設停用，確認後再啟用）');
                openAndScrollTo($copy, '.twshop-rule-name-input');
            })
            .fail(function() { showCardStatus($form, 'error', '複製失敗，請檢查網路連線後重試'); })
            .always(function() { $btn.prop('disabled', false); });
    });

    // ── 階梯列 ───────────────────────────────────────────────
    $container.on('click', '.twshop-add-tier-row', function() {
        var $row = $(document.getElementById('twshop-tier-row-template').innerHTML);
        $(this).closest('.rule-tiers-wrap').find('.twshop-tiers-rows').append($row);
        updateTierHint($row.filter('.twshop-tier-row'));
    });
    $container.on('click', '.twshop-remove-tier-row', function() {
        $(this).closest('.twshop-tier-row').remove();
    });

    // ── 清除時間 ─────────────────────────────────────────────
    $container.on('click', '.twshop-clear-datetime', function(e) {
        e.preventDefault();
        var $card = $(this).closest('.twshop-rule-form');
        $card.find('.twshop-datetime-picker').each(function() {
            if (this._flatpickr) this._flatpickr.clear();
        });
        scheduleSave($card);
    });

    // ── 刪除 ─────────────────────────────────────────────────
    $container.on('click', '.remove-rule-row', function() {
        var $form = $(this).closest('.twshop-rule-form');
        var name = $form.find('input[name="name"]').val() || '新規則';
        if (!confirm('確定要刪除「' + name + '」嗎？此操作無法復原。')) return;
        var ruleId = $form.find('input[name="rule_id"]').val();
        var removeCard = function() {
            $form.fadeOut(200, function() { $form.remove(); });
            showToolbarStatus('success', '✓ 已刪除「' + name + '」');
        };
        if (!ruleId) { removeCard(); return; }
        var deleteNonce = $form.find('input[name="twshop_nonce"]').val() || twshopAdminNonce;
        $.post(twshopDiscountRules.ajaxUrl, { action: 'twshop_delete_rule', rule_id: ruleId, twshop_nonce: deleteNonce })
            .done(function(res) {
                if (res && res.success) removeCard();
                else showCardStatus($form, 'error', ajaxErrorMsg(res, '刪除失敗，請重新整理頁面後再試'));
            })
            .fail(function() { showCardStatus($form, 'error', '刪除失敗，請檢查網路連線後重試'); });
    });
});
