// @ts-check
const { test, expect } = require( '@playwright/test' );
const path = require( 'path' );
const fs = require( 'fs' );

// Fixture data is written by global-setup.js before any test runs.
const fixturePath = path.join( __dirname, 'fixtures.json' );
if ( ! fs.existsSync( fixturePath ) ) {
	throw new Error(
		'tests/e2e/fixtures.json not found. Run `npx playwright test` to ' +
			'trigger globalSetup, which seeds wp-env and writes this file.'
	);
}
const fixtures = JSON.parse( fs.readFileSync( fixturePath, 'utf8' ) );
const { productId, webhookSecret, classicCheckoutPath } = fixtures;

/** Paths to browser storage-state files created by global-setup.js. */
const ADMIN_AUTH = path.join( __dirname, 'auth/admin.json' );
const CUSTOMER_AUTH = path.join( __dirname, 'auth/customer.json' );

/**
 * Add the fixture product to the cart via the add-to-cart query param.
 *
 * @param {import('@playwright/test').Page} page Playwright page object.
 * @param {number}                          pId  Product ID seeded by global-setup.
 */
async function addProductToCart( page, pId ) {
	await page.goto( `/?add-to-cart=${ pId }` );
	await page.waitForLoadState( 'networkidle' );
}

// ──────────────────────────────────────────────────────────────
// 1. Admin: PAY.JP 設定ページ
// ──────────────────────────────────────────────────────────────
test.describe( 'Admin — PAY.JP settings page', () => {
	test.use( { storageState: ADMIN_AUTH } );

	test( 'PAY.JP tab loads and shows API key fields', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=payjp' );
		await expect( page.locator( '#mainform' ) ).toBeVisible( {
			timeout: 10000,
		} );
		await expect( page.locator( '#payjp_test_public_key' ) ).toBeVisible();
		await expect( page.locator( '#payjp_test_secret_key' ) ).toBeVisible();
		await expect( page.locator( '#payjp_webhook_secret' ) ).toBeVisible();
	} );

	test( 'payjp_card gateway settings page loads', async ( { page } ) => {
		await page.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=payjp_card'
		);
		await expect(
			page.locator( '#mainform, .woocommerce-save-button' ).first()
		).toBeVisible( { timeout: 10000 } );
	} );

	test( 'payjp_paypay gateway settings page loads', async ( { page } ) => {
		await page.goto(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=payjp_paypay'
		);
		await expect(
			page.locator( '#mainform, .woocommerce-save-button' ).first()
		).toBeVisible( { timeout: 10000 } );
	} );
} );

// ──────────────────────────────────────────────────────────────
// 2. Classic Checkout — 決済手段の表示確認
// ──────────────────────────────────────────────────────────────
// Uses a dedicated page rendered by [woocommerce_checkout] shortcode so the
// Classic and Block suites cover genuinely distinct rendering paths.
test.describe( 'Classic Checkout — payment methods visibility', () => {
	test.beforeEach( async ( { page } ) => {
		await addProductToCart( page, productId );
	} );

	test( 'PAY.JP Card option is visible at classic checkout', async ( {
		page,
	} ) => {
		await page.goto( classicCheckoutPath );
		// Classic checkout renders a server-side #payment list — assert that only.
		await page.locator( '#payment' ).waitFor( { timeout: 15000 } );
		await expect(
			page.locator( '#payment_method_payjp_card' )
		).toBeVisible();
	} );

	test( 'PAY.JP PayPay option is visible at classic checkout', async ( {
		page,
	} ) => {
		await page.goto( classicCheckoutPath );
		await page.locator( '#payment' ).waitFor( { timeout: 15000 } );
		await expect(
			page.locator( '#payment_method_payjp_paypay' )
		).toBeVisible();
	} );
} );

// ──────────────────────────────────────────────────────────────
// 3. Block Checkout — 決済手段の表示確認
// ──────────────────────────────────────────────────────────────
// Uses the default /checkout/ page (Block Checkout in WooCommerce 8.3+).
// Selectors are scoped to block-specific elements — the classic #payment
// element is intentionally excluded to keep this suite block-only.
test.describe( 'Block Checkout — payment methods', () => {
	test.beforeEach( async ( { page } ) => {
		await addProductToCart( page, productId );
	} );

	test( 'Checkout page loads with a block payment section', async ( {
		page,
	} ) => {
		await page.goto( '/checkout/' );
		// Block checkout renders payment options inside these containers.
		await expect(
			page
				.locator(
					'.wc-block-checkout__payment-method, .wp-block-woocommerce-checkout-payment-block, [data-block-name="woocommerce/checkout-payment-block"]'
				)
				.first()
		).toBeVisible( { timeout: 15000 } );
	} );

	test( 'PAY.JP Card payment method is available in Block checkout', async ( {
		page,
	} ) => {
		await page.goto( '/checkout/' );
		// Scope to the block payment container to exclude the classic #payment list.
		const blockPayment = page
			.locator(
				'.wc-block-checkout__payment-method, [data-block-name="woocommerce/checkout-payment-block"]'
			)
			.first();
		await expect( blockPayment ).toBeVisible( { timeout: 15000 } );
		await expect(
			blockPayment
				.locator(
					'input[value="payjp_card"], label:has-text("PAY.JP")'
				)
				.first()
		).toBeVisible( { timeout: 10000 } );
	} );
} );

