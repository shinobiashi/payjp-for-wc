/* global PayjpPayments, payjpCardData */

/**
 * PAY.JP card payment widget — order-pay page.
 *
 * Mounts the payments.js widget and calls confirmPayment() when the
 * customer clicks "Pay now". PAY.JP handles 3-D Secure (if required)
 * and redirects to the return URL provided by PHP.
 *
 * Billing details entered at checkout (name, email, phone) are passed
 * to the widget as defaultValues so the customer does not need to re-enter them.
 */
( function () {
	'use strict';

	// Guard: payjpCardData is localised by PHP only on the order-pay page
	// after a Payment Flow has been created in process_payment().
	if ( typeof payjpCardData === 'undefined' ) {
		return;
	}

	const {
		publicKey,
		clientSecret,
		returnUrl,
		billingDetails = {},
		i18n,
	} = payjpCardData;

	const payments = PayjpPayments( publicKey );
	const widgets = payments.widgets( { clientSecret } );

	const form = widgets.createForm( 'payment', {
		layout: { type: 'accordion', defaultCollapsed: false },
		paymentMethodOrder: [ 'card' ],
		defaultValues: {
			billingDetails: {
				email: billingDetails.email || '',
				phone: billingDetails.phone || '',
			},
		},
	} );

	form.mount( '#payjp-card-receipt-form' );

	const payButton = document.getElementById( 'payjp-card-pay-button' );
	const errorEl = document.getElementById( 'payjp-card-errors' );

	if ( ! payButton || ! errorEl ) {
		return;
	}

	payButton.addEventListener( 'click', async function () {
		payButton.disabled = true;
		payButton.textContent = i18n.processing;
		errorEl.textContent = '';

		try {
			const result = await widgets.confirmPayment( {
				return_url: returnUrl,
				payment_method_data: {
					billing_details: {
						email: billingDetails.email || '',
						phone: billingDetails.phone || '',
					},
				},
			} );

			if ( result.error ) {
				// Display PAY.JP error message; PAY.JP does not redirect on failure.
				errorEl.textContent = result.error.message;
				payButton.disabled = false;
				payButton.textContent = i18n.payNow;
			}
			// On success PAY.JP redirects automatically via return_url.
		} catch ( sdkError ) {
			// SDK threw unexpectedly (network error, malformed clientSecret, etc.).
			errorEl.textContent =
				sdkError instanceof Error
					? sdkError.message
					: String( sdkError );
			payButton.disabled = false;
			payButton.textContent = i18n.payNow;
		}
	} );
} )();
