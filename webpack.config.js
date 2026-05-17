const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDepExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );

module.exports = {
	...defaultConfig,

	entry: {
		// Block Checkout payment method (registered via registerPaymentMethod)
		'blocks/checkout': './src/blocks/checkout/index.js',
		// Admin settings page
		'admin/settings': './src/admin/settings/index.js',
		// Classic checkout — card payment widget (order-pay page)
		'frontend/checkout-card': './src/frontend/checkout-card.js',
		// Classic checkout — PayPay payment widget (order-pay page)
		'frontend/checkout-paypay': './src/frontend/checkout-paypay.js',
		// My Account — Add payment method setup widget
		'frontend/setup-card': './src/frontend/setup-card.js',
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
