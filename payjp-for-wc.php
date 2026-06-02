<?php
/**
 * Plugin Name: PAY.JP for WooCommerce
 * Plugin URI:  https://wordpress.org/plugins/payjp-for-wc/
 * Description: PAY.JP v2 payment gateway for WooCommerce. Supports credit card and PayPay payments via Payment Widgets.
 * Version:     0.9.3
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * Author:      Shohei Tanaka
 * Author URI:  https://artws.info
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: payjp-for-wc
 * Domain Path: /languages
 * WC requires at least: 9.0
 * WC tested up to: 10.8
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

// Constants are defined immediately (not inside hooks) to support
// Japanized for WooCommerce bundling via the double-load prevention pattern.
defined( 'PAYJP_FOR_WC_VERSION' ) || define( 'PAYJP_FOR_WC_VERSION', '0.9.3' );
defined( 'PAYJP_FOR_WC_FILE' ) || define( 'PAYJP_FOR_WC_FILE', __FILE__ );
defined( 'PAYJP_FOR_WC_DIR' ) || define( 'PAYJP_FOR_WC_DIR', plugin_dir_path( __FILE__ ) );
defined( 'PAYJP_FOR_WC_URL' ) || define( 'PAYJP_FOR_WC_URL', plugin_dir_url( __FILE__ ) );
defined( 'PAYJP_API_BASE' ) || define( 'PAYJP_API_BASE', 'https://api.pay.jp/v2' );

// Prevent fatal function-redeclare errors when this file is included twice
// (e.g. bundled inside Japanized for WooCommerce while also active standalone).
if ( defined( 'PAYJP_FOR_WC_LOADED' ) ) {
	return;
}
define( 'PAYJP_FOR_WC_LOADED', true );

/**
 * Declare HPOS and Block Checkout compatibility before WooCommerce initializes.
 * Must fire before WooCommerce reads compatibility declarations.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Register Block Checkout payment method types when WooCommerce Blocks loads.
 * This hook fires during WooCommerce's own plugins_loaded (priority 10), so
 * the action must be registered here at plugin file load time — NOT inside our
 * own plugins_loaded callback at priority 11 (which would be too late).
 */
add_action( 'woocommerce_blocks_loaded', 'payjp_for_wc_register_block_payment_methods' );

/**
 * Register PAY.JP payment method types with the WooCommerce Blocks registry.
 */
function payjp_for_wc_register_block_payment_methods(): void {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once PAYJP_FOR_WC_DIR . 'includes/gateways/payjp/class-payjp-blocks-integration.php';
	require_once PAYJP_FOR_WC_DIR . 'includes/gateways/payjp/class-payjp-blocks-integration-card.php';
	require_once PAYJP_FOR_WC_DIR . 'includes/gateways/payjp/class-payjp-blocks-integration-paypay.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {
			$registry->register( new Payjp_Blocks_Integration_Card() );
			$registry->register( new Payjp_Blocks_Integration_Paypay() );
		}
	);
}

/**
 * Initialize the plugin after WooCommerce has loaded (priority 11).
 */
add_action( 'plugins_loaded', 'payjp_for_wc_init', 11 );

/**
 * Boot the plugin: check WooCommerce is active, then load classes and hooks.
 */
function payjp_for_wc_init(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'payjp_for_wc_missing_wc_notice' );
		return;
	}

	require_once PAYJP_FOR_WC_DIR . 'includes/class-payjp-loader.php';
	Payjp_Loader::init();
}

/**
 * Admin notice shown when WooCommerce is not active.
 */
function payjp_for_wc_missing_wc_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
		<?php
		echo wp_kses_post(
			sprintf(
				/* translators: %s: WooCommerce plugin name */
				__( 'PAY.JP for WooCommerce requires %s to be installed and active.', 'payjp-for-wc' ),
				'<strong>WooCommerce</strong>'
			)
		);
		?>
		</p>
	</div>
	<?php
}
