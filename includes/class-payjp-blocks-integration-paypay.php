<?php
/**
 * Block Checkout integration for PAY.JP PayPay payments.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'Payjp_Blocks_Integration_Paypay' ) ) {
	return;
}

/**
 * Registers the PAY.JP PayPay payment method with the WooCommerce Blocks registry.
 * The name must match the gateway ID ('payjp_paypay') and the getSetting() key in JS.
 */
class Payjp_Blocks_Integration_Paypay extends Payjp_Blocks_Integration {

	/**
	 * Payment method name — matches the gateway ID and JS getSetting() key.
	 *
	 * @var string
	 */
	protected $name = 'payjp_paypay';

	/**
	 * Return the payment method name.
	 */
	public function get_name(): string {
		return $this->name;
	}
}
