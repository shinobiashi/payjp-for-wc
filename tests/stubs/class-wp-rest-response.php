<?php
/**
 * Minimal WP_REST_Response stub for unit tests.
 *
 * @package Payjp_For_WooCommerce
 */

/**
 * Stub for WP_REST_Response used in PHPUnit tests.
 */
class WP_REST_Response {

	/** @var mixed */
	private mixed $data;

	/** @var int */
	private int $status;

	/**
	 * @param mixed $data   Response data.
	 * @param int   $status HTTP status code (default 200).
	 */
	public function __construct( mixed $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	/** @return int */
	public function get_status(): int {
		return $this->status;
	}

	/** @return mixed */
	public function get_data(): mixed {
		return $this->data;
	}
}
