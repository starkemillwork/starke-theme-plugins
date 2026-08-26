<?php
/**
 * Plugin Name:     Vern Shipping Block
 * Version:         0.1.2
 * Author:          The WordPress Contributors
 * License:         GPL-2.0-or-later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:     vern_shipping_block
 *
 * @package         create-block
 */

add_action(
	'init',
	function () {
		register_block_type_from_metadata( __DIR__ . '/build/js/checkout-newsletter-subscription-block' );
		register_block_type_from_metadata( __DIR__ . '/build/js/samples-shipping-methods-block' );
		register_block_type_from_metadata( __DIR__ . '/build/js/shipping-address-selector-block' );
		register_block_type_from_metadata( __DIR__ . '/build/js/cart-items-features-block' );
		register_block_type_from_metadata( __DIR__ . '/build/js/contact-features-block' );
		register_block_type_from_metadata( __DIR__ . '/build/js/payment-terms-block' );
	}
);

add_action(
	'woocommerce_blocks_loaded',
	function () {
		require_once __DIR__ . '/vern_shipping_block-blocks-integration.php';
		require_once __DIR__ . '/vern_shipping_block-extend-woo-core.php';
		require_once __DIR__ . '/vern_shipping_block-extend-store-endpoint.php';

		// Initialize our store endpoint extension when WC Blocks is loaded.
		VernShippingBlock_Blocks_Extend_Store_Endpoint::init();
		// Add hooks relevant to extending the Woo core experience.
		$extend_core = VernShippingBlock_Extend_Woo_Core::get_instance();
		//$extend_core->init();

		add_action(
			'woocommerce_blocks_cart_block_registration',
			function ( $integration_registry ) {
				$integration_registry->register( new VernShippingBlock_Blocks_Integration() );
			}
		);
		add_action(
			'woocommerce_blocks_checkout_block_registration',
			function ( $integration_registry ) {
				$integration_registry->register( new VernShippingBlock_Blocks_Integration() );
			}
		);
		add_action(
			'woocommerce_blocks_mini-cart_block_registration',
			function ( $integration_registry ) {
				$integration_registry->register( new VernShippingBlock_Blocks_Integration() );
			}
		);
		// Register the update callback using a closure to pass $extend_core
        woocommerce_store_api_register_update_callback(
            [
                'namespace' => 'vern_shipping_block',
                'callback'  => function($data) use ($extend_core) {
                    extensionCartFunction($data, $extend_core);
                },
            ]
        );
	}
);

function extensionCartFunction($data, $extend_core) {
	$functionSelector = $data['action'];
	if (isset($functionSelector)) {
		switch ($functionSelector) {
			case 'set_shipping_and_billing_address_country':
				$extend_core->set_shipping_and_billing_address_country($data);
				break;
			case 'update_samples_full_shipping_address':
				$extend_core->update_samples_full_shipping_address($data);
				break;
			case 'update_job_info_in_session':
				$extend_core->update_job_info_in_session($data);
				break;
			case 'update_cc_emails_in_session':
				$extend_core->update_cc_emails_in_session($data);
				break;
			//case 'trigger_cart_update':
			//	$extend_core->trigger_cart_update($data);
			//	break;
			case 'update_ltl_freight_cost':
				$extend_core->update_ltl_freight_cost($data);
				break;
			//case 'update_chosen_payment_method':
			//	$extend_core->update_chosen_payment_method($data);
			//	break;
			case 'update_official_profile_number':
				$extend_core->update_official_profile_number($data);
				break;
			case 'update_payment_terms':
				$extend_core->update_payment_terms($data);
				break;
		}
	}
}

/**
 * Registers the slug as a block category with WordPress.
 */
function register_VernShippingBlock_block_category( $categories ) {
	return array_merge(
		$categories,
		[
			[
				'slug'  => 'vern_shipping_block',
				'title' => __( 'VernShippingBlock Blocks', 'vern_shipping_block' ),
			],
		]
	);
}

add_action( 'block_categories_all', 'register_VernShippingBlock_block_category', 10, 2 );


//add_action( 'woocommerce_init', 'VernShippingBlock_register_custom_checkout_fields' );

/**
 * Registers custom checkout fields for the WooCommerce checkout form.
 *
 * @return void
 * @throws Exception If there is an error during the registration of the checkout fields.
 */
function VernShippingBlock_register_custom_checkout_fields() {

	if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
		return;
	}

	woocommerce_register_additional_checkout_field(
		array(
			'id'       => 'vern_shipping_block/custom-checkbox',
			'label'    => 'Check this box to see a custom field on the order.',
			'location' => 'contact',
			'type'     => 'checkbox',
		)
	);

	woocommerce_register_additional_checkout_field(
		array(
			'id'       => 'vern_shipping_block/custom-text-input',
			'label'    => "VernShippingBlock's example text input",
			'location' => 'address',
			'type'     => 'text',
		)
	);

	/**
	 * Sanitizes the value of the custom text input field. For demo purposes we will just turn it to all caps.
	 */
	add_action(
		'woocommerce_sanitize_additional_field',
		function ( $value, $key, $group ) {
			if ( 'vern_shipping_block/custom-text-input' === $key ) {
				return strtoupper( $value );
			}
			return $value;
		},
		10,
		3
	);

	/**
	 * Validates the custom text input field. For demo purposes we will not accept the string 'INVALID'.
	 */
	add_action(
		'woocommerce_blocks_validate_location_address_fields',
		function ( \WP_Error $errors, $fields, $group ) {
			if ( 'INVALID' === $fields['vern_shipping_block/custom-text-input'] ) {
				$errors->add( 'invalid_text_detected', 'Please ensure your custom text input is not "INVALID".' );
			}
		},
		10,
		3
	);

	woocommerce_register_additional_checkout_field(
		array(
			'id'       => 'vern_shipping_block/custom-select-input',
			'label'    => "VernShippingBlock's example select input",
			'location' => 'order',
			'type'     => 'select',
			'options'  => [
				[
					'label' => 'Option 1',
					'value' => 'option1',
				],
				[
					'label' => 'Option 2',
					'value' => 'option2',
				],
			],
		)
	);
}