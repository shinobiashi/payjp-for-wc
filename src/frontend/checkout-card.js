/* global PayjpPayments, payjpCardData */

/**
 * PAY.JP card payment widget — order-pay page.
 *
 * Mounts the payments.js widget and calls confirmPayment() when the
 * customer clicks "Pay now". PAY.JP handles 3-D Secure (if required)
 * and redirects to the return URL provided by PHP.
 */
( function () {
	'use strict';

	// Guard: payjpCardData is localised by PHP only on the order-pay page
	// after a Payment Flow has been created in process_payment().
	if ( typeof payjpCardData === 'undefined' ) {
		return;
	}

	const { publicKey, clientSecret, returnUrl, i18n } = payjpCardData;

	const payments = PayjpPayments( publicKey );
	const widgets = payments.widgets( { clientSecret } );

	const form = widgets.createForm( 'payment', {
		layout: 'accordion',
		paymentMethodOrder: [ 'card' ],
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

		const result = await widgets.confirmPayment( {
			return_url: returnUrl,
		} );

		if ( result.error ) {
			// Display PAY.JP error message; PAY.JP does not redirect on failure.
			errorEl.textContent = result.error.message;
			payButton.disabled = false;
			payButton.textContent = i18n.payNow;
		}
		// On success PAY.JP redirects automatically via return_url.
	} );
} )();
