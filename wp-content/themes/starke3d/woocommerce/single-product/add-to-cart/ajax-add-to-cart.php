<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
//$checkout_url = wc_get_checkout_url();

// 'https://www.starkemillwork.com/checkout/'

/**
 * Enable ajax add to cart for WooCommerce products
 */
function sm_wc_single_ajax_add_to_cart() {
    if ( function_exists('is_product') && 
       ( is_product() || is_shop() || is_front_page() )
    ) {
        // Enqueue your custom JS file
        wp_enqueue_script('dk-wc-add-to-cart', get_stylesheet_directory_uri() . '/assets/js/ajax-add-to-cart.js', array('jquery'), false, true);

        // Localize WooCommerce parameters for your script
        wp_localize_script('dk-wc-add-to-cart', 'wc_add_to_cart_params', array(
            'wc_ajax_url' => admin_url('admin-ajax.php'),
            'wc_nonce' => wp_create_nonce('woocommerce-cart'), // Add nonce for security
        ));
    }
}
add_action('wp_enqueue_scripts', 'sm_wc_single_ajax_add_to_cart');

// --- NEW: Core Server-Side Protection against Limited Accounts ---
add_filter( 'woocommerce_add_to_cart_validation', 'starke_strict_backend_cart_block', 99, 3 );
function starke_strict_backend_cart_block( $passed, $product_id, $quantity ) {
    if ( function_exists( 'starke_is_account_limited' ) && starke_is_account_limited() ) {
        // Throw a WooCommerce error notice and block the addition
        wc_add_notice( 'Your account currently has limited access. Purchasing is disabled.', 'error' );
        return false;
    }
    return $passed;
}

