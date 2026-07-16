<?php
/**
 * Unit tests for Payjp_Webhook_Handler.
 *
 * Covers: token verification, event routing, order-lookup no-ops,
 * and order state transitions for all supported event types.
 *
 * @package Payjp_For_WooCommerce
 */

namespace Payjp\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Payjp_Webhook_Handler;
use WP_REST_Request;
use WC_Order;

/**
 * Tests for Payjp_Webhook_Handler.
 */
class WebhookHandlerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset the Payjp_Settings static cache so each test gets a fresh get_option() call.
		\Payjp_Settings::flush_cache();

		// Stub WordPress translation/escape functions used inside the handler.
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );

		// Stub functions used by Payjp_Admin_Notifier::send_alert(), called from the
		// late-webhook alert paths below.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Store' );
		Functions\when( 'wp_specialchars_decode' )->returnArg( 1 );
		Functions\when( 'is_email' )->justReturn( true );

		// The settlement paths call Payjp_Pending_Payment_Monitor::clear(), which
		// unschedules the poll job when Action Scheduler is available. Stub the
		// function so the behaviour is deterministic regardless of whether another
		// test file already caused Brain Monkey to define it.
		Functions\when( 'as_unschedule_action' )->justReturn( 0 );
	}

	/**
	 * Add the expectations produced by Payjp_Pending_Payment_Monitor::clear(),
	 * called by the webhook handler after each successful settlement.
	 *
	 * @param Mockery\MockInterface&WC_Order $order Order mock.
	 */
	private function expect_monitor_clear( $order ): void {
		$order->shouldReceive( 'delete_meta_data' )->once()->with( '_payjp_awaiting_webhook' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( '_payjp_flow_poll_attempts' );
		$order->shouldReceive( 'save' )->atLeast()->once();
	}

	protected function tearDown(): void {
		Payjp_Webhook_Handler::set_api_factory( null );
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	// ── Token verification ────────────────────────────────────────────────────

	#[Test]
	public function missing_webhook_secret_returns_401(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'any' ], [] );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	#[Test]
	public function wrong_token_returns_401(): void {
		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'correct-secret' ] );

		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'wrong-secret' ], [] );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	#[Test]
	public function empty_token_returns_401(): void {
		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'correct-secret' ] );

		$request  = new WP_REST_Request( [], [] );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	// ── Unknown event type — no-op ────────────────────────────────────────────

	#[Test]
	public function unknown_event_type_returns_200_without_side_effects(): void {
		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$payload  = [ 'type' => 'unsupported.event', 'data' => [] ];
		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'received' => true ], $response->get_data() );
	}

	// ── payment_flow.succeeded ────────────────────────────────────────────────

	#[Test]
	public function payment_flow_succeeded_with_unknown_flow_id_is_noop(): void {
		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );
		Functions\when( 'wc_get_orders' )->justReturn( [] );

		$payload = [
			'type' => 'payment_flow.succeeded',
			'data' => [ 'id' => 'pflw_unknown', 'status' => 'succeeded' ],
		];
		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	#[Test]
	public function payment_flow_succeeded_calls_payment_complete_on_unpaid_order(): void {
		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'is_paid' )->once()->andReturn( false );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'failed', 'on-hold' ] )->andReturn( true );
		$order->shouldReceive( 'payment_complete' )->once()->with( 'pflw_abc123' );
		$order->shouldReceive( 'add_order_note' )->once();
		$order->shouldReceive( 'get_id' )->andReturn( 1 );
		$this->expect_monitor_clear( $order );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$payload = [
			'type' => 'payment_flow.succeeded',
			'data' => [ 'id' => 'pflw_abc123', 'status' => 'succeeded' ],
		];
		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	#[Test]
	public function payment_flow_succeeded_skips_already_paid_order(): void {
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'is_paid' )->once()->andReturn( true );
		$order->shouldNotReceive( 'payment_complete' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$payload = [
			'type' => 'payment_flow.succeeded',
			'data' => [ 'id' => 'pflw_paid', 'status' => 'succeeded' ],
		];
		$request = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		Payjp_Webhook_Handler::handle_request( $request );
	}

	#[Test]
	public function payment_flow_succeeded_skips_cancelled_order(): void {
		// Regression test: a Payment Flow that already succeeded stays 'succeeded'
		// even after do_refund()/cancel_payment_flow() refunds it on order cancellation
		// (see class-wc-gateway-payjp.php). A delayed/retried webhook for such a flow
		// must not revive the order via payment_complete(), since WooCommerce's default
		// payment-complete status list includes 'cancelled'.
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'is_paid' )->once()->andReturn( false );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'failed', 'on-hold' ] )->andReturn( false );
		$order->shouldNotReceive( 'payment_complete' );
		// This order was already auto-refunded on cancellation, so the late-webhook
		// alert path (Issue #23) also stays silent — see alert_succeeded_after_final().
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_cancel_refund_processed' )->andReturn( '1' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$payload = [
			'type' => 'payment_flow.succeeded',
			'data' => [ 'id' => 'pflw_cancelled', 'status' => 'succeeded' ],
		];
		$request = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		Payjp_Webhook_Handler::handle_request( $request );
	}

	// ── payment_flow.succeeded: late webhook alert (#23) ─────────────────────

	#[Test]
	public function payment_flow_succeeded_alerts_on_cancelled_order(): void {
		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret', 'alert_email' => 'ops@example.com' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'is_paid' )->once()->andReturn( false );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'failed', 'on-hold' ] )->andReturn( false );
		$order->shouldNotReceive( 'payment_complete' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_cancel_refund_processed' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_alerted_late_succeeded' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_payjp_alerted_late_succeeded', '1' );
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->once();
		$order->shouldReceive( 'get_status' )->andReturn( 'cancelled' );
		$order->shouldReceive( 'get_order_number' )->andReturn( '100' );
		$order->shouldReceive( 'get_id' )->andReturn( 1 );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.com/order/100' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );
		Functions\expect( 'wp_mail' )->once()->andReturn( true );

		$payload = [
			'type' => 'payment_flow.succeeded',
			'data' => [ 'id' => 'pflw_late', 'status' => 'succeeded', 'amount' => 1000 ],
		];
		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	#[Test]
	public function payment_flow_succeeded_skips_alert_when_already_refunded_on_cancel(): void {
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'is_paid' )->once()->andReturn( false );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'failed', 'on-hold' ] )->andReturn( false );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_cancel_refund_processed' )->andReturn( '1' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save' );
		$order->shouldNotReceive( 'add_order_note' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );
		Functions\expect( 'wp_mail' )->never();

		$payload = [
			'type' => 'payment_flow.succeeded',
			'data' => [ 'id' => 'pflw_refunded', 'status' => 'succeeded' ],
		];
		$request = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		Payjp_Webhook_Handler::handle_request( $request );
	}

	#[Test]
	public function payment_flow_succeeded_skips_alert_when_already_notified(): void {
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'is_paid' )->once()->andReturn( false );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'failed', 'on-hold' ] )->andReturn( false );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_cancel_refund_processed' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_alerted_late_succeeded' )->andReturn( '1' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save' );
		$order->shouldNotReceive( 'add_order_note' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );
		Functions\expect( 'wp_mail' )->never();

		$payload = [
			'type' => 'payment_flow.succeeded',
			'data' => [ 'id' => 'pflw_already_alerted', 'status' => 'succeeded' ],
		];
		$request = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		Payjp_Webhook_Handler::handle_request( $request );
	}

	// ── payment_flow.amount_capturable_updated: late webhook alert (#23) ─────

	#[Test]
	public function payment_flow_capturable_voids_authorization_on_cancelled_order(): void {
		Functions\when( 'get_option' )->justReturn(
			[ 'webhook_secret' => 'secret', 'alert_email' => 'ops@example.com', 'test_secret_key' => 'sk_test_xxx' ]
		);

		$api = Mockery::mock( \Payjp_API::class );
		$api->shouldReceive( 'post' )
			->once()
			->with( '/payment_flows/pflw_cap/cancel', [ 'cancellation_reason' => 'requested_by_customer' ], 1 )
			->andReturn( [] );
		Payjp_Webhook_Handler::set_api_factory( static fn( string $key ) => $api );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'on-hold' ] )->andReturn( false );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_alerted_late_capturable' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_payjp_alerted_late_capturable', '1' );
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'has_status' )->once()->with( [ 'cancelled', 'failed' ] )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->once();
		$order->shouldReceive( 'get_status' )->andReturn( 'cancelled' );
		$order->shouldReceive( 'get_order_number' )->andReturn( '200' );
		$order->shouldReceive( 'get_id' )->andReturn( 1 );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.com/order/200' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );
		Functions\expect( 'wp_mail' )->once()->andReturn( true );

		$payload = [
			'type' => 'payment_flow.amount_capturable_updated',
			'data' => [ 'id' => 'pflw_cap', 'status' => 'requires_capture', 'livemode' => false ],
		];
		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	#[Test]
	public function payment_flow_capturable_alerts_when_void_api_call_fails(): void {
		Functions\when( 'get_option' )->justReturn(
			[ 'webhook_secret' => 'secret', 'alert_email' => 'ops@example.com', 'test_secret_key' => 'sk_test_xxx' ]
		);

		$api = Mockery::mock( \Payjp_API::class );
		$api->shouldReceive( 'post' )->once()->andThrow( new \RuntimeException( 'invalid_status' ) );
		Payjp_Webhook_Handler::set_api_factory( static fn( string $key ) => $api );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'on-hold' ] )->andReturn( false );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_alerted_late_capturable' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_payjp_alerted_late_capturable', '1' );
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'has_status' )->once()->with( [ 'cancelled', 'failed' ] )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->once();
		$order->shouldReceive( 'get_status' )->andReturn( 'cancelled' );
		$order->shouldReceive( 'get_order_number' )->andReturn( '201' );
		$order->shouldReceive( 'get_id' )->andReturn( 1 );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.com/order/201' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );
		Functions\expect( 'wp_mail' )->once()->andReturn( true );

		$payload = [
			'type' => 'payment_flow.amount_capturable_updated',
			'data' => [ 'id' => 'pflw_cap_fail', 'status' => 'requires_capture', 'livemode' => false ],
		];
		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	#[Test]
	public function payment_flow_capturable_alerts_without_void_when_secret_key_missing(): void {
		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret', 'alert_email' => 'ops@example.com' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'on-hold' ] )->andReturn( false );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_alerted_late_capturable' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_payjp_alerted_late_capturable', '1' );
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'has_status' )->once()->with( [ 'cancelled', 'failed' ] )->andReturn( true );
		// Copilot review fix: skipped (no API key) must not be worded as "FAILED" —
		// that phrasing is reserved for an attempted-and-failed void.
		$order->shouldReceive( 'add_order_note' )->once()->with( Mockery::pattern( '/not attempted/' ) );
		$order->shouldReceive( 'get_status' )->andReturn( 'cancelled' );
		$order->shouldReceive( 'get_order_number' )->andReturn( '202' );
		$order->shouldReceive( 'get_id' )->andReturn( 1 );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.com/order/202' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );
		Functions\expect( 'wp_mail' )
			->once()
			->with( Mockery::any(), Mockery::any(), Mockery::pattern( '/not attempted/' ) )
			->andReturn( true );

		$payload = [
			'type' => 'payment_flow.amount_capturable_updated',
			'data' => [ 'id' => 'pflw_cap_nokey', 'status' => 'requires_capture', 'livemode' => false ],
		];
		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	#[Test]
	public function payment_flow_capturable_skips_void_when_livemode_missing(): void {
		// Copilot review fix: a payload without a livemode flag must not silently
		// guess "test" — get_api_for_flow() should bail out (skip the void) rather
		// than risk operating against the wrong PAY.JP environment.
		Functions\when( 'get_option' )->justReturn(
			[ 'webhook_secret' => 'secret', 'alert_email' => 'ops@example.com', 'test_secret_key' => 'sk_test_xxx', 'live_secret_key' => 'sk_live_xxx' ]
		);

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'on-hold' ] )->andReturn( false );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_alerted_late_capturable' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_payjp_alerted_late_capturable', '1' );
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'has_status' )->once()->with( [ 'cancelled', 'failed' ] )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->once()->with( Mockery::pattern( '/not attempted/' ) );
		$order->shouldReceive( 'get_status' )->andReturn( 'cancelled' );
		$order->shouldReceive( 'get_order_number' )->andReturn( '203' );
		$order->shouldReceive( 'get_id' )->andReturn( 1 );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.com/order/203' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );
		Functions\expect( 'wp_mail' )
			->once()
			->with( Mockery::any(), Mockery::any(), Mockery::pattern( '/not attempted/' ) )
			->andReturn( true );

		$payload = [
			'type' => 'payment_flow.amount_capturable_updated',
			// livemode intentionally omitted.
			'data' => [ 'id' => 'pflw_cap_no_livemode', 'status' => 'requires_capture' ],
		];
		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	#[Test]
	public function payment_flow_capturable_skips_void_when_livemode_not_boolean(): void {
		// Copilot review fix: a non-boolean livemode (e.g. the string "false", which
		// PHP treats as truthy) must not be coerced into picking the live secret key.
		Functions\when( 'get_option' )->justReturn(
			[ 'webhook_secret' => 'secret', 'alert_email' => 'ops@example.com', 'test_secret_key' => 'sk_test_xxx', 'live_secret_key' => 'sk_live_xxx' ]
		);

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'on-hold' ] )->andReturn( false );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_alerted_late_capturable' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_payjp_alerted_late_capturable', '1' );
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'has_status' )->once()->with( [ 'cancelled', 'failed' ] )->andReturn( true );
		$order->shouldReceive( 'add_order_note' )->once()->with( Mockery::pattern( '/not attempted/' ) );
		$order->shouldReceive( 'get_status' )->andReturn( 'cancelled' );
		$order->shouldReceive( 'get_order_number' )->andReturn( '204' );
		$order->shouldReceive( 'get_id' )->andReturn( 1 );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.com/order/204' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );
		Functions\expect( 'wp_mail' )
			->once()
			->with( Mockery::any(), Mockery::any(), Mockery::pattern( '/not attempted/' ) )
			->andReturn( true );

		$payload = [
			'type' => 'payment_flow.amount_capturable_updated',
			'data' => [ 'id' => 'pflw_cap_string_livemode', 'status' => 'requires_capture', 'livemode' => 'false' ],
		];
		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	#[Test]
	public function payment_flow_capturable_retry_on_processed_order_is_noop(): void {
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'on-hold' ] )->andReturn( false );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( 'pflw_processed' );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'processing', 'completed' ] )->andReturn( true );
		$order->shouldNotReceive( 'get_meta' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'add_order_note' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );
		Functions\expect( 'wp_mail' )->never();

		$payload = [
			'type' => 'payment_flow.amount_capturable_updated',
			'data' => [ 'id' => 'pflw_processed', 'status' => 'requires_capture' ],
		];
		$request = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		Payjp_Webhook_Handler::handle_request( $request );
	}

	#[Test]
	public function payment_flow_capturable_skips_alert_when_already_notified(): void {
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'on-hold' ] )->andReturn( false );
		$order->shouldReceive( 'get_transaction_id' )->once()->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_alerted_late_capturable' )->andReturn( '1' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save' );
		$order->shouldNotReceive( 'add_order_note' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );
		Functions\expect( 'wp_mail' )->never();

		$payload = [
			'type' => 'payment_flow.amount_capturable_updated',
			'data' => [ 'id' => 'pflw_cap_already', 'status' => 'requires_capture' ],
		];
		$request = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		Payjp_Webhook_Handler::handle_request( $request );
	}

	// ── payment_flow.payment_failed ───────────────────────────────────────────

	#[Test]
	public function payment_flow_payment_failed_updates_order_status_to_failed(): void {
		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'is_paid' )->once()->andReturn( false );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'failed', 'cancelled' ] )->andReturn( false );
		$order->shouldReceive( 'update_status' )->once()->with( 'failed', Mockery::type( 'string' ) );
		$order->shouldReceive( 'get_id' )->andReturn( 1 );
		$this->expect_monitor_clear( $order );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$payload = [
			'type' => 'payment_flow.payment_failed',
			'data' => [ 'id' => 'pflw_fail', 'status' => 'payment_failed' ],
		];
		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	#[Test]
	public function payment_flow_payment_failed_skips_already_failed_order(): void {
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'is_paid' )->once()->andReturn( false );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'failed', 'cancelled' ] )->andReturn( true );
		$order->shouldNotReceive( 'update_status' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$payload = [
			'type' => 'payment_flow.payment_failed',
			'data' => [ 'id' => 'pflw_already_failed', 'status' => 'payment_failed' ],
		];
		$request = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		Payjp_Webhook_Handler::handle_request( $request );
	}

	#[Test]
	public function payment_flow_payment_failed_skips_already_paid_order(): void {
		// 入金済み（1 回目失敗 → 同じフローの再試行で成功）の注文に、遅れて（順序が逆転して）
		// 届いた payment_failed が failed に差し戻さないことを保証する回帰テスト。
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'is_paid' )->once()->andReturn( true );
		$order->shouldNotReceive( 'update_status' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$payload = [
			'type' => 'payment_flow.payment_failed',
			'data' => [ 'id' => 'pflw_paid_then_failed', 'status' => 'payment_failed' ],
		];
		$request = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		Payjp_Webhook_Handler::handle_request( $request );
	}

	// ── refund.created ────────────────────────────────────────────────────────

	#[Test]
	public function refund_created_adds_order_note_exactly_once(): void {
		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_refund_processed_ref_abc' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_payjp_refund_processed_ref_abc', '1' );
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->once();
		$order->shouldReceive( 'get_id' )->andReturn( 1 );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$payload = [
			'type' => 'refund.created',
			'data' => [
				'id'           => 'ref_abc',
				'payment_flow' => 'pflw_xyz',
				'amount'       => 1000,
			],
		];
		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	#[Test]
	public function refund_created_skips_when_refund_id_already_recorded(): void {
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_refund_processed_ref_abc' )->andReturn( '1' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save' );
		$order->shouldNotReceive( 'add_order_note' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$payload = [
			'type' => 'refund.created',
			'data' => [
				'id'           => 'ref_abc',
				'payment_flow' => 'pflw_xyz',
				'amount'       => 1000,
			],
		];
		$request = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		Payjp_Webhook_Handler::handle_request( $request );
	}

	#[Test]
	public function refund_created_without_flow_id_is_noop(): void {
		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$payload = [
			'type' => 'refund.created',
			'data' => [
				'id'     => 'ref_abc',
				'amount' => 1000,
				// payment_flow intentionally missing
			],
		];
		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}
}
