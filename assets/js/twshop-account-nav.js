/* 會員中心頁籤：點擊導覽時以 AJAX 局部更新內容，不重新載入整頁 */
(function ($) {
    'use strict';

    var $nav, $content, isLoading = false;

    // 依後台「頁籤排序與開關」設定的圖示（wc_account_tab_icons）在導覽連結前插入圖示；
    // twshopAccountNavData.tabIcons 是 slug => 完整 SVG 原始碼（PHP 端已從外掛自己 assets/icons/ 下的固定
    // 檔案讀出，見 twshop_get_account_tab_icon_svg()，不是使用者可自由輸入的文字，直接當 HTML 插入無虞）。
    function insertAccountTabIcons() {
        var icons = window.twshopAccountNavData && window.twshopAccountNavData.tabIcons;
        if (!icons || !$nav) return;
        $.each(icons, function (slug, svg) {
            if (!svg) return;
            var $link = $nav.find('.woocommerce-MyAccount-navigation-link--' + slug + ' > a');
            if ($link.length && !$link.find('> .twshop-tab-icon').length) {
                $link.prepend($('<span class="twshop-tab-icon" aria-hidden="true"></span>').html(svg));
            }
        });
    }

    function scrollActiveTabIntoView() {
        if (!document.body.classList.contains('twshop-account-tab-mobile-scroll')) return;
        if (!window.matchMedia('(max-width: 689.98px)').matches) return;
        var active = $nav.find('li.is-active')[0];
        if (active) {
            active.scrollIntoView({ inline: 'center', block: 'nearest' });
        }
    }

    // 有些頁籤（例如 WooCommerce 11.0 原生「願望清單」頁籤，其 woocommerce/wishlist 區塊在渲染當下
    // 才 enqueue 自己的 CSS/JS）的樣式/腳本是只在該頁籤實際輸出時才附加的，本來就不會出現在其他頁籤的
    // <head>/<body> 裡。loadAccountPage() 只取用 AJAX 回應裡 .woocommerce-MyAccount-content／
    // .woocommerce-MyAccount-navigation 的 innerHTML，回應文件其餘部分（含這些 <link>/<script src>）
    // 直接被丟棄，於是第一次用 AJAX 導覽切過去時樣式/腳本根本沒載入、手機版排版跑掉，重新整理整頁
    // （走真正的頁面請求，WordPress 會照該頁籤實際需要重新 enqueue）才會恢復正常。這裡比對回應文件與
    // 目前頁面已有的外部 <link rel=stylesheet>／<script src>，把目前頁面缺少的補插入 <head>，讓任何
    // 頁籤（不限於願望清單，含未來新增的頁籤）第一次用 AJAX 切換過去時也能拿到它需要的資源。
    function syncMissingPageAssets(doc) {
        var existing = {};
        document.querySelectorAll('link[rel="stylesheet"][href], script[src]').forEach(function (el) {
            existing[el.getAttribute('href') || el.getAttribute('src')] = true;
        });

        doc.querySelectorAll('link[rel="stylesheet"][href]').forEach(function (link) {
            var href = link.getAttribute('href');
            if (href && !existing[href]) {
                existing[href] = true;
                var newLink = document.createElement('link');
                newLink.rel = 'stylesheet';
                newLink.href = href;
                document.head.appendChild(newLink);
            }
        });

        doc.querySelectorAll('script[src]').forEach(function (script) {
            var src = script.getAttribute('src');
            if (src && !existing[src]) {
                existing[src] = true;
                var newScript = document.createElement('script');
                newScript.src = src;
                newScript.async = false; // 依原本文件順序依序執行，避免依賴關係跑掉
                if (script.id) newScript.id = script.id;
                document.body.appendChild(newScript);
            }
        });
    }

    function loadAccountPage(url, pushState) {
        if (isLoading) return;
        isLoading = true;
        $content.addClass('twshop-account-loading');

        $.get(url)
            .done(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newNav = doc.querySelector('.woocommerce-MyAccount-navigation');
                var newContent = doc.querySelector('.woocommerce-MyAccount-content');
                var newTitle = doc.querySelector('title');

                if (!newContent) {
                    window.location.href = url;
                    return;
                }

                syncMissingPageAssets(doc);

                $content.html(newContent.innerHTML);
                if (newNav) {
                    $nav.html(newNav.innerHTML);
                }
                if (newTitle) {
                    document.title = newTitle.textContent;
                }
                if (pushState) {
                    window.history.pushState({ twshopAccountNav: true }, '', url);
                }

                // innerHTML 不會執行內含的 <script>，手動補跑一次
                $content.find('script').each(function () {
                    $.globalEval(this.textContent || '');
                });

                insertAccountTabIcons();
                $(document.body).trigger('twshop_account_content_loaded');
                scrollActiveTabIntoView();
            })
            .fail(function () {
                window.location.href = url; // AJAX 失敗則退回一般導航，避免卡住
            })
            .always(function () {
                isLoading = false;
                $content.removeClass('twshop-account-loading');
            });
    }

    $(function () {
        $nav = $('.woocommerce-MyAccount-navigation');
        $content = $('.woocommerce-MyAccount-content');

        if (!$nav.length || !$content.length) return;

        insertAccountTabIcons();
        scrollActiveTabIntoView();

        $nav.on('click', 'a', function (e) {
            var $li = $(this).closest('li');

            // 登出連結、開新分頁的修飾鍵點擊，一律走原生導航
            if ($li.hasClass('woocommerce-MyAccount-navigation-link--customer-logout')) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.which === 2) return;
            if ($li.hasClass('is-active')) { e.preventDefault(); return; }

            var url = $(this).attr('href');
            if (!url) return;

            e.preventDefault();
            loadAccountPage(url, true);
        });

        window.addEventListener('popstate', function () {
            loadAccountPage(window.location.href, false);
        });
    });

})(jQuery);
