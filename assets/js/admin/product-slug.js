/**
 * 系統設定 ▸ 一般 ▸ 商品網址（slug）：分批把全站商品的網址轉成商品編號，
 * 或在開關關閉後把它們還原回去。方向由伺服器端讀當下的開關值決定，前端不傳。
 *
 * 進場即自動開跑（頁面渲染時剩餘數 > 0 才會有這個區塊），不需要使用者再按一次按鈕——
 * 「儲存設定」本身就是使用者的意思表示。中途離開頁面不會壞掉：剩餘數是伺服器端即時
 * 算出來的，回到這一頁會從頭再跑一次，游標從 0 開始重掃也只是多掃一輪。
 */
jQuery(document).ready(function ($) {
    var cfg = window.twshopProductSlug || {};
    var $box = $('#twshop-slug-batch');
    if (!$box.length) return;

    var remaining = parseInt($box.attr('data-remaining'), 10) || 0;
    if (remaining <= 0) return;

    var mode = $box.attr('data-mode') === 'restore' ? 'restore' : 'convert';
    var verb = mode === 'restore' ? '還原' : '轉換';
    var $remaining = $box.find('.twshop-slug-remaining');
    var $status = $box.find('.twshop-slug-status');
    var lastId = 0;

    function fail(msg) {
        $status.text(msg || ('網址' + verb + '中斷了，請重新整理頁面再試一次。'));
    }

    function runBatch() {
        $.post(cfg.ajaxUrl, {
            action: 'twshop_batch_product_slugs',
            twshop_nonce: cfg.nonce,
            after_id: lastId
        }).done(function (res) {
            if (!res || !res.success || !res.data) {
                fail(res && res.data && res.data.msg ? res.data.msg : null);
                return;
            }

            remaining = Math.max(0, remaining - (res.data.processed || 0));
            lastId = res.data.lastId || lastId;
            $remaining.text(remaining);

            if (res.data.done) {
                // 伺服器說撈不滿一批了，就是跑完了。剩餘數若還有殘留（例如中途有商品被刪掉），
                // 以伺服器的判斷為準歸零，不要讓畫面停在一個永遠不會變成 0 的數字。
                $box.html('<span class="twshop-badge twshop-badge--ok">全部商品的網址都已' + verb + '完成</span>');
                return;
            }

            runBatch();
        }).fail(function () {
            fail();
        });
    }

    runBatch();
});
