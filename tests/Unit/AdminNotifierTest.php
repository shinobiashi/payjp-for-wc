<?php
/**
 * Unit tests for Payjp_Admin_Notifier.
 *
 * @package Payjp_For_WooCommerce
 */

namespace Payjp\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Payjp_Admin_Notifier;
use WC_Order;

/**
 * Tests for Payjp_Admin_Notifier.
 */
class AdminNotifierTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		\Payjp_Settings::flush_cache();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Store' );
		Functions\when( 'wp_specialchars_decode' )->returnArg( 1 );
		Functions\when( 'is_email' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	#[Test]
	public function sends_to_configured_alert_email(): void {
		Functions\when( 'get_option' )->justReturn( [ 'alert_email' => 'ops@example.com' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.com/order/1' );

		Functions\expect( 'wp_mail' )
			->once()
			->with( 'ops@example.com', Mockery::type( 'string' ), Mockery::type( 'string' ) )
			->andReturn( true );

		$result = Payjp_Admin_Notifier::send_alert( $order, 'Subject', [ 'line' ] );

		$this->assertTrue( $result );
	}

	#[Test]
	public function falls_back_to_admin_email_when_alert_email_is_blank(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, mixed $default = false ): mixed {
				if ( \Payjp_Settings::OPTION_KEY === $key ) {
					return [ 'alert_email' => '' ];
				}
				if ( 'admin_email' === $key ) {
					return 'admin@example.com';
				}
				return $default;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.com/order/1' );

		Functions\expect( 'wp_mail' )
			->once()
			->with( 'admin@example.com', Mockery::type( 'string' ), Mockery::type( 'string' ) )
			->andReturn( true );

		$result = Payjp_Admin_Notifier::send_alert( $order, 'Subject', [ 'line' ] );

		$this->assertTrue( $result );
	}

	#[Test]
	public function recipient_filter_overrides_configured_address(): void {
		Functions\when( 'get_option' )->justReturn( [ 'alert_email' => 'ops@example.com' ] );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value, mixed ...$args ): mixed {
				return 'payjp_for_wc_alert_email_recipient' === $tag ? 'override@example.com' : $value;
			}
		);

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.com/order/1' );

		Functions\expect( 'wp_mail' )
			->once()
			->with( 'override@example.com', Mockery::type( 'string' ), Mockery::type( 'string' ) )
			->andReturn( true );

		$result = Payjp_Admin_Notifier::send_alert( $order, 'Subject', [ 'line' ] );

		$this->assertTrue( $result );
	}

	#[Test]
	public function enabled_filter_false_skips_sending(): void {
		Functions\when( 'get_option' )->justReturn( [ 'alert_email' => 'ops@example.com' ] );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, mixed $value, mixed ...$args ): mixed {
				return 'payjp_for_wc_alert_email_enabled' === $tag ? false : $value;
			}
		);

		$order = Mockery::mock( WC_Order::class );
		$order->shouldNotReceive( 'get_edit_order_url' );

		Functions\expect( 'wp_mail' )->never();

		$result = Payjp_Admin_Notifier::send_alert( $order, 'Subject', [ 'line' ] );

		$this->assertFalse( $result );
	}

	#[Test]
	public function body_joins_lines_and_appends_edit_order_url(): void {
		Functions\when( 'get_option' )->justReturn( [ 'alert_email' => 'ops@example.com' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$order = Mockery::mock( WC_Order::class );
		$order->shouldReceive( 'get_edit_order_url' )->andReturn( 'https://example.com/order/1' );

		Functions\expect( 'wp_mail' )
			->once()
			->andReturnUsing(
				static function ( string $to, string $subject, string $body ): bool {
					$expected = "line one\nline two\n\nhttps://example.com/order/1";
					if ( $expected !== $body ) {
						throw new \RuntimeException( 'Unexpected body: ' . $body );
					}
					return true;
				}
			);

		$result = Payjp_Admin_Notifier::send_alert( $order, 'Subject', [ 'line one', 'line two' ] );

		$this->assertTrue( $result );
	}
}
