const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDepExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );

module.exports = {
	...defaultConfig,

	entry: {
		// Block Checkout payment method (registered via registerPaymentMethod)
		'blocks/checkout': './src/blocks/checkout/index.js',
		// Admin settings page
		'admin/settings': './src/admin/settings/index.js',
	},

	plugins: [
		// Replace @wordpress/scripts' DependencyExtractionWebpackPlugin with
		// the WooCommerce version, which handles @woocommerce/* externals
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new WooCommerceDepExtractionWebpackPlugin(),
	],
};
