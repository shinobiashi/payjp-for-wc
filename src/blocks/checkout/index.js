/**
 * Block Checkout payment method registration for PAY.JP v2.
 *
 * Registers both card and PayPay payment methods with the WooCommerce
 * Blocks payment registry so they appear in the Block Checkout.
 */
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';

// Card payment method
import CardPaymentMethod from './payment-method-card';

// PayPay payment method
import PayPayPaymentMethod from './payment-method-paypay';

const cardSettings = getSetting( 'payjp_card_data', {} );
const paypaySettings = getSetting( 'payjp_paypay_data', {} );

if ( cardSettings.supports?.showInCheckout ) {
	registerPaymentMethod( CardPaymentMethod );
}

if ( paypaySettings.supports?.showInCheckout ) {
	registerPaymentMethod( PayPayPaymentMethod );
}
