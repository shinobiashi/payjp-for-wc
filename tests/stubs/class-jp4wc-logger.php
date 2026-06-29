<?php
/**
 * Minimal stub for ArtisanWorkshop\WCLogger\v1_0_0\JP4WC_Logger used in unit tests.
 *
 * @package Payjp_For_WooCommerce
 */

namespace ArtisanWorkshop\WCLogger\v1_0_0;

/**
 * No-op logger stub: all methods accept the same arguments as the real class but do nothing.
 */
class JP4WC_Logger {

	/** @var array<string, self> */
	private static array $instances = array();

	/** @param callable(): bool $is_enabled_cb */
	private function __construct( string $handle, callable $is_enabled_cb ) {}

	/**
	 * @param callable(): bool $is_enabled_cb
	 */
	public static function get_instance( string $handle, callable $is_enabled_cb ): self {
		if ( ! isset( self::$instances[ $handle ] ) ) {
			self::$instances[ $handle ] = new self( $handle, $is_enabled_cb );
		}
		return self::$instances[ $handle ];
	}

	/** @param array<string, mixed> $context */
	public function log_event( string $event, int $order_id = 0, array $context = array() ): void {}

	/** @param array<string, mixed> $context */
	public function log_error( string $message, int $order_id = 0, ?\Throwable $e = null, array $context = array() ): void {}

	/** @param array<string, mixed> $payload */
	public function log_webhook( string $type, array $payload = array() ): void {}
}
