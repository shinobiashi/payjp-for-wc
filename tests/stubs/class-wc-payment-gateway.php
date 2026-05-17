<?php
/**
 * Minimal WC_Payment_Gateway stub for unit tests.
 *
 * @package Payjp_For_WooCommerce
 */

/**
 * Stub for WC_Payment_Gateway used in PHPUnit tests.
 * Provides only the properties and methods called by WC_Gateway_Payjp and subclasses.
 */
abstract class WC_Payment_Gateway {

	/** @var string */
	public string $id = '';

	/** @var string */
	public string $title = '';

	/** @var string */
	public string $description = '';

	/** @var string */
	public string $enabled = 'no';

	/** @var string */
	public string $method_title = '';

	/** @var string */
	public string $method_description = '';

	/** @var bool */
	public bool $has_fields = false;

	/** @var string[] */
	public array $supports = [];

	/** @var array<string, mixed> */
	public array $form_fields = [];

	/** @var array<string, mixed> */
	protected array $settings = [];

	/**
	 * Initialise form fields (no-op in stub).
	 */
	public function init_form_fields(): void {}

	/**
	 * Initialise settings from the DB option (stub returns empty array).
	 */
	public function init_settings(): void {
		$this->settings = [];
	}

	/**
	 * @param string $key         Setting key.
	 * @param mixed  $empty_value Default value when key not found.
	 * @return mixed
	 */
	public function get_option( string $key, $empty_value = null ): mixed {
		return $this->settings[ $key ] ?? ( $empty_value ?? '' );
	}

	/**
	 * @return bool
	 */
	public function process_admin_options(): bool {
		return true;
	}

	/**
	 * @return bool
	 */
	public function is_available(): bool {
		return 'yes' === $this->enabled;
	}
}
