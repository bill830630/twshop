<?php
/**
 * 商品網址（slug）自動改用商品編號
 *
 * WordPress 的 sanitize_title() 對中文標題不做音譯，直接保留 UTF-8 字元，存進
 * wp_posts.post_name 後在網址上就是 percent-encoding，商品網址會長成
 * /product/%e8%b6%85%e8%b2%b4%e7%9a%84%e6%9d%b1%e8%a5%bf/ 這種樣子。本模組提供一個
 * 站台層級的開關（wc_product_slug_use_id），啟用後商品的 slug 一律等於它的 post ID
 * （/product/486/），既有商品可在後台批次轉換，之後新建/編輯的商品也會自動維持。
 *
 * **關閉開關會自動還原**：轉換前的 slug 記在 _twshop_original_slug postmeta 裡
 * （見 twshop_enforce_product_id_slug()），開關關掉後同一套批次機制會反方向跑，
 * 把 slug 換回原本的值。
 *
 * **不綁任何模組開關**：這是站台層級的網址設定，跟任何一個功能模組都沒有邏輯關聯
 * ——關掉「折扣規則」的人不會預期商品網址跟著變回中文。掛載處見 includes/init.php
 * 「商品網址（slug）改用商品編號」那一段，比照同樣不綁模組的「商品折扣徽章」。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 記錄「轉換成 ID 之前的原始 slug」的 postmeta key。這是「關閉開關自動還原」唯一可靠的依據。
 *
 * **為什麼不能只靠核心的 `_wp_old_slug`**：那個 meta 是可以有多筆的累積清單（核心每次改
 * slug 都 append 一筆），分不出哪一筆才是「轉換前的原始值」；而且它的用途是舊網址的 301
 * 對照表，不是狀態記錄。兩者職責不同，各自獨立維護。
 */
define( 'TWSHOP_ORIGINAL_SLUG_META', '_twshop_original_slug' );

// 每批處理的商品數。沿用蝦皮推送佇列的 50，站內保持一致。
define( 'TWSHOP_SLUG_BATCH_SIZE', 50 );

/**
 * 會被處理的商品狀態白名單。
 *
 * **刻意用白名單而不是 `NOT IN ( 'auto-draft', 'trash' )`**：後者會把第三方外掛註冊的
 * 自訂文章狀態一起吃進來，行為變成「撿到什麼算什麼」。列舉出來才是刻意的選擇。
 *
 * `trash` 不在裡面是關鍵：`wp_trash_post()` 會透過
 * `wp_add_trashed_suffix_to_post_name_for_post()` 把 post_name 改成 `486__trashed`
 * 並另存 `_wp_desired_post_slug`，我方若跟著改就會把那個後綴洗掉，商品從垃圾桶還原時
 * 拿不回原本的 slug。
 */
function twshop_product_slug_statuses() {
    return array( 'publish', 'future', 'draft', 'pending', 'private' );
}

/**
 * 實際把 post_name 寫進資料庫，並維護核心的 `_wp_old_slug` 舊網址對照。
 *
 * **為什麼用 `$wpdb->update()` 而不是 `wp_update_post()`**：後者會再觸發一輪
 * save_post / wp_insert_post（我方自己的 hook 也掛在上面），還會產生多餘的 revision。
 * WooCommerce 自己在 `WC_Product_Data_Store_CPT::update()` 就是為了同一個理由走 $wpdb，
 * 該處原文：「to prevent infinite loops, use $wpdb to update data, since wp_update_post
 * spawns more calls to the save_post action」。
 *
 * **繞過 wp_update_post() 的代價是要自己補 `_wp_old_slug`**：核心的
 * `wp_check_for_changed_slugs()` 掛在 `post_updated`，繞過去它就不會跑，舊網址的 301
 * 會整個失效——顧客收藏的、搜尋引擎收錄的、已寄出的通知信裡的商品連結全部變成 404，
 * 而且不會有任何錯誤訊息。這裡逐條比照核心那支的邏輯自己補：只對 publish 狀態、舊 slug
 * 非空、且該值尚未記錄過時才寫入；若新 slug 曾出現在舊清單裡則把那筆刪掉（否則會變成
 * 「新網址被記為舊網址」，301 打架）。
 *
 * 舊 slug 是中文 percent-encoding 也照樣成立：`_find_post_by_old_slug()`（wp-includes/query.php）
 * 是拿 `meta_value = get_query_var( 'name' )` 直接字串比對，而 `sanitize_title_with_dashes()`
 * 會保留 %xx 八位元組並對整串 strtolower()，`WP_Query::get_posts()` 又對 $q['name']
 * （是 $this->query_vars 的參考）再跑一次 `sanitize_title_for_query()`——資料庫存的、
 * 查詢用的、這裡寫進 meta 的三者是同一種小寫 percent-encoding 形式。
 *
 * @return bool 是否真的改了（值本來就一樣時回傳 false）
 */
