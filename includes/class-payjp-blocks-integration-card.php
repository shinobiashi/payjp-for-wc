<?php
/**
 * Block Checkout integration for PAY.JP credit card payments.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Payjp_Blocks_Integration' ) ) {
	return;
}

if ( class_exists( 'Payjp_Blocks_Integration_Card' ) ) {
	return;
}

/**
 * Registers the PAY.JP card payment method with the WooCommerce Blocks registry.
 * The name must match the gateway ID ('payjp_card') and the getSetting() key in JS.
 */
class Payjp_Blocks_Integration_Card extends Payjp_Blocks_Integration {

	/**
	 * Payment method name — matches the gateway ID and JS getSetting() key.
	 *
	 * @var string
	 */
	protected $name = 'payjp_card';

	/**
	 * Return the payment method name.
	 */
	public function get_name(): string {
		return $this->name;
	}
}
