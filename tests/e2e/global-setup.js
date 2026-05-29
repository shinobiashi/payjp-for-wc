// @ts-check
/**
 * Playwright global setup — seeds wp-env with test fixtures before any spec runs.
 *
 * Runs `wp-env run cli wp ...` via child_process so the test database is in a
 * known state regardless of how the environment was previously initialised.
 */

const { execSync } = require( 'child_process' );
const path = require( 'path' );
const fs = require( 'fs' );

/** Known test webhook secret written to payjp_settings. */
const TEST_WEBHOOK_SECRET = 'payjp_e2e_webhook_secret';

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

	// Seed API keys from env vars if not already stored in the database.
	if ( ! settings.test_public_key ) {
		settings.test_public_key = process.env.PAYJP_TEST_PUBLIC_KEY || '';
	}
	if ( ! settings.test_secret_key ) {
		settings.test_secret_key = process.env.PAYJP_TEST_SECRET_KEY || '';
	}

	settings.webhook_secret = TEST_WEBHOOK_SECRET;
	settings.enabled_methods = [ 'card', 'paypay' ];
	settings.test_mode = true;

	wpCli(
		`option update payjp_settings '${ JSON.stringify(
			settings
		) }' --format=json`
	);
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
	};
	const fixturePath = path.join( __dirname, 'fixtures.json' );
	fs.writeFileSync( fixturePath, JSON.stringify( fixtureData, null, 2 ) );
	// eslint-disable-next-line no-console
	console.log( `  ✔ fixtures written to ${ fixturePath }\n` );
};
