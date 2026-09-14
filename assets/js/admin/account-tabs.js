/**
 * 會員中心頁籤排序：jQuery UI sortable 拖曳，放開後把順序寫回隱藏欄位。
 *
 * 自 page-general.php 的內嵌 <script> 抽出（2026-08-21）。
 */
jQuery(document).ready(function($) {
    $('#account-tabs-repeater-container').sortable({
        axis: 'y',
        opacity: 0.8,
        cancel: 'input, label, button, .twshop-icon-picker',
        placeholder: 'twshop-tab-row-placeholder',
        forcePlaceholderSize: true,
        tolerance: 'pointer'
    });
    $('#account-tabs-repeater-container').on('change', '.tab-enabled-checkbox', function () {
        $(this).closest('.twshop-tab-row').find('.tab-enabled-input').val(this.checked ? 'yes' : 'no');
    });
});
