/**
 * Block Checkout: PAY.JP PayPay payment method component.
 *
 * When selected, process_payment() (PHP) creates a Payment Flow and redirects
 * to the order-pay page where the payments.js widget triggers the PayPay redirect.
 */
import { __ } from '@wordpress/i18n';
import { RawHTML } from '@wordpress/element';
import { getSetting } from '@woocommerce/settings';

const settings = getSetting( 'payjp_paypay_data', {} );

const label = settings.title || __( 'PayPay (PAY.JP)', 'payjp-for-wc' );

/**
 * Label component shown in the payment method list.
 * Renders a custom <img> for the PayPay logo because WooCommerce's
 * PaymentMethodLabel only accepts predefined icon names (bank, card, etc.),
 * not arbitrary SVG URLs.
 */
const Label = () => (
	<span className="wc-block-components-payment-method-label">
		{ settings.icon && (
			<img
				src={ settings.icon }
				alt=""
				style={ { height: '2em', width: 'auto', verticalAlign: 'middle' } }
			/>
		) }
		{ label }
	</span>
);

/**
 * Content shown when PayPay is selected.
 * Displays the gateway description, or a default redirect notice.
 */
const Content = () => {
	const description =
		settings.description ||
		`<p>${ __(
			'You will be redirected to PayPay to complete your payment.',
			'payjp-for-wc'
		) }</p>`;
	return (
		<div className="payjp-paypay-block-form">
			<RawHTML>{ description }</RawHTML>
		</div>
	);
};

const PayPayPaymentMethod = {
	name: 'payjp_paypay',
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports || [],
	},
};

export default PayPayPaymentMethod;
