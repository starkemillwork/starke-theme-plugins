<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;

    //////////////////////////////
    ////// --- SHIPPING --- //////
    //////////////////////////////

// Toggle Shipping methods for Samples
// Hide Sample shipping rates and set Standard Sample Shipping method cost
add_filter('woocommerce_package_rates', 'customize_and_conditionally_hide_shipping_methods', 2, 2);
function customize_and_conditionally_hide_shipping_methods($rates, $package) {
    // Get the package type and check if a complete address has been entered by the customer.
    $package_type        = isset($package['package_type']) ? $package['package_type'] : '';
    $address_is_complete = ! empty( WC()->customer->get_shipping_postcode() ) &&
                           ! empty( WC()->customer->get_shipping_city() ) &&
                           ! empty( WC()->customer->get_shipping_state() );

    // If this is a sample-only package AND the address is not complete, hide all rates.
    if ( ('sample_only' === $package_type) && ! $address_is_complete ) {
        return []; // Returning an empty array tells WooCommerce there are no available rates.
    }

    //$calculate_taxes = false;
    $shipping_method_ids = get_shipping_method_ids($rates);
    $linear_ft_shipping_method_ids = $shipping_method_ids[0];
    $samples_shipping_method_ids   = $shipping_method_ids[1];
    $samples_standard_method_id    = $shipping_method_ids[2];

    $package_type = isset($package['package_type']) ? $package['package_type'] : '';
    $has_sample_product    = false;
    $has_linear_ft_product = false;
    $total_sample_cost     = 0;

    // Detect which types of products are present in this package
    foreach ($package['contents'] as $package_item) {
        if (is_cart_item_a_sample($package_item)) {
            $has_sample_product = true;
            $total_sample_cost += $package_item['line_total'];
        } else {
            $has_linear_ft_product = true;
        }
    }

    // Filter methods based on package_type
    if (in_array($package_type, ['standard', 'standard_only', 'standard_fallback'])) {
        foreach ($samples_shipping_method_ids as $method_id) {
            if (strpos($method_id, 'pickup_location:') !== 0 && isset($rates[$method_id])) {
                unset($rates[$method_id]);
            }
        }
    }

    if (in_array($package_type, ['sample', 'sample_only'])) {
        foreach ($linear_ft_shipping_method_ids as $method_id) {
            if (strpos($method_id, 'pickup_location:') !== 0 && isset($rates[$method_id])) {
                unset($rates[$method_id]);
            }
        }

        // --- NEW: Remove all pickup location rates for sample-only packages. ---
        if ( 'sample_only' === $package_type ) {
            foreach ( $rates as $rate_id => $rate ) {
                if ( strpos( $rate_id, 'pickup_location:' ) === 0 ) {
                    unset( $rates[ $rate_id ] );
                }
            }
        }
        
        // Adjust Samples Standard Shipping cost based on number of samples
        if (isset($rates[$samples_standard_method_id])) {
            $original_cost = $rates[$samples_standard_method_id]->cost;
            $new_cost      = $original_cost - $total_sample_cost;
            $rates[$samples_standard_method_id]->cost = ($new_cost <= 0) ? 0 : $new_cost;
        }
        //$calculate_taxes = true;
    }

    // Add or remove the LTL Shipping flat rate
    if (in_array($package_type, ['standard', 'standard_only', 'standard_fallback'])) {
        $ltl_freight_rate_id = get_shipping_rate_id_by_name($rates, 'LTL Shipping');
        if (isset($rates[$ltl_freight_rate_id])) {
            // Condition 1: Check for admin or impersonation (your existing logic).
            $is_admin_or_impersonating = function_exists('impersonation_is_active') && (impersonation_is_active() || current_user_can('manage_woocommerce'));
            $is_customer_with_matching_address = false;
            if (!$is_admin_or_impersonating) {
                if (WC()->session->get('cart_is_active_quote') && WC()->session->get('ltl_freight_approved')) {
                    $locked_quote_data = WC()->session->get('locked_quote_data');
                    $original_address = $locked_quote_data['comparison_primary_addr'] ?? null;

                    if ($original_address) {
                        // Create a comparable snapshot of the current package's destination.
                        $current_address = [
                            'city'     => strtolower(trim($package['destination']['city'] ?? '')),
                            'state'    => strtolower(trim($package['destination']['state'] ?? '')),
                            'postcode' => strtolower(trim($package['destination']['postcode'] ?? ''))
                        ];
                        // Compare the current address to the original.
                        if ($current_address === $original_address) {
                            $is_customer_with_matching_address = true;
                        }
                    }
                }
            }

            if ($is_admin_or_impersonating || $is_customer_with_matching_address) {
                if ( ! $address_is_complete ) {
                    unset($rates[$ltl_freight_rate_id]);
                } else {
                    $other_flat_rates_exist = false;
                    // Check for other flat rates that are NOT the 'LTL Shipping' flat rate
                    foreach ($rates as $rate_id => $rate) {
                        if (strpos($rate_id, 'flat_rate:') === 0 && $rate_id !== $ltl_freight_rate_id) {
                            $other_flat_rates_exist = true;
                            break;
                        }
                    }
                    // If our LTL rate exists and there are no other flat rates, keep it and modify it.
                    if (!$other_flat_rates_exist) {
                        $ltl_cost = WC()->session->get( 'ltl_freight_cost', 0.00 );
                        $rates[$ltl_freight_rate_id]->cost = floatval($ltl_cost);
                        //$calculate_taxes = true;
                    } // Otherwise, if other flat rates exist, hide our LTL rate.
                    else {
                        unset($rates[$ltl_freight_rate_id]);
                    }
                }
            } // If it's not a freight quote, hide the LTL rate.
            else {
                unset($rates[$ltl_freight_rate_id]);
            }
        }
    }

    $destination_for_tax = null;
    if ($package_type === 'sample') {
        $destination_for_tax = get_samples_destination();
    } else { 
        $destination_for_tax = $package['destination'];
    }

    $taxjar = new Starke_TaxJar_API();
    
    foreach ($rates as $rate_id => $rate) {
        $rate_dest = $destination_for_tax;
        // Check if this rate is a local pickup AND it's not the sample package
        if ( $package_type !== 'sample' && strpos( $rate_id, 'pickup_location:' ) === 0 ) {
            $rate_dest = starke_get_pickup_location_address( $rate_id, $rate_dest );
        }

        $tax_rates_for_this_rate = [];
        if ( $rate_dest ) {
            $tax_rates_for_this_rate = $taxjar->get_formatted_rate_array($rate_dest);
        }

        $json_encoded_rates = json_encode($tax_rates_for_this_rate);
        $rate->add_meta_data('_destination_tax_rates', $json_encoded_rates);
    }

    return $rates;
}

/**
 * Splits the shipping package if both standard (linear ft) and sample products are present.
 * Samples are sent to the destination stored in the session, standard items to the original destination.
 *
 * @param array $packages Array of shipping packages.
 * @return array Modified array of shipping packages.
 */
add_filter( 'woocommerce_cart_shipping_packages', 'split_standard_and_samples_packages', 2, 1 );
function split_standard_and_samples_packages( $packages ) {
    // Ensure session is available and there's a package to process.
    if ( ! WC()->session || ! isset( $packages[0] ) ) {
        return $packages;
    }

    // --- REFACTORED LOGIC ---
    // We will modify the original $packages array instead of creating a new one.
    $original_package = $packages[0];
    $standard_items   = [];
    $sample_items     = [];

    // Separate items into standard and sample groups.
    foreach ( $original_package['contents'] as $item_key => $item ) {
        if ( is_cart_item_a_sample( $item ) ) {
            $sample_items[ $item_key ] = $item;
        } else {
            $standard_items[ $item_key ] = $item;
        }
    }

    // Only proceed with splitting if we have both types of items.
    if ( ! empty( $standard_items ) && ! empty( $sample_items ) ) {
        // 1. Modify the original package to only contain standard items.
        $packages[0]['contents']      = $standard_items;
        $packages[0]['contents_cost'] = array_sum( wp_list_pluck( $standard_items, 'line_total' ) );
        $packages[0]['package_type']  = 'standard';

        // 2. Create a new, separate package for sample items.
        $samples_full_shipping_address = WC()->session->get('samples_full_shipping_address');

        if ( $samples_full_shipping_address && ! empty( $samples_full_shipping_address['state'] ) && ! empty( $samples_full_shipping_address['postcode'] ) ) {
            $sample_package                = $original_package; // Start by copying original package data.
            $sample_package['contents']      = $sample_items;
            $sample_package['contents_cost'] = array_sum( wp_list_pluck( $sample_items, 'line_total' ) );
            $sample_package['package_type']  = 'sample';
            $sample_package['destination']   = [
                'country'   => $samples_full_shipping_address['country'] ?? 'US',
                'state'     => $samples_full_shipping_address['state'],
                'postcode'  => $samples_full_shipping_address['postcode'],
                'city'      => $samples_full_shipping_address['city'],
                'address'   => $samples_full_shipping_address['address_1'],
                'address_1' => $samples_full_shipping_address['address_1'],
                'address_2' => $samples_full_shipping_address['address_2'],
            ];
            // Add the new sample package to the array.
            $packages[] = $sample_package;
        } else {
            // If sample address is not set, merge samples back into the main package.
             $packages[0]['contents'] = array_merge( $standard_items, $sample_items );
             $packages[0]['contents_cost'] = array_sum( wp_list_pluck( $packages[0]['contents'], 'line_total' ) );
             wc_get_logger()->log('warning', 'Sample shipping address details missing in session. Samples merged with standard package.', array( 'source' => 'split_packages' ) );
        }
    } else {
        if ( ! empty( $standard_items ) ) {
            $packages[0]['package_type'] = 'standard_only';
        } elseif ( ! empty( $sample_items ) ) {
            $packages[0]['package_type'] = 'sample_only';
        }
    }
    // Return the new set of packages only if splitting actually occurred, otherwise return original

	wc_get_logger()->log('debug', '$packages: ' . print_r($packages, true), [ 'source' => 'shipping_methods_debug4' ]);

    //wc_get_logger()->log('debug', '$new_packages[0][package_type]: ' . print_r($new_packages[0]['package_type'], true), [ 'source' => 'shipping_methods_debug3' ]);

    return $packages;
}

/**
 * 1. Saves the last chosen non-pickup method to a custom session variable.
 * 2. Prevents pickup location methods from being set by restoring the saved method.
 */
add_action( 'woocommerce_store_api_cart_select_shipping_rate', 'prevent_forced_pickup_for_samples_shipping_method', 20, 3 );
function prevent_forced_pickup_for_samples_shipping_method( $package_id, $rate_id, $request ) {
    // Exit if this is the admin area or if we don't have a valid cart object.
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

    wc_get_logger()->warning('$rate_id: ' . print_r($rate_id, true), ['source' => 'shipping_rate_debug']);

    $chosen_methods = WC()->session->get( 'chosen_shipping_methods', [] );
    if ( count( $chosen_methods ) < 2 ) {
        return;
    }

    $current_samples_method = $chosen_methods[1];
    // Save the LABEL of the chosen samples method if it's not a pickup location
    if ( strpos( $current_samples_method, 'pickup_location:' ) === false ) {
        // Get the rates again to find the label for the current ID
        $rates_for_package1 = WC()->session->get('shipping_for_package_1')['rates'] ?? [];
        if (isset($rates_for_package1[$current_samples_method])) {
            $current_label = $rates_for_package1[$current_samples_method]->get_label();
            WC()->session->set( 'samples_shipping_method', $current_label ); // Saves Label
        }
    }

    // Check if the currently chosen method for samples IS a pickup location
    if ( strpos( $current_samples_method, 'pickup_location:' ) !== false ) {
        // Get the LABEL of the last known good non-pickup method
        $saved_method_label = WC()->session->get( 'samples_shipping_method' ); // Reads Label (Correct now)

        if ( ! empty( $saved_method_label ) ) {
            // Find the CURRENT ID for that saved LABEL in the available rates for package 1
            $rates_for_package1 = WC()->session->get('shipping_for_package_1')['rates'] ?? [];
            $rate_id_to_restore = null;
            foreach ($rates_for_package1 as $rate_id_iter => $rate_obj) {
                if ($rate_obj->get_label() === $saved_method_label) {
                    $rate_id_to_restore = $rate_id_iter;
                    break;
                }
            }

            // If we found the ID corresponding to the saved label, use it
            if ($rate_id_to_restore) {
                $chosen_methods[1] = $rate_id_to_restore; // Write the correct ID
                WC()->session->set( 'chosen_shipping_methods', $chosen_methods );
            }
            // If not found, we can't restore, let WC default (shouldn't happen often)
        }
    }

    wc_get_logger()->warning('WC()->session->get(chosen_shipping_methods): ' . print_r(WC()->session->get( 'chosen_shipping_methods'), true), ['source' => 'shipping_rate_debug']);
}

