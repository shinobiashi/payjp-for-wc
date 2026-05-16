<?php
/**
 * Minimal WP_REST_Request stub for unit tests.
 *
 * @package Payjp_For_WooCommerce
 */

/**
 * Stub for WP_REST_Request used in PHPUnit tests.
 * Provides only the methods called by Payjp_Webhook_Handler.
 */
class WP_REST_Request {

	/** @var array<string, string> */
	private array $headers;

	/** @var array<string, mixed> */
	private array $json_params;

	/**
	 * @param array<string, string> $headers     HTTP headers keyed by lowercase header name.
	 * @param array<string, mixed>  $json_params Parsed JSON body.
	 */
	public function __construct( array $headers = [], array $json_params = [] ) {
		$this->headers     = array_change_key_case( $headers, CASE_LOWER );
		$this->json_params = $json_params;
	}

	/**
	 * @param string $name Header name (case-insensitive).
	 * @return string|null
	 */
	public function get_header( string $name ): ?string {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_json_params(): array {
		return $this->json_params;
	}
}
