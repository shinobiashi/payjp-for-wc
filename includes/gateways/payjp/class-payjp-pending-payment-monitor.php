<?php
/**
 * Pending payment monitor for in-flight PAY.JP Payment Flows.
 *
 * Prevents WooCommerce's unpaid-order auto-cancellation from cancelling orders
 * whose Payment Flow is still settling (PayPay confirms asynchronously), and
 * polls the flow status via Action Scheduler as a fallback for undelivered
 * payment_flow.* webhooks (Issue #25).
 *
 * @package Payjp_For_WooCommerce
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

use ArtisanWorkshop\WCLogger\v1_0_0\JP4WC_Logger;

if ( class_exists( 'Payjp_Pending_Payment_Monitor' ) ) {
	return;
}

/**
 * Holds auto-cancellation for orders awaiting webhook confirmation and polls
 * the PAY.JP Payment Flow status to settle them when webhooks never arrive.
 *
 * Lifecycle:
 *   1. WC_Gateway_Payjp::handle_return() calls start() when the customer
 *      returns while the flow is still requires_action / processing.
 *   2. hold_auto_cancel() (woocommerce_cancel_unpaid_order filter) skips the
 *      WooCommerce automatic unpaid-order cancellation while the hold window
 *      is open. Manual cancellation is unaffected.
 *   3. poll_flow() (Action Scheduler single actions, +5/+10/+15 minutes)
 *      fetches the flow server-side and settles the order using the same
 *      guards and transitions as the webhook handler.
 *   4. clear() removes the flag and any pending poll job once the webhook,
 *      the return handler, or the poller settles the order.
 *
 * Without Action Scheduler only the prevention filter operates; the flag then
 * expires naturally after the hold window, so orders are never held forever.
 */
class Payjp_Pending_Payment_Monitor {

	/**
	 * Action Scheduler hook name for the polling job.
	 *
	 * @var string
	 */
	const POLL_HOOK = 'payjp_for_wc_poll_flow';

	/**
	 * Action Scheduler group for all jobs scheduled by this plugin.
	 *
	 * @var string
	 */
	const POLL_GROUP = 'payjp-for-wc';

	/**
	 * Maximum number of polling attempts before giving up.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Delays (seconds) of each polling attempt, measured from the moment the
	 * awaiting-webhook flag was set: +5m, +10m, +15m. Keeping every attempt
	 * relative to that fixed origin (rather than to the previous poll) ensures
	 * the final attempt still runs well inside the 30-minute hold window.
	 *
	 * @var int[]
	 */
	const POLL_DELAYS = array( 300, 600, 900 );

	/**
	 * Test seam: when set, used instead of constructing Payjp_API directly.
	 *
	 * @var callable(string):Payjp_API|null
	 */
	private static $api_factory = null;

	/**
	 * Register the auto-cancel hold filter and the polling action.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_cancel_unpaid_order', array( self::class, 'hold_auto_cancel' ), 10, 2 );
		add_action( self::POLL_HOOK, array( self::class, 'poll_flow' ) );
	}

	/**
	 * Skip WooCommerce's automatic unpaid-order cancellation while the order is
	 * awaiting webhook confirmation (woocommerce_cancel_unpaid_order filter).
	 *
	 * Decides from order meta only — wc_cancel_unpaid_orders() loops over many
	 * orders, so no API call may happen here. An expired flag simply falls
	 * through to the default behaviour, making stale flags fail-safe.
	 *
	 * @param bool  $cancel Whether WooCommerce intends to cancel the order.
	 * @param mixed $order  Order being evaluated (WC_Order; typed loosely because
	 *                      filter callbacks cannot trust their input).
	 * @return bool
	 */
	public static function hold_auto_cancel( $cancel, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $cancel;
		}

		if ( 0 !== strpos( $order->get_payment_method(), 'payjp_' ) ) {
			return $cancel;
		}

		if ( self::is_hold_active( $order ) ) {
			return false;
		}