/**
 * Configures the correct default shipping method.
 *
 * @param string $default_method_id The method ID chosen by WooCommerce's default logic.
 * @param array  $package_rates   All available rates for the package.
 * @return string The new, corrected default method ID.
 */
add_filter( 'woocommerce_shipping_chosen_method', 'prevent_default_and_auto_switching_to_pickup_method', 30, 3 ); // Only 2 args now
function prevent_default_and_auto_switching_to_pickup_method( $default_method_id, $available_rates, $chosen_method ) {
    
    wc_get_logger()->warning('Chosen Method Hook - WC Default ID: ' . var_export($default_method_id, true), ['source' => 'methods_debug28']);
    wc_get_logger()->warning('$chosen_method: ' . var_export($chosen_method, true), ['source' => 'methods_debug28']);

    if ($default_method_id === '') {
        return $default_method_id;
    }

    $address_is_complete = ! empty( WC()->customer->get_shipping_postcode() ) &&
                           ! empty( WC()->customer->get_shipping_city() ) &&
                           ! empty( WC()->customer->get_shipping_state() );
                           
    $is_ship_mode = !isset($_COOKIE['is_ship_mode']) || $_COOKIE['is_ship_mode'] === 'true';

    // If the UI is in "Shipping" mode but the address is incomplete, WooCommerce 
    // will often auto-select the only available rate (Pickup) and flip the UI tab.
    // Returning an empty string stops this and keeps them in Shipping mode safely.
    if ( $is_ship_mode && ! $address_is_complete && strpos( $default_method_id, 'pickup_location:' ) === 0 ) {
        return '';
    }

    // -------------------------------------------------------------------------
    // NEW: PENDING FREIGHT QUOTE DIRECT DETECTION
    // -------------------------------------------------------------------------
    if ( function_exists('WC') && WC()->session ) {
        // Grab the original order ID safely saved during the cart load process
        $editing_order_id = WC()->session->get('editing_original_order_id');
        
        if ( $editing_order_id ) {
            $original_order = wc_get_order( $editing_order_id );
            
            // If the source order is explicitly a freight-quote, check if we need to block pickup
            if ( $original_order && 'freight-quote' === $original_order->get_status() ) {
                
                // Read what's currently active in the main chosen methods session array
                $session_chosen_methods = WC()->session->get( 'chosen_shipping_methods', [] );
                $primary_chosen_method  = isset( $session_chosen_methods[0] ) ? $session_chosen_methods[0] : '';

                // If the session doesn't contain a concrete selection yet, OR if WooCommerce is trying
                // to force a default selection because no flat rates exist, force it to empty.
                if ( empty( $primary_chosen_method ) || strpos( $default_method_id, 'pickup_location:' ) === 0 ) {
                    return ''; // Returns exactly what is needed to stay in ship mode with an unselected state
                }
            }
        }
    }
    // -------------------------------------------------------------------------

    // --- NEW: Infer package type and get correct session key ---
    //$is_ship_mode = isset($_COOKIE['is_ship_mode']) && $_COOKIE['is_ship_mode'] === 'true';
    $package_id = 0; // Assume primary (package 0) by default
    $saved_delivery_label_key = 'primary_delivery_method';
    $has_flat_rates = false;

    // Check if any rate label contains "Samples" to detect package 1
    foreach ($available_rates as $rate) {
        if (stripos($rate->get_label(), 'Samples') !== false) {
            $package_id = 1;
            $saved_delivery_label_key = 'samples_delivery_method';
            break; // Found one, no need to check further
        }
    }

  

    wc_get_logger()->warning('$is_ship_mode: ' . var_export($is_ship_mode, true), ['source' => 'methods_debug28']);
    wc_get_logger()->warning('Chosen Method Hook - Inferred Package Index: ' . $package_id, ['source' => 'methods_debug28']);

    $target_delivery_label = WC()->session->get( $saved_delivery_label_key, '' );

    
    wc_get_logger()->warning('Chosen Method Hook - Target Label from Session (' . $saved_delivery_label_key . '): ' . var_export($target_delivery_label, true), ['source' => 'methods_debug28']);
    // --- END NEW ---
    // --- MODIFIED: Loop to find ID matching the saved LABEL ---
    $found_target_id = null; // Variable to store the ID if found
    $first_flat_rate = ''; // Keep track of first flat rate for fallback if needed
    foreach ( $available_rates as $rate_id => $rate ) {
            // Check if we have a target label AND if the current rate's label matches it
        if ( ! empty($target_delivery_label) && $rate->get_label() === $target_delivery_label ) {
        $found_target_id = $rate_id; // Store the matching ID
                // Log the match
                
                break; // Exit loop once found
        }
            // Still find the first flat rate as a fallback
        //if ( $first_flat_rate === '' && strpos( $rate_id, 'flat_rate:' ) !== false ) {
        //$first_flat_rate = $rate_id;
        //}
    }
    // --- END MODIFIED LOOP ---

    // --- Prioritize returning the found target ID ---
    if ($found_target_id !== null) {
        wc_get_logger()->warning('Chosen Method Hook - Found matching ID by label: ' . var_export($found_target_id, true), ['source' => 'methods_debug28']);
        return $found_target_id; // Return the correct ID based on the saved label
    }
    // --- END ---

  // --- MODIFIED FALLBACK ---
    // If we didn't find our target label ID...
    // Fallback to whatever WC originally chose (safer default)
    wc_get_logger()->warning('Chosen Method Hook - Target label not found or no target set, using WC default: ' . var_export($default_method_id, true), ['source' => 'methods_debug28']);
    return $default_method_id;
    // --- END MODIFIED FALLBACK ---
}

/**
 * If the previously chosen method was a pickup location and the new choice is a flat rate,
 * this function overrides the new choice with the last-saved flat rate method.
 *
 * It also handles adding a temporary "LTL Freight" shipping rate for freight quotes
 * when no other flat rate options are available.
 *
 * @param array $rates   Array of calculated rates for the package.
 * @param array $package The package details.
 * @return array The potentially modified rates array.
 */
