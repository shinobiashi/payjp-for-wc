<?php
/**
 * PAY.JP Webhook handler.
 *
 * Registers a REST API endpoint and routes PAY.JP events to order status handlers.
 *
 * @package Payjp_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

use ArtisanWorkshop\WCLogger\v1_0_0\JP4WC_Logger;

if ( class_exists( 'Payjp_Webhook_Handler' ) ) {
	return;
}

/**
 * Registers the REST API endpoint for PAY.JP webhooks and routes events
 * to the appropriate order status handlers.
 *
 * Endpoint: POST /wp-json/payjp/v2/webhook
 *
 * Supported events:
 *   - payment_flow.succeeded                → payment_complete()
 *   - payment_flow.amount_capturable_updated → update_status('processing') for requires_capture (manual capture)
 *   - payment_flow.payment_failed           → update_status('failed')
 *   - refund.created                        → add order note
 */
class Payjp_Webhook_Handler {

	/**
	 * REST API namespace for the webhook endpoint.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'payjp/v2';

	/**
	 * REST API route for the webhook endpoint.
	 *
	 * @var string
	 */
	const REST_ROUTE = '/webhook';

	/**
	 * Test seam: when set, used instead of constructing Payjp_API directly.
	 *
	 * @var callable(string):Payjp_API|null
	 */
	private static $api_factory = null;

