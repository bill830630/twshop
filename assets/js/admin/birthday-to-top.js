/**
 * 使用者編輯頁：把會員生日管理區塊搬到個人資料表單的最上方。
 *
 * 自 points-engine.php 的內嵌 <script> 抽出（2026-08-21）。v25.8.66 起手動增減點數
 * 搬到後台「紅利點數 ▸ 會員餘額」頁籤，這裡只剩生日管理，檔名與 DOM id 同步改名
 * （原本是 twshop-points-management，見 CLAUDE.md「紅利點數」相關章節）。
 */
(function(){
    var block = document.getElementById('twshop-birthday-management');
    var form  = document.getElementById('your-profile');
    if (block && form) form.insertBefore(block, form.firstChild);
})();
