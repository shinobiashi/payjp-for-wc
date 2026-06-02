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
	 * Suppress is_default on payjp_card tokens during StoreAPI requests.
	 *
	 * Without this, PaymentUtils::get_default_payment_method() returns 'payjp_card'
	 * whenever a saved card exists, ignoring the user's actual selection (e.g. PayPay).
	 * Suppressing is_default in REST context forces the method to fall through to the
	 * session value, which update_order_from_request() sets to the chosen gateway.
	 *
	 * My Account > Payment Methods is unaffected (not a REST_REQUEST).
	 *
	 * @param array<string, mixed> $list_item Saved payment method list item.
	 * @param \WC_Payment_Token    $token     The WooCommerce payment token.
	 * @return array<string, mixed>
	 */
	public static function suppress_payjp_token_default_in_storeapi( array $list_item, \WC_Payment_Token $token ): array {
		if ( 'payjp_card' === $token->get_gateway_id() && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$list_item['is_default'] = false;
		}
		return $list_item;
	}

	/**
	 * Correct the WooCommerce payment_method column for PAY.JP orders after payment completes.
	 *
	 * The StoreAPI's update_order_from_cart() resets payment_method to the first
	 * available gateway before our process_payment() runs. Even though
	 * update_order_from_request() corrects it, a later save can revert the value.
	 * This hook uses the authoritative _payjp_payment_method meta (set by our own
	 * process_payment()) to ensure payment_method always reflects the actual gateway.
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

		$order->set_payment_method( $correct_gateway );
		$order->save();
	}
}
