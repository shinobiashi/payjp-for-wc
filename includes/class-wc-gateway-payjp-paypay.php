<?php
/**
 * PayPay payment gateway via PAY.JP v2 Payment Widgets.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WC_Gateway_Payjp_Paypay' ) ) {
	return;
}

/**
 * Handles PayPay payments using PAY.JP v2 Payment Widgets (embedded).
 * payment_fields() and process_payment() are fully implemented in Phase 4.
 */
class WC_Gateway_Payjp_Paypay extends WC_Gateway_Payjp {

	/**
	 * Constructor: set gateway properties and initialize settings.
	 */
	public function __construct() {
		$this->id                 = 'payjp_paypay';
		$this->payjp_method       = 'paypay';
		$this->has_fields         = true;
		$this->method_title       = __( 'PAY.JP PayPay', 'payjp-for-wc' );
		$this->method_description = __( 'Accept PayPay payments via PAY.JP v2 Payment Widgets.', 'payjp-for-wc' );
		// PayPay does not support refunds via WooCommerce admin (manual handling required).
		$this->supports = [ 'products' ];

		$this->setup();
	}

	/**
	 * Render the PayPay form area (payments.js widget mount point).
	 * Full implementation in Phase 4.
	 */
	public function payment_fields(): void {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( $this->description ) );
		}
		echo '<div id="payjp-paypay-form"></div>';
		echo '<div id="payjp-paypay-errors" role="alert" aria-live="polite"></div>';
	}

	/**
	 * Process the PayPay payment via PAY.JP Payment Flow API.
	 * Full implementation in Phase 4.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array{result: string}
	 */
	public function process_payment( $order_id ): array {
		wc_add_notice(
			__( 'PAY.JP PayPay payment is not yet configured. Please complete the setup.', 'payjp-for-wc' ),
			'error'
		);
		return [ 'result' => 'failure' ];
	}
}
