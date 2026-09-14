/**
 * 使用者編輯頁：把點數管理區塊搬到個人資料表單的最上方。
 *
 * 自 points-engine.php 的內嵌 <script> 抽出（2026-08-21）。
 */
(function(){
    var block = document.getElementById('twshop-points-management');
    var form  = document.getElementById('your-profile');
    if (block && form) form.insertBefore(block, form.firstChild);
})();
