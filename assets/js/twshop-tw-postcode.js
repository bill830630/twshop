/* 台灣地址欄位互動：「縣/市 ＋ 鄉鎮市區」→ 3 碼郵遞區號自動帶入（郵遞區號欄位本身隱藏，
 * 值照常隨表單送出），以及「鄉鎮市區」下拉選單隨「縣/市」改變即時重新灌選項。
 *
 * **2026-09 資料流向反過來了**：早期版本是顧客自己輸入郵遞區號、由這裡反查帶出縣市與
 * 鄉鎮市區（twshopFillAddressFromPostcode()，已移除）。改成縣市/鄉鎮市區都是下拉選單之後，
 * 選完就唯一決定郵遞區號，再要顧客自己填一次只是多一個填錯的機會，因此改由
 * twshopSyncPostcodeFromAddress() 自動帶入、欄位以 .twshop-postcode-auto 隱藏。
 * 伺服器端的對應處理見 includes/modules/order-checkout.php 的
 * twshop_taiwan_hide_postcode_field()／twshop_fill_taiwan_postcode_posted_data()。
 *
 * 另含一段**跟台灣無關的通用行為**：換國別時清空原本填的地址欄位（見檔案下半段
 * 「換國別時清空原本填的地址」）。放在這支檔案是因為它就是「地址欄位互動」的那支，
 * 載入時機（結帳頁＋我的帳號）也剛好完全吻合，不值得為了一段 30 行的邏輯多開一支檔案。
 *
 * 「縣/市」本來就是 WooCommerce 原生下拉選單（見 twshop_add_taiwan_states()）；「鄉鎮市區」
 * 由 twshop_taiwan_city_field_as_select()（includes/modules/order-checkout.php）也改成
 * 下拉選單，但該函式只負責「頁面第一次載入當下」該顯示哪個縣市的清單，縣市選單改變之後
 * 選項要即時換一批，就得靠這裡的 JS。
 *
 * 資料來源：中華郵政 3 碼郵遞區號（2010 年縣市合併後的現行行政區劃），共 365 個郵遞區號：
 * 363 筆唯一對應（縣市＋鄉鎮市區都能確定），2 筆（300／600）被新竹市／嘉義市底下多個行政區
 * 共用（300：東區／北區／香山區；600：東區／西區）。**歧義只存在於「郵遞區號 → 鄉鎮市區」
 * 那個已經廢除的方向**；現在要的「鄉鎮市區 → 郵遞區號」不管選哪一區答案都一樣，沒有歧義。
 *
 * 跟後台「系統 ▸ 一般 ▸ 結帳頁欄位客製化」同一個開關控制，只在 twshopData.checkoutFieldCustomization
 * 為 true 時才會 enqueue 這支檔案（見 twshop_global_frontend_js()），這裡不用再自行判斷一次。
 */
