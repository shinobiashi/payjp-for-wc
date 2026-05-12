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

if ( is_multisite() ) {
	// Remove per-site options from every subsite.
	$sites = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);
	foreach ( $sites as $site_id ) {
		switch_to_blog( (int) $site_id );
		foreach ( $option_keys as $key ) {
			delete_option( $key );
		}
		restore_current_blog();
	}
	// Remove network-level options stored via add_site_option().
	foreach ( $option_keys as $key ) {
		delete_site_option( $key );
	}
} else {
	foreach ( $option_keys as $key ) {
		delete_option( $key );
	}
}
