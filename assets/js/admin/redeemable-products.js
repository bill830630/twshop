/**
 * 紅利點數頁「點數兌換商品」欄位：新增/移除項目並同步回 JSON 隱藏欄位。
 *
 * 自 page-points.php 的內嵌 <script> 抽出（2026-08-21）。
 * v25.8.15：項目從「只能是單一商品」擴充成也能是「商品分類」/「商品標籤」，
 * 資料形狀從 {product_id, points_cost} 改成 {type, id, points_cost}
 * （PHP 端已在輸出隱藏欄位前正規化成新形狀，見 twshop_normalize_redeemable_entry()）。
 * v25.8.17：分類/標籤不再手動填點數——同分類底下商品售價通常不同，統一點數等於把
 * 貴的商品賤賣，改成讀取端依各商品售價自動換算（twshop_calc_redeem_cost_from_price()，
 * includes/modules/points-engine.php）。選「分類」/「標籤」時「所需點數」欄位隱藏、
 * 送出的 points_cost 固定是 0（後端 sanitize 對這兩種 type 本來就不驗證這個值）。
 */
jQuery(document).ready(function($){
    var TYPE_LABEL = { category: '分類', tag: '標籤' };

    function renderRedeemProducts($wrap) {
        var $input = $wrap.find('.redeem-products-json');
        var arr = JSON.parse($input.val() || '[]');
        var html = '';
        arr.forEach(function(item, i) {
            var label, costText;
            if (item.type === 'category' || item.type === 'tag') {
                var $opt = $wrap.find('.redeem-' + item.type + '-add-select option[value="' + item.id + '"]');
                label = '[' + TYPE_LABEL[item.type] + '] ' + ($opt.text() || ('#' + item.id));
                costText = '依售價自動換算';
            } else {
                label = $wrap.find('.redeem-product-add-select option[value="' + item.id + '"]').text() || ('商品 #' + item.id);
                costText = item.points_cost + ' 點';
            }
            html += '<span style="display:inline-block; background:#fff; border:1px solid #ccc; padding:4px 8px; border-radius:4px; font-size:12px; margin:4px 6px 4px 0;">' + label + '：' + costText + '<a href="#" class="remove-redeem-product-btn" data-idx="' + i + '" style="color:red; text-decoration:none; margin-left:8px; font-weight:bold;">[移除]</a></span>';
        });
        $wrap.find('.redeem-products-list').html(html);
    }
    $('.twshop-redeem-products-section').each(function(){ renderRedeemProducts($(this)); });

    function togglePointsField($wrap, type) {
        var isProduct = type === 'product';
        $wrap.find('.redeem-product-add-points').toggle(isProduct);
        $wrap.find('.redeem-category-cost-note').toggle(!isProduct);
    }

    $(document).on('change', '.redeem-item-type-select', function(){
        var $wrap = $(this).closest('.twshop-redeem-products-section');
        var type = $(this).val();
        $wrap.find('.redeem-product-add-select').toggle(type === 'product');
        $wrap.find('.redeem-category-add-select').toggle(type === 'category');
        $wrap.find('.redeem-tag-add-select').toggle(type === 'tag');
        togglePointsField($wrap, type);
    });

    $(document).on('click', '.add-redeem-product-btn', function(){
        var $wrap = $(this).closest('.twshop-redeem-products-section');
        var type = $wrap.find('.redeem-item-type-select').val() || 'product';
        var $select = $wrap.find('.redeem-' + type + '-add-select');
        var id = $select.val();
        if (!id) { alert('請選擇項目'); return; }

        var pts = 0;
        if (type === 'product') {
            pts = $wrap.find('.redeem-product-add-points').val();
            if (!pts || pts <= 0) { alert('請輸入所需點數'); return; }
        }

        var $input = $wrap.find('.redeem-products-json');
        var arr = JSON.parse($input.val() || '[]');
        if (arr.some(function(it){ return it.type === type && String(it.id) === String(id); })) { alert('此項目已在兌換清單中'); return; }
        arr.push({ type: type, id: parseInt(id, 10), points_cost: type === 'product' ? parseInt(pts, 10) : 0 });
        $input.val(JSON.stringify(arr));
        renderRedeemProducts($wrap);
        $wrap.find('.redeem-product-add-points').val('');
    });

    $(document).on('click', '.remove-redeem-product-btn', function(e){
        e.preventDefault();
        var $wrap = $(this).closest('.twshop-redeem-products-section');
        var idx = $(this).data('idx');
        var $input = $wrap.find('.redeem-products-json');
        var arr = JSON.parse($input.val() || '[]');
        arr.splice(idx, 1);
        $input.val(JSON.stringify(arr));
        renderRedeemProducts($wrap);
    });
});
