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
 *
 * Payment flow:
 *   1. process_payment() creates a PAY.JP Payment Flow and redirects to the
 *      WooCommerce order-pay page.
 *   2. payment_scripts() (base class) localises the client_secret on the
 *      order-pay page; receipt_page() renders the widget mount point.
 *   3. checkout-paypay.js mounts the payments.js widget and calls
 *      widgets.confirmPayment({ return_url }) on the "Pay with PayPay" button.
 *   4. PAY.JP redirects the customer to the PayPay app or website.
 *   5. handle_return() (base class, template_redirect) verifies the Payment Flow
 *      server-side and calls $order->payment_complete().
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
		$this->supports           = [ 'products' ];

		$this->setup();

		// payment_scripts() and handle_return() are registered by setup() via the base class.
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'receipt_page' ] );
	}

	// ── Template-method implementations ──────────────────────────────────────

	/**
	 * Returns the script handle for the PayPay checkout widget.
	 *
	 * @return string
	 */
	protected function get_checkout_script_handle(): string {
		return 'payjp-checkout-paypay';
	}

	/**
	 * Returns the compiled JS filename basename under build/frontend/.
	 *
	 * @return string
	 */
	protected function get_checkout_script_filename(): string {
		return 'checkout-paypay';
	}

	/**
	 * Returns the JS variable name for wp_localize_script.
	 *
	 * @return string
	 */
	protected function get_script_localize_var(): string {
		return 'payjpPaypayData';
	}

	/**
	 * Returns i18n strings passed to the PayPay payment widget JS.
	 *
	 * @return array{payNow: string, processing: string}
	 */
	protected function get_script_i18n(): array {
		return [
			'payNow'     => __( 'Pay with PayPay', 'payjp-for-wc' ),
			'processing' => __( 'Processing…', 'payjp-for-wc' ),
		];
	}

	// ── Gateway-specific methods ──────────────────────────────────────────────

	/**
	 * Render the payment form placeholder shown at WooCommerce checkout.
	 * The actual widget mounts on the order-pay page via receipt_page().
	 */
	public function payment_fields(): void {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( $this->description ) );
		}
		echo '<div id="payjp-paypay-form"></div>';
		echo '<div id="payjp-paypay-errors" role="alert" aria-live="polite"></div>';
	}

	/**
	 * Create a PAY.JP Payment Flow for the order and redirect to the order-pay
	 * page where the payments.js widget will be rendered.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array{result: string, redirect?: string}
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( esc_html__( 'Unable to load the order for payment.', 'payjp-for-wc' ), 'error' );
			return [ 'result' => 'failure' ];
		}
		$amount = (int) round( $order->get_total() );

		try {
			$flow = $this->get_api()->post(
				'/payment_flows',
				[
					'amount'               => $amount,
					'currency'             => 'jpy',
					'payment_method_types' => [ 'paypay' ],
					'capture_method'       => 'automatic',
				]
			);

			$flow_id       = isset( $flow['id'] ) && is_string( $flow['id'] ) ? $flow['id'] : '';
			$client_secret = isset( $flow['client_secret'] ) && is_string( $flow['client_secret'] ) ? $flow['client_secret'] : '';

			if ( ! $flow_id || ! $client_secret ) {
				wc_add_notice( esc_html__( 'PAY.JP returned an incomplete payment session. Please try again.', 'payjp-for-wc' ), 'error' );
				return [ 'result' => 'failure' ];
			}

			$order->update_meta_data( '_payjp_payment_flow_id', $flow_id );
			$order->update_meta_data( '_payjp_client_secret', $client_secret );
			$order->update_meta_data( '_payjp_payment_method', 'paypay' );
			$order->update_meta_data( '_payjp_capture_method', 'automatic' );
			$order->save();

			return [
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( true ),
			];
		} catch ( RuntimeException $e ) {
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
			return [ 'result' => 'failure' ];
		}
	}

	/**
	 * Render the payments.js widget mount point on the WooCommerce order-pay page.
	 * Fired by the woocommerce_receipt_payjp_paypay action.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function receipt_page( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( ! $order->get_meta( '_payjp_client_secret' ) ) {
			// Headers are already sent at this point (inside the order-pay template),
			// so wp_safe_redirect() would fail. Render an inline error with a link instead.
			printf(
				'<p class="woocommerce-error">%s <a href="%s">%s</a></p>',
				esc_html__( 'Payment session expired.', 'payjp-for-wc' ),
				esc_url( wc_get_checkout_url() ),
				esc_html__( 'Return to checkout', 'payjp-for-wc' )
			);
			return;
		}
		?>
		<div id="payjp-paypay-receipt-form"></div>
		<div id="payjp-paypay-errors" role="alert" aria-live="polite"></div>
		<button id="payjp-paypay-pay-button" type="button" class="button alt">
			<?php esc_html_e( 'Pay with PayPay', 'payjp-for-wc' ); ?>
		</button>
		<?php
	}
}
