/**
 * Block Checkout: PAY.JP card payment method component.
 *
 * When selected, process_payment() (PHP) creates a Payment Flow and redirects
 * to the order-pay page where the payments.js widget is rendered. No widget
 * is mounted here because confirmPayment() always redirects — calling it before
 * the WC order exists would prevent linking the payment to the order on return.
 */
import { __ } from '@wordpress/i18n';
import { RawHTML } from '@wordpress/element';
import { getSetting } from '@woocommerce/settings';

const settings = getSetting( 'payjp_card_data', {} );

const label = settings.title || __( 'Credit Card (PAY.JP)', 'payjp-for-wc' );

/**
 * Label component shown in the payment method list.
 *
 * @param {Object}   props
 * @param {Object}   props.components
 * @param {Function} props.components.PaymentMethodLabel
 */
const Label = ( { components: { PaymentMethodLabel } } ) => (
	<PaymentMethodLabel text={ label } />
);

/**
 * Content shown when the card payment method is selected.
 * Displays the gateway description configured in WooCommerce settings.
 */
const Content = () => {
	const description =
		settings.description ||
		`<p>${ __(
			'Pay securely with your credit card via PAY.JP.',
			'payjp-for-wc'
		) }</p>`;
	return (
		<div className="payjp-card-block-form">
			<RawHTML>{ description }</RawHTML>
		</div>
	);
};

const CardPaymentMethod = {
	name: 'payjp_card',
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports || [],
		showSavedCards: settings.showSavedCards || false,
		showSaveOption: settings.showSaveOption || false,
	},
};

export default CardPaymentMethod;
