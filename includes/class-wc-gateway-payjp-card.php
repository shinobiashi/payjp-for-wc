<?php
/**
 * Credit card payment gateway via PAY.JP v2 Payment Widgets.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WC_Gateway_Payjp_Card' ) ) {
	return;
}

/**
 * Handles credit card payments using PAY.JP v2 Payment Widgets (embedded).
 * payment_fields() and process_payment() are fully implemented in Phase 3.
 */
class WC_Gateway_Payjp_Card extends WC_Gateway_Payjp {

	/**
	 * Constructor: set gateway properties and initialize settings.
	 */
	public function __construct() {
		$this->id                 = 'payjp_card';
		$this->payjp_method       = 'card';
		$this->has_fields         = true;
		$this->method_title       = __( 'PAY.JP Credit Card', 'payjp-for-woocommerce' );
		$this->method_description = __( 'Accept credit card payments via PAY.JP v2 Payment Widgets.', 'payjp-for-woocommerce' );
		$this->supports           = [ 'products', 'refunds' ];

		$this->setup();
	}

	/**
	 * Render the payment form area (payments.js widget mount point).
	 * Full implementation in Phase 3.
	 */
	public function payment_fields(): void {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( $this->description ) );
		}
		echo '<div id="payjp-card-form"></div>';
		echo '<div id="payjp-card-errors" role="alert" aria-live="polite"></div>';
	}

	/**
	 * Process the card payment via PAY.JP Payment Flow API.
	 * Full implementation in Phase 3.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array{result: string}
	 */
	public function process_payment( $order_id ): array {
		wc_add_notice(
			__( 'PAY.JP card payment is not yet configured. Please complete the setup.', 'payjp-for-woocommerce' ),
			'error'
		);
		return [ 'result' => 'failure' ];
	}
}