// Save Fields to Cart Meta
add_action('woocommerce_add_cart_item_data', 'add_or_update_custom_fields_in_cart', 10, 2);
function add_or_update_custom_fields_in_cart($cart_item_data, $product_id) {
    if (isset($cart_item_data['is_loaded_from_order']) && $cart_item_data['is_loaded_from_order']) {
        // If this item is loaded from an order, we don't want to modify it.
        unset($cart_item_data['is_loaded_from_order']);
        return $cart_item_data;
    }
    if (!is_user_logged_in()) {
        wp_send_json_error( array( 'message' => 'You must be logged in to add a product to the cart.' ), 403 );
        wp_die();
    }
    if ( function_exists( 'starke_is_account_limited' ) && starke_is_account_limited() ) {
        wp_send_json_error( array( 'message' => 'Account Limited: Purchasing and sample requests are disabled.' ), 403 );
        wp_die();
    }
    
    $setup_charge_product_id = 444; // ID of the Setup Charge product
    $knife_cost_product_id = 2843; // ID of the Knife Cost product

    $is_custom_profile = is_custom_profile($product_id);
    $is_custom_profile_allowed = $is_custom_profile && (current_user_can('manage_woocommerce') || impersonation_is_active());

    if ($product_id === $setup_charge_product_id || $product_id === $knife_cost_product_id || ($is_custom_profile && !$is_custom_profile_allowed)) {
		return $cart_item_data;
	}

    // --- NEW: Server-Side Validation for Required Fields ---
    if ( !isset($_POST['sample']) || $_POST['sample'] !== 'true' ) {
        // Check if empty OR if the string starts with "Select"
        if ( empty($_POST['linear_feet']) || empty($_POST['length']) || strpos($_POST['length'], 'Select') === 0 ) {
            wp_send_json_error( array( 'message' => 'Please fill in all required fields (Linear Feet and Length).' ) );
            wp_die();
        }
    }

    $is_edit_mode = !empty($_POST['cikey']) && isset($_POST['cikey']);
    $is_add_same_custom_profile = isset($_POST['add_same_custom_profile']) && $_POST['add_same_custom_profile'] === 'true';
    
    $fields = [
        'linear_feet',
        'linear_feet_actual',
        'thickness',
        'thickness_actual',
        'width',
        'width_actual',
        'length',
        'first_rabbet',
        'first_rabbet_actual',
        'first_rabbet_thickness',
        'first_rabbet_thickness_actual',
        'first_rabbet_width',
        'first_rabbet_width_actual',
        'reliefangle',
        'reliefangle_actual',
        'backrelief',
        'backrelief_actual',
        'species',
        'species_actual',
        'finish',
        'finish_actual',
        'stain',
        'stain_actual',
        'sheen',
        'sheen_actual',
        'rabbet_setup_charge',
        'relief_angle_setup_charge',
    ];

    if ($is_custom_profile_allowed) {
        $fields[] = 'knifecost';
        $fields[] = 'markup';
        $fields[] = 'waste';
        $fields[] = 'similar_profiles';
        $fields[] = 'custom_description';
    }

    // If cart_item_key is provided (is Edit/Save Mode), update existing cart item (Normal and Custom Profiles)
    if ($is_edit_mode && !$is_add_same_custom_profile) {
        $cart_item_key = sanitize_text_field($_POST['cikey']);
        $cart = WC()->cart;

        if (isset($cart->cart_contents[$cart_item_key])) {
            foreach ($fields as $field) {
				unset($cart->cart_contents[$cart_item_key][$field]);
                if (isset($_POST[$field])) {
                    $cart->cart_contents[$cart_item_key][$field] = sanitize_text_field(wp_unslash($_POST[$field]));
                }
            }

            wc_get_logger()->warning('$cart_item_key A: ' . print_r($cart_item_key, true), ['source' => 'cart_debug5']);
            
            // --- Generate structured data for similar_profiles in edit mode ---
            if ($is_custom_profile_allowed && isset($cart->cart_contents[$cart_item_key]['similar_profiles'])) {
                $cart->cart_contents[$cart_item_key]['similar_profiles_data'] = get_similar_profiles_structured_data($cart->cart_contents[$cart_item_key]['similar_profiles']);
            } else {
                // Ensure the data field is removed if the source field is empty
                unset($cart->cart_contents[$cart_item_key]['similar_profiles_data']);
            }

            // After updating, check if the edited item now matches another item in the cart.
            $matched_key_after_edit = find_matching_cart_item_key($cart->cart_contents[$cart_item_key]['product_id'], $cart->cart_contents[$cart_item_key], $cart_item_key);
            if ($matched_key_after_edit) {
                $other_item = $cart->cart_contents[$matched_key_after_edit];
                $cart->cart_contents[$cart_item_key]['linear_feet_actual'] += floatval($other_item['linear_feet_actual']);
                $cart->cart_contents[$cart_item_key]['linear_feet'] = $cart->cart_contents[$cart_item_key]['linear_feet_actual'] . 'ft';
                $cart->remove_cart_item($matched_key_after_edit);
                $edited_item_message = 'cart_item_consolidated';
            } else {
                $edited_item_message = 'cart_item_updated';
            }

            if ($is_custom_profile_allowed && isset($cart->cart_contents[$cart_item_key]['knifecost'], $cart->cart_contents[$cart_item_key]['custom_name'])) {
                sync_knife_cost_for_custom_profile($cart->cart_contents[$cart_item_key]['custom_name'], $cart->cart_contents[$cart_item_key]['knifecost'], $cart_item_key);
            }

            // --- NEW: Manually trigger cart sync and pricing ---
            // This ensures the cart is 100% correct before the redirect,
            // preventing any price flicker on the checkout page.
            
            // 1. Syncs charge products (adds/removes/updates data)
            if (function_exists('starke_synchronize_charge_products')) {
                starke_synchronize_charge_products($cart);
            }
            
            // 2. Applies prices based on the synced data
            if (function_exists('starke_calculate_dynamic_prices_and_quantities')) {
                starke_calculate_dynamic_prices_and_quantities($cart);
            }
            // --- END NEW ---
            if ( ! $matched_key_after_edit ) {
                $cart->set_session();
            }
            wp_send_json_success(['message' => $edited_item_message, 'checkout_url' => wc_get_checkout_url()]);
            wp_die();
        }
    }

    // If no cart_item_key, add a new cart item (Normal and Custom Profiles)
    $potential_item_data = [];
    if (isset($_POST['sample']) && ($_POST['sample'] === 'false' || $_POST['sample'] === null)) {
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $potential_item_data[$field] = sanitize_text_field(wp_unslash($_POST[$field]));
            }
        }
        $potential_item_data['sample'] = false;

        // Specific Add To Cart details for Custom profiles
        if ($is_custom_profile_allowed) {
            // Add To Cart for Same Custom Profile
            if ($is_edit_mode && $is_add_same_custom_profile) {
                // Get custom_name from the original Custom Profile cart item
                $source_key = sanitize_text_field($_POST['cikey']);
                $existing_custom_name = WC()->cart->cart_contents[$source_key]['custom_name'] ?? '';
                $potential_item_data['custom_name'] = $existing_custom_name;
                WC()->session->set('this_placeholder_custom_profile_number', $existing_custom_name);

                wc_get_logger()->warning('$existing_custom_name: ' . print_r($existing_custom_name, true), ['source' => 'cart_debug5']);

                // Sync knife cost for Custom Profile
                if (isset($potential_item_data['knifecost'])) {
                    $new_knife_cost = $potential_item_data['knifecost'];
                    
                    wc_get_logger()->warning('$new_knife_cost: ' . print_r($new_knife_cost, true), ['source' => 'cart_debug5']);

                    // This syncs the knifecost on all *other* custom profile line items
                    sync_knife_cost_for_custom_profile($existing_custom_name, $new_knife_cost);
                    
                    // --- NEW ---
                    // We must ALSO manually update the price data on the charge product *itself*.
                    // This avoids the race condition flicker when the standard hooks run.
                    $charge_product_name = 'Tooling Charge for ' . $existing_custom_name;
                    foreach (WC()->cart->get_cart() as $key => $item) {
                        if ($item['data']->get_name() === $charge_product_name) {
                            WC()->cart->cart_contents[$key]['knife_cost_price'] = $new_knife_cost;
                            break; 
                        }
                    }
                }
            } // Add To Cart for new Custom Profile
            else {
                // Get the placeholder custom profile number and set custom profile name
                $potential_item_data['custom_name'] = get_custom_profile_number();
            }

            if (isset($potential_item_data['similar_profiles'])) {
                $potential_item_data['similar_profiles_data'] = get_similar_profiles_structured_data($potential_item_data['similar_profiles']);
            }
        }
    } else {
        $potential_item_data['sample'] = true;
    }

    // Now, run the consolidation check using the fully-formed temporary data.
    $matched_cart_item_key = find_matching_cart_item_key($product_id, $potential_item_data);
    if ($matched_cart_item_key) {

        wc_get_logger()->warning('$matched_cart_item_key: ' . print_r($matched_cart_item_key, true), ['source' => 'cart_debug5']);

        $cart = WC()->cart;
        $cart->cart_contents[$matched_cart_item_key]['linear_feet_actual'] += floatval(sanitize_text_field($_POST['linear_feet_actual']));
        $cart->cart_contents[$matched_cart_item_key]['linear_feet'] = $cart->cart_contents[$matched_cart_item_key]['linear_feet_actual'] . 'ft';
       
        // 1. Syncs charge products (adds/removes/updates data)
        if (function_exists('starke_synchronize_charge_products')) {
            starke_synchronize_charge_products($cart);
        }
        
        // 2. Applies prices based on the synced data
        if (function_exists('starke_calculate_dynamic_prices_and_quantities')) {
            starke_calculate_dynamic_prices_and_quantities($cart);
        }
        
        $cart->set_session();
        wp_send_json_success(['message' => 'cart_item_consolidated', 'checkout_url' => wc_get_checkout_url()]);
        wp_die();
    }
    
    // If we reach here, no consolidation match was found. Proceed to populate the final $cart_item_data.
    $cart_item_data = $potential_item_data;

    // Handle session variables for brand new custom profiles.
    if ($is_custom_profile_allowed && !($is_edit_mode && $is_add_same_custom_profile)) {
        if(isset($cart_item_data['custom_name'])) {
            increment_custom_profile_number();
            WC()->session->set('this_placeholder_custom_profile_number', $cart_item_data['custom_name']);
            WC()->session->set('next_placeholder_custom_profile_number', get_custom_profile_number());
        }
    }

    // Sample product
    if (isset($_POST['sample']) && $_POST['sample'] === 'true'/* && $product_id != $setup_charge_product_id*/) {
        // --- NEW: Server-side check for sample inventory ---
        $sample_inventory = intval(get_field('sample_inventory', $product_id));
        if ($sample_inventory <= 0) {
            // If no samples are in stock, send an error and stop.
            wp_send_json_error(['message' => 'sample_out_of_stock']);
            wp_die();
        }
        
        $cart = WC()->cart;
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['sample']) && $cart_item['sample'] === true && $cart_item['product_id'] == $product_id) {
                wp_send_json_error(['message' => 'duplicate_sample', ]);
                wp_die();
            }
        }

        $cart_item_data['sample'] = true;
        $product = wc_get_product($product_id);
        $cart_item_data['custom_name'] = $product->get_name() . ' (sample)';
		$cart_item_data['thickness_actual'] = get_field('thickness', $product_id);
		$cart_item_data['thickness'] = decimalToImperialFraction($cart_item_data['thickness_actual'], 16);
		$cart_item_data['width_actual'] = get_field('width', $product_id);
		$cart_item_data['width'] = decimalToImperialFraction($cart_item_data['width_actual'], 16);
    }
    return $cart_item_data;
}

