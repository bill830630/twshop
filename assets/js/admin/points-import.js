/**
 * 紅利點數頁「匯入點數資料」：選擇 CSV 檔案後以 FormData 上傳給
 * wp_ajax_twshop_import_points_csv，回傳結果（成功筆數＋略過清單）就地渲染，
 * 不重新整理頁面。
 */
jQuery(document).ready(function ($) {
    var cfg = window.twshopPointsImport || {};
    var $btn = $('#twshop-points-import-btn');
    if (!$btn.length) return;

    var $file = $('#twshop-points-import-file');
    var $result = $('#twshop-points-import-result');

    function escapeHtml(str) {
        return $('<div>').text(str === null || str === undefined ? '' : str).html();
    }

    function renderResult(data) {
        var html = '<p style="margin-top:14px;"><span class="twshop-badge twshop-badge--ok">成功匯入 ' + data.imported + ' 筆</span>';
        if (data.skipped && data.skipped.length) {
            html += ' <span class="twshop-badge twshop-badge--warn">略過 ' + data.skipped.length + ' 筆</span>';
        }
        if (data.truncated) {
            html += ' <span class="twshop-badge twshop-badge--warn">檔案超過單次匯入上限，僅處理前面部分，請將剩餘資料另存新檔後再次匯入</span>';
        }
        html += '</p>';

        if (data.skipped && data.skipped.length) {
            html += '<table class="widefat striped" style="max-width:640px;"><thead><tr><th style="width:60px;">行數</th><th>Email</th><th>略過原因</th></tr></thead><tbody>';
            $.each(data.skipped, function (i, row) {
                html += '<tr><td>' + escapeHtml(row.line) + '</td><td>' + escapeHtml(row.email) + '</td><td>' + escapeHtml(row.reason) + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        $result.html(html);
    }

    $btn.on('click', function () {
        var file = $file.length ? $file[0].files[0] : null;
        if (!file) {
            $result.html('<p class="twshop-hint">請先選擇 CSV 檔案。</p>');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'twshop_import_points_csv');
        formData.append('twshop_nonce', cfg.nonce);
        formData.append('csv_file', file);

        $btn.prop('disabled', true).addClass('twshop-btn-busy').text('匯入中…');
        $result.html('');

        $.ajax({
            url: cfg.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false
        }).done(function (res) {
            if (!res || !res.success) {
                $result.html('<p class="twshop-hint">' + escapeHtml(res && res.data && res.data.msg ? res.data.msg : '匯入失敗，請再試一次。') + '</p>');
                return;
            }
            renderResult(res.data);
        }).fail(function () {
            $result.html('<p class="twshop-hint">匯入失敗，請再試一次。</p>');
        }).always(function () {
            $btn.prop('disabled', false).removeClass('twshop-btn-busy').text('開始匯入');
            $file.val('');
        });
    });
});
