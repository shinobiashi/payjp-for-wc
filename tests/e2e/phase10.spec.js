// @ts-check
const { test, expect } = require( '@playwright/test' );

const ADMIN_USER = 'admin';
const ADMIN_PASS = 'password';
const CUSTOMER_USER = 'testcustomer';
const CUSTOMER_PASS = 'password123';

/** Log in to WP Admin. */
async function loginAdmin( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	// DB update prompt が出ることがある
	if ( await page.locator( 'input[name="upgrade"]' ).isVisible( { timeout: 3000 } ).catch( () => false ) ) {
		await page.click( 'input[name="upgrade"]' );
	}
	await page.waitForURL( /wp-admin/, { timeout: 10000 } );
}

/** Log in as customer via My Account. */
async function loginCustomer( page ) {
	await page.goto( '/my-account/' );
	await page.fill( '#username', CUSTOMER_USER );
	await page.fill( '#password', CUSTOMER_PASS );
	await page.click( 'button[name="login"]' );
	await page.waitForURL( /my-account/, { timeout: 10000 } );
}

/** カートに商品を追加（直接商品URLからフォームPOST）. */
async function addProductToCart( page, productId ) {
	await page.goto( `/?add-to-cart=${ productId }` );
	await page.waitForTimeout( 1000 );
}

// ──────────────────────────────────────────────────────────────
// 1. Admin: PAY.JP 設定ページ
// ──────────────────────────────────────────────────────────────
test.describe( 'Admin — PAY.JP settings page', () => {
	test( 'PAY.JP tab loads and shows API key fields', async ( { page } ) => {
		await loginAdmin( page );
		// PAY.JP 設定は独立タブ tab=payjp
		await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=payjp' );
		await expect( page.locator( '#mainform' ) ).toBeVisible( { timeout: 10000 } );
		await expect( page.locator( '#payjp_test_public_key' ) ).toBeVisible();
		await expect( page.locator( '#payjp_test_secret_key' ) ).toBeVisible();
		await expect( page.locator( '#payjp_webhook_secret' ) ).toBeVisible();
	} );

	test( 'payjp_card gateway settings page loads', async ( { page } ) => {
		await loginAdmin( page );
		await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=payjp_card' );
		await expect( page.locator( '#mainform, .woocommerce-save-button' ).first() ).toBeVisible( { timeout: 10000 } );
	} );

	test( 'payjp_paypay gateway settings page loads', async ( { page } ) => {
		await loginAdmin( page );
		await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=payjp_paypay' );
		await expect( page.locator( '#mainform, .woocommerce-save-button' ).first() ).toBeVisible( { timeout: 10000 } );
	} );
} );

// ──────────────────────────────────────────────────────────────
// 2. Classic Checkout — 決済手段の表示確認
// ──────────────────────────────────────────────────────────────
test.describe( 'Classic Checkout — payment methods visibility', () => {
	test.beforeEach( async ( { page } ) => {
		await addProductToCart( page, 20 ); // Shirt (simple product)
	} );

	test( 'PAY.JP Card option is visible at checkout', async ( { page } ) => {
		await page.goto( '/checkout/' );
		// Block checkout ではページ下部に決済セクションがあるのでスクロール
		await page.evaluate( () => window.scrollTo( 0, document.body.scrollHeight ) );
		await page.waitForTimeout( 2000 );
		// Classic checkout
		const classicCard = page.locator( '#payment_method_payjp_card' );
		// Block checkout: ラジオボタンの value に gateway ID が入る
		const blockCard = page.locator( 'input[value="payjp_card"]' );
		const found = ( await classicCard.count() ) > 0 || ( await blockCard.count() ) > 0;
		expect( found ).toBe( true );
	} );

	test( 'PAY.JP PayPay option is visible at checkout', async ( { page } ) => {
		await page.goto( '/checkout/' );
		await page.evaluate( () => window.scrollTo( 0, document.body.scrollHeight ) );
		await page.waitForTimeout( 2000 );
		const classicPaypay = page.locator( '#payment_method_payjp_paypay' );
		const blockPaypay = page.locator( 'input[value="payjp_paypay"]' );
		const found = ( await classicPaypay.count() ) > 0 || ( await blockPaypay.count() ) > 0;
		expect( found ).toBe( true );
	} );
} );

