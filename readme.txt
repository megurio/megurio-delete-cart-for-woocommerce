=== Megurio Delete Cart for WooCommerce ===
Contributors: megurio, wapai222
Tags: woocommerce, cart, buy now, checkout, direct checkout
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.0
Requires Plugins: woocommerce
WC requires at least: 8.2
WC tested up to: 10.6.2
Stable tag: 1.2.5
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Disables the WooCommerce cart and replaces "Add to Cart" with a "Buy Now" button that goes directly to checkout.

== Description ==

Megurio Delete Cart for WooCommerce simplifies the purchase flow by removing the cart step entirely. Customers are sent directly to checkout when they click the buy button.

Features:

* Replaces "Add to Cart" with "今すぐ購入" (Buy Now) on product pages and archive pages
* Redirects customers directly to checkout after adding a product
* Hides cart icons and widgets from common themes (Storefront, Astra, OceanWP, Kadence, SWELL, AFFINGER, and more)
* Disables cart fragment AJAX to improve page performance
* Redirects direct visits to the cart page to checkout (or shop if cart is empty)
* Shows an editable cart table on the checkout page so customers can adjust quantity or remove items
* Suppresses the "added to cart" notice on checkout
* Admin settings page to hide individual checkout fields

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory.
2. Activate the plugin through the WordPress plugins screen.
3. WooCommerce must be installed and active.
4. Optionally configure hidden checkout fields under WooCommerce > チェックアウト設定.

== Frequently Asked Questions ==

= Does this work with all themes? =

The plugin hides cart icons via CSS for most popular themes. If your theme's cart icon is still visible, you may need to add a custom CSS rule.

= Can customers still change quantity before paying? =

Yes. An editable cart table is shown on the checkout page, allowing quantity changes and item removal.

= Does this support variable products? =

Variable and grouped products will still show the updated button text, but require the customer to visit the product page to select options before proceeding to checkout.

== Changelog ==

= 1.2.5 =
* Added cart delete feature toggle and buy button text setting
* Added checkout coupon field hide setting
* Fixed existing cart items remaining when using Buy Now
* Fixed inline frontend assets being output directly

= 1.2.0 =
* Added editable cart table on checkout page
* Added admin settings page for checkout field visibility
* Added support for additional Japanese themes

= 1.0.0 =
* Initial release
