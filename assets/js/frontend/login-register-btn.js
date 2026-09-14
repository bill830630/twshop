/**
 * 登入／註冊按鈕文字覆寫。佈景主題彈窗的按鈕是點開才 clone 進 DOM 的，所以要用 MutationObserver 監看。
 *
 * 自 cart-injection.php 的內嵌 <script> 抽出（2026-08-21）。
 */
( function () {
    var loginText = twshopLoginRegister.loginText;
    var registerText = twshopLoginRegister.registerText;

    function setFirstTextNode( el, text ) {
        for ( var i = 0; i < el.childNodes.length; i++ ) {
            if ( el.childNodes[ i ].nodeType === 3 ) {
                el.childNodes[ i ].nodeValue = text;
                return;
            }
        }
        el.insertBefore( document.createTextNode( text ), el.firstChild );
    }

    function applyIn( root ) {
        if ( ! root.querySelectorAll ) return;
        if ( loginText ) {
            root.querySelectorAll( '.woocommerce-form-login__submit' ).forEach( function ( el ) {
                el.textContent = loginText;
            } );
            root.querySelectorAll( '.ct-account-login-submit' ).forEach( function ( el ) {
                setFirstTextNode( el, loginText );
            } );
        }
        if ( registerText ) {
            root.querySelectorAll( '.woocommerce-form-register__submit' ).forEach( function ( el ) {
                el.textContent = registerText;
            } );
            root.querySelectorAll( '.ct-account-register-submit' ).forEach( function ( el ) {
                setFirstTextNode( el, registerText );
            } );
        }
    }

    applyIn( document );

    // 監看目標縮小為 Blocksy 的 .ct-drawer-canvas（而非整個 document.body subtree），
    // 大幅降低全站每頁常駐的 MutationObserver 成本。理由見上方函式說明：Blocksy 的
    // account.js（static/js/account.js registerDynamicChunk('blocksy_account', ...)）
    // 一律用 document.querySelector('.ct-drawer-canvas').insertAdjacentHTML('beforeend', ...)
    // 把彈窗內容插入這個固定容器，而 .ct-drawer-canvas 本身由 blocksy 佈景主題的
    // blocksy_output_drawer_canvas()（inc/footer.php）在頁尾伺服器端直接輸出、頁面載入
    // 當下就已存在於 DOM 中（不像彈窗內容本身要點擊才 clone 進來），因此可以安全地把
    // MutationObserver 的觀察範圍收斂到這個容器，不會錯過彈窗第一次被點開的插入事件。
    // 找不到這個容器時（理論上不應發生，但佈景主題版本/設定變動是外部風險）退回觀察
    // 整個 document.body，維持原本一定能捕捉到節點的行為，不讓文字覆蓋功能悄悄失效。
    var observeTarget = document.querySelector( '.ct-drawer-canvas' ) || document.body;

    new MutationObserver( function ( mutations ) {
        mutations.forEach( function ( m ) {
            m.addedNodes.forEach( function ( node ) {
                if ( node.nodeType === 1 ) applyIn( node );
            } );
        } );
    } ).observe( observeTarget, { childList: true, subtree: true } );
} )();