function twshop_write_product_slug( $post_id, $new_slug ) {
    global $wpdb;

    $post = get_post( $post_id );
    if ( ! $post ) return false;

    $old_slug = (string) $post->post_name;
    if ( $old_slug === $new_slug ) return false;

    if ( 'publish' === $post->post_status ) {
        $old_slugs = (array) get_post_meta( $post_id, '_wp_old_slug' );
        if ( '' !== $old_slug && ! in_array( $old_slug, $old_slugs, true ) ) {
            add_post_meta( $post_id, '_wp_old_slug', $old_slug );
        }
        if ( in_array( $new_slug, $old_slugs, true ) ) {
            delete_post_meta( $post_id, '_wp_old_slug', $new_slug );
        }
    }

    $wpdb->update( $wpdb->posts, array( 'post_name' => $new_slug ), array( 'ID' => $post_id ) );
    clean_post_cache( $post_id );

    return true;
}

/**
 * 存檔時強制把商品的 slug 換成商品編號。**這支函式必須是冪等的**——它同時掛在
 * `wp_insert_post` 與 WooCommerce 的 `woocommerce_new_product`／`woocommerce_update_product`
 * 上，同一次存檔很可能兩邊都會跑到，重複執行只會多一次字串比較。
 *
 * **為什麼兩組 hook 都要掛**（已讀 WooCommerce 原始碼確認）：
 * `WC_Product_Data_Store_CPT::update()` 在 `doing_action( 'save_post' )` 為 true 時
 * （後台商品編輯頁存檔走的就是這條）**直接用 `$GLOBALS['wpdb']->update()` 寫 post 資料列，
 * 不會觸發 `wp_insert_post` action**。只掛 wp_insert_post 會漏掉「第三方外掛拿著存檔前
 * 讀進記憶體的舊 WC_Product 物件再存一次」這種把舊 slug 寫回去的情況；反過來只掛
 * WooCommerce 的 action，則會漏掉不經 CRUD、直接呼叫 wp_insert_post() 的匯入工具。
 * 兩組都掛就不必逐一驗證 CSV 匯入器／商品複製／REST／WP-CLI／快速編輯各走哪條路。
 *
 * 幾個刻意跳過的情況：
 * - **product_variation 完全不碰**：規格的 slug 是內部用的，不是公開網址。
 * - **auto-draft / trash 不碰**：不跟核心的垃圾桶改名機制（`{slug}__trashed`）打架。
 * - **草稿的空 slug 不碰**：核心刻意讓草稿的 post_name 保持空字串（wp-includes/post.php：
 *   「Drafts and pending posts are allowed to have an empty post name」），slug 是發布當下
 *   才產生的，我方不該提早幫草稿生一個。草稿發布時這支函式會再跑一次，那時才換。
 *
 * @param int          $post_id
 * @param WP_Post|null $post 由 wp_insert_post 傳入；WooCommerce 的 hook 只傳 ID，這裡自己查
 */
function twshop_enforce_product_id_slug( $post_id, $post = null ) {
    if ( ! twshop_product_slug_use_id_enabled() ) return;

    $post = ( $post instanceof WP_Post ) ? $post : get_post( $post_id );
    if ( ! $post || 'product' !== $post->post_type ) return;
    if ( in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) return;
    if ( '' === $post->post_name && in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) return;

    $new_slug = (string) $post->ID;
    if ( $post->post_name === $new_slug ) return; // 冪等：已經是編號就什麼都不做

    // 第四個參數 true = unique，已經記錄過就不覆寫。這很重要：開關開著的期間管理員若手動
    // 改過 slug，我方會馬上蓋回編號，但「原始值」仍應該是最初那一個，不是他中途打的那個。
    add_post_meta( $post->ID, TWSHOP_ORIGINAL_SLUG_META, $post->post_name, true );

    twshop_write_product_slug( $post->ID, $new_slug );
}

/**
 * 把單一商品的 slug 還原成 `_twshop_original_slug` 記錄的值，並刪掉該筆 meta。
 *
 * 刪掉 meta 是還原批次的終止條件（「還剩幾筆」＝ 還有幾個商品帶著這個 meta），所以
 * **不論還原成功與否都要刪**，否則批次會卡在同一筆無限重跑。
 *
 * 兩個要處理的情況：
 * - **原始 slug 可能已經被別的商品佔走**（轉換之後又新建了同名商品），所以寫回去之前
 *   一定要過一次 `wp_unique_post_slug()`，不然兩個商品共用同一個 slug，WordPress 只認得
 *   其中一個，另一個永遠打不開。
 * - **原始值是空字串**（理論上碰不到——草稿的空 slug 根本不會被轉換，但防一手）：
 *   改用標題重新產生，標題也是空的就維持編號。
 */
