=== PAY.JP for WooCommerce ===
Contributors:      shohei1978
Tags:              woocommerce, payment, payjp, paypay, checkout
Requires at least: 6.9
Tested up to:      7.0
Stable tag:        0.9.4
Requires PHP:      8.3
WC requires at least: 9.0
WC tested up to:   10.8
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Accept credit card and PayPay payments in WooCommerce via PAY.JP v2 Payment Widgets. PCI DSS compliant, no raw card data on your server.

== Description ==

**PAY.JP for WooCommerce** integrates the [PAY.JP](https://pay.jp/) v2 Payment Widgets into your WooCommerce store, enabling you to accept credit card payments and PayPay payments securely and easily.

Raw card data never touches your server. The PAY.JP payments.js widget handles card entry entirely in a PCI DSS compliant iframe, keeping your integration simple and your compliance scope minimal.

=== Key Features ===

**Credit Card Payments**

* Embedded PAY.JP v2 Payment Widgets (no redirect, PCI DSS compliant)
* Save cards to customer accounts for faster repeat checkout
* Full and partial refunds directly from the WooCommerce order screen

**PayPay Payments**

* Embedded PayPay widget via PAY.JP v2 Payment Widgets
* Seamless in-page payment experience
* Full and partial refunds directly from the WooCommerce order screen

**Subscriptions**

* WooCommerce Subscriptions compatible for automatic recurring billing
* Saved card via PAY.JP Setup Flow powers subscription renewals

**Developer & Store Friendly**

* Block Checkout (WooCommerce 8.3+) and Classic Checkout both supported
* HPOS (High-Performance Order Storage) compatible
* Multisite compatible
* Test mode / Live mode toggle — no code changes required
* Webhook processing for `payment_flow.succeeded`, `payment_flow.payment_failed`, and `refund.created` events
* Clean uninstall — removes all plugin data and options on deletion

=== How It Works ===

1. The checkout page loads the PAY.JP payments.js widget from PAY.JP's CDN.
2. The customer enters card details (or selects PayPay) entirely within the secure widget.
3. PAY.JP returns a Payment Flow ID to your server — raw card data never reaches WordPress.
4. WooCommerce processes the order and the plugin completes the payment via the PAY.JP v2 API.

=== Requirements ===

* WordPress 6.9 or later
* WooCommerce 9.0 or later
* PHP 8.3 or later
* A [PAY.JP account](https://pay.jp/) (free to register)

== External Services ==

This plugin connects to the following external services. By using this plugin you agree to their respective terms and privacy policies.

=== 1. PAY.JP API ===

Used to create and manage Payment Flows, process payments, issue refunds, and manage saved payment methods.

* **Endpoint:** `https://api.pay.jp/v2`
* **When called:** At checkout when a payment is initiated, on order completion, on refund, and during webhook processing.
* **Data sent:** Payment Flow ID, order amount, currency, and (for saved cards) PAY.JP Customer ID. Raw card numbers are **never** sent to your server or to the API directly from WordPress.
* **Privacy Policy:** https://pay.jp/privacy
* **Terms of Service:** https://pay.jp/terms

=== 2. PAY.JP payments.js (CDN) ===

The PAY.JP payments.js script is loaded from PAY.JP's CDN to render the secure card entry and PayPay widget on the checkout page. This is the standard PCI DSS compliant integration method (equivalent to Stripe.js for Stripe).

* **Script URL:** `https://js.pay.jp/payments.js`
* **When loaded:** On any checkout page where PAY.JP is an available payment method.
* **Data handled:** Card details entered by the customer are tokenized entirely within this script and its iframe — they are never accessible to your WordPress site.
* **Privacy Policy:** https://pay.co.jp/privacy
* **Terms of Service:** https://pay.jp/legal/tos

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel.
2. Go to **Plugins > Add New**.
3. Search for "PAY.JP for WooCommerce".
4. Click **Install Now**, then **Activate**.
5. Go to **WooCommerce > Settings > Payments** and configure PAY.JP.

= Manual Installation =

1. Download the plugin ZIP from the WordPress.org plugin directory.
2. Go to **Plugins > Add New > Upload Plugin** and upload the ZIP.
3. Activate the plugin through the Plugins screen.
4. Go to **WooCommerce > Settings > Payments** and configure PAY.JP.

= Configuration =

1. Navigate to **WooCommerce > Settings > Payments**.
2. Click **PAY.JP** to open the unified settings page.
3. Enter your **Public Key** and **Secret Key** from the [PAY.JP dashboard](https://console.pay.jp/d/login).
4. Set the mode to **Test** for development or **Live** for production.
5. Copy the **Webhook URL** shown in the settings and register it in your PAY.JP dashboard under **Webhook**.
6. Save changes and place a test order.

**Webhook URL format:** `https://yoursite.com/wp-json/payjp/v2/webhook`

== Frequently Asked Questions ==

= Where do I get my PAY.JP API keys? =

Sign up or log in at [pay.jp](https://console.pay.jp/d/login), then go to **API** in the dashboard. You will find your Public Key and Secret Key there. Use the test keys during development and the live keys for production.

= Is this plugin PCI DSS compliant? =

Yes. Raw card data never touches your server. All card entry is handled by the PAY.JP payments.js widget loaded from PAY.JP's CDN, which tokenizes the card data in a secure iframe. Only a Payment Flow ID is sent to your server.

= How do I test payments? =

Enable **Test Mode** in the plugin settings and use your PAY.JP test API keys. For PayPay test payments, use one of the reserved test phone numbers: 080-1111-5912 through 080-1111-5921. Each PayPay test transaction is capped at ¥100.

= Does this plugin support WooCommerce Subscriptions? =

Yes. When a customer completes their first subscription payment, their card is saved via PAY.JP Setup Flow. Subsequent automatic renewal payments are processed against the saved PAY.JP Customer ID without requiring the customer to re-enter their card.

= Does this plugin support saving cards? =

Yes. Customers can save cards to their account during checkout. Saved cards are stored as WooCommerce payment tokens backed by PAY.JP Customer IDs and PaymentMethod IDs — no raw card data is stored in your database.

= How do I configure webhooks? =

1. In the PAY.JP for WooCommerce settings, copy the **Webhook URL** (`https://yoursite.com/wp-json/payjp/v2/webhook`).
2. Log in to your PAY.JP dashboard and go to **Webhook**.
3. Add the webhook URL and enable the following events: `payment_flow.succeeded`, `payment_flow.payment_failed`, `refund.created`.
4. Copy the **Webhook Secret** from PAY.JP into the plugin settings and save.

= Does this plugin work with Block Checkout? =

Yes. PAY.JP for WooCommerce supports both the Block-based Checkout (introduced in WooCommerce 8.3) and the Classic Checkout shortcode.

= Is HPOS (High-Performance Order Storage) supported? =

Yes. The plugin is fully compatible with WooCommerce HPOS and uses the WooCommerce order meta API (`$order->get_meta()`, `$order->update_meta_data()`) exclusively.

= Can I process partial refunds? =

Yes. Navigate to the WooCommerce order screen, click **Refund**, enter the amount to refund, and click **Refund via PAY.JP**. Both full and partial refunds are supported.

= What happens to my data if I uninstall the plugin? =

Uninstalling (deleting) the plugin removes all plugin options from your database. Order meta is intentionally retained as a permanent payment record. PAY.JP customer records remain on the PAY.JP side; manage or delete those from your PAY.JP dashboard.

= Is multisite supported? =

Yes. The plugin is compatible with WordPress multisite. Each site in the network uses its own PAY.JP API keys and settings.

== Screenshots ==

1. PAY.JP unified settings page — configure API keys, test/live mode, and webhook secret.
2. Credit card payment widget embedded in the Block Checkout page.
3. PayPay payment widget embedded in the Block Checkout page.
4. Saved card selection at checkout for returning customers.
5. WooCommerce order detail screen showing PAY.JP payment information and refund controls.

== Changelog ==

= 0.9.4 =
* Added: PayPay refund processing via the PAY.JP API — full and partial refunds now work from the WooCommerce order screen for PayPay orders, not just credit card.
* Added: The PAY.JP Payment Flow is automatically cancelled when a WooCommerce order is cancelled; already-captured payments are refunded automatically to prevent stuck charges.
* Fixed: Payment Flow status `processing` (seen with asynchronous methods such as PayPay) was incorrectly treated as a failed payment on return from checkout — the customer now sees the order confirmation page and the order completes automatically once the `payment_flow.succeeded` webhook arrives.
* Fixed: WooCommerce Blocks' cart-sync/Hydration requests could overwrite `payment_method` and `payment_method_title` on the order-pay page, occasionally showing a PayPay order as "Credit Card". The correct gateway and title are now restored before the order is saved, without interfering with legitimate payment method changes made in wp-admin.
* Fixed: Duplicate refunds could be issued when an order was cancelled more than once; a guard now prevents re-processing an already-refunded cancellation.

= 0.9.3 =
* Fixed: Removed discouraged load_plugin_textdomain() call — WordPress 4.6+ loads translations automatically.
* Fixed: Hook names fired by the plugin now use the payjp_for_wc_ prefix (Plugin Check compliance).
* Fixed: Global variables in uninstall.php wrapped in a prefixed function to satisfy PrefixAllGlobals rule.
* Fixed: Removed hidden file languages/.gitkeep (replaced by actual translation files).
* Fixed: Added .distignore to its own exclusion list so it is not included in WordPress.org distribution ZIPs.

= 0.9.2 =
* Fixed: PayPay payments recorded as credit card when saved card tokens exist (Block Checkout default payment method conflict).
* Fixed: Correct payment_method written to HPOS after payment_complete() using _payjp_payment_method meta as authority.
* Fixed: Manual capture for authorized orders — empty request body now serialized as {} (JSON object) instead of [] (JSON array).
* Fixed: Authorized orders now set to processing status instead of on-hold for clearer merchant workflow.
* Fixed: Card token deletion now calls POST /payment_methods/{id}/detach on PAY.JP to sync removal.
* Added: Manual capture (authorize only) support for PayPay payments — mirrors credit card capture behaviour.
* Added: Payment capture setting in PAY.JP PayPay individual gateway settings (immediate / authorize only).

= 0.9.1 =
* Fixed: Refund endpoint corrected from /refunds to /payment_refunds.
* Fixed: Refund request parameter corrected from payment_flow to payment_flow_id.
* Fixed: PayPay return handling — requires_action status now redirects to order-received page instead of showing an error.
* Fixed: Setup Flow customer parameter corrected from customer to customer_id.
* Fixed: Setup Flow return URL now includes setup_flow_id so card save completes correctly.
* Fixed: Subscription renewal payment parameters corrected (payment_method_id, customer_id).
* Changed: REST API namespace updated from payjp/v1 to payjp/v2.
* Added: PAY.JP Dashboard / sign-up button in admin settings.
* Added: Test card and PayPay test account links shown when test mode is active.
* Added: Webhook URL display with setup instructions in admin settings.

= 0.9.0 =
* Added: Credit card payments via PAY.JP v2 Payment Widgets (embedded, PCI DSS compliant).
* Added: PayPay payments via PAY.JP v2 Payment Widgets (embedded).
* Added: Saved cards via PAY.JP Setup Flow and WooCommerce Token API.
* Added: WooCommerce Subscriptions support for automatic recurring billing.
* Added: Block Checkout (WooCommerce 8.3+) and Classic Checkout compatibility.
* Added: Webhook processing for payment_flow.succeeded, payment_flow.payment_failed, and refund.created.
* Added: Full and partial refunds from the WooCommerce order screen.
* Added: Test mode and Live mode toggle.
* Added: HPOS (High-Performance Order Storage) compatibility.
* Added: Multisite compatibility.
* Added: Uninstall cleanup for all plugin options.

== Upgrade Notice ==

= 0.9.4 =
Adds PayPay refunds and automatic payment cancellation on order cancel; fixes a false "payment failed" message for asynchronous payments and a Block Checkout issue that could mislabel the payment method on the order. Recommended update.

= 0.9.3 =
Code quality and Plugin Check compliance fixes. No functional changes. Safe to update.

= 0.9.2 =
Important fixes for Block Checkout payment method handling with saved cards, capture API, and card token deletion sync. Recommended update.

= 0.9.1 =
Bug fixes for refunds, PayPay return handling, card save via Setup Flow, and subscription renewals. Recommended update.

= 0.9.0 =
Initial release.
