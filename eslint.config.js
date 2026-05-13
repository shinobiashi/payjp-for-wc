'use strict';

const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	// Ignore .claude/ skill scripts — not part of the plugin source.
	{ ignores: [ '.claude/**' ] },

	// Spread the @wordpress/scripts default config (includes build/, node_modules/, vendor/ ignores).
	...defaultConfig,

	// @woocommerce/* and @wordpress/* are WooCommerce/WordPress runtime-provided
	// externals, not installed as npm packages. Skip module resolution checks for them.
	{
		rules: {
			'import/no-unresolved': [
				'error',
				{ ignore: [ '^@wordpress/', '^@woocommerce/' ] },
			],
		},
	},
];