function twshop_restore_product_slug( $post_id, $original_slug ) {
    $post = get_post( $post_id );
    if ( ! $post ) {
        delete_post_meta( $post_id, TWSHOP_ORIGINAL_SLUG_META );
        return;
    }

    $target = (string) $original_slug;
    if ( '' === $target ) $target = sanitize_title( $post->post_title );
    if ( '' === $target ) $target = (string) $post_id;

    $target = wp_unique_post_slug( $target, $post_id, $post->post_status, $post->post_type, $post->post_parent );

    twshop_write_product_slug( $post_id, $target );
    delete_post_meta( $post_id, TWSHOP_ORIGINAL_SLUG_META );
}

/**
 * 目前批次要跑哪個方向：開關開著就是「轉換」，關著就是「還原」。
 *
 * **刻意不用 `update_option_*` 之類的 transition hook 記狀態**：方向與待處理數都是從
 * 「當下的 option 值 ＋ 資料庫現況」即時算出來的，沒有任何進度狀態要維護，也就沒有
 * 「錯過那一次 transition 就永遠卡住」的狀態機問題。使用者中途關掉瀏覽器、直接改 option、
 * 甚至用 WP-CLI 改，下次進設定頁看到的數字都還是對的。
 */
function twshop_product_slug_batch_mode() {
    return twshop_product_slug_use_id_enabled() ? 'convert' : 'restore';
}

/**
 * 還有幾個商品要處理（依目前方向）。跟批次查詢用同一組條件。
 *
 * **只在頁面載入時算一次**，不要每批都算：轉換方向的條件含
 * `post_name <> CAST( ID AS CHAR )` 這個欄位對欄位比較，吃不到 `post_name` 索引，
 * 每一列都要回表。每批都重算一次的話，5000 個商品跑 100 批就是 50 萬次無謂讀取。
 * 批次進行中的剩餘數由前端自己遞減，是否跑完則看批次回傳的 `done`。
 */
function twshop_count_pending_product_slugs() {
    global $wpdb;

    $statuses     = twshop_product_slug_statuses();
    $placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

    if ( 'convert' === twshop_product_slug_batch_mode() ) {
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND post_status IN ( {$placeholders} )
               AND post_name <> CAST( ID AS CHAR )
               AND NOT ( post_name = '' AND post_status IN ( 'draft', 'pending' ) )",
            $statuses
        ) );
    }

    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
         WHERE p.post_type = 'product'
           AND p.post_status IN ( {$placeholders} )",
        array_merge( array( TWSHOP_ORIGINAL_SLUG_META ), $statuses )
    ) );
}

/**
 * 跑一批。`$after_id` 是上一批回傳的 `last_id`，用來當游標。
 *
 * **為什麼要游標**：不帶游標的話每一批都從頭重新掃描一次符合條件的商品，而轉換方向的
 * 條件吃不到索引，整體是 O(n²/batch)——5000 個商品跑 100 批 × 每批掃 5000 列 = 50 萬次
 * 無謂讀取。因為我們是照 ID 遞增逐批處理、且每批都會把撈到的全部處理掉，
 * 「ID <= 上一批最大 ID」的部分保證已經處理完，直接跳過是安全的。
 *
 * 游標由前端帶回來，**伺服器端仍然零狀態**：使用者中途離開、回來時游標從 0 重新開始，
 * 第一批多掃一輪就會找到剩下的，正確性不受影響。
 *
 * **一定要走原生 SQL，不能用 `wp_update_post()` 或 `$product->save()`**，三個理由：
 * 1. **蝦皮佇列會被灌爆**：`includes/init.php` 在 `shopee_sync` 啟用時把
 *    `woocommerce_update_product` 接到 `twshop_shopee_queue_push_from_hook()`，走 CRUD
 *    會讓上千個商品全部進 `twshop_shopee_push_queue`（每筆一次 get_option + update_option），
 *    然後被 5 分鐘 cron 全量推去蝦皮。
 * 2. **第三方的 `save_post`** —— SEO 外掛重建索引、快取外掛清頁、CDN purge，全部會被觸發 N 次。
 * 3. **速度**：`wp_update_post()` 每一筆要跑完整的 sanitize 與 term/meta hook，比
 *    `$wpdb->update` 慢一到兩個數量級。
 *
 * 代價就是核心的 `wp_check_for_changed_slugs()` 不會跑、`_wp_old_slug` 要自己補——
 * 那正是 twshop_write_product_slug() 裡那段邏輯存在的理由。
 *
 * @return array fetched（撈到幾列）／processed（實際處理幾筆）／last_id（游標）／done（是否跑完）
 */