		return $cancel;
	}

	/**
	 * Whether the order carries an awaiting-webhook flag that is still inside
	 * the hold window.
	 *
	 * @param WC_Order $order Order being evaluated.
	 * @return bool
	 */
	private static function is_hold_active( WC_Order $order ): bool {
		$flagged_at = (string) $order->get_meta( '_payjp_awaiting_webhook' );
		if ( '' === $flagged_at || ! is_numeric( $flagged_at ) ) {
			return false;
		}

		/**
		 * Filters how long (seconds) the unpaid-order auto-cancellation is held
		 * for orders awaiting PAY.JP webhook confirmation.
		 *
		 * @param int      $hold  Hold window in seconds. Default 30 minutes.
		 * @param WC_Order $order Order being evaluated.
		 */
		$hold = (int) apply_filters( 'payjp_for_wc_awaiting_webhook_hold', 30 * MINUTE_IN_SECONDS, $order );

		return time() - (int) $flagged_at <= $hold;
	}

	/**
	 * Flag the order as awaiting webhook confirmation and schedule the first poll.
	 *
	 * Called by WC_Gateway_Payjp::handle_return() when the customer returns
	 * while the Payment Flow is still requires_action / processing.
	 *
	 * Idempotent while a monitoring cycle is active: revisits of the return URL
	 * (refresh/back button) must not push the flag timestamp forward — that
	 * would extend the hold window indefinitely and desync the flag from the
	 * already-scheduled poll jobs. A new cycle only begins when no flag exists
	 * or the previous one has expired, and then the attempt counter left over
	 * from the expired cycle is reset so the new cycle gets its full three polls.
	 *
	 * @param WC_Order $order Order awaiting confirmation.
	 */
	public static function start( WC_Order $order ): void {
		if ( self::is_hold_active( $order ) ) {
			return;
		}

		$flagged_at = time();

		$order->delete_meta_data( '_payjp_flow_poll_attempts' );
		$order->update_meta_data( '_payjp_awaiting_webhook', (string) $flagged_at );
		$order->save();

		self::schedule_poll( $order->get_id(), 0, $flagged_at );
	}

	/**
	 * Poll the PAY.JP Payment Flow status and settle the order (Action Scheduler callback).
	 *
	 * Mirrors the guards and status transitions of handle_return() and the
	 * webhook handler so that concurrent settlement paths stay idempotent.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function poll_flow( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Already settled by the webhook or the return handler — just clean up.
		if ( $order->is_paid() || ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			self::clear( $order );
			return;
		}

		$flow_id = (string) $order->get_meta( '_payjp_payment_flow_id' );
		if ( '' === $flow_id ) {
			self::clear( $order );
			return;
		}

		$api = self::get_api_for_order( $order );
		if ( null === $api ) {
			// Secret key for the flow's environment is not configured; count the
			// attempt and retry later rather than holding the order forever.
			self::logger()->log_error( 'poll_flow: secret key unavailable (flow_id=' . $flow_id . ')', $order_id, null );
			self::record_attempt( $order, $flow_id );
			return;
		}

		try {
			$flow = $api->get( '/payment_flows/' . rawurlencode( $flow_id ), $order_id );
		} catch ( RuntimeException $e ) {
			// Payjp_API already logged the exception; record context for the poller.
			self::logger()->log_error( 'poll_flow: API fetch failed (flow_id=' . $flow_id . ')', $order_id, $e );
			self::record_attempt( $order, $flow_id );
			return;
		}

		$status = isset( $flow['status'] ) && is_string( $flow['status'] ) ? $flow['status'] : '';

		if ( 'succeeded' === $status ) {
			$order->payment_complete( $flow_id );
			$order->add_order_note( __( 'PAY.JP: Payment confirmed via status polling.', 'payjp-for-wc' ) );
			self::logger()->log_event(
				'poll_settled',
				$order_id,
				array(
					'flow_id'     => $flow_id,
					'flow_status' => $status,
				)
			);
			self::clear( $order );
			return;
		}

		if ( 'requires_capture' === $status ) {
			$order->set_transaction_id( $flow_id );
			$order->save();
			$order->update_status(
				'processing',
				/* translators: PAY.JP order note shown in WooCommerce admin for manual-capture orders confirmed via status polling. */
				__( 'PAY.JP authorised via status polling. Payment will be captured when the order is marked Completed.', 'payjp-for-wc' )
			);
			self::logger()->log_event(
				'poll_settled',
				$order_id,
				array(
					'flow_id'     => $flow_id,
					'flow_status' => $status,
				)
			);
			self::clear( $order );
			return;
		}

		if ( in_array( $status, array( 'payment_failed', 'canceled' ), true ) ) {
			$order->update_status(
				'failed',
				sprintf(
					/* translators: %s: PAY.JP Payment Flow status (e.g. "payment_failed"). */
					__( 'PAY.JP payment did not complete (status: %s, detected via status polling).', 'payjp-for-wc' ),
					esc_html( $status )
				)
			);
			self::logger()->log_event(
				'poll_settled',
				$order_id,
				array(
					'flow_id'     => $flow_id,
					'flow_status' => $status,
				)
			);
			self::clear( $order );
			return;
		}

		// Still in flight (requires_action / processing / requires_payment_method /
		// requires_confirmation) or an unexpected status — retry until the limit.
		self::record_attempt( $order, $flow_id );
	}

	/**
	 * Clear the awaiting-webhook flag, the attempt counter, and any pending poll job.
	 *
	 * Single cleanup entry point used by the poller, the webhook handler, and
	 * handle_return() once the order is settled.
	 *
	 * @param WC_Order $order Order to clean up.
	 */
	public static function clear( WC_Order $order ): void {
		$order->delete_meta_data( '_payjp_awaiting_webhook' );
		$order->delete_meta_data( '_payjp_flow_poll_attempts' );
		$order->save();

		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::POLL_HOOK, array( 'order_id' => $order->get_id() ), self::POLL_GROUP );
		}
	}

	/**
	 * Override the API factory used by get_api_for_order().
	 *
	 * @internal Unit tests only.
	 *
	 * @param callable|null $factory Receives the secret key, returns a Payjp_API.
	 */
	public static function set_api_factory( ?callable $factory ): void {
		self::$api_factory = $factory;
	}

	/**
	 * Count a polling attempt and either reschedule the next poll or give up.
	 *
	 * After the final attempt the flag is cleared so the order returns to the
	 * normal unpaid-order auto-cancellation flow — stock is never reserved
	 * indefinitely.
	 *
	 * @param WC_Order $order   Order being polled.
	 * @param string   $flow_id Payment Flow ID (for notes and logs).
	 */
	private static function record_attempt( WC_Order $order, string $flow_id ): void {
		$attempts = (int) $order->get_meta( '_payjp_flow_poll_attempts' ) + 1;

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$order->add_order_note(
				__( 'PAY.JP: Payment could not be confirmed by status polling. The order is again subject to the standard automatic cancellation of unpaid orders.', 'payjp-for-wc' )
			);
			self::logger()->log_event(
				'poll_exhausted',
				$order->get_id(),
				array(
					'flow_id'  => $flow_id,
					'attempts' => $attempts,
				)
			);
			self::clear( $order );
			return;
		}

		$order->update_meta_data( '_payjp_flow_poll_attempts', (string) $attempts );
		$order->save();

		// Anchor the next poll to the flag timestamp so attempts run at
		// +5/+10/+15 minutes from the start, not relative to the previous poll
		// (which would drift the final attempt onto the hold-window boundary).
		$flagged_at = (int) $order->get_meta( '_payjp_awaiting_webhook' );
		if ( $flagged_at <= 0 ) {
			$flagged_at = time();
		}

		self::schedule_poll( $order->get_id(), $attempts, $flagged_at );
	}

	/**
	 * Schedule a single Action Scheduler poll job for the order.
	 *
	 * The run time is $base + POLL_DELAYS[$attempt], where $base is the moment
	 * the awaiting-webhook flag was set. A timestamp already in the past (e.g.
	 * Action Scheduler ran the previous poll late) is fine: Action Scheduler
	 * treats past-due single actions as immediately runnable.
	 *
	 * No-op when Action Scheduler is unavailable (the prevention filter still
	 * operates and the flag expires naturally after the hold window).
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @param int $attempt  Zero-based attempt index selecting the delay.
	 * @param int $base     Unix timestamp the delays are measured from.
	 */
	private static function schedule_poll( int $order_id, int $attempt, int $base ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$args = array( 'order_id' => $order_id );

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::POLL_HOOK, $args, self::POLL_GROUP ) ) {
			return;
		}

		$delay = self::POLL_DELAYS[ min( $attempt, count( self::POLL_DELAYS ) - 1 ) ];

		as_schedule_single_action( $base + $delay, self::POLL_HOOK, $args, self::POLL_GROUP );
	}

	/**
	 * Build an API client using the secret key for the environment the flow was
	 * created in (recorded as _payjp_flow_livemode by process_payment()).
	 *
	 * Orders created before that meta existed fall back to the currently active
	 * key. Returns null when the resolved key is not configured.
	 *
	 * @param WC_Order $order Order being polled.
	 */
	private static function get_api_for_order( WC_Order $order ): ?Payjp_API {
		$livemode = (string) $order->get_meta( '_payjp_flow_livemode' );

		if ( '1' === $livemode ) {
			$key = (string) Payjp_Settings::get( 'live_secret_key' );
		} elseif ( '0' === $livemode ) {
			$key = (string) Payjp_Settings::get( 'test_secret_key' );
		} else {
			$key = Payjp_Settings::get_secret_key();
		}

		if ( '' === $key ) {
			return null;
		}

		if ( null !== self::$api_factory ) {
			return ( self::$api_factory )( $key );
		}

		return new Payjp_API( $key, self::logger() );
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
}
