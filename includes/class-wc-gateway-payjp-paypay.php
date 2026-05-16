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
 *   2. payment_scripts() localises the client_secret on the order-pay page;
 *      receipt_page() renders the widget mount point.
 *   3. checkout-paypay.js mounts the payments.js widget and calls
 *      widgets.confirmPayment({ return_url }) on the "Pay with PayPay" button.
 *   4. PAY.JP redirects the customer to the PayPay app or website.
 *   5. handle_return() (template_redirect) verifies the Payment Flow server-side
 *      and calls $order->payment_complete().
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

		add_action( 'wp_enqueue_scripts', [ $this, 'payment_scripts' ] );
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'receipt_page' ] );
		add_action( 'template_redirect', [ $this, 'handle_return' ] );
	}

	/**
	 * Enqueue payments.js (CDN) and checkout-paypay.js.
	 * On the order-pay page, validates order ownership first so scripts are not
	 * enqueued for orders belonging to a different gateway.
	 * Also localises the Payment Flow data for the widget.
	 */
	public function payment_scripts(): void {
		if ( ! is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}
		if ( ! $this->is_available() ) {
			return;
		}

		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			// Resolve order before enqueuing: skip if this gateway does not own the order.
			$order_id = absint( get_query_var( 'order-pay' ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- order key validated against DB below.
			$order_key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
			$order     = wc_get_order( $order_id );

			if ( ! $order || $order->get_order_key() !== $order_key || $this->id !== $order->get_payment_method() ) {
				return;
			}

			$client_secret = (string) $order->get_meta( '_payjp_client_secret' );
			$flow_id       = (string) $order->get_meta( '_payjp_payment_flow_id' );

			if ( ! $client_secret || ! $flow_id ) {
				return;
			}

			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external CDN; versioned by PAY.JP.
			wp_enqueue_script( 'payjp-payments-js', 'https://js.pay.jp/payments.js', [], null, true );
			wp_enqueue_script(
				'payjp-checkout-paypay',
				PAYJP_FOR_WC_URL . 'build/frontend/checkout-paypay.js',
				[ 'payjp-payments-js' ],
				PAYJP_FOR_WC_VERSION,
				true
			);
			wp_localize_script(
				'payjp-checkout-paypay',
				'payjpPaypayData',
				[
					'publicKey'    => Payjp_Settings::get_public_key(),
					'clientSecret' => $client_secret,
					'returnUrl'    => $this->build_return_url( $order ),
					'i18n'         => [
						'payNow'     => __( 'Pay with PayPay', 'payjp-for-wc' ),
						'processing' => __( 'Processing…', 'payjp-for-wc' ),
					],
				]
			);
			return;
		}

		// Checkout page: enqueue scripts for the payment option display.
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external CDN; versioned by PAY.JP.
		wp_enqueue_script( 'payjp-payments-js', 'https://js.pay.jp/payments.js', [], null, true );
		wp_enqueue_script(
			'payjp-checkout-paypay',
			PAYJP_FOR_WC_URL . 'build/frontend/checkout-paypay.js',
			[ 'payjp-payments-js' ],
			PAYJP_FOR_WC_VERSION,
			true
		);
	}

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
		<button id="payjp-paypay-pay-button" class="button alt">
			<?php esc_html_e( 'Pay with PayPay', 'payjp-for-wc' ); ?>
		</button>
		<?php
	}

	/**
	 * Handle the PAY.JP return redirect after confirmPayment().
	 * Verifies the Payment Flow server-side, then marks the order complete.
	 * Fires on template_redirect.
	 */
	public function handle_return(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- return URL set by PAY.JP; validated via order key + server-side API call.
		if ( empty( $_GET['payjp-return'] ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order_id  = absint( $_GET['order_id'] ?? 0 );
		$order_key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order = $order_id ? wc_get_order( $order_id ) : false;

		if (
			! $order ||
			$order->get_order_key() !== $order_key ||
			$this->id !== $order->get_payment_method()
		) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( $order->is_paid() ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		// Authoritative flow ID comes from order meta, not the URL.
		$flow_id = (string) $order->get_meta( '_payjp_payment_flow_id' );
		if ( ! $flow_id ) {
			wc_add_notice( __( 'Payment verification failed. Please contact support.', 'payjp-for-wc' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		try {
			$flow = $this->get_api()->get( '/payment_flows/' . rawurlencode( $flow_id ) );
		} catch ( RuntimeException $e ) {
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$status = (string) ( $flow['status'] ?? '' );

		if ( 'succeeded' === $status ) {
			$order->payment_complete( $flow_id );
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		if ( 'requires_capture' === $status ) {
			/* translators: PAY.JP order-hold note shown in WooCommerce admin */
			$order->update_status( 'on-hold', __( 'PAY.JP authorised. Awaiting manual capture.', 'payjp-for-wc' ) );
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		wc_add_notice( __( 'Payment was not completed. Please try again.', 'payjp-for-wc' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Build the return URL to which PAY.JP redirects after confirmPayment().
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string Absolute URL with order_id and key query args.
	 */
	private function build_return_url( WC_Order $order ): string {
		return add_query_arg(
			[
				'payjp-return' => '1',
				'order_id'     => $order->get_id(),
				'key'          => $order->get_order_key(),
			],
			home_url( '/' )
		);
	}
}