	/**
	 * Register webhook-related hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_endpoint' ) );
	}

	/**
	 * Register the /wp-json/payjp/v2/webhook REST endpoint.
	 */
	public static function register_endpoint(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_request' ),
				// PAY.JP servers have no WP session; token auth is done inside handle_request() via hash_equals().
				'permission_callback' => '__return_true',
			)
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
			// Use log_event (debug-gated) to avoid disk-filling noise from public endpoint probing.
			self::logger()->log_event( 'webhook_auth_failed' );
			return new WP_REST_Response( array( 'error' => 'Unauthorized' ), 401 );
		}

		// Defense-in-depth: reject requests that are not JSON.
		$content_type = $request->get_header( 'Content-Type' );
		if ( ! is_string( $content_type ) || false === strpos( $content_type, 'application/json' ) ) {
			return new WP_REST_Response( array( 'error' => 'Unsupported Media Type' ), 415 );
		}

		$event = json_decode( $request->get_body(), true );

		if ( ! is_array( $event ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid JSON payload' ), 400 );
		}

		$type   = isset( $event['type'] ) && is_string( $event['type'] ) ? $event['type'] : '';
		$object = isset( $event['data'] ) && is_array( $event['data'] )
			? $event['data']
			: array();

		self::logger()->log_webhook( $type, $event );

		switch ( $type ) {
			case 'payment_flow.succeeded':
				self::handle_payment_succeeded( $object );
				break;

			case 'payment_flow.amount_capturable_updated':
				self::handle_payment_capturable_updated( $object );
				break;

			case 'payment_flow.payment_failed':
				self::handle_payment_failed( $object );
				break;

			case 'refund.created':
				self::handle_refund_created( $object );
				break;
		}

		return new WP_REST_Response( array( 'received' => true ) );
	}

	/**
	 * Get the shared JP4WC_Logger instance.
	 *
	 * @return JP4WC_Logger
	 */
	private static function logger(): JP4WC_Logger {
		return JP4WC_Logger::get_instance(
			'payjp-for-wc',
			static fn() => (bool) Payjp_Settings::get( 'debug_log' )
		);
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

		// Only orders still awaiting payment may be advanced by this webhook.
		// A delayed/retried 'succeeded' event for an order that was since cancelled
		// (and possibly refunded — the PAY.JP Payment Flow itself stays 'succeeded'
		// after a refund) must not revive it, since WooCommerce's default
		// payment-complete status list includes 'cancelled'.
		if ( ! $order->has_status( array( 'pending', 'failed', 'on-hold' ) ) ) {
			self::alert_succeeded_after_final( $order, $flow_id, $flow );
			return;
		}

		$order->payment_complete( $flow_id );
		$order->add_order_note( __( 'PAY.JP: Payment confirmed via webhook.', 'payjp-for-wc' ) );
		self::logger()->log_event(
			'succeeded',
			$order->get_id(),
			array(
				'flow_id' => $flow_id,
				'source'  => 'webhook',
			)
		);
	}

	/**
	 * Alert the store administrator that a 'succeeded' webhook arrived for an
	 * order that is no longer payable (e.g. cancelled). The Payment Flow is
	 * captured on PAY.JP but the order was never marked paid, so the two
	 * systems disagree and a human must reconcile them (see Issue #23).
	 *
	 * @param WC_Order             $order   Order the webhook targets.
	 * @param string               $flow_id Payment Flow ID.
	 * @param array<string, mixed> $flow    Payment Flow object from the webhook payload.
	 */
	private static function alert_succeeded_after_final( WC_Order $order, string $flow_id, array $flow ): void {
		// Not an anomaly: cancel_payment_flow() already auto-refunded this succeeded
		// flow when the order was cancelled, so a delayed/retried webhook is expected.
		if ( '1' === (string) $order->get_meta( '_payjp_cancel_refund_processed' ) ) {
			return;
		}

		// Idempotency: PAY.JP retries non-2xx responses up to 3 times and allows
		// manual redelivery from the dashboard; only alert once per order.
		if ( $order->get_meta( '_payjp_alerted_late_succeeded' ) ) {
			return;
		}

		$order->update_meta_data( '_payjp_alerted_late_succeeded', '1' );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: WooCommerce order status the order already had (e.g. "cancelled"), 2: PAY.JP Payment Flow ID. */
				__( 'PAY.JP: Payment was confirmed for this order after it was already %1$s. The payment is captured on PAY.JP but is NOT reflected on this order. Review the PAY.JP dashboard and either refund the payment or handle the order manually. (Payment Flow ID: %2$s)', 'payjp-for-wc' ),
				esc_html( $order->get_status() ),
				esc_html( $flow_id )
			)
		);

		$lines = array(
			sprintf(
				/* translators: %s: WooCommerce order number. */
				__( 'Order: #%s', 'payjp-for-wc' ),
				$order->get_order_number()
			),
			sprintf(
				/* translators: %s: WooCommerce order status (e.g. "cancelled"). */
				__( 'Current order status: %s', 'payjp-for-wc' ),
				$order->get_status()
			),
			sprintf(
				/* translators: %s: PAY.JP Payment Flow ID. */
				__( 'Payment Flow ID: %s', 'payjp-for-wc' ),
				$flow_id
			),
		);
		if ( isset( $flow['amount'] ) && is_numeric( $flow['amount'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: Payment amount in JPY, formatted with thousands separators. */
				__( 'Amount: ¥%s', 'payjp-for-wc' ),
				number_format( (int) $flow['amount'] )
			);
		}
		$lines[] = __( 'Review the payment on the PAY.JP dashboard and either refund it or handle the order manually.', 'payjp-for-wc' );

		Payjp_Admin_Notifier::send_alert(
			$order,
			__( 'PAY.JP payment confirmed for a closed order — action required', 'payjp-for-wc' ),
			$lines
		);

		self::logger()->log_error( 'late succeeded webhook for closed order', $order->get_id(), null );
	}

	/**
	 * Handle payment_flow.amount_capturable_updated: move order to processing for manual-capture flows.
	 *
	 * Fires when PayPay authorises a manual-capture Payment Flow (status → requires_capture).
	 * The customer may have returned from PayPay while the flow was still requires_action,
	 * so handle_return() left the order in pending payment. This webhook completes the
	 * transition so the merchant can fulfil and then capture.
	 *
	 * @param array<string, mixed> $flow Payment Flow object from the webhook payload.
	 */
	private static function handle_payment_capturable_updated( array $flow ): void {
		$flow_id = isset( $flow['id'] ) && is_string( $flow['id'] ) ? $flow['id'] : '';
		if ( ! $flow_id ) {
			return;
		}

		$status = isset( $flow['status'] ) && is_string( $flow['status'] ) ? $flow['status'] : '';
		if ( 'requires_capture' !== $status ) {
			return;
		}

		$order = self::find_order_by_flow_id( $flow_id );
		if ( ! $order ) {
			return;
		}

		if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			self::alert_capturable_after_final( $order, $flow_id, $flow );
			return;
		}

		$order->set_transaction_id( $flow_id );
		$order->save();
		$order->update_status(
			'processing',
			/* translators: PAY.JP order note shown in WooCommerce admin for manual-capture orders confirmed via webhook. */
			__( 'PAY.JP authorised via webhook. Payment will be captured when the order is marked Completed.', 'payjp-for-wc' )
		);
		self::logger()->log_event(
			'authorized',
			$order->get_id(),
			array(
				'flow_id' => $flow_id,
				'source'  => 'webhook',
			)
		);
	}

	/**
	 * Alert the store administrator that an 'amount_capturable_updated' webhook
	 * arrived for an order that is no longer payable, and automatically void the
	 * uncaptured authorization when the order was cancelled or failed (see D-4
	 * in the Issue #23 implementation plan: voiding only releases the customer's
	 * credit limit and moves no money, so it is safe to automate).
	 *
	 * @param WC_Order             $order   Order the webhook targets.
	 * @param string               $flow_id Payment Flow ID.
	 * @param array<string, mixed> $flow    Payment Flow object from the webhook payload.
	 */
	private static function alert_capturable_after_final( WC_Order $order, string $flow_id, array $flow ): void {
		// Not an anomaly: a retried delivery of the same event for an order that
		// this webhook already advanced to processing/completed successfully.
		if ( $flow_id === $order->get_transaction_id() && $order->has_status( array( 'processing', 'completed' ) ) ) {
			return;
		}

		// Idempotency: only alert/void once per order.
		if ( $order->get_meta( '_payjp_alerted_late_capturable' ) ) {
			return;
		}

		$order->update_meta_data( '_payjp_alerted_late_capturable', '1' );
		$order->save();

		$voided         = false;
		$void_attempted = false;
		if ( $order->has_status( array( 'cancelled', 'failed' ) ) ) {
			$api = self::get_api_for_flow( $flow );
			if ( null !== $api ) {
				$void_attempted = true;
				try {
					$api->post(
						'/payment_flows/' . rawurlencode( $flow_id ) . '/cancel',
						array( 'cancellation_reason' => 'requested_by_customer' ),
						$order->get_id()
					);
					$voided = true;
				} catch ( RuntimeException ) {
					$voided = false;
				}
			}
		}

		if ( $voided ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: WooCommerce order status the order already had (e.g. "cancelled"), 2: PAY.JP Payment Flow ID. */
					__( 'PAY.JP: A PayPay authorization completed for this order after it was already %1$s. The authorization has been automatically voided. (Payment Flow ID: %2$s)', 'payjp-for-wc' ),
					esc_html( $order->get_status() ),
					esc_html( $flow_id )
				)
			);
		} elseif ( $void_attempted ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: WooCommerce order status the order already had (e.g. "cancelled"), 2: PAY.JP Payment Flow ID. */
					__( "PAY.JP: A PayPay authorization completed for this order after it was already %1\$s. Automatic void FAILED — the customer's funds remain reserved. Cancel the payment on the PAY.JP dashboard. (Payment Flow ID: %2\$s)", 'payjp-for-wc' ),
					esc_html( $order->get_status() ),
					esc_html( $flow_id )
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: 1: WooCommerce order status the order already had (e.g. "cancelled"), 2: PAY.JP Payment Flow ID. */
					__( 'PAY.JP: A PayPay authorization completed for this order after it was already %1$s. Automatic void was not attempted for this order — cancel the payment manually on the PAY.JP dashboard if needed. (Payment Flow ID: %2$s)', 'payjp-for-wc' ),
					esc_html( $order->get_status() ),
					esc_html( $flow_id )
				)
			);
		}

		if ( $voided ) {
			$void_summary_line = __( 'The uncaptured authorization has been automatically voided.', 'payjp-for-wc' );
		} elseif ( $void_attempted ) {
			$void_summary_line = __( "Automatic void FAILED — the customer's funds remain reserved. Cancel the payment on the PAY.JP dashboard.", 'payjp-for-wc' );
		} else {
			$void_summary_line = __( 'Automatic void was not attempted for this order. Cancel the payment manually on the PAY.JP dashboard if needed.', 'payjp-for-wc' );
		}

		$lines = array(
			sprintf(
				/* translators: %s: WooCommerce order number. */
				__( 'Order: #%s', 'payjp-for-wc' ),
				$order->get_order_number()
			),
			sprintf(
				/* translators: %s: WooCommerce order status (e.g. "cancelled"). */
				__( 'Current order status: %s', 'payjp-for-wc' ),
				$order->get_status()
			),
			sprintf(
				/* translators: %s: PAY.JP Payment Flow ID. */
				__( 'Payment Flow ID: %s', 'payjp-for-wc' ),
				$flow_id
			),
			$void_summary_line,
		);

		Payjp_Admin_Notifier::send_alert(
			$order,
			__( 'PAY.JP authorization received for a closed order', 'payjp-for-wc' ),
			$lines
		);

		if ( $voided ) {
			self::logger()->log_event(
				'late_capturable_voided',
				$order->get_id(),
				array(
					'flow_id' => $flow_id,
					'source'  => 'webhook',
				)
			);
		} elseif ( $void_attempted ) {
			self::logger()->log_error( 'late capturable webhook: automatic void failed', $order->get_id(), null );
		} else {
			self::logger()->log_event(
				'late_capturable_void_skipped',
				$order->get_id(),
				array(
					'flow_id' => $flow_id,
					'source'  => 'webhook',
				)
			);
		}
	}

	/**
	 * Override the API factory used by get_api_for_flow().
	 *
	 * @internal Unit tests only.
	 *
	 * @param callable|null $factory Receives the secret key, returns a Payjp_API.
	 */
	public static function set_api_factory( ?callable $factory ): void {
		self::$api_factory = $factory;
	}

	/**
	 * Build an API client using the secret key matching the event's livemode flag.
	 * Returns null when the corresponding key is not configured.
	 *
	 * @param array<string, mixed> $flow Payment Flow object from the webhook payload.
	 */
	private static function get_api_for_flow( array $flow ): ?Payjp_API {
		$livemode = ! empty( $flow['livemode'] );
		$key      = $livemode
			? (string) Payjp_Settings::get( 'live_secret_key' )
			: (string) Payjp_Settings::get( 'test_secret_key' );
		if ( '' === $key ) {
			return null;
		}
		if ( null !== self::$api_factory ) {
			return ( self::$api_factory )( $key );
		}
		return new Payjp_API( $key, self::logger() );
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

		if ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
			return;
		}

		/* translators: PAY.JP failed payment note shown in WooCommerce admin */
		$order->update_status( 'failed', __( 'PAY.JP payment failed (webhook).', 'payjp-for-wc' ) );
		self::logger()->log_event(
			'failed',
			$order->get_id(),
			array(
				'flow_id' => $flow_id,
				'source'  => 'webhook',
			)
		);
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

		self::logger()->log_event(
			'refunded',
			$order->get_id(),
			array(
				'refund_id' => $refund_id,
				'amount'    => $amount,
				'source'    => 'webhook',
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
			array(
				'limit'          => 1,
				'transaction_id' => $flow_id,
			)
		);
		if ( ! empty( $orders ) ) {
			return $orders[0];
		}

		// Fallback: meta query covers the window before payment_complete() has been called.
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_payjp_payment_flow_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $flow_id,                 // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return ! empty( $orders ) ? $orders[0] : false;
	}
}
