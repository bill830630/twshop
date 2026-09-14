/**
 * 會員中心頁籤的圖示選擇器：開關面板、選取後回填隱藏欄位與預覽。
 *
 * 自 page-member-tiers.php 的內嵌 <script> 抽出（2026-08-21）。
 */
(function($){
    // 整頁只有一份圖示網格面板（#twshop-icon-picker-shared-panel），開啟時用 appendTo()
    // 搬進當下這顆按鈕所屬的 .twshop-icon-picker（該容器已是 position:relative，面板的
    // position:absolute;top:36px;left:0 搬過去後視覺位置跟原本各自一份時完全一樣）。
    // 選到選項或收合時面板留在原地即可，不需要搬離；.closest('.twshop-icon-picker') 永遠
    // 會抓到面板「目前」所在的那一列，天然對應「正在編輯哪一列」。
    $(document).on('click', '.twshop-icon-picker-toggle', function(e){
        e.preventDefault();
        e.stopPropagation();
        var $picker = $(this).closest('.twshop-icon-picker');
        var $panel = $('#twshop-icon-picker-shared-panel');
        var alreadyOpenHere = $panel.parent().is($picker) && $panel.hasClass('is-open');
        $panel.removeClass('is-open');
        if (alreadyOpenHere) return; // 再次點擊同一顆按鈕＝收合
        $panel.appendTo($picker).addClass('is-open');
    });

    $(document).on('click', '.twshop-icon-picker-option', function(e){
        e.preventDefault();
        var $option = $(this);
        var icon = $option.attr('data-icon') || '';
        var $picker = $option.closest('.twshop-icon-picker');
        $picker.find('.twshop-icon-picker-input').val(icon);
        // 選項按鈕內的 SVG（略過「不顯示圖示」選項自己額外附加的文字 <span>）直接複製到預覽按鈕，
        // 不需要在 JS 端另外維護一份圖示對照表
        $picker.find('.twshop-icon-picker-preview').html($option.find('svg').first().prop('outerHTML'));
        $picker.find('.twshop-icon-picker-toggle').toggleClass('is-empty', !icon);
        $('#twshop-icon-picker-shared-panel').removeClass('is-open');
    });

    $(document).on('click', function(e){
        if ($(e.target).closest('.twshop-icon-picker').length) return;
        $('#twshop-icon-picker-shared-panel').removeClass('is-open');
    });
})(jQuery);
