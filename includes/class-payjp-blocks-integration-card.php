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
 * The name must match the gateway ID ('payjp_card'); the JS getSetting() key is
 * the derived "{$name}_data" value ('payjp_card_data').
 */
class Payjp_Blocks_Integration_Card extends Payjp_Blocks_Integration {

	/**
	 * Payment method name — matches the gateway ID; the JS getSetting() key is
	 * "{$name}_data" ('payjp_card_data'), not the raw name itself.
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
