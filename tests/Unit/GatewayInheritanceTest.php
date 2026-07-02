<?php
/**
 * Regression tests for the PAY.JP gateway class hierarchy.
 *
 * Guards two invariants that a future edit could silently break:
 *   - WC_Gateway_Payjp must extend WC_Payment_Gateway, not WC_Payment_Gateway_CC.
 *     Neither PAY.JP gateway is a "card form" gateway; both render their own
 *     widget via payment_fields(), so inheriting WC_Payment_Gateway_CC would
 *     only risk a stray classic-checkout card form and mislabel PayPay as a
 *     card gateway for any instanceof WC_Payment_Gateway_CC check.
 *   - Both card and PayPay gateways must declare their own payment_fields().
 *     If that override is ever removed, PayPay would silently fall back to
 *     the parent's rendering instead of its embedded widget.
 *
 * @package Payjp_For_WooCommerce
 */

namespace Payjp\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WC_Gateway_Payjp_Card;
use WC_Gateway_Payjp_Paypay;

/**
 * Tests for the shared PAY.JP gateway class hierarchy.
 */
class GatewayInheritanceTest extends TestCase {

	/**
	 * WC_Gateway_Payjp must extend WC_Payment_Gateway directly, not
	 * WC_Payment_Gateway_CC (whose only contribution is the classic-checkout
	 * card form renderer, which this plugin never uses).
	 */
	#[Test]
	public function base_gateway_extends_plain_payment_gateway(): void {
		$parent = get_parent_class( WC_Gateway_Payjp_Card::class );
		while ( $parent && 'WC_Gateway_Payjp' !== $parent ) {
			$parent = get_parent_class( $parent );
		}

		self::assertSame( 'WC_Gateway_Payjp', $parent );
		self::assertSame( 'WC_Payment_Gateway', get_parent_class( $parent ) );
	}

	/**
	 * The card gateway must declare its own payment_fields() rather than
	 * relying on an inherited implementation.
	 */
	#[Test]
	public function card_gateway_declares_its_own_payment_fields(): void {
		$method = ( new ReflectionClass( WC_Gateway_Payjp_Card::class ) )->getMethod( 'payment_fields' );
		self::assertSame( WC_Gateway_Payjp_Card::class, $method->getDeclaringClass()->getName() );
	}

	/**
	 * The PayPay gateway must declare its own payment_fields() rather than
	 * relying on an inherited implementation.
	 */
	#[Test]
	public function paypay_gateway_declares_its_own_payment_fields(): void {
		$method = ( new ReflectionClass( WC_Gateway_Payjp_Paypay::class ) )->getMethod( 'payment_fields' );
		self::assertSame( WC_Gateway_Payjp_Paypay::class, $method->getDeclaringClass()->getName() );
	}
}