add_filter( 'woocommerce_package_rates', 'restore_last_shipping_method', 20, 2 );
function restore_last_shipping_method( $rates, $package ) {
    $package_id = $package['package_id'] ?? null;

    wc_get_logger()->warning('$package_id A: ' . print_r($package_id, true), ['source' => 'methods_debug22']);

	if ( ! isset( $package_id )/* || $package['package_id'] !== 0*/ ) {
		return $rates;
	}
	if ($package_id === 0) {
		$saved_shipping_method_key	     = 'primary_shipping_method';
		$saved_delivery_method_key	     = 'primary_delivery_method';
        $package_is_from_quote_order_key = 'primary_package_is_from_quote_order';
	} elseif ($package_id === 1) {
		$saved_shipping_method_key	     = 'samples_shipping_method';
		$saved_delivery_method_key	     = 'samples_delivery_method';
        $package_is_from_quote_order_key = 'samples_package_is_from_quote_order';
	} else {
		// Not a package we are interested in, so we exit.
		return $rates;
	}
	
	// --- STEP 1: Get all required state from the session ---
	$chosen_methods              = WC()->session->get( 'chosen_shipping_methods', [] );
	$current_choice              = isset( $chosen_methods[$package_id] ) ? $chosen_methods[$package_id] : '';
	$previous_delivery_method    = WC()->session->get( $saved_delivery_method_key, '' );
	$last_saved_flat_rate        = WC()->session->get( $saved_shipping_method_key, '' );
    $package_is_from_quote_order = WC()->session->get($package_is_from_quote_order_key, false);
    // Only trust the cookie override during a POST request (active user interaction), 
    // NOT during a GET request (passive page load/navigation).
    $is_post_request  = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
    $is_shipping_mode = isset($_COOKIE['is_ship_mode']) && $_COOKIE['is_ship_mode'] === 'true' && $is_post_request;
	// Assume we are not changing the choice by default.
	$new_choice = $current_choice;

    wc_get_logger()->warning('$chosen_methods: ' . print_r($chosen_methods, true), ['source' => 'methods_debug22']);
    //wc_get_logger()->warning('$rates: ' . print_r($rates, true), ['source' => 'methods_debug22']);
	wc_get_logger()->warning('$package_id: ' . var_export($package_id, true), ['source' => 'methods_debug22']);
	wc_get_logger()->warning('$previous_delivery_method: ' . var_export($previous_delivery_method, true), ['source' => 'methods_debug22']);
	wc_get_logger()->warning('$current_choice: ' . var_export($current_choice, true), ['source' => 'methods_debug22']);
	wc_get_logger()->warning('$last_saved_flat_rate: ' . var_export($last_saved_flat_rate, true), ['source' => 'methods_debug22']);
    wc_get_logger()->warning('$is_shipping_mode: ' . var_export($is_shipping_mode, true), ['source' => 'methods_debug22']);

    // --- NEW: Detect ACTUAL address change ---
    $address_changed = false;
    $session_address_key = 'previous_destination_' . $package_id;
    $previous_destination_snapshot = WC()->session->get( $session_address_key, null );

    // Create a snapshot of the current destination (normalized)
    $current_destination_snapshot = [
        'country' => strtolower(trim($package['destination']['country'] ?? '')),
        'state' => strtolower(trim($package['destination']['state'] ?? '')),
        'postcode' => strtolower(trim($package['destination']['postcode'] ?? '')),
        'city' => strtolower(trim($package['destination']['city'] ?? '')),
    ];

    // Compare snapshots if a previous one exists
    if ( is_array($previous_destination_snapshot) && $previous_destination_snapshot !== $current_destination_snapshot ) {
        $address_changed = true;
    }
    // --- END NEW DETECTION ---

    wc_get_logger()->warning('$address_changed: ' . var_export($address_changed, true), ['source' => 'methods_debug22']);

	// --- STEP 2: Apply your specific override condition ---
	if ($package_id === 0) {
        if (strpos( $current_choice, 'flat_rate:' ) !== false) {
            $ltl_freight_rate_id = get_shipping_rate_id_by_name($rates, 'LTL Shipping');
            if (isset($rates[$ltl_freight_rate_id])) {
                $chosen_methods = WC()->session->get('chosen_shipping_methods', array());
                $chosen_methods[ $package_id ] = $ltl_freight_rate_id;
                WC()->session->set('chosen_shipping_methods', $chosen_methods);       
            }            
        }
        if ($package_is_from_quote_order) {
            // $previous_delivery_method and $last_saved_flat_rate now contain LABELS, not IDs
            $rate_id_to_select = null;
            foreach ($rates as $rate_id => $rate) {
                if ($rate->get_label() === $previous_delivery_method) {
                    $rate_id_to_select = $rate_id;
                    break;
                }
            }
            
            if ($rate_id_to_select) {
                $new_choice = $rate_id_to_select;
            } else {
                // Fallback: if the saved label isn't found, try the "flat_rate" specific one
                foreach ($rates as $rate_id => $rate) {
                    if ($rate->get_label() === $last_saved_flat_rate) {
                        $rate_id_to_select = $rate_id;
                        break;
                    }
                }
                $new_choice = $rate_id_to_select; // Will be null if neither is found
            }
            WC()->session->set($package_is_from_quote_order_key, false);

        } else if ( empty( $previous_delivery_method ) || strpos( $previous_delivery_method, 'pickup_location:' ) !== false && strpos( $current_choice, 'flat_rate:' ) !== false ) {
            // The condition is met. Attempt to restore the last saved flat rate LABEL.
            if ( ! empty( $last_saved_flat_rate ) ) { // $last_saved_flat_rate holds a label
                $rate_id_to_select = null;
                foreach ($rates as $rate_id => $rate) {
                    if ($rate->get_label() === $last_saved_flat_rate) {
                        $rate_id_to_select = $rate_id;
                    break;
                    }
                }
                if ($rate_id_to_select) {
                    $new_choice = $rate_id_to_select; // Set choice to the found ID
                }
            }
		} // --- NEW: Restore previous label if address changed and other conditions didn't apply ---
        else if ( $address_changed && ! empty( $previous_delivery_method ) ) {
            $rate_id_to_select = null;
            $has_flat_rates = false;
            foreach ($rates as $rate_id => $rate) {
                // $previous_delivery_method holds the target LABEL
                if ($rate->get_label() === $previous_delivery_method) {
                    $rate_id_to_select = $rate_id;
                    break;
                } else if (strpos( $rate_id, 'flat_rate:' ) !== false) {
                    $has_flat_rates = true;
                }
            }
            wc_get_logger()->warning('$rate_id_to_select: ' . var_export($rate_id_to_select, true), ['source' => 'methods_debug22']);
            wc_get_logger()->warning('$has_flat_rates: ' . var_export($has_flat_rates, true), ['source' => 'methods_debug22']);
            // Only override if we found the previous label in the new rates
            if ($is_shipping_mode && !$has_flat_rates) {
                $new_choice = '';
            } else if ($rate_id_to_select) {
                $new_choice = $rate_id_to_select;
            }
            // If not found, $new_choice remains whatever it was (likely WC's default)
        } else {
            if ($is_shipping_mode && strpos( $current_choice, 'pickup_location:' ) !== false) {
                // Restore based on the last saved flat rate LABEL.
                if ( ! empty( $last_saved_flat_rate ) ) { // $last_saved_flat_rate holds a label
                    $rate_id_to_select = null;
                    foreach ($rates as $rate_id => $rate) {
                        if ($rate->get_label() === $last_saved_flat_rate) {
                            $rate_id_to_select = $rate_id;
                            break;
                        }
                    }
                    // If found, set the ID, otherwise clear the choice
                    $new_choice = $rate_id_to_select ?? '';
                } else {
                    $new_choice = '';
                }
                wc_get_logger()->warning('Ran COOKIE choice: ' . var_export(true, true), ['source' => 'methods_debug22']);
            }
        }
    } elseif ($package_id === 1) {
        if ($package_is_from_quote_order) {
            // --- Quote Load Logic (Keep As Is) ---
            $rate_id_to_select = null;
            // $last_saved_flat_rate contains label from quote
            foreach ($rates as $rate_id => $rate) {
                if ($rate->get_label() === $last_saved_flat_rate) {
                    $rate_id_to_select = $rate_id;
                    break;
                }
            }
            $new_choice = $rate_id_to_select; // Use label from quote load
            WC()->session->set($package_is_from_quote_order_key, false);
            // --- End Quote Load ---

        } else if ( $address_changed && ! empty( $previous_delivery_method ) ) {
            // --- Address Changed Logic ---
            // Try to restore the previous delivery method label specifically because the address changed
            $rate_id_to_select = null;
            foreach ($rates as $rate_id => $rate) {
                if ($rate->get_label() === $previous_delivery_method) {
                    $rate_id_to_select = $rate_id;
                    break;
                }
            }
            if ($rate_id_to_select) {
                $new_choice = $rate_id_to_select;
            }
            // If the previous label isn't found for the new address, $new_choice remains $current_choice (WC's default)
            // --- End Address Changed ---

        } else if ( empty( $previous_delivery_method ) ) {
            // --- Fallback Logic: Previous selection was empty ---
            // Try to restore the last saved flat rate label
            if ( ! empty( $last_saved_flat_rate ) ) {
                $rate_id_to_select_flat = null;
                foreach ($rates as $rate_id => $rate) {
                    if ($rate->get_label() === $last_saved_flat_rate) {
                        $rate_id_to_select_flat = $rate_id;
                        break;
                    }
                }
                if ($rate_id_to_select_flat) {
                    $new_choice = $rate_id_to_select_flat;
                }
            }
            // --- End Fallback ---

        }
        // IMPORTANT: If none of the above conditions are met (not quote load, address didn't change,
        // previous selection wasn't empty), $new_choice remains $current_choice,
        // correctly preserving manual selections or acceptable defaults during other recalculations.
    }

// --- STEP 3: Save the final state (as labels) for the NEXT request ---
    $final_choice_label = '';
    // Check if the final chosen ID exists in the current rates
    if ( ! empty( $new_choice ) && isset( $rates[ $new_choice ] ) ) {
        $final_choice_label = $rates[ $new_choice ]->get_label();
    }

    if ( ! empty( $final_choice_label ) ) {
        // Always save the label of the final chosen rate (could be any type)
        WC()->session->set( $saved_delivery_method_key, $final_choice_label );

        // If the final choice was a flat rate, also save its label specifically
        // Note: Check based on $new_choice (ID) still, before saving the label
        if ( strpos( $new_choice, 'flat_rate:' ) !== false ) {
            WC()->session->set( $saved_shipping_method_key, $final_choice_label );
        }
    } else {
        // If no choice was made or found, clear the saved labels
        WC()->session->set( $saved_delivery_method_key, '' );
        WC()->session->set( $saved_shipping_method_key, '' );
    }

	// --- STEP 4: Recalculate if we changed something ---
	if ( $new_choice !== $current_choice ) {
		$chosen_methods[$package_id] = $new_choice;
		WC()->session->set( 'chosen_shipping_methods', $chosen_methods );
	}
    
    wc_get_logger()->warning('$chosen_methods B: ' . print_r($chosen_methods, true), ['source' => 'methods_debug22']);

    // --- NEW: Save current destination for next cycle's comparison ---
    // Do this even if address didn't change this cycle, to prime the next check
    WC()->session->set( $session_address_key, $current_destination_snapshot );
    
	return $rates;
}











/**
 * NEW FUNCTION
 * Catches the "Ship -> Pickup -> Ship" race condition right after the stale API request
 * (selectShippingRate for 'pickup_location') tries to update the session.
 * It uses the UP-TO-DATE cookie from the *final* "Ship" click to correct the session.
 */
add_action( 'woocommerce_store_api_cart_update_order_from_request', 'fix_shipping_rate_race_condition', 10, 2 );
function fix_shipping_rate_race_condition( $cart, $request ) {
    // Check if this is a shipping rate selection request
    $data = $request->get_json_params();
    $is_shipping_mode = isset( $_COOKIE['is_ship_mode'] ) && $_COOKIE['is_ship_mode'] === 'true';

    wc_get_logger()->warning('$data: ' . print_r($data, true), ['source' => 'methods_debug27']);
    wc_get_logger()->warning('$is_shipping_mode: ' . var_export($is_shipping_mode, true), ['source' => 'methods_debug27']);

}


    ///////////////////////////////
    // --- UTILITY FUNCTIONS --- //
    ///////////////////////////////

/**
 * Finds a shipping rate ID in a package by its label (name).
 *
 * @param array  $rates Array of available shipping rates for a package.
 * @param string $name  The label of the shipping rate to find.
 * @return string|null The rate ID if found, otherwise null.
 */
function get_shipping_rate_id_by_name( $rates, $name ) {
    foreach ( $rates as $rate_id => $rate ) {
        if ( $rate->get_label() === $name ) {
            return $rate_id;
        }
    }
    return null;
}

// Function to filter and then retrieve shipping methods between Linear Ft and Samples products
function get_shipping_method_ids($rates) {
	$samples_standard_method_name = 'Samples Standard Shipping';
    $samples_method_ids = [];
	$samples_standard_method_id = '';
	$linear_ft_method_ids = [];
    foreach ($rates as $method_id => $rate) {
        if (isset($rate->label) && stripos($rate->label, 'Samples') !== false) {
            $samples_method_ids[] = $method_id;
			if (stripos($rate->label, $samples_standard_method_name) !== false) {
				$samples_standard_method_id = $method_id;
			}
        } else {
			$linear_ft_method_ids[] = $method_id;
		}
    }
    return [$linear_ft_method_ids, $samples_method_ids, $samples_standard_method_id];
}

function is_cart_item_a_sample($item) {
	// Handle cart item arrays
	if (is_array($item) && isset($item['data'])) {
		$product         = $item['data'];
		$product_name    = $product->get_name();
		$linear_feet     = intval($item['linear_feet_actual'] ?? 0);
		$lengths         = sanitize_text_field($item['length'] ?? '');
		$rabbet_position = sanitize_text_field($item['first_rabbet'] ?? '');
		$relief_angle    = sanitize_text_field($item['reliefangle_actual'] ?? '');
		$species         = sanitize_text_field($item['species_actual'] ?? '');
		$finish_option   = sanitize_text_field($item['finish_actual'] ?? '');
		$sample          = sanitize_text_field($item['sample'] ?? '');
	} else {
		return false;
	}

	return (
		stripos($product_name, '(sample)') !== false &&
		$linear_feet === 0 &&
		$lengths === '' &&
		$rabbet_position === '' &&
		$relief_angle === '' &&
		$species === '' &&
		$finish_option === '' &&
		$sample === '1'
	);
}

function is_order_item_a_sample($item) {
	// Handle WC_Order_Item_Product objects (from the final order)
	if ($item instanceof WC_Order_Item_Product) {
		$product_name    = $item->get_name();
		$linear_feet     = intval($item->get_meta('linear_feet_actual', true));
		$lengths         = sanitize_text_field($item->get_meta('length', true));
		$rabbet_position = sanitize_text_field($item->get_meta('first_rabbet', true));
		$relief_angle    = sanitize_text_field($item->get_meta('reliefangle_actual', true));
		$species         = sanitize_text_field($item->get_meta('species_actual', true));
		$finish_option   = sanitize_text_field($item->get_meta('finish_actual', true));
		$sample          = sanitize_text_field($item->get_meta('sample', true));
	} else {
		return false;
	}

	return (
		stripos($product_name, '(sample)') !== false &&
		$linear_feet === 0 &&
		$lengths === '' &&
		$rabbet_position === '' &&
		$relief_angle === '' &&
		$species === '' &&
		$finish_option === '' &&
		$sample === '1'
	);
}

function get_samples_destination($order = null) {
    if ($order !== null) {
        $samples_address = $order->get_meta('_samples_full_shipping_address', true);

        if (empty($samples_address)) {
            if ( is_null( WC()->session ) ) return false;
            // Fallback to session if no address is found in the order meta.
            $samples_address = WC()->session->get('samples_full_shipping_address');
        }
    } else {
        $samples_address = WC()->session->get('samples_full_shipping_address');
    }

	if (!empty($samples_address['state']) && !empty($samples_address['postcode'])) {
		return [
			'country'   => $samples_address['country'] ?? 'US',
			'state'     => $samples_address['state'],
			'postcode'  => $samples_address['postcode'],
			'city'      => $samples_address['city'] ?? '',
			'address'   => $samples_address['address_1'] ?? '',
			'address_2' => $samples_address['address_2'] ?? '',
		];
	}
	return false;
}

