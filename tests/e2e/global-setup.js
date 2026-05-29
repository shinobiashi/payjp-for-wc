// @ts-check
/**
 * Playwright global setup — seeds wp-env with test fixtures before any spec runs.
 *
 * Runs `wp-env run cli wp ...` via child_process so the test database is in a
 * known state regardless of how the environment was previously initialised.
 */

const { execSync } = require( 'child_process' );
const { chromium } = require( '@playwright/test' );
const path = require( 'path' );
const fs = require( 'fs' );

/** Base URL of the wp-env test environment. */
const BASE_URL = 'http://localhost:8888';

/** Directory where browser storage-state (auth) files are saved. */
const AUTH_DIR = path.join( __dirname, 'auth' );

/** Known test webhook secret written to payjp_settings. */
const TEST_WEBHOOK_SECRET = 'payjp_e2e_webhook_secret';

/**
 * Deterministic placeholder API keys used when PAYJP_TEST_* env vars are absent.
 * Non-empty values satisfy is_available()'s key-presence check without requiring
 * real PAY.JP credentials. Tests that make actual API calls should supply real keys.
 */
const SMOKE_TEST_PUBLIC_KEY = 'pk_test_e2e_smoke_key_placeholder';
const SMOKE_TEST_SECRET_KEY = 'sk_test_e2e_smoke_key_placeholder';

/** Known test customer credentials. */
const TEST_CUSTOMER = {
	login: 'testcustomer',
	email: 'test@example.com',
	password: 'password123',
};

/**
 * Run a WP-CLI command inside the wp-env container and return stdout.
 *
 * @param {string} cmd WP-CLI command (without `wp` prefix).
 * @return {string} Trimmed stdout from the WP-CLI command.
 */
function wpCli( cmd ) {
	return execSync( `npx wp-env run cli wp ${ cmd } 2>/dev/null`, {
		cwd: path.resolve( __dirname, '../..' ),
		encoding: 'utf8',
	} ).trim();
}

/**
 * Wrap a string in POSIX single quotes, escaping any embedded single quotes.
 * Prevents shell breakage when interpolating JSON values into wpCli commands.
 *
 * @param {string} str Raw string to escape.
 * @return {string} Shell-safe single-quoted string.
 */