// Display Fields in Cart/Checkout
add_filter('woocommerce_get_item_data', 'display_custom_fields_at_checkout', 10, 2);
function display_custom_fields_at_checkout($item_data, $cart_item) {
    $fields = [
        'rabbet_setup_charge' => 'Rabbet Setup Charge (Under 100ft)',
        'relief_angle_setup_charge' => 'Relief Angle Setup Charge (Under 100ft)',
        'custom_description' => 'Description',
        'linear_feet' => 'Linear Feet',
        'quantity_discount' => 'Quantity Discount',
        'thickness' => 'Thickness',
        'width' => 'Width',
        'length' => 'Lengths',
        'first_rabbet' => 'Rabbet Position',
        'first_rabbet_thickness' => 'Rabbet Thickness',
        'first_rabbet_width' => 'Rabbet Width',
        'reliefangle' => 'Relief Angle',
        'backrelief' => 'Back Relief',
        'species' => 'Species',
        'finish' => 'Finish',
        'stain' => 'Stain',
        'sheen' => 'Sheen',
        'similar_profiles' => 'Similar Profiles',
    ];

    foreach ($fields as $key => $label) {
        if (isset($cart_item[$key]) && $cart_item[$key] != "null") {
            $item_data[] = [
                'key' => $label,
                'value' => wc_clean($cart_item[$key])
            ];
        }
    }

    return $item_data;
}