function get_samples_shipping_method($order = null) {
    if ($order !== null) {
        $samples_shipping_method = $order->get_meta('_samples_shipping_method', true);

        if (empty($samples_shipping_method)) {
            if ( ! isset( WC()->session ) || is_null( WC()->session ) ) {
                return '';
            }
            // Fallback to session if no address is found in the order meta.
            $chosen_methods = WC()->session->get('chosen_shipping_methods');
            $samples_shipping_method = $chosen_methods[1] ?? '';
        }
    } else {
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        $samples_shipping_method = $chosen_methods[1] ?? '';
    }
    return $samples_shipping_method;
}

    ///////////////////
    // --- TAXES --- //
    ///////////////////

/**
 * HELPER: Get Live Payment Context (Method & Terms)
 * Bypasses stale session data by reading directly from the Store API payload.
 * This guarantees 100% accurate taxes even if the user clicks Place Order instantly.
 */
function starke_get_live_payment_context() {
    $context = [
        'method' => '',
        'term'   => 'no_terms'
    ];

    // 1. Default to Session
    if ( function_exists('WC') && WC()->session ) {
        $context['method'] = WC()->session->get('chosen_payment_method');
        $context['term']   = WC()->session->get('starke_payment_terms') ?: 'no_terms';
    }

    // 2. Override with Live Store API Payload (Highest Priority)
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    if ( strpos( $uri, '/wc/store/' ) !== false || strpos( $uri, '/wc-ajax' ) !== false ) {
        $request_body = file_get_contents( 'php://input' );
        if ( ! empty( $request_body ) ) {
            $payload = json_decode( $request_body, true );
            
            // Check Payment Method
            if ( ! empty( $payload['payment_method'] ) ) {
                $context['method'] = sanitize_key( $payload['payment_method'] );
            }
            
            // Check Payment Terms (in extensions payload)
            if ( ! empty( $payload['extensions']['vern_shipping_block']['starke_payment_terms'] ) ) {
                $context['term'] = sanitize_key( $payload['extensions']['vern_shipping_block']['starke_payment_terms'] );
            }
        }
    } 
    // 3. Fallback for Classic POST
    elseif ( isset( $_POST['payment_method'] ) ) {
        $context['method'] = sanitize_key( $_POST['payment_method'] );
    }

    // --- NEW SECURITY CHECK: Enforce Samples Only ---
    if ( function_exists('WC') && WC()->cart && ! WC()->cart->is_empty() ) {
        $is_samples_only = true;
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( ! function_exists('is_cart_item_a_sample') || ! is_cart_item_a_sample( $cart_item ) ) {
                $is_samples_only = false;
                break;
            }
        }
        if ( $is_samples_only ) {
            $context['term'] = 'no_terms';
        }
    }

    // --- NEW SECURITY CHECK: Validate Assigned Term for Standard Orders ---
    if ( $context['term'] !== 'no_terms' ) {
        $user_id = get_current_user_id();
        if ( $user_id ) {
            $assigned = get_user_meta( $user_id, '_starke_assigned_payment_term', true );
            if ( $context['term'] !== $assigned ) {
                $context['term'] = empty( $assigned ) ? 'no_terms' : $assigned;
            }
        } else {
            $context['term'] = 'no_terms'; // Guests get no terms
        }
    }

    return $context;
}

/**
 * HOOK: woocommerce_store_api_checkout_order_processed
 * This is the definitive final step for the block-based checkout. It acts as a
 * failsafe that runs BEFORE payment is processed, correcting all taxes on the
 * final order to prevent any "flattening" issues and ensure the convenience
 * fee tax is included.
 */
add_action('woocommerce_store_api_checkout_order_processed', 'sm_adjust_order_taxes', 10, 1);
function sm_adjust_order_taxes($order) {
    if (!$order) {
        return;
    }

    // Do not recalculate standard taxes for Invoice/Balance orders (orders with a parent).
    // These orders only need the Fee Tax (which is handled separately by sm_apply_fee_before_payment).
    if ( $order->get_parent_id() ) {
        return;
    }

    // --- Get settings needed for all calculations ---
    $items_changed = false;
    
    // Get convenience fee settings
    $chosen_payment_method = $order->get_payment_method();
    $payment_term = $order->get_meta('_starke_payment_terms', true);

    // --- NEW SECURITY CHECK: Enforce Samples Only ---
    $is_samples_only = true;
    $line_items = $order->get_items('line_item');
    if ( sizeof( $line_items ) > 0 ) {
        foreach ( $line_items as $item ) {
            if ( ! function_exists('is_order_item_a_sample') || ! is_order_item_a_sample( $item ) ) {
                $is_samples_only = false;
                break;
            }
        }
    } else {
        $is_samples_only = false;
    }

    $has_standard_items = !$is_samples_only;

    if ( $is_samples_only ) {
        $payment_term = 'no_terms';
    } else {
        // --- NEW SECURITY CHECK: Validate Assigned Term for Standard Orders ---
        if ( $payment_term !== 'no_terms' ) {
            $user_id = $order->get_customer_id();
            if ( $user_id ) {
                $assigned = get_user_meta( $user_id, '_starke_assigned_payment_term', true );
                if ( $payment_term !== $assigned ) {
                    $payment_term = empty( $assigned ) ? 'no_terms' : $assigned;
                }
            } else {
                $payment_term = 'no_terms';
            }
        }
    }

    $fee_percentage = 0;

    if ('stripe_cc' === $chosen_payment_method) {
        $options = get_option('starke_commerce_options');
        $base_percent = isset($options['card_convenience_fee']) ? floatval($options['card_convenience_fee']) / 100 : 0;

        if ( 'net_30' === $payment_term ) {
            $fee_percentage = 0;
        } elseif ( '50_50' === $payment_term ) {
            $fee_percentage = $base_percent / 2;
        } else {
            $fee_percentage = $base_percent;
        }
    }

    // Get tax rates for both potential destinations
    $sample_destination = get_samples_destination($order);
    $taxjar = new Starke_TaxJar_API();
    $sample_tax_rates = $sample_destination ? $taxjar->get_formatted_rate_array($sample_destination) : []; // Samples never picked up
    
    $standard_dest = [
        'country'  => $order->get_shipping_country() ?: 'US',
        'state'    => $order->get_shipping_state(),
        'postcode' => $order->get_shipping_postcode(),
        'city'     => $order->get_shipping_city(),
        'street'   => $order->get_shipping_address_1(),
    ];

    // YOUR FIX: Simply read the exact address we safely saved to the order meta during checkout!
    $saved_pickup_address = $order->get_meta('_standard_pickup_address', true);

    if ( !empty($saved_pickup_address) && is_array($saved_pickup_address) ) {
        $standard_dest = [
            'country'  => $saved_pickup_address['country'] ?? 'US',
            'state'    => $saved_pickup_address['state'] ?? '',
            'postcode' => $saved_pickup_address['postcode'] ?? '',
            'city'     => $saved_pickup_address['city'] ?? ''
        ];
    }

    $standard_tax_rates = $taxjar->get_formatted_rate_array($standard_dest);

    // --- 1. Correct the tax for ALL product line items (samples and standard) ---
    foreach ($order->get_items('line_item') as $item_id => $item) {
        $tax_rates_for_item = [];
        
        if (is_order_item_a_sample($item) && $has_standard_items) {
            $tax_rates_for_item = $sample_tax_rates;
        } else {
            $tax_rates_for_item = $standard_tax_rates;
        }

        if (!empty($tax_rates_for_item)) {
            $base_amount_for_tax = $item->get_total(); // get_total() on an order item is the pre-tax line total
            
            if ($fee_percentage > 0) {
                $fee_on_item = $base_amount_for_tax * $fee_percentage;
                $base_amount_for_tax += $fee_on_item;
            }
            
            $correct_taxes = WC_Tax::calc_tax($base_amount_for_tax, $tax_rates_for_item, false);
            
            $item->set_taxes(['total' => $correct_taxes, 'subtotal' => $correct_taxes]);
            $item->save();
            $items_changed = true;
        }
    }

    // --- 2. Correct the tax for ALL shipping line items ---
    $sample_shipping_rate_id = get_samples_shipping_method($order);
    foreach ($order->get_items('shipping') as $item_id => $shipping_item) {
        $tax_rates_for_shipping = [];
        $method_id_in_order = $shipping_item->get_method_id() . ':' . $shipping_item->get_instance_id();

        if ($sample_shipping_rate_id && $method_id_in_order === $sample_shipping_rate_id && $has_standard_items) {
            $tax_rates_for_shipping = $sample_tax_rates;
        } else {
            $tax_rates_for_shipping = $standard_tax_rates;
        }

        if (!empty($tax_rates_for_shipping)) {
            $base_amount_for_tax = $shipping_item->get_total();

            if ($fee_percentage > 0) {
                $fee_on_shipping = $base_amount_for_tax * $fee_percentage;
                $base_amount_for_tax += $fee_on_shipping;
            }
            
            $correct_shipping_taxes = WC_Tax::calc_shipping_tax($base_amount_for_tax, $tax_rates_for_shipping);
            
            $shipping_item->set_taxes(['total' => $correct_shipping_taxes]);
            $shipping_item->save();
            $items_changed = true;
        }
    }

    // --- 3. If we made any changes, force the order to recalculate its grand totals ---
    if ($items_changed) {
        $order->update_taxes();
        $order->calculate_totals(false); // false prevents re-calculating taxes, we just want to sum the new totals
    }
}

// --- Admin Order Display --- START
/**
 * HOOK: woocommerce_order_item_get_taxes
 * This function is modified to prevent on-the-fly tax recalculation for display.
 * It now directly returns the tax data that was saved with the order item,
 * ensuring that historical order data remains accurate even if tax rates change.
 *
 * @param array         $taxes  The original tax data array from the database.
 * @param WC_Order_Item $item   The order item object.
 * @return array                The original, unmodified tax data array.
 */
add_filter( 'woocommerce_order_item_get_taxes', 'adjust_admin_order_line_item_tax_display', 10, 2 );
function adjust_admin_order_line_item_tax_display( $taxes, $item ) {
    return $taxes;
}

/**
 * HOOK: woocommerce_order_get_tax_totals
 * This function is modified to build the tax summary totals based on the
 * actual tax amounts saved on each line item in the order.
 *
 * @param array    $tax_totals  The array of tax total objects.
 * @param WC_Order $order       The order object.
 * @return array                The corrected array of tax total objects.
 */
