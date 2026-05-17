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

	/**
	 * Data passed to the payment method JS component via getSetting().
	 * Passes showSaveOption so the JS component can show the "save for later"
	 * checkbox when tokenization is enabled. showSavedCards is intentionally
	 * omitted: the Content component renders saved cards inline itself so
	 * WC Blocks' global saved-token section is suppressed (showSavedCards: false).
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		$save_enabled = null !== $this->gateway && $this->gateway->supports( 'tokenization' );
		return array_merge(
			parent::get_payment_method_data(),
			array(
				'showSaveOption' => $save_enabled,
			)
		);
	}
}
