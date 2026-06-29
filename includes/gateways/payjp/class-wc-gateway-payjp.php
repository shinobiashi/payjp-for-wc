<?php
/**
 * Abstract base class shared by all PAY.JP payment gateways.
 *
 * @package Payjp_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

use ArtisanWorkshop\WCLogger\v1_0_0\JP4WC_Logger;

if ( class_exists( 'WC_Gateway_Payjp' ) ) {
	return;
}

/**
 * Abstract PAY.JP gateway.
 * Provides shared constructor setup, availability check, API helper,
 * and template-method implementations of payment_scripts() and handle_return().
 *
 * Subclasses must implement:
 *   - get_checkout_script_handle()
 *   - get_script_localize_var()
 *   - get_script_i18n()
 *   - receipt_page()
 *   - process_payment()
 */
abstract class WC_Gateway_Payjp extends WC_Payment_Gateway_CC {

	/**
	 * PAY.JP payment method slug: 'card' or 'paypay'.
	 * Subclasses must set this before calling setup().
	 *
	 * @var string
	 */
	protected string $payjp_method = '';

	/**
	 * Initialize shared gateway properties and register shared frontend hooks.
	 * Must be called from each subclass constructor after setting id and payjp_method.
	 */
	protected function setup(): void {
		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		// Wrap in a void closure: process_admin_options() returns bool but
		// action callbacks must not return values (PHPStan rule).
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			function () {
				$this->process_admin_options();
				// Keep payjp_settings['enabled_methods'] in sync when the individual
				// gateway settings page is saved, so neither source of truth diverges.
				$this->sync_enabled_to_shared_settings();
			}
		);