add_filter( 'woocommerce_order_get_tax_totals', 'adjust_admin_order_tax_totals_display', 10, 2 );
function adjust_admin_order_tax_totals_display( $tax_totals, $order ) {
    // Optional: You can keep this check if you only want this logic to run on complex orders.
    // If you want it to run for all orders to be safe, you can remove this if-statement.
    if ( count( $order->get_taxes() ) <= 1 && !is_admin() ) {
        return $tax_totals;
    }

    $new_tax_totals = [];
    $summed_taxes   = [];

    // The logic has been simplified. Instead of singling out "sample" items for
    // recalculation, we now loop through ALL items in the order ('line_item', 'shipping', 'fee')
    // and sum up the tax amounts that are already stored on them.

    foreach ( $order->get_items( [ 'line_item', 'shipping', 'fee' ] ) as $item ) {
        // For every item, trust the tax data already saved on it.
        $item_taxes = $item->get_taxes()['total'] ?? [];

        // Add the stored taxes to our summary array.
        foreach ( $item_taxes as $tax_rate_id => $tax_amount ) {
            if ( ! isset( $summed_taxes[ $tax_rate_id ] ) ) {
                $summed_taxes[ $tax_rate_id ] = 0;
            }
            $summed_taxes[ $tax_rate_id ] += (float) $tax_amount;
        }
    }

    // Build a new, correct array of tax total objects from the summed stored taxes.
    foreach ( $summed_taxes as $rate_id => $amount ) {
        $code = WC_Tax::get_rate_code( $rate_id );
        if ( ! $code ) {
            continue;
        }

        $new_tax_totals[ $code ] = (object) [
            'rate_id'          => $rate_id,
            'is_compound'      => WC_Tax::is_compound( $rate_id ),
            'label'            => WC_Tax::get_rate_label( $rate_id ),
            'amount'           => wc_round_tax_total($amount),
            'formatted_amount' => wc_price( wc_round_tax_total($amount), [ 'currency' => $order->get_currency() ] ),
        ];
    }
    return $new_tax_totals;
}

/**
 * ADMIN DISPLAY: Split "Order Total" vs "Amount Charged"
 * FIXED VERSION: Uses Specific IDs and General Sibling CSS for guaranteed hiding.
 */
add_action( 'woocommerce_admin_order_totals_after_tax', 'starke_admin_inject_custom_totals_rows', 10, 1 );

function starke_admin_inject_custom_totals_rows( $order_id ) {
    $order = wc_get_order( $order_id );
    
    // We return early to show standard WooCommerce totals instead of the Charged/Paid rows.
    $quote_statuses = array( 
        'active-quote', 
        'expired-quote', 
        'pending-quote', 
        'freight-quote', 
        'deleted-quote', 
        'ordered-quote', 
        'profiles-ready'
    );

    if ( in_array( $order->get_status(), $quote_statuses ) ) {
        return;
    }

    // 2. Check for Deferred Balance
    // (Check removed so standard orders are included too)
    $deferred = (float) $order->get_meta( '_starke_deferred_balance', true );

    // --- FIX: Use Stored Natural Total as Source of Truth ---
    $natural_total = (float) $order->get_meta( '_starke_natural_total', true );
    
    // Fallback for older orders
    if ( $natural_total <= 0 ) {
        $natural_total = (float) $order->get_total() + $deferred;
    }

    // 3. Calculate "Amount Charged" (Billed)
    // Rule: If status is 'pending', 'failed', or 'cancelled', the customer never committed.
    // Therefore, Charged = 0.
    if ( $order->has_status( array( 'pending', 'failed', 'cancelled' ) ) ) {
        $amount_charged = 0.00;
    } else {
        // They committed (Processing, Completed, or On-Hold/Check)
        $amount_charged = $natural_total - $deferred;
    }

    // 4. Calculate "Amount Paid" (Collected)
    // Only count as paid if the money is secured (Processing/Completed)
    // Note: 'On-Hold' (Net 30 / Check) is Charged, but NOT Paid.
    $amount_paid = $order->is_paid() ? $amount_charged : 0.00;

    // --- RENDER ROWS ---
    ?>
    <tr class="starke-custom-row">
        <td class="label">
            <strong><?php esc_html_e( 'Order Total:', 'woocommerce' ); ?></strong>
        </td>
        <td width="1%"></td>
        <td class="total">
            <?php echo wc_price( $natural_total, array( 'currency' => $order->get_currency() ) ); ?>
        </td>
    </tr>

    <tr class="starke-custom-row" id="starke-anchor-row">
        <td class="label">
            <?php esc_html_e( 'Amount Charged:', 'woocommerce' ); ?>
        </td>
        <td width="1%"></td>
        <td class="total">
            <?php echo wc_price( $amount_charged, array( 'currency' => $order->get_currency() ) ); ?>
        </td>
    </tr>

    <tr class="starke-custom-row">
        <td class="label">
            <?php esc_html_e( 'Amount Paid:', 'woocommerce' ); ?>
        </td>
        <td width="1%"></td>
        <td class="total">
            <?php echo wc_price( $amount_paid, array( 'currency' => $order->get_currency() ) ); ?>
        </td>
    </tr>

    <style>
        /* GUARANTEED HIDER:
           1. Select 'starke-anchor-row' (Our Amount Charged Row).
           2. Look for ANY sibling row (~) that follows it.
           3. If that sibling is NOT one of our custom rows (:not(.starke-custom-row))...
           4. ...HIDE IT.
        */
        #starke-anchor-row ~ tr:not(.starke-custom-row) {
            display: none !important;
        }
    </style>
    <?php
}
// --- Admin Order Display --- END

/**
 * HOOK: woocommerce_calculate_totals
 * Adjusts the cart's final ITEM TAX amount for the sample products and
 * directly overwrites the tax values on the cart items themselves. This ensures
 * the correct data is used when the order is created.
 *
 * @param WC_Cart $cart The cart object.
 */
add_action('woocommerce_calculate_totals', 'sm_adjust_cart_item_tax', 20, 1);
function sm_adjust_cart_item_tax($cart) {
    if (class_exists('Quote_Lock_Controller') && Quote_Lock_Controller::get_instance()->is_quote_locked()) {
        $locked_quote = WC()->session->get('locked_quote_data');
        if (!empty($locked_quote['is_fully_locked'])) {
            return; 
        }
    }
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }
    if ( $cart->is_empty() ) {
        return;
    }

    $sample_items_keys = [];
    $has_standard_items = false;
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        if (function_exists('is_cart_item_a_sample') && is_cart_item_a_sample($cart_item)) {
            $sample_items_keys[] = $cart_item_key;
        } else {
            $has_standard_items = true;
        }
    }
    
    $taxjar = new Starke_TaxJar_API();
    $sample_destination = function_exists('get_samples_destination') ? get_samples_destination() : false;
    $tax_rates_for_samples = $sample_destination ? $taxjar->get_formatted_rate_array($sample_destination) : [];
    $chosen_methods = WC()->session->get('chosen_shipping_methods', []);
    
    $standard_destination = [
        'country'  => WC()->customer->get_shipping_country() ?: 'US',
        'state'    => WC()->customer->get_shipping_state(),
        'postcode' => WC()->customer->get_shipping_postcode(),
        'city'     => WC()->customer->get_shipping_city(),
        'street'   => WC()->customer->get_shipping_address(),
    ];
    
    // --- NEW: Dynamic Pickup Override for Standard ---
    $primary_method = $chosen_methods[0] ?? '';
    if ( strpos( $primary_method, 'pickup_location:' ) === 0 ) {
        $standard_destination = starke_get_pickup_location_address( $primary_method, $standard_destination );
    }
    $tax_rates_for_standard_items = $taxjar->get_formatted_rate_array($standard_destination);

    $live_context = starke_get_live_payment_context();
    $chosen_payment_method = $live_context['method'];
    $payment_term = $live_context['term'];
    $fee_percentage = 0;

    if ('stripe_cc' === $chosen_payment_method) {
        $options = get_option('starke_commerce_options');
        $base_percent = isset($options['card_convenience_fee']) ? floatval($options['card_convenience_fee']) / 100 : 0;

        if ( 'net_30' === $payment_term ) {
            $fee_percentage = 0; 
        } elseif ( '50_50' === $payment_term ) {
            $fee_percentage = $base_percent / 2; 
        } else {
            $fee_percentage = $base_percent; 
        }
    }

    $total_correct_sample_tax = 0;
    $total_incorrect_sample_tax = 0;

    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        if (in_array($cart_item_key, $sample_items_keys) && $has_standard_items) {
            $item_correct_taxes = [];
            if (!empty($tax_rates_for_samples)) {
                $base_amount_for_tax = $cart_item['line_total'];

                if ($fee_percentage > 0) {
                    $fee_on_item = $cart_item['line_total'] * $fee_percentage;
                    $base_amount_for_tax += $fee_on_item;
                }

                $item_correct_taxes = WC_Tax::calc_tax($base_amount_for_tax, $tax_rates_for_samples, false);
            }

            $correct_tax_for_this_item = array_sum($item_correct_taxes);
            $cart->cart_contents[$cart_item_key]['line_tax'] = $correct_tax_for_this_item;
			$cart->cart_contents[$cart_item_key]['line_subtotal_tax'] = $correct_tax_for_this_item;
            $cart->cart_contents[$cart_item_key]['line_tax_data'] = ['total' => $item_correct_taxes, 'subtotal' => $item_correct_taxes];

            $total_correct_sample_tax += $correct_tax_for_this_item;
            $total_incorrect_sample_tax += $cart_item['line_tax']; 
        } else {
            if (!empty($tax_rates_for_standard_items)) {
                $base_amount_for_tax = $cart_item['line_total'];

                if ($fee_percentage > 0) {
                    $fee_on_item = $cart_item['line_total'] * $fee_percentage;
                    $base_amount_for_tax += $fee_on_item;
                } 

                $item_correct_taxes = WC_Tax::calc_tax($base_amount_for_tax, $tax_rates_for_standard_items, false);
                $correct_tax_for_this_item = array_sum($item_correct_taxes);
                $cart->cart_contents[$cart_item_key]['line_tax'] = $correct_tax_for_this_item;
                $cart->cart_contents[$cart_item_key]['line_subtotal_tax'] = $correct_tax_for_this_item;
                $cart->cart_contents[$cart_item_key]['line_tax_data'] = ['total' => $item_correct_taxes, 'subtotal' => $item_correct_taxes];
            }
        }
    }		
}

/**
 * HOOK: woocommerce_shipping_rate_taxes
 * Calculates taxes for shipping rates using metadata prepared in the
 * customize_and_conditionally_hide_shipping_methods function.
 *
 * @param array        $taxes Array of taxes for the shipping rate.
 * @param WC_Shipping_Rate $rate  The shipping rate object.
 * @return array The potentially modified taxes array.
 */
add_filter( 'woocommerce_shipping_rate_taxes', 'sm_adjust_shipping_item_tax', 10, 2 );
function sm_adjust_shipping_item_tax( $taxes, $rate ) {
    if ( is_null( WC()->session ) ) {
        return $taxes; 
    }
    if (class_exists('Quote_Lock_Controller') && Quote_Lock_Controller::get_instance()->is_quote_locked()) {
        $locked_quote = WC()->session->get('locked_quote_data');
        if (!empty($locked_quote['is_fully_locked'])) {
            return $taxes; 
        }
    }
    $live_context = starke_get_live_payment_context();
    $chosen_payment_method = $live_context['method'];
    $payment_term = $live_context['term'];
    $fee_percentage = 0;

    if ('stripe_cc' === $chosen_payment_method) {
        $options = get_option('starke_commerce_options');
        $base_percent = isset($options['card_convenience_fee']) ? floatval($options['card_convenience_fee']) / 100 : 0;

        if ( 'net_30' === $payment_term ) {
            $fee_percentage = 0;
        } elseif ( '50_50' === $payment_term ) {
            $fee_percentage = $base_percent / 2;
        } else {
            $fee_percentage = $base_percent;
        }
    }

    $all_meta = $rate->get_meta_data();
    $raw_tax_meta   = isset($all_meta['_destination_tax_rates']) ? $all_meta['_destination_tax_rates'] : null;
    $tax_rates_to_use = []; 

    if ( is_string($raw_tax_meta) && !empty($raw_tax_meta) ) {
        $decoded = json_decode( $raw_tax_meta, true );
        if ( is_array($decoded) ) {
            $tax_rates_to_use = $decoded;
        }
    } elseif ( is_array($raw_tax_meta) ) {
        $tax_rates_to_use = $raw_tax_meta;
    }

    if (empty($tax_rates_to_use) && $fee_percentage === 0.0) {
        return [];
    }
    
    if (empty($tax_rates_to_use)) {
        return [];
    }

    $shipping_cost = $rate->cost;
    $taxable_amount = $shipping_cost;
    if ( $fee_percentage > 0 ) {
        $taxable_amount += ( $shipping_cost * $fee_percentage );
    }    
    $calculated_shipping_tax = WC_Tax::calc_shipping_tax( $taxable_amount, $tax_rates_to_use );

    return $calculated_shipping_tax;
}

