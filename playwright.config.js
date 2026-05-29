// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	globalSetup: './tests/e2e/global-setup.js',
	timeout: 30000,
	retries: 0,
	use: {
		baseURL: 'http://localhost:8888',
		headless: true,
		screenshot: 'only-on-failure',
		video: 'off',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
	reporter: [ [ 'list' ] ],
} );
