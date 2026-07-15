<?php
/**
 * Unit tests for PAY.JP gateway order cancellation.
 *
 * Covers cancel_order() (card + PayPay) and all branches of the shared
 * cancel_payment_flow() implementation in the base class:
 *   - gateway ID guard (order belonging to another gateway is skipped)
 *   - no flow ID → early return
 *   - API fetch error → order note added
 *   - empty flow status → order note added (new guard)
 *   - terminal state (canceled / payment_failed) → silent skip
 *   - succeeded → do_refund() called via PAY.JP /payment_refunds
 *   - non-terminal → POST /payment_flows/{id}/cancel called
 *   - cancel API error → error note added
 *
 * @package Payjp_For_WooCommerce
 */

namespace Payjp\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WC_Gateway_Payjp_Card;
use WC_Gateway_Payjp_Paypay;
use WC_Order;
use Payjp_API;

/**
 * Tests for cancel_order() / cancel_payment_flow() across both PAY.JP gateways.
 */
class GatewayCancelTest extends TestCase {

	/**
	 * Card gateway partial mock.
	 *
	 * @var WC_Gateway_Payjp_Card&\Mockery\MockInterface
	 */
	private WC_Gateway_Payjp_Card $card;

	/**
	 * PayPay gateway partial mock.
	 *
	 * @var WC_Gateway_Payjp_Paypay&\Mockery\MockInterface
	 */
	private WC_Gateway_Payjp_Paypay $paypay;

	/**
	 * PAY.JP API mock shared across all gateways under test.
	 *
	 * @var Payjp_API&\Mockery\MockInterface
	 */
	private Payjp_API $api;

	/**
	 * Bootstrap Brain\Monkey, stub WP functions, and build partial-mocked gateways.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );

		$this->api = Mockery::mock( Payjp_API::class );

		$this->card = Mockery::mock( WC_Gateway_Payjp_Card::class )
			->makePartial()
			->shouldAllowMockingProtectedMethods();
		$this->card->shouldReceive( 'get_api' )->andReturn( $this->api );
		// makePartial() does not call the real constructor, so $this->id stays at its
		// default empty string. Set it explicitly so cancel_order()'s gateway ID guard
		// matches the mocked order's get_payment_method() return value.
		$this->card->id = 'payjp_card';

		$this->paypay = Mockery::mock( WC_Gateway_Payjp_Paypay::class )
			->makePartial()
			->shouldAllowMockingProtectedMethods();
		$this->paypay->shouldReceive( 'get_api' )->andReturn( $this->api );
		$this->paypay->id = 'payjp_paypay';
	}

	/**
	 * Tear down Brain\Monkey and verify all Mockery expectations.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	// ── Gateway ID guard ──────────────────────────────────────────────────────

	/**
	 * Card gateway ignores orders that belong to a different gateway.
	 */
	#[Test]
	public function card_cancel_order_skips_when_order_belongs_to_different_gateway(): void {
		$this->expectNotToPerformAssertions();

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'payjp_paypay' );
		$order->shouldNotReceive( 'get_meta' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->card->cancel_order( 1 );
	}

	/**
	 * PayPay gateway ignores orders that belong to a different gateway.
	 */
	#[Test]
	public function paypay_cancel_order_skips_when_order_belongs_to_different_gateway(): void {
		$this->expectNotToPerformAssertions();

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'payjp_card' );
		$order->shouldNotReceive( 'get_meta' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->paypay->cancel_order( 1 );
	}

	// ── No flow ID ────────────────────────────────────────────────────────────

