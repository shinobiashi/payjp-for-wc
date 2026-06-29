<?php
/**
 * Unit tests for WC_Gateway_Payjp_Paypay::process_refund().
 *
 * Covers: order-not-found, missing flow ID, API error, empty API response,
 * full refund, partial refund, idempotency marker, and order note label.
 *
 * @package Payjp_For_WooCommerce
 */

namespace Payjp\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WC_Gateway_Payjp_Paypay;
use WC_Order;
use Payjp_API;
use WP_Error;

/**
 * Tests for WC_Gateway_Payjp_Paypay::process_refund().
 */
class PaypayGatewayRefundTest extends TestCase {

	/** @var WC_Gateway_Payjp_Paypay&\Mockery\MockInterface */
	private WC_Gateway_Payjp_Paypay $gateway;

	/** @var Payjp_API&\Mockery\MockInterface */
	private Payjp_API $api;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub WordPress / WooCommerce functions called during construction and in process_refund().
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'update_option' )->justReturn( true );

		// Partial mock: real process_refund() / do_refund() run; get_api() (protected) is overridden.
		$this->gateway = Mockery::mock( WC_Gateway_Payjp_Paypay::class )
			->makePartial()
			->shouldAllowMockingProtectedMethods();
		$this->api     = Mockery::mock( Payjp_API::class );
		$this->gateway->shouldReceive( 'get_api' )->andReturn( $this->api );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	// ── Order / flow validation ───────────────────────────────────────────────

	#[Test]
	public function refund_returns_wp_error_when_order_not_found(): void {
		Functions\when( 'wc_get_order' )->justReturn( false );

		$result = $this->gateway->process_refund( 99 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_order', $result->get_error_code() );
	}

	#[Test]
	public function refund_returns_wp_error_when_no_flow_id(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_flow_id' )->andReturn( '' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$result = $this->gateway->process_refund( 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_flow_id', $result->get_error_code() );
	}

	// ── API error handling ────────────────────────────────────────────────────

	#[Test]
	public function refund_returns_wp_error_when_api_throws(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_flow_id' )->andReturn( 'pflw_pp1' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_capture_method' )->andReturn( '' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'post' )->andThrow( new \RuntimeException( 'network error' ) );

		$result = $this->gateway->process_refund( 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'payjp_refund_error', $result->get_error_code() );
	}

	#[Test]
	public function refund_returns_wp_error_when_api_returns_no_refund_id(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_flow_id' )->andReturn( 'pflw_pp1' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_capture_method' )->andReturn( '' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'post' )->andReturn( [] );

		$result = $this->gateway->process_refund( 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'payjp_refund_error', $result->get_error_code() );
	}

	// ── Successful refund ────────────────────────────────────────────────────

	#[Test]
	public function full_refund_omits_amount_from_api_request(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_flow_id' )->andReturn( 'pflw_pp2' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_capture_method' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->with( '_payjp_refund_processed_pyr_pp_full', '1' )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->once();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'post' )
			->once()
			->with( '/payment_refunds', [ 'payment_flow_id' => 'pflw_pp2' ] )
			->andReturn( [ 'id' => 'pyr_pp_full' ] );

		$result = $this->gateway->process_refund( 1, null );

		$this->assertTrue( $result );
	}

	#[Test]
	public function partial_refund_sends_amount_to_api(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_flow_id' )->andReturn( 'pflw_pp3' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_capture_method' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->with( '_payjp_refund_processed_pyr_pp_partial', '1' )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->once();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'post' )
			->once()
			->with( '/payment_refunds', [ 'payment_flow_id' => 'pflw_pp3', 'amount' => 100 ] )
			->andReturn( [ 'id' => 'pyr_pp_partial' ] );

		$result = $this->gateway->process_refund( 1, 100.0 );

		$this->assertTrue( $result );
	}

	#[Test]
	public function successful_refund_writes_idempotency_marker(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_flow_id' )->andReturn( 'pflw_pp4' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_capture_method' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->with( '_payjp_refund_processed_pyr_pp_idem', '1' )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )->once();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'post' )->andReturn( [ 'id' => 'pyr_pp_idem' ] );

		$result = $this->gateway->process_refund( 1 );

		$this->assertTrue( $result );
	}

	#[Test]
	public function order_note_contains_refund_id(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_flow_id' )->andReturn( 'pflw_pp5' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_capture_method' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )
			->once()
			->with( Mockery::on( fn( $note ) => str_contains( $note, 'pyr_pp_note' ) ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'post' )->andReturn( [ 'id' => 'pyr_pp_note' ] );

		$this->assertTrue( $this->gateway->process_refund( 1 ) );
	}

	#[Test]
	public function order_note_contains_gateway_label_paypay(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_flow_id' )->andReturn( 'pflw_pp6' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_capture_method' )->andReturn( '' );
		$order->shouldReceive( 'update_meta_data' )->once();
		$order->shouldReceive( 'save' )->once();
		$order->shouldReceive( 'add_order_note' )
			->once()
			->with( Mockery::on( fn( $note ) => str_contains( $note, 'PAY.JP PayPay' ) ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'post' )->andReturn( [ 'id' => 'pyr_pp_label' ] );

		$this->assertTrue( $this->gateway->process_refund( 1 ) );
	}
}
