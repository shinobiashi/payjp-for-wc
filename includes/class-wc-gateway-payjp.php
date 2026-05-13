<?php
/**
 * Abstract base class shared by all PAY.JP payment gateways.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WC_Gateway_Payjp' ) ) {
	return;
}

/**
 * Abstract PAY.JP gateway.
 * Provides shared constructor setup, availability check, and API helper.
 * Concrete subclasses set $this->id, $this->payjp_method, and call $this->setup().
 */
abstract class WC_Gateway_Payjp extends WC_Payment_Gateway {

	/**
	 * PAY.JP payment method slug: 'card' or 'paypay'.
	 * Subclasses must set this before calling setup().
	 *
	 * @var string
	 */
	protected string $payjp_method = '';

	/**
	 * Initialize shared gateway properties.
	 * Must be called from each subclass constructor after setting id and payjp_method.
	 */
	protected function setup(): void {
		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		// Wrap in a void closure: process_admin_options() returns bool but
		// action callbacks must not return values (PHPStan rule).
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			function () {
				$this->process_admin_options();
			}
		);
	}

	/**
	 * Common form fields for all PAY.JP gateways.
	 * Subclasses may override and call parent to merge additional fields.
	 */
	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'     => [
				'title'   => __( 'Enable/Disable', 'payjp-for-wc' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this payment method', 'payjp-for-wc' ),
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( 'Title', 'payjp-for-wc' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to the customer at checkout.', 'payjp-for-wc' ),
				'default'     => $this->method_title,
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'payjp-for-wc' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown to the customer at checkout.', 'payjp-for-wc' ),
				'default'     => '',
				'desc_tip'    => true,
			],
		];
	}

	/**
	 * Available when the parent check passes, the method is enabled in shared settings,
	 * and API keys are configured.
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( ! Payjp_Settings::is_method_enabled( $this->payjp_method ) ) {
			return false;
		}

		if ( ! Payjp_Settings::get_public_key() || ! Payjp_Settings::get_secret_key() ) {
			return false;
		}

		return true;
	}

	/**
	 * Get a Payjp_API instance initialized with the currently active secret key.
	 */
	protected function get_api(): Payjp_API {
		return new Payjp_API( Payjp_Settings::get_secret_key() );
	}
}