/**
 * HOOK: woocommerce_calculated_total
 * This filter runs at the very end. We use it to strictly enforce the
 * "Amount Due Today" based on the selected Payment Term.
 */
add_filter('woocommerce_calculated_total', 'sm_set_updated_grand_total', 10, 2);
function sm_set_updated_grand_total($total, $cart) {
    if ( is_null( WC()->session ) ) {
        return $total; 
    }
    // 1. Get the final tax for all items (already corrected by sm_adjust_cart_item_tax).
    $final_contents_tax = 0;
    foreach ($cart->get_cart() as $item) {
        $final_contents_tax += $item['line_tax'];
    }
    
    // 2. Get the final tax for shipping.
    $final_shipping_tax = array_sum($cart->get_shipping_taxes());

    // 3. Set the definitive tax totals on the main cart object for display purposes.
    $cart->set_cart_contents_tax($final_contents_tax);
    $cart->set_total_tax($final_contents_tax + $final_shipping_tax);
    
    // 4. Calculate the "Natural Grand Total" (The full value of the order)
    $natural_grand_total = 
        $cart->get_cart_contents_total() +
        $cart->get_shipping_total() +
        $cart->get_fee_total() +
        $cart->get_total_tax();

    // --- NEW: Save Natural Total to Session (Single Source of Truth) ---
    // We save this here so the React Block can read it directly without re-calculating.
    if ( WC()->session ) {
        WC()->session->set( 'starke_natural_total', $natural_grand_total );
    }

    // --- Payment Terms Logic ---
    if ( WC()->session ) {
        $live_context = starke_get_live_payment_context();
        $term = $live_context['term'];
        
        // Threshold Check: Only apply terms if order is >= $50
        if ( $natural_grand_total >= 50 ) {
            
            if ( 'net_30' === $term ) {
                WC()->session->set( 'starke_deferred_total', $natural_grand_total );
                return 0; 
            } 
            elseif ( '50_50' === $term ) {
                // FIX: Convert to integer cents first to bypass PHP floating-point imprecision
                $total_cents = (int) round( $natural_grand_total * 100 );
                
                // Divide by 2 and round half up to get exact cents
                $half_cents  = (int) round( $total_cents / 2 );
                
                // Convert back to standard dollars/decimals
                $half_amount     = $half_cents / 100;
                $deferred_amount = ( $total_cents - $half_cents ) / 100;
                
                WC()->session->set( 'starke_deferred_total', $deferred_amount );
                return $half_amount;
            }
        }
    }
    
    if ( WC()->session ) {
        WC()->session->set( 'starke_deferred_total', 0 );
    }
    
    return $natural_grand_total;
}

/**
 * HELPER: Get Dynamic Pickup Location Address
 * Pulls the specific physical address of a chosen native Blocks local pickup location.
 */
function starke_get_pickup_location_address( $method_id, $fallback_dest ) {
    $parts = explode( ':', $method_id );
    $location_id = end( $parts );

    // Target the exact option key used by WooCommerce Blocks
    $raw_locations = get_option( 'pickup_location_pickup_locations', [] );

    $locations = [];
    if ( is_string($raw_locations) ) {
        $locations = json_decode($raw_locations, true) ?: [];
    } elseif ( is_array($raw_locations) ) {
        if ( isset($raw_locations['pickup_locations']) ) {
            $locations = $raw_locations['pickup_locations'];
        } else {
            $locations = $raw_locations;
        }
    }
    
    // Try to match the ID
    foreach ( $locations as $index => $location ) {
        // In WooCommerce Blocks, the ID is sometimes just the array index
        $current_id = isset($location['id']) ? $location['id'] : $index;
        
        if ( (string) $current_id === (string) $location_id || (string) $index === (string) $location_id ) {
            return [
                'country'  => !empty($location['address']['country']) ? $location['address']['country'] : 'US',
                'state'    => $location['address']['state'] ?? '',
                'postcode' => $location['address']['postcode'] ?? '',
                'city'     => $location['address']['city'] ?? '',
                'street'   => $location['address']['address_1'] ?? ''
            ];
        }
    }

    // No match found, fall back to original destination
    return $fallback_dest;
}

/**
 * ==============================================================================
 * STARKE PAYMENT FEES (INVOICE & CHECKOUT COMPATIBILITY)
 * ==============================================================================
 */

// 1. CONSTANT: Define Fee Percentage (Single Source of Truth)
function sm_get_cc_fee_percentage() {
    $options = get_option( 'starke_commerce_options' );
    return isset( $options['card_convenience_fee'] ) ? floatval( $options['card_convenience_fee'] ) : 0;
}

// 2. HELPER: Calculate Fee Amount based on a specific total
function sm_calculate_fee_from_total( $amount_to_charge ) {
    $percent = sm_get_cc_fee_percentage();
    if ( $percent <= 0 || $amount_to_charge <= 0 ) return 0;
    
    // Force exact 2-decimal rounding to prevent 1-cent total discrepancies
    return round( ( $amount_to_charge * $percent ) / 100, 2 );
}

// 3. HELPER: Get Total Estimated Tax for Visual Display (Logic B)
function sm_get_total_tax_amount_for_fee( $fee_amount, $order ) {
    $parent_id = $order->get_parent_id();
    if ( ! $parent_id ) return 0;

    $parent = wc_get_order( $parent_id );
    if ( ! $parent ) return 0;

    $parent_total_tax = $parent->get_total_tax();

    // Use Natural Total to ensure the rate is calculated against the 100% value
    $natural_total = $parent->get_meta( '_starke_natural_total', true );
    
    if ( ! empty( $natural_total ) && is_numeric( $natural_total ) ) {
        $parent_net_total = (float)$natural_total - $parent_total_tax;
    } else {
        $parent_net_total = $parent->get_total() - $parent_total_tax;
    }

    if ( $parent_net_total <= 0 ) return 0;

    $effective_rate = $parent_total_tax / $parent_net_total;
    
    // Force exact 2-decimal rounding to prevent 1-cent total discrepancies
    return round( $fee_amount * $effective_rate, 2 );
}

/**
 * LOGIC A: Standard Checkout (Block/Cart Based)
 */
add_action( 'woocommerce_cart_calculate_fees', 'sm_add_fee_to_cart_checkout' );
function sm_add_fee_to_cart_checkout( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
    if ( is_wc_endpoint_url( 'order-pay' ) ) return; 

    $live_context = starke_get_live_payment_context();
    $method = $live_context['method'];
    
    if ( 'stripe_cc' === $method ) {
        $base_amount = $cart->get_subtotal() + $cart->get_shipping_total();
        $term = $live_context['term'];

        if ( 'net_30' === $term ) {
            $base_amount = 0; 
        } elseif ( '50_50' === $term ) {
            // Sync the fee base with the rounded deposit
            $base_amount = round( $base_amount / 2, 2 );
        }
        
        $fee = sm_calculate_fee_from_total( $base_amount );
        
        if ( $fee > 0 ) {
            $cart->add_fee( __( 'Card Convenience Fee', 'vern_shipping_block' ), $fee, false );
        }
    }
}

/**
 * LOGIC B: Invoice / Order Pay Page (And Confirmation Page)
 */
add_filter( 'woocommerce_get_order_item_totals', 'sm_visual_fee_on_invoice_load', 10, 3 );
function sm_visual_fee_on_invoice_load( $total_rows, $order, $tax_display ) {
    // 1. Allow this to run on BOTH the Pay page and the Order Confirmation page
    if ( ! is_wc_endpoint_url( 'order-pay' ) && ! is_wc_endpoint_url( 'order-received' ) ) {
        if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            return $total_rows;
        }
    }

    // Standard checkout orders already have the fee permanently saved to the database, 
    // so WooCommerce natively displays it. We only want to manually inject our custom fee row 
    // on the Confirmation Page if this is a Balance Invoice (which scrubs its native rows).
    if ( is_wc_endpoint_url( 'order-received' ) && 'yes' !== $order->get_meta( '_starke_is_balance_invoice', true ) ) {
        return $total_rows;
    }
    
    // 2. Explicitly scrub out WooCommerce's native tax AND native fee rows
    if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
        foreach( $total_rows as $key => $row ) {
            // Fix: Added || strpos($key, 'fee_') === 0 to wipe the native fee duplicate
            if ( strpos($key, 'tax-') === 0 || $key === 'tax' || strpos($key, 'fee_') === 0 ) {
                unset( $total_rows[$key] );
            }
        }
    }

    $method = '';
    if ( isset( $_POST['payment_method'] ) ) {
        $method = sanitize_key( $_POST['payment_method'] );
    } elseif ( isset( $_GET['payment_method'] ) ) {
        $method = sanitize_key( $_GET['payment_method'] );
    } elseif ( is_wc_endpoint_url( 'order-received' ) ) {
        $method = $order->get_payment_method();
    } elseif ( function_exists('WC') && WC()->session ) {
        $method = WC()->session->get( 'chosen_payment_method' );
    }

    if ( empty( $method ) && function_exists('WC') && WC()->payment_gateways ) {
        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        if ( ! empty( $gateways ) ) {
            reset( $gateways );
            $method = key( $gateways );
        }
    }

    // 3. Inject the correct custom rows strictly based on the live method
    if ( 'stripe_cc' === $method ) {
        return sm_inject_fee_row( $total_rows, $order );
    }

    return $total_rows;
}

/**
 * HELPER: Injects Fee AND Tax Rows into the Totals Array
 */
function sm_inject_fee_row( $total_rows, $order ) {
    $subtotal    = is_numeric( $order->get_subtotal() ) ? floatval( $order->get_subtotal() ) : 0.0;
    $shipping    = is_numeric( $order->get_shipping_total() ) ? floatval( $order->get_shipping_total() ) : 0.0;
    $base_amount = $subtotal + $shipping;
    $fee_net     = sm_calculate_fee_from_total( $base_amount );

    if ( $fee_net > 0 ) {
        $fee_tax = sm_get_total_tax_amount_for_fee( $fee_net, $order );

        $new_rows = array();
        $fee_inserted = false;

        foreach ( $total_rows as $key => $row ) {
            if ( ( $key === 'payment_method' || $key === 'order_total' ) && ! $fee_inserted ) {
                
                $new_rows['card_fee'] = array(
                    'label' => __( 'Card Convenience Fee:', 'vern_shipping_block' ),
                    'value' => wc_price( $fee_net ),
                );

                if ( $fee_tax > 0 ) {
                    $new_rows['fee_tax'] = array(
                        'label' => __( 'Tax on Fee:', 'vern_shipping_block' ), 
                        'value' => wc_price( $fee_tax ), 
                    );
                }

                $fee_inserted = true;
            }
            // Override the final visual total calculation directly
            if ( $key === 'order_total' ) {
                $exact_total = $base_amount + $fee_net + $fee_tax;
                $row['value'] = '<strong>' . wc_price( $exact_total ) . '</strong>';
            }

            $new_rows[ $key ] = $row;
        }
        $total_rows = $new_rows;
    }
    return $total_rows;
}

/**
 * LOGIC C: Physical Charge (Before Payment Processing)
 * (This is for the Balance Invoice order-pay Checkout page only)
 */