// Display custom name in cart and checkout
function display_custom_name_in_cart($cart_item, $cart_item_key) {
    if (isset($cart_item['custom_name'])) {
        $cart_item['data']->set_name($cart_item['custom_name']);
    }
    return $cart_item;
}
add_filter('woocommerce_get_cart_item_from_session', 'display_custom_name_in_cart', 10, 2);
add_filter('woocommerce_get_cart_item', 'display_custom_name_in_cart', 10, 2);

// Set custom profile number in session to use in fragments on frontend
add_filter( 'woocommerce_add_to_cart_fragments', 'inject_custom_profile_into_fragments' );
function inject_custom_profile_into_fragments( $fragments ) {
    $this_custom_profile_number = WC()->session->get( 'this_placeholder_custom_profile_number' );
    $next_custom_profile_number = WC()->session->get( 'next_placeholder_custom_profile_number' );
    if ($this_custom_profile_number) {
        $fragments['this_custom_profile_number'] = $this_custom_profile_number;
        WC()->session->__unset( 'this_placeholder_custom_profile_number' );
    }
    if ($next_custom_profile_number) {
        $fragments['next_custom_profile_number'] = $next_custom_profile_number;
        WC()->session->__unset( 'next_placeholder_custom_profile_number' );
    }
    return $fragments;
}

