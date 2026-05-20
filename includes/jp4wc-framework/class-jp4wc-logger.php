<?php
/**
 * JP4WC Logger — structured WooCommerce logger for payment plugins.
 *
 * Framework Name:    JP4WC Logger
 * Framework Version: 1.0.0
 * Author:            Artisan Workshop
 * Author URI:        https://wc.artws.info/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Drop this file into your plugin's includes/jp4wc-framework/ directory.
 * Each plugin that bundles a copy uses the same versioned namespace, so
 * whichever plugin loads first wins — all others silently skip class definition.
 *
 * Basic usage:
 *
 *   use ArtisanWorkshop\WCLogger\v1_0_0\JP4WC_Logger;
 *
 *   $logger = JP4WC_Logger::get_instance(
 *       'my-plugin-slug',
 *       static fn() => (bool) get_option( 'my_plugin_debug_log', false )
 *   );
 *
 *   $start = hrtime( true );
 *   $logger->log_request( 'POST', '/payments', $body, $order_id );
 *   $data  = $api->post( '/payments', $body );
 *   $logger->log_response( '/payments', $data, ( hrtime( true ) - $start ) / 1e6, $order_id );
 *
 *   $logger->log_event( 'authorized', $order_id, [ 'flow_id' => $data['id'] ] );
 *   $logger->log_webhook( 'payment.succeeded', $payload, $order_id );
 *   $logger->log_error( 'Something went wrong', $order_id, $exception );
 *
 * Logs appear in WooCommerce > Status > Logs, filtered by your plugin slug.
 *
 * @package JP4WC_Framework
 */

namespace ArtisanWorkshop\WCLogger\v1_0_0;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( __NAMESPACE__ . '\\JP4WC_Logger' ) ) {
	return;
}

/**
 * Structured payment gateway logger built on WooCommerce's WC_Logger.
 *
 * One instance per plugin handle — acquire via get_instance().
 * log_error() always writes regardless of the enabled callback.
 * All other methods are silently skipped when the callback returns false.
 *
 * @package JP4WC_Framework
 */
class JP4WC_Logger {

	/**
	 * Logger version — bump when the public API changes.
	 *
	 * @var string
	 */
	const VERSION = '1.0.0';

	/**
	 * Key fragments that trigger value masking in request bodies.
	 * Matching is case-insensitive substring search.
	 *
	 * @var string[]
	 */
	private const SENSITIVE_KEYS = array(
		'secret_key',
		'api_key',
		'password',
		'token',
		'card_number',
		'cvv',
		'cvc',
		'authorization',
	);

	/**
	 * Per-handle singleton cache.
	 *
	 * @var array<string, self>
	 */
	private static array $instances = array();

	/**
	 * Lazily-initialised WooCommerce logger.
	 *
	 * @var \WC_Logger_Interface|null
	 */
	private ?\WC_Logger_Interface $wc_logger = null;

	/**
	 * Log source name used as the WC log file handle (typically the plugin slug).
	 *
	 * @var string
	 */
	private string $handle;

	/**
	 * Evaluated on every log call so runtime setting changes take effect immediately.
	 *
	 * @var callable(): bool
	 */
	private $is_enabled_cb;

	/**
	 * Private constructor — use get_instance().
	 *
	 * @param string           $handle        Plugin slug, e.g. 'payjp-for-wc'.
	 * @param callable(): bool $is_enabled_cb Returns true when logging is active.
	 */
	private function __construct( string $handle, callable $is_enabled_cb ) {
		$this->handle        = $handle;
		$this->is_enabled_cb = $is_enabled_cb;
	}

	/**
	 * Get (or create) the logger instance for a given handle.
	 *
	 * The first call for a handle registers the enabled callback; subsequent calls
	 * return the cached instance unchanged. To swap the callback, call reset_instance()
	 * before get_instance().
	 *
	 * @param string           $handle        Plugin slug, e.g. 'payjp-for-wc'.
	 * @param callable(): bool $is_enabled_cb Callback that returns true when logging is on.
	 * @return self
	 */
	public static function get_instance( string $handle, callable $is_enabled_cb ): self {
		if ( ! isset( self::$instances[ $handle ] ) ) {
			self::$instances[ $handle ] = new self( $handle, $is_enabled_cb );
		}
		return self::$instances[ $handle ];
	}

	/**
	 * Remove the cached instance for a handle.
	 * Useful in unit tests or when the enabled callback must be replaced.
	 *
	 * @param string $handle Plugin slug.
	 */
	public static function reset_instance( string $handle ): void {
		unset( self::$instances[ $handle ] );
	}

	/**
	 * Whether logging is currently active.
	 */
	public function is_enabled(): bool {
		return (bool) ( $this->is_enabled_cb )();
	}

	/**
	 * Log an outgoing API request.
	 *
	 * Values whose keys contain a SENSITIVE_KEYS fragment are automatically
	 * replaced with '***' before writing to the log.
	 *
	 * @param string               $method    HTTP method in upper-case: 'GET', 'POST', 'DELETE', etc.
	 * @param string               $endpoint  Relative endpoint path, e.g. '/payment_flows'.
	 * @param array<string, mixed> $body      Request body (JSON-pretty-printed in the log).
	 * @param int|null             $order_id  WooCommerce order ID for correlation.
	 */
	public function log_request( string $method, string $endpoint, array $body = array(), ?int $order_id = null ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$label   = '[REQUEST] ' . strtoupper( $method ) . ' ' . $endpoint . $this->order_tag( $order_id );
		$message = $label . "\n" . $this->encode( $this->mask_sensitive( $body ) );
		$this->logger()->info( $message, $this->context() );
	}