(function ($) {
    'use strict';

    var TWSHOP_POSTCODE_UNIQUE = {"100":["TPE","中正區"],"103":["TPE","大同區"],"104":["TPE","中山區"],"105":["TPE","松山區"],"106":["TPE","大安區"],"108":["TPE","萬華區"],"110":["TPE","信義區"],"111":["TPE","士林區"],"112":["TPE","北投區"],"114":["TPE","內湖區"],"115":["TPE","南港區"],"116":["TPE","文山區"],"200":["KEE","仁愛區"],"201":["KEE","信義區"],"202":["KEE","中正區"],"203":["KEE","中山區"],"204":["KEE","安樂區"],"205":["KEE","暖暖區"],"206":["KEE","七堵區"],"207":["NTP","萬里區"],"208":["NTP","金山區"],"209":["LIE","南竿鄉"],"210":["LIE","北竿鄉"],"211":["LIE","莒光鄉"],"212":["LIE","東引鄉"],"220":["NTP","板橋區"],"221":["NTP","汐止區"],"222":["NTP","深坑區"],"223":["NTP","石碇區"],"224":["NTP","瑞芳區"],"226":["NTP","平溪區"],"227":["NTP","雙溪區"],"228":["NTP","貢寮區"],"231":["NTP","新店區"],"232":["NTP","坪林區"],"233":["NTP","烏來區"],"234":["NTP","永和區"],"235":["NTP","中和區"],"236":["NTP","土城區"],"237":["NTP","三峽區"],"238":["NTP","樹林區"],"239":["NTP","鶯歌區"],"241":["NTP","三重區"],"242":["NTP","新莊區"],"243":["NTP","泰山區"],"244":["NTP","林口區"],"247":["NTP","蘆洲區"],"248":["NTP","五股區"],"249":["NTP","八里區"],"251":["NTP","淡水區"],"252":["NTP","三芝區"],"253":["NTP","石門區"],"260":["YIL","宜蘭市"],"261":["YIL","頭城鎮"],"262":["YIL","礁溪鄉"],"263":["YIL","壯圍鄉"],"264":["YIL","員山鄉"],"265":["YIL","羅東鎮"],"266":["YIL","三星鄉"],"267":["YIL","大同鄉"],"268":["YIL","五結鄉"],"269":["YIL","冬山鄉"],"270":["YIL","蘇澳鎮"],"272":["YIL","南澳鄉"],"302":["HSQ","竹北市"],"303":["HSQ","湖口鄉"],"304":["HSQ","新豐鄉"],"305":["HSQ","新埔鎮"],"306":["HSQ","關西鎮"],"307":["HSQ","芎林鄉"],"308":["HSQ","寶山鄉"],"310":["HSQ","竹東鎮"],"311":["HSQ","五峰鄉"],"312":["HSQ","橫山鄉"],"313":["HSQ","尖石鄉"],"314":["HSQ","北埔鄉"],"315":["HSQ","峨嵋鄉"],"320":["TYC","中壢區"],"324":["TYC","平鎮區"],"325":["TYC","龍潭區"],"326":["TYC","楊梅區"],"327":["TYC","新屋區"],"328":["TYC","觀音區"],"330":["TYC","桃園區"],"333":["TYC","龜山區"],"334":["TYC","八德區"],"335":["TYC","大溪區"],"336":["TYC","復興區"],"337":["TYC","大園區"],"338":["TYC","蘆竹區"],"350":["MIA","竹南鎮"],"351":["MIA","頭份市"],"352":["MIA","三灣鄉"],"353":["MIA","南庄鄉"],"354":["MIA","獅潭鄉"],"356":["MIA","後龍鎮"],"357":["MIA","通霄鎮"],"358":["MIA","苑裡鎮"],"360":["MIA","苗栗市"],"361":["MIA","造橋鄉"],"362":["MIA","頭屋鄉"],"363":["MIA","公館鄉"],"364":["MIA","大湖鄉"],"365":["MIA","泰安鄉"],"366":["MIA","銅鑼鄉"],"367":["MIA","三義鄉"],"368":["MIA","西湖鄉"],"369":["MIA","卓蘭鎮"],"400":["TXG","中區"],"401":["TXG","東區"],"402":["TXG","南區"],"403":["TXG","西區"],"404":["TXG","北區"],"406":["TXG","北屯區"],"407":["TXG","西屯區"],"408":["TXG","南屯區"],"411":["TXG","太平區"],"412":["TXG","大里區"],"413":["TXG","霧峰區"],"414":["TXG","烏日區"],"420":["TXG","豐原區"],"421":["TXG","后里區"],"422":["TXG","石岡區"],"423":["TXG","東勢區"],"424":["TXG","和平區"],"426":["TXG","新社區"],"427":["TXG","潭子區"],"428":["TXG","大雅區"],"429":["TXG","神岡區"],"432":["TXG","大肚區"],"433":["TXG","沙鹿區"],"434":["TXG","龍井區"],"435":["TXG","梧棲區"],"436":["TXG","清水區"],"437":["TXG","大甲區"],"438":["TXG","外埔區"],"439":["TXG","大安區"],"500":["CHA","彰化市"],"502":["CHA","芬園鄉"],"503":["CHA","花壇鄉"],"504":["CHA","秀水鄉"],"505":["CHA","鹿港鎮"],"506":["CHA","福興鄉"],"507":["CHA","線西鄉"],"508":["CHA","和美鎮"],"509":["CHA","伸港鄉"],"510":["CHA","員林市"],"511":["CHA","社頭鄉"],"512":["CHA","永靖鄉"],"513":["CHA","埔心鄉"],"514":["CHA","溪湖鎮"],"515":["CHA","大村鄉"],"516":["CHA","埔鹽鄉"],"520":["CHA","田中鎮"],"521":["CHA","北斗鎮"],"522":["CHA","田尾鄉"],"523":["CHA","埤頭鄉"],"524":["CHA","溪州鄉"],"525":["CHA","竹塘鄉"],"526":["CHA","二林鎮"],"527":["CHA","大城鄉"],"528":["CHA","芳苑鄉"],"530":["CHA","二水鄉"],"540":["NAN","南投市"],"541":["NAN","中寮鄉"],"542":["NAN","草屯鎮"],"544":["NAN","國姓鄉"],"545":["NAN","埔里鎮"],"546":["NAN","仁愛鄉"],"551":["NAN","名間鄉"],"552":["NAN","集集鎮"],"553":["NAN","水里鄉"],"555":["NAN","魚池鄉"],"556":["NAN","信義鄉"],"557":["NAN","竹山鎮"],"558":["NAN","鹿谷鄉"],"602":["CYQ","番路鄉"],"603":["CYQ","梅山鄉"],"604":["CYQ","竹崎鄉"],"605":["CYQ","阿里山鄉"],"606":["CYQ","中埔鄉"],"607":["CYQ","大埔鄉"],"608":["CYQ","水上鄉"],"611":["CYQ","鹿草鄉"],"612":["CYQ","太保市"],"613":["CYQ","朴子市"],"614":["CYQ","東石鄉"],"615":["CYQ","六腳鄉"],"616":["CYQ","新港鄉"],"621":["CYQ","民雄鄉"],"622":["CYQ","大林鎮"],"623":["CYQ","溪口鄉"],"624":["CYQ","義竹鄉"],"625":["CYQ","布袋鎮"],"630":["YUN","斗南鎮"],"631":["YUN","大埤鄉"],"632":["YUN","虎尾鎮"],"633":["YUN","土庫鎮"],"634":["YUN","褒忠鄉"],"635":["YUN","東勢鄉"],"636":["YUN","臺西鄉"],"637":["YUN","崙背鄉"],"638":["YUN","麥寮鄉"],"640":["YUN","斗六市"],"643":["YUN","林內鄉"],"646":["YUN","古坑鄉"],"647":["YUN","莿桐鄉"],"648":["YUN","西螺鎮"],"649":["YUN","二崙鄉"],"651":["YUN","北港鎮"],"652":["YUN","水林鄉"],"653":["YUN","口湖鄉"],"654":["YUN","四湖鄉"],"655":["YUN","元長鄉"],"700":["TNN","中西區"],"701":["TNN","東區"],"702":["TNN","南區"],"704":["TNN","北區"],"708":["TNN","安平區"],"709":["TNN","安南區"],"710":["TNN","永康區"],"711":["TNN","歸仁區"],"712":["TNN","新化區"],"713":["TNN","左鎮區"],"714":["TNN","玉井區"],"715":["TNN","楠西區"],"716":["TNN","南化區"],"717":["TNN","仁德區"],"718":["TNN","關廟區"],"719":["TNN","龍崎區"],"720":["TNN","官田區"],"721":["TNN","麻豆區"],"722":["TNN","佳里區"],"723":["TNN","西港區"],"724":["TNN","七股區"],"725":["TNN","將軍區"],"726":["TNN","學甲區"],"727":["TNN","北門區"],"730":["TNN","新營區"],"731":["TNN","後壁區"],"732":["TNN","白河區"],"733":["TNN","東山區"],"734":["TNN","六甲區"],"735":["TNN","下營區"],"736":["TNN","柳營區"],"737":["TNN","鹽水區"],"741":["TNN","善化區"],"742":["TNN","大內區"],"743":["TNN","山上區"],"744":["TNN","新市區"],"745":["TNN","安定區"],"800":["KHH","新興區"],"801":["KHH","前金區"],"802":["KHH","苓雅區"],"803":["KHH","鹽埕區"],"804":["KHH","鼓山區"],"805":["KHH","旗津區"],"806":["KHH","前鎮區"],"807":["KHH","三民區"],"811":["KHH","楠梓區"],"812":["KHH","小港區"],"813":["KHH","左營區"],"814":["KHH","仁武區"],"815":["KHH","大社區"],"820":["KHH","岡山區"],"821":["KHH","路竹區"],"822":["KHH","阿蓮區"],"823":["KHH","田寮區"],"824":["KHH","燕巢區"],"825":["KHH","橋頭區"],"826":["KHH","梓官區"],"827":["KHH","彌陀區"],"828":["KHH","永安區"],"829":["KHH","湖內區"],"830":["KHH","鳳山區"],"831":["KHH","大寮區"],"832":["KHH","林園區"],"833":["KHH","鳥松區"],"840":["KHH","大樹區"],"842":["KHH","旗山區"],"843":["KHH","美濃區"],"844":["KHH","六龜區"],"845":["KHH","內門區"],"846":["KHH","杉林區"],"847":["KHH","甲仙區"],"848":["KHH","桃源區"],"849":["KHH","那瑪夏區"],"851":["KHH","茂林區"],"852":["KHH","茄萣區"],"880":["PEN","馬公市"],"881":["PEN","西嶼鄉"],"882":["PEN","望安鄉"],"883":["PEN","七美鄉"],"884":["PEN","白沙鄉"],"885":["PEN","湖西鄉"],"890":["KIN","金沙鎮"],"891":["KIN","金湖鎮"],"892":["KIN","金寧鄉"],"893":["KIN","金城鎮"],"894":["KIN","烈嶼鄉"],"896":["KIN","烏坵鄉"],"900":["PIF","屏東市"],"901":["PIF","三地門鄉"],"902":["PIF","霧臺鄉"],"903":["PIF","瑪家鄉"],"904":["PIF","九如鄉"],"905":["PIF","里港鄉"],"906":["PIF","高樹鄉"],"907":["PIF","鹽埔鄉"],"908":["PIF","長治鄉"],"909":["PIF","麟洛鄉"],"911":["PIF","竹田鄉"],"912":["PIF","內埔鄉"],"913":["PIF","萬丹鄉"],"920":["PIF","潮州鎮"],"921":["PIF","泰武鄉"],"922":["PIF","來義鄉"],"923":["PIF","萬巒鄉"],"924":["PIF","崁頂鄉"],"925":["PIF","新埤鄉"],"926":["PIF","南州鄉"],"927":["PIF","林邊鄉"],"928":["PIF","東港鎮"],"929":["PIF","琉球鄉"],"931":["PIF","佳冬鄉"],"932":["PIF","新園鄉"],"940":["PIF","枋寮鄉"],"941":["PIF","枋山鄉"],"942":["PIF","春日鄉"],"943":["PIF","獅子鄉"],"944":["PIF","車城鄉"],"945":["PIF","牡丹鄉"],"946":["PIF","恆春鎮"],"947":["PIF","滿州鄉"],"950":["TTT","臺東市"],"951":["TTT","綠島鄉"],"952":["TTT","蘭嶼鄉"],"953":["TTT","延平鄉"],"954":["TTT","卑南鄉"],"955":["TTT","鹿野鄉"],"956":["TTT","關山鎮"],"957":["TTT","海端鄉"],"958":["TTT","池上鄉"],"959":["TTT","東河鄉"],"961":["TTT","成功鎮"],"962":["TTT","長濱鄉"],"963":["TTT","太麻里鄉"],"964":["TTT","金峰鄉"],"965":["TTT","大武鄉"],"966":["TTT","達仁鄉"],"970":["HUA","花蓮市"],"971":["HUA","新城鄉"],"972":["HUA","秀林鄉"],"973":["HUA","吉安鄉"],"974":["HUA","壽豐鄉"],"975":["HUA","鳳林鎮"],"976":["HUA","光復鄉"],"977":["HUA","豐濱鄉"],"978":["HUA","瑞穗鄉"],"979":["HUA","萬榮鄉"],"981":["HUA","玉里鎮"],"982":["HUA","卓溪鄉"],"983":["HUA","富里鄉"]};

    // 300（新竹市東區/北區/香山區共用）、600（嘉義市東區/西區共用）：這兩碼底下各有多個行政區，
    // 因此不在 TWSHOP_POSTCODE_UNIQUE 裡，兩市的行政區清單改在這裡手動維護。
    // **本檔案要的「鄉鎮市區 → 郵遞區號」方向沒有歧義**：不管選新竹市哪一區，郵遞區號都是 300。
    var TWSHOP_POSTCODE_AMBIGUOUS = {"300":"HSZ","600":"CYI"};
    var TWSHOP_AMBIGUOUS_DISTRICTS = {"HSZ":["東區","北區","香山區"],"CYI":["東區","西區"]};

    // 「鄉鎮市區」下拉選單的 縣市代碼 → 鄉鎮市區清單，從上面 TWSHOP_POSTCODE_UNIQUE 反查去重
    // 整理而成（跟 PHP 端 twshop_get_taiwan_districts() 各自獨立維護，理由見檔頭說明）。
    var TWSHOP_TAIWAN_DISTRICTS = (function () {
        var byState = {};
        for (var state in TWSHOP_AMBIGUOUS_DISTRICTS) {
            if (!Object.prototype.hasOwnProperty.call(TWSHOP_AMBIGUOUS_DISTRICTS, state)) continue;
            byState[state] = TWSHOP_AMBIGUOUS_DISTRICTS[state].slice();
        }
        for (var zip in TWSHOP_POSTCODE_UNIQUE) {
            if (!Object.prototype.hasOwnProperty.call(TWSHOP_POSTCODE_UNIQUE, zip)) continue;
            var entry = TWSHOP_POSTCODE_UNIQUE[zip];
            var stateCode = entry[0], district = entry[1];
            if (!byState[stateCode]) byState[stateCode] = [];
            if (byState[stateCode].indexOf(district) === -1) byState[stateCode].push(district);
        }
        return byState;
    })();

    // 「縣市代碼|鄉鎮市區」→ 3 碼郵遞區號，同樣從上面兩份資料反查整理而成
    // （跟 PHP 端 twshop_get_taiwan_district_postcodes() 各自獨立維護，理由見檔頭說明）。
    var TWSHOP_DISTRICT_POSTCODE = (function () {
        var byDistrict = {};
        for (var zip in TWSHOP_POSTCODE_UNIQUE) {
            if (!Object.prototype.hasOwnProperty.call(TWSHOP_POSTCODE_UNIQUE, zip)) continue;
            var entry = TWSHOP_POSTCODE_UNIQUE[zip];
            byDistrict[entry[0] + '|' + entry[1]] = zip;
        }
        for (var ambiguousZip in TWSHOP_POSTCODE_AMBIGUOUS) {
            if (!Object.prototype.hasOwnProperty.call(TWSHOP_POSTCODE_AMBIGUOUS, ambiguousZip)) continue;
            var ambiguousState = TWSHOP_POSTCODE_AMBIGUOUS[ambiguousZip];
            var districts = TWSHOP_AMBIGUOUS_DISTRICTS[ambiguousState] || [];
            for (var i = 0; i < districts.length; i++) {
                byDistrict[ambiguousState + '|' + districts[i]] = ambiguousZip;
            }
        }
        return byDistrict;
    })();

    /**
     * 依「縣/市」目前選的值，重新灌一次「鄉鎮市區」下拉選單的選項。
     * preserveValue 未傳時預設沿用 city 欄位目前的值（縣市改變前使用者可能已經選過一個
     * 鄉鎮市區，盡量幫他保留住，選不到新縣市清單裡才會被清掉／退回佔位選項）。
     * 用 jQuery 的 $('<option>', {value, text}) 建立節點而非字串拼接 innerHTML，
     * 避免地址欄位裡的既有值（使用者自己輸入過的舊資料）被當成 HTML 插回頁面。
     *
     * 只在 city 欄位真的是 <select> 時才動作：twshop_taiwan_city_field_as_select()
     * （PHP）只在國別是 TW 時才會把 city 從文字輸入改成下拉選單，這裡刻意再檢查一次
     * （而不是預設「反正不會有其他情境」），確保這整套下拉選單邏輯（含縣市/鄉鎮市區）
     * 只在台灣地區才會啟用——即使本站目前銷售/運送地區都鎖定台灣、國別選單根本不會出現，
     * 這裡的判斷式仍是這個保證在程式碼層級的最後一道防線，不是只靠外部設定湊巧成立。
     */
    function twshopPopulateCityOptions(prefix, preserveValue) {
        var $state = $('#' + prefix + '_state');
        var $city = $('#' + prefix + '_city');
        if (!$state.length || !$city.length) return;
        if (!$city.is('select')) return;

        var stateCode = $state.val();
        var districts = TWSHOP_TAIWAN_DISTRICTS[stateCode] || [];
        var currentValue = preserveValue !== undefined ? preserveValue : $city.val();

        $city.empty();
        $city.append($('<option>', { value: '', text: '請先選擇縣市' }));

        var found = false;
        for (var i = 0; i < districts.length; i++) {
            if (districts[i] === currentValue) found = true;
            $city.append($('<option>', { value: districts[i], text: districts[i] }));
        }
        // 保留既有值：即使不在目前縣市的清單裡（縣市剛換過、或舊資料本來就不在標準清單內），
        // 也留著讓使用者自己決定要不要清掉，不要無聲把使用者原本填的東西丟掉。
        if (currentValue && !found) {
            $city.append($('<option>', { value: currentValue, text: currentValue }));
        }
        if (currentValue) $city.val(currentValue);
    }

    /**
     * 目前已選的運送方式（shipping_method[0]）是否勾了「台灣地址下拉選單連動」
     * （twshop_is_address_linkage，後台每個運送方式 instance 設定頁個別勾選）。
     * twshopData.addressLinkageMethods 由 PHP 端 twshop_get_address_linkage_method_strings()
     * 產生，同一份清單也是 PHP 端 twshop_is_address_linkage_shipping_chosen() 的資料來源，
     * 兩邊判斷邏輯要保持一致——這裡只是把「session 記得的已選運送方式」換成「畫面上當下
     * 勾選的 radio」，因為使用者在頁面上切換運送方式的當下，session 還沒更新完。
     */
    function twshopIsAddressLinkageChosen() {
        if (typeof twshopData === 'undefined') return false;
        var methods = twshopData.addressLinkageMethods || [];
        if (!methods.length) return false;
        var method = $('input[name="shipping_method[0]"]:checked').val()
                  || $('input[name="shipping_method[0]"][type="hidden"]').val()
                  || '';
        return methods.indexOf(method) !== -1;
    }

    /**
     * 頁面上到底有沒有「運送方式」可選。沒有的話（「我的帳號 ▸ 編輯地址」頁、或購物車全是
     * 虛擬商品的結帳頁）就完全不切換欄位型態，維持 PHP 端渲染出來的樣子——
     * twshop_is_address_linkage_shipping_chosen()（PHP）讀的是 session 裡記得的已選運送方式，
     * 前端 twshopIsAddressLinkageChosen() 讀的是畫面上勾選的 radio，兩者在「畫面上根本沒有
     * 運送方式」時必然不一致（前端一定判斷成 false），沒有這道防線就會把編輯地址頁上 PHP
     * 已經正確渲染好的縣市/鄉鎮市區選單，在 document ready 當下換回自由輸入文字。
     */
    function twshopHasShippingMethodInputs() {
        return $('input[name^="shipping_method"], select[name^="shipping_method"]').length > 0;
    }

    /**
     * 目前國別是不是台灣。整套「縣市／鄉鎮市區下拉選單」只在台灣地址成立——PHP 端
     * twshop_taiwan_city_field_as_select() 第一行就是 `'TW' !== $country` 直接 return，
     * 前端這邊原本漏掉同一道判斷。國別欄位不存在（銷售地區只有一國時不一定會輸出）視為台灣，
     * 維持單一國家站台的既有行為。
     */
    function twshopIsTwCountry(prefix) {
        var $country = $('#' + prefix + '_country');
        if (!$country.length) return true;
        return 'TW' === $country.val();
    }

    /**
     * 欄位的「原始 class」：WooCommerce 渲染 state/country 欄位時會把 $args['input_class']
     * 同時寫進 class 與 data-input-classes，核心 country-select.js 切換欄位型態時就是讀後者
     * 重建 class。沿用同一份資料，避免把 state_select／input-text／select 這種「跟型態綁定」
     * 的 class 帶到另一種型態的元素上（原本直接複製整串 class，換成 <input> 之後身上還掛著
     * state_select，換成 <select> 又掛著 input-text，樣式與核心的選取器都會兜不起來）。
     */
    var TWSHOP_TYPE_BOUND_CLASSES = ['state_select', 'input-text', 'select', 'country_select', 'country_to_state', 'select2-hidden-accessible'];

    function twshopFieldInputClasses($field) {
        var classes = $field.attr('data-input-classes');
        if (typeof classes === 'string') return classes;
        var kept = [];
        var names = ($field.attr('class') || '').split(/\s+/);
        for (var i = 0; i < names.length; i++) {
            if (!names[i]) continue;
            if (TWSHOP_TYPE_BOUND_CLASSES.indexOf(names[i]) !== -1) continue;
            kept.push(names[i]);
        }
        return kept.join(' ');
    }

    /**
     * 換掉地址欄位元素本身（<select> ↔ <input>）。
     *
     * **一定要先移除 .select2-container，這就是「換回台灣後縣市選單卡在上一個國家」的根因**：
     * WooCommerce 用 selectWoo 把「縣/市」加強成 select2，畫面上真正看得到的是 <select>
     * 旁邊那個 <span class="select2-container">，原本的 <select> 反而是隱藏的。selectWoo 只有
     * 在「對同一個元素重新初始化」時才會順手 destroy 掉舊實例（靠元素身上的 .data('select2')），
     * 直接 replaceWith() 是把舊元素連同那份 data 一起拔掉，容器就變成孤兒留在畫面上，還握著
     * 上一次渲染的選項；之後核心切回台灣時再建一個新的容器，使用者看到的仍是上面那個舊的、
     * 怎麼點都不會變。核心 country-select.js 換欄位型態前一律先
     * `$parent.find('.select2-container').remove()`（見該檔 states 為空與 else 兩個分支），
     * 這裡比照辦理。
     */
    function twshopSwapAddressField($old, $new) {
        var $row = $old.closest('.form-row');
        if ($row.length) $row.find('.select2-container').remove();
        $old.replaceWith($new);
    }

    /**
     * 讓 WooCommerce 核心重新把 select2 套到新建立的「縣/市」選單上：直接 trigger 核心自己的
     * country_to_state_changed 事件（core country-select.js 掛在上面的 handler 就是
     * wc_country_select_select2()），而不是自己呼叫 selectWoo()——placeholder、i18n 文案、
     * width 全部跟核心渲染出來的選單一致，日後核心改設定也不需要兩邊同步。核心 cart.js 也是
     * 用同一招。不會無限遞迴：本檔案掛在同一個事件上的 handler 只會在「型態需要改變」時才
     * 再次呼叫這裡，重跑一輪時型態已經正確、直接 return。
     */
    function twshopEnhanceStateSelect() {
        $(document.body).trigger('country_to_state_changed');
    }

    /**
     * 依「目前選的運送方式是否勾了地址連動」切換 city 欄位的型態：
     * 勾選 → 從文字輸入換成下拉選單（並灌入目前縣市的鄉鎮市區清單，保留原本填的值）；
     * 沒勾選/沒選運送方式 → 從下拉選單換回文字輸入（一樣保留目前的值）。
     *
     * PHP 端 twshop_taiwan_city_field_as_select() 只在「頁面第一次載入當下、session 已經
     * 記得上次選的運送方式」才會把 city 直接渲染成 select；使用者在頁面上第一次選運送方式、
     * 或改選到另一個運送方式時，session 要等 AJAX 更新完才會反映，這段期間的欄位型態切換
     * 一定要靠這裡的 JS 補上，不然要重新整理頁面才會生效。
     *
     * 用 replaceWith() 整個換掉元素（而非改 <select>/<input> 本身的 tag），比照 WooCommerce
     * 核心 country-select.js 切換「縣/市」欄位型態的既有寫法，維持 name/id/class 不變，
     * 表單驗證與其他程式碼靠這些屬性認欄位的邏輯不受影響。
     */
    function twshopToggleCityFieldType(prefix) {
        var $city = $('#' + prefix + '_city');
        if (!$city.length) return;
        if (!twshopHasShippingMethodInputs()) return;

        // 國別不是台灣時一律換回自由輸入文字：台灣鄉鎮市區的清單套在其他國家的地址上毫無意義
        // （這是 CLAUDE.md 原本記載的「已知未解限制」，2026-09-04 一併補上）。
        var shouldBeSelect = twshopIsAddressLinkageChosen() && twshopIsTwCountry(prefix);
        var isSelect = $city.is('select');
        if (shouldBeSelect === isSelect) return;

        var value = $city.val();
        var inputClasses = twshopFieldInputClasses($city);
        var $newCity;
        if (shouldBeSelect) {
            $newCity = $('<select>')
                .prop('name', $city.attr('name'))
                .prop('id', $city.attr('id'))
                .addClass('select ' + inputClasses);
            twshopSwapAddressField($city, $newCity);
            twshopPopulateCityOptions(prefix, value);
        } else {
            $newCity = $('<input type="text" />')
                .prop('name', $city.attr('name'))
                .prop('id', $city.attr('id'))
                .addClass('input-text ' + inputClasses)
                .val(value);
            twshopSwapAddressField($city, $newCity);
        }
    }

    // 22 縣市代碼 → 名稱，跟 PHP 端 twshop_add_taiwan_states() 的資料一致（各自獨立維護，
    // 理由同 TWSHOP_TAIWAN_DISTRICTS 檔頭的說明）。只有這裡（切換「縣/市」欄位型態時
    // 自己建立選單）用得到；PHP 端渲染出來的選單本身不需要 JS 重建。
    var TWSHOP_TAIWAN_STATES = {
        'TPE': '臺北市', 'NTP': '新北市', 'TYC': '桃園市', 'TXG': '臺中市',
        'TNN': '臺南市', 'KHH': '高雄市', 'KEE': '基隆市', 'HSZ': '新竹市',
        'HSQ': '新竹縣', 'MIA': '苗栗縣', 'CHA': '彰化縣', 'NAN': '南投縣',
        'YUN': '雲林縣', 'CYI': '嘉義市', 'CYQ': '嘉義縣', 'PIF': '屏東縣',
        'YIL': '宜蘭縣', 'HUA': '花蓮縣', 'TTT': '臺東縣', 'PEN': '澎湖縣',
        'KIN': '金門縣', 'LIE': '連江縣'
    };

    /**
     * 依「目前選的運送方式是否勾了地址連動」切換「縣/市」欄位的型態，寫法與
     * twshopToggleCityFieldType() 完全對稱。PHP 端 twshop_add_taiwan_states() 現在也依同一個
     * 開關決定要不要回傳台灣縣市清單給 WooCommerce（不回傳時 state 欄位退回原生文字輸入），
     * 「縣市、鄉鎮市區、郵遞區號」三者是同一組連動功能，缺這段的話縣市會維持選單、跟鄉鎮市區
     * 的開關狀態兜不起來。
     *
     * 不倚賴 WooCommerce 核心 country-select.js 的縣市切換邏輯（那是綁在「國別」欄位變動、
     * 讀的是頁面載入當下就固定好的 wc_country_select_params.countries 靜態資料，不會因為
     * 運送方式改變而重新計算），改成跟 city 一樣自己 replaceWith() 整個換掉元素。
     */
    function twshopToggleStateFieldType(prefix) {
        var $state = $('#' + prefix + '_state');
        if (!$state.length) return;
        if (!twshopHasShippingMethodInputs()) return;

        // **國別不是台灣時完全不碰「縣/市」**：這個欄位在其他國家由 WooCommerce 核心
        // country-select.js 依 wc_country_select_params.countries 自己管（香港有分區、中國有
        // 省份），我方再插手，會把核心剛渲染好的該國清單換成文字輸入。台灣以外的行為一律
        // 交還核心，跟 PHP 端 twshop_taiwan_city_field_as_select() 的 `'TW' !== $country`
        // 是同一道判斷。
        if (!twshopIsTwCountry(prefix)) return;

        var shouldBeSelect = twshopIsAddressLinkageChosen();
        var isSelect = $state.is('select');
        if (shouldBeSelect === isSelect) return;

        var value = $state.val();
        var inputClasses = twshopFieldInputClasses($state);
        var $newState;
        if (shouldBeSelect) {
            $newState = $('<select>')
                .prop('name', $state.attr('name'))
                .prop('id', $state.attr('id'))
                .attr('data-input-classes', inputClasses)
                .addClass('state_select ' + inputClasses);
            $newState.append($('<option>', { value: '', text: '請選擇縣/市…' }));
            $.each(TWSHOP_TAIWAN_STATES, function (code, name) {
                $newState.append($('<option>', { value: code, text: name }));
            });
            twshopSwapAddressField($state, $newState);
            $newState.val(value);
            // class 給 state_select ＋ 這裡重新套 select2，是為了讓我方建立的選單跟核心渲染出來的
            // 完全同一種東西——否則核心後續的 wc_country_select_select2()／換國別邏輯都選不到它。
            twshopEnhanceStateSelect();
        } else {
            $newState = $('<input type="text" />')
                .prop('name', $state.attr('name'))
                .prop('id', $state.attr('id'))
                .attr('data-input-classes', inputClasses)
                .addClass('input-text ' + inputClasses)
                .val(value);
            twshopSwapAddressField($state, $newState);
        }
    }

    /**
     * 郵遞區號欄位現在是不是「自動帶入＋隱藏」狀態。
     *
     * 判斷依據跟 twshopToggleCityFieldType() 一致（連動運送方式 ＋ 國別是台灣），唯一的差別是
     * **頁面上根本沒有運送方式可選時（「我的帳號 ▸ 編輯地址」頁）改以 PHP 端渲染出來的結果為準**：
     * 那一頁 twshopIsAddressLinkageChosen() 一定回傳 false（沒有 radio 可讀），若照它的答案動作，
     * 會在 document ready 當下把 PHP 已經正確藏起來的郵遞區號欄位又掀開來，理由同
     * twshopHasShippingMethodInputs() 的說明。
     */
    function twshopPostcodeAutoActive(prefix) {
        if (!twshopIsTwCountry(prefix)) return false;
        if (!twshopHasShippingMethodInputs()) {
            return $('#' + prefix + '_postcode_field').hasClass('twshop-postcode-auto');
        }
        return twshopIsAddressLinkageChosen();
    }

    /**
     * 依已選的「縣/市 ＋ 鄉鎮市區」把郵遞區號填進（隱藏的）郵遞區號欄位，並同步切換該欄位列的
     * 隱藏狀態。**方向跟這個功能最早的版本相反**：原本是顧客自己輸入郵遞區號、由 JS 反查帶出
     * 縣市與鄉鎮市區；現在縣市/鄉鎮市區都是下拉選單，選完就唯一決定郵遞區號，改成由這裡自動
     * 帶入、欄位不再顯示給顧客填（伺服器端的對應處理見 PHP 的 twshop_taiwan_hide_postcode_field()）。
     *
     * `removeAttr('required')` 不能省：PHP 端渲染時已經 `required = false`，但
     * twshop-frontend.js 的超商取貨切換（twshopCvsToggleAddress）在「非超商」分支會把
     * required 一律加回地址欄位，隱藏欄位帶著 required 會讓瀏覽器原生驗證擋下送出又無法對焦，
     * 畫面上不會有任何提示。那支檔案也做了對稱的處理，這裡再保一次，兩邊誰先跑都不會出事。
     *
     * 值真的改變時才 trigger('change')：結帳頁的運費/稅率試算是靠郵遞區號等欄位的 change
     * 觸發 update_checkout，值沒變就不必多送一次 AJAX。也因為「沒變就不 trigger」，
     * update_checkout → updated_checkout → 再跑一次這裡 的循環會在第二輪自然停下來。
     */
    function twshopSyncPostcodeFromAddress(prefix) {
        var $postcode = $('#' + prefix + '_postcode');
        if (!$postcode.length) return;

        var active = twshopPostcodeAutoActive(prefix);

        // 只有「頁面上真的有運送方式可選」時才由前端接手切換顯示狀態，否則維持 PHP 渲染的樣子
        if (twshopHasShippingMethodInputs()) {
            $('#' + prefix + '_postcode_field').toggleClass('twshop-postcode-auto', active);
        }
        if (!active) return;

        $postcode.removeAttr('required');

        // **查不到就清空，不能保留原值**：查不到只有兩種情況——鄉鎮市區還沒選，或它跟目前的
        // 縣市對不起來（舊資料，或顧客換了縣市）。這兩種情況留著上一輪算出來的郵遞區號，
        // 就會變成「高雄市 ＋ 大安區 ＋ 106」這種三個欄位互相矛盾、顧客又看不到的隱藏錯誤。
        // 寧可空白讓伺服器端的驗證擋下來（twshop_validate_taiwan_district_match()），
        // 也不要送出一個看起來很正常的錯值。
        var zip = TWSHOP_DISTRICT_POSTCODE[($('#' + prefix + '_state').val() || '') + '|' + ($('#' + prefix + '_city').val() || '')] || '';
        if (($postcode.val() || '') === zip) return;

        $postcode.val(zip).trigger('change');
    }

    // =====================================================================
    // 換國別時清空原本填的地址
    // =====================================================================

    /**
     * 換國別要清掉的欄位。**姓名／公司／電話／Email 刻意不清**：這些欄位跨國通用，
     * 使用者只是把國別選錯又改回來，不該把整張表單洗掉；真正跟國家綁死、換了國家就一定
     * 要重填的是下面這五個地址欄位（郵遞區號格式、縣市/州的代碼清單、地址寫法全都不同）。
     */
    var TWSHOP_COUNTRY_BOUND_FIELDS = ['postcode', 'state', 'city', 'address_1', 'address_2'];

    // prefix → 上一次記錄到的國別。用來分辨「使用者真的換了國家」與「值其實沒變」，
    // 頁面載入當下先記一次（見下方 $(function(){...})），不會在載入時就把既有地址清掉。
    var twshopCountryMemo = {};

    function twshopClearCountryBoundFields(prefix) {
        for (var i = 0; i < TWSHOP_COUNTRY_BOUND_FIELDS.length; i++) {
            var $field = $('#' + prefix + '_' + TWSHOP_COUNTRY_BOUND_FIELDS[i]);
            if (!$field.length) continue;

            $field.val('');

            // select2 加強過的選單（「縣/市」）畫面上看得到的是旁邊那個容器，光改底層
            // <select> 的值不會更新顯示。用**帶命名空間**的 change.select2 只喚醒 select2
            // 自己註冊的 handler（selectWoo 的 _registerDomEvents() 就是綁在這個命名空間上），
            // 不會連帶觸發 checkout.js 綁在 `.address-field select` 上的 update_checkout——
            // 換國別本身已經會觸發一次結帳更新，不需要每個欄位再各觸發一次。
            if ($field.is('select') && $field.data('select2')) $field.trigger('change.select2');

            // 清掉 WooCommerce 留在該列上的驗證狀態，否則剛被清空的必填欄位還會頂著
            // 「已驗證通過」的綠框；WC 會在下次 blur／update_checkout 時重新驗證。
            $field.closest('.form-row').removeClass('woocommerce-validated woocommerce-invalid woocommerce-invalid-required-field');
        }
    }

    /**
     * 換國別 → 清空地址欄位。掛在 `change` 而不是核心的 country_to_state_changed：後者在
     * 「舊國家與新國家都沒有 states、欄位本來就是文字輸入」時根本不會被 trigger
     * （見核心 country-select.js 最後那個 else 分支的 `$statebox.is('select, input[type="hidden"]')`
     * 條件），漏掉那種組合就會有幾個國家之間切換不清空。
     *
     * 本檔案的 handler 是在腳本執行當下就用委派掛上 document.body，核心 country-select.js
     * 的則是掛在 jQuery ready 裡，因此**我方一定先跑**：核心的 change handler 開頭會先讀
     * `value = $statebox.val()` 再把它填回重建後的「縣/市」選單，我方先清空，核心讀到的就是
     * 空字串，不會把舊值又帶回去。結帳更新（update_checkout）有 5ms 的 setTimeout 才會序列化
     * 表單，這裡同步清完早於那個時間點，送出去的已經是清空後的值。
     */
    $(document.body).on('change', '#billing_country, #shipping_country', function () {
        var prefix = this.id === 'shipping_country' ? 'shipping' : 'billing';
        var country = $(this).val() || '';
        if (twshopCountryMemo[prefix] === country) return;
        twshopCountryMemo[prefix] = country;
        twshopClearCountryBoundFields(prefix);
    });

    $(function () {
        $('#billing_country, #shipping_country').each(function () {
            var prefix = this.id === 'shipping_country' ? 'shipping' : 'billing';
            twshopCountryMemo[prefix] = $(this).val() || '';
        });
    });

    // 用 event delegation 掛在 document.body，不受結帳頁 AJAX 局部重繪（updated_checkout）
    // 影響——帳單/地址欄位本身不在 update_order_review 替換的範圍內，但用委派比較保險，
    // 也同時涵蓋「我的帳號 ▸ 編輯地址」頁面（billing_* / shipping_* 兩種欄位前綴）。

    // 「縣/市」改變時即時重新灌一次「鄉鎮市區」的選項——這是本檔案能讓「鄉鎮市區」維持
    // 選單而非自由輸入的關鍵：PHP 端只在頁面第一次載入當下渲染正確的清單
    // （twshop_taiwan_city_field_as_select()），使用者手動改變縣市選單並不會觸發結帳頁
    // 的伺服器端重新渲染（那只有改變「國別」才會透過 update_order_review AJAX 整段換掉），
    // 不補上這段，換了縣市之後鄉鎮市區清單就會停在舊縣市、選不到新縣市的行政區。
    //
    // 灌完選項再同步一次郵遞區號：換縣市會讓原本選中的鄉鎮市區被保留或被清掉，兩種情況
    // 對應的郵遞區號都不一樣，一定要在 twshopPopulateCityOptions() 之後才算得準。
    //
    // **顧客真的把縣市換掉時，不屬於新縣市的鄉鎮市區必須清掉**（2026-09 修正，實測踩過）：
    // twshopPopulateCityOptions() 預設會把「目前這個值」保留成一個額外 option 並維持選中，
    // 那是為了不要無聲丟掉顧客帳號裡的舊資料（行政區劃調整前的值、或本來就不在標準清單裡的），
    // 但套在「臺北市 大安區 → 改成高雄市」這種情況就變成畫面上留著「高雄市 ＋ 大安區」，
    // 而且看起來完全正常——WooCommerce 沒有任何跨欄位驗證，顧客按下結帳就直接成立一筆
    // 地址不存在的訂單；郵遞區號更糟，`KHH|大安區` 查不到會讓 twshopSyncPostcodeFromAddress()
    // 直接放棄、隱藏欄位留著上一個縣市的 106，三個欄位互相矛盾且顧客看不到。
    //
    // **只在「值真的變了」時才清掉**，靠 twshopStateMemo 分辨，理由跟 twshopCountryMemo 一樣：
    // WooCommerce 核心 country-select.js 重建「縣/市」選單後會用**原本的值**再 trigger 一次
    // change（該檔 `$statebox.val( value ).trigger( 'change' )`），我方的
    // twshopEnhanceStateSelect() 也會經由 country_to_state_changed 走到那一段。這種「值沒變、
    // 只是元素被重建」的 change 若照樣清掉鄉鎮市區，就會把上面說的那種舊資料無聲丟掉，
    // 正好是這段邏輯本來要避免的事。
    var twshopStateMemo = {};

    function twshopDistrictInState(stateCode, district) {
        if (!district) return true; // 空值本來就沒有「跟縣市對不起來」的問題
        return (TWSHOP_TAIWAN_DISTRICTS[stateCode] || []).indexOf(district) !== -1;
    }

    $(document.body).on('change', '#billing_state, #shipping_state', function () {
        var prefix = this.id === 'shipping_state' ? 'shipping' : 'billing';
        var state = $(this).val() || '';
        var stateChanged = twshopStateMemo[prefix] !== undefined && twshopStateMemo[prefix] !== state;
        twshopStateMemo[prefix] = state;

        var district = $('#' + prefix + '_city').val() || '';
        var dropDistrict = stateChanged && !twshopDistrictInState(state, district);

        // 傳空字串 = 不保留目前的值，鄉鎮市區退回「請先選擇縣市」佔位選項；
        // 傳 undefined = 沿用 twshopPopulateCityOptions() 既有的「保留目前值」行為。
        twshopPopulateCityOptions(prefix, dropDistrict ? '' : undefined);
        twshopSyncPostcodeFromAddress(prefix);
    });

    // 「鄉鎮市區」改變 → 郵遞區號跟著換。這是隱藏郵遞區號欄位之後，值唯一的日常來源
    // （伺服器端 twshop_fill_taiwan_postcode_posted_data() 只是 JS 沒跑時的最後防線）。
    $(document.body).on('change', '#billing_city, #shipping_city', function () {
        var prefix = this.id === 'shipping_city' ? 'shipping' : 'billing';
        twshopSyncPostcodeFromAddress(prefix);
    });

    // 依序切換「縣/市」→「鄉鎮市區」→「郵遞區號」，順序刻意固定：twshopPopulateCityOptions()
    // 灌選項時是讀當下 state 欄位的值決定要顯示哪個縣市的清單，state 一定要先換完型態（含還原/
    // 清空選中值）鄉鎮市區才有正確的依據可以參考；郵遞區號又是讀前兩者的值算出來的，排最後。
    function twshopToggleAddressFieldTypes(prefix) {
        twshopToggleStateFieldType(prefix);
        twshopToggleCityFieldType(prefix);
        twshopSyncPostcodeFromAddress(prefix);
    }

    // 頁面載入當下也跑一次：伺服器端渲染時「縣/市」欄位實際顯示的值（可能來自結帳驗證失敗
    // 後的表單重填、或 WooCommerce 自己的預設地區設定）不一定跟 twshop_taiwan_city_field_as_select()
    // 當下讀到的 WC()->customer 狀態完全一致，這裡以瀏覽器畫面上真正顯示的縣市為準重灌一次，
    // 確保兩邊不會兜不起來。
    $(function () {
        twshopToggleAddressFieldTypes('billing');
        twshopToggleAddressFieldTypes('shipping');
        // 記下頁面載入當下的縣市，之後才分辨得出「顧客真的換了縣市」與「元素被重建、值其實沒變」
        // （見上方 twshopStateMemo）。放在切換欄位型態之後：型態切換會重建「縣/市」元素。
        $('#billing_state, #shipping_state').each(function () {
            var prefix = this.id === 'shipping_state' ? 'shipping' : 'billing';
            twshopStateMemo[prefix] = $(this).val() || '';
        });
        twshopPopulateCityOptions('billing');
        twshopPopulateCityOptions('shipping');
        // 重灌選項可能改變「鄉鎮市區」目前選中的值，郵遞區號要跟著重算一次
        twshopSyncPostcodeFromAddress('billing');
        twshopSyncPostcodeFromAddress('shipping');
    });

    // 運送方式改變（含第一次選定）時，依是否勾了「台灣地址下拉選單連動」切換
    // 「縣/市」「鄉鎮市區」兩個欄位的型態。跟 twshop-frontend.js 的超商取貨切換
    // （twshopCvsToggleAddress）綁在同一組事件上（updated_checkout／shipping_method 的
    // change），兩支各自獨立的 handler 互不干擾，jQuery 同一個事件可以掛多個 handler。
    $(document.body).on('updated_checkout', function () {
        twshopToggleAddressFieldTypes('billing');
        twshopToggleAddressFieldTypes('shipping');
    });
    $(document.body).on('change', 'input[name^="shipping_method"]', function () {
        twshopToggleAddressFieldTypes('billing');
        twshopToggleAddressFieldTypes('shipping');
    });

    // 切換國別時，核心 country-select.js 換完「縣/市」欄位就會 trigger 這個事件。一定要掛在
    // 這裡、不能只靠 updated_checkout：
    // (1) updated_checkout 是 update_order_review AJAX 回來之後才觸發，慢一拍；
    // (2) 更重要的是核心是依 wc_country_select_params.countries 這份**頁面載入當下就固定的
    //     快照**決定要渲染成選單還是文字輸入，而 woocommerce_states filter 每個請求只會被
    //     WC_Countries::load_country_states() 評估一次並快取起來——頁面載入當下若還沒選到有勾
    //     連動的運送方式，快照裡就沒有 TW 這個 key，之後切到別的國家再切回台灣，核心會把
    //     欄位換成文字輸入，得由這裡立刻把台灣縣市選單補回來。
    $(document.body).on('country_to_state_changed', function () {
        twshopToggleAddressFieldTypes('billing');
        twshopToggleAddressFieldTypes('shipping');
        twshopPopulateCityOptions('billing');
        twshopPopulateCityOptions('shipping');
        // 重灌選項可能改變「鄉鎮市區」目前選中的值，郵遞區號要跟著重算一次
        twshopSyncPostcodeFromAddress('billing');
        twshopSyncPostcodeFromAddress('shipping');
    });
})(jQuery);
