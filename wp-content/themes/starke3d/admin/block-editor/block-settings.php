<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if (is_admin()) {
	add_filter('register_block_type_args', function ($args, $block_name) {
		// List of block names to unlock
		$unlock_blocks = [
			'woocommerce/cart-items-block',
			'woocommerce/cart-line-items-block',
			'woocommerce/cart-totals-block',
			'woocommerce/checkout-order-summary-cart-items-block'
		];

		// Check if the current block name is in the unlock list
		if (in_array($block_name, $unlock_blocks, true)) {
			// Modify the lock settings if present
			if (isset($args['attributes']['lock']['default'])) {
				$args['attributes']['lock']['default']['remove'] = false; // Allow removal
				$args['attributes']['lock']['default']['move'] = false;   // Allow movement
			}
		}
		return $args;
	}, 10, 2);
}