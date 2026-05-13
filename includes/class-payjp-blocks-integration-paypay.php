<?php
/**
 * Block Checkout integration for PAY.JP PayPay payments.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Payjp_Blocks_Integration' ) ) {
	return;
}

if ( class_exists( 'Payjp_Blocks_Integration_Paypay' ) ) {
	return;
}

/**
 * Registers the PAY.JP PayPay payment method with the WooCommerce Blocks registry.
 * The name must match the gateway ID ('payjp_paypay'); the JS getSetting() key is
 * the derived "{$name}_data" value ('payjp_paypay_data').
 */
class Payjp_Blocks_Integration_Paypay extends Payjp_Blocks_Integration {

	/**
	 * Payment method name — matches the gateway ID; the JS getSetting() key is
	 * "{$name}_data" ('payjp_paypay_data'), not the raw name itself.
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
