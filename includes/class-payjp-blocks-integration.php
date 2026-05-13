<?php
/**
 * Abstract base class for PAY.JP Block Checkout payment method integrations.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

// Guard: this file must only be loaded after WooCommerce Blocks has defined
// AbstractPaymentMethodType (i.e. inside the woocommerce_blocks_loaded callback).
// If require_once'd before Blocks loads and this guard fires, PHP will not
// re-execute the file later, so Payjp_Blocks_Integration would never be declared.
if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
	return;
}

if ( class_exists( 'Payjp_Blocks_Integration' ) ) {
	return;
}

/**
 * Abstract base for PAY.JP Block Checkout integrations.
 * Concrete subclasses (Card, PayPay) supply the gateway-specific name.
 * Full implementation in Phase 7.
 */
abstract class Payjp_Blocks_Integration extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	/**
	 * Initialize gateway settings for block data.
	 * Full implementation in Phase 7.
	 */
	public function initialize(): void {}

	/**
	 * Whether this payment method is currently active.
	 * Returns false until Phase 7 implements the full block integration.
	 */
	public function is_active(): bool {
		return false;
	}

	/**
	 * Registered script handles for this payment method's JS component.
	 * Full implementation in Phase 7.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		return [];
	}

	/**
	 * Data passed to the payment method JS component via getSetting().
	 * Full implementation in Phase 7.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		return [];
	}
}
