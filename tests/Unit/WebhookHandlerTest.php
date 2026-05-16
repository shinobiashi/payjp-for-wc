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

		// Stub WordPress translation/escape functions used inside the handler.
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
	}

	protected function tearDown(): void {
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

		$payload  = [ 'type' => 'unsupported.event', 'data' => [ 'object' => [] ] ];
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
			'data' => [ 'object' => [ 'id' => 'pflw_unknown', 'status' => 'succeeded' ] ],
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
		$order->shouldReceive( 'payment_complete' )->once()->with( 'pflw_abc123' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$payload = [
			'type' => 'payment_flow.succeeded',
			'data' => [ 'object' => [ 'id' => 'pflw_abc123', 'status' => 'succeeded' ] ],
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
			'data' => [ 'object' => [ 'id' => 'pflw_paid', 'status' => 'succeeded' ] ],
		];
		$request = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		Payjp_Webhook_Handler::handle_request( $request );
	}

	// ── payment_flow.payment_failed ───────────────────────────────────────────

	#[Test]
	public function payment_flow_payment_failed_updates_order_status_to_failed(): void {
		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'failed', 'cancelled' ] )->andReturn( false );
		$order->shouldReceive( 'update_status' )->once()->with( 'failed', Mockery::type( 'string' ) );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$payload = [
			'type' => 'payment_flow.payment_failed',
			'data' => [ 'object' => [ 'id' => 'pflw_fail', 'status' => 'payment_failed' ] ],
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
		$order->shouldReceive( 'has_status' )->once()->with( [ 'failed', 'cancelled' ] )->andReturn( true );
		$order->shouldNotReceive( 'update_status' );
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$payload = [
			'type' => 'payment_flow.payment_failed',
			'data' => [ 'object' => [ 'id' => 'pflw_already_failed', 'status' => 'payment_failed' ] ],
		];
		$request = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		Payjp_Webhook_Handler::handle_request( $request );
	}

	// ── refund.created ────────────────────────────────────────────────────────

	#[Test]
	public function refund_created_adds_order_note_exactly_once(): void {
		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_refund_id' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_payjp_refund_id', 'ref_abc' );
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->once();
		Functions\when( 'wc_get_orders' )->justReturn( [ $order ] );

		$payload = [
			'type' => 'refund.created',
			'data' => [
				'object' => [
					'id'           => 'ref_abc',
					'payment_flow' => 'pflw_xyz',
					'amount'       => 1000,
				],
			],
		];
		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	#[Test]
	public function refund_created_without_flow_id_is_noop(): void {
		Functions\when( 'get_option' )->justReturn( [ 'webhook_secret' => 'secret' ] );

		$payload = [
			'type' => 'refund.created',
			'data' => [
				'object' => [
					'id'     => 'ref_abc',
					'amount' => 1000,
					// payment_flow intentionally missing
				],
			],
		];
		$request  = new WP_REST_Request( [ 'x-payjp-webhook-token' => 'secret', 'content-type' => 'application/json' ], $payload );
		$response = Payjp_Webhook_Handler::handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}
}