// =================================================================
// --- HELPER FUNCTIONS --- START ---
// =================================================================

/**
 * Generates a unique signature for a cart item based on consolidation criteria.
 * This helps in efficiently finding matching items in the cart.
 *
 * @param int   $product_id The product ID.
 * @param array $data       The item's data (either from $_POST or a cart item array).
 * @return string|null      A unique MD5 hash signature, or null if the item is not consolidatable.
 */
function generate_consolidation_signature($product_id, $data) {
    // 1. Exclude specific product IDs and samples from consolidation.
    if (in_array(intval($product_id), [444, 2843], true)) {
        return null;
    }
    // The 'sample' field in $_POST is a string 'true'/'false', in the cart it's a boolean.
    // This check handles both cases consistently.
    $is_sample = (isset($data['sample']) && ($data['sample'] === true || $data['sample'] === 'true'));
    if ($is_sample) {
        return null;
    }

    // 2. Define the fields that must match for consolidation.
    $fields_to_check = [
        'thickness_actual', 'width_actual', 'length', 'first_rabbet_actual',
        'first_rabbet_thickness_actual', 'first_rabbet_width_actual',
        'reliefangle_actual', 'backrelief_actual', 'species_actual',
        'finish_actual', 'stain_actual', 'sheen_actual', 'custom_name',
        'markup', 'waste', 'similar_profiles', 'custom_description'
    ];

    // 3. Build the signature string from the product ID and field values.
    $signature_parts = [$product_id];
    foreach ($fields_to_check as $field) {
        // Use a consistent value (empty string) for missing fields to ensure matching works correctly.
        $value = isset($data[$field]) ? (string)$data[$field] : '';
        // Normalize "null" string from POST data to empty string to match unset fields in cart items.
        if ($value === 'null') {
            $value = '';
        }
        $signature_parts[] = $value;
    }

    // 4. Return the MD5 hash of the combined string for a fast and reliable comparison.
    return md5(implode('|', $signature_parts));
}

/**
 * Finds a matching item in the cart to consolidate with.
 *
 * @param int   $product_id The ID of the product being added.
 * @param array $post_data  The $_POST data from the add-to-cart request.
 * @param string|null $exclude_key A cart item key to exclude from the search (used in edit mode).
 * @return string|false     The cart item key of the matched item, or false if no match is found.
 */
function find_matching_cart_item_key($product_id, $post_data, $exclude_key = null) {
    $new_item_signature = generate_consolidation_signature($product_id, $post_data);

    if (is_null($new_item_signature)) {
        return false;
    }

    foreach (WC()->cart->cart_contents as $cart_item_key => $cart_item) {
        if ($cart_item_key === $exclude_key) {
            continue;
        }
        $existing_item_signature = generate_consolidation_signature($cart_item['product_id'], $cart_item);
        
        if (!is_null($existing_item_signature) && $new_item_signature === $existing_item_signature) {
            return $cart_item_key;
        }
    }

    return false;
}

/**
 * Generates a structured array of data for similar profiles.
 *
 * @param string $profiles_string A comma and/or space-separated string of profile names.
 * @return array A structured array with name, url, and image_url for each profile.
 */
