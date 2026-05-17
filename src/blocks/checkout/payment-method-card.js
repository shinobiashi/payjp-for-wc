/**
 * Block Checkout: PAY.JP card payment method component.
 *
 * Saved cards are rendered inline within the Content component (matching
 * classic-checkout behaviour) rather than using WC Blocks' global saved-token
 * section (showSavedCards). When a saved card is selected, its WC token ID is
 * forwarded via onPaymentSetup → paymentMethodData so that the PHP gateway's
 * process_payment() can detect it through $_POST['wc-payjp_card-payment-token'].
 *
 * When no saved cards exist, or the user picks "Use a new payment method", the
 * normal PAY.JP redirect flow (order-pay page widget) is used.
 */
import { __, sprintf } from '@wordpress/i18n';
import { RawHTML, useState, useEffect, useRef } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { RadioControl } from '@wordpress/components';
import { getSetting } from '@woocommerce/settings';

const PAYMENT_STORE_KEY = 'wc/store/payment';

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
 * Simplified content used in the block editor preview (no WC store access).
 */
const EditContent = () => {
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

/**
 * Content shown when the card payment method is selected at checkout.
 *
 * If the customer has saved cards, a radio selector is displayed inside this
 * component. Selecting a saved card registers an onPaymentSetup handler that
 * injects wc-payjp_card-payment-token into the Store API payment_data, which
 * the PHP gateway reads from $_POST.
 *
 * @param {Object} props
 * @param {Object} props.eventRegistration
 * @param {Object} props.emitResponse
 */
const Content = ( { eventRegistration, emitResponse } ) => {
	// Fetch saved tokens for payjp_card from the WC payment store.
	const savedCards = useSelect( ( select ) => {
		const store = select( PAYMENT_STORE_KEY );
		if ( typeof store?.getSavedPaymentMethods !== 'function' ) {
			return [];
		}
		const methods = store.getSavedPaymentMethods();
		return Array.isArray( methods?.payjp_card ) ? methods.payjp_card : [];
	}, [] );

	// Start with 'new'; auto-select the default saved card once data loads.
	const [ selectedToken, setSelectedToken ] = useState( 'new' );
	const initialized = useRef( false );

	useEffect( () => {
		if ( ! initialized.current && savedCards.length > 0 ) {
			initialized.current = true;
			const def =
				savedCards.find( ( t ) => t.is_default ) || savedCards[ 0 ];
			if ( def ) {
				setSelectedToken( String( def.tokenId ) );
			}
		}
	}, [ savedCards ] );

	// When a saved token is selected, forward it to process_payment() via payment_data.
	const { onPaymentSetup } = eventRegistration;
	useEffect( () => {
		const unsubscribe = onPaymentSetup( () => {
			if ( selectedToken !== 'new' ) {
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							'wc-payjp_card-payment-token': selectedToken,
						},
					},
				};
			}
			return { type: emitResponse.responseTypes.SUCCESS };
		} );
		return unsubscribe;
	}, [ onPaymentSetup, selectedToken, emitResponse.responseTypes.SUCCESS ] );

	const description =
		settings.description ||
		`<p>${ __(
			'Pay securely with your credit card via PAY.JP.',
			'payjp-for-wc'
		) }</p>`;

	// No saved cards — show only the description (normal new-card flow).
	if ( savedCards.length === 0 ) {
		return (
			<div className="payjp-card-block-form">
				<RawHTML>{ description }</RawHTML>
			</div>
		);
	}

	const options = [
		...savedCards.map( ( card ) => ( {
			value: String( card.tokenId ),
			label: sprintf(
				/* translators: %1$s: card brand (e.g. Visa), %2$s: last 4 digits */
				__( '%1$s ending in %2$s', 'payjp-for-wc' ),
				card.method?.brand || __( 'Card', 'payjp-for-wc' ),
				card.method?.last4 || '••••'
			),
		} ) ),
		{
			value: 'new',
			label: __( 'Use a new payment method', 'payjp-for-wc' ),
		},
	];

	return (
		<div className="payjp-card-block-form">
			<RadioControl
				selected={ selectedToken }
				options={ options }
				onChange={ ( val ) => setSelectedToken( val ) }
			/>
			{ selectedToken === 'new' && <RawHTML>{ description }</RawHTML> }
		</div>
	);
};

const CardPaymentMethod = {
	name: 'payjp_card',
	label: <Label />,
	content: <Content />,
	edit: <EditContent />,
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports || [],
		// Handled in our Content component; suppress WC Blocks' top-level saved-token section.
		showSavedCards: false,
		showSaveOption: settings.showSaveOption || false,
	},
};

export default CardPaymentMethod;
