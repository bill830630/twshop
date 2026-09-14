/**
 * 「下拉挑選、已選項目以方塊呈現」欄位的共用行為，供折扣規則／點數等後台頁面共用。
 *
 * 自 ui-components.php 的內嵌 <script> 抽出（2026-08-21）。
 */
(function($){
    // 用事件委派 (delegated events) 綁定，動態新增的規則列/欄位不需要再手動重新初始化
    function renderChipsFor($field) {
        var $box = $field.find('.twshop-chip-box');
        var $picker = $field.find('.twshop-chip-picker');
        var $source = $field.find('.twshop-chip-source');

        $box.empty();
        $source.find('option:selected').each(function(){
            var $opt = $(this);
            var $chip = $('<span class="twshop-chip"></span>');
            $chip.append($('<span></span>').text($opt.text()));
            $chip.append($('<a href="#" class="twshop-chip-remove">&times;</a>').attr('data-val', $opt.val()));
            $box.append($chip);
        });

        var selectedVals = $source.find('option:selected').map(function(){ return $(this).val(); }).get();
        $picker.find('option').each(function(){
            var $opt = $(this);
            if (!$opt.val()) return;
            $opt.toggle(selectedVals.indexOf($opt.val()) === -1);
        });
    }

    $(document).on('change', '.twshop-chip-picker', function(){
        var $picker = $(this);
        var val = $picker.val();
        if (!val) return;
        var $field = $picker.closest('.twshop-chip-field');
        $field.find('.twshop-chip-source option[value="' + val + '"]').prop('selected', true);
        $picker.val('');
        renderChipsFor($field);
    });

    $(document).on('click', '.twshop-chip-remove', function(e){
        e.preventDefault();
        var $field = $(this).closest('.twshop-chip-field');
        var val = $(this).attr('data-val');
        $field.find('.twshop-chip-source option[value="' + val + '"]').prop('selected', false);
        renderChipsFor($field);
    });

    // 「限制類型」下拉選單：切換顯示對應類型的複選欄位（折扣系統的商品/分類/標籤、點數系統的分類/標籤皆共用）
    // 切換類型時清空未使用類型已選的項目：不同類型若共用同一個欄位名稱（如點數系統的分類/標籤），
    // 隱藏欄位裡殘留的舊選擇仍會隨表單一起送出，必須主動清掉避免與新類型的資料混在一起
    $(document).on('change', '.twshop-condition-type', function(){
        var val = $(this).val();
        var $scope = $(this).closest('.twshop-condition-scope');
        $scope.find('.condition-values-wrap').each(function(){
            var $wrap = $(this);
            var isActive = !!val && $wrap.hasClass('condition-values-' + val);
            $wrap.toggle(isActive);
            if (!isActive) {
                $wrap.find('.twshop-chip-source option:selected').prop('selected', false);
                $wrap.find('.twshop-chip-field').each(function(){ renderChipsFor($(this)); });
                // 商品限制條件改用 AJAX 搜尋（wc-product-search，見 twshop_render_product_search_field()）
                // 後新增：selectWoo 多選一樣要 .trigger('change') 才會連動更新畫面顯示，直接改
                // DOM option 屬性不會反映在 selectWoo 的 UI 上。沒有 .wc-product-search 元素的
                // wrap（分類/標籤）這行查無元素、無副作用。
                $wrap.find('select.wc-product-search').val(null).trigger('change');
            }
        });
    });

    // 動態插入的欄位（例如 AJAX 複製出來的規則卡片）用這個事件重繪已選項目
    $(document).on('twshop-chip-refresh', '.twshop-chip-field', function(e){
        e.stopPropagation();
        renderChipsFor($(this));
    });

    jQuery(document).ready(function($){
        $('.twshop-chip-field').each(function(){ renderChipsFor($(this)); });
        $('.twshop-condition-type').trigger('change');
    });
})(jQuery);
