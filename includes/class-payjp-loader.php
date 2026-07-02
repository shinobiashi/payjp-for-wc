<?php
/**
 * Plugin loader: requires class files and registers hooks.
 *
 * @package Payjp_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'Payjp_Loader' ) ) {
	return;
}

/**
 * Requires all plugin class files and wires up WordPress/WooCommerce hooks.
 */
class Payjp_Loader {

	/**
	 * Boot the loader: require classes and register hooks.
	 */
	public static function init(): void {
		self::load_classes();
		self::register_hooks();
	}

	/**
	 * Require all plugin class files.
	 */
	private static function load_classes(): void {
		$fw_dir = PAYJP_FOR_WC_DIR . 'includes/jp4wc-framework/';
		$dir    = PAYJP_FOR_WC_DIR . 'includes/gateways/payjp/';

		require_once $fw_dir . 'class-jp4wc-logger.php';
		require_once $dir . 'class-payjp-settings.php';
		require_once $dir . 'class-payjp-api.php';
		require_once $dir . 'class-wc-gateway-payjp.php';
		require_once $dir . 'class-wc-gateway-payjp-card.php';
		require_once $dir . 'class-wc-gateway-payjp-paypay.php';
		require_once $dir . 'class-payjp-webhook-handler.php';
		require_once $dir . 'class-payjp-token-manager.php';
		require_once $dir . 'class-payjp-subscriptions.php';
		// Blocks integration files are NOT loaded here. They are loaded by
		// payjp_for_wc_register_block_payment_methods() on the woocommerce_blocks_loaded
		// hook, which fires during WooCommerce's plugins_loaded (priority 10) — before
		// this loader runs (priority 11). Loading them here as well would be redundant
		// and unsafe when AbstractPaymentMethodType is unavailable.
		//
		// admin/class-payjp-admin-settings-page.php extends WC_Settings_Page which is NOT
		// available at plugins_loaded; it is required inside the woocommerce_get_settings_pages
		// filter callback (see register_hooks()) where WC admin has already booted.
	}

	/**
	 * Register WordPress and WooCommerce hooks.
	 */
	private static function register_hooks(): void {
		add_filter( 'woocommerce_payment_gateways', array( self::class, 'register_gateways' ) );

		if ( is_admin() ) {
			// Defer requiring admin/class-payjp-admin-settings-page.php to the
			// woocommerce_get_settings_pages filter: WC_Settings_Page is guaranteed
			// to be defined by the time this filter fires (after WC admin boots),
			// whereas it may not exist yet during plugins_loaded.
			add_filter(
				'woocommerce_get_settings_pages',
				static function ( array $pages ): array {
					require_once PAYJP_FOR_WC_DIR . 'includes/admin/class-payjp-admin-settings-page.php';
					return Payjp_Admin_Settings_Page::register_page( $pages );
				}
			);
		}

		Payjp_Webhook_Handler::init();
		Payjp_Token_Manager::init();
		Payjp_Subscriptions::init();

		// Correct payment_method on orders after payment_complete(). WooCommerce Block
		// Checkout (StoreAPI) resets payment_method to the default gateway via
		// update_order_from_cart() during draft-order sync. Even though
		// update_order_from_request() corrects it later, certain save paths may
		// re-overwrite it. This hook uses the authoritative _payjp_payment_method meta
		// to ensure the HPOS payment_method column always reflects the real gateway.
		add_action( 'woocommerce_payment_complete', array( self::class, 'fix_payment_method_after_complete' ) );

		// When a customer has a saved payjp_card token, PaymentUtils::get_default_payment_method()
		// returns 'payjp_card' regardless of session — causing update_order_from_cart() to
		// override 'payjp_paypay' back to 'payjp_card' on every cart sync.
		// Suppressing is_default in StoreAPI context forces the fallback to the session value
		// (set by update_order_from_request to the user's actual selection).
		add_filter( 'woocommerce_payment_methods_list_item', array( self::class, 'suppress_payjp_token_default_in_storeapi' ), 5, 2 );

		// Guard against WooCommerce Blocks Hydration service overwriting payment_method.
		// On the order-pay page, the Hydration service (AssetDataRegistry) makes an internal
		// fake GET request to /wc/store/v1/checkout. This runs update_order_from_cart(), which
		// calls set_payment_method(PaymentUtils::get_default_payment_method()). Because the
		// fake request does not set REST_REQUEST, the suppress_payjp_token_default_in_storeapi
		// filter may not fire, so the default saved card token wins and overwrites the PayPay
		// payment_method that process_payment() already persisted.
		// This hook intercepts the save and restores the authoritative value from meta.
		add_action( 'woocommerce_before_order_object_save', array( self::class, 'correct_payment_method_before_save' ) );
	}

	/**
	 * Add PAY.JP gateway classes to WooCommerce's gateway list.
	 *
	 * @param array<int, string|object> $gateways Registered gateway class names.
	 * @return array<int, string|object>
	 */
	public static function register_gateways( array $gateways ): array {
		$gateways[] = 'WC_Gateway_Payjp_Card';
		$gateways[] = 'WC_Gateway_Payjp_Paypay';
		return $gateways;
	}

