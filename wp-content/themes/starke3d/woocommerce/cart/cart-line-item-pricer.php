<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * =================================================================================
 * STABLE CART CALCULATIONS & CHARGE PRODUCT MANAGEMENT
 * =================================================================================
 * This file consolidates all dynamic cart logic into functions that follow
 * WooCommerce best practices to avoid race conditions and data corruption.
 *
 */


/**
 * ---------------------------------------------------------------------------------
 * HOOK 1: woocommerce_check_cart_items
 *
 * This is the correct, early hook to safely manage the PRESENCE of charge products.
 * It synchronizes the cart, ensuring that for a given set of products, the
 * required "Setup Charge" and "Tooling Charge" products either exist or are removed.
 * This happens BEFORE the main calculation hooks run, preventing race conditions.
 * ---------------------------------------------------------------------------------
 */
add_action('woocommerce_before_calculate_totals', 'starke_synchronize_charge_products', 10, 1);
function starke_synchronize_charge_products($cart) {
    // If a quote is being programmatically loaded, STOP.
    // This function will run again after loading is complete.
    if ( WC()->session && WC()->session->get('is_loading_quote') ) {
        return;
    }

    // --- NEW: Run-once guard to prevent infinite loops ---
    // This variable is "static", so it persists during the entire request.
    static $has_run_this_request = false;
    if ( $has_run_this_request ) {
        return; // We've already run, so do nothing.
    }
    $has_run_this_request = true;
    // --- END NEW ---
    
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    if (class_exists('Quote_Lock_Controller') && Quote_Lock_Controller::get_instance()->is_quote_locked()) {
        return;
    }

    wc_get_logger()->warning('Ran: ' . print_r('Ran', true), ['source' => 'cart_debug5']);

    // --- Define Product IDs for our charge products ---
    $setup_charge_product_id = 444;
    $knife_cost_product_id = 2843;

    // --- Step 1: Determine which charge products SHOULD exist ---
    $required_setup_charges = [];
    $required_knife_costs = [];

    // Group items to calculate total linear feet for setup charges
    $product_groups = [];
    foreach ($cart->cart_contents as $cart_item) {
        $linear_feet = $cart_item['linear_feet_actual'] ?? 0;
        
        if ( ! empty($cart_item['sample']) && $cart_item['sample'] !== 'false' ) {
            continue;
        }
        if ($cart_item['data']->is_virtual()) {
            continue;
        }

        $product_id = $cart_item['product_id'];

        // --- NEW: Exclude SKU 5000 from getting a setup charge ---
        $product_sku = $cart_item['data']->get_sku();
        if ($product_sku === '5000') {
            continue;
        }
        
        $quantity = $cart_item['quantity'] ?? 1;

        $is_custom_profile = is_custom_profile($product_id) && isset($cart_item['custom_name']);
        $group_key = $is_custom_profile ? $cart_item['custom_name'] : (string)$product_id;
        if (!isset($product_groups[$group_key])) {
            $product_groups[$group_key] = ['linear_feet_total' => 0, 'product_name' => $cart_item['data']->get_name()];
        }
        $product_groups[$group_key]['linear_feet_total'] += ($linear_feet * $quantity);
    }

    // Determine required setup charges based on linear feet
    foreach ($product_groups as $group_data) {
        if ($group_data['linear_feet_total'] > 0 && $group_data['linear_feet_total'] < 100) {
            $required_setup_charges['Setup Charge for ' . $group_data['product_name']] = true;
        }
    }

    // Determine required knife costs for custom profiles
    $custom_names_seen = [];
    foreach ($cart->cart_contents as $cart_item) {
        if (is_custom_profile($cart_item['product_id'])) {
            $custom_name = $cart_item['data']->get_name();
            $knife_cost = isset($cart_item['knifecost']) ? floatval($cart_item['knifecost']) : 0.00;

            if ($knife_cost > 0 && !isset($custom_names_seen[$custom_name])) {
                $charge_name = 'Tooling Charge for ' . $custom_name;
                $required_knife_costs[$charge_name] = $knife_cost; // Store cost for later
                $custom_names_seen[$custom_name] = true;
            }
        }
    }


    // --- Step 2: Find which charge products CURRENTLY exist ---
    $existing_charges_keys = [];
    foreach ($cart->cart_contents as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        if ($product_id === $setup_charge_product_id || $product_id === $knife_cost_product_id) {
            $existing_charges_keys[$cart_item['data']->get_name()] = $cart_item_key;
        }
    }

    // --- NEW: Step 2.5: Update prices for existing knife cost products ---
    // This solves the problem where a custom profile's knife cost is edited,
    // but the associated charge product's price isn't updated.
    foreach ($existing_charges_keys as $name => $key_to_check) {
        // Check if this is a knife cost product AND its corresponding custom profile still exists
        if (strpos($name, 'Tooling Charge for') === 0 && isset($required_knife_costs[$name])) {
            $required_cost = $required_knife_costs[$name];
            // Get the cart item directly
            if (isset($cart->cart_contents[$key_to_check])) {
                $cart_item = $cart->cart_contents[$key_to_check];
                // If the stored price is different from the required price, update it.
                if (!isset($cart_item['knife_cost_price']) || floatval($cart_item['knife_cost_price']) !== floatval($required_cost)) {
                    $cart->cart_contents[$key_to_check]['knife_cost_price'] = $required_cost;
                }
            }
        }
    }

    // --- Step 3: Synchronize - Remove what's not needed, Add what's missing ---

    // Remove obsolete charges
    foreach ($existing_charges_keys as $name => $key_to_remove) {
        $is_setup_charge = strpos($name, 'Setup Charge for') === 0;
        $is_knife_cost = strpos($name, 'Tooling Charge for') === 0;

        if (($is_setup_charge && !isset($required_setup_charges[$name])) || ($is_knife_cost && !isset($required_knife_costs[$name]))) {
            $cart->remove_cart_item($key_to_remove);
        }
    }

    // Add missing setup charges
    foreach ($required_setup_charges as $name => $value) {
        if (!isset($existing_charges_keys[$name])) {
            $cart->add_to_cart($setup_charge_product_id, 1, 0, [], ['custom_name' => $name]);
        }
    }

    // Add missing knife costs
    foreach ($required_knife_costs as $name => $cost) {
        if (!isset($existing_charges_keys[$name])) {

            wc_get_logger()->warning('$name: ' . print_r($name, true), ['source' => 'cart_debug5']);

            $cart->add_to_cart($knife_cost_product_id, 1, 0, [], ['custom_name' => $name, 'knife_cost_price' => $cost]);
        }
    }
}


