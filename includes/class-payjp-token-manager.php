<?php
/**
 * PAY.JP card token manager.
 *
 * Manages PAY.JP Customer and PaymentMethod objects, and integrates them
 * with the WooCommerce Payment Token API for saved card functionality.
 * Full implementation in Phase 8.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'Payjp_Token_Manager' ) ) {
	return;
}

/**
 * Handles card tokenization via PAY.JP Setup Flow, stores tokens using the
 * WooCommerce Token API, and exposes them for re-use at checkout.
 */
class Payjp_Token_Manager {
	// Full implementation in Phase 8.
}
