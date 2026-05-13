<?php
/**
 * WooCommerce Subscriptions integration for PAY.JP.
 *
 * Handles scheduled renewal payments, subscription status changes,
 * and payment method updates for recurring billing.
 * Full implementation in Phase 9.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'Payjp_Subscriptions' ) ) {
	return;
}

/**
 * Wires up WooCommerce Subscriptions hooks for automatic renewal processing
 * using the customer's saved PAY.JP PaymentMethod.
 * Only activates when WooCommerce Subscriptions is installed.
 */
class Payjp_Subscriptions {
	// Full implementation in Phase 9.
}
