<?php
/**
 * Minimal WC_Order stub for unit tests.
 *
 * @package Payjp_For_WooCommerce
 */

/**
 * Abstract stub for WC_Order used by Mockery in PHPUnit tests.
 * Declares the methods called by Payjp_Webhook_Handler.
 */
abstract class WC_Order {

	/**
	 * @return bool
	 */
	abstract public function is_paid(): bool;

	/**
	 * @param string|string[] $status
	 * @return bool
	 */
	abstract public function has_status( string|array $status ): bool;

	/**
	 * @param string $key
	 * @return mixed
	 */
	abstract public function get_meta( string $key ): mixed;

	/**
	 * @param string $key
	 * @param mixed  $value
	 */
	abstract public function update_meta_data( string $key, mixed $value ): void;

	abstract public function save(): void;

	/**
	 * @param string $note
	 */
	abstract public function add_order_note( string $note ): void;

	/**
	 * @param string $status
	 * @param string $note
	 */
	abstract public function update_status( string $status, string $note = '' ): void;

	/**
	 * @param string $transaction_id
	 */
	abstract public function payment_complete( string $transaction_id = '' ): void;
}
