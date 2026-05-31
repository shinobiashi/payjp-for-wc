<?php
/**
 * PayPay payment gateway via PAY.JP v2 Payment Widgets.
 *
 * @package Payjp_For_WooCommerce
 * @license GPL-2.0-or-later
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
		$this->id           = 'payjp_paypay';
		$this->payjp_method = 'paypay';
		$this->has_fields   = true;
		// Used by the WooCommerce admin payment provider list (not the checkout icon,
		// which is rendered by the overridden get_icon() below).
		$this->icon               = PAYJP_FOR_WC_URL . 'assets/images/pp_logo_02.svg';
		$this->method_title       = __( 'PAY.JP PayPay', 'payjp-for-wc' );
		$this->method_description = __( 'Accept PayPay payments via PAY.JP v2 Payment Widgets.', 'payjp-for-wc' );
		$this->supports           = array( 'products' );

		$this->setup();

		// payment_scripts() and handle_return() are registered by setup() via the base class.
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'capture_payment' ) );
	}

	// ── Template-method implementations ──────────────────────────────────────

	/**
	 * Initialize form fields, overriding the description default for PayPay payments.
	 */
	public function init_form_fields(): void {
		parent::init_form_fields();
		$this->form_fields['description']['default'] = __(
			'After clicking "Place order", you will be taken to a secure page to complete your PayPay payment.',
			'payjp-for-wc'
		);
		$this->form_fields['capture_method'] = array(
			'title'    => __( 'Payment capture', 'payjp-for-wc' ),
			'type'     => 'select',
			'options'  => array(
				'automatic' => __( 'Capture immediately (recommended)', 'payjp-for-wc' ),
				'manual'    => __( 'Authorize only — capture when order is marked Completed', 'payjp-for-wc' ),
			),
			'default'  => 'automatic',
			'desc_tip' => false,
			'desc'     => __( 'Immediate: the payment is confirmed at checkout. Authorize only: funds are reserved now and captured automatically when you set the order to Completed.', 'payjp-for-wc' ),
		);
	}

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
		return array(
			'payNow'     => __( 'Pay with PayPay', 'payjp-for-wc' ),
			'processing' => __( 'Processing…', 'payjp-for-wc' ),
		);
	}

	/**
	 * Return the configured capture method: 'automatic' or 'manual'.
	 *
	 * @return string
	 */
	private function get_capture_method(): string {
		return 'manual' === $this->get_option( 'capture_method', 'automatic' ) ? 'manual' : 'automatic';
	}

	/**
	 * Returns the gateway icon HTML using pp_logo_02.svg for all contexts.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		$url  = PAYJP_FOR_WC_URL . 'assets/images/pp_logo_02.svg';
		$icon = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $this->get_title() ) . '" style="height:2em;width:auto;vertical-align:middle;" />';
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
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
			return array( 'result' => 'failure' );
		}

		// Correct payment_method on the order object before saving. Block Checkout creates
		// a draft order with the first available gateway; a stale WP object cache may
		// still carry that value when process_payment() is called after the StoreAPI
		// updates the DB. Explicitly setting it here ensures our save() writes the
		// correct gateway ID.
		if ( $this->id !== $order->get_payment_method() ) {
			$order->set_payment_method( $this->id );
		}

		$amount         = (int) round( $order->get_total() );
		$capture_method = $this->get_capture_method();

		try {
			$flow = $this->get_api()->post(
				'/payment_flows',
				array(
					'amount'               => $amount,
					'currency'             => 'jpy',
					'payment_method_types' => array( 'paypay' ),
					'capture_method'       => $capture_method,
				)
			);

			$flow_id       = isset( $flow['id'] ) && is_string( $flow['id'] ) ? $flow['id'] : '';
			$client_secret = isset( $flow['client_secret'] ) && is_string( $flow['client_secret'] ) ? $flow['client_secret'] : '';

			if ( ! $flow_id || ! $client_secret ) {
				wc_add_notice( esc_html__( 'PAY.JP returned an incomplete payment session. Please try again.', 'payjp-for-wc' ), 'error' );
				return array( 'result' => 'failure' );
			}

			$order->update_meta_data( '_payjp_payment_flow_id', $flow_id );
			$order->update_meta_data( '_payjp_client_secret', $client_secret );
			$order->update_meta_data( '_payjp_payment_method', 'paypay' );
			$order->update_meta_data( '_payjp_capture_method', $capture_method );
			$order->save();

			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( true ),
			);
		} catch ( RuntimeException $e ) {
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
			return array( 'result' => 'failure' );
		}
	}

	/**
	 * Capture a previously authorized (manual-capture) PayPay payment when the order is Completed.
	 *
	 * Fires on woocommerce_order_status_completed. Skips orders that were charged
	 * immediately (capture_method = automatic) or already captured.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function capture_payment( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( 'payjp_paypay' !== $order->get_payment_method() ) {
			return;
		}

		if ( 'manual' !== (string) $order->get_meta( '_payjp_capture_method' ) ) {
			return;
		}

		$flow_id = (string) $order->get_meta( '_payjp_payment_flow_id' );
		if ( ! $flow_id ) {
			return;
		}

		$logger = $this->get_logger();

		try {
			$this->get_api()->post(
				'/payment_flows/' . rawurlencode( $flow_id ) . '/capture',
				array()
			);
			/* translators: PAY.JP PayPay capture success note shown in WooCommerce order admin. */
			$order->add_order_note( __( 'PAY.JP PayPay: Payment captured successfully.', 'payjp-for-wc' ) );
			$logger->log_event( 'captured', $order_id, array( 'flow_id' => $flow_id ) );
		} catch ( RuntimeException $e ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: PAY.JP error message */
					__( 'PAY.JP PayPay: Payment capture failed — %s', 'payjp-for-wc' ),
					esc_html( $e->getMessage() )
				)
			);
			$logger->log_error( 'PayPay capture failed', $order_id, $e );
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
		<button id="payjp-paypay-pay-button" type="button" class="button alt wp-element-button">
			<?php esc_html_e( 'Pay with PayPay', 'payjp-for-wc' ); ?>
		</button>
		<p class="payjp-back-to-checkout">
			<a href="<?php echo esc_url( wc_get_checkout_url() ); ?>">
				&larr; <?php esc_html_e( 'Change payment method', 'payjp-for-wc' ); ?>
			</a>
		</p>
		<?php
	}
}
