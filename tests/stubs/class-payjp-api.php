<?php
/**
 * Minimal Payjp_API stub for unit tests.
 *
 * @package Payjp_For_WooCommerce
 */

use ArtisanWorkshop\WCLogger\v1_0_0\JP4WC_Logger;

/**
 * Stub for Payjp_API used in PHPUnit tests.
 * The real class is replaced by Mockery in tests that exercise API calls.
 */
class Payjp_API {

	/**
	 * @param string            $secret_key PAY.JP secret key.
	 * @param JP4WC_Logger|null $logger     Optional logger instance.
	 */
	public function __construct( string $secret_key, ?JP4WC_Logger $logger = null ) {}

	/**
	 * @param string               $endpoint  Relative endpoint.
	 * @param array<string, mixed> $body      Request body.
	 * @param int|null             $order_id  WooCommerce order ID for log correlation.
	 * @return array<string, mixed>
	 * @throws RuntimeException On HTTP or API error.
	 */
	public function post( string $endpoint, array $body, ?int $order_id = null ): array {
		return array();
	}

	/**
	 * @param string   $endpoint  Relative endpoint.
	 * @param int|null $order_id  WooCommerce order ID for log correlation.
	 * @return array<string, mixed>
	 * @throws RuntimeException On HTTP or API error.
	 */
	public function get( string $endpoint, ?int $order_id = null ): array {
		return array();
	}
}