	/**
	 * Suppress is_default on payjp_card tokens outside My Account > Payment Methods.
	 *
	 * Without this, PaymentUtils::get_default_payment_method() returns 'payjp_card'
	 * whenever a saved card exists, ignoring the user's actual selection (e.g. PayPay).
	 * Suppressing is_default forces the method to fall through to the session value,
	 * which update_order_from_request() sets to the chosen gateway.
	 *
	 * The WooCommerce Blocks Hydration service issues an internal fake GET request to
	 * /wc/store/v1/checkout during order-pay page rendering. Because that fake request
	 * does not set REST_REQUEST, checking REST_REQUEST alone is insufficient — the
	 * filter must suppress in non-My-Account contexts regardless of request type.
	 *
	 * @param array<string, mixed> $list_item Saved payment method list item.
	 * @param \WC_Payment_Token    $token     The WooCommerce payment token.
	 * @return array<string, mixed>
	 */
	public static function suppress_payjp_token_default_in_storeapi( array $list_item, \WC_Payment_Token $token ): array {
		if ( 'payjp_card' === $token->get_gateway_id() && ! is_wc_endpoint_url( 'payment-methods' ) ) {
			$list_item['is_default'] = false;
		}
		return $list_item;
	}

	/**
	 * Prevent update_order_from_cart() from overwriting payment_method for PAY.JP orders.
	 *
	 * WooCommerce Block Checkout's update_order_from_cart() calls
	 * set_payment_method(PaymentUtils::get_default_payment_method()) on every cart sync.
	 * This can set 'payjp_card' even on a PayPay order if the session or token default
	 * resolves to 'payjp_card'. This hook fires before each save and corrects the
	 * payment_method to match the authoritative _payjp_payment_method meta, which is
	 * written by process_payment() and never overwritten by WooCommerce internals.
	 *
	 * Also corrects payment_method_title: passing only a string ID to
	 * set_payment_method() skips the title update entirely (see
	 * fix_payment_method_after_complete()), so without this the order could keep a
	 * stale title (e.g. "Credit Card") that Blocks' cart-sync code wrote alongside
	 * the wrong gateway ID.
	 *
	 * Skipped in wp-admin: the Hydration bug this guards against only occurs on the
	 * frontend order-pay page. woocommerce_before_order_object_save fires for every
	 * order save, so without this guard a merchant intentionally changing
	 * payment_method from the Edit Order screen (or an admin-side integration) would
	 * have that change silently reverted.
	 *
	 * @param \WC_Order $order Order being saved.
	 */
	public static function correct_payment_method_before_save( \WC_Order $order ): void {
		if ( is_admin() ) {
			return;
		}

		$changes = $order->get_changes();
		if ( ! isset( $changes['payment_method'] ) ) {
			return;
		}

		$payjp_method = (string) $order->get_meta( '_payjp_payment_method' );
		if ( '' === $payjp_method ) {
			return;
		}

		$gateway_map = array(
			'card'   => 'payjp_card',
			'paypay' => 'payjp_paypay',
		);

		if ( ! isset( $gateway_map[ $payjp_method ] ) ) {
			return;
		}

		$correct_gateway = $gateway_map[ $payjp_method ];

		if ( $correct_gateway === $changes['payment_method'] ) {
			return;
		}

		$order->set_payment_method( $correct_gateway );

		$all_gateways = WC()->payment_gateways()->payment_gateways();
		$gateway      = isset( $all_gateways[ $correct_gateway ] ) ? $all_gateways[ $correct_gateway ] : null;
		if ( $gateway instanceof WC_Payment_Gateway ) {
			$order->set_payment_method_title( $gateway->get_title() );
		}
	}

	/**
	 * Correct the WooCommerce payment_method column for PAY.JP orders after payment completes.
	 *
	 * The StoreAPI's update_order_from_cart() resets payment_method to the first
	 * available gateway before our process_payment() runs. Even though
	 * update_order_from_request() corrects it, a later save can revert the value.
	 * This hook uses the authoritative _payjp_payment_method meta (set by our own
	 * process_payment()) to ensure both the payment_method ID and payment_method_title
	 * always reflect the actual gateway used.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function fix_payment_method_after_complete( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$payjp_method = (string) $order->get_meta( '_payjp_payment_method' );

		$gateway_map = array(
			'card'   => 'payjp_card',
			'paypay' => 'payjp_paypay',
		);

		if ( ! isset( $gateway_map[ $payjp_method ] ) ) {
			return;
		}

		$correct_gateway = $gateway_map[ $payjp_method ];

		if ( $correct_gateway === $order->get_payment_method() ) {
			return;
		}

		// Look up the gateway instance to also fix payment_method_title. Passing only a
		// string ID to set_payment_method() skips the title update entirely.
		$all_gateways = WC()->payment_gateways()->payment_gateways();
		$gateway      = isset( $all_gateways[ $correct_gateway ] ) ? $all_gateways[ $correct_gateway ] : null;

		$order->set_payment_method( $correct_gateway );
		if ( $gateway instanceof WC_Payment_Gateway ) {
			$order->set_payment_method_title( $gateway->get_title() );
		}
		$order->save();
	}
}
