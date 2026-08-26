<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define a constant for the custom profile option name
define('CUSTOM_PROFILE_NUMBER_OPTION_KEY', 'custom_profile_placeholder_number'); // This will be used to store the custom profile number in the database

// --- Custom Molding Profile Number Incrementor --- START
// Add Custom Molding Profile number incrementor field to the database
function initialize_custom_profile_number() {
    if (get_option(CUSTOM_PROFILE_NUMBER_OPTION_KEY) === false) {
        update_option(CUSTOM_PROFILE_NUMBER_OPTION_KEY, 1);
    }
}
initialize_custom_profile_number();

// Increment the custom profile counter
function increment_custom_profile_number() {
    $current = intval(get_option(CUSTOM_PROFILE_NUMBER_OPTION_KEY));
    update_option(CUSTOM_PROFILE_NUMBER_OPTION_KEY, $current + 1);
}

// Get the current custom profile number
function get_custom_profile_number() {
    $current = intval(get_option(CUSTOM_PROFILE_NUMBER_OPTION_KEY));
    $padded = str_pad($current, 4, '0', STR_PAD_LEFT); // Always at least 4 digits
    return 'X' . $padded . 'X'; // e.g., X0001X, X0100X, X1000X, X10000X
}
// --- Custom Molding Profile Number Incrementor --- END

function hide_products_from_main_archives( $query ) {
    if ( is_admin() || ! $query->is_main_query() ) { return; }
    if ( is_cart() || is_checkout() || current_user_can('manage_woocommerce') || ( function_exists('impersonation_is_active') && impersonation_is_active() ) ) { return; }

    if ( is_post_type_archive('product') || is_tax( get_object_taxonomies('product') ) || is_search() ) {
        
        // INSTANT LOOKUP: Grab the IDs from the cache
        $products_to_exclude = sm_get_cached_hidden_product_ids();

        if ( ! empty( $products_to_exclude ) ) {
            $query->set( 'post__not_in', array_merge( $query->get( 'post__not_in' ) ?: [], $products_to_exclude ) );
        }
    }
}
add_action( 'pre_get_posts', 'hide_products_from_main_archives' );

function exclude_from_related_products( $related_posts, $product_id, $args ) {
    if ( current_user_can('manage_woocommerce') || ( function_exists('impersonation_is_active') && impersonation_is_active() ) ) {
        return $related_posts;
    }

    // INSTANT LOOKUP: Grab the IDs from the cache
    $products_to_exclude = sm_get_cached_hidden_product_ids();

    if ( ! empty( $products_to_exclude ) ) {
        return array_diff( $related_posts, $products_to_exclude );
    }

    return $related_posts;
}
add_filter( 'woocommerce_related_products', 'exclude_from_related_products', 10, 3 );

function block_direct_product_page_access() {
    // Only run for single products, for non-admins
    if ( is_product() && !current_user_can('manage_woocommerce') && !( function_exists('impersonation_is_active') && impersonation_is_active() ) ) {
        $product = wc_get_product( get_the_ID() );
        if ($product) {
            $skus_to_hide = get_hidden_product_skus();
            if ( in_array( $product->get_sku(), $skus_to_hide ) ) {
                wp_redirect( get_permalink( wc_get_page_id( 'shop' ) ) );
                exit();
            }
        }
    }
}
add_action( 'template_redirect', 'block_direct_product_page_access' );

/**
 * Checks if a given product ID belongs to a custom product type.
 *
 * @param int $product_id The ID of the product to check.
 * @return bool True if it's a custom product, false otherwise.
 */
function is_custom_profile($product_id) {
    // An array of all your custom product IDs
    $custom_profile_ids = [
        6173 , // Baseboard
        6156 , // Casing
        6159 , // Crown
        6157 , // Miscellaneous
    ];

    // The in_array() function efficiently checks if the ID exists in the list.
    return in_array($product_id, $custom_profile_ids);
}

/**
 * Returns an array of all of the IDs for every custom profile type.
 *
 * @param int $product_id The ID of the product to check.
 * @return bool True if it's a custom product, false otherwise.
 */