function twshop_run_product_slug_batch( $after_id = 0, $limit = TWSHOP_SLUG_BATCH_SIZE ) {
    global $wpdb;

    $after_id     = max( 0, (int) $after_id );
    $limit        = max( 1, (int) $limit );
    $statuses     = twshop_product_slug_statuses();
    $placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

    $processed = 0;
    $last_id   = $after_id;

    if ( 'convert' === twshop_product_slug_batch_mode() ) {
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND post_status IN ( {$placeholders} )
               AND ID > %d
               AND post_name <> CAST( ID AS CHAR )
               AND NOT ( post_name = '' AND post_status IN ( 'draft', 'pending' ) )
             ORDER BY ID ASC
             LIMIT %d",
            array_merge( $statuses, array( $after_id, $limit ) )
        ) );

        $fetched = count( $ids );

        foreach ( $ids as $id ) {
            $last_id = max( $last_id, (int) $id );

            $post = get_post( (int) $id );
            if ( ! $post ) continue;

            add_post_meta( $post->ID, TWSHOP_ORIGINAL_SLUG_META, $post->post_name, true );
            twshop_write_product_slug( $post->ID, (string) $post->ID );
            $processed++;
        }
    } else {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, m.meta_value FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_type = 'product'
               AND p.post_status IN ( {$placeholders} )
               AND p.ID > %d
             ORDER BY p.ID ASC
             LIMIT %d",
            array_merge( array( TWSHOP_ORIGINAL_SLUG_META ), $statuses, array( $after_id, $limit ) )
        ) );

        $fetched = count( $rows );

        foreach ( $rows as $row ) {
            $last_id = max( $last_id, (int) $row->ID );
            twshop_restore_product_slug( (int) $row->ID, (string) $row->meta_value );
            $processed++;
        }
    }

    return array(
        'fetched'   => $fetched,
        'processed' => $processed,
        'last_id'   => $last_id,
        // 撈不滿一批 = 游標之後沒東西了。用「撈到幾列」而不是「處理了幾筆」判斷，
        // 中間有列處理失敗（例如 get_post() 拿不到）時才不會誤判成還沒跑完而無限重試。
        'done'      => $fetched < $limit,
    );
}

/**
 * 批次的 AJAX 入口。**方向由伺服器端自己讀開關決定，不從前端傳**——前端傳方向的話，
 * 使用者在另一個分頁把開關改掉就會讓兩邊不同步，變成「明明關掉了卻還在往 ID 轉」。
 */
function twshop_ajax_batch_product_slugs() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'msg' => '權限不足' ) );
    check_ajax_referer( 'twshop_admin_action', 'twshop_nonce' );

    $after_id = isset( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;
    $mode     = twshop_product_slug_batch_mode();
    $result   = twshop_run_product_slug_batch( $after_id );

    wp_send_json_success( array(
        'mode'      => $mode,
        'processed' => $result['processed'],
        'lastId'    => $result['last_id'],
        'done'      => $result['done'],
    ) );
}

/**
 * 商品的 404 猜測改成嚴格比對（只在本功能啟用、且查的是純數字的商品 slug 時）。
 *
 * `redirect_guess_404_permalink()`（wp-includes/canonical.php）預設是**前綴模糊比對**
 * （`post_name LIKE '<name>%'`）。中文 slug 時代這幾乎不會誤中，但換成數字之後
 * `/product/48/` 會前綴命中 `480`、`486`…，然後 301 到一個**完全不相干的商品**——
 * 顧客不會發現自己看的不是原本要找的東西。這是把 slug 換成數字才出現的新失敗模式，
 * 所以防呆也只在這個前提下開啟。
 *
 * 範圍刻意收到最窄：只有 post_type 是 product、且 name 是純數字時才回 true，
 * 其他 post type 與非數字 slug 的既有行為完全不動。
 */
function twshop_product_slug_strict_404_guess( $strict ) {
    if ( ! twshop_product_slug_use_id_enabled() ) return $strict;
    if ( 'product' !== get_query_var( 'post_type' ) ) return $strict;
    if ( ! preg_match( '/^[0-9]+$/', (string) get_query_var( 'name' ) ) ) return $strict;

    return true;
}
