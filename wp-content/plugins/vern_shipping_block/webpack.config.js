const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const WooCommerceDependencyExtractionWebpackPlugin = require('@woocommerce/dependency-extraction-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

// Remove SASS rule from the default config so we can define our own.
const defaultRules = defaultConfig.module.rules.filter((rule) => {
	return String(rule.test) !== String(/\.(sc|sa)ss$/);
});

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve(process.cwd(), 'src', 'js', 'index.js'),
		'vern_shipping_block-checkout-newsletter-subscription-block': path.resolve(
			process.cwd(),
			'src',
			'js',
			'checkout-newsletter-subscription-block',
			'index.js'
		),
		'vern_shipping_block-checkout-newsletter-subscription-block-frontend': path.resolve(
			process.cwd(),
			'src',
			'js',
			'checkout-newsletter-subscription-block',
			'frontend.js'
		),
		'vern_shipping_block-samples-shipping-methods-block': path.resolve(process.cwd(), 'src', 'js', 'samples-shipping-methods-block', 'index.js'),
		'vern_shipping_block-samples-shipping-methods-block-frontend': path.resolve(
			process.cwd(),
			'src',
			'js',
			'samples-shipping-methods-block',
			'frontend.js'
		),
		'vern_shipping_block-shipping-address-selector-block': path.resolve(
			process.cwd(),
			'src',
			'js',
			'shipping-address-selector-block',
			'index.js'
		),
		'vern_shipping_block-shipping-address-selector-block-frontend': path.resolve(
			process.cwd(),
			'src',
			'js',
			'shipping-address-selector-block',
			'frontend.js'
		),
		'vern_shipping_block-cart-items-features-block': path.resolve(process.cwd(), 'src', 'js', 'cart-items-features-block', 'index.js'),
		'vern_shipping_block-cart-items-features-block-frontend': path.resolve(
			process.cwd(),
			'src',
			'js',
			'cart-items-features-block',
			'frontend.js'
		),
		'vern_shipping_block-contact-features-block': path.resolve(process.cwd(), 'src', 'js', 'contact-features-block', 'index.js'),
		'vern_shipping_block-contact-features-block-frontend': path.resolve(process.cwd(), 'src', 'js', 'contact-features-block', 'frontend.js'),
		'vern_shipping_block-payment-terms-block': path.resolve(process.cwd(), 'src', 'js', 'payment-terms-block', 'index.js'),
		'vern_shipping_block-payment-terms-block-frontend': path.resolve(process.cwd(), 'src', 'js', 'payment-terms-block', 'frontend.js')
	},
	module: {
		...defaultConfig.module,
		rules: [
			...defaultRules,
			{
				test: /\.(sc|sa)ss$/,
				exclude: /node_modules/,
				use: [
					MiniCssExtractPlugin.loader,
					{ loader: 'css-loader', options: { importLoaders: 1 } },
					{
						loader: 'sass-loader',
						options: {
							sassOptions: {
								includePaths: ['src/css'],
								loadPaths: ['src/css', '.']
							},
							additionalData: (content, loaderContext) => {
								const { resourcePath, rootContext } = loaderContext;
								const relativePath = path.relative(rootContext, resourcePath);

								if (relativePath.startsWith('src/css/')) {
									return content;
								}

								// Add code here to prepend to all .scss/.sass files.
								return '@import "colors"; ' + content;
							}
						}
					}
				]
			}
		]
	},
	plugins: [
		...defaultConfig.plugins.filter((plugin) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'),
		new WooCommerceDependencyExtractionWebpackPlugin(),
		new MiniCssExtractPlugin({
			filename: `[name].css`
		})
	]
	/*externals: {
		//'@woocommerce/blocks-components': [ 'wc', 'blockComponents' ],
		'@woocommerce/components': [ 'wc', 'components' ],
		// other externals...
	},*/
};
