/**
 * 儲值金商品（product type: wallet_credit）的商品編輯頁補丁。
 *
 * WooCommerce 核心的「售價」「稅別」欄位群組是寫死在 html-product-data-general.php 裡的
 * class="options_group pricing show_if_simple show_if_external"／
 * class="options_group show_if_simple show_if_external show_if_variable"，沒有對應的
 * PHP filter 可以幫自訂商品類型加上 show_if_wallet_credit——這兩組欄位本來就不是本外掛
 * 自己輸出的 HTML，改用 JS 在這兩個既有欄位群組上補一個 class，讓核心既有的
 * show_if_*/hide_if_* 切換邏輯（meta-boxes-product.js 的 show_and_hide_controls()）
 * 把它們當成「儲值金商品也要顯示」的欄位，藉此重用核心欄位本身（含幣別符號、含稅/未稅
 * 標籤、特價排程等），不必自己重刻一份輸入框、也不需要另外處理存檔（核心 save() 對所有
 * 商品類型一律無條件讀取 $_POST['_regular_price'] 等欄位寫回，欄位有沒有顯示不影響
 * 存檔邏輯，只是決定使用者看不看得到輸入框）。
 *
 * 用 closest('#_regular_price').closest('.options_group') 而非寫死完整 class 組合字串去比對，
 * 是為了在 WooCommerce 版本升級、核心 class 名稱有增減時仍然抓得到正確的欄位群組。
 *
 * 只加 class，不需要在使用者切換商品類型下拉選單時額外處理——那個 class 一旦補上就
 * 永久留在 DOM 上，之後任何一次 show_and_hide_controls()（含使用者手動切換下拉選單時）
 * 都會自動吃到，不需要重複綁定。唯一需要手動處理的是「頁面剛載入、商品本來就已經是
 * 儲值金商品」這個情境——此時核心自己的 change() 早於這支腳本執行，已經用了舊的（沒有
 * 這個 class 的）狀態跑過一次，所以補上 class 後要再手動 trigger 一次 change 讓核心
 * 重新判斷一次，欄位才會在頁面第一次載入時就正確顯示，不用使用者手動點一下下拉選單。
 *
 * 另外處理「儲值金額度」留空時即時顯示「會自動帶入商品價格」的 placeholder 提示
 * （實際的預設邏輯在存檔端，見 twshop_save_wallet_credit_product_fields()，這裡純粹是
 * 存檔前的視覺提示，不影響送出的資料）。
 */
jQuery( function ( $ ) {
    $( '#_regular_price, #_sale_price' ).closest( '.options_group' ).addClass( 'show_if_wallet_credit' );
    $( '#_tax_status' ).closest( '.options_group' ).addClass( 'show_if_wallet_credit' );

    $( 'select#product-type' ).trigger( 'change' );

    // 「儲值金額度」留空時，存檔會自動帶入商品價格（PHP 端
    // twshop_save_wallet_credit_product_fields() 的既有邏輯）——這裡只是把這個預設值
    // 用 placeholder 即時顯示出來，讓管理員不用等存檔完重新整理頁面才看到「原來留空
    // 就是這個數字」。刻意只更新 placeholder、不寫入欄位的實際 value：寫入 value 會
    // 讓「管理員特意留空、想沿用預設值」跟「管理員填了一個剛好等於價格的數字」在畫面上
    // 分不出來，也可能不小心蓋掉管理員已經手動填過、想做加贈的數字。
    var $creditAmount = $( '#_twshop_wallet_credit_amount' );
    var $regularPrice = $( '#_regular_price' );

    function syncCreditAmountPlaceholder() {
        var price = $regularPrice.val();
        $creditAmount.attr( 'placeholder', price ? ( '留空預設等於商品價格 ' + price ) : '留空預設等於商品價格' );
    }

    syncCreditAmountPlaceholder();
    $regularPrice.on( 'input change', syncCreditAmountPlaceholder );
} );
