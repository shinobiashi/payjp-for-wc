<?php
/**
 * PAY.JP Webhook handler.
 *
 * Registers and processes webhook events from PAY.JP.
 * Full implementation in Phase 5.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'Payjp_Webhook_Handler' ) ) {
	return;
}

/**
 * Registers the REST API endpoint for PAY.JP webhooks and routes events
 * to the appropriate order status handlers.
 */
class Payjp_Webhook_Handler {

	/**
	 * Register webhook-related hooks.
	 * Full REST endpoint registration in Phase 5.
	 */
	public static function init(): void {}
}
