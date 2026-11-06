<?php
/**
 * Unit tests for the JPY-only currency checks (issue #38).
 *
 * PAY.JP charges in yen and the order total is sent unconverted, so a USD 100.00
 * order must never reach the API, where it would be charged as ¥100.
 *
 * Covers: Payjp_Settings::is_supported_currency(), gateway availability (store
 * currency and the order-pay page), process_payment(), receipt_page(), subscription
 * renewals, and the notice on the PAY.JP settings page.
 *
 * @package Payjp_For_WooCommerce
 */

namespace Payjp\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Payjp_Admin_Settings_Page;
use Payjp_API;
use Payjp_Settings;
use Payjp_Subscriptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WC_Gateway_Payjp;
use WC_Gateway_Payjp_Card;
use WC_Gateway_Payjp_Paypay;
use WC_Order;
use WC_Subscription;

/**
 * Tests for the JPY-only currency checks.
 */
class CurrencyCheckTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_html_e' )->echoArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'JPY' );
		Functions\when( 'get_option' )->justReturn(
			[
				'test_mode'       => true,
				'test_public_key' => 'pk_test_x',
				'test_secret_key' => 'sk_test_x',
				'enabled_methods' => [ 'card', 'paypay' ],
			]
		);

		Payjp_Settings::flush_cache();
		Payjp_API::$post_calls    = [];
		Payjp_API::$post_response = [];
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		Payjp_Settings::flush_cache();
		parent::tearDown();
	}

	/**
	 * Both gateways, for tests that must hold for each of them.
	 *
	 * @return array<string, array{class-string<WC_Gateway_Payjp>}>
	 */
	public static function gateway_provider(): array {
		return [
			'card'   => [ WC_Gateway_Payjp_Card::class ],
			'paypay' => [ WC_Gateway_Payjp_Paypay::class ],
		];
	}

	/**
	 * Build an enabled gateway. It keeps its real get_api(), so API calls land in
	 * Payjp_API::$post_calls rather than on a mock.
	 *
	 * @param class-string<WC_Gateway_Payjp> $gateway_class Gateway class.
	 */
	private function make_gateway( string $gateway_class ): WC_Gateway_Payjp {
		$gateway          = new $gateway_class();
		$gateway->enabled = 'yes';
		return $gateway;
	}

	/**
	 * Build an order mock that reports the given currency.
	 *
	 * @param string $currency Order currency.
	 * @return WC_Order&\Mockery\MockInterface
	 */
	private function make_order( string $currency ): WC_Order {
		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_currency' )->andReturn( $currency );
		return $order;
	}

	// ── Payjp_Settings::is_supported_currency() ───────────────────────────────

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function currency_provider(): array {
		return [
			'JPY'                   => [ 'JPY', true ],
			'lowercase jpy'         => [ 'jpy', true ],
			'USD'                   => [ 'USD', false ],
			'empty means unknown'   => [ '', false ],
			'JPY as part of a code' => [ 'JPYX', false ],
		];
	}

	#[Test]
	#[DataProvider( 'currency_provider' )]
	public function is_supported_currency_accepts_only_jpy( string $currency, bool $expected ): void {
		$this->assertSame( $expected, Payjp_Settings::is_supported_currency( $currency ) );
	}

	// ── is_available() ────────────────────────────────────────────────────────

	/**
	 * @param class-string<WC_Gateway_Payjp> $gateway_class Gateway class.
	 */
	#[Test]
	#[DataProvider( 'gateway_provider' )]
	public function gateway_is_available_for_a_jpy_store( string $gateway_class ): void {
		$this->assertTrue( $this->make_gateway( $gateway_class )->is_available() );
	}

	/**
	 * @param class-string<WC_Gateway_Payjp> $gateway_class Gateway class.
	 */
	#[Test]
	#[DataProvider( 'gateway_provider' )]
	public function gateway_is_hidden_for_a_usd_store( string $gateway_class ): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$this->assertFalse( $this->make_gateway( $gateway_class )->is_available() );
	}

	/**
	 * A multi-currency plugin can leave the store on JPY while the order is in USD.
	 *
	 * @param class-string<WC_Gateway_Payjp> $gateway_class Gateway class.
	 */
	#[Test]
	#[DataProvider( 'gateway_provider' )]
	public function order_pay_page_hides_gateway_for_a_usd_order_in_a_jpy_store( string $gateway_class ): void {
		Functions\when( 'get_query_var' )->justReturn( '42' );
		Functions\when( 'wc_get_order' )->justReturn( $this->make_order( 'USD' ) );

		$this->assertFalse( $this->make_gateway( $gateway_class )->is_available() );
	}

	/**
	 * The reverse: a JPY order stays payable after the store moves to another currency.
	 *
	 * @param class-string<WC_Gateway_Payjp> $gateway_class Gateway class.
	 */
	#[Test]
	#[DataProvider( 'gateway_provider' )]
	public function order_pay_page_shows_gateway_for_a_jpy_order_in_a_usd_store( string $gateway_class ): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'get_query_var' )->justReturn( '42' );
		Functions\when( 'wc_get_order' )->justReturn( $this->make_order( 'JPY' ) );

		$this->assertTrue( $this->make_gateway( $gateway_class )->is_available() );
	}

	#[Test]
	public function order_pay_page_falls_back_to_the_store_currency_when_the_order_is_missing(): void {
		Functions\when( 'get_query_var' )->justReturn( '42' );
		Functions\when( 'wc_get_order' )->justReturn( false );

		$this->assertTrue( $this->make_gateway( WC_Gateway_Payjp_Card::class )->is_available(), 'JPY store' );

		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$this->assertFalse( $this->make_gateway( WC_Gateway_Payjp_Card::class )->is_available(), 'USD store' );
	}

	// ── process_payment() ─────────────────────────────────────────────────────

	/**
	 * A Store API payment for an existing order passes is_available() on the store's
	 * currency, so process_payment() has to check the order's own.
	 *
	 * @param class-string<WC_Gateway_Payjp> $gateway_class Gateway class.
	 */
	#[Test]
	#[DataProvider( 'gateway_provider' )]
	public function process_payment_rejects_a_usd_order_without_calling_the_api( string $gateway_class ): void {
		$order = $this->make_order( 'USD' );
		$order->shouldNotReceive( 'set_payment_method' );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wc_add_notice' )->once()->with( Mockery::pattern( '/JPY/' ), 'error' );

		$result = $this->make_gateway( $gateway_class )->process_payment( 1 );

		$this->assertSame( [ 'result' => 'failure' ], $result );
		$this->assertSame( [], Payjp_API::$post_calls );
	}

	#[Test]
	public function process_payment_rejects_a_usd_order_paid_with_a_saved_card(): void {
		$_POST['wc-payjp_card-payment-token'] = '7';
		Functions\when( 'wc_get_order' )->justReturn( $this->make_order( 'USD' ) );
		Functions\expect( 'wc_add_notice' )->once();

		try {
			$result = $this->make_gateway( WC_Gateway_Payjp_Card::class )->process_payment( 1 );
		} finally {
			unset( $_POST['wc-payjp_card-payment-token'] );
		}

		$this->assertSame( [ 'result' => 'failure' ], $result );
		$this->assertSame( [], Payjp_API::$post_calls );
	}

	/**
	 * Regression guard: JPY orders keep creating a Payment Flow as before.
	 *
	 * @param class-string<WC_Gateway_Payjp> $gateway_class Gateway class.
	 */
	#[Test]
	#[DataProvider( 'gateway_provider' )]
	public function process_payment_creates_a_jpy_flow_for_a_jpy_order( string $gateway_class ): void {
		$order = $this->make_order( 'JPY' );
		$order->shouldReceive( 'set_payment_method' )->once();
		$order->shouldReceive( 'set_payment_method_title' )->once();
		$order->shouldReceive( 'get_total' )->andReturn( '1000' );
		$order->shouldReceive( 'update_meta_data' );
		$order->shouldReceive( 'save' );
		$order->shouldReceive( 'get_checkout_payment_url' )->andReturn( 'https://example.com/pay' );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\expect( 'wc_add_notice' )->never();
		Payjp_API::$post_response = [
			'id'            => 'pflw_1',
			'client_secret' => 'cs_1',
		];

		$result = $this->make_gateway( $gateway_class )->process_payment( 1 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertCount( 1, Payjp_API::$post_calls );
		$this->assertSame( '/payment_flows', Payjp_API::$post_calls[0]['endpoint'] );
		$this->assertSame( 'jpy', Payjp_API::$post_calls[0]['body']['currency'] );
		$this->assertSame( 1000, Payjp_API::$post_calls[0]['body']['amount'] );
	}

	// ── receipt_page() ────────────────────────────────────────────────────────

	/**
	 * Render receipt_page() for an order that already holds a Payment Flow.
	 *
	 * @param class-string<WC_Gateway_Payjp> $gateway_class Gateway class.
	 * @param string                         $currency      Order currency.
	 */
	private function render_receipt_page( string $gateway_class, string $currency ): string {
		$order = $this->make_order( $currency );
		$order->shouldReceive( 'get_meta' )->with( '_payjp_client_secret' )->andReturn( 'cs_1' );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'wc_get_checkout_url' )->justReturn( 'https://example.com/checkout' );

		ob_start();
		$this->make_gateway( $gateway_class )->receipt_page( 1 );
		return (string) ob_get_clean();
	}

	/**
	 * A flow created before the currency check existed must not leave a dead button:
	 * payment_scripts() does not enqueue the widget for this order.
	 *
	 * @param class-string<WC_Gateway_Payjp> $gateway_class Gateway class.
	 */
	#[Test]
	#[DataProvider( 'gateway_provider' )]
	public function receipt_page_does_not_offer_payment_for_a_usd_order( string $gateway_class ): void {
		$html = $this->render_receipt_page( $gateway_class, 'USD' );

		$this->assertStringContainsString( 'Payment session expired.', $html );
		$this->assertStringNotContainsString( 'pay-button', $html );
	}

	/**
	 * @param class-string<WC_Gateway_Payjp> $gateway_class Gateway class.
	 */
	#[Test]
	#[DataProvider( 'gateway_provider' )]
	public function receipt_page_renders_the_widget_for_a_jpy_order( string $gateway_class ): void {
		$html = $this->render_receipt_page( $gateway_class, 'JPY' );

		$this->assertStringContainsString( 'pay-button', $html );
		$this->assertStringNotContainsString( 'Payment session expired.', $html );
	}

	// ── Subscription renewals ─────────────────────────────────────────────────

	#[Test]
	public function renewal_in_usd_fails_with_a_note_and_makes_no_api_call(): void {
		$order = $this->make_order( 'USD' );
		$order->shouldReceive( 'update_status' )->once()->with( 'failed', Mockery::pattern( '/JPY.*USD/' ) );
		$order->shouldNotReceive( 'payment_complete' );

		Payjp_Subscriptions::handle_scheduled_payment( 100.0, $order );

		$this->assertSame( [], Payjp_API::$post_calls );
	}

	/**
	 * Nothing is charged, so a free renewal must not be failed for its currency.
	 */
	#[Test]
	public function zero_amount_renewal_in_usd_still_completes(): void {
		$order = $this->make_order( 'USD' );
		$order->shouldReceive( 'payment_complete' )->once();
		$order->shouldNotReceive( 'update_status' );

		Payjp_Subscriptions::handle_scheduled_payment( 0.0, $order );

		$this->assertSame( [], Payjp_API::$post_calls );
	}

	/**
	 * The currency comes from the renewal order. Renewals used to send the store
	 * currency, which would be 'usd' here.
	 */
	#[Test]
	public function jpy_renewal_is_charged_in_jpy_even_when_the_store_is_in_usd(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		Functions\when( 'get_user_meta' )->justReturn( 'cus_1' );

		$subscription = Mockery::mock( WC_Subscription::class );
		$subscription->shouldReceive( 'get_meta' )->with( '_payjp_payment_method_id' )->andReturn( 'pm_1' );
		Functions\when( 'wcs_get_subscriptions_for_renewal_order' )->justReturn( [ $subscription ] );

		$order = $this->make_order( 'JPY' );
		$order->shouldReceive( 'get_customer_id' )->andReturn( 5 );
		$order->shouldReceive( 'update_meta_data' );
		$order->shouldReceive( 'save' );
		$order->shouldReceive( 'payment_complete' )->once()->with( 'pflw_1' );
		$order->shouldNotReceive( 'update_status' );

		Payjp_API::$post_response = [
			'id'     => 'pflw_1',
			'status' => 'succeeded',
		];

		Payjp_Subscriptions::handle_scheduled_payment( 1000.0, $order );

		$this->assertCount( 1, Payjp_API::$post_calls );
		$this->assertSame( 'jpy', Payjp_API::$post_calls[0]['body']['currency'] );
		$this->assertSame( 1000, Payjp_API::$post_calls[0]['body']['amount'] );
	}

	// ── Settings page notice ──────────────────────────────────────────────────

	/**
	 * Render the PAY.JP settings page.
	 */
	private function render_settings_page(): string {
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'wp_kses' )->returnArg( 1 );

		$page = Mockery::mock( Payjp_Admin_Settings_Page::class )->makePartial();
		$page->shouldReceive( 'get_settings' )->andReturn( [] );

		ob_start();
		$page->output();
		return (string) ob_get_clean();
	}

	#[Test]
	public function settings_page_warns_when_the_store_currency_is_not_jpy(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$html = $this->render_settings_page();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'USD', $html );
		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=general', $html );
	}

	#[Test]
	public function settings_page_shows_no_notice_for_a_jpy_store(): void {
		$this->assertStringNotContainsString( 'notice-warning', $this->render_settings_page() );
	}
}
