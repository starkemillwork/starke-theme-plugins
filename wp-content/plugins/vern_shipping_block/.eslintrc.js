module.exports = {
	extends: [
		'plugin:@woocommerce/eslint-plugin/recommended',
		'plugin:prettier/recommended' // This is the crucial line that was missing
	],
	rules: {
		// Your existing custom rules
		'react/react-in-jsx-scope': 'off',
		'import/no-unresolved': 'off',
		'import/no-extraneous-dependencies': 'off',
		'import/named': 'off',
		'import/default': 'off',
		'@wordpress/i18n-text-domain': [
			'error',
			{
				allowedTextDomain: ['vern_shipping_block']
			}
		],

		// This is the prettier rule with your configuration embedded
		'prettier/prettier': [
			'warn',
			{
				printWidth: 150,
				trailingComma: 'none',
				singleQuote: true
			}
		]
	}
};
