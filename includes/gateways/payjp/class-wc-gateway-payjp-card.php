<?php
/**
 * Credit card payment gateway via PAY.JP v2 Payment Widgets.
 *
 * @package Payjp_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WC_Gateway_Payjp_Card' ) ) {
	return;
}

/**
 * Handles credit card payments using PAY.JP v2 Payment Widgets (embedded).
 *
 * Payment flow:
 *   1. process_payment() creates a PAY.JP Payment Flow and redirects to the
 *      WooCommerce order-pay page.
 *   2. payment_scripts() (base class) localises the client_secret on the
 *      order-pay page; receipt_page() renders the widget mount point.
 *   3. checkout-card.js mounts the payments.js widget and calls
 *      widgets.confirmPayment({ return_url }) on the "Pay now" button.
 *   4. PAY.JP handles 3-D Secure (if required) and redirects to the return URL.
 *   5. handle_return() (base class, template_redirect) verifies the Payment Flow
 *      server-side and calls $order->payment_complete().
 *
 * Saved card flow:
 *   - Logged-in customers may save cards via PAY.JP Setup Flow (add-payment-method page).
 *   - At checkout, saved tokens are listed; selecting one creates a Payment Flow
 *     with payment_method + confirm:true, succeeding immediately or falling back to 3DS.
 */
class WC_Gateway_Payjp_Card extends WC_Gateway_Payjp {

