/**
 * My Account — Add Payment Method: PAY.JP Setup Flow widget.
 *
 * Intercepts the WooCommerce "Add payment method" form submit, creates a
 * PAY.JP Setup Flow via the REST endpoint, mounts the payments.js setup
 * widget, then calls confirmSetup() which redirects to handle_setup_return().
 */

/* global PayjpPayments, payjpSetupData */

document.addEventListener( 'DOMContentLoaded', () => {
	const form = document.getElementById( 'add_payment_method' );
	if ( ! form ) {
		return;
	}

	const mountEl = document.getElementById( 'payjp-setup-form' );
	if ( ! mountEl ) {
		return;
	}

	const errorEl = document.getElementById( 'payjp-setup-errors' );
	const { publicKey, returnUrl, restUrl, nonce, i18n } = payjpSetupData;

	let widgetsInstance = null;
	let setupInitialised = false;

	/**
	 * Display an error message in the accessible error container.
	 *
	 * @param {string} message
	 */
	function showError( message ) {
		if ( errorEl ) {
			errorEl.textContent = message;
		}
	}

	/**
	 * Initialise the setup widget by calling the REST endpoint to obtain a
	 * client_secret, then mounting the payments.js setup form.
	 */
	async function initSetupWidget() {
		if ( setupInitialised ) {
			return;
		}
		setupInitialised = true;

		let flowData;
		try {
			const response = await fetch( restUrl, {
				method: 'POST',
				headers: {
					'X-WP-Nonce': nonce,
					'Content-Type': 'application/json',
				},
			} );
			flowData = await response.json();
		} catch {
			showError( i18n.errorGeneric );
			return;
		}

		if ( flowData.error || ! flowData.client_secret ) {
			showError( flowData.error || i18n.errorGeneric );
			return;
		}

		const payments = PayjpPayments( publicKey );
		widgetsInstance = payments.widgets( {
			clientSecret: flowData.client_secret,
		} );

		const setupForm = widgetsInstance.createForm( 'payment', {
			layout: 'accordion',
		} );
		setupForm.mount( mountEl );
	}

	// Initialise as soon as the page loads.
	initSetupWidget();

	// Intercept the WC form submit before WooCommerce's AJAX handler fires.
	form.addEventListener(
		'submit',
		async ( e ) => {
			e.preventDefault();
			e.stopImmediatePropagation();

			if ( ! widgetsInstance ) {
				showError( i18n.errorGeneric );
				return;
			}

			const submitButton = form.querySelector( '[type="submit"]' );
			if ( submitButton ) {
				submitButton.disabled = true;
				submitButton.textContent = i18n.processing;
			}

			const result = await widgetsInstance.confirmSetup( {
				return_url: returnUrl,
			} );

			if ( result && result.error ) {
				showError( result.error.message || i18n.errorGeneric );
				if ( submitButton ) {
					submitButton.disabled = false;
					submitButton.textContent = i18n.addCard;
				}
			}
			// On success PAY.JP redirects automatically.
		},
		true // capture phase — runs before WC's jQuery submit handler
	);
} );
