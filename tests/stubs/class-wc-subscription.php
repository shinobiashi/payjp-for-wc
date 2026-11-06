<?php
/**
 * Minimal WC_Subscription stub for unit tests.
 *
 * @package Payjp_For_WooCommerce
 */

/**
 * Abstract stub for WC_Subscription used by Mockery in PHPUnit tests.
 * Declares the methods called by Payjp_Subscriptions.
 */
abstract class WC_Subscription {

	/**
	 * @param string $key
	 * @return mixed
	 */
	abstract public function get_meta( string $key ): mixed;

	/**
	 * @return int
	 */
	abstract public function get_customer_id(): int;
}
