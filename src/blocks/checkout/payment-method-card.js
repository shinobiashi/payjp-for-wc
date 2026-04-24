/**
 * Block Checkout: PAY.JP card payment method component.
 */
import { __ } from '@wordpress/i18n';
import { getSetting } from '@woocommerce/settings';

const settings = getSetting( 'payjp_card_data', {} );

const label = settings.title || __( 'Credit Card (PAY.JP)', 'payjp-for-woocommerce' );

/**
 * Label component shown in the payment method list.
 *
 * @param {Object} props
 */
const Label = ( { components: { PaymentMethodLabel } } ) => (
	<PaymentMethodLabel text={ label } />
);

/**
 * Content shown when card is selected.
 * payments.js widget will be mounted here via useEffect in the full implementation.
 *
 * @param {Object} props
 */
const Content = ( props ) => {
	return (
		<div id="payjp-card-block-form">
			<div id="payjp-card-element" />
			<div id="payjp-card-errors" role="alert" aria-live="polite" />
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
	},
};

export default CardPaymentMethod;
