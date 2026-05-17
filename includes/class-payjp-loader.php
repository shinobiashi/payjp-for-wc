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
