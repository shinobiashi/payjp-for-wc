<?php
/**
 * WooCommerce Subscriptions integration for PAY.JP.
 *
 * Handles scheduled renewal payments, subscription status changes,
 * and payment method updates for recurring billing.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use ArtisanWorkshop\WCLogger\v1_0_0\JP4WC_Logger;

if ( class_exists( 'Payjp_Subscriptions' ) ) {
	return;
}

/**
 * Wires up WooCommerce Subscriptions hooks for automatic renewal processing
 * using the customer's saved PAY.JP PaymentMethod.
 * All hooks are registered only when WooCommerce Subscriptions is installed.
 *
 * Renewal flow:
 *   1. WCS fires woocommerce_scheduled_subscription_payment_payjp_card.
 *   2. handle_scheduled_payment() resolves the PAY.JP PaymentMethod ID and
 *      Customer ID stored on the subscription.
 *   3. Creates a Payment Flow with confirm:true for an immediate charge.
 *   4. On success: calls payment_complete() on the renewal order.
 *   5. On failure: marks the renewal order as failed; WCS retry logic fires
 *      automatically via the woocommerce_order_status_failed hook.
 */
class Payjp_Subscriptions {

	/**
	 * Register WooCommerce Subscriptions hooks.
	 * No-op when WooCommerce Subscriptions is not active.
	 */
	public static function init(): void {
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return;
		}

		add_action(
			'woocommerce_scheduled_subscription_payment_payjp_card',
			array( self::class, 'handle_scheduled_payment' ),
			10,
			2
		);

