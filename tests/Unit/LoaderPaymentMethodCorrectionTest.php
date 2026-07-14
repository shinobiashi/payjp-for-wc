<?php
/**
 * Unit tests for Payjp_Loader::correct_payment_method_before_save().
 *
 * Covers the woocommerce_before_order_object_save guard that restores
 * payment_method (and, per Copilot review feedback on PR #20, the matching
 * payment_method_title) when WooCommerce Blocks' Hydration service overwrites
 * the gateway selection during an internal cart-sync request. Also covers the
 * is_admin() scoping added in response to further Copilot feedback, so that
 * legitimate wp-admin changes to payment_method are never reverted.
 *
 * Also covers the session-based legitimate-selection check: a customer who
 * switches away from PAY.JP to a different gateway on a reused pending order
 * (e.g. PayPay -> Cash on Delivery via "Change payment method") must not have
 * that change reverted, and the stale PAY.JP meta must be cleared so it can't
 * be picked up later by a delayed webhook.
 *
 * @package Payjp_For_WooCommerce
 */

namespace Payjp\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Payjp_Loader;
use WC_Order;

/**
 * Tests for Payjp_Loader::correct_payment_method_before_save().
 */
class LoaderPaymentMethodCorrectionTest extends TestCase {

	/**
	 * Bootstrap Brain\Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_admin' )->justReturn( false );
	}

	/**
	 * Tear down Brain\Monkey and verify all Mockery expectations.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Does nothing in wp-admin, even when payment_method would otherwise be
	 * corrected — the Hydration bug this guards against is frontend-only, so
	 * a deliberate admin-side payment_method change must not be reverted.
	 */
	#[Test]
	public function does_nothing_in_wp_admin(): void {
		$this->expectNotToPerformAssertions();

		Functions\when( 'is_admin' )->justReturn( true );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldNotReceive( 'get_changes' );
		$order->shouldNotReceive( 'set_payment_method' );

		Payjp_Loader::correct_payment_method_before_save( $order );
	}

	/**
	 * Does nothing when payment_method is not among the pending changes.
	 */
	#[Test]
	public function does_nothing_when_payment_method_is_not_changing(): void {
		$this->expectNotToPerformAssertions();

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_changes' )->andReturn( array() );
		$order->shouldNotReceive( 'get_meta' );
		$order->shouldNotReceive( 'set_payment_method' );

		Payjp_Loader::correct_payment_method_before_save( $order );
	}

	/**
	 * Does nothing when the order has no _payjp_payment_method meta (not a PAY.JP order).
	 */
	#[Test]
	public function does_nothing_when_order_is_not_a_payjp_order(): void {
		$this->expectNotToPerformAssertions();

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_changes' )->andReturn( array( 'payment_method' => 'bacs' ) );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_method' )->andReturn( '' );
		$order->shouldNotReceive( 'set_payment_method' );

		Payjp_Loader::correct_payment_method_before_save( $order );
	}

	/**
	 * Does nothing when the pending payment_method already matches the PAY.JP gateway.
	 */
	#[Test]
	public function does_nothing_when_payment_method_is_already_correct(): void {
		$this->expectNotToPerformAssertions();

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_changes' )->andReturn( array( 'payment_method' => 'payjp_paypay' ) );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_method' )->andReturn( 'paypay' );
		$order->shouldNotReceive( 'set_payment_method' );
		$order->shouldNotReceive( 'set_payment_method_title' );

		Payjp_Loader::correct_payment_method_before_save( $order );
	}

	/**
	 * Restores both payment_method and payment_method_title when Blocks Hydration
	 * has overwritten the gateway selection with a different one (no matching
	 * session selection — this is not a legitimate customer-driven change).
	 */
	#[Test]
	public function restores_payment_method_and_title_when_overwritten(): void {
		$this->expectNotToPerformAssertions();

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_changes' )->andReturn( array( 'payment_method' => 'payjp_card' ) );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_method' )->andReturn( 'paypay' );
		$order->shouldReceive( 'set_payment_method' )->once()->with( 'payjp_paypay' );
		$order->shouldReceive( 'set_payment_method_title' )->once()->with( 'PayPay' );
		$order->shouldNotReceive( 'delete_meta_data' );

		$paypay_gateway        = Mockery::mock( 'WC_Payment_Gateway' );
		$paypay_gateway->title = 'PayPay';
		$paypay_gateway->shouldReceive( 'get_title' )->andReturn( 'PayPay' );

		$payment_gateways = Mockery::mock();
		$payment_gateways->shouldReceive( 'payment_gateways' )->andReturn( array( 'payjp_paypay' => $paypay_gateway ) );

		$wc          = Mockery::mock();
		$wc->session = Mockery::mock();
		$wc->session->shouldReceive( 'get' )->with( 'chosen_payment_method' )->andReturn( null );
		$wc->shouldReceive( 'payment_gateways' )->andReturn( $payment_gateways );

		Functions\when( 'WC' )->justReturn( $wc );

		Payjp_Loader::correct_payment_method_before_save( $order );
	}

	/**
	 * Restores payment_method but leaves the title untouched when the gateway
	 * instance cannot be found in the registered gateways list.
	 */
	#[Test]
	public function restores_payment_method_only_when_gateway_instance_missing(): void {
		$this->expectNotToPerformAssertions();

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_changes' )->andReturn( array( 'payment_method' => 'payjp_card' ) );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_method' )->andReturn( 'paypay' );
		$order->shouldReceive( 'set_payment_method' )->once()->with( 'payjp_paypay' );
		$order->shouldNotReceive( 'set_payment_method_title' );
		$order->shouldNotReceive( 'delete_meta_data' );

		$payment_gateways = Mockery::mock();
		$payment_gateways->shouldReceive( 'payment_gateways' )->andReturn( array() );

		$wc          = Mockery::mock();
		$wc->session = Mockery::mock();
		$wc->session->shouldReceive( 'get' )->with( 'chosen_payment_method' )->andReturn( null );
		$wc->shouldReceive( 'payment_gateways' )->andReturn( $payment_gateways );

		Functions\when( 'WC' )->justReturn( $wc );

		Payjp_Loader::correct_payment_method_before_save( $order );
	}

	/**
	 * Does not revert, and clears the stale PAY.JP meta, when the customer
	 * legitimately switched away from PAY.JP to a different gateway (e.g.
	 * selected PayPay, clicked "Change payment method", then paid with Cash
	 * on Delivery on a reused pending order).
	 */
	#[Test]
	public function clears_stale_meta_and_skips_revert_when_customer_switched_gateway(): void {
		$this->expectNotToPerformAssertions();

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_changes' )->andReturn( array( 'payment_method' => 'cod' ) );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_payment_method' )->andReturn( 'paypay' );
		$order->shouldNotReceive( 'set_payment_method' );
		$order->shouldNotReceive( 'set_payment_method_title' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( '_payjp_payment_flow_id' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( '_payjp_client_secret' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( '_payjp_payment_method' );
		$order->shouldReceive( 'delete_meta_data' )->once()->with( '_payjp_capture_method' );

		$wc          = Mockery::mock();
		$wc->session = Mockery::mock();
		$wc->session->shouldReceive( 'get' )->with( 'chosen_payment_method' )->andReturn( 'cod' );

		Functions\when( 'WC' )->justReturn( $wc );

		Payjp_Loader::correct_payment_method_before_save( $order );
	}
}
