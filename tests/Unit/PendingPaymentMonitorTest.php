<?php
/**
 * Unit tests for Payjp_Pending_Payment_Monitor.
 *
 * Covers: the woocommerce_cancel_unpaid_order hold filter, flag/poll scheduling
 * via start(), the poll_flow() settlement paths, retry/exhaustion behaviour,
 * and secret-key selection from the _payjp_flow_livemode meta.
 *
 * @package Payjp_For_WooCommerce
 */

namespace Payjp\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Payjp_Pending_Payment_Monitor;
use WC_Order;

/**
 * Tests for Payjp_Pending_Payment_Monitor.
 */
class PendingPaymentMonitorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset the Payjp_Settings static cache so each test gets a fresh get_option() call.
		\Payjp_Settings::flush_cache();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Payjp_Pending_Payment_Monitor::set_api_factory( null );
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Build a WC_Order mock representing a pending order with an in-flight flow.
	 *
	 * @param string $livemode Value of _payjp_flow_livemode meta.
	 * @param string $attempts Value of _payjp_flow_poll_attempts meta.
	 * @return WC_Order&Mockery\MockInterface
	 */
	private function pending_order_mock( string $livemode = '0', string $attempts = '' ): WC_Order {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'is_paid' )->once()->andReturn( false );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'on-hold' ] )->andReturn( true );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_flow_id' )->andReturn( 'pflw_poll' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_flow_livemode' )->andReturn( $livemode );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_flow_poll_attempts' )->andReturn( $attempts );
		return $order;
	}

	/**
	 * Add the expectations produced by Payjp_Pending_Payment_Monitor::clear().
	 *
	 * @param WC_Order&Mockery\MockInterface $order Order mock.
	 */
	private function expect_clear( WC_Order $order ): void {
		$order->shouldReceive( 'delete_meta_data' )->once()->with( '_payjp_awaiting_webhook' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( '_payjp_flow_poll_attempts' );
		$order->shouldReceive( 'save' )->atLeast()->once();
		Functions\expect( 'as_unschedule_action' )
			->once()
			->with( 'payjp_for_wc_poll_flow', [ 'order_id' => 42 ], 'payjp-for-wc' );
	}

	// ── hold_auto_cancel ──────────────────────────────────────────────────────

	#[Test]
	public function hold_auto_cancel_suppresses_cancel_within_hold_window(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->once()->andReturn( 'payjp_paypay' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_awaiting_webhook' )->andReturn( (string) ( time() - 5 * MINUTE_IN_SECONDS ) );

		$this->assertFalse( Payjp_Pending_Payment_Monitor::hold_auto_cancel( true, $order ) );
	}

	#[Test]
	public function hold_auto_cancel_passes_through_when_flag_expired(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->once()->andReturn( 'payjp_paypay' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_awaiting_webhook' )->andReturn( (string) ( time() - 31 * MINUTE_IN_SECONDS ) );

		$this->assertTrue( Payjp_Pending_Payment_Monitor::hold_auto_cancel( true, $order ) );
	}

	#[Test]
	public function hold_auto_cancel_passes_through_without_flag(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->once()->andReturn( 'payjp_card' );
		$order->shouldReceive( 'get_meta' )->once()->with( '_payjp_awaiting_webhook' )->andReturn( '' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save' );

		$this->assertTrue( Payjp_Pending_Payment_Monitor::hold_auto_cancel( true, $order ) );
	}

	#[Test]
	public function hold_auto_cancel_ignores_other_gateways(): void {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->twice()->andReturn( 'cod' );
		$order->shouldNotReceive( 'get_meta' );
		$order->shouldNotReceive( 'update_meta_data' );
		$order->shouldNotReceive( 'save' );

		$this->assertTrue( Payjp_Pending_Payment_Monitor::hold_auto_cancel( true, $order ) );
		$this->assertFalse( Payjp_Pending_Payment_Monitor::hold_auto_cancel( false, $order ) );
	}

	// ── start() ───────────────────────────────────────────────────────────────

	#[Test]
	public function start_saves_flag_and_schedules_first_poll(): void {
		// Behaviour is verified through Mockery expectations (checked in tearDown).
		$this->expectNotToPerformAssertions();

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'update_meta_data' )
			->once()
			->with( '_payjp_awaiting_webhook', Mockery::on( static fn( $value ) => is_string( $value ) && is_numeric( $value ) ) );
		$order->shouldReceive( 'save' )->once();

		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::on( static fn( $timestamp ) => is_int( $timestamp ) && abs( $timestamp - ( time() + 300 ) ) <= 5 ),
				'payjp_for_wc_poll_flow',
				[ 'order_id' => 42 ],
				'payjp-for-wc'
			);

		Payjp_Pending_Payment_Monitor::start( $order );
	}

	// ── poll_flow(): settlement paths ─────────────────────────────────────────

	#[Test]
	public function poll_flow_completes_payment_when_flow_succeeded(): void {
		// Behaviour is verified through Mockery expectations (checked in tearDown).
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'test_secret_key' => 'sk_test_xxx' ] );

		$api = Mockery::mock( \Payjp_API::class );
		$api->shouldReceive( 'get' )
			->once()
			->with( '/payment_flows/pflw_poll', 42 )
			->andReturn( [ 'status' => 'succeeded' ] );
		Payjp_Pending_Payment_Monitor::set_api_factory( static fn( string $key ) => $api );

		$order = $this->pending_order_mock();
		$order->shouldReceive( 'payment_complete' )->once()->with( 'pflw_poll' );
		$order->shouldReceive( 'add_order_note' )->once()->with( Mockery::pattern( '/status polling/' ) );
		$this->expect_clear( $order );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		Payjp_Pending_Payment_Monitor::poll_flow( 42 );
	}

	#[Test]
	public function poll_flow_moves_order_to_processing_when_flow_requires_capture(): void {
		// Behaviour is verified through Mockery expectations (checked in tearDown).
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'test_secret_key' => 'sk_test_xxx' ] );

		$api = Mockery::mock( \Payjp_API::class );
		$api->shouldReceive( 'get' )->once()->andReturn( [ 'status' => 'requires_capture' ] );
		Payjp_Pending_Payment_Monitor::set_api_factory( static fn( string $key ) => $api );

		$order = $this->pending_order_mock();
		$order->shouldReceive( 'set_transaction_id' )->once()->with( 'pflw_poll' );
		$order->shouldReceive( 'update_status' )->once()->with( 'processing', Mockery::type( 'string' ) );
		$order->shouldNotReceive( 'payment_complete' );
		$this->expect_clear( $order );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		Payjp_Pending_Payment_Monitor::poll_flow( 42 );
	}

	#[Test]
	public function poll_flow_fails_order_when_flow_payment_failed(): void {
		// Behaviour is verified through Mockery expectations (checked in tearDown).
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'test_secret_key' => 'sk_test_xxx' ] );

		$api = Mockery::mock( \Payjp_API::class );
		$api->shouldReceive( 'get' )->once()->andReturn( [ 'status' => 'payment_failed' ] );
		Payjp_Pending_Payment_Monitor::set_api_factory( static fn( string $key ) => $api );

		$order = $this->pending_order_mock();
		$order->shouldReceive( 'update_status' )->once()->with( 'failed', Mockery::type( 'string' ) );
		$order->shouldNotReceive( 'payment_complete' );
		$this->expect_clear( $order );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		Payjp_Pending_Payment_Monitor::poll_flow( 42 );
	}

	// ── poll_flow(): retry and exhaustion ─────────────────────────────────────

	#[Test]
	public function poll_flow_reschedules_when_flow_still_in_flight(): void {
		// Behaviour is verified through Mockery expectations (checked in tearDown).
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'test_secret_key' => 'sk_test_xxx' ] );

		$api = Mockery::mock( \Payjp_API::class );
		$api->shouldReceive( 'get' )->once()->andReturn( [ 'status' => 'processing' ] );
		Payjp_Pending_Payment_Monitor::set_api_factory( static fn( string $key ) => $api );

		$order = $this->pending_order_mock( '0', '' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_payjp_flow_poll_attempts', '1' );
		$order->shouldReceive( 'save' )->once();
		$order->shouldNotReceive( 'delete_meta_data' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_schedule_single_action' )
			->once()
			->with(
				Mockery::on( static fn( $timestamp ) => is_int( $timestamp ) && abs( $timestamp - ( time() + 600 ) ) <= 5 ),
				'payjp_for_wc_poll_flow',
				[ 'order_id' => 42 ],
				'payjp-for-wc'
			);

		Payjp_Pending_Payment_Monitor::poll_flow( 42 );
	}

	#[Test]
	public function poll_flow_gives_up_after_final_attempt(): void {
		// Behaviour is verified through Mockery expectations (checked in tearDown).
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'test_secret_key' => 'sk_test_xxx' ] );

		$api = Mockery::mock( \Payjp_API::class );
		$api->shouldReceive( 'get' )->once()->andReturn( [ 'status' => 'processing' ] );
		Payjp_Pending_Payment_Monitor::set_api_factory( static fn( string $key ) => $api );

		$order = $this->pending_order_mock( '0', '2' );
		$order->shouldReceive( 'add_order_note' )->once()->with( Mockery::pattern( '/automatic cancellation/' ) );
		$order->shouldNotReceive( 'update_meta_data' );
		$this->expect_clear( $order );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		Functions\expect( 'as_schedule_single_action' )->never();

		Payjp_Pending_Payment_Monitor::poll_flow( 42 );
	}

	#[Test]
	public function poll_flow_clears_without_api_call_when_order_already_paid(): void {
		// Behaviour is verified through Mockery expectations (checked in tearDown).
		$this->expectNotToPerformAssertions();

		Payjp_Pending_Payment_Monitor::set_api_factory( static function () {
			throw new \LogicException( 'API must not be constructed for a settled order.' );
		} );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'is_paid' )->once()->andReturn( true );
		$order->shouldNotReceive( 'payment_complete' );
		$order->shouldNotReceive( 'update_status' );
		$this->expect_clear( $order );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		Payjp_Pending_Payment_Monitor::poll_flow( 42 );
	}

	#[Test]
	public function poll_flow_clears_without_api_call_when_order_cancelled(): void {
		// Behaviour is verified through Mockery expectations (checked in tearDown).
		$this->expectNotToPerformAssertions();

		Payjp_Pending_Payment_Monitor::set_api_factory( static function () {
			throw new \LogicException( 'API must not be constructed for a settled order.' );
		} );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$order->shouldReceive( 'is_paid' )->once()->andReturn( false );
		$order->shouldReceive( 'has_status' )->once()->with( [ 'pending', 'on-hold' ] )->andReturn( false );
		$order->shouldNotReceive( 'payment_complete' );
		$order->shouldNotReceive( 'update_status' );
		$this->expect_clear( $order );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		Payjp_Pending_Payment_Monitor::poll_flow( 42 );
	}

	#[Test]
	public function poll_flow_counts_api_error_as_attempt_and_reschedules(): void {
		// Behaviour is verified through Mockery expectations (checked in tearDown).
		$this->expectNotToPerformAssertions();

		Functions\when( 'get_option' )->justReturn( [ 'test_secret_key' => 'sk_test_xxx' ] );

		$api = Mockery::mock( \Payjp_API::class );
		$api->shouldReceive( 'get' )->once()->andThrow( new \RuntimeException( 'timeout' ) );
		Payjp_Pending_Payment_Monitor::set_api_factory( static fn( string $key ) => $api );

		$order = $this->pending_order_mock( '0', '' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_payjp_flow_poll_attempts', '1' );
		$order->shouldReceive( 'save' )->once();
		$order->shouldNotReceive( 'delete_meta_data' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\expect( 'as_schedule_single_action' )->once();

		Payjp_Pending_Payment_Monitor::poll_flow( 42 );
	}

	// ── poll_flow(): secret key selection (D-6) ───────────────────────────────

	#[Test]
	public function poll_flow_selects_live_key_when_flow_livemode_is_1(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'test_mode'       => true,
				'test_secret_key' => 'sk_test_B',
				'live_secret_key' => 'sk_live_A',
			]
		);

		$captured_key = $this->capture_api_key_for_livemode( '1' );

		$this->assertSame( 'sk_live_A', $captured_key );
	}

	#[Test]
	public function poll_flow_selects_test_key_when_flow_livemode_is_0(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'test_mode'       => false,
				'test_secret_key' => 'sk_test_B',
				'live_secret_key' => 'sk_live_A',
			]
		);

		$captured_key = $this->capture_api_key_for_livemode( '0' );

		$this->assertSame( 'sk_test_B', $captured_key );
	}

	#[Test]
	public function poll_flow_falls_back_to_active_key_without_livemode_meta(): void {
		// Active mode is live (test_mode false): a legacy order without the
		// _payjp_flow_livemode meta must use the currently active key.
		Functions\when( 'get_option' )->justReturn(
			[
				'test_mode'       => false,
				'test_secret_key' => 'sk_test_B',
				'live_secret_key' => 'sk_live_A',
			]
		);

		$captured_key = $this->capture_api_key_for_livemode( '' );

		$this->assertSame( 'sk_live_A', $captured_key );
	}

	/**
	 * Run poll_flow() with a succeeded flow and return the secret key handed
	 * to the API factory.
	 *
	 * @param string $livemode Value of the _payjp_flow_livemode meta.
	 * @return string|null
	 */
	private function capture_api_key_for_livemode( string $livemode ): ?string {
		$captured_key = null;

		$api = Mockery::mock( \Payjp_API::class );
		$api->shouldReceive( 'get' )->once()->andReturn( [ 'status' => 'succeeded' ] );
		Payjp_Pending_Payment_Monitor::set_api_factory(
			static function ( string $key ) use ( &$captured_key, $api ) {
				$captured_key = $key;
				return $api;
			}
		);

		$order = $this->pending_order_mock( $livemode );
		$order->shouldReceive( 'payment_complete' )->once();
		$order->shouldReceive( 'add_order_note' )->once();
		$this->expect_clear( $order );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		Payjp_Pending_Payment_Monitor::poll_flow( 42 );

		return $captured_key;
	}
}
