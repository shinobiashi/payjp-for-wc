<?php
/**
 * Minimal WC_Settings_Page and WC_Admin_Settings stubs for unit tests.
 *
 * @package Payjp_For_WooCommerce
 */

/**
 * Stub for WC_Settings_Page, the parent of Payjp_Admin_Settings_Page.
 */
abstract class WC_Settings_Page {

	/** @var string */
	protected $id = '';

	/** @var string */
	protected $label = '';

	/**
	 * The real constructor registers the settings tab hooks; the stub has none.
	 */
	public function __construct() {}
}

/**
 * Stub for WC_Admin_Settings.
 */
class WC_Admin_Settings {

	/**
	 * @param array<int, array<string, mixed>> $options Field definitions.
	 */
	public static function output_fields( array $options ): void {}
}
