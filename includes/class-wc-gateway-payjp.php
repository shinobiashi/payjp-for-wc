<?php
/**
 * Abstract base class shared by all PAY.JP payment gateways.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

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
		add_action( 'wp_enqueue_scripts', [ $this, 'payment_scripts' ] );
		add_action( 'template_redirect', [ $this, 'handle_return' ] );
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
			$methods = array_values( array_diff( $methods, [ $this->payjp_method ] ) );
		}

		$settings['enabled_methods'] = $methods;
		update_option( Payjp_Settings::OPTION_KEY, $settings );
	}

	/**
	 * Common form fields for all PAY.JP gateways.
	 * Subclasses may override and call parent to merge additional fields.
	 */
	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'     => [
				'title'   => __( 'Enable/Disable', 'payjp-for-wc' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this payment method', 'payjp-for-wc' ),
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( 'Title', 'payjp-for-wc' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to the customer at checkout.', 'payjp-for-wc' ),
				'default'     => $this->method_title,
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'payjp-for-wc' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown to the customer at checkout.', 'payjp-for-wc' ),
				'default'     => '',
				'desc_tip'    => true,
			],
		];
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
	 * Get a Payjp_API instance initialized with the currently active secret key.
	 */
	protected function get_api(): Payjp_API {
		return new Payjp_API( Payjp_Settings::get_secret_key() );
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
				[],
				PAYJP_FOR_WC_VERSION
			);
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external CDN; versioned by PAY.JP.
			wp_enqueue_script( 'payjp-payments-js', 'https://js.pay.jp/payments.js', [], null, true );
			wp_enqueue_script(
				$script_handle,
				PAYJP_FOR_WC_URL . 'build/frontend/' . $this->get_checkout_script_filename() . '.js',
				[ 'payjp-payments-js' ],
				PAYJP_FOR_WC_VERSION,
				true
			);
			wp_localize_script(
				$script_handle,
				$this->get_script_localize_var(),
				[
					'publicKey'      => Payjp_Settings::get_public_key(),
					'clientSecret'   => $client_secret,
					'returnUrl'      => $this->build_return_url( $order ),
					'billingDetails' => [
						'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
						'email' => $order->get_billing_email(),
						'phone' => $order->get_billing_phone(),
					],
					'i18n'           => $this->get_script_i18n(),
				]
			);
			return;
		}

		// Checkout page: enqueue scripts for the payment option display.
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external CDN; versioned by PAY.JP.
		wp_enqueue_script( 'payjp-payments-js', 'https://js.pay.jp/payments.js', [], null, true );
		wp_enqueue_script(
			$script_handle,
			PAYJP_FOR_WC_URL . 'build/frontend/' . $this->get_checkout_script_filename() . '.js',
			[ 'payjp-payments-js' ],
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
		$order_id  = absint( $_GET['order_id'] ?? 0 );
		$order_key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order = $order_id ? wc_get_order( $order_id ) : false;

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
			$this->after_payment_complete( $order, $flow );
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		if ( 'requires_capture' === $status ) {
			// Persist the flow ID as the transaction ID so the order retains
			// a reference to the PAY.JP Payment Flow for later capture or refund.
			$order->set_transaction_id( $flow_id );
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
	 * Called after $order->payment_complete() when the Payment Flow status is 'succeeded'.
	 * Subclasses may override to perform post-payment actions such as saving a card token.
	 *
	 * @param WC_Order             $order WooCommerce order.
	 * @param array<string, mixed> $flow  PAY.JP Payment Flow object.
	 */
	protected function after_payment_complete( WC_Order $order, array $flow ): void {}

	/**
	 * Build the return URL to which PAY.JP redirects after confirmPayment().
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string Absolute URL with order_id and key query args.
	 */
	protected function build_return_url( WC_Order $order ): string {
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