/**
 * ---------------------------------------------------------------------------------
 * HOOK 2: woocommerce_before_calculate_totals
 *
 * This hook is now ONLY used for safe operations:
 * - Dynamically calculating the price of an existing line item.
 * - Enforcing a quantity limit on an existing line item.
 * - Setting the price for the charge products that were safely added earlier.
 * ---------------------------------------------------------------------------------
 */
add_action('woocommerce_before_calculate_totals', 'starke_calculate_dynamic_prices_and_quantities', 20, 1);
function starke_calculate_dynamic_prices_and_quantities($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }
    if (class_exists('Quote_Lock_Controller') && Quote_Lock_Controller::get_instance()->is_quote_locked()) {
        return;
    }

    $starke_options = get_option('starke_commerce_options');
    $setup_charge_product_id = 444;
    $knife_cost_product_id = 2843;

    foreach ($cart->cart_contents as $cart_item_key => $cart_item) {

        $product_id = intval($cart_item['product_id']);

        // --- Pricing for Charge Products ---
        if ($product_id === $setup_charge_product_id) {
            $setup_charge_cost = isset($starke_options['upcharge_for_runs_under_100_linear_feet']) ? floatval($starke_options['upcharge_for_runs_under_100_linear_feet']) : 100;
            $cart_item['data']->set_price($setup_charge_cost);
            if (isset($cart_item['custom_name'])) {
                $cart_item['data']->set_name($cart_item['custom_name']);
            }
            continue;
        }

        if ($product_id === $knife_cost_product_id) {
            if (isset($cart_item['knife_cost_price'])) {
                $cart_item['data']->set_price(floatval($cart_item['knife_cost_price']));
            }
            if (isset($cart_item['custom_name'])) {
                $cart_item['data']->set_name($cart_item['custom_name']);
            }
            continue;
        }
        
        // --- Pricing for Linear Feet and Samples Products ---
		$linear_feet = intval($cart_item['linear_feet_actual']);
        $width = floatval($cart_item['width_actual']);
        $thickness = floatval($cart_item['thickness_actual']);
        $lengths = sanitize_text_field($cart_item['length']);
        $rabbet_position = sanitize_text_field($cart_item['first_rabbet']);
        $relief_angle = sanitize_text_field($cart_item['reliefangle_actual']);
        $species = sanitize_text_field($cart_item['species_actual']);
        $finish_option = sanitize_text_field($cart_item['finish_actual']);
		$sample = sanitize_text_field($cart_item['sample']);
		
		$isSample = $linear_feet === 0 && $lengths === '' && $rabbet_position === '' && $relief_angle === '' && $species === '' && $finish_option === '' && $sample === '1';
		// Sets Sample product's price and also forces quantity of 1
		if ($isSample) {
            $per_sample_cost = isset($starke_options['charge_per_sample']) ? floatval($starke_options['charge_per_sample']) : 4;
            $cart->cart_contents[$cart_item_key]['quantity'] = 1;
			$cart_item['data']->set_price($per_sample_cost);
			continue;
		}

        $markup = is_custom_profile($product_id) && isset($cart_item['markup']) ? floatval($cart_item['markup']) : 0;
        $waste = is_custom_profile($product_id) && isset($cart_item['waste']) ? floatval($cart_item['waste']) : 0;

		// Sets inear Feet product's price
		$price = 0;
        $results = Price_Engine::calculate_pricing($linear_feet, $width, $thickness, $lengths, $rabbet_position, $relief_angle, $species, $finish_option, $product_id, $markup, $waste);
		$price += $results['subtotal'];
		$linear_feet = intval($results['linear_feet']);
		// Add Feature's Setup Charges for Linear Profiles under 100ft
		if ($results['rabbet_position'] === 'true' && $linear_feet < 100) {
			$rabbet_under100ft_setup_charge = isset($starke_options['rabbet_under100ft_setup_charge']) ? floatval($starke_options['rabbet_under100ft_setup_charge']) : 50; // Pull this cost from Starke Commerce tool when I create it later
			$cart_item['rabbet_setup_charge'] = '$' . $rabbet_under100ft_setup_charge;
			$price += $rabbet_under100ft_setup_charge;
		} else {
			$cart_item['rabbet_setup_charge'] = null;
		}
		if ($results['relief_angle'] === 'true' && $linear_feet < 100) {
			$relief_angle_under100ft_setup_charge = isset($starke_options['relief_angle_under100ft_setup_charge']) ? floatval($starke_options['relief_angle_under100ft_setup_charge']) : 50; // Pull this cost from Starke Commerce tool when I create it later
			$cart_item['relief_angle_setup_charge'] = '$' . $relief_angle_under100ft_setup_charge;
			$price += $relief_angle_under100ft_setup_charge;
		} else {
			$cart_item['relief_angle_setup_charge'] = null;
		}

		$cart_item['quantity_discount'] = $results['quantity_discount'] . '%';
		$cart_item['price_per_foot'] = $results['price_per_foot'];
		$cart->cart_contents[$cart_item_key] = $cart_item;
        $cart_item['data']->set_price($price);
    }
}

