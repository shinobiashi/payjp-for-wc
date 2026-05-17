<?php
/**
 * PAY.JP card token manager.
 *
 * Manages PAY.JP Customer and PaymentMethod objects, and integrates them
 * with the WooCommerce Payment Token API for saved card functionality.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'Payjp_Token_Manager' ) ) {
	return;
}

/**
 * Handles card tokenization via PAY.JP Setup Flow, stores tokens using the
 * WooCommerce Token API, and exposes them for re-use at checkout.
 */
class Payjp_Token_Manager {

	/**
	 * WordPress user meta key for the PAY.JP Customer ID.
	 */
	const USER_META_CUSTOMER_ID = '_payjp_customer_id';

	/**
	 * Register REST and template_redirect hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );
		add_action( 'template_redirect', [ self::class, 'handle_setup_return' ] );
	}

	/**
	 * Register the REST endpoint for creating Setup Flows.
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'payjp/v1',
			'/setup-flow',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'rest_create_setup_flow' ],
				'permission_callback' => static function (): bool {
					return is_user_logged_in();
				},
			]
		);
	}

	/**
	 * REST callback: create a PAY.JP Setup Flow for the logged-in user.
	 *
	 * @param WP_REST_Request $request REST request object (unused; auth handled by permission_callback).
	 * @return WP_REST_Response JSON response containing setup_flow_id and client_secret.
	 */
	public static function rest_create_setup_flow( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$user_id = get_current_user_id();
		$api     = new Payjp_API( Payjp_Settings::get_secret_key() );

		try {
			$customer_id = self::get_or_create_customer( $user_id, $api );
		} catch ( RuntimeException $e ) {
			return new WP_REST_Response(
				[ 'error' => esc_html( $e->getMessage() ) ],
				500
			);
		}

		if ( ! $customer_id ) {
			return new WP_REST_Response(
				[ 'error' => __( 'Failed to create PAY.JP customer.', 'payjp-for-wc' ) ],
				500
			);
		}

		try {
			$setup_flow = $api->post(
				'/setup_flows',
				[
					'payment_method_types' => [ 'card' ],
					'customer'             => $customer_id,
				]
			);
		} catch ( RuntimeException $e ) {
			return new WP_REST_Response(
				[ 'error' => esc_html( $e->getMessage() ) ],
				500
			);
		}

		$flow_id       = isset( $setup_flow['id'] ) && is_string( $setup_flow['id'] ) ? $setup_flow['id'] : '';
		$client_secret = isset( $setup_flow['client_secret'] ) && is_string( $setup_flow['client_secret'] ) ? $setup_flow['client_secret'] : '';

		if ( ! $flow_id || ! $client_secret ) {
			return new WP_REST_Response(
				[ 'error' => __( 'Failed to create card setup session.', 'payjp-for-wc' ) ],
				500
			);
		}

		return new WP_REST_Response(
			[
				'setup_flow_id' => $flow_id,
				'client_secret' => $client_secret,
			]
		);
	}

