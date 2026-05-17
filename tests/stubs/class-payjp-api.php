<?php
/**
 * Minimal Payjp_API stub for unit tests.
 *
 * @package Payjp_For_WooCommerce
 */

/**
 * Stub for Payjp_API used in PHPUnit tests.
 * The real class is replaced by Mockery in tests that exercise API calls.
 */
class Payjp_API {

	/**
	 * @param string $secret_key PAY.JP secret key.
	 */
	public function __construct( string $secret_key ) {}

	/**
	 * @param string               $endpoint Relative endpoint.
	 * @param array<string, mixed> $body     Request body.
	 * @return array<string, mixed>
	 * @throws RuntimeException On HTTP or API error.
	 */
	public function post( string $endpoint, array $body ): array {
		return [];
	}

	/**
	 * @param string $endpoint Relative endpoint.
	 * @return array<string, mixed>
	 * @throws RuntimeException On HTTP or API error.
	 */
	public function get( string $endpoint ): array {
		return [];
	}
}