add_action( 'woocommerce_before_pay_action', 'sm_apply_fee_before_payment', 10 );
function sm_apply_fee_before_payment( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    // ROBUST METHOD DETECTION: 
    // Prevents background Stripe webhooks (which lack $_POST data) from accidentally deleting the fee.
    $method = '';
    if ( isset( $_POST['payment_method'] ) ) {
        $method = sanitize_key( $_POST['payment_method'] );
    } else {
        $raw_input = file_get_contents( 'php://input' );
        if ( ! empty( $raw_input ) ) {
            $json = json_decode( $raw_input, true );
            $method = isset( $json['payment_method'] ) ? sanitize_key( $json['payment_method'] ) : '';
        }
    }
    if ( empty( $method ) ) {
        $method = $order->get_payment_method();
    }

    $existing_fee_item = null;
    $existing_tax_items = [];
    foreach ( $order->get_items( array('fee', 'tax') ) as $item_id => $item ) {
        if ( is_a( $item, 'WC_Order_Item_Fee' ) && $item->get_name() === __( 'Card Convenience Fee', 'vern_shipping_block' ) ) {
            $existing_fee_item = $item;
        }
        if ( is_a( $item, 'WC_Order_Item_Tax' ) ) {
            $existing_tax_items[] = $item;
        }
    }

    if ( 'stripe_cc' === $method ) {
        $base_amount = $order->get_subtotal() + $order->get_shipping_total();
        $fee_net     = sm_calculate_fee_from_total( $base_amount );

        if ( $fee_net > 0 ) {
            if ( $existing_fee_item ) {
                $existing_fee_item->set_amount( $fee_net );
                $existing_fee_item->set_total( $fee_net );
                $existing_fee_item->save(); 
            } else {
                $item_fee = new WC_Order_Item_Fee();
                $item_fee->set_name( __( 'Card Convenience Fee', 'vern_shipping_block' ) );
                $item_fee->set_amount( $fee_net );
                $item_fee->set_total( $fee_net );
                $item_fee->set_tax_status( 'taxable' ); 
                $order->add_item( $item_fee );
                $item_fee->save(); 
            }
            
            foreach ( $existing_tax_items as $tax_item ) {
                $order->remove_item( $tax_item->get_id() );
            }

            $order->calculate_totals( true );
            $order->save();
        }
    } else {
        $needs_recalc = false;
        if ( $existing_fee_item ) {
            $order->remove_item( $existing_fee_item->get_id() );
            $needs_recalc = true;
        }
        foreach ( $existing_tax_items as $tax_item ) {
            $order->remove_item( $tax_item->get_id() );
            $needs_recalc = true;
        }
        if ( $needs_recalc ) {
            $order->calculate_totals( true );
            $order->save();
        }
    }
}

/**
 * LOGIC E: Force Correct Taxes on Invoice Orders (The Safety Net)
 * Runs immediately after ANY tax calculation (including Gateway triggers).
 */
add_action( 'woocommerce_order_after_calculate_totals', 'sm_fix_invoice_taxes', 10, 2 );
function sm_fix_invoice_taxes( $and_taxes, $order ) {
    if ( ! $and_taxes ) return; 
    if ( ! is_a( $order, 'WC_Order' ) ) return; 
    if ( 'yes' !== $order->get_meta( '_starke_is_balance_invoice', true ) ) return; 

    $fee_item = null;
    $balance_items = [];
    
    foreach( $order->get_items( ['line_item', 'fee'] ) as $item ) {
        if ( is_a( $item, 'WC_Order_Item_Fee' ) && $item->get_name() === __( 'Card Convenience Fee', 'vern_shipping_block' ) ) {
            $fee_item = $item;
        } elseif ( is_a( $item, 'WC_Order_Item_Product' ) ) {
            $balance_items[] = $item;
        }
    }

    foreach ( $balance_items as $item ) {
        $item->set_taxes( [ 'total' => [], 'subtotal' => [] ] );
        $item->save(); 
    }

    foreach ( $order->get_items( 'tax' ) as $item_id => $item ) {
        $order->remove_item( $item_id );
    }

    $total_tax_amount = 0;
    $fee_item_tax_data = [ 'total' => [], 'subtotal' => [] ];

    if ( $fee_item ) {
        $fee_net = $fee_item->get_total();
        $parent = wc_get_order( $order->get_parent_id() );
        
        if ( $parent ) {
             $parent_total_tax = $parent->get_total_tax();
             $natural_total = $parent->get_meta( '_starke_natural_total', true );
             $parent_net_total = ( ! empty( $natural_total ) && is_numeric( $natural_total ) ) ? (float)$natural_total - $parent_total_tax : $parent->get_total() - $parent_total_tax;

             $ratio = ( $parent_net_total > 0 ) ? ( $fee_net / $parent_net_total ) : 0;

             foreach ( $parent->get_items( 'tax' ) as $tax_item ) {
                 $rate_id = $tax_item->get_rate_id();
                 $label   = $tax_item->get_label();
                 $parent_tax_amount = (float) $tax_item->get_tax_total() + (float) $tax_item->get_shipping_tax_total();
                 $fee_tax_share = $parent_tax_amount * $ratio;

                 if ( $fee_tax_share > 0 ) {
                     $new_tax = new WC_Order_Item_Tax();
                     $new_tax->set_rate_id( $rate_id );
                     $new_tax->set_label( $label );
                     $new_tax->set_tax_total( $fee_tax_share );
                     $order->add_item( $new_tax );
                     
                     $fee_item_tax_data['total'][ $rate_id ] = $fee_tax_share;
                     $fee_item_tax_data['subtotal'][ $rate_id ] = $fee_tax_share;
                     $total_tax_amount += $fee_tax_share;
                 }
             }
        }
        $fee_item->set_taxes( $fee_item_tax_data );
        $fee_item->save();
    }

    $order->set_cart_tax( $total_tax_amount );
    $order->set_shipping_tax( 0 );
    
    $total = $order->get_subtotal() + $order->get_shipping_total() + $order->get_total_fees() + $total_tax_amount;
    $order->set_total( $total );
}

/**
 * LOGIC D: AJAX Handler for Invoice Updates
 */
add_action( 'wp_ajax_starke_update_invoice_fee', 'sm_ajax_update_invoice_fee' );
add_action( 'wp_ajax_nopriv_starke_update_invoice_fee', 'sm_ajax_update_invoice_fee' );

function sm_ajax_update_invoice_fee() {
    check_ajax_referer( 'starke-invoice-nonce', 'security' );

    $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
    $method   = isset( $_POST['payment_method'] ) ? sanitize_key( $_POST['payment_method'] ) : '';

    $order = wc_get_order( $order_id );
    if ( ! $order ) wp_send_json_error( 'Invalid Order' );

    // The visual fee and tax rows are now automatically injected by the 
    // sm_visual_fee_on_invoice_load filter hooked into get_order_item_totals().
    $totals = $order->get_order_item_totals();

    ob_start();
    foreach ( $totals as $total ) {
        ?>
        <tr>
            <th scope="row" colspan="2"><?php echo $total['label']; ?></th>
            <td class="product-total"><?php echo $total['value']; ?></td>
        </tr>
        <?php
    }
    $html = ob_get_clean();

    wp_send_json_success( array( 'html' => $html ) );
}

/**
 * SCRIPT: AJAX Listener for Invoice Page
 */
add_action( 'wp_footer', 'sm_invoice_ajax_script' );
function sm_invoice_ajax_script() {
    if ( ! is_wc_endpoint_url( 'order-pay' ) ) return;
    
    $order_id = get_query_var( 'order-pay' );
    ?>
    <script type="text/javascript">
    jQuery(function($){
        var orderId = '<?php echo esc_js( $order_id ); ?>';
        var endpoint = '<?php echo admin_url( 'admin-ajax.php' ); ?>';

        $('form#order_review').on('change', 'input[name="payment_method"]', function(){
            var method = $(this).val();

            $('table.shop_table').block({ 
                message: null, 
                overlayCSS: { background: '#fff', opacity: 0.6 } 
            });

            $.post( endpoint, {
                action: 'starke_update_invoice_fee',
                order_id: orderId,
                payment_method: method,
                security: '<?php echo wp_create_nonce( "starke-invoice-nonce" ); ?>'
            }, function( response ) {
                if ( response.success ) {
                    $('table.shop_table tfoot').html( response.data.html );
                    
                    // Re-apply zebra striping manually to the newly injected rows
                    var rows = document.querySelectorAll('table.shop_table tbody tr, table.shop_table tfoot tr');
                    rows.forEach(function(row, index) {
                        row.classList.remove('starke-row-white', 'starke-row-gray');
                        if (index % 2 === 0) {
                            row.classList.add('starke-row-gray');
                        } else {
                            row.classList.add('starke-row-white');
                        }
                    });
                }
                $('table.shop_table').unblock();
            }).fail(function() {
                $('table.shop_table').unblock();
            });
        });
    });
    </script>
    <?php
}

/**
 * --- TAMPER-PROOF CREDIT CARD CONVENIENCE FEE ---
 * Verifies and applies the correct convenience fee amount based on Payment Terms
 * when the order is processed.
 */
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'validate_and_apply_convenience_fee_on_order', 99, 2 );
function validate_and_apply_convenience_fee_on_order( $order, $request ) {
    // 1. Get current submission data
    $payment_method = $request['payment_method'] ?? '';
    
    // Get terms from the request extensions or fallback to order meta
    $extensions_data = $request['extensions']['vern_shipping_block'] ?? [];
    $term = !empty($extensions_data['starke_payment_terms']) ? $extensions_data['starke_payment_terms'] : $order->get_meta('_starke_payment_terms', true);

    // --- NEW SECURITY CHECK: Enforce Samples Only ---
    $is_samples_only = true;
    $line_items = $order->get_items('line_item');
    if ( sizeof( $line_items ) > 0 ) {
        foreach ( $line_items as $item ) {
            if ( ! function_exists('is_order_item_a_sample') || ! is_order_item_a_sample( $item ) ) {
                $is_samples_only = false;
                break;
            }
        }
    } else {
        $is_samples_only = false;
    }

    if ( $is_samples_only ) {
        $term = 'no_terms';
    } else {
        // --- NEW SECURITY CHECK: Validate Assigned Term for Standard Orders ---
        if ( $term !== 'no_terms' ) {
            $user_id = $order->get_customer_id();
            if ( $user_id ) {
                $assigned = get_user_meta( $user_id, '_starke_assigned_payment_term', true );
                if ( $term !== $assigned ) {
                    $term = empty( $assigned ) ? 'no_terms' : $assigned;
                }
            } else {
                $term = 'no_terms';
            }
        }
    }

    // --- CONFIGURATION ---
    $fee_label = __( 'Card Convenience Fee', 'vern_shipping_block' );
    $credit_card_gateway_id = 'stripe_cc';
    // --- END CONFIGURATION ---

    // 2. Remove any existing fee first to ensure a clean calculation
    foreach ( $order->get_fees() as $item_id => $fee ) {
        if ( $fee->get_name() === $fee_label ) {
            $order->remove_item( $item_id );
        }
    }

    // 3. Only calculate and add a fee if Stripe CC is selected AND it's not Net 30
    if ( $credit_card_gateway_id === $payment_method && 'net_30' !== $term ) {
        
        $options = get_option('starke_commerce_options');
        $base_percent = (isset($options['card_convenience_fee']) ? floatval($options['card_convenience_fee']) : 0) / 100;
        
        // Determine the base amount for the fee
        $subtotal_base = $order->get_subtotal() + $order->get_shipping_total();
        
        // Adjust the base amount based on terms and round it perfectly
        if ( '50_50' === $term ) {
            $subtotal_base = round( $subtotal_base / 2, 2 );
        } elseif ( 'net_30' === $term ) {
            $subtotal_base = 0;
        }

        // Calculate the fee on the rounded base, then round the final fee amount
        $fee_amount = round( $subtotal_base * $base_percent, 2 );

        if ( $fee_amount > 0 ) {
            $fee_item = new WC_Order_Item_Fee();
            $fee_item->set_name( $fee_label );
            $fee_item->set_amount( $fee_amount );
            $fee_item->set_total( $fee_amount );
            $fee_item->set_tax_status( 'none' ); // Handled by your ratio tax logic
            $order->add_item( $fee_item );
        }
    }
    
    // 4. Force a final recalculation to ensure the order total is accurate
    $order->calculate_totals();
}