	/**
	 * Constructor: set gateway properties and initialize settings.
	 */
	public function __construct() {
		$this->id                 = 'payjp_card';
		$this->payjp_method       = 'card';
		$this->has_fields         = true;
		$this->method_title       = __( 'PAY.JP Credit Card', 'payjp-for-wc' );
		$this->method_description = __( 'Accept credit card payments via PAY.JP v2 Payment Widgets.', 'payjp-for-wc' );
		$this->supports           = array( 'products', 'refunds', 'tokenization', 'add_payment_method' );

		// Extend supports when WooCommerce Subscriptions is active.
		if ( class_exists( 'WC_Subscriptions' ) ) {
			$this->supports = array_merge(
				$this->supports,
				array(
					'subscriptions',
					'subscription_cancellation',
					'subscription_suspension',
					'subscription_reactivation',
					'subscription_amount_changes',
					'subscription_date_changes',
					'subscription_payment_method_change',
					'subscription_payment_method_change_customer',
					'subscription_payment_method_change_admin',
					'multiple_subscriptions',
				)
			);
		}

		$this->setup();

		// Remove tokenization support when the merchant has disabled card saving.
		if ( 'yes' !== $this->get_option( 'save_payment_methods', 'yes' ) ) {
			$this->supports = array_values(
				array_diff( $this->supports, array( 'tokenization', 'add_payment_method' ) )
			);
		}

		// payment_scripts() and handle_return() are registered by setup() via the base class.
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'setup_card_scripts' ) );
	}

	// ── Template-method implementations ──────────────────────────────────────

	/**
	 * Initialize form fields, overriding the description default and adding a
	 * "save payment methods" toggle specific to card payments.
	 */
	public function init_form_fields(): void {
		parent::init_form_fields();
		$this->form_fields['description']['default'] = __(
			'After clicking "Place order", you will be taken to a secure page to enter your card details.',
			'payjp-for-wc'
		);
		$this->form_fields['save_payment_methods']   = array(
			'title'   => __( 'Save payment methods', 'payjp-for-wc' ),
			'type'    => 'checkbox',
			'label'   => __( 'Allow customers to save their card for future purchases', 'payjp-for-wc' ),
			'default' => 'yes',
		);
	}

	/**
	 * Returns the script handle for the card checkout widget.
	 *
	 * @return string
	 */
	protected function get_checkout_script_handle(): string {
		return 'payjp-checkout-card';
	}

	/**
	 * Returns the compiled JS filename basename under build/frontend/.
	 *
	 * @return string
	 */
	protected function get_checkout_script_filename(): string {
		return 'checkout-card';
	}

	/**
	 * Returns the JS variable name for wp_localize_script.
	 *
	 * @return string
	 */
	protected function get_script_localize_var(): string {
		return 'payjpCardData';
	}

	/**
	 * Returns i18n strings passed to the card payment widget JS.
	 *
	 * @return array{payNow: string, processing: string}
	 */
	protected function get_script_i18n(): array {
		return array(
			'payNow'     => __( 'Pay now', 'payjp-for-wc' ),
			'processing' => __( 'Processing…', 'payjp-for-wc' ),
		);
	}

	// ── Gateway-specific methods ──────────────────────────────────────────────

	/**
	 * Enqueue setup-card.js and localise it on the My Account add-payment-method page.
	 */
	public function setup_card_scripts(): void {
		if ( ! is_wc_endpoint_url( 'add-payment-method' ) ) {
			return;
		}
		if ( ! $this->is_available() ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}

		$return_url = add_query_arg(
			'payjp-setup-return',
			'1',
			wc_get_account_endpoint_url( 'payment-methods' )
		);

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external CDN; versioned by PAY.JP.
		wp_enqueue_script( 'payjp-payments-js', 'https://js.pay.jp/payments.js', array(), null, true );
		wp_enqueue_script(
			'payjp-setup-card',
			PAYJP_FOR_WC_URL . 'build/frontend/setup-card.js',
			array( 'payjp-payments-js' ),
			PAYJP_FOR_WC_VERSION,
			true
		);
		wp_localize_script(
			'payjp-setup-card',
			'payjpSetupData',
			array(
				'publicKey' => Payjp_Settings::get_public_key(),
				'returnUrl' => $return_url,
				'restUrl'   => rest_url( 'payjp/v2/setup-flow' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'addCard'      => __( 'Add card', 'payjp-for-wc' ),
					'processing'   => __( 'Processing…', 'payjp-for-wc' ),
					'errorGeneric' => __( 'An error occurred. Please try again.', 'payjp-for-wc' ),
				),
			)
		);
	}

	/**
	 * Render the payment form placeholder shown at WooCommerce checkout.
	 *
	 * On the checkout page, shows saved tokens and a "save for later" checkbox
	 * when the customer is logged in. The actual widget mounts on the order-pay
	 * page via receipt_page(). On the add-payment-method page, renders the
	 * setup widget mount point for setup-card.js.
	 */
	public function payment_fields(): void {
		if ( is_wc_endpoint_url( 'add-payment-method' ) ) {
			echo '<div id="payjp-setup-form"></div>';
			echo '<div id="payjp-setup-errors" role="alert" aria-live="polite"></div>';
			return;
		}

		if ( $this->description ) {
			echo wp_kses_post( wpautop( $this->description ) );
		}

		if ( is_user_logged_in() && $this->supports( 'tokenization' ) ) {
			$this->tokenization_script();
			$this->saved_payment_methods();
			$this->save_payment_method_checkbox();
		}

		echo '<div class="wc-payment-form">';
		echo '<div id="payjp-card-form"></div>';
		echo '<div id="payjp-card-errors" role="alert" aria-live="polite"></div>';
		echo '</div>';
	}

	/**
	 * Create a PAY.JP Payment Flow for the order and redirect to the order-pay
	 * page where the payments.js widget will be rendered.
	 *
	 * When a saved token is selected, creates a Payment Flow with confirm:true
	 * for an immediate charge (or 3DS fallback via order-pay page).
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

		// WC checkout verifies nonce before calling process_payment; no further nonce check needed here.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$raw_token_id = isset( $_POST['wc-payjp_card-payment-token'] ) && is_string( $_POST['wc-payjp_card-payment-token'] )
			? sanitize_text_field( wp_unslash( $_POST['wc-payjp_card-payment-token'] ) )
			: '';

		// Flag the order for card saving if the customer checked the checkbox.
		// Classic checkout sends the string 'true'; Block checkout (WC Blocks Store API)
		// serialises the JS boolean as '1', so both values must be accepted.
		$save_card_raw = isset( $_POST['wc-payjp_card-new-payment-method'] ) && is_string( $_POST['wc-payjp_card-new-payment-method'] )
			? sanitize_text_field( wp_unslash( $_POST['wc-payjp_card-new-payment-method'] ) )
			: '';
		$save_card     = 'true' === $save_card_raw || '1' === $save_card_raw;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// WCS subscription payment method change: $0 order linked to a subscription.
		// PAY.JP charges cannot be zero; bypass the payment flow and just validate the token.
		if ( 0 === (int) round( $order->get_total() )
			&& function_exists( 'wcs_order_contains_subscription' )
			&& wcs_order_contains_subscription( $order )
		) {
			return $this->process_subscription_method_change( $order, $raw_token_id );
		}

		if ( $raw_token_id && 'new' !== $raw_token_id ) {
			return $this->process_payment_with_token( $order, absint( $raw_token_id ) );
		}

		if ( $save_card && is_user_logged_in() ) {
			$order->update_meta_data( '_payjp_save_card', '1' );
		}

		$amount = (int) round( $order->get_total() );

		if ( $amount < 50 ) {
			wc_add_notice(
				__( 'The minimum order amount for PAY.JP card payments is ¥50.', 'payjp-for-wc' ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		try {
			$flow = $this->get_api()->post(
				'/payment_flows',
				array(
					'amount'               => $amount,
					'currency'             => 'jpy',
					'payment_method_types' => array( 'card' ),
					'capture_method'       => 'automatic',
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
			$order->update_meta_data( '_payjp_payment_method', 'card' );
			$order->update_meta_data( '_payjp_capture_method', 'automatic' );
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
	 * Charge a previously saved payment token.
	 *
	 * Creates a Payment Flow with the stored PaymentMethod ID and confirm:true.
	 * If the charge succeeds without 3DS, the order is completed immediately.
	 * If 3DS is required (requires_action), falls back to the order-pay widget flow.
	 *
	 * @param WC_Order $order    WooCommerce order.
	 * @param int      $token_id WooCommerce Payment Token ID.
	 * @return array{result: string, redirect?: string}
	 */
	private function process_payment_with_token( WC_Order $order, int $token_id ): array {
		$token = WC_Payment_Tokens::get( $token_id );

		if ( ! $token
			|| $token->get_user_id() !== get_current_user_id()
			|| 'payjp_card' !== $token->get_gateway_id()
		) {
			wc_add_notice( esc_html__( 'Invalid payment token. Please try again.', 'payjp-for-wc' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$pm_id       = $token->get_token();
		$amount      = (int) round( $order->get_total() );
		$customer_id = Payjp_Token_Manager::get_customer_id( (int) $order->get_customer_id() );

		$payload = array(
			'amount'            => $amount,
			'currency'          => 'jpy',
			'payment_method_id' => $pm_id,
			'confirm'           => true,
			'return_url'        => $this->build_return_url( $order ),
			'capture_method'    => 'automatic',
		);

		if ( $customer_id ) {
			$payload['customer_id'] = $customer_id;
		}

		try {
			$flow = $this->get_api()->post( '/payment_flows', $payload );
		} catch ( RuntimeException $e ) {
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
			return array( 'result' => 'failure' );
		}

		$flow_id       = isset( $flow['id'] ) && is_string( $flow['id'] ) ? $flow['id'] : '';
		$status        = isset( $flow['status'] ) && is_string( $flow['status'] ) ? $flow['status'] : '';
		$client_secret = isset( $flow['client_secret'] ) && is_string( $flow['client_secret'] ) ? $flow['client_secret'] : '';

		if ( ! $flow_id ) {
			wc_add_notice( esc_html__( 'PAY.JP returned an incomplete payment session. Please try again.', 'payjp-for-wc' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_payjp_payment_flow_id', $flow_id );
		$order->update_meta_data( '_payjp_payment_method', 'card' );
		$order->update_meta_data( '_payjp_capture_method', 'automatic' );

		if ( 'succeeded' === $status ) {
			$order->save();
			$order->payment_complete( $flow_id );
			$this->after_payment_complete( $order, $flow );
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_order_received_url(),
			);
		}

		// Explicit failure — surface an error immediately without redirecting.
		if ( in_array( $status, array( 'payment_failed', 'requires_payment_method' ), true ) ) {
			wc_add_notice( esc_html__( 'Payment failed. Please try a different card.', 'payjp-for-wc' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// 3DS or other action required — redirect to order-pay page for widget handling.
		if ( $client_secret ) {
			$order->update_meta_data( '_payjp_client_secret', $client_secret );
		}
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * After a successful payment, save the card if the customer opted in.
	 *
	 * @param WC_Order             $order WooCommerce order.
	 * @param array<string, mixed> $flow  PAY.JP Payment Flow object.
	 */
	protected function after_payment_complete( WC_Order $order, array $flow ): void {
		Payjp_Token_Manager::maybe_save_card_after_payment( $order, $flow, $this->get_api() );
		Payjp_Subscriptions::store_payment_method_on_subscriptions( $order, $flow );
	}

	/**
	 * Handle "Add payment method" from My Account.
	 *
	 * The setup-card.js script intercepts the form submit and handles the full
	 * Setup Flow in the browser. This method is only reached when JavaScript is
	 * unavailable; show an explicit error rather than silently looping.
	 *
	 * @return array{result: string}
	 */
	public function add_payment_method(): array {
		wc_add_notice(
			__( 'JavaScript is required to add a payment method. Please enable JavaScript and try again.', 'payjp-for-wc' ),
			'error'
		);
		return array( 'result' => 'failure' );
	}

	/**
	 * Process a refund for a card order via the PAY.JP v2 Refunds API.
	 *
	 * @param int        $order_id WooCommerce order ID.
	 * @param float|null $amount   Refund amount. Omit (null) for a full refund.
	 * @param string     $reason   Free-text reason from the WooCommerce admin (not forwarded to PAY.JP).
	 * @return bool|\WP_Error True on success; WP_Error describing the failure.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'invalid_order', __( 'Order not found.', 'payjp-for-wc' ) );
		}

		$flow_id = (string) $order->get_meta( '_payjp_payment_flow_id' );
		if ( ! $flow_id ) {
			return new \WP_Error(
				'no_flow_id',
				__( 'No PAY.JP Payment Flow ID found for this order.', 'payjp-for-wc' )
			);
		}

		$body = array( 'payment_flow' => $flow_id );

		// Only send amount for partial refunds; omit to trigger a full refund.
		if ( null !== $amount && $amount > 0 ) {
			$body['amount'] = (int) round( $amount );
		}

		try {
			$refund = $this->get_api()->post( '/refunds', $body );
		} catch ( RuntimeException $e ) {
			return new \WP_Error( 'payjp_refund_error', $e->getMessage() );
		}

		$refund_id = isset( $refund['id'] ) && is_string( $refund['id'] ) ? $refund['id'] : '';
		if ( ! $refund_id ) {
			return new \WP_Error(
				'payjp_refund_error',
				__( 'PAY.JP returned an incomplete refund response.', 'payjp-for-wc' )
			);
		}

		// Seed the idempotency marker so the refund.created webhook skips adding a duplicate note.
		$order->update_meta_data( '_payjp_refund_processed_' . $refund_id, '1' );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: PAY.JP refund confirmation shown in WooCommerce order notes. %s: PAY.JP refund ID. */
				__( 'PAY.JP refund processed. Refund ID: %s.', 'payjp-for-wc' ),
				esc_html( $refund_id )
			)
		);

		return true;
	}

	/**
	 * Handle a WCS subscription payment method change ($0 order).
	 *
	 * WooCommerce Subscriptions creates a $0 order when the customer or admin changes
	 * the payment method on a subscription. No API charge is needed; validate the
	 * selected saved token and mark the order paid so WCS updates the subscription.
	 *
	 * @param WC_Order $order        Zero-value change-payment-method order from WCS.
	 * @param string   $raw_token_id Selected WC payment token ID, or empty/'new' if none chosen.
	 * @return array{result: string, redirect?: string}
	 */
	private function process_subscription_method_change( WC_Order $order, string $raw_token_id ): array {
		if ( ! $raw_token_id || 'new' === $raw_token_id ) {
			wc_add_notice(
				__( 'Please select a saved card to update your subscription payment method.', 'payjp-for-wc' ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		$token = WC_Payment_Tokens::get( absint( $raw_token_id ) );
		if (
			! $token
			|| (int) $token->get_user_id() !== get_current_user_id()
			|| 'payjp_card' !== $token->get_gateway_id()
		) {
			wc_add_notice( esc_html__( 'Invalid payment token. Please try again.', 'payjp-for-wc' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->payment_complete();
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_order_received_url(),
		);
	}

	/**
	 * Render the payments.js widget mount point on the WooCommerce order-pay page.
	 * Fired by the woocommerce_receipt_payjp_card action.
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
		<div id="payjp-card-receipt-form"></div>
		<div id="payjp-card-errors" role="alert" aria-live="polite"></div>
		<button id="payjp-card-pay-button" type="button" class="button alt wp-element-button">
			<?php esc_html_e( 'Pay now', 'payjp-for-wc' ); ?>
		</button>
		<p class="payjp-back-to-checkout">
			<a href="<?php echo esc_url( wc_get_checkout_url() ); ?>">
				&larr; <?php esc_html_e( 'Change payment method', 'payjp-for-wc' ); ?>
			</a>
		</p>
		<?php
	}
}
