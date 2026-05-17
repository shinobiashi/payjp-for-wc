<?php
/**
 * Abstract base class for PAY.JP Block Checkout payment method integrations.
 *
 * @package Payjp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

// Guard: this file must only be loaded after WooCommerce Blocks has defined
// AbstractPaymentMethodType (i.e. inside the woocommerce_blocks_loaded callback).
// If require_once'd before Blocks loads and this guard fires, PHP will not
// re-execute the file later, so Payjp_Blocks_Integration would never be declared.
if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
	return;
}

if ( class_exists( 'Payjp_Blocks_Integration' ) ) {
	return;
}

/**
 * Abstract base for PAY.JP Block Checkout integrations.
 *
 * Concrete subclasses (Payjp_Blocks_Integration_Card, Payjp_Blocks_Integration_Paypay)
 * set $name and implement get_name(). All shared logic lives here.
 *
 * Payment flow: Block Checkout → process_payment() creates a Payment Flow and
 * redirects to the order-pay page → payments.js widget on order-pay page →
 * PAY.JP redirect → handle_return() completes the order.
 * This avoids calling confirmPayment() before the WC order exists, which would
 * make it impossible to link the payment to the order on the return URL.
 */
abstract class Payjp_Blocks_Integration extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	/**
	 * WooCommerce gateway instance, resolved in initialize().
	 *
	 * @var WC_Payment_Gateway|null
	 */
	protected ?WC_Payment_Gateway $gateway = null;

	/**
	 * Resolve the corresponding WooCommerce gateway from the payment gateway registry.
	 */
	public function initialize(): void {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gw       = $gateways[ $this->name ] ?? null;
		if ( $gw instanceof WC_Payment_Gateway ) {
			$this->gateway = $gw;
		}
	}

	/**
	 * Whether this payment method is currently active and available for checkout.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return null !== $this->gateway && $this->gateway->is_available();
	}

	/**
	 * Register and return the JS script handle for the Block Checkout payment component.
	 *
	 * Both card and PayPay share the same compiled bundle (build/blocks/checkout.js),
	 * which calls registerPaymentMethod() for each method. The script is registered
	 * only once even though two integration instances call this method.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		$asset_path = PAYJP_FOR_WC_DIR . 'build/blocks/checkout.asset.php';
		$asset      = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array(),
				'version'      => PAYJP_FOR_WC_VERSION,
			);
		$deps       = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$version    = isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : PAYJP_FOR_WC_VERSION;

		if ( ! wp_script_is( 'payjp-blocks-checkout', 'registered' ) ) {
			wp_register_script(
				'payjp-blocks-checkout',
				PAYJP_FOR_WC_URL . 'build/blocks/checkout.js',
				$deps,
				$version,
				true
			);
		}

		return array( 'payjp-blocks-checkout' );
	}

	/**
	 * Data passed to the payment method JS component via getSetting().
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		$gateway = $this->gateway;
		return array(
			'title'          => null !== $gateway ? (string) $gateway->get_option( 'title' ) : '',
			'description'    => null !== $gateway ? wp_kses_post( wpautop( (string) $gateway->get_option( 'description' ) ) ) : '',
			'supports'       => array_values( null !== $gateway ? $gateway->supports : array() ),
			'showInCheckout' => $this->is_active(),
		);
	}
}
