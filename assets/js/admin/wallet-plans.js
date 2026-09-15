/**
 * 儲值金「儲值方案」頁籤：方案列表的新增/刪除/拖曳排序，以及啟用勾選框跟隱藏欄位同步
 * （比照 account-tabs.js 的既有做法：核取方塊未勾選時瀏覽器根本不會送出該欄位，
 * 陣列索引會跟其他欄位錯位，改用一個一定會送出的隱藏欄位讓 JS 同步狀態）。
 */
jQuery(document).ready(function ($) {
    $('#wallet-plan-repeater-container').sortable({ handle: '.drag-handle', axis: 'y', opacity: 0.8 });

    $('#wallet-plan-repeater-container').on('change', '.wallet-plan-enabled-checkbox', function () {
        $(this).closest('.twshop-wallet-plan-row').find('.wallet-plan-enabled-input').val(this.checked ? 'yes' : 'no');
    });

    $('#add-wallet-plan-row').on('click', function () {
        $('#wallet-plan-repeater-container').append($('#wallet-plan-template').html());
    });

    $(document).on('click', '.remove-wallet-plan-row', function () {
        if ($('#wallet-plan-repeater-container .twshop-wallet-plan-row').length > 1) {
            $(this).closest('.twshop-wallet-plan-row').remove();
        } else {
            alert('至少保留一個方案！');
        }
    });
});
