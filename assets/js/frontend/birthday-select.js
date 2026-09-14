/**
 * 生日欄位的月份／日期連動：切換月份時把該月不存在的日期隱藏並停用。
 *
 * 自 points-engine.php 的內嵌 <script> 抽出（2026-08-21）。
 */
(function(){
    var monthSel = document.getElementById('twshop_birthday_month');
    var daySel   = document.getElementById('twshop_birthday_day');
    if ( ! monthSel || ! daySel ) return;
    var daysInMonth = { 1:31, 2:29, 3:31, 4:30, 5:31, 6:30, 7:31, 8:31, 9:30, 10:31, 11:30, 12:31 };
    function adjustDays() {
        var max = daysInMonth[ parseInt( monthSel.value, 10 ) ] || 31;
        var current = parseInt( daySel.value, 10 ) || 0;
        Array.prototype.forEach.call( daySel.options, function( opt ) {
            if ( ! opt.value ) return;
            var v = parseInt( opt.value, 10 );
            opt.hidden = v > max;
            opt.disabled = v > max;
        });
        if ( current > max ) daySel.value = '';
    }
    monthSel.addEventListener( 'change', adjustDays );
    adjustDays();
})();
