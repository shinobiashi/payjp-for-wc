/* global PayjpPayments, payjpPaypayData */

/**
 * PAY.JP PayPay payment widget — order-pay page.
 *
 * Mounts the payments.js widget and calls confirmPayment() when the
 * customer clicks "Pay with PayPay". PAY.JP redirects to the PayPay
 * app or website to complete the payment, then back to the return URL.
 */
( function () {
	'use strict';

	// Guard: payjpPaypayData is localised by PHP only on the order-pay page
	// after a Payment Flow has been created in process_payment().
	if ( typeof payjpPaypayData === 'undefined' ) {
		return;
	}

	const { publicKey, clientSecret, returnUrl, i18n } = payjpPaypayData;

	const payments = PayjpPayments( publicKey );
	const widgets = payments.widgets( { clientSecret } );

	const form = widgets.createForm( 'payment', {
		layout: 'accordion',
		paymentMethodOrder: [ 'paypay' ],
	} );

	form.mount( '#payjp-paypay-receipt-form' );

	const payButton = document.getElementById( 'payjp-paypay-pay-button' );
	const errorEl = document.getElementById( 'payjp-paypay-errors' );

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
