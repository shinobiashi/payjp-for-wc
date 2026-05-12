<?php
/**
 * PAY.JP unified admin settings page.
 *
 * Provides a single settings page under WooCommerce > Settings > Payments
 * for API keys, test mode, and enabling/disabling each payment method.
 * Full implementation in Phase 2.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'Payjp_Admin_Settings_Page' ) ) {
	return;
}

/**
 * Registers the PAY.JP settings page using the WooCommerce Settings API.
 */
class Payjp_Admin_Settings_Page {

	/**
	 * Register admin settings hooks.
	 * Full settings page registration in Phase 2.
	 */
	public static function init(): void {}
}