	/**
	 * Returns early without adding an order note when no Payment Flow ID is stored.
	 */
	#[Test]
	public function cancel_returns_early_when_no_flow_id(): void {
		$this->expectNotToPerformAssertions();

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->andReturn( 'payjp_card' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_flow_id' )->andReturn( '' );
		$order->shouldNotReceive( 'add_order_note' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->card->cancel_order( 1 );
	}

	// ── API fetch error ───────────────────────────────────────────────────────

	/**
	 * Adds an order note when the API throws during flow status retrieval.
	 */
	#[Test]
	public function cancel_adds_note_when_flow_fetch_throws(): void {
		$this->expectNotToPerformAssertions();

		$order = $this->make_order( 'payjp_card', 'pflw_err' );
		$order->shouldReceive( 'add_order_note' )->once();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'get' )
			->once()
			->andThrow( new \RuntimeException( 'timeout' ) );

		$this->card->cancel_order( 1 );
	}

	// ── Empty status guard ────────────────────────────────────────────────────

	/**
	 * Adds an order note when the API returns a flow without a status field.
	 */
	#[Test]
	public function cancel_adds_note_when_flow_status_is_empty(): void {
		$this->expectNotToPerformAssertions();

		$order = $this->make_order( 'payjp_card', 'pflw_nostatus' );
		$order->shouldReceive( 'add_order_note' )->once();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'get' )
			->once()
			->andReturn( array( 'id' => 'pflw_nostatus' ) ); // No 'status' key.

		$this->card->cancel_order( 1 );
	}

	// ── Terminal state skip ───────────────────────────────────────────────────

	/**
	 * Does nothing when the flow is already in the canceled terminal state.
	 */
	#[Test]
	public function cancel_skips_when_flow_already_canceled(): void {
		$this->expectNotToPerformAssertions();

		$order = $this->make_order( 'payjp_card', 'pflw_done' );
		$order->shouldNotReceive( 'add_order_note' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'get' )
			->once()
			->andReturn(
				array(
					'id'     => 'pflw_done',
					'status' => 'canceled',
				)
			);

		$this->card->cancel_order( 1 );
	}

	/**
	 * Does nothing when the flow is already in the payment_failed terminal state.
	 */
	#[Test]
	public function cancel_skips_when_flow_payment_failed(): void {
		$this->expectNotToPerformAssertions();

		$order = $this->make_order( 'payjp_card', 'pflw_fail' );
		$order->shouldNotReceive( 'add_order_note' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'get' )
			->once()
			->andReturn(
				array(
					'id'     => 'pflw_fail',
					'status' => 'payment_failed',
				)
			);

		$this->card->cancel_order( 1 );
	}

	// ── Succeeded → automatic refund ─────────────────────────────────────────

	/**
	 * Calls /payment_refunds when the flow has already been captured (succeeded).
	 */
	#[Test]
	public function cancel_calls_refund_api_when_flow_succeeded(): void {
		$this->expectNotToPerformAssertions();

		$order = $this->make_order( 'payjp_card', 'pflw_paid' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_cancel_refund_processed' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_capture_method' )->andReturn( 'automatic' );
		$order->shouldReceive( 'update_meta_data' )->with( '_payjp_refund_processed_pyr_auto', '1' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_payjp_cancel_refund_processed', '1' )->once();
		$order->shouldReceive( 'save' )->twice();
		$order->shouldReceive( 'add_order_note' )->once();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'get' )
			->once()
			->with( '/payment_flows/pflw_paid', Mockery::any() )
			->andReturn(
				array(
					'id'     => 'pflw_paid',
					'status' => 'succeeded',
				)
			);

		$this->api->shouldReceive( 'post' )
			->once()
			->with( '/payment_refunds', array( 'payment_flow_id' => 'pflw_paid' ) )
			->andReturn( array( 'id' => 'pyr_auto' ) );

		$this->card->cancel_order( 1 );
	}

	/**
	 * Does not re-issue a refund when the auto-refund guard meta is already set.
	 */
	#[Test]
	public function cancel_skips_auto_refund_when_already_processed(): void {
		$this->expectNotToPerformAssertions();

		$order = $this->make_order( 'payjp_card', 'pflw_paid2' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_cancel_refund_processed' )->andReturn( '1' );
		$order->shouldNotReceive( 'add_order_note' );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'get' )
			->once()
			->andReturn(
				array(
					'id'     => 'pflw_paid2',
					'status' => 'succeeded',
				)
			);
		$this->api->shouldNotReceive( 'post' );

		$this->card->cancel_order( 1 );
	}