// ──────────────────────────────────────────────────────────────
// 4. My Account — 支払い方法（カードトークン保存 UI）
// ──────────────────────────────────────────────────────────────
test.describe( 'My Account — payment methods', () => {
	test.use( { storageState: CUSTOMER_AUTH } );

	test( 'payment methods page loads for logged-in customer', async ( {
		page,
	} ) => {
		await page.goto( '/my-account/payment-methods/' );
		await expect( page.locator( 'h2, .entry-title' ).first() ).toBeVisible(
			{ timeout: 10000 }
		);
		await expect(
			page.locator( 'a[href*="add-payment-method"]' )
		).toBeVisible( { timeout: 10000 } );
	} );

	test( 'add-payment-method page shows PAY.JP setup form mount point', async ( {
		page,
	} ) => {
		await page.goto( '/my-account/add-payment-method/' );
		await expect( page.locator( '#payjp-setup-form' ) ).toBeVisible( {
			timeout: 15000,
		} );
	} );
} );

// ──────────────────────────────────────────────────────────────
// 5. HPOS — 互換性確認
// ──────────────────────────────────────────────────────────────
test.describe( 'HPOS compatibility', () => {
	test.use( { storageState: ADMIN_AUTH } );

	test( 'WooCommerce status page shows no payjp conflicts', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/admin.php?page=wc-status' );
		await expect( page.locator( '#status' ).first() ).toBeVisible( {
			timeout: 10000,
		} );
		const content = await page.content();
		expect( content ).not.toContain( 'payjp-for-wc is not compatible' );
		expect( content ).not.toContain( 'Fatal error' );
	} );
} );

// ──────────────────────────────────────────────────────────────
// 6. Plugin health — PHP エラーなし・Webhook エンドポイント
// ──────────────────────────────────────────────────────────────
test.describe( 'Plugin health', () => {
	test( 'homepage loads without PHP errors', async ( { page } ) => {
		const response = await page.goto( '/' );
		expect( response?.status() ).toBe( 200 );
		const body = await page.content();
		expect( body ).not.toContain( 'Fatal error' );
		expect( body ).not.toContain( 'Parse error' );
	} );

	test( 'webhook endpoint returns 401 without token', async ( { page } ) => {
		const response = await page.request.post( '/wp-json/payjp/v2/webhook', {
			headers: { 'Content-Type': 'application/json' },
			data: JSON.stringify( { type: 'test' } ),
		} );
		expect( response.status() ).toBe( 401 );
	} );

	test( 'webhook endpoint returns 415 for non-JSON content type', async ( {
		page,
	} ) => {
		const response = await page.request.post( '/wp-json/payjp/v2/webhook', {
			headers: {
				'Content-Type': 'text/plain',
				'X-Payjp-Webhook-Token': webhookSecret,
			},
			data: 'not-json',
		} );
		expect( response.status() ).toBe( 415 );
	} );

	test( 'webhook endpoint returns 400 for invalid JSON', async ( {
		page,
	} ) => {
		const response = await page.request.post( '/wp-json/payjp/v2/webhook', {
			headers: {
				'Content-Type': 'application/json',
				'X-Payjp-Webhook-Token': webhookSecret,
			},
			data: 'not-valid-json{{{',
		} );
		expect( response.status() ).toBe( 400 );
	} );

	test( 'webhook endpoint returns 200 for valid event', async ( {
		page,
	} ) => {
		const response = await page.request.post( '/wp-json/payjp/v2/webhook', {
			headers: {
				'Content-Type': 'application/json',
				'X-Payjp-Webhook-Token': webhookSecret,
			},
			data: JSON.stringify( {
				type: 'unknown.event',
				data: { object: {} },
			} ),
		} );
		expect( response.status() ).toBe( 200 );
		const body = await response.json();
		expect( body.received ).toBe( true );
	} );
} );