		// Shared frontend hooks — subclasses only need to register receipt_page.
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		add_action( 'template_redirect', array( $this, 'handle_return' ) );
	}

	/**
	 * After saving individual gateway options, propagate the 'enabled' state
	 * back to payjp_settings['enabled_methods'] so the unified PAY.JP settings
	 * page always reflects the current state.
	 */
	private function sync_enabled_to_shared_settings(): void {
		$is_enabled = 'yes' === $this->get_option( 'enabled' );
		$settings   = Payjp_Settings::get_all();
		// Seed from get_enabled_methods() so the full, correctly-derived list is
		// used (handles upgrade from pre-Phase-2, fresh install, and saved state)
		// rather than silently discarding other gateways when the key is absent.
		$methods = Payjp_Settings::get_enabled_methods();

		if ( $is_enabled ) {
			if ( ! in_array( $this->payjp_method, $methods, true ) ) {
				$methods[] = $this->payjp_method;
			}
		} else {
			$methods = array_values( array_diff( $methods, array( $this->payjp_method ) ) );
		}

		$settings['enabled_methods'] = $methods;
		update_option( Payjp_Settings::OPTION_KEY, $settings );
		Payjp_Settings::flush_cache();
	}

	/**
	 * Common form fields for all PAY.JP gateways.
	 * Subclasses may override and call parent to merge additional fields.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'payjp-for-wc' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this payment method', 'payjp-for-wc' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'payjp-for-wc' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to the customer at checkout.', 'payjp-for-wc' ),
				'default'     => $this->method_title,
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'payjp-for-wc' ),
				'type'        => 'textarea',
				/* translators: Admin tooltip for payment method description field. */
				'description' => __( 'Payment method description shown to the customer at checkout. Basic HTML tags are supported (e.g. &lt;strong&gt;, &lt;a&gt;, &lt;br&gt;).', 'payjp-for-wc' ),
				'default'     => '',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Available when the parent check passes, the method is enabled in shared settings,
	 * and API keys are configured.
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( ! Payjp_Settings::is_method_enabled( $this->payjp_method ) ) {
			return false;
		}

		if ( ! Payjp_Settings::get_public_key() || ! Payjp_Settings::get_secret_key() ) {
			return false;
		}

		return true;
	}

	/**
	 * Get a Payjp_API instance initialized with the currently active secret key and logger.
	 */
	protected function get_api(): Payjp_API {
		return new Payjp_API( Payjp_Settings::get_secret_key(), $this->get_logger() );
	}

	/**
	 * Get the shared JP4WC_Logger instance for this plugin.
	 */
	protected function get_logger(): JP4WC_Logger {
		return JP4WC_Logger::get_instance(
			'payjp-for-wc',
			static fn() => (bool) Payjp_Settings::get( 'debug_log' )
		);
	}

	// ── Template-method hooks for payment_scripts() ───────────────────────────

	/**
	 * WordPress script handle passed to wp_enqueue_script / wp_localize_script.
	 * Example: 'payjp-checkout-card'.
	 *
	 * @return string
	 */
	abstract protected function get_checkout_script_handle(): string;

	/**
	 * Basename of the compiled JS file under build/frontend/ (without .js).
	 * Example: 'checkout-card'.
	 *
	 * @return string
	 */
	abstract protected function get_checkout_script_filename(): string;

	/**
	 * JavaScript variable name used by wp_localize_script.
	 * Example: 'payjpCardData'.
	 *
	 * @return string
	 */
	abstract protected function get_script_localize_var(): string;

	/**
	 * I18n strings passed to the payment widget JS.
	 *
	 * @return array{payNow: string, processing: string}
	 */
	abstract protected function get_script_i18n(): array;

	// ── Shared frontend methods ───────────────────────────────────────────────

	/**
	 * Enqueue payments.js (CDN) and the gateway-specific widget script.
	 * On the order-pay page, validates order ownership before enqueuing so only
	 * the gateway that owns the order enqueues its scripts (prevents duplicate
	 * CDN requests when multiple PAY.JP gateways are active).
	 * Localises Payment Flow data for the widget on the order-pay page.
	 * Registered on wp_enqueue_scripts by setup().
	 */
	public function payment_scripts(): void {
		if ( ! is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}
		if ( ! $this->is_available() ) {
			return;
		}

		$script_handle = $this->get_checkout_script_handle();

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

			wp_enqueue_style(
				'payjp-receipt',
				PAYJP_FOR_WC_URL . 'assets/css/payjp-receipt.css',
				array(),
				PAYJP_FOR_WC_VERSION
			);
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external CDN; versioned by PAY.JP.
			wp_enqueue_script( 'payjp-payments-js', 'https://js.pay.jp/payments.js', array(), null, true );
			wp_enqueue_script(
				$script_handle,
				PAYJP_FOR_WC_URL . 'build/frontend/' . $this->get_checkout_script_filename() . '.js',
				array( 'payjp-payments-js' ),
				PAYJP_FOR_WC_VERSION,
				true
			);
			wp_localize_script(
				$script_handle,
				$this->get_script_localize_var(),
				array(
					'publicKey'      => Payjp_Settings::get_public_key(),
					'clientSecret'   => $client_secret,
					'returnUrl'      => $this->build_return_url( $order ),
					'billingDetails' => array(
						'email' => $order->get_billing_email(),
						'phone' => $order->get_billing_phone(),
					),
					'i18n'           => $this->get_script_i18n(),
				)
			);
			return;
		}

		// Checkout page: enqueue scripts for the payment option display.
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external CDN; versioned by PAY.JP.
		wp_enqueue_script( 'payjp-payments-js', 'https://js.pay.jp/payments.js', array(), null, true );
		wp_enqueue_script(
			$script_handle,
			PAYJP_FOR_WC_URL . 'build/frontend/' . $this->get_checkout_script_filename() . '.js',
			array( 'payjp-payments-js' ),
			PAYJP_FOR_WC_VERSION,
			true
		);
	}

	/**
	 * Handle the PAY.JP return redirect after confirmPayment().
	 * Verifies the Payment Flow server-side, then marks the order complete.
	 * Only processes returns for orders belonging to this gateway ($this->id).
	 * Registered on template_redirect by setup().
	 */
	public function handle_return(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- return URL set by PAY.JP; validated via order key + server-side API call.
		if ( empty( $_GET['payjp-return'] ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$url_order_id = absint( $_GET['order_id'] ?? 0 );
		$order_key    = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order = $url_order_id ? wc_get_order( $url_order_id ) : false;

		if ( ! $order || $order->get_order_key() !== $order_key ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// Order belongs to another PAY.JP gateway — let its handle_return() run.
		if ( $this->id !== $order->get_payment_method() ) {
			return;
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

		$order_id = $order->get_id();  // verified against $url_order_id; use typed int from WC_Order.
		$logger   = $this->get_logger();

		try {
			$flow = $this->get_api()->get( '/payment_flows/' . rawurlencode( $flow_id ), $order_id );
		} catch ( RuntimeException $e ) {
			// Payjp_API already logged this exception before rethrowing; no duplicate log here.
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$status = (string) ( $flow['status'] ?? '' );

		if ( 'succeeded' === $status ) {
			$order->payment_complete( $flow_id );
			$logger->log_event( 'succeeded', $order_id, array( 'flow_id' => $flow_id ) );
			$this->after_payment_complete( $order, $flow );
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		if ( 'requires_capture' === $status ) {
			// Persist the flow ID as the transaction ID so the order retains
			// a reference to the PAY.JP Payment Flow for later capture or refund.
			$order->set_transaction_id( $flow_id );
			$order->save();
			// Set to "processing" so the merchant can fulfil and then Complete the order,
			// which triggers capture_payment(). "on-hold" implies a problem to customers.
			/* translators: PAY.JP order note shown in WooCommerce admin for manual-capture orders */
			$order->update_status( 'processing', __( 'PAY.JP authorised. Payment will be captured when the order is marked Completed.', 'payjp-for-wc' ) );
			$logger->log_event( 'authorized', $order_id, array( 'flow_id' => $flow_id ) );
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		// Payment methods such as PayPay confirm asynchronously: the user is redirected
		// back while PAY.JP's backend is still processing. "requires_action" at this
		// point means the customer completed the off-site flow and the payment is
		// pending server-side confirmation. Redirect to the thank-you page and let
		// the payment_flow.succeeded webhook call payment_complete() a few seconds later.
		if ( 'requires_action' === $status ) {
			$logger->log_event( 'payment_pending_confirmation', $order_id, array( 'flow_id' => $flow_id ) );
			$order->add_order_note(
				__( 'PAY.JP payment is awaiting confirmation. Order will be updated automatically via webhook.', 'payjp-for-wc' )
			);
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		$logger->log_event( 'payment_incomplete', $order_id, array( 'flow_status' => $status ) );
		wc_add_notice( __( 'Payment was not completed. Please try again.', 'payjp-for-wc' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Called after $order->payment_complete() when the Payment Flow status is 'succeeded'.
	 * Subclasses may override to perform post-payment actions such as saving a card token.
	 *
	 * @param WC_Order             $order WooCommerce order.
	 * @param array<string, mixed> $flow  PAY.JP Payment Flow object.
	 */
	protected function after_payment_complete( WC_Order $order, array $flow ): void {}

	/**
	 * Execute a PAY.JP refund via POST /payment_refunds and record an order note.
	 *
	 * Shared implementation used by card and PayPay subclasses.
	 * Callers are responsible for ensuring the order belongs to their gateway.
	 *
	 * For manual-capture card orders whose PaymentFlow is still in requires_capture state
	 * (i.e. the authorization has not yet been captured), POST /payment_refunds is not
	 * available. In that case this method calls cancel_flow() to void the authorization
	 * via POST /payment_flows/{id}/cancel instead.
	 *
	 * @param int        $order_id   WooCommerce order ID.
	 * @param float|null $amount     Refund amount; null triggers a full refund.
	 * @param string     $note_label Gateway-specific label for the order note (e.g. "PAY.JP" or "PAY.JP PayPay").
	 * @return bool|\WP_Error True on success; WP_Error on failure.
	 */
	protected function do_refund( int $order_id, ?float $amount, string $note_label ): bool|\WP_Error {
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

		// Manual-capture orders may still be in requires_capture state (authorized but not yet
		// captured). POST /payment_refunds requires status succeeded; for uncaptured flows we
		// must cancel the authorization via POST /payment_flows/{id}/cancel instead.
		if ( 'manual' === (string) $order->get_meta( '_payjp_capture_method' ) ) {
			try {
				$flow        = $this->get_api()->get( '/payment_flows/' . rawurlencode( $flow_id ), $order_id );
				$flow_status = isset( $flow['status'] ) && is_string( $flow['status'] ) ? $flow['status'] : '';
			} catch ( RuntimeException $e ) {
				return new \WP_Error( 'payjp_api_error', $e->getMessage() );
			}

			if ( 'requires_capture' === $flow_status ) {
				// Partial void is impossible; only a full authorization cancellation is supported.
				if ( null !== $amount && $amount < (float) $order->get_total() - 0.01 ) {
					return new \WP_Error(
						'payjp_partial_void_not_supported',
						__( 'Partial refunds are not available for uncaptured payments. Complete the order first to capture the payment, then issue a partial refund.', 'payjp-for-wc' )
					);
				}
				return $this->cancel_flow( $order, $flow_id, $note_label );
			}
		}

		$body = array( 'payment_flow_id' => $flow_id );

		// Only send amount for partial refunds; omit to trigger a full refund.
		if ( null !== $amount && $amount > 0 ) {
			$body['amount'] = (int) round( $amount );
		}

		try {
			$refund = $this->get_api()->post( '/payment_refunds', $body );
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
				/* translators: 1: Gateway label (e.g. "PAY.JP" or "PAY.JP PayPay"), 2: PAY.JP refund ID. */
				__( '%1$s refund processed. Refund ID: %2$s.', 'payjp-for-wc' ),
				esc_html( $note_label ),
				esc_html( $refund_id )
			)
		);

		return true;
	}

	/**
	 * Cancel (void) a PaymentFlow that is in requires_capture state.
	 *
	 * Called when a refund is requested for a manual-capture order whose payment has not
	 * yet been captured. POST /payment_refunds is only valid for captured (succeeded) flows;
	 * for authorized-but-uncaptured flows, POST /payment_flows/{id}/cancel voids the
	 * authorization and releases the reserved funds back to the customer.
	 *
	 * @param WC_Order $order      WooCommerce order object.
	 * @param string   $flow_id    PAY.JP Payment Flow ID.
	 * @param string   $note_label Gateway-specific label for the order note.
	 * @return bool|\WP_Error True on success; WP_Error on failure.
	 */
	private function cancel_flow( WC_Order $order, string $flow_id, string $note_label ): bool|\WP_Error {
		try {
			$this->get_api()->post(
				'/payment_flows/' . rawurlencode( $flow_id ) . '/cancel',
				array()
			);
		} catch ( RuntimeException $e ) {
			return new \WP_Error( 'payjp_cancel_error', $e->getMessage() );
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: Gateway label (e.g. "PAY.JP"). */
				__( '%s payment authorization voided (payment was not yet captured).', 'payjp-for-wc' ),
				esc_html( $note_label )
			)
		);

		return true;
	}

	/**
	 * Cancel the PAY.JP Payment Flow when the WooCommerce order is cancelled.
	 *
	 * Fetches the current flow status from the API and either calls the cancel
	 * endpoint (for flows not yet captured) or adds an order note directing the
	 * merchant to issue a manual refund via the WooCommerce Refund button
	 * (for already-captured flows).
	 *
	 * Status handling:
	 *   - requires_payment_method / requires_action / requires_capture → POST /cancel
	 *   - succeeded → order note only (use WC Refund button)
	 *   - canceled / payment_failed → skip (already terminal)
	 *
	 * @param WC_Order $order      WooCommerce order object.
	 * @param string   $note_label Gateway-specific label for order notes, e.g. "PAY.JP".
	 */
	protected function cancel_payment_flow( WC_Order $order, string $note_label ): void {
		$flow_id = (string) $order->get_meta( '_payjp_payment_flow_id' );
		if ( ! $flow_id ) {
			// Payment Flow was never created (order cancelled before process_payment ran).
			return;
		}

		$order_id = $order->get_id();
		$logger   = $this->get_logger();

		try {
			$flow   = $this->get_api()->get( '/payment_flows/' . rawurlencode( $flow_id ), $order_id );
			$status = isset( $flow['status'] ) && is_string( $flow['status'] ) ? $flow['status'] : '';
		} catch ( RuntimeException $e ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: Gateway label (e.g. "PAY.JP"), 2: API error message. */
					__( '%1$s: Could not retrieve payment status during order cancellation — %2$s', 'payjp-for-wc' ),
					esc_html( $note_label ),
					esc_html( $e->getMessage() )
				)
			);
			$logger->log_error( 'cancel_payment_flow: API fetch failed', $order_id, $e );
			return;
		}

		// Already in a terminal state — nothing to do.
		if ( in_array( $status, array( 'canceled', 'payment_failed' ), true ) ) {
			return;
		}

		// Payment already captured: the cancel endpoint is unavailable for succeeded flows.
		// Issue a full refund automatically via wc_create_refund(), which creates a WC
		// refund record and calls process_refund() → do_refund() → PAY.JP /payment_refunds.
		if ( 'succeeded' === $status ) {
			$refund = wc_create_refund(
				array(
					'order_id' => $order_id,
					'amount'   => $order->get_total(),
					/* translators: WooCommerce refund reason recorded when an order is cancelled after payment. */
					'reason'   => __( 'Order cancelled.', 'payjp-for-wc' ),
				)
			);

			if ( is_wp_error( $refund ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: Gateway label (e.g. "PAY.JP"), 2: error message. */
						__( '%1$s: Automatic refund on cancellation failed — %2$s', 'payjp-for-wc' ),
						esc_html( $note_label ),
						esc_html( $refund->get_error_message() )
					)
				);
				$logger->log_error( 'cancel_payment_flow: auto-refund failed', $order_id, new \RuntimeException( $refund->get_error_message() ) );
			}
			// On success: do_refund() already adds "PAY.JP refund processed" order note.
			return;
		}

		// Cancelable states: requires_payment_method, requires_confirmation,
		// requires_action, processing, requires_capture.
		try {
			$this->get_api()->post(
				'/payment_flows/' . rawurlencode( $flow_id ) . '/cancel',
				array( 'cancellation_reason' => 'requested_by_customer' )
			);
			$order->add_order_note(
				sprintf(
					/* translators: %s: Gateway label (e.g. "PAY.JP"). */
					__( '%s payment cancelled successfully.', 'payjp-for-wc' ),
					esc_html( $note_label )
				)
			);
			$logger->log_event(
				'cancelled',
				$order_id,
				array(
					'flow_id'     => $flow_id,
					'flow_status' => $status,
				)
			);
		} catch ( RuntimeException $e ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: Gateway label (e.g. "PAY.JP"), 2: API error message. */
					__( '%1$s: Payment cancellation failed — %2$s', 'payjp-for-wc' ),
					esc_html( $note_label ),
					esc_html( $e->getMessage() )
				)
			);
			$logger->log_error( 'cancel_payment_flow: cancel API failed', $order_id, $e );
		}
	}

	/**
	 * Build the return URL to which PAY.JP redirects after confirmPayment().
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string Absolute URL with order_id and key query args.
	 */
	protected function build_return_url( WC_Order $order ): string {
		return add_query_arg(
			array(
				'payjp-return' => '1',
				'order_id'     => $order->get_id(),
				'key'          => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}
}
