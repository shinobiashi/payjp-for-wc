<?php
/**
 * Minimal WP_Error stub for unit tests.
 *
 * @package Payjp_For_WooCommerce
 */

/**
 * Stub for WP_Error used in PHPUnit tests.
 */
class WP_Error {

	/** @var string */
	private string $code;

	/** @var string */
	private string $message;

	/**
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->code;
	}

	/**
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->message;
	}
}