	/**
	 * Handle the return redirect from PAY.JP after a Setup Flow completes.
	 *
	 * Verifies the Setup Flow server-side, fetches card details, and persists
	 * a WooCommerce Payment Token for the user. Registered on template_redirect.
	 */
	public static function handle_setup_return(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- setup_flow_id verified against PAY.JP API server-side.
		if ( empty( $_GET['payjp-setup-return'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$flow_id = isset( $_GET['setup_flow_id'] ) && is_string( $_GET['setup_flow_id'] )
			? sanitize_text_field( wp_unslash( $_GET['setup_flow_id'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $flow_id ) {
			wc_add_notice( __( 'Invalid card setup session.', 'payjp-for-wc' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
			exit;
		}

		$user_id = get_current_user_id();
		$api     = new Payjp_API( Payjp_Settings::get_secret_key() );

		try {
			$flow = $api->get( '/setup_flows/' . rawurlencode( $flow_id ) );
		} catch ( RuntimeException $e ) {
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
			exit;
		}

		$status = isset( $flow['status'] ) && is_string( $flow['status'] ) ? $flow['status'] : '';

		if ( 'succeeded' !== $status ) {
			wc_add_notice( __( 'Card setup was not completed. Please try again.', 'payjp-for-wc' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
			exit;
		}

		$pm_id = isset( $flow['payment_method_id'] ) && is_string( $flow['payment_method_id'] )
			? $flow['payment_method_id']
			: '';

		if ( ! $pm_id ) {
			wc_add_notice( __( 'Card setup completed but no payment method was returned.', 'payjp-for-wc' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
			exit;
		}

		try {
			$pm   = $api->get( '/payment_methods/' . rawurlencode( $pm_id ) );
			$card = isset( $pm['card'] ) && is_array( $pm['card'] ) ? $pm['card'] : [];
			self::save_wc_token( $user_id, $pm_id, $card );
		} catch ( RuntimeException $e ) {
			wc_add_notice( esc_html( $e->getMessage() ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
			exit;
		}

		wc_add_notice( __( 'Card saved successfully.', 'payjp-for-wc' ), 'success' );
		wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
		exit;
	}

	/**
	 * Get the stored PAY.JP Customer ID for a WordPress user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string PAY.JP Customer ID, or empty string if not found.
	 */
	public static function get_customer_id( int $user_id ): string {
		return (string) get_user_meta( $user_id, self::USER_META_CUSTOMER_ID, true );
	}

	/**
	 * Persist a PAY.JP Customer ID against a WordPress user.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $customer_id PAY.JP Customer ID.
	 */
	public static function save_customer_id( int $user_id, string $customer_id ): void {
		update_user_meta( $user_id, self::USER_META_CUSTOMER_ID, $customer_id );
	}

	/**
	 * Return the existing PAY.JP Customer ID for the user, or create one via the API.
	 *
	 * @param int       $user_id WordPress user ID.
	 * @param Payjp_API $api     Initialised API client.
	 * @return string PAY.JP Customer ID, or empty string on failure.
	 * @throws RuntimeException If the PAY.JP API returns an error.
	 */
	public static function get_or_create_customer( int $user_id, Payjp_API $api ): string {
		$customer_id = self::get_customer_id( $user_id );
		if ( $customer_id ) {
			return $customer_id;
		}

		$body = [];
		$user = get_userdata( $user_id );
		if ( $user instanceof WP_User ) {
			$body['email'] = $user->user_email;
		}

		$customer    = $api->post( '/customers', $body );
		$customer_id = isset( $customer['id'] ) && is_string( $customer['id'] ) ? $customer['id'] : '';

		if ( $customer_id ) {
			self::save_customer_id( $user_id, $customer_id );
		}

		return $customer_id;
	}

	/**
	 * Create and persist a WooCommerce payment token from PAY.JP card data.
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param string               $pm_id   PAY.JP PaymentMethod ID.
	 * @param array<string, mixed> $card    Card data from PAY.JP API (brand, last4, exp_month, exp_year).
	 * @return WC_Payment_Token_CC The saved WooCommerce token.
	 */
	public static function save_wc_token( int $user_id, string $pm_id, array $card ): WC_Payment_Token_CC {
		// Return the existing WC token if this PM ID is already saved to avoid duplicates.
		$existing_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, 'payjp_card' );
		foreach ( $existing_tokens as $existing ) {
			if ( $existing instanceof WC_Payment_Token_CC && $existing->get_token() === $pm_id ) {
				return $existing;
			}
		}

		$token = new WC_Payment_Token_CC();
		$token->set_token( $pm_id );
		$token->set_gateway_id( 'payjp_card' );
		$token->set_user_id( $user_id );
		$token->set_card_type(
			strtolower(
				isset( $card['brand'] ) && is_string( $card['brand'] ) ? $card['brand'] : 'unknown'
			)
		);
		$token->set_last4(
			isset( $card['last4'] ) && is_string( $card['last4'] ) ? $card['last4'] : ''
		);
		$token->set_expiry_month(
			isset( $card['exp_month'] ) ? (string) $card['exp_month'] : ''
		);
		$token->set_expiry_year(
			isset( $card['exp_year'] ) ? (string) $card['exp_year'] : ''
		);
		$token->save();

		return $token;
	}

	/**
	 * Attach the payment method from a completed order to the customer's saved cards,
	 * if the customer opted to save their card at checkout.
	 *
	 * Called by WC_Gateway_Payjp_Card::after_payment_complete() which is triggered
	 * from WC_Gateway_Payjp::handle_return() after payment_complete().
	 *
	 * @param WC_Order             $order WooCommerce order that just completed.
	 * @param array<string, mixed> $flow  PAY.JP Payment Flow object returned by the API.
	 * @param Payjp_API            $api   Initialised API client.
	 */
	public static function maybe_save_card_after_payment( WC_Order $order, array $flow, Payjp_API $api ): void {
		if ( '1' !== (string) $order->get_meta( '_payjp_save_card' ) ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();
		if ( ! $user_id ) {
			return;
		}

		$pm_id = isset( $flow['payment_method_id'] ) && is_string( $flow['payment_method_id'] )
			? $flow['payment_method_id']
			: '';

		if ( ! $pm_id ) {
			return;
		}

		try {
			$pm          = $api->get( '/payment_methods/' . rawurlencode( $pm_id ) );
			$customer_id = self::get_or_create_customer( $user_id, $api );

			if ( $customer_id ) {
				$api->post(
					'/payment_methods/' . rawurlencode( $pm_id ) . '/attach',
					[ 'customer' => $customer_id ]
				);
			}

			$card = isset( $pm['card'] ) && is_array( $pm['card'] ) ? $pm['card'] : [];
			self::save_wc_token( $user_id, $pm_id, $card );
		} catch ( RuntimeException $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
					sprintf(
						/* translators: 1: PAY.JP PaymentMethod ID, 2: error message */
						__( 'PAY.JP: Failed to save card %1$s after payment: %2$s', 'payjp-for-wc' ),
						esc_html( $pm_id ),
						esc_html( $e->getMessage() )
					),
					[ 'source' => 'payjp-for-wc' ]
				);
			}
			return;
		}

		$order->delete_meta_data( '_payjp_save_card' );
		$order->save();
	}
}