function shellescape( str ) {
	return "'" + str.replace( /'/g, "'\\''" ) + "'";
}

/**
 * Return an existing simple product ID, or create one and return its ID.
 *
 * @return {number} Published simple product ID.
 */
function ensureProduct() {
	// Look for any published simple product.
	// wp-env prepends "ℹ Starting…" and appends "✔ Ran…" lines; filter them out.
	const listOutput = wpCli(
		'wc product list --user=1 --type=simple --status=publish --format=ids'
	);
	const first = listOutput
		.split( /\s+/ )
		.find( ( id ) => /^\d+$/.test( id ) );
	if ( first ) {
		return parseInt( first, 10 );
	}

	// No products — create a minimal one.
	const output = wpCli(
		"wc product create --user=1 --name='E2E Test Product' --regular_price=1000 --status=publish"
	);
	const match = output.match( /Created product (\d+)/ );
	if ( ! match ) {
		throw new Error( `Could not create product: ${ output }` );
	}
	return parseInt( match[ 1 ], 10 );
}

/**
 * Ensure the test customer exists and return their user ID.
 *
 * @return {number} WordPress user ID of the test customer.
 */
function ensureCustomer() {
	try {
		const id = wpCli( `user get ${ TEST_CUSTOMER.login } --field=ID` );
		return parseInt( id, 10 );
	} catch {
		// User does not exist — create them.
		const output = wpCli(
			`user create ${ TEST_CUSTOMER.login } ${ TEST_CUSTOMER.email } --role=customer --user_pass=${ TEST_CUSTOMER.password } --first_name=Test --last_name=Customer`
		);
		const match = output.match( /Created user (\d+)/ );
		if ( ! match ) {
			throw new Error( `Could not create customer: ${ output }` );
		}
		return parseInt( match[ 1 ], 10 );
	}
}

/**
 * Extract a JSON object string from wp-env output.
 * wp-env wraps command output with "ℹ Starting…" / "✔ Ran…" lines;
 * find the first line that begins with '{' to get the raw JSON.
 *
 * @param {string} wpEnvOutput Full stdout returned by wpCli().
 * @return {string} The JSON line, or '{}' when none is found.
 */
function extractJson( wpEnvOutput ) {
	const jsonLine = wpEnvOutput
		.split( '\n' )
		.find( ( line ) => line.trim().startsWith( '{' ) );
	return jsonLine ? jsonLine.trim() : '{}';
}

/**
 * Ensure PAY.JP settings contain test API keys, enabled methods, and the
 * known test webhook secret. Existing keys in the database are preserved so
 * the setup is idempotent across multiple runs.
 *
 * API keys can also be provided via PAYJP_TEST_PUBLIC_KEY /
 * PAYJP_TEST_SECRET_KEY environment variables for CI environments that do
 * not have a pre-seeded database.
 */
function ensurePayjpSettings() {
	let settings = {};
	try {
		const raw = wpCli( 'option get payjp_settings --format=json' );
		// Extract only the JSON line from wp-env wrapper output.
		settings = JSON.parse( extractJson( raw ) );
	} catch {
		// Option doesn't exist yet — start with empty settings.
	}

	// Seed API keys from env vars, falling back to deterministic smoke-test
	// placeholders so is_available() returns true on a fresh wp-env.
	// Tests that make real PAY.JP API calls should supply PAYJP_TEST_* vars.
	if ( ! settings.test_public_key ) {
		settings.test_public_key =
			process.env.PAYJP_TEST_PUBLIC_KEY || SMOKE_TEST_PUBLIC_KEY;
	}
	if ( ! settings.test_secret_key ) {
		settings.test_secret_key =
			process.env.PAYJP_TEST_SECRET_KEY || SMOKE_TEST_SECRET_KEY;
	}

	settings.webhook_secret = TEST_WEBHOOK_SECRET;
	settings.enabled_methods = [ 'card', 'paypay' ];
	settings.test_mode = true;

	wpCli(
		`option update payjp_settings ${ shellescape(
			JSON.stringify( settings )
		) } --format=json`
	);
}

/**
 * Set `enabled = yes` in an individual WooCommerce gateway option row.
 *
 * WooCommerce's parent::is_available() checks $this->enabled (read from
 * woocommerce_{gatewayId}_settings.enabled), independently of the shared
 * payjp_settings.enabled_methods value. Both must be set for the gateway
 * to appear at checkout.
 *
 * @param {string} gatewayId Gateway ID, e.g. 'payjp_card'.
 */
function ensureGatewayEnabled( gatewayId ) {
	let gatewaySettings = {};
	try {
		const raw = wpCli(
			`option get woocommerce_${ gatewayId }_settings --format=json`
		);
		gatewaySettings = JSON.parse( extractJson( raw ) );
	} catch {
		// Option doesn't exist yet — start fresh.
	}
	gatewaySettings.enabled = 'yes';
	wpCli(
		`option update woocommerce_${ gatewayId }_settings ${ shellescape(
			JSON.stringify( gatewaySettings )
		) } --format=json`
	);
}

/**
 * Ensure a Classic Checkout page exists (rendered via [woocommerce_checkout]
 * shortcode) and return its relative path. Keeping this page separate from the
 * default /checkout/ (Block Checkout) lets the two E2E test suites cover
 * genuinely distinct rendering paths.
 *
 * @return {string} Relative URL path, e.g. '/classic-checkout/'.
 */
function ensureClassicCheckoutPage() {
	const existing = wpCli(
		'post list --post_type=page --name=classic-checkout --field=ID --format=ids'
	);
	const existingId = existing
		.split( /\s+/ )
		.find( ( s ) => /^\d+$/.test( s ) );
	if ( existingId ) {
		return '/classic-checkout/';
	}
	// Create a page whose content is the classic WooCommerce checkout shortcode.
	const output = wpCli(
		`post create --post_type=page --post_status=publish` +
			` --post_title='Classic Checkout' --post_name=classic-checkout` +
			` --post_content='[woocommerce_checkout]' --porcelain`
	);
	const newId = output.split( /\s+/ ).find( ( s ) => /^\d+$/.test( s ) );
	if ( ! newId ) {
		throw new Error(
			`Could not create classic checkout page: ${ output }`
		);
	}
	return '/classic-checkout/';
}

/**
 * Log in to WP Admin using a headless browser and persist the session to
 * AUTH_DIR/admin.json. Specs load this file via test.use({ storageState })
 * instead of repeating the login on every test.
 */
async function saveAdminAuthState() {
	const browser = await chromium.launch();
	const page = await browser.newPage();
	await page.goto( `${ BASE_URL }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	// Handle wp-env DB upgrade prompt if it appears on first boot.
	if (
		await page
			.locator( 'input[name="upgrade"]' )
			.isVisible( { timeout: 3000 } )
			.catch( () => false )
	) {
		await page.click( 'input[name="upgrade"]' );
	}
	await page.waitForURL( /wp-admin/, { timeout: 10000 } );
	await page
		.context()
		.storageState( { path: path.join( AUTH_DIR, 'admin.json' ) } );
	await browser.close();
}

/**
 * Log in as the seeded test customer using a headless browser and persist the
 * session to AUTH_DIR/customer.json for reuse across My Account specs.
 */
async function saveCustomerAuthState() {
	const browser = await chromium.launch();
	const page = await browser.newPage();
	await page.goto( `${ BASE_URL }/my-account/` );
	await page.fill( '#username', TEST_CUSTOMER.login );
	await page.fill( '#password', TEST_CUSTOMER.password );
	await page.click( 'button[name="login"]' );
	await page.waitForURL( /my-account/, { timeout: 10000 } );
	await page
		.context()
		.storageState( { path: path.join( AUTH_DIR, 'customer.json' ) } );
	await browser.close();
}

module.exports = async function globalSetup() {
	// eslint-disable-next-line no-console
	console.log( '\n[global-setup] Seeding wp-env test fixtures…' );

	const productId = ensureProduct();
	// eslint-disable-next-line no-console
	console.log( `  ✔ product ID: ${ productId }` );

	const customerId = ensureCustomer();
	// eslint-disable-next-line no-console
	console.log(
		`  ✔ customer ID: ${ customerId } (${ TEST_CUSTOMER.login })`
	);

	ensurePayjpSettings();
	// eslint-disable-next-line no-console
	console.log( `  ✔ webhook secret: ${ TEST_WEBHOOK_SECRET }` );

	// Mirror 'enabled: yes' into each individual gateway option so
	// WC_Payment_Gateway::is_available() passes on a fresh wp-env.
	ensureGatewayEnabled( 'payjp_card' );
	ensureGatewayEnabled( 'payjp_paypay' );
	// eslint-disable-next-line no-console
	console.log( '  ✔ individual gateway options: enabled = yes' );

	const classicCheckoutPath = ensureClassicCheckoutPage();
	// eslint-disable-next-line no-console
	console.log( `  ✔ classic checkout page: ${ classicCheckoutPath }` );

	// Flush WP object cache so settings take effect immediately.
	try {
		wpCli( 'cache flush' );
	} catch {
		// Object cache may not be available — not fatal.
	}

	// Write resolved fixture data to a JSON file so specs can read it.
	const fixtureData = {
		productId,
		customerId,
		webhookSecret: TEST_WEBHOOK_SECRET,
		customer: TEST_CUSTOMER,
		classicCheckoutPath,
	};
	const fixturePath = path.join( __dirname, 'fixtures.json' );
	fs.writeFileSync( fixturePath, JSON.stringify( fixtureData, null, 2 ) );
	// eslint-disable-next-line no-console
	console.log( `  ✔ fixtures written to ${ fixturePath }` );

	// Persist authenticated browser sessions so specs use test.use({ storageState })
	// instead of logging in at the start of every test.
	fs.mkdirSync( AUTH_DIR, { recursive: true } );
	await saveAdminAuthState();
	// eslint-disable-next-line no-console
	console.log( '  ✔ admin auth state saved' );
	await saveCustomerAuthState();
	// eslint-disable-next-line no-console
	console.log( '  ✔ customer auth state saved\n' );
};
