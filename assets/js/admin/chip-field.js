/**
 * 折扣規則「適用範圍」與紅利點數限制條件共用的「限制類型」切換行為。
 *
 * 自 ui-components.php 的內嵌 <script> 抽出（2026-08-21）。
 *
 * 2026-09 起，商品分類／標籤的複選欄位改用跟「選擇商品」（wc-product-search）同一套
 * selectWoo 元件（見 twshop_render_chip_field()，ui-components.php）——分類/標籤是站台
 * 本地資料、數量有限，不需要 AJAX 搜尋，selectWoo 對既有 <option> 做本地過濾即可，只是
 * 換掉底層元件，操作方式跟「選擇商品」欄位一致。原本「下拉挑選＋已選項目另外顯示成方塊」
 * 的 .twshop-chip-picker／.twshop-chip-box／.twshop-chip-source 三段式 DOM 與對應的
 * renderChipsFor() 渲染邏輯已隨這次改動整個移除。「點數兌換商品」清單另外沿用
 * .twshop-chip／.twshop-chip-box 的純視覺樣式（不經過這支檔案，見 redeemable-products.js），
 * 不受影響。
 */
(function($){
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
                // 商品／分類／標籤三種欄位現在都是 selectWoo 多選（wc-product-search 或
                // wc-enhanced-select），一律用 .val(null).trigger('change') 清空並同步畫面——
                // 直接改 DOM option 的 selected 屬性不會反映在 selectWoo 的 UI 上。
                $wrap.find('select.wc-product-search, select.wc-enhanced-select').val(null).trigger('change');
            }
        });
    });

    jQuery(document).ready(function($){
        $('.twshop-condition-type').trigger('change');
    });
})(jQuery);