/**
 * HOOK: woocommerce_store_api_checkout_order_processed
 * Priority 50: Runs AFTER sm_adjust_order_taxes (Priority 10).
 *
 * FIX: We now read from $order->get_meta() instead of WC()->session.
 * The Store API saves extension data to meta BEFORE this hook runs, so meta is the
 * only reliable source of truth here.
 */
add_action( 'woocommerce_store_api_checkout_order_processed', 'sm_override_order_total_final', 50, 1 );

function sm_override_order_total_final( $order ) {
    // 1. Get the payment term directly from the order meta (saved securely from the final Checkout payload)
    $term = $order->get_meta( '_starke_payment_terms', true );

    // 2. ROOT FIX: Calculate the TRUE 100% natural total strictly from the physical line items.
    // We CANNOT use $order->get_total('edit') because if the background session was "50_50", 
    // the cart may have already halved the total before creating this order (Double-Halving Bug).
    $natural_total = 0.0;
    foreach ( $order->get_items( ['line_item', 'fee', 'shipping'] ) as $item ) {
        $natural_total += (float) $item->get_total();
    }
    $natural_total += (float) $order->get_total_tax();

    // --- NEW SECURITY CHECK: Enforce Samples Only ---
    $is_samples_only = true;
    $line_items = $order->get_items('line_item');
    if ( sizeof( $line_items ) > 0 ) {
        foreach ( $line_items as $item ) {
            if ( ! function_exists('is_order_item_a_sample') || ! is_order_item_a_sample( $item ) ) {
                $is_samples_only = false;
                break;
            }
        }
    } else {
        $is_samples_only = false;
    }

    // --- NEW SECURITY CHECK: Validate Assigned Term for Standard Orders ---
    if ( ! $is_samples_only && ! empty( $term ) && $term !== 'no_terms' ) {
        $user_id = $order->get_customer_id();
        if ( $user_id ) {
            $assigned = get_user_meta( $user_id, '_starke_assigned_payment_term', true );
            if ( $term !== $assigned ) {
                $term = empty( $assigned ) ? 'no_terms' : $assigned;
            }
        } else {
            $term = 'no_terms';
        }
    }

    if ( $is_samples_only || empty( $term ) || 'no_terms' === $term || (float)$natural_total < 50 ) {
        $order->update_meta_data( '_starke_payment_terms', 'no_terms' );
        $order->update_meta_data( '_starke_deferred_balance', 0.0 );
        $order->update_meta_data( '_starke_natural_total', $natural_total );
        
        // Force total back to 100% in case cart halved it incorrectly
        $order->set_total( $natural_total );
        $order->save();
        return;
    }

    $amount_due_now = $natural_total;
    $deferred_amount = 0.0;

    if ( 'net_30' === $term ) {
        $amount_due_now = 0.0;
        $deferred_amount = $natural_total;
    } elseif ( '50_50' === $term ) {
        // FIX: Convert to integer cents first to bypass PHP floating-point imprecision
        $total_cents = (int) round( $natural_total * 100 );
        
        // Divide by 2 and round half up to get exact cents
        $due_now_cents = (int) round( $total_cents / 2 );
        
        // Convert back to standard dollars/decimals
        $amount_due_now  = $due_now_cents / 100;
        $deferred_amount = ( $total_cents - $due_now_cents ) / 100;
    }

    // 3. Force the final mathematically perfect totals onto the order
    $order->set_total( $amount_due_now );
    $order->update_meta_data( '_starke_payment_terms', $term );
    $order->update_meta_data( '_starke_deferred_balance', $deferred_amount );
    $order->update_meta_data( '_starke_natural_total', $natural_total );

    $order->save();
}

/**
 * Force payment gateways to show even if the total is zero (Net 30).
 */
add_filter( 'woocommerce_cart_needs_payment', 'starke_force_payment_for_net30', 20, 2 );
function starke_force_payment_for_net30( $needs_payment, $cart ) {
    $live_context = starke_get_live_payment_context();
    if ( 'net_30' === $live_context['term'] ) {
        return true; // Force WC to show payment methods
    }
    return $needs_payment;
}

/**
 * Force Net 30 orders to 'on-hold' instead of 'processing' 
 * when WooCommerce completes the $0 transaction.
*/
add_filter( 'woocommerce_payment_complete_order_status', 'starke_net30_force_status_on_payment_complete', 20, 3 );
function starke_net30_force_status_on_payment_complete( $status, $order_id, $order ) {
    $term = $order->get_meta( '_starke_payment_terms', true );
    if ( 'net_30' === $term ) {
        return 'processing'; // Overwrites the 'processing' decision
    }
    return $status;
}


/**
 * CRITICAL FIX: Dynamic Order Total for Balance Invoices (Pay for Order Page)
 * * Logic:
 * 1. Listens for the active payment method (via POST, GET, or JSON).
 * 2. If 'stripe_cc' is active -> Returns Total + Fee + Tax.
 * 3. If 'cheque' (or other) is active -> Returns Normal Total.
 * * This ensures both the Visual Display AND the Stripe Payment Intent 
 * always use the exact same number.
 */
add_filter( 'woocommerce_order_get_total', 'sm_dynamic_balance_invoice_order_total', 99, 2 );
function sm_dynamic_balance_invoice_order_total( $total, $order ) {
    if ( ! is_a( $order, 'WC_Order' ) || 'yes' !== $order->get_meta( '_starke_is_balance_invoice', true ) ) {
        return $total;
    }

    $method = $order->get_payment_method();
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    $is_stripe_api = strpos( $uri, 'wc_stripe' ) !== false || strpos( $uri, 'wc-stripe' ) !== false;
    
    if ( $is_stripe_api ) {
        $method = 'stripe_cc';
    } elseif ( isset( $_POST['payment_method'] ) ) {
        $method = sanitize_key( $_POST['payment_method'] );
    } elseif ( is_wc_endpoint_url( 'order-pay' ) ) {
        if ( empty( $method ) && function_exists('WC') && WC()->session ) {
            $method = WC()->session->get( 'chosen_payment_method' );
        }
        if ( empty( $method ) && function_exists('WC') && WC()->payment_gateways ) {
            // Prevent infinite loop: temporarily remove this filter while checking gateways
            remove_filter( 'woocommerce_order_get_total', 'sm_dynamic_balance_invoice_order_total', 99 );
            
            $gateways = WC()->payment_gateways->get_available_payment_gateways();
            if ( ! empty( $gateways ) ) {
                reset( $gateways );
                $method = key( $gateways );
            }
            
            // Re-attach filter immediately after
            add_filter( 'woocommerce_order_get_total', 'sm_dynamic_balance_invoice_order_total', 99, 2 );
        }
    }

    // Only override for Credit Card payments
    if ( 'stripe_cc' === $method ) {
        $subtotal = (float) $order->get_subtotal();
        $shipping = (float) $order->get_shipping_total();
        
        // 1. Calculate the fee purely mathematically based on current context
        $base_amount = $subtotal + $shipping;
        $fee_amount  = sm_calculate_fee_from_total( $base_amount );

        // 2. ROOT FIX: Calculate tax purely mathematically. 
        // We DO NOT use $order->get_total_tax() because it holds the corrupted $57.67.
        $tax_amount = sm_get_total_tax_amount_for_fee( $fee_amount, $order );

        // 3. Return the impenetrable exact total (e.g., $396.06 + $15.84 + $2.22 = $414.12)
        return $subtotal + $shipping + $fee_amount + $tax_amount;
    }

    return $total;
}

/**
 * Hides the "Total" line in WooCommerce Blocks Order Confirmation.
 * Uses a MutationObserver to catch the element even if it loads late via React/AJAX.
 */
add_action( 'wp_footer', 'starke_hide_block_thankyou_total_robust' );
function starke_hide_block_thankyou_total_robust() {
    // Only run on the Order Received (Thank You) page
    if ( is_wc_endpoint_url( 'order-received' ) ) {
        ?>
        <script type="text/javascript">
            (function() {
                // Function to find and hide the row
                function hideTotalRow() {
                    // Get all list items (rows)
                    var items = document.querySelectorAll('.wc-block-order-confirmation-summary-list-item');
                    
                    items.forEach(function(item) {
                        var key = item.querySelector('.wc-block-order-confirmation-summary-list-item__key');
                        // Check if the Key exists and contains "Total"
                        if (key && key.textContent.includes('Total')) {
                            item.style.display = 'none';
                            // Optional: Add a class to mark it as hidden so we don't keep checking it
                            item.classList.add('starke-hidden');
                        }
                    });
                }

                // 1. Run immediately (in case it is already there)
                hideTotalRow();

                // 2. Set up a "Watcher" (MutationObserver) for late-loading content
                var observer = new MutationObserver(function(mutations) {
                    hideTotalRow();
                });

                // Start watching the body for changes (elements being added)
                observer.observe(document.body, { childList: true, subtree: true });
            })();
        </script>
        <style>
            /* Backup CSS: If the JS takes a split second, this helps prevent a "flash" of the total */
            .starke-hidden { display: none !important; }
            
            /* HIDE QUANTITY READOUTS ON ORDER ITEMS */
            /* Targets classic WooCommerce "× 1" text */
            .product-quantity,
            /* Targets modern WooCommerce Block text quantities */
            .wc-block-components-order-summary-item__quantity,
            /* Targets modern WooCommerce Block badge quantities */
            .wc-block-components-order-summary-item .wc-block-components-badge {
                display: none !important;
            }
        </style>
        <?php
    }
}

/**
 * Remove Stripe (and other JS-heavy gateways) when Net 30 is selected.
 * This forces WC Blocks to fall back to an offline method (like 'cheque'), bypassing CC validation.
 */
add_filter( 'woocommerce_available_payment_gateways', 'starke_disable_stripe_validation_for_net30', 99, 1 );
function starke_disable_stripe_validation_for_net30( $available_gateways ) {
    // Don't interfere with the admin backend
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return $available_gateways;
    }

    $live_context = starke_get_live_payment_context();
    
    if ( 'net_30' === $live_context['term'] ) {
        // Unset Stripe so its frontend JS validation is completely removed
        if ( isset( $available_gateways['stripe_cc'] ) ) {
            unset( $available_gateways['stripe_cc'] );
        }
        if ( isset( $available_gateways['stripe_ach'] ) ) {
            unset( $available_gateways['stripe_ach'] );
        }
        
        // Note: Ensure an offline gateway like 'cheque' (Check payments) is enabled in WooCommerce settings!
    }

    return $available_gateways;
}

/**
 * Clears the is_ship_mode cookie when a user logs out.
 * Prevents checkout UI state bleeding between admin impersonation sessions.
 */
add_action('clear_auth_cookie', 'starke_destroy_ship_mode_cookie_on_logout');
function starke_destroy_ship_mode_cookie_on_logout() {
    if ( isset( $_COOKIE['is_ship_mode'] ) ) {
        // Remove it from the current PHP execution array
        unset( $_COOKIE['is_ship_mode'] );
        // Instruct the browser to expire and delete the cookie immediately
        setcookie( 'is_ship_mode', '', time() - 3600, '/' ); 
    }
}