	/**
	 * Log an API response received from the payment provider.
	 *
	 * @param string               $endpoint    Endpoint that was called.
	 * @param array<string, mixed> $data        Decoded response body.
	 * @param float                $elapsed_ms  Round-trip time in milliseconds (use hrtime for precision).
	 * @param int|null             $order_id    WooCommerce order ID for correlation.
	 */
	public function log_response( string $endpoint, array $data, float $elapsed_ms, ?int $order_id = null ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$label   = '[RESPONSE] ' . $endpoint . $this->order_tag( $order_id ) . ' | ' . number_format( $elapsed_ms, 1 ) . 'ms';
		$message = $label . "\n" . $this->encode( $data );
		$this->logger()->info( $message, $this->context() );
	}

	/**
	 * Log a payment lifecycle event.
	 *
	 * Suggested event names: 'authorized', 'captured', 'refunded', 'failed', 'cancelled'.
	 *
	 * @param string               $event    Short event name.
	 * @param int|null             $order_id WooCommerce order ID.
	 * @param array<string, mixed> $context  Additional key=value pairs appended to the log line.
	 */
	public function log_event( string $event, ?int $order_id = null, array $context = array() ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$line = '[EVENT] ' . $event . $this->order_tag( $order_id ) . $this->format_context( $context );
		$this->logger()->info( $line, $this->context() );
	}

	/**
	 * Log an incoming webhook notification.
	 *
	 * @param string               $event_type Webhook event type, e.g. 'payment_flow.succeeded'.
	 * @param array<string, mixed> $payload    Full webhook payload (JSON-pretty-printed).
	 * @param int|null             $order_id   WooCommerce order ID if already resolved.
	 */
	public function log_webhook( string $event_type, array $payload = array(), ?int $order_id = null ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$label   = '[WEBHOOK] ' . $event_type . $this->order_tag( $order_id );
		$message = $label . "\n" . $this->encode( $payload );
		$this->logger()->info( $message, $this->context() );
	}

	/**
	 * Log an error. Always written regardless of the enabled callback.
	 *
	 * @param string          $message  Human-readable description of what went wrong.
	 * @param int|null        $order_id WooCommerce order ID.
	 * @param \Throwable|null $e        Exception/error to append (message + stack trace).
	 */
	public function log_error( string $message, ?int $order_id = null, ?\Throwable $e = null ): void {
		$line = '[ERROR] ' . $message . $this->order_tag( $order_id );
		if ( null !== $e ) {
			$line .= "\n" . get_class( $e ) . ': ' . $e->getMessage()
				. "\n" . $e->getTraceAsString();
		}
		$this->logger()->error( $line, $this->context() );
	}

	// ── Internals ─────────────────────────────────────────────────────────────

	/**
	 * Return (and lazily initialise) the WC_Logger_Interface instance.
	 *
	 * @return \WC_Logger_Interface
	 */
	private function logger(): \WC_Logger_Interface {
		if ( null === $this->wc_logger ) {
			$this->wc_logger = wc_get_logger();
		}
		return $this->wc_logger;
	}

	/**
	 * WC logger context array — routes output to the plugin's own log file.
	 *
	 * @return array{source: string}
	 */
	private function context(): array {
		return array( 'source' => $this->handle );
	}

	/**
	 * Format an order ID as a log-line tag.
	 *
	 * @param int|null $order_id WooCommerce order ID.
	 * @return string e.g. ' | order=123', or '' when null.
	 */
	private function order_tag( ?int $order_id ): string {
		return null !== $order_id ? ' | order=' . $order_id : '';
	}

	/**
	 * Serialise a context array as ' | key=value' segments.
	 *
	 * @param array<string, mixed> $context Key-value pairs.
	 * @return string e.g. ' | flow_id=pflw_xxx | amount=3000', or ''.
	 */
	private function format_context( array $context ): string {
		if ( empty( $context ) ) {
			return '';
		}
		$parts = array();
		foreach ( $context as $k => $v ) {
			$parts[] = $k . '=' . $v;
		}
		return ' | ' . implode( ' | ', $parts );
	}

	/**
	 * Recursively replace values whose keys contain a sensitive keyword with '***'.
	 *
	 * @param array<string, mixed> $data Input data.
	 * @return array<string, mixed>
	 */
	private function mask_sensitive( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->mask_sensitive( $value );
			} elseif ( is_string( $key ) ) { // integer keys (numeric arrays) are not field names; skip.
				foreach ( self::SENSITIVE_KEYS as $fragment ) {
					if ( false !== stripos( $key, $fragment ) ) {
						$data[ $key ] = '***';
						break;
					}
				}
			}
		}
		return $data;
	}

	/**
	 * JSON-encode data for log output (pretty-printed, Unicode preserved).
	 *
	 * @param array<string, mixed> $data Data to encode.
	 * @return string Pretty-printed JSON, or '(encode error)' on failure.
	 */
	private function encode( array $data ): string {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false !== $json ? $json : '(encode error)';
	}
}