function get_similar_profiles_structured_data($profiles_string) {
    if (empty($profiles_string)) {
        return array();
    }

    $structured_data = array();
    // Use a regular expression to split the string by any combination of commas and spaces.
    $profile_names = preg_split('/[\s,]+/', $profiles_string, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($profile_names as $profile_name) {
        // Find the WordPress post object for a product by its title.
        $product_post = get_page_by_title($profile_name, OBJECT, 'product');

        if ($product_post) {
            $product_id = $product_post->ID;
            $product = wc_get_product($product_id);
            
            if ($product) {
                // Get the product URL and the CloudFront thumbnail URL.
                $product_url = $product->get_permalink();
                $image_url = get_the_post_thumbnail_url($product_id, 'woocommerce_thumbnail'); // Gets the 300x300 CloudFront URL

                // Add the structured data to our new array.
                $structured_data[] = array(
                    'name'        => $profile_name,
                    'product_url' => $product_url,
                    'image_url'   => $image_url ? $image_url : '' // Ensure image_url is not null
                );
            }
        }
    }
    return $structured_data;
}

/**
 * Sync the knife cost value for all cart items with the same custom_name.
 *
 * @param string $custom_name  The custom profile identifier.
 * @param mixed  $knife_cost   The knife cost value to apply.
 * @param string|null $exclude_key Optional cart item key to exclude from update.
 */
function sync_knife_cost_for_custom_profile($custom_name, $knife_cost, $exclude_key = null) {
    $cart = WC()->cart;
    if (!$custom_name || $knife_cost === null) {
        return;
    }

    foreach ($cart->cart_contents as $key => &$item) {
        if ($key !== $exclude_key && ($item['custom_name'] ?? '') === $custom_name) {
            $item['knifecost'] = $knife_cost;
        }
    }
    //$cart->set_session(); // Persist the updates
}

/**
 * Convert a decimal inch measurement to an imperial fraction string.
 *
 * Accepts numeric strings as well as floats. The fraction part is rounded
 * to the nearest 1/{$precision} increment, and the fraction is always simplified.
 *
 * Examples:
 *   decimalToImperialFraction("4.25")       returns: 4-1/4"
 *   decimalToImperialFraction(1.5)            returns: 1-1/2"
 *   decimalToImperialFraction(7.0625)         returns: 7-1/16"
 *   decimalToImperialFraction("2.25", 8)      returns: 2-1/4"  (not 2-2/8")
 *
 * @param mixed $decimal   The inch measurement (float or numeric string).
 * @param int   $precision The denominator to round to (e.g., 16 for sixteenths).
 * @return string The measurement in imperial fraction format.
 */
function decimalToImperialFraction($decimal, $precision = 16) {
    // Return empty string if input is null or an empty string.
    if ($decimal === null || $decimal === '') {
        return '';
    }

    // Ensure the input is treated as a float.
    $decimal = floatval($decimal);

    // Separate the whole number and the fractional part.
    $whole = floor($decimal);
    $fractional = $decimal - $whole;

    // Convert the fractional part into a numerator relative to the given precision.
    $numerator = round($fractional * $precision);

    // If the fraction rounds to a whole number, increment the whole number.
    if ($numerator == $precision) {
        $whole++;
        $numerator = 0;
    }

    // If there's no fractional part, return just the whole number with a quote.
    if ($numerator == 0) {
        return "{$whole}\"";
    }

    // Simplify the fraction using the greatest common divisor (GCD).
    $gcd = gcd($numerator, $precision);
    $simplifiedNumerator = $numerator / $gcd;
    $simplifiedDenom = $precision / $gcd;

    // Format the output correctly.
    return ($whole > 0)
        ? "{$whole}-{$simplifiedNumerator}/{$simplifiedDenom}\""
        : "{$simplifiedNumerator}/{$simplifiedDenom}\"";
}

/**
 * Calculate the Greatest Common Divisor (GCD) using the Euclidean algorithm.
 *
 * @param int $a
 * @param int $b
 * @return int
 */
function gcd($a, $b) {
    return ($b == 0) ? abs($a) : gcd($b, $a % $b);
}

// =================================================================
// --- HELPER FUNCTIONS --- END ---
// =================================================================

/**
 * Output a hidden sync div for JS to read.
 * Populates the live cart data instantly via PHP on page load.
 */
add_action('wp_footer', 'starke_sample_sync_div');
function starke_sample_sync_div() {
    $sample_ids = [];
    // Safely check the live cart right on page load
    if ( is_object( WC() ) && isset(WC()->cart) && WC()->cart ) {
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['sample'] ) && $cart_item['sample'] === true ) {
                $sample_ids[] = intval( $cart_item['product_id'] );
            }
        }
    }
    // Echo the actual populated array into the HTML immediately
    echo '<div id="starke-sample-sync" style="display:none;" data-samples="' . esc_attr(json_encode(array_values(array_unique($sample_ids)))) . '"></div>';
}