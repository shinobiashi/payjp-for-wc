<?php
/**
 * PAY.JP v2 API wrapper.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use ArtisanWorkshop\WCLogger\v1_0_0\JP4WC_Logger;

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
	 * Optional logger. When null, all logging is silently skipped.
	 *
	 * @var JP4WC_Logger|null
	 */
	private ?JP4WC_Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param string            $secret_key PAY.JP secret key (sk_test_xxx or sk_live_xxx).
	 * @param JP4WC_Logger|null $logger     Optional logger instance.
	 */
	public function __construct( string $secret_key, ?JP4WC_Logger $logger = null ) {
		$this->secret_key = $secret_key;
		$this->logger     = $logger;
	}

	/**
	 * POST to a PAY.JP v2 endpoint.
	 *
	 * @param string               $endpoint  Relative endpoint, e.g. '/payment_flows'.
	 * @param array<string, mixed> $body      Request body (will be JSON-encoded).
	 * @param int|null             $order_id  WooCommerce order ID for log correlation.
	 * @return array<string, mixed> Decoded response body.
	 * @throws RuntimeException On HTTP error or PAY.JP API error.
	 */
	public function post( string $endpoint, array $body, ?int $order_id = null ): array {
		$start = hrtime( true );
		$this->logger?->log_request( 'POST', $endpoint, $body, $order_id );

		$response = wp_remote_post(
			PAYJP_API_BASE . $endpoint,
			array(
				'headers' => $this->build_headers(),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		try {
			$data = $this->parse_response( $response );
		} catch ( RuntimeException $e ) {
			$this->logger?->log_error( $e->getMessage(), $order_id, $e );
			throw $e;
		}
		$this->logger?->log_response( $endpoint, $data, ( hrtime( true ) - $start ) / 1e6, $order_id );
		return $data;
	}

	/**
	 * GET from a PAY.JP v2 endpoint.
	 *
	 * @param string   $endpoint  Relative endpoint, e.g. '/payment_flows/pflw_xxx'.
	 * @param int|null $order_id  WooCommerce order ID for log correlation.
	 * @return array<string, mixed> Decoded response body.
	 * @throws RuntimeException On HTTP error or PAY.JP API error.
	 */
	public function get( string $endpoint, ?int $order_id = null ): array {
		$start = hrtime( true );
		$this->logger?->log_request( 'GET', $endpoint, array(), $order_id );

		$response = wp_remote_get(
			PAYJP_API_BASE . $endpoint,
			array(
				'headers' => $this->build_headers(),
				'timeout' => 30,
			)
		);

		try {
			$data = $this->parse_response( $response );
		} catch ( RuntimeException $e ) {
			$this->logger?->log_error( $e->getMessage(), $order_id, $e );
			throw $e;
		}
		$this->logger?->log_response( $endpoint, $data, ( hrtime( true ) - $start ) / 1e6, $order_id );
		return $data;
	}

	/**
	 * Build the Authorization / Content-Type headers.
	 *
	 * @return array<string, string>
	 */
	private function build_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->secret_key,
			'Content-Type'  => 'application/json',
		);
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

		// PAY.JP v2 error (RFC 9457 format): {"status":4xx,"code":"...","detail":"...","errors":[...]}.
		// The top-level "status" key is an integer >= 400; no "error" wrapper key.
		if ( isset( $body['status'] ) && is_int( $body['status'] ) && $body['status'] >= 400 ) {
			$message = isset( $body['detail'] ) && is_string( $body['detail'] )
				? wp_strip_all_tags( $body['detail'] )
				: __( 'An unknown PAY.JP error occurred.', 'payjp-for-wc' );

			// Append individual field-level validation errors when present.
			if ( ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
				$field_errors = array();
				foreach ( $body['errors'] as $e ) {
					if ( isset( $e['field'], $e['message'] ) && is_string( $e['field'] ) && is_string( $e['message'] ) ) {
						$field_errors[] = wp_strip_all_tags( $e['field'] ) . ': ' . wp_strip_all_tags( $e['message'] );
					}
				}
				if ( $field_errors ) {
					$message .= ' (' . implode( ', ', $field_errors ) . ')';
				}
			}

			throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// PAY.JP v1 error legacy format: top-level "error" object with a "message" field.
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

		return is_array( $body ) ? $body : array();
	}
}
