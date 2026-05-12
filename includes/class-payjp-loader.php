<?php
/**
 * Plugin loader: requires class files and registers hooks.
 *
 * @package Payjp_For_WooCommerce
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
		self::load_textdomain();
		self::register_hooks();
	}

	/**
	 * Require all plugin class files.
	 */
	private static function load_classes(): void {
		$dir = PAYJP_FOR_WC_DIR . 'includes/';

		require_once $dir . 'class-payjp-settings.php';
		require_once $dir . 'class-payjp-api.php';
		require_once $dir . 'class-wc-gateway-payjp.php';
		require_once $dir . 'class-wc-gateway-payjp-card.php';
		require_once $dir . 'class-wc-gateway-payjp-paypay.php';
		require_once $dir . 'class-payjp-webhook-handler.php';
		require_once $dir . 'class-payjp-blocks-integration.php';
		require_once $dir . 'class-payjp-blocks-integration-card.php';
		require_once $dir . 'class-payjp-blocks-integration-paypay.php';
		require_once $dir . 'class-payjp-admin-settings-page.php';
		require_once $dir . 'class-payjp-token-manager.php';
		require_once $dir . 'class-payjp-subscriptions.php';
	}

	/**
	 * Load the plugin text domain.
	 * Called directly because we're already inside plugins_loaded.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain(
			'payjp-for-wc',
			false,
			dirname( plugin_basename( PAYJP_FOR_WC_FILE ) ) . '/languages'
		);
	}

	/**
	 * Register WordPress and WooCommerce hooks.
	 */
	private static function register_hooks(): void {
		add_filter( 'woocommerce_payment_gateways', [ self::class, 'register_gateways' ] );

		Payjp_Admin_Settings_Page::init();
		Payjp_Webhook_Handler::init();
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
}
