/**
 * 訂單編輯頁物流 metabox：列印託運單按鈕（實際列印由綠界外掛的 ecpayLogisticPrint() 執行）。
 *
 * 自 order-logistics.php 的內嵌 <script> 抽出（2026-08-21）。
 */
jQuery(function($){
    $('#twshop-print-order-logistics').on('click', function(){
        if ( typeof ecpayLogisticPrint === 'function' ) {
            ecpayLogisticPrint();
        } else {
            alert( '列印功能尚未載入，請重新整理頁面後再試一次；若持續發生，請確認「ECPay Ecommerce for WooCommerce」外掛是否已啟用。' );
        }
    });
});
