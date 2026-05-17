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
	 * Payment method name. Matches the gateway ID.
	 * The JS getSetting() key is "{$name}_data" ('payjp_paypay_data').
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

	/**
	 * Data passed to the payment method JS component via getSetting().
	 * Extends the base data with the checkout icon URL for Block Checkout display.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		return array_merge(
			parent::get_payment_method_data(),
			[
				'icon' => PAYJP_FOR_WC_URL . 'assets/images/pp_logo_02.svg',
			]
		);
	}
}
