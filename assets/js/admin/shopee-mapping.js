/**
 * 蝦皮串接後台：商品對應表的手動綁定/解除綁定、重新抓取＋自動配對、立即推送，
 * 以及同步設定頁的「立即匯入訂單」、同步紀錄頁的「清空紀錄」。全部走 AJAX，非同步顯示結果。
 */
jQuery(document).ready(function ($) {
    var cfg = window.twshopShopeeMapping || {};

    function post(action, data, $status) {
        if ($status) $status.text(cfg.i18n && cfg.i18n.working ? cfg.i18n.working : '處理中…');
        data = data || {};
        data.action = action;
        data.twshop_nonce = cfg.nonce;
        return $.post(cfg.ajaxUrl, data).done(function (res) {
            if (!res || !res.success) {
                var msg = (res && res.data && res.data.msg) ? res.data.msg : (cfg.i18n && cfg.i18n.error ? cfg.i18n.error : '發生錯誤');
                if ($status) $status.text(msg);
                else alert(msg);
                return;
            }
            if ($status) $status.text((res.data && res.data.msg) ? res.data.msg : (cfg.i18n && cfg.i18n.done ? cfg.i18n.done : '完成'));
        }).fail(function () {
            if ($status) $status.text(cfg.i18n && cfg.i18n.error ? cfg.i18n.error : '發生錯誤');
        });
    }

    // ---- 商品對應表頁 ----
    $('#twshop-shopee-fetch-items').on('click', function () {
        var $status = $('#twshop-shopee-mapping-status');
        post('twshop_shopee_fetch_items', {}, $status).done(function (res) {
            if (res && res.success) location.reload();
        });
    });

    $('#twshop-shopee-push-all').on('click', function () {
        var $status = $('#twshop-shopee-mapping-status');
        post('twshop_shopee_push_now', {}, $status);
    });

    $(document).on('click', '.twshop-shopee-link', function () {
        var $row = $(this).closest('tr');
        var rowId = $(this).data('id');
        var productId = $row.find('.twshop-shopee-link-product-id').val();
        if (!productId) {
            alert(cfg.i18n && cfg.i18n.needProductId ? cfg.i18n.needProductId : '請輸入有效的 Woo 商品 ID');
            return;
        }
        post('twshop_shopee_link_item', { row_id: rowId, product_id: productId }).done(function (res) {
            if (res && res.success) location.reload();
        });
    });

    $(document).on('click', '.twshop-shopee-unlink', function () {
        if (!confirm(cfg.i18n && cfg.i18n.confirmUnlink ? cfg.i18n.confirmUnlink : '確定要解除這筆綁定嗎？')) return;
        var rowId = $(this).data('id');
        post('twshop_shopee_link_item', { row_id: rowId, unlink: 1 }).done(function (res) {
            if (res && res.success) location.reload();
        });
    });

    // ---- 同步設定頁：立即匯入訂單 ----
    $('#twshop-shopee-pull-orders').on('click', function () {
        var $status = $('#twshop-shopee-sync-status');
        post('twshop_shopee_pull_orders', {}, $status);
    });

    // ---- 同步紀錄頁：清空紀錄 ----
    $('#twshop-shopee-clear-log').on('click', function () {
        if (!confirm(cfg.i18n && cfg.i18n.confirmClear ? cfg.i18n.confirmClear : '確定要清空所有同步紀錄嗎？此動作無法復原。')) return;
        var $status = $('#twshop-shopee-log-status');
        post('twshop_shopee_clear_log', {}, $status).done(function (res) {
            if (res && res.success) location.reload();
        });
    });
});