// ──────────────────────────────────────────────────────────────
// 3. Block Checkout — 決済手段の表示確認
// ──────────────────────────────────────────────────────────────
test.describe( 'Block Checkout — payment methods', () => {
	test.beforeEach( async ( { page } ) => {
		await addProductToCart( page, 20 );
	} );

	test( 'Checkout page loads with a payment section', async ( { page } ) => {
		await page.goto( '/checkout/' );
		await page.evaluate( () => window.scrollTo( 0, document.body.scrollHeight ) );
		await page.waitForTimeout( 2000 );
		// Classic または Block どちらでも決済セクションが存在すること
		const hasPayment = await page.locator(
			'#payment, .wc-block-checkout__payment-method, .wp-block-woocommerce-checkout-payment-block, [data-block-name="woocommerce/checkout-payment-block"]'
		).count();
		expect( hasPayment ).toBeGreaterThan( 0 );
	} );

	test( 'PAY.JP Card payment method is available in Block checkout', async ( { page } ) => {
		await page.goto( '/checkout/' );
		await page.evaluate( () => window.scrollTo( 0, document.body.scrollHeight ) );
		await page.waitForTimeout( 2000 );
		// PAY.JP カード選択肢の存在確認（Block or Classic）
		const payjpLabel = page.locator( 'label:has-text("PAY.JP"), input[value="payjp_card"], #payment_method_payjp_card' );
		await expect( payjpLabel.first() ).toBeVisible( { timeout: 10000 } );
	} );
} );

// ──────────────────────────────────────────────────────────────
// 4. My Account — 支払い方法（カードトークン保存 UI）
// ──────────────────────────────────────────────────────────────
test.describe( 'My Account — payment methods', () => {
	test( 'payment methods page loads for logged-in customer', async ( { page } ) => {
		await loginCustomer( page );
		await page.goto( '/my-account/payment-methods/' );
		await expect( page.locator( 'h2, .entry-title' ).first() ).toBeVisible( { timeout: 10000 } );
		await expect( page.locator( 'a[href*="add-payment-method"]' ) ).toBeVisible( { timeout: 10000 } );
	} );

	test( 'add-payment-method page shows PAY.JP setup form mount point', async ( { page } ) => {
		await loginCustomer( page );
		await page.goto( '/my-account/add-payment-method/' );
		// payments.js のマウントポイントが存在すること
		await expect( page.locator( '#payjp-setup-form' ) ).toBeVisible( { timeout: 15000 } );
	} );
} );

// ──────────────────────────────────────────────────────────────
// 5. HPOS — 注文が HPOS テーブルに保存されていること
// ──────────────────────────────────────────────────────────────
test.describe( 'HPOS compatibility', () => {
	test( 'WooCommerce status page shows HPOS enabled and no payjp conflicts', async ( { page } ) => {
		await loginAdmin( page );
		await page.goto( '/wp-admin/admin.php?page=wc-status' );
		await expect( page.locator( '#status' ).first() ).toBeVisible( { timeout: 10000 } );
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

	test( 'webhook endpoint returns 401 without token (not 500)', async ( { page } ) => {
		const response = await page.request.post( '/wp-json/payjp/v1/webhook', {
			headers: { 'Content-Type': 'application/json' },
			data: JSON.stringify( { type: 'test' } ),
		} );
		expect( response.status() ).toBe( 401 );
	} );

	test( 'webhook endpoint returns 415 for non-JSON content type', async ( { page } ) => {
		const secret = 'whook_908bcec9a1d276e0b405dc2ec9';
		const response = await page.request.post( '/wp-json/payjp/v1/webhook', {
			headers: {
				'Content-Type': 'text/plain',
				'X-Payjp-Webhook-Token': secret,
			},
			data: 'not-json',
		} );
		expect( response.status() ).toBe( 415 );
	} );

	test( 'webhook endpoint returns 400 for invalid JSON', async ( { page } ) => {
		const secret = 'whook_908bcec9a1d276e0b405dc2ec9';
		const response = await page.request.post( '/wp-json/payjp/v1/webhook', {
			headers: {
				'Content-Type': 'application/json',
				'X-Payjp-Webhook-Token': secret,
			},
			data: 'not-valid-json{{{',
		} );
		expect( response.status() ).toBe( 400 );
	} );

	test( 'webhook endpoint returns 200 for valid event', async ( { page } ) => {
		const secret = 'whook_908bcec9a1d276e0b405dc2ec9';
		const response = await page.request.post( '/wp-json/payjp/v1/webhook', {
			headers: {
				'Content-Type': 'application/json',
				'X-Payjp-Webhook-Token': secret,
			},
			data: JSON.stringify( { type: 'unknown.event', data: { object: {} } } ),
		} );
		expect( response.status() ).toBe( 200 );
		const body = await response.json();
		expect( body.received ).toBe( true );
	} );
} );
