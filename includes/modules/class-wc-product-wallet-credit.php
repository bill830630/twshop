<?php
/**
 * 儲值金商品（product type: wallet_credit）的商品類別。
 *
 * 直接繼承 WC_Product_Simple（價格、稅別、庫存、SKU 等 getter/setter 全部沿用，不重寫），
 * 只覆寫 get_type() 讓 WooCommerce 認得這是獨立的商品類型，不是簡單商品——比照競標/預購
 * 這類第三方外掛「商品類型下拉多一個選項」的既有做法，取代 v25.8.67 掛在簡單商品上的
 * `_twshop_wallet_product` checkbox。
 *
 * 這個檔案只在 wallet-topup.php 的 `woocommerce_product_class` filter 判斷到
 * product_type=wallet_credit 時才會被 lazy require（見該檔
 * twshop_wallet_credit_product_class()），刻意不放進 ultimate-ecommerce.php 頂層的
 * require 清單——`class ... extends WC_Product_Simple` 是在檔案被 include 當下就解析的
 * 敘述，若 WooCommerce 還沒載入（外掛載入順序無法保證），會直接 fatal error。lazy
 * require 保證這個檔案只會在 WooCommerce 的商品工廠實際需要這個類別時才被載入，那時
 * WooCommerce 核心必然已經完整啟動。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Product_Wallet_Credit extends WC_Product_Simple {
    public function get_type() {
        return 'wallet_credit';
    }
}