add_filter('woocommerce_store_api_product_quantity_editable',
  function( $value, $product, $cart_item ) {
	  return false;
  },
  10,
  3,
);

/**
 * Modifies the total cart contents count to exclude virtual products.
 *
 * This filter is respected by both classic WooCommerce and the new
 * block-based Store API, ensuring the count is correct everywhere.
 *
 * @param int $count The original total number of items in the cart.
 * @return int The modified count, including only non-virtual products.
 */
add_filter( 'woocommerce_cart_contents_count', 'exclude_virtuals_from_cart_count' );

function exclude_virtuals_from_cart_count( $count ) {
    // If the cart is empty, no need to do anything.
    if ( is_admin() && ! defined( 'DOING_AJAX' ) || is_null( WC()->cart ) ) {
        return $count;
    }

    $physical_item_count = 0;
    $cart_items          = WC()->cart->get_cart();

    // Loop through each item in the cart.
    foreach ( $cart_items as $cart_item ) {
        // The product object is stored in the 'data' key.
        $product = $cart_item['data'];
        
        // Check if the product is NOT virtual.
        if ( ! $product->is_virtual() ) {
            // Add the quantity of this physical item to our new count.
            $physical_item_count += $cart_item['quantity'];
        }
    }

    return $physical_item_count;
}