		add_action(
			'woocommerce_subscription_payment_method_updated',
			array( self::class, 'on_payment_method_updated' ),
			10,
			2
		);
	}

	/**
	 * Process an automatic renewal payment for a WooCommerce Subscription.
	 *
	 * Called by WCS when a scheduled renewal is due. 3DS redirects cannot be
	 * completed during automatic renewals, so any non-immediate status is
	 * treated as a failure and WCS retry scheduling is triggered.
	 *
	 * @param float    $amount        Amount to charge in the store currency.
	 * @param WC_Order $renewal_order WooCommerce renewal order.
	 */
	public static function handle_scheduled_payment( float $amount, WC_Order $renewal_order ): void {
		// Free trials or $0 renewals: complete immediately without charging.
		if ( $amount <= 0.0 ) {
			$renewal_order->payment_complete();
			return;
		}

		$subscriptions = function_exists( 'wcs_get_subscriptions_for_renewal_order' )
			? wcs_get_subscriptions_for_renewal_order( $renewal_order )
			: array();

		/* @var WC_Subscription|false $subscription */ // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
		$subscription = ! empty( $subscriptions ) ? reset( $subscriptions ) : false;

		if ( ! $subscription ) {
			$renewal_order->update_status(
				'failed',
				__( 'PAY.JP: No subscription found for this renewal order.', 'payjp-for-wc' )
			);
			return;
		}

		$pm_id       = self::resolve_payment_method_id( $subscription );
		$user_id     = (int) $renewal_order->get_customer_id();
		$customer_id = Payjp_Token_Manager::get_customer_id( $user_id );

		if ( ! $pm_id || ! $customer_id ) {
			/* translators: WooCommerce renewal order failure note visible in admin */
			$renewal_order->update_status(
				'failed',
				__( 'PAY.JP: No saved payment method found for this subscription. Please update the payment method.', 'payjp-for-wc' )
			);
			return;
		}

		$api = new Payjp_API(
			Payjp_Settings::get_secret_key(),
			JP4WC_Logger::get_instance( 'payjp-for-wc', static fn() => (bool) Payjp_Settings::get( 'debug_log' ) )
		);

		try {
			$flow = $api->post(
				'/payment_flows',
				array(
					'amount'               => (int) round( $amount ),
					'currency'             => strtolower( get_woocommerce_currency() ),
					'payment_method_types' => array( 'card' ),
					'payment_method'       => $pm_id,
					'customer'             => $customer_id,
					'confirm'              => true,
					'capture_method'       => 'automatic',
				)
			);
		} catch ( RuntimeException $e ) {
			$renewal_order->update_status( 'failed', esc_html( $e->getMessage() ) );
			return;
		}

		$flow_id = isset( $flow['id'] ) && is_string( $flow['id'] ) ? $flow['id'] : '';
		$status  = isset( $flow['status'] ) && is_string( $flow['status'] ) ? $flow['status'] : '';

		if ( 'succeeded' === $status && $flow_id ) {
			$renewal_order->update_meta_data( '_payjp_payment_flow_id', $flow_id );
			$renewal_order->update_meta_data( '_payjp_payment_method', 'card' );
			$renewal_order->update_meta_data( '_payjp_capture_method', 'automatic' );
			$renewal_order->save();
			$renewal_order->payment_complete( $flow_id );
			return;
		}

		// Automatic renewals cannot redirect for 3DS — treat any non-success as a failure.
		// WCS's WCS_Retry_Manager hooks woocommerce_order_status_failed and schedules
		// a retry automatically; no explicit retry call is needed here.
		$renewal_order->update_status(
			'failed',
			__( 'PAY.JP: Renewal payment failed or requires additional authentication.', 'payjp-for-wc' )
		);
	}

	/**
	 * Store the PAY.JP PaymentMethod ID on all subscriptions belonging to an order.
	 *
	 * Called from WC_Gateway_Payjp_Card::after_payment_complete() so that the
	 * PM ID is available immediately for future renewals without an extra API call.
	 *
	 * @param WC_Order             $order WooCommerce order that just completed payment.
	 * @param array<string, mixed> $flow  PAY.JP Payment Flow object.
	 */
	public static function store_payment_method_on_subscriptions( WC_Order $order, array $flow ): void {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}

		if ( 'payjp_card' !== $order->get_payment_method() ) {
			return;
		}

		// Guest orders cannot have a PAY.JP Customer ID, so renewal payments would fail.
		// WCS should prevent guest subscriptions, but guard explicitly for safety.
		if ( ! $order->get_customer_id() ) {
			return;
		}

		$pm_id = isset( $flow['payment_method_id'] ) && is_string( $flow['payment_method_id'] )
			? $flow['payment_method_id']
			: '';

		if ( ! $pm_id ) {
			return;
		}

		$customer_id   = Payjp_Token_Manager::get_customer_id( (int) $order->get_customer_id() );
		$subscriptions = wcs_get_subscriptions_for_order( $order->get_id() );

		foreach ( $subscriptions as $subscription ) {
			$subscription->update_meta_data( '_payjp_payment_method_id', $pm_id );
			if ( $customer_id ) {
				$subscription->update_meta_data( '_payjp_customer_id', $customer_id );
			}
			$subscription->save();
		}
	}

	/**
	 * Update the stored PAY.JP PaymentMethod ID when a subscription payment method changes.
	 *
	 * WCS fires this hook after the customer selects a new payment method on the
	 * "Change payment method" page. We resolve the new PM ID from the selected
	 * WC token and persist it to subscription meta so that the next renewal uses it.
	 *
	 * @param WC_Subscription $subscription      Updated subscription.
	 * @param string          $old_payment_method Previous gateway ID.
	 */
	public static function on_payment_method_updated( WC_Subscription $subscription, string $old_payment_method ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( 'payjp_card' !== $subscription->get_payment_method() ) {
			return;
		}

		$pm_id = self::resolve_payment_method_id( $subscription );
		if ( ! $pm_id ) {
			return;
		}

		$subscription->update_meta_data( '_payjp_payment_method_id', $pm_id );
		$subscription->save();
	}

	/**
	 * Resolve the PAY.JP PaymentMethod ID for a subscription.
	 *
	 * Resolution order:
	 *   1. _payjp_payment_method_id meta stored by this plugin.
	 *   2. WC token ID stored by WooCommerce Subscriptions in _payment_method_token.
	 *   3. The user's default PAY.JP card token.
	 *
	 * @param WC_Subscription $subscription WooCommerce subscription.
	 * @return string PAY.JP PaymentMethod ID, or empty string if not found.
	 */
	private static function resolve_payment_method_id( WC_Subscription $subscription ): string {
		// 1. Direct meta stored after payment completion.
		$pm_id = (string) $subscription->get_meta( '_payjp_payment_method_id' );
		if ( $pm_id ) {
			return $pm_id;
		}

		// 2. WC token ID stored by WCS when the customer changes payment method.
		$token_id = (string) $subscription->get_meta( '_payment_method_token' );
		if ( $token_id ) {
			$token = WC_Payment_Tokens::get( absint( $token_id ) );
			if ( $token instanceof WC_Payment_Token_CC && 'payjp_card' === $token->get_gateway_id() ) {
				return $token->get_token();
			}
		}

		// 3. Fall back to the user's default PAY.JP card token.
		$user_id = (int) $subscription->get_customer_id();
		if ( ! $user_id ) {
			return '';
		}

		$default = WC_Payment_Tokens::get_customer_default_token( $user_id );
		if (
			$default instanceof WC_Payment_Token_CC &&
			'payjp_card' === $default->get_gateway_id() &&
			(int) $default->get_user_id() === $user_id
		) {
			return $default->get_token();
		}

		return '';
	}
}