function custom_profile_ids() {
    // An array of all your custom product IDs
    $custom_profile_ids = [
        6173 , // Baseboard
        6156 , // Casing
        6159 , // Crown
        6157 , // Miscellaneous
    ];

    return $custom_profile_ids;
}

/**
 * Returns the single source of truth for SKUs that should be hidden from customers.
 *
 * @return array A list of product SKUs.
 */
function get_hidden_product_skus() {
    return [
        'XBASEBOARD',
        'XCASING',
        'XCROWN',
        'XMISCELLANEOUS',
        'KNIFECOST',
        'SETUPCHARGE',
    ];
}

// Add "Other" option to the end of the Species and Finish Options dropdown in Custom Profiles request form
add_action('wp_footer', function() {
    ?>
    <script type="text/javascript">
    (function($) {
        // 1. Safety check: Ensure jQuery is loaded
        if (typeof $ === 'undefined') {
            return;
        }

        // 2. Listen for the 'wsf-rendered' event
        $(document).on('wsf-rendered', function(e, form_object, form_id, instance_id, form_el) {
            
            // Only run for Form ID 2
            if (parseInt(form_id) !== 2) {
                return;
            }

            // LIST OF FIELDS TO ADD "OTHER" TO
            // You can add more IDs here later if needed (e.g., [25, 32, 40])
            var targetFields = [25, 32];

            // Loop through each target field ID
            targetFields.forEach(function(fieldId) {
                
                // Find the Dropdown by ID
                var $dropdown = form_el.find('select[id$="-field-' + fieldId + '"]');

                if ($dropdown.length > 0) {
                    // Check if "Other" doesn't already exist
                    if ($dropdown.find('option[value="Other (Check in Notes field)"]').length === 0) {
                        
                        // Append the "Other" option
                        $dropdown.append('<option value="Other (Check in Notes field)">Other (Explain in Notes field below)</option>');
                        
                        // Trigger change to update UI
                        $dropdown.trigger('change');
                    }
                }
            });
        });

    })(jQuery);
    </script>
    <?php
}, 999);

/**
 * SEARCHWP 4.0+: Exclude Hidden Profiles (The "Mod" Method)
 * Logic: We inject a specific rule (Mod) into the search engine to
 * strictly exclude these IDs for non-admins.
 */
add_filter( 'searchwp\query\mods', function( $mods, $query ) {

    if ( current_user_can( 'manage_woocommerce' ) ) { return $mods; }
    if ( function_exists( 'impersonation_is_active' ) && impersonation_is_active() ) { return $mods; }

    // INSTANT LOOKUP: Grab the IDs from the cache
    $ids_to_exclude = sm_get_cached_hidden_product_ids();

    if ( empty( $ids_to_exclude ) ) { return $mods; }

    $source = \SearchWP\Utils::get_post_type_source_name( 'product' );
    $mod = new \SearchWP\Mod( $source );
    $mod->set_where( [
        [
            'column'  => 'id',
            'value'   => $ids_to_exclude,
            'compare' => 'NOT IN',
            'type'    => 'NUMERIC',
        ]
    ] );

    $mods[] = $mod;
    return $mods;
}, 20, 2 );

/**
 * Gets the IDs of the hidden SKUs using a cached Transient
 * to prevent massive database lag on every page load.
 */
function sm_get_cached_hidden_product_ids() {
    $hidden_ids = get_transient( 'starke_hidden_sku_ids' );

    // If the cache is empty (or expired), do the heavy database lookup ONCE
    if ( false === $hidden_ids ) {
        $skus_to_hide = get_hidden_product_skus();
        
        $hidden_ids = get_posts([
            'post_type'              => 'product',
            'fields'                 => 'ids',
            'numberposts'            => -1,
            'meta_query'             => [
                [
                    'key'     => '_sku',
                    'value'   => $skus_to_hide,
                    'compare' => 'IN'
                ]
            ],
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        // Save the IDs to the fast cache for 30 days
        set_transient( 'starke_hidden_sku_ids', $hidden_ids, 30 * DAY_IN_SECONDS );
    }

    return $hidden_ids;
}