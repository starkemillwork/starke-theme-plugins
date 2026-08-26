/**
 * External dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
//import { ExperimentalOrderMeta } from '@woocommerce/blocks-checkout';
//import { getSetting } from '@woocommerce/settings';
/**
 * Internal dependencies
 */
import './style.scss';

import { ExampleComponent } from './ExampleComponent';
//import { registerFilters } from './filters';
//const exampleDataFromSettings = getSetting( 'vern_shipping_block_data' );

const render = () => {
	return (
		<>
			<div>
				<ExampleComponent data={{ fruit: 'apples', color: 'red' }} />
			</div>
		</>
	);
};

registerPlugin('vern-shipping-block', {
	render,
	scope: 'woocommerce-checkout'
});

//registerFilters(); // Not currently using any filters
