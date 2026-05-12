<?php
/**
 * Uninstall PAY.JP for WooCommerce.
 *
 * Runs when the plugin is deleted via the WordPress admin.
 * Removes plugin-owned options. Order meta is intentionally retained
 * as a permanent payment record.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$option_keys = [
	'payjp_settings',
	'woocommerce_payjp_card_settings',
	'woocommerce_payjp_paypay_settings',
];

foreach ( $option_keys as $key ) {
	delete_option( $key );
}

// On multisite, also remove any network-level options stored via add_site_option().
if ( is_multisite() ) {
	foreach ( $option_keys as $key ) {
		delete_site_option( $key );
	}
}
