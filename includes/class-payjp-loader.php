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
		require_once $dir . 'class-payjp-admin-notifier.php';
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
	 * A pending order started with PAY.JP (e.g. PayPay) is reused when the customer
	 * clicks "Change payment method" and re-submits checkout with a different gateway
	 * (e.g. Cash on Delivery). Without checking the customer's actual selection, this
	 * guard would wrongly revert that legitimate switch back to PAY.JP. The session's
	 * chosen_payment_method (set by WooCommerce before create_order()/update_order_from_request()
	 * run, for both classic and Block checkout) is the authoritative signal of what the
	 * customer actually picked.
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

		if ( self::is_customers_actual_selection( $changes['payment_method'] ) ) {
			// The customer genuinely switched away from PAY.JP. Clear the stale meta
			// so it can't resurface later, e.g. a delayed payment_flow.succeeded webhook
			// for the abandoned flow completing this order via Payjp_Webhook_Handler's
			// fallback lookup by _payjp_payment_flow_id.
			self::clear_stale_payjp_meta( $order );
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
	 * Whether $new_method matches the customer's actual checkout selection.
	 *
	 * WooCommerce records the chosen gateway in the session under 'chosen_payment_method'
	 * before the order is saved with that payment method, for both classic checkout
	 * (WC_Checkout::update_session(), and the update_order_review AJAX call fired on
	 * radio-button change) and Block Checkout (update_order_from_request()). This is
	 * distinct from internal cart-sync saves (e.g. the Blocks Hydration service's fake
	 * GET request) that reset payment_method without any corresponding session update.
	 *
	 * @param string $new_method Payment method ID being saved.
	 * @return bool
	 */
	private static function is_customers_actual_selection( string $new_method ): bool {
		$chosen = WC()->session->get( 'chosen_payment_method' );

		return is_string( $chosen ) && '' !== $chosen && $chosen === $new_method;
	}

	/**
	 * Delete PAY.JP-specific order meta left over from an abandoned payment attempt.
	 *
	 * Called when the customer switches away from PAY.JP to a different gateway on a
	 * reused pending order. Without this, the stale _payjp_payment_flow_id would let
	 * Payjp_Webhook_Handler::find_order_by_flow_id() match this order if the abandoned
	 * flow later completes asynchronously, incorrectly marking it paid via PAY.JP.
	 *
	 * Also clears transaction_id: find_order_by_flow_id() checks it before falling
	 * back to the meta query, so a manual-capture attempt that already reached
	 * requires_capture (which sets transaction_id to the flow ID) would otherwise
	 * still let a delayed webhook match this order even with the meta above cleared.
	 *
	 * @param \WC_Order $order Order being saved.
	 */
	private static function clear_stale_payjp_meta( \WC_Order $order ): void {
		$order->delete_meta_data( '_payjp_payment_flow_id' );
		$order->delete_meta_data( '_payjp_client_secret' );
		$order->delete_meta_data( '_payjp_payment_method' );
		$order->delete_meta_data( '_payjp_capture_method' );
		$order->set_transaction_id( '' );
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