	// ── Non-terminal → cancel endpoint ───────────────────────────────────────

	/**
	 * Calls the cancel endpoint when the flow is in requires_action (non-terminal) state.
	 */
	#[Test]
	public function cancel_calls_cancel_endpoint_for_requires_action_flow(): void {
		$this->expectNotToPerformAssertions();

		$order = $this->make_order( 'payjp_card', 'pflw_pend' );
		$order->shouldReceive( 'add_order_note' )->once();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'get' )
			->once()
			->with( '/payment_flows/pflw_pend', Mockery::any() )
			->andReturn(
				array(
					'id'     => 'pflw_pend',
					'status' => 'requires_action',
				)
			);

		$this->api->shouldReceive( 'post' )
			->once()
			->with(
				'/payment_flows/pflw_pend/cancel',
				array( 'cancellation_reason' => 'requested_by_customer' ),
				Mockery::any()
			)
			->andReturn(
				array(
					'id'     => 'pflw_pend',
					'status' => 'canceled',
				)
			);

		$this->card->cancel_order( 1 );
	}

	/**
	 * Adds an error note when the cancel endpoint throws and the re-fetch shows a
	 * non-succeeded status (no race — genuine cancel failure).
	 */
	#[Test]
	public function cancel_adds_error_note_when_cancel_api_throws(): void {
		$this->expectNotToPerformAssertions();

		$order = $this->make_order( 'payjp_card', 'pflw_throw' );
		$order->shouldReceive( 'add_order_note' )->once();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'get' )
			->once()
			->with( '/payment_flows/pflw_throw', Mockery::any() )
			->andReturn(
				array(
					'id'     => 'pflw_throw',
					'status' => 'requires_payment_method',
				)
			);

		$this->api->shouldReceive( 'post' )
			->once()
			->andThrow( new \RuntimeException( 'cancel failed' ) );

		// Race fallback re-fetch: still not succeeded → generic failure note only.
		$this->api->shouldReceive( 'get' )
			->once()
			->with( '/payment_flows/pflw_throw', Mockery::any() )
			->andReturn(
				array(
					'id'     => 'pflw_throw',
					'status' => 'requires_action',
				)
			);

		$this->card->cancel_order( 1 );
	}

	// ── Cancel race fallback ─────────────────────────────────────────────────

	/**
	 * Falls back to the automatic refund when the cancel endpoint throws because the
	 * flow raced to 'succeeded' in between the status fetch and the cancel call.
	 */
	#[Test]
	public function cancel_falls_back_to_refund_when_flow_races_to_succeeded(): void {
		$this->expectNotToPerformAssertions();

		$order = $this->make_order( 'payjp_card', 'pflw_race' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_cancel_refund_processed' )->andReturn( '' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_capture_method' )->andReturn( 'automatic' );
		$order->shouldReceive( 'update_meta_data' )->with( '_payjp_refund_processed_pyr_race', '1' )->once();
		$order->shouldReceive( 'update_meta_data' )->with( '_payjp_cancel_refund_processed', '1' )->once();
		$order->shouldReceive( 'save' )->twice();
		$order->shouldReceive( 'add_order_note' )->twice();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		// Initial status fetch: still cancelable.
		$this->api->shouldReceive( 'get' )
			->once()
			->with( '/payment_flows/pflw_race', Mockery::any() )
			->andReturn(
				array(
					'id'     => 'pflw_race',
					'status' => 'processing',
				)
			);

		// Cancel POST fails because the flow already succeeded.
		$this->api->shouldReceive( 'post' )
			->once()
			->with(
				'/payment_flows/pflw_race/cancel',
				array( 'cancellation_reason' => 'requested_by_customer' ),
				Mockery::any()
			)
			->andThrow( new \RuntimeException( 'invalid_status' ) );

		// Race fallback re-fetch: now succeeded.
		$this->api->shouldReceive( 'get' )
			->once()
			->with( '/payment_flows/pflw_race', Mockery::any() )
			->andReturn(
				array(
					'id'     => 'pflw_race',
					'status' => 'succeeded',
				)
			);

		$this->api->shouldReceive( 'post' )
			->once()
			->with( '/payment_refunds', array( 'payment_flow_id' => 'pflw_race' ) )
			->andReturn( array( 'id' => 'pyr_race' ) );

		$this->card->cancel_order( 1 );
	}

	/**
	 * Does not re-issue a refund via the race fallback when the auto-refund guard
	 * meta is already set (idempotent even through the race path).
	 */
	#[Test]
	public function cancel_race_fallback_skips_refund_when_already_processed(): void {
		$this->expectNotToPerformAssertions();

		$order = $this->make_order( 'payjp_card', 'pflw_race2' );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_cancel_refund_processed' )->andReturn( '1' );
		$order->shouldReceive( 'add_order_note' )->once();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'get' )
			->once()
			->with( '/payment_flows/pflw_race2', Mockery::any() )
			->andReturn(
				array(
					'id'     => 'pflw_race2',
					'status' => 'processing',
				)
			);

		$this->api->shouldReceive( 'post' )
			->once()
			->andThrow( new \RuntimeException( 'invalid_status' ) );

		$this->api->shouldReceive( 'get' )
			->once()
			->with( '/payment_flows/pflw_race2', Mockery::any() )
			->andReturn(
				array(
					'id'     => 'pflw_race2',
					'status' => 'succeeded',
				)
			);

		$this->api->shouldNotReceive( 'post' )->with( '/payment_refunds', Mockery::any() );

		$this->card->cancel_order( 1 );
	}

	/**
	 * Keeps the generic cancel-failure note when the race fallback re-fetch itself
	 * throws (e.g. a transient network error) — no refund is attempted.
	 */
	#[Test]
	public function cancel_keeps_failure_note_when_race_refetch_throws(): void {
		$this->expectNotToPerformAssertions();

		$order = $this->make_order( 'payjp_card', 'pflw_race3' );
		$order->shouldReceive( 'add_order_note' )->once();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'get' )
			->once()
			->with( '/payment_flows/pflw_race3', Mockery::any() )
			->andReturn(
				array(
					'id'     => 'pflw_race3',
					'status' => 'processing',
				)
			);

		$this->api->shouldReceive( 'post' )
			->once()
			->andThrow( new \RuntimeException( 'cancel failed' ) );

		$this->api->shouldReceive( 'get' )
			->once()
			->with( '/payment_flows/pflw_race3', Mockery::any() )
			->andThrow( new \RuntimeException( 'timeout' ) );

		$this->card->cancel_order( 1 );
	}

	// ── PayPay label ──────────────────────────────────────────────────────────

	/**
	 * Order note for PayPay cancellation includes the "PayPay" label.
	 */
	#[Test]
	public function paypay_cancel_order_note_contains_paypay_label(): void {
		$this->expectNotToPerformAssertions();

		$order = $this->make_order( 'payjp_paypay', 'pflw_pp' );
		$order->shouldReceive( 'add_order_note' )
			->once()
			->with( Mockery::on( fn( $note ) => str_contains( $note, 'PayPay' ) ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->api->shouldReceive( 'get' )
			->once()
			->andReturn(
				array(
					'id'     => 'pflw_pp',
					'status' => 'requires_action',
				)
			);

		$this->api->shouldReceive( 'post' )
			->once()
			->andReturn( array() );

		$this->paypay->cancel_order( 1 );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Build a WC_Order mock with payment method and flow ID pre-configured.
	 *
	 * @param string $payment_method Gateway ID, e.g. 'payjp_card'.
	 * @param string $flow_id        PAY.JP Payment Flow ID.
	 * @return WC_Order&\Mockery\MockInterface
	 */
	private function make_order( string $payment_method, string $flow_id ): WC_Order {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_payment_method' )->andReturn( $payment_method );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_flow_id' )->andReturn( $flow_id );
		$order->shouldReceive( 'get_id' )->andReturn( 1 );
		return $order;
	}
}
