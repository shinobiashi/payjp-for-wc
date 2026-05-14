<?php
/**
 * Shared settings manager for all PAY.JP payment gateways.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'Payjp_Settings' ) ) {
	return;
}

/**
 * Static accessors for shared PAY.JP settings stored in the `payjp_settings`
 * option. All gateways read from this single source of truth.
 *
 * Settings structure:
 * [
 *   'test_mode'       => bool,
 *   'test_public_key' => string,
 *   'test_secret_key' => string,
 *   'live_public_key' => string,
 *   'live_secret_key' => string,
 *   'webhook_secret'  => string,
 *   'enabled_methods' => string[], // e.g. ['card', 'paypay']
 * ]
 */
class Payjp_Settings {

	/**
	 * WordPress option key for all shared PAY.JP settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'payjp_settings';

	/**
	 * Get a single setting value.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value returned when the key is absent.
	 * @return mixed
	 */
	public static function get( string $key, mixed $fallback = '' ): mixed {
		return self::get_all()[ $key ] ?? $fallback;
	}

	/**
	 * Get all settings as an associative array.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_all(): array {
		$settings = get_option( self::OPTION_KEY, [] );
		return is_array( $settings ) ? $settings : [];
	}

	/**
	 * Whether test mode is currently active.
	 */
	public static function is_test_mode(): bool {
		return (bool) self::get( 'test_mode', true );
	}

	/**
	 * Get the active public key (test or live), selected by mode.
	 */
	public static function get_public_key(): string {
		return self::is_test_mode()
			? (string) self::get( 'test_public_key' )
			: (string) self::get( 'live_public_key' );
	}

	/**
	 * Get the active secret key (test or live), selected by mode.
	 */
	public static function get_secret_key(): string {
		return self::is_test_mode()
			? (string) self::get( 'test_secret_key' )
			: (string) self::get( 'live_secret_key' );
	}

	/**
	 * Get the list of enabled payment method slugs.
	 *
	 * @return string[] e.g. ['card', 'paypay']
	 */
	public static function get_enabled_methods(): array {
		$settings = self::get_all();

		if ( array_key_exists( 'enabled_methods', $settings ) ) {
			return is_array( $settings['enabled_methods'] ) ? $settings['enabled_methods'] : [];
		}

		// When enabled_methods has never been saved (fresh install or upgrade before
		// the first unified-settings save), derive from the individual gateway options
		// so previously enabled gateways remain available at checkout.
		$derived         = [];
		$gateway_methods = [
			'woocommerce_payjp_card_settings'   => 'card',
			'woocommerce_payjp_paypay_settings' => 'paypay',
		];
		foreach ( $gateway_methods as $option_key => $method ) {
			$gateway_settings = get_option( $option_key, [] );
			if ( is_array( $gateway_settings ) && 'yes' === ( $gateway_settings['enabled'] ?? 'no' ) ) {
				$derived[] = $method;
			}
		}
		return $derived;
	}

	/**
	 * Whether a given payment method slug is enabled.
	 *
	 * @param string $method 'card' or 'paypay'.
	 */
	public static function is_method_enabled( string $method ): bool {
		return in_array( $method, self::get_enabled_methods(), true );
	}

	/**
	 * Get the Webhook verification token.
	 */
	public static function get_webhook_secret(): string {
		return (string) self::get( 'webhook_secret' );
	}
}
