<?php
/**
 * PAY.JP Webhook handler.
 *
 * Registers a REST API endpoint and routes PAY.JP events to order status handlers.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'Payjp_Webhook_Handler' ) ) {
	return;
}

/**
 * Registers the REST API endpoint for PAY.JP webhooks and routes events
 * to the appropriate order status handlers.
 *
 * Endpoint: POST /wp-json/payjp/v1/webhook
 *
 * Supported events:
 *   - payment_flow.succeeded      → payment_complete()
 *   - payment_flow.payment_failed → update_status('failed')
 *   - refund.created              → add order note
 */
class Payjp_Webhook_Handler {

	/**
	 * REST API namespace for the webhook endpoint.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'payjp/v1';

	/**
	 * REST API route for the webhook endpoint.
	 *
	 * @var string
	 */
	const REST_ROUTE = '/webhook';

	/**
	 * Register webhook-related hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_endpoint' ] );
	}

	/**
	 * Register the /wp-json/payjp/v1/webhook REST endpoint.
	 */
	public static function register_endpoint(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_request' ],
				// PAY.JP servers have no WP session; token auth is done inside handle_request() via hash_equals().
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle an incoming webhook request from PAY.JP.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public static function handle_request( WP_REST_Request $request ): WP_REST_Response {
		if ( ! self::verify_token( $request ) ) {
			return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
		}

		// Defense-in-depth: reject requests that are not JSON.
		$content_type = $request->get_header( 'Content-Type' );
		if ( ! is_string( $content_type ) || false === strpos( $content_type, 'application/json' ) ) {
			return new WP_REST_Response( [ 'error' => 'Unsupported Media Type' ], 415 );
		}

		$event = $request->get_json_params();

		$type   = isset( $event['type'] ) && is_string( $event['type'] ) ? $event['type'] : '';
		$object = isset( $event['data']['object'] ) && is_array( $event['data']['object'] )
			? $event['data']['object']
			: [];

		switch ( $type ) {
			case 'payment_flow.succeeded':
				self::handle_payment_succeeded( $object );
				break;

			case 'payment_flow.payment_failed':
				self::handle_payment_failed( $object );
				break;

			case 'refund.created':
				self::handle_refund_created( $object );
				break;
		}

		return new WP_REST_Response( [ 'received' => true ] );
	}

	/**
	 * Verify the X-Payjp-Webhook-Token header using a timing-safe comparison.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return bool
	 */
	private static function verify_token( WP_REST_Request $request ): bool {
		$secret = Payjp_Settings::get_webhook_secret();
		if ( ! $secret ) {
			return false;
		}

		$token = $request->get_header( 'X-Payjp-Webhook-Token' );
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}

		return hash_equals( $secret, $token );
	}

	/**
	 * Handle payment_flow.succeeded: mark the WooCommerce order as complete.
	 *
	 * @param array<string, mixed> $flow Payment Flow object from the webhook payload.
	 */
	private static function handle_payment_succeeded( array $flow ): void {
		$flow_id = isset( $flow['id'] ) && is_string( $flow['id'] ) ? $flow['id'] : '';
		if ( ! $flow_id ) {
			return;
		}

		$order = self::find_order_by_flow_id( $flow_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->is_paid() ) {
			return;
		}

		$order->payment_complete( $flow_id );
	}

	/**
	 * Handle payment_flow.payment_failed: mark the WooCommerce order as failed.
	 *
	 * @param array<string, mixed> $flow Payment Flow object from the webhook payload.
	 */
	private static function handle_payment_failed( array $flow ): void {
		$flow_id = isset( $flow['id'] ) && is_string( $flow['id'] ) ? $flow['id'] : '';
		if ( ! $flow_id ) {
			return;
		}

		$order = self::find_order_by_flow_id( $flow_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->has_status( [ 'failed', 'cancelled' ] ) ) {
			return;
		}

		/* translators: PAY.JP failed payment note shown in WooCommerce admin */
		$order->update_status( 'failed', __( 'PAY.JP payment failed (webhook).', 'payjp-for-wc' ) );
	}

	/**
	 * Handle refund.created: add an order note with the refund details.
	 *
	 * @param array<string, mixed> $refund Refund object from the webhook payload.
	 */
	private static function handle_refund_created( array $refund ): void {
		$flow_id   = isset( $refund['payment_flow'] ) && is_string( $refund['payment_flow'] ) ? $refund['payment_flow'] : '';
		$refund_id = isset( $refund['id'] ) && is_string( $refund['id'] ) ? $refund['id'] : '';
		// Accept any numeric value; cast to int for consistent formatting.
		$amount = isset( $refund['amount'] ) && is_numeric( $refund['amount'] ) ? (int) $refund['amount'] : 0;

		if ( ! $flow_id || ! $refund_id ) {
			return;
		}

		$order = self::find_order_by_flow_id( $flow_id );
		if ( ! $order ) {
			return;
		}

		// Idempotency: skip if this refund has already been processed.
		if ( $order->get_meta( '_payjp_refund_processed_' . $refund_id ) ) {
			return;
		}

		$order->update_meta_data( '_payjp_refund_processed_' . $refund_id, '1' );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: PAY.JP refund ID, 2: refund amount in JPY */
				__( 'PAY.JP refund created (webhook). Refund ID: %1$s. Amount: ¥%2$s.', 'payjp-for-wc' ),
				esc_html( $refund_id ),
				number_format( $amount )
			)
		);
	}

	/**
	 * Find a WooCommerce order by its PAY.JP Payment Flow ID.
	 *
	 * Strategy: query by transaction_id first (direct column on HPOS; fast), then
	 * fall back to a meta query for orders not yet completed (rare race condition
	 * where the webhook arrives before the customer's return-URL redirect is handled).
	 *
	 * @param string $flow_id PAY.JP Payment Flow ID.
	 * @return WC_Order|false WooCommerce order, or false if not found.
	 */
	private static function find_order_by_flow_id( string $flow_id ): WC_Order|false {
		// payment_complete( $flow_id ) sets transaction_id — fast indexed lookup on HPOS.
		$orders = wc_get_orders(
			[
				'limit'          => 1,
				'transaction_id' => $flow_id,
			]
		);
		if ( ! empty( $orders ) ) {
			return $orders[0];
		}

		// Fallback: meta query covers the window before payment_complete() has been called.
		$orders = wc_get_orders(
			[
				'limit'      => 1,
				'meta_key'   => '_payjp_payment_flow_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $flow_id,                 // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		return ! empty( $orders ) ? $orders[0] : false;
	}
}
