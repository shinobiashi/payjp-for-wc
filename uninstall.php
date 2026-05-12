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

delete_option( 'payjp_settings' );
delete_option( 'woocommerce_payjp_card_settings' );
delete_option( 'woocommerce_payjp_paypay_settings' );
