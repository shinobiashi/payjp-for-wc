/**
 * Block Checkout: PAY.JP PayPay payment method component.
 */
import { __ } from '@wordpress/i18n';
import { getSetting } from '@woocommerce/settings';

const settings = getSetting( 'payjp_paypay_data', {} );

const label = settings.title || __( 'PayPay (PAY.JP)', 'payjp-for-wc' );

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
 * Content shown when PayPay is selected.
 */
const Content = () => {
	return (
		<div id="payjp-paypay-block-form">
			<p>
				{ __(
					'You will be redirected to PayPay to complete your payment.',
					'payjp-for-wc'
				) }
			</p>
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
