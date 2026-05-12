<?php
/**
 * PAY.JP v2 API wrapper.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'Payjp_API' ) ) {
	return;
}

/**
 * Thin HTTP wrapper around the PAY.JP v2 REST API.
 * Uses wp_remote_post() / wp_remote_get() exclusively (no direct curl).
 * Throws RuntimeException on any HTTP or API-level error.
 */
class Payjp_API {

	/**
	 * PAY.JP secret key (sk_test_xxx or sk_live_xxx).
	 *
	 * @var string
	 */
	private string $secret_key;

	/**
	 * Constructor.
	 *
	 * @param string $secret_key PAY.JP secret key (sk_test_xxx or sk_live_xxx).
	 */
	public function __construct( string $secret_key ) {
		$this->secret_key = $secret_key;
	}

	/**
	 * POST to a PAY.JP v2 endpoint.
	 *
	 * @param string               $endpoint Relative endpoint, e.g. '/payment_flows'.
	 * @param array<string, mixed> $body     Request body (will be JSON-encoded).
	 * @return array<string, mixed> Decoded response body.
	 * @throws RuntimeException On HTTP error or PAY.JP API error.
	 */
	public function post( string $endpoint, array $body ): array {
		$response = wp_remote_post(
			PAYJP_API_BASE . $endpoint,
			[
				'headers' => $this->build_headers(),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			]
		);

		return $this->parse_response( $response );
	}

	/**
	 * GET from a PAY.JP v2 endpoint.
	 *
	 * @param string $endpoint Relative endpoint, e.g. '/payment_flows/pflw_xxx'.
	 * @return array<string, mixed> Decoded response body.
	 * @throws RuntimeException On HTTP error or PAY.JP API error.
	 */
	public function get( string $endpoint ): array {
		$response = wp_remote_get(
			PAYJP_API_BASE . $endpoint,
			[
				'headers' => $this->build_headers(),
				'timeout' => 30,
			]
		);

		return $this->parse_response( $response );
	}

	/**
	 * Build the Authorization / Content-Type headers.
	 *
	 * @return array<string, string>
	 */
	private function build_headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->secret_key,
			'Content-Type'  => 'application/json',
		];
	}

	/**
	 * Parse a wp_remote_* response into an array.
	 *
	 * @param array<string, mixed>|\WP_Error $response WordPress HTTP response.
	 * @return array<string, mixed>
	 * @throws RuntimeException On HTTP transport error or PAY.JP API error body.
	 */
	private function parse_response( array|\WP_Error $response ): array {
		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_Error messages are internal.
			throw new RuntimeException( $response->get_error_message() );
		}

		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		// Non-JSON body (e.g. HTML error page from a gateway or proxy).
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$error = __( 'PAY.JP returned an unexpected non-JSON response.', 'payjp-for-wc' );
			throw new RuntimeException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// PAY.JP error object — present on 4xx and some 5xx responses.
		if ( isset( $body['error'] ) ) {
			$error = isset( $body['error']['message'] )
				? wp_strip_all_tags( (string) $body['error']['message'] )
				: __( 'An unknown PAY.JP error occurred.', 'payjp-for-wc' );
			throw new RuntimeException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// Catch non-2xx responses that did not include a PAY.JP error body.
		$http_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $http_code < 200 || $http_code >= 300 ) {
			$error = sprintf(
				/* translators: %d: HTTP status code */
				__( 'PAY.JP API returned HTTP %d.', 'payjp-for-wc' ),
				$http_code
			);
			throw new RuntimeException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return is_array( $body ) ? $body : [];
	}
}
