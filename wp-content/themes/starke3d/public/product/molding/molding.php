<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


/**
 * STARKE SHAREABLE COMPARE URL HANDLER
 * Listens for '?starke_share_compare=1,2,3', sets the cookie, then REDIRECTS to clean the URL.
 */
add_action('init', 'starke_catch_shared_compare_url');
function starke_catch_shared_compare_url() {
    if ( isset( $_GET['starke_share_compare'] ) ) {
        
        $raw = $_GET['starke_share_compare'];
        
        // 1. Sanitize
        $raw = str_replace('undefined', '', $raw);
        $cleaned = preg_replace('/[^0-9,]/', '', $raw);
        $cleaned = trim($cleaned, ',');
        $cleaned = preg_replace('/,+/', ',', $cleaned);
        
        if ( ! empty( $cleaned ) ) {
            
            // Force the leading dot to match the plugin's cookie
            $domain_fix = '.' . $_SERVER['HTTP_HOST'];
            
            // A. Set the DATA cookie (The actual products)
            setcookie('br_products_compare', $cleaned, time() + (86400 * 7), "/", $domain_fix); 
            $_COOKIE['br_products_compare'] = $cleaned;
            
            // B. Set the TRIGGER cookie (Expires in 60 seconds)
            // This tells the JavaScript on the NEXT page load to open the popup.
            setcookie('starke_trigger_popup', '1', time() + 60, "/", $domain_fix);

            // 3. Redirect (Cleans the URL immediately)
            if ( ! defined( 'DOING_AJAX' ) && ! headers_sent() ) {
                $current_url = remove_query_arg( 'starke_share_compare' );
                wp_safe_redirect( $current_url );
                exit;
            }
        }
    }
}

/**
 * ADMIN ONLY: Product Protection
 * This runs only in the dashboard/admin to prevent deletion.
 */
if (is_admin()) {
    function prevent_protected_product_removal($post_id) {
        $protected_skus = array(
            'XBASEBOARD', 'XCASING', 'XCROWN', 'XMISCELLANEOUS', 'KNIFECOST', 'SETUPCHARGE'
        );

        if (get_post_type($post_id) !== 'product') return;

        $product = wc_get_product($post_id);

        if ($product && $product->get_sku() && in_array($product->get_sku(), $protected_skus)) {
            wp_die(
                "<strong>Protected Product:</strong> The product " . esc_html($product->get_name()) .  " cannot be moved to the trash or deleted.",
                'Action Prevented',
                array('back_link' => true)
            );
        }
    }
    add_action('wp_trash_post', 'prevent_protected_product_removal', 10, 1);
    add_action('before_delete_post', 'prevent_protected_product_removal', 10, 1);
}

/**
 * GLOBAL: Shortcodes & Helpers 
 * CRITICAL FIX: We removed the 'else' so these run for BOTH the Shop Page and the AJAX Popup.
 */

/**
 * Force the default Products Per Page to 20.
 * This matches the first option in the WP Grid Builder facet (20, 50, 100),
 * ensuring the facet is active and visible on page load.
 */
add_filter( 'loop_shop_per_page', 'starke_set_default_products_per_page', 20 );
function starke_set_default_products_per_page( $cols ) {
    return 4;
}

/**
 * -------------------------------------------------------------------------
 * MOLDING CALCULATOR: DECIMAL TO FRACTION SHORTCODE
 * -------------------------------------------------------------------------
 * Usage in WP Grid Builder Card: 
 * [molding_fraction field="thickness"]
 * [molding_fraction field="width"]
 */
add_shortcode( 'molding_fraction', 'wpgb_render_fraction_shortcode' );

function wpgb_render_fraction_shortcode( $atts ) {
    // 1. Get attributes (default to 'thickness')
    $atts = shortcode_atts( [
        'field' => 'thickness', // The ACF Field Name
    ], $atts );

    // 2. Get the current product in the WPGB Loop
    if ( function_exists( 'wpgb_get_post' ) ) {
        $post_object = wpgb_get_post();
    } else {
        global $post;
        $post_object = $post;
    }

    if ( ! $post_object ) {
        return '';
    }

    // 3. Get the decimal value from ACF
    $decimal_value = get_post_meta( $post_object->ID, $atts['field'], true );

    // If empty, return nothing
    if ( $decimal_value === '' || $decimal_value === false ) {
        return '';
    }

    // 4. Convert to Fraction using your algorithm
    return wpgb_decimal_to_fraction_string( $decimal_value );
}

/**
 * Helper: Your Custom JS Algorithm converted to PHP
 */
function wpgb_decimal_to_fraction_string( $number ) {
    
    $input = floatval( $number );

    if ( $input != 0 ) {
        // "PL.variables.incrementSize" - Set to 1/16 (0.0625) for standard molding precision
        $increment = 0.0625; 

        $startingNumber = $input;
        $wholePart = floor( $startingNumber );
        $decimalPart = $startingNumber - $wholePart;
        
        // Logic: Round decimal part to nearest increment
        $decimalPart = floor( 1.0 * $decimalPart / $increment + 0.5 ) * $increment;
        
        // Check if rounding pushed us to the next whole number
        if ( abs( $decimalPart - 1.0 ) < 0.00001 ) { 
            return ( $wholePart + 1 ) . '"';
        } 
        else {
            $wholePartAsString = "";
            if ( $wholePart != 0 ) {
                $wholePartAsString = (string) $wholePart;
            }
            
            $fractionPartAsString = "";
            
            // If there is a decimal remainder
            if ( abs( $decimalPart ) > 0.00001 ) {
                
                // Calculate decimal places to determine power of 10
                // equivalent to: (decimalPart.toString().length - 2)
                $decimalString = (string) $decimalPart;
                
                // Handle "0." prefix length
                if ( strpos( $decimalString, '.' ) !== false ) {
                    // PHP strings might vary, so we ensure we get the length of digits after dot
                    $decimals_count = strlen( substr( strrchr( $decimalString, "." ), 1 ) );
                } else {
                    $decimals_count = 0;
                }

                $numerator = $decimalPart * pow( 10, $decimals_count );
                $denominator = pow( 10, $decimals_count );
                
                $divisor = wpgb_get_gcd( $numerator, $denominator );
                
                $fractionPartAsString = ( $numerator / $divisor ) . "/" . ( $denominator / $divisor );
            }
            
            $dash = "";
            // Add dash only if we have BOTH a whole number AND a fraction
            if ( $wholePartAsString != "" && $fractionPartAsString != "" ) {
                $dash = "-"; // Matches your old site style (e.g., 1-1/2")
            }
            
            // If result is just a fraction (e.g. 1/2")
            if ( $wholePartAsString == "" && $fractionPartAsString != "" ) {
                return $fractionPartAsString . '"';
            }
            
            // If result is just a whole number (e.g. 3")
            if ( $fractionPartAsString == "" && $wholePartAsString != "" ) {
                return $wholePartAsString . '"';
            }

            // Full string
            return $wholePartAsString . $dash . $fractionPartAsString . '"';
        }
    } 
    else {
        return (string) $number;
    }
}

/**
 * Helper: Find Greatest Common Divisor (GCD)
 */
function wpgb_get_gcd( $a, $b ) {
    while ( $b > 0 ) {
        $c = $a % $b;
        $a = $b;
        $b = $c;
    }
    return abs( $a );
}

/**
 * STARKE: DYNAMIC CUSTOMIZABLE LABEL SHORTCODE
 * Usage: [molding_custom_label]
 * Outputs a styled span if min/max width or thickness differ.
 */
add_shortcode( 'molding_custom_label', 'starke_render_custom_label_shortcode' );

function starke_render_custom_label_shortcode( $atts ) {
    // 1. Resolve Post ID from Attributes OR Global Context
    $atts = shortcode_atts( ['id' => 0], $atts );
    $post_object = null;

    if ( ! empty( $atts['id'] ) ) {
        $post_object = get_post( $atts['id'] );
    } elseif ( function_exists( 'wpgb_get_post' ) ) {
        $post_object = wpgb_get_post();
    } else {
        global $post;
        $post_object = $post;
    }

    if ( ! $post_object ) { return ''; }
    $post_id = $post_object->ID;

    // 2. Get values directly from the database (Optimized)
    $min_width_raw    = get_post_meta( $post_id, 'min_width', true );
    $max_width_raw    = get_post_meta( $post_id, 'max_width', true );
    $min_thickness_raw = get_post_meta( $post_id, 'min_thickness', true );
    $max_thickness_raw = get_post_meta( $post_id, 'max_thickness', true );

    // 3. Check conditions
    $has_custom_width = ( 
        $min_width_raw !== '' && $min_width_raw !== null &&
        $max_width_raw !== '' && $max_width_raw !== null &&
        floatval( $min_width_raw ) !== floatval( $max_width_raw )
    );

    $has_custom_thickness = ( 
        $min_thickness_raw !== '' && $min_thickness_raw !== null &&
        $max_thickness_raw !== '' && $max_thickness_raw !== null &&
        floatval( $min_thickness_raw ) !== floatval( $max_thickness_raw )
    );

    // 4. Determine Label Text
    $label_text = '';
    if ( $has_custom_width && $has_custom_thickness ) {
        $label_text = 'Customizable Thickness & Width';
    } elseif ( $has_custom_width ) {
        $label_text = 'Customizable Width';
    } elseif ( $has_custom_thickness ) {
        $label_text = 'Customizable Thickness';
    }

    // 5. Output HTML
    if ( empty( $label_text ) ) {
        return '';
    }

    ob_start();
    ?>
    <span class="starke-custom-label starke-compare-pill"><?php echo esc_html( $label_text ); ?></span>
    <?php
    return ob_get_clean();
}

/**
 * STARKE: ADD SAMPLE BUTTON SHORTCODE (With Login Redirect)
 * Usage: [starke_sample_button]
 */
add_shortcode( 'starke_sample_button', 'starke_render_sample_button_shortcode' );

function starke_render_sample_button_shortcode( $atts ) {
    // 1. Resolve Post ID from Attributes OR Global Context
    $atts = shortcode_atts( ['id' => 0], $atts );
    $post_object = null;

    if ( ! empty( $atts['id'] ) ) {
        $post_object = get_post( $atts['id'] );
    } elseif ( function_exists( 'wpgb_get_post' ) ) {
        $post_object = wpgb_get_post();
    } else {
        global $post;
        $post_object = $post;
    }

    if ( ! $post_object ) { return ''; }
    
    $product_id = $post_object->ID;
    
    // --- Hide button for custom profiles ---
    if ( function_exists('is_custom_profile') && is_custom_profile( $product_id ) ) {
        return '';
    }

    // 2. Check Inventory (Optimized)
    $sample_inventory = get_post_meta( $product_id, 'sample_inventory', true );
    $is_out_of_stock = ( $sample_inventory == 0 || $sample_inventory === '' );

    // 3. Check User Session & Cart (Optimized with Static Cache)
    if ( is_null( WC()->session ) ) {
        WC()->initialize_session();
    }
    $requested_samples = WC()->session->get( 'sample_requests', [] );
    $already_requested = in_array( $product_id, $requested_samples );

    // --- NEW: ISOLATED CART CHECK ---
    // This ONLY runs when the specific Compare Popup AJAX action is triggered.
    // It is completely bypassed during normal WP Grid Builder execution.
    $already_in_cart = false;
    
    if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'starke_load_compare_table' ) {
        static $cart_sample_ids = null;
        if ( is_null( $cart_sample_ids ) ) {
            $cart_sample_ids = [];
            
            if ( is_null( WC()->cart ) && function_exists( 'wc_load_cart' ) ) {
                wc_load_cart();
            }
            
            if ( isset( WC()->cart ) && ! is_null( WC()->cart ) ) {
                foreach ( WC()->cart->get_cart() as $cart_item ) {
                    if ( isset( $cart_item['sample'] ) && $cart_item['sample'] === true ) {
                        $cart_sample_ids[] = intval( $cart_item['product_id'] );
                    }
                }
            }
        }
        $already_in_cart = in_array( $product_id, $cart_sample_ids );
    }
    // --- END ISOLATED CART CHECK ---

    // 4. Determine Button State
    $button_text  = 'ADD SAMPLE';
    $button_class = 'starke-sample-btn';
    $action_type  = 'add';
    $disabled     = '';
    $login_url    = '';

    // Check if the logged-in user is flagged as "Limited Access"
    $is_limited = false;
    if ( is_user_logged_in() ) {
        $is_limited = get_user_meta( get_current_user_id(), '_starke_account_access_level', true ) === 'limited';
    }

    if ( ! is_user_logged_in() ) {
        $action_type = 'login';
        $login_url   = get_permalink( get_option('woocommerce_myaccount_page_id') );
    } 
    elseif ( $is_out_of_stock ) {
        if ( $already_requested ) {
            $button_text  = 'SAMPLE REQUESTED';
            $button_class .= ' disabled requested';
            $action_type  = 'none';
            $disabled     = 'disabled';
        } else {
            $button_text  = 'REQUEST SAMPLE <span class="starke-oos-subtext">(Sample Out of Stock)</span>';
            $button_class .= ' request-mode';
            $action_type  = $is_limited ? 'limited' : 'request';
        }
    }
    else {
        // NEW: Check if it's already in the cart.
        if ( $already_in_cart ) {
            $button_text  = 'SAMPLE ADDED';
            $button_class .= ' disabled added';
            $action_type  = 'none';
            $disabled     = 'disabled';
        } else {
            if ( $is_limited ) {
                $action_type = 'limited';
            }
        }
    }

    // 5. Output HTML
    ob_start();
    ?>
    <button 
        type="button" 
        class="<?php echo esc_attr( $button_class ); ?>" 
        data-product-id="<?php echo esc_attr( $product_id ); ?>"
        data-action="<?php echo esc_attr( $action_type ); ?>"
        data-nonce="<?php echo wp_create_nonce( 'wp_rest' ); ?>"
        <?php if ( $action_type === 'login' ) : ?>
            data-login-url="<?php echo esc_url( $login_url ); ?>"
        <?php endif; ?>
        <?php echo $disabled; ?>
    >
        <?php echo $button_text; ?>
    </button>
    <?php
    return ob_get_clean();
}

/**
 * STARKE: COMPARE BUTTON SHORTCODE (Direct JS Version)
 * Usage: [starke_compare_button]
 */
add_shortcode( 'starke_compare_button', 'starke_render_compare_button_shortcode' );

function starke_render_compare_button_shortcode() {
    // 1. Get Current Product
    if ( function_exists( 'wpgb_get_post' ) ) {
        $post_object = wpgb_get_post();
    } else {
        global $post;
        $post_object = $post;
    }

    if ( ! $post_object ) { return ''; }
    
    $product_id = $post_object->ID;

    // --- Hide button for custom profiles ---
    if ( function_exists('is_custom_profile') && is_custom_profile( $product_id ) ) {
        return '';
    }

    // 2. Check "Is Added" State (via Cookie)
    $is_added = false;
    if ( isset( $_COOKIE['br_products_compare'] ) ) {
        $compare_list = explode( ',', $_COOKIE['br_products_compare'] );
        if ( in_array( $product_id, $compare_list ) ) {
            $is_added = true;
        }
    }

    $button_text = $is_added ? 'ADDED' : 'COMPARE';
    $checked_attr = $is_added ? 'checked' : '';
    $active_class = $is_added ? 'active' : '';

    ob_start();
    ?>
    <div class="starke-compare-wrapper" data-product-id="<?php echo esc_attr( $product_id ); ?>">
        
        <button type="button" class="starke-compare-btn <?php echo $active_class; ?>">
            <span class="compare-text"><?php echo esc_html( $button_text ); ?></span>
            <div class="checkbox-wrapper">
                <input type="checkbox" class="compare-checkbox" <?php echo $checked_attr; ?> tabindex="-1">
            </div>
        </button>

    </div>
    <?php
    return ob_get_clean();
}

// ---------------------------------------------------------
// MASTER LISTS (Defined in the exact order you want)
// This is just a data array. Very fast.
// ---------------------------------------------------------
function sm_get_molding_master_lists() {
    return [
        'thickness' => [
            '0" - 1/4"'       => [0, 0.25],
            '1/4" - 1/2"'     => [0.25, 0.5],
            '1/2" - 3/4"'     => [0.5, 0.75],
            '3/4" - 1"'       => [0.75, 1],
            '1" - 1 1/4"'     => [1, 1.25],
            '1 1/4" - 1 1/2"' => [1.25, 1.5],
            '1 1/2" - 1 3/4"' => [1.5, 1.75],
            '1 3/4" - 2"'     => [1.75, 2],
            '2" - 2 1/4"'     => [2, 2.25],
            '2 1/4" - 2 1/2"' => [2.25, 2.5],
            '2 1/2" - 2 3/4"' => [2.5, 2.75],
            '2 3/4" - 3"'     => [2.75, 3],
            '3" - 3 1/4"'     => [3, 3.25],
            '3 1/4" - 3 1/2"' => [3.25, 3.5],
            '3 1/2" - 3 3/4"' => [3.5, 3.75],
            '3 3/4" - 4"'     => [3.75, 4],
            '4" - 4 1/4"'     => [4, 4.25],
        ],
        'width' => [
            '0" - 1"'   => [0, 1],
            '1" - 2"'   => [1, 2],
            '2" - 3"'   => [2, 3],
            '3" - 4"'   => [3, 4],
            '4" - 5"'   => [4, 5],
            '5" - 6"'   => [5, 6],
            '6" - 7"'   => [6, 7],
            '7" - 8"'   => [7, 8],
            '8" - 9"'   => [8, 9],
            '9" - 10"'  => [9, 10],
            '10" - 11"' => [10, 11],
            '11" - 12"' => [11, 12],
        ],
        'projection' => [
            '0" - 1"' => [0, 1],
            '1" - 2"' => [1, 2],
            '2" - 3"' => [2, 3],
            '3" - 4"' => [3, 4],
            '4" - 5"' => [4, 5],
            '5" - 6"' => [5, 6],
            '6" - 7"' => [6, 7],
        ]
    ];
}

// ---------------------------------------------------------
// REGISTER TAXONOMIES
// ---------------------------------------------------------
add_action( 'init', 'sm_register_and_seed_molding_taxonomies' );

function sm_register_and_seed_molding_taxonomies() {
    $taxonomies = [
        'molding_thickness_range' => 'Thickness Range',
        'molding_width_range'     => 'Width Range',
        'molding_wall_proj'       => 'Wall Projection Range',
        'molding_ceil_proj'       => 'Ceiling Projection Range',
    ];

    foreach ( $taxonomies as $slug => $label ) {
        register_taxonomy( $slug, 'product', [
            'label'             => $label,
            'public'            => true,
            'hierarchical'      => true, 
            'show_ui'           => true,   
            'show_in_menu'      => false,  
            'show_admin_column' => false,  
            'show_in_nav_menus' => false,
            'show_in_rest'      => true,   
        ]);
    }

    /**
     * EFFICIENCY UPDATE:
     * This function call is now commented out. 
     * The terms are already created in your database. 
     * We do not need to check/create them on every single page load.
     * If you ever add NEW ranges to the master list above, uncomment this line ONCE, 
     * load the site, and then comment it out again.
     */
    // sm_ensure_terms_exist_in_order(); 
}

// This function acts as a utility belt for the import script.
// It is safe to keep because it is only called by import.php, not by page loads.
function sm_calculate_molding_terms( $type, $value, $min = 0, $max = 0 ) {
    $terms_to_apply = [];
    $master_data = sm_get_molding_master_lists();
    $ranges = [];

    if ( $type === 'thickness' ) $ranges = $master_data['thickness'];
    elseif ( $type === 'width' ) $ranges = $master_data['width'];
    elseif ( in_array( $type, ['wall_proj', 'ceil_proj'] ) ) $ranges = $master_data['projection'];

    $value = floatval( $value );
    $min   = floatval( $min );
    $max   = floatval( $max );

    $p_min = ( $min > 0 || $max > 0 ) ? $min : $value;
    $p_max = ( $min > 0 || $max > 0 ) ? $max : $value;

    if ( $p_max == 0 ) $p_max = $p_min;
    if ( $p_min == 0 ) $p_min = $p_max;

    foreach ( $ranges as $term_name => $bounds ) {
        $r_min = $bounds[0];
        $r_max = $bounds[1];
        if ( $p_max >= $r_min && $p_min <= $r_max ) {
            $terms_to_apply[] = $term_name;
        }
    }
    return $terms_to_apply;
}

// You can keep the `sm_ensure_terms_exist_in_order` function definition here 
// in case you need it later, but since it isn't called, it uses 0 resources.
function sm_ensure_terms_exist_in_order() {
    global $wpdb; 
    $lists = sm_get_molding_master_lists();
    $map = [
        'molding_thickness_range' => 'thickness',
        'molding_width_range'     => 'width',
        'molding_wall_proj'       => 'projection',
        'molding_ceil_proj'       => 'projection',
    ];
    foreach ( $map as $tax_slug => $list_key ) {
        if ( ! taxonomy_exists( $tax_slug ) ) continue;
        $terms_ordered = array_keys( $lists[ $list_key ] );
        foreach ( $terms_ordered as $index => $term_label ) {
            if ( ! term_exists( $term_label, $tax_slug ) ) {
                wp_insert_term( $term_label, $tax_slug );
            }
            $term = get_term_by( 'name', $term_label, $tax_slug );
            if ( $term && isset( $term->term_taxonomy_id ) ) {
                $wpdb->update(
                    $wpdb->term_taxonomy,
                    [ 'term_order' => $index ],
                    [ 'term_taxonomy_id' => $term->term_taxonomy_id ],
                    [ '%d' ], [ '%d' ]
                );
            }
        }
    }
}

/**
 * Force WP Grid Builder to sort specific facets.
 * EFFICIENCY: This is highly efficient (sorting ~20 items in memory).
 * It only runs when a Grid is loaded.
 */
add_filter( 'wp_grid_builder/facet/choices', 'sm_force_wpgb_facet_order', 10, 2 );

function sm_force_wpgb_facet_order( $choices, $facet ) {
    
    // 1. Identify the Facet Source
    $source = isset( $facet['source'] ) ? $facet['source'] : 'unknown';
    
    // Map your sources
    $map = [
        'taxonomy/molding_thickness_range' => 'thickness',
        'taxonomy/molding_width_range'     => 'width',
        'taxonomy/molding_wall_proj'       => 'projection',
        'taxonomy/molding_ceil_proj'       => 'projection',
    ];

    if ( ! isset( $map[ $source ] ) ) {
        return $choices;
    }

    if ( empty( $choices ) ) {
        return $choices;
    }

    // 2. Get Master List
    if ( ! function_exists( 'sm_get_molding_master_lists' ) ) {
        return $choices;
    }
    
    $master_lists = sm_get_molding_master_lists();
    $list_key = $map[ $source ];
    
    if ( ! isset( $master_lists[ $list_key ] ) ) {
         return $choices;
    }

    $correct_order = array_keys( $master_lists[ $list_key ] );

    // 3. Sort
    usort( $choices, function( $a, $b ) use ( $correct_order ) {
        $name_a = is_object( $a ) ? $a->facet_name : ( isset( $a['name'] ) ? $a['name'] : '' );
        $name_b = is_object( $b ) ? $b->facet_name : ( isset( $b['name'] ) ? $b['name'] : '' );
        
        $name_a = trim( strip_tags( $name_a ) );
        $name_b = trim( strip_tags( $name_b ) );

        $pos_a = array_search( $name_a, $correct_order );
        $pos_b = array_search( $name_b, $correct_order );

        if ( $pos_a === false ) $pos_a = 999;
        if ( $pos_b === false ) $pos_b = 999;

        return $pos_a - $pos_b;
    } );

    return $choices;
}

/**
 * -----------------------------------------------------------------------------
 * WPGB INDEXER: MERGE 'STYLE' AND 'STYLE_2' (Optimized)
 * -----------------------------------------------------------------------------
 * Hook: wp_grid_builder/indexer/index_object
 * Target: 'molding_style' facet.
 * Logic: Merges ACF 'style' and 'style_2' into a single indexable list.
 */
add_filter( 'wp_grid_builder/indexer/index_object', 'sm_merge_styles_optimized', 10, 3 );

function sm_merge_styles_optimized( $rows, $object_id, $facet ) {

    // 1. EFFICIENCY: Target ONLY the specific facet slug
    if ( ! isset( $facet['slug'] ) || 'molding_style' !== $facet['slug'] ) {
        return $rows;
    }

    // 2. GET DATA: Retrieve raw data from both ACF fields
    $style_1 = get_post_meta( $object_id, 'style', true );
    $style_2 = get_post_meta( $object_id, 'style_2', true );

    // Efficiency: If both are empty, exit early to save processing
    if ( empty( $style_1 ) && empty( $style_2 ) ) {
        return $rows;
    }

    $combined_values = [];

    // 3. MERGE: Handle Style 1 (String or Array)
    if ( ! empty( $style_1 ) ) {
        if ( is_array( $style_1 ) ) {
            $combined_values = array_merge( $combined_values, $style_1 );
        } else {
            $combined_values[] = $style_1;
        }
    }

    // 4. MERGE: Handle Style 2 (String or Array)
    if ( ! empty( $style_2 ) ) {
        if ( is_array( $style_2 ) ) {
            $combined_values = array_merge( $combined_values, $style_2 );
        } else {
            $combined_values[] = $style_2;
        }
    }

    // 5. CLEANUP: Remove duplicates and empty strings
    $combined_values = array_unique( array_filter( $combined_values ) );

    // 6. FORMAT: Build rows for the indexer
    $new_rows = [];
    
    foreach ( $combined_values as $value ) {
        // Ensure value is a string for display/indexing
        $str_value = is_array($value) ? json_encode($value) : (string)$value;

        $new_rows[] = [
            'facet_value' => $str_value,
            'facet_name'  => ucfirst( $str_value ), // Capitalize for display
        ];
    }

    return $new_rows;
}

/*
 * ==============================================================================
 * FOR REMOVING DEAD FILTERS FROM WP GRID BUILDER FACETS (ENABLES MULTIPLE FILTER SELECTION WITHIN SAME FACET) -- START
 * ==============================================================================
*/
// 0. CONFIGURATION
function sm_get_facet_config() {
    return [
        'molding_category'                => [ 'type' => 'taxonomy', 'source' => 'product_cat' ],
        'molding_type'                    => [ 'type' => 'taxonomy', 'source' => 'product_tag' ],
        'molding_style'                   => [ 'type' => 'meta',     'source' => ['style', 'style_2'] ],
        'molding_thickness'               => [ 'type' => 'taxonomy', 'source' => 'molding_thickness_range' ],
        'molding_width'                   => [ 'type' => 'taxonomy', 'source' => 'molding_width_range' ],
        'molding_projection_from_wall'    => [ 'type' => 'taxonomy', 'source' => 'molding_wall_proj' ],
        'molding_projection_from_ceiling' => [ 'type' => 'taxonomy', 'source' => 'molding_ceil_proj' ]
    ];
}

// 0. SHARED MEMORY
function sm_global_state_manager( $key, $value = null ) {
    static $storage = [];
    if ( $value !== null ) $storage[ $key ] = $value;
    return isset( $storage[ $key ] ) ? $storage[ $key ] : null;
}

// 1. CAPTURE STATE
add_filter( 'wp_grid_builder/facet/query_string', 'sm_capture_selected_facets', 10, 3 );

function sm_capture_selected_facets( $query_string, $grid_id, $action ) {
    $config = sm_get_facet_config();
    foreach ( array_keys($config) as $slug ) {
        if ( ! empty( $query_string[ $slug ] ) ) {
            sm_global_state_manager( 'selected_' . $slug, $query_string[ $slug ] );
        } else {
            sm_global_state_manager( 'selected_' . $slug, [] );
        }
    }
    return $query_string;
}

// HELPER: Build Test Query
function sm_build_zombie_test_query( $target_slug, $target_value, $context_override = null ) {
    $config = sm_get_facet_config();
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'tax_query'      => [ 'relation' => 'AND' ],
        'meta_query'     => [ 'relation' => 'AND' ]
    ];

    $targets = [ $target_slug => is_array($target_value) ? $target_value : [$target_value] ];

    // Determine Context
    if ( $context_override !== null ) {
        // Use Clean Context
        foreach ( $context_override as $slug => $vals ) {
            if ( $slug !== $target_slug && ! empty($vals) ) $targets[$slug] = $vals;
        }
    } else {
        // Default: Use Global State (Dirty)
        foreach ( array_keys($config) as $slug ) {
            if ( $slug === $target_slug ) continue;
            $s = sm_global_state_manager( 'selected_' . $slug );
            if ( ! empty($s) ) $targets[$slug] = $s;
        }
    }

    foreach ( $targets as $slug => $terms ) {
        $conf = $config[$slug];
        if ( $conf['type'] === 'taxonomy' ) {
            $args['tax_query'][] = [
                'taxonomy' => $conf['source'], 'field' => 'slug', 'terms' => $terms
            ];
        } elseif ( $conf['type'] === 'meta' ) {
            $meta_sub = [ 'relation' => 'OR' ];
            $meta_keys = is_array($conf['source']) ? $conf['source'] : [ $conf['source'] ];
            foreach ( $meta_keys as $key ) {
                foreach ( $terms as $term_val ) {
                    $formatted_val = ucwords( str_replace( '-', ' ', $term_val ) );
                    $meta_sub[] = [ 'key' => $key, 'value' => $term_val, 'compare' => 'LIKE' ];
                    if ( $formatted_val !== $term_val ) {
                        $meta_sub[] = [ 'key' => $key, 'value' => $formatted_val, 'compare' => 'LIKE' ];
                    }
                }
            }
            $args['meta_query'][] = $meta_sub;
        }
    }

    if ( empty( $args['tax_query'] ) ) unset( $args['tax_query'] );
    if ( empty( $args['meta_query'] ) ) unset( $args['meta_query'] );

    return $args;
}

// HELPER: Get Clean Context (Calculates which selected filters are actually ALIVE)
function sm_get_clean_context() {
    static $clean_context = null;
    if ( $clean_context !== null ) return $clean_context;

    $config = sm_get_facet_config();
    $clean_context = [];

    // Check every selected filter against the others to see if it's dead
    foreach ( array_keys($config) as $slug ) {
        $selected = sm_global_state_manager( 'selected_' . $slug );
        if ( empty( $selected ) ) continue;

        $alive_terms = [];
        foreach ( $selected as $term ) {
            // Check if this term is valid with the OTHER raw selections
            $args = sm_build_zombie_test_query( $slug, $term ); 
            $check = new WP_Query( $args );
            
            if ( $check->have_posts() ) {
                $alive_terms[] = $term;
            }
        }
        
        if ( ! empty( $alive_terms ) ) {
            $clean_context[$slug] = $alive_terms;
        }
    }
    return $clean_context;
}

// 2. CHOICES FILTER (Targeted & Context Aware)
add_filter( 'wp_grid_builder/facet/choices', 'sm_force_zombie_counts_to_zero', 10, 2 );

function sm_force_zombie_counts_to_zero( $choices, $facet ) {

    $config = sm_get_facet_config();
    if ( ! isset( $config[ $facet['slug'] ] ) ) return $choices;

    // OPTIMIZATION: Only run on facets with active selections
    // If a facet isn't selected, WPGB handles it perfectly. We skip it.
    $my_selected = sm_global_state_manager( 'selected_' . $facet['slug'] );
    if ( empty( $my_selected ) ) {
        return $choices;
    }

    // 1. Get the Clean Context (Cached for performance)
    // This tells us "Casings" is dead, but "Crowns" is alive.
    $clean_context = sm_get_clean_context();

    // 2. Process Choices (Siblings)
    foreach ( $choices as $key => $choice ) {
        
        // Skip the user's current selection (Handled by Response Filter)
        if ( in_array( $choice->facet_value, $my_selected ) ) continue;

        // TEST: Is this sibling valid given the CLEAN context?
        // We must pass $clean_context to override the dirty global state.
        $args = sm_build_zombie_test_query( $facet['slug'], $choice->facet_value, $clean_context );
        $check = new WP_Query( $args );

        if ( ! $check->have_posts() ) {
            $choices[$key]->count = 0; // Dependent Zombie found!
        }
    }

    return $choices;
}

// 3. RESPONSE FILTER
add_filter( 'wp_grid_builder/facet/response', 'sm_identify_selected_zombies', 10, 3 );

function sm_identify_selected_zombies( $response, $facet, $choices ) {
    if ( empty( $facet['selected'] ) ) return $response;

    $choice_map = [];
    foreach ( $choices as $choice ) {
        $choice_map[ $choice->facet_value ] = (int) $choice->count;
    }

    $zombies = [];
    foreach ( $facet['selected'] as $val ) {
        $count = isset( $choice_map[ $val ] ) ? $choice_map[ $val ] : -1;
        if ( $count === 0 || $count === -1 ) {
            $zombies[] = $val;
        }
    }

    if ( ! empty( $zombies ) ) {
        $response['invalid_selections'] = $zombies;
    }
    return $response;
}

// 4. JS INTERCEPTOR
add_action( 'wp_footer', 'sm_execute_zombie_fix_final_v3', 99 );

function sm_execute_zombie_fix_final_v3() {
    ?>
    <script type="text/javascript">
    (function($) {
        var wpgb_instance = null;
        if ( typeof window.WP_Grid_Builder !== 'undefined' ) {
            window.WP_Grid_Builder.on( 'init', function( instance ) { wpgb_instance = instance; });
        }

        var originalParse = JSON.parse;
        JSON.parse = function(text, reviver) {
            var data = originalParse(text, reviver);
            try {
                if ( data && data.facets && data.posts ) {
                    setTimeout(function() { processPacket(data); }, 10);
                }
            } catch (err) { }
            return data;
        };

        function processPacket(response) {
            if ( ! wpgb_instance ) return;
            var killOrders = {};
            Object.keys(response.facets).forEach(function(id) {
                var f = response.facets[id];
                if ( f.slug && f.invalid_selections && f.invalid_selections.length > 0 ) {
                    killOrders[f.slug] = f.invalid_selections;
                }
            });

            if ( Object.keys(killOrders).length === 0 ) return;

            $('.wpgb-facet').each(function() {
                var $el = $(this);
                var $input = $el.find('input[name]');
                if ( ! $input.length ) return;
                var slug = $input.attr('name').replace('[]', '');

                if ( killOrders[slug] ) {
                    var zombies = killOrders[slug];
                    if ( wpgb_instance.facets ) wpgb_instance.facets.deleteParams( slug, zombies );
                    zombies.forEach(function(val) {
                        var $chk = $el.find('input[value="' + val + '"]');
                        if ( $chk.length ) {
                            $chk.prop('checked', false);
                            $chk.closest('.wpgb-button, .wpgb-checkbox')
                                .attr('aria-pressed', 'false')
                                .removeClass('wpgb-checked');
                        }
                    });
                }
            });
        }
    })(jQuery);
    </script>
    <?php
}
/*
 * ==============================================================================
 * FOR REMOVING DEAD FILTERS FROM WP GRID BUILDER FACETS (ENABLES MULTIPLE FILTER SELECTION WITHIN SAME FACET) -- END
 * ==============================================================================
*/














/**
 * ===============================================================
 * SearchWP: Numeric SKU & Partial Match Logic, etc --- START
 * ===============================================================
*/

// 1. Allow Partial Matches for short terms (Fixes the "CA" -> "Casings" issue)
add_filter( 'searchwp\query\partial_matches\minimum_length', function() {
    return 1;
});

// 2. Force Partial Matching even if an Exact Match is found (Fixes "100" vs "1001")
add_filter( 'searchwp\query\partial_matches\force', '__return_true' );

// 3. Prevent strict numeric tokenization (Fixes "100" being treated as an integer)
add_filter( 'searchwp\tokens\strict', '__return_false' );

// 4. Configure "Starts With" Logic (100 -> 100*)
add_filter( 'searchwp\query\partial_matches', '__return_true' ); // Turn feature on
add_filter( 'searchwp\query\partial_matches\wildcard_before', '__return_false' ); // Disable leading wildcard
add_filter( 'searchwp\query\partial_matches\wildcard_after', '__return_true' );  // Enable trailing wildcard

// 5. SAFETY NET: Increase the Token Limit (MISSING IN YOUR CODE)
// Ensures that if you have 50 products starting with "100", search checks all of them, not just the first 10.
add_filter( 'searchwp\query\tokens\limit', function() {
    return 500;
});

// 6. Clean "Junk" Tokens (Master Filter - Guaranteed to Run)
// Corrected to use the 'searchwp\query\tokens' hook for reliability
add_filter( 'searchwp\query\tokens', function( $tokens, $args ) {
    $clean_tokens = [];

    foreach ( $tokens as $token ) {
        // If the token contains a space, it's a "ghost" variation/image size. Skip it.
        if ( strpos( $token, ' ' ) !== false ) {
            continue; 
        }
        $clean_tokens[] = $token;
    }

    return $clean_tokens;
}, 999, 2 );

/**
 * WP Grid Builder: Search Logic Fixes (Final "Clean UI" Version)
 * 1. PERSISTENCE: Syncs search term into API params.
 * 2. AUTO-RESET: Handles Backspace & Clear (X) Button.
 * 3. UI FIXES: Updates Reset Button ONLY after grid load to prevent flickering.
 */
add_action( 'wp_footer', function() {
    ?>
    <script type="text/javascript">
    (function($) {
        
        var targetSlug = 'molding_search'; 

        // --- 1. GLOBAL CLICK HANDLERS (Capture Phase) ---
        document.addEventListener('click', function(e) {
            
            // A. Handle Search "X" Button
            var clearBtn = e.target.closest('.wpgb-clear-button');
            if ( clearBtn ) {
                var $facet = $(clearBtn).closest('.wpgb-facet');
                if ( $facet.find('input[name="' + targetSlug + '"]').length > 0 ) {
                    triggerGlobalReset( 'Search Clear (X)' );
                }
            }

            // B. Handle Master "Reset" Facet Button
            var resetBtn = e.target.closest('.wpgb-facet-reset button, .wpgb-reset');
            if ( resetBtn ) {
                if ( ! resetBtn.disabled || resetBtn.classList.contains('wpgb-disabled') === false ) {
                    triggerGlobalReset( 'Master Reset Button' );
                }
            }

        }, true); 

        // Helper to reset the specific search facet
        function triggerGlobalReset( source ) {
            // console.log('WPGB Fix: ' + source + ' clicked. Forcing API Reset.');
            
            if ( window.WP_Grid_Builder && window.WP_Grid_Builder.instances ) {
                Object.keys( window.WP_Grid_Builder.instances ).forEach( function( id ) {
                    var instance = window.WP_Grid_Builder.instances[id];
                    if ( instance.facets ) {
                        instance.facets.reset( [targetSlug] );
                        
                        if ( source === 'Master Reset Button' ) {
                             instance.facets.reset(); // Reset everything
                        }
                        
                        instance.facets.refresh();
                    }
                });
            }
        }

        // --- 2. GLOBAL INPUT HANDLER (Backspace Support) ---
        $(document).on('input', 'input[name="' + targetSlug + '"]', function() {
            
            // REMOVED: updateResetButtonState(); 
            // We removed the immediate UI update so it doesn't flicker before the grid reloads.

            if ( $(this).val().length === 0 ) {
                triggerGlobalReset('Backspace');
            }
        });

        // --- 3. UI HELPER: Force Reset Button Active ---
        function updateResetButtonState() {
            var $input = $('input[name="' + targetSlug + '"]');
            var val = ($input.length) ? $input.val().trim() : '';
            
            var $resetBtn = $('.wpgb-facet-reset button, .wpgb-reset');

            if ( $resetBtn.length ) {
                if ( val.length > 0 ) {
                    // Enable if search has text
                    $resetBtn.removeClass('wpgb-disabled');
                    $resetBtn.prop('disabled', false);
                    $resetBtn.attr('aria-disabled', 'false');
                } else {
                    // Optionally let WPGB handle disabling, or enforce it here if needed
                    // For now, we only force-enable to fix the bug.
                }
            }
        }

        // --- 4. INSTANCE LOGIC (Persistence) ---
        function attachLogic( wpgb ) {
            if ( ! wpgb.facets ) return;
            if ( wpgb.starke_attached ) return;
            wpgb.starke_attached = true;

            // EVENT: 'change' (Syncs search term into API params)
            wpgb.facets.on( 'change', function( slug ) {
                
                if ( slug === targetSlug ) return; 

                var $input = $('input[name="' + targetSlug + '"]');
                
                if ( $input.length ) {
                    var visualValue = $input.val().trim();
                    var internalParams = wpgb.facets.getParams( targetSlug );
                    
                    if ( visualValue !== '' && ( !internalParams || internalParams.length === 0 ) ) {
                        wpgb.facets.setParams( targetSlug, [visualValue] );
                    }
                }
                
                // REMOVED: immediate UI update here. 
                // We wait for the 'loaded' event instead.
            });

            // EVENT: 'loaded' (Re-apply UI state AFTER grid redraw)
            wpgb.facets.on( 'loaded', function() {
                // This is the correct time to update the button
                // The grid is done loading, the grey-out is gone.
                setTimeout(updateResetButtonState, 100);
            });
        }

        // --- 5. INITIALIZATION ---
        var initInterval = setInterval(function() {
            if ( typeof window.WP_Grid_Builder !== 'undefined' ) {
                
                if ( ! window.WP_Grid_Builder.starke_listener_added ) {
                    window.WP_Grid_Builder.on( 'init', function( wpgb ) {
                        attachLogic( wpgb );
                    });
                    window.WP_Grid_Builder.starke_listener_added = true;
                }

                if ( window.WP_Grid_Builder.instances ) {
                    var keys = Object.keys( window.WP_Grid_Builder.instances );
                    if ( keys.length > 0 ) {
                        keys.forEach( function( id ) {
                            attachLogic( window.WP_Grid_Builder.instances[id] );
                        });
                        clearInterval(initInterval);
                        
                        // Initial Check
                        setTimeout(updateResetButtonState, 500);
                    }
                }
            }
        }, 50);

    })(jQuery);
    </script>
    <?php
}, 999 );


















/**
 * STARKE SYNONYMS (Shop Isolation)
 * 1. Shop Search: "Profile" = "Molding" (Shows Products)
 * 2. Header Search: "Profile" = "Profile" (Shows Doors naturally)
 */
add_filter( 'searchwp\synonyms', function( $synonyms, $args ) {

    // 1. Check if this search is coming from WP Grid Builder (The Shop)
    $is_wpgb = ( isset( $_REQUEST['wpgb'] ) || ( isset( $_REQUEST['action'] ) && strpos( $_REQUEST['action'], 'wpgb' ) !== false ) );

    // 2. Also check specifically for the Molding Engine (just in case)
    $target_engine = 'molding_search_engine';
    $is_shop_engine = ( isset( $args['engine'] ) && $args['engine'] === $target_engine );

    // 3. Apply the rule ONLY if we are on the Shop
    if ( $is_wpgb || $is_shop_engine ) {
        $synonyms[] = [
            'sources'  => 'profile',
            'synonyms' => 'molding',
            'replace'  => false
        ];
        $synonyms[] = [
            'sources'  => 'profiles',
            'synonyms' => 'molding',
            'replace'  => false
        ];
    }

    return $synonyms;
}, 20, 2 );

/**
 * ===============================================================
 * SearchWP: Numeric SKU & Partial Match Logic, etc --- END
 * ===============================================================
*/



/**
 * ===============================================================
 * STARKE COMPARE POPUP --- START
 * ===============================================================
 * Fix: Wrapped in wp_footer to prevent "Headers already sent" errors
 */

/**
 * ===============================================================
 * STARKE CUSTOM COMPARE TABLE (The "Own Table" Rewrite)
 * Replaces B-Rocket's complex grid with a single, clean, sticky table.
 * ===============================================================
 */

// 1. AJAX Handler to generate the custom table
add_action('wp_ajax_starke_load_compare_table', 'starke_load_compare_table_handler');
add_action('wp_ajax_nopriv_starke_load_compare_table', 'starke_load_compare_table_handler');

function starke_load_compare_table_handler() {
    // A. Security & Setup
    $cookie_name = 'br_products_compare';
    $product_ids = [];
    
    if (isset($_COOKIE[$cookie_name])) {
        $cookie_val = urldecode($_COOKIE[$cookie_name]);
        $product_ids = explode(',', $cookie_val);
    }
    
    // Clean IDs
    $product_ids = array_unique(array_filter($product_ids, function($id) { 
        return is_numeric($id) && $id > 0; 
    }));
    
    if (empty($product_ids)) {
        wp_send_json_success('<div class="starke-compare-empty" style="padding:40px; text-align:center; color:#fff;">Your compare list is empty.</div>');
    }

    // B. Define Attributes (Label => Meta Key)
    $attributes = [
        'THICKNESS' => 'thickness',
        'WIDTH' => 'width',
        'MIN THICKNESS' => 'min_thickness',
        'MAX THICKNESS' => 'max_thickness',
        'MIN WIDTH' => 'min_width',
        'MAX WIDTH' => 'max_width',
        'PROJECTION FROM WALL' => 'projection_from_wall',
        'PROJECTION FROM CEILING' => 'projection_from_ceiling',
        'CATEGORY' => 'type',
        
    ];

    // C. Fetch Data
    $products_data = [];

    // 1. DEFINE KEYS TO CONVERT
    // These are the meta keys that store decimals but should display as fractions
    $fraction_keys = [
        'thickness', 
        'width', 
        'min_thickness', 
        'max_thickness', 
        'min_width', 
        'max_width', 
        'projection_from_wall', 
        'projection_from_ceiling'
    ];

    foreach ($product_ids as $id) {
        $product = wc_get_product($id);
        if (!$product) continue;
        
        $btn_html = do_shortcode('[starke_sample_button id="' . $id . '"]');
        $pill_html = do_shortcode('[molding_custom_label id="' . $id . '"]');
        
        // Cleanup shortcodes if they failed to render
        if ( strpos($btn_html, '[starke_sample_button') !== false ) $btn_html = '';
        if ( strpos($pill_html, '[molding_custom_label') !== false ) $pill_html = '';

        if(empty($pill_html) || $pill_html === '') {
             $pill_html = '<span class="starke-custom-label starke-compare-pill starke-pill-placeholder">Placeholder</span>';
        }

        $p_data = [
            'id' => $id,
            'title' => $product->get_name(),
            'link' => $product->get_permalink(),
            'image' => $product->get_image('woocommerce_thumbnail'),
            'actions' => '<div class="starke-compare-actions-col">' . $pill_html . $btn_html . '</div>',
            'attrs' => []
        ];

        foreach ($attributes as $label => $key) {
            $val = get_post_meta($id, $key, true);
            
            // --- CONVERSION LOGIC ---
            // If the key is in our "Dimensions" list and has a numeric value, convert it.
            if ( in_array( $key, $fraction_keys ) && $val !== '' && is_numeric( $val ) ) {
                // This reuses your existing function from line 90 of molding.php
                // It automatically adds the inch symbol (")
                $val = wpgb_decimal_to_fraction_string( $val );
            }
            // ------------------------

            $p_data['attrs'][$label] = $val;
        }
        $products_data[] = $p_data;
    }

    // ... end of $products_data loop ...

    // --- NEW: PRE-CALCULATE IF BUTTON IS NEEDED ---
    // We scan the data to see if ANY row has identical values across all products.
    $show_hide_button = false;
    
    // Only check if we have more than 1 product (comparing 1 product to itself is pointless)
    if ( count($products_data) > 1 ) {
        foreach ($attributes as $label => $key) {
            $first_val = $products_data[0]['attrs'][$label] ?? '';
            $is_row_identical = true;
            
            foreach ($products_data as $p) {
                // If any product has a different value, this row is NOT identical
                if ( ($p['attrs'][$label] ?? '') != $first_val ) {
                    $is_row_identical = false;
                    break; 
                }
            }
            
            // If we found even ONE identical row, we must show the button
            if ( $is_row_identical ) {
                $show_hide_button = true;
                break; 
            }
        }
    }
    // ----------------------------------------------

    // D. Build HTML
    ob_start();
    ?>
    <div class="starke-compare-wrapper-custom">
        <table class="starke-compare-table">
            <thead>
                <tr>
                    <th class="starke-corner-cell">
                        <div class="starke-corner-buttons">
                            <?php if ( $show_hide_button ): ?>
                                <a href="#" class="starke-action-btn" onclick="starkeToggleSame(this); return false;">
                                    Hide attributes with same values
                                </a>
                            <?php endif; ?>
                            
                            <a href="#" class="starke-action-btn" onclick="starkeCopyShareLink(this); return false;">
                                <i class="fa fa-share-alt" style="margin-right: 8px;"></i> Share Comparison
                            </a>

                            <a href="#" class="starke-action-btn" onclick="starkeClearAll(); return false;">
                                Clear compare list
                            </a>
                        </div>
                    </th>
                    
                    <?php foreach ($products_data as $p): ?>
                        <th class="starke-product-col">
                            <div class="starke-header-content">
                                <h3 class="starke-product-title">
                                    <a href="<?php echo esc_url($p['link']); ?>"><?php echo esc_html($p['title']); ?></a>
                                </h3>
                                <a href="#" class="starke-remove-icon" onclick="starkeRemoveOne(<?php echo $p['id']; ?>); return false;">
                                    <i class="fa fa-times"></i>
                                </a>
                                
                                <div class="starke-product-img">
                                    <a href="<?php echo esc_url($p['link']); ?>">
                                        <?php echo $p['image']; ?>
                                    </a>
                                </div>
                                
                                <?php echo $p['actions']; ?>
                            </div>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attributes as $label => $key): 
                    $first_val = $products_data[0]['attrs'][$label] ?? '';
                    $is_same = true;
                    foreach ($products_data as $p) {
                        if (($p['attrs'][$label] ?? '') != $first_val) {
                            $is_same = false;
                            break;
                        }
                    }
                    $row_class = $is_same ? 'starke-row-same' : '';
                ?>
                    <tr class="<?php echo $row_class; ?>">
                        <th class="starke-attr-label"><?php echo esc_html($label); ?></th>
                        
                        <?php foreach ($products_data as $p): ?>
                            <td class="starke-attr-val">
                                <?php echo esc_html($p['attrs'][$label] ?? ''); ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $html = ob_get_clean();
    wp_send_json_success($html);
}

// 2. JS Injector to Override Plugin Behavior (Final Robust Version)
add_action('wp_footer', function() {
    ?>
    <script type="text/javascript">
    jQuery(window).on('load', function() {
        (function($) {
            
            // CONSOLE DEBUG: Confirm the script is running
            console.log('Starke: Initializing Compare Override...');

            // ----------------------------------------------
            // SCROLLBAR WIDTH CALCULATOR (Prevents Layout Shift)
            // ----------------------------------------------
            // 1. Create a temporary div to measure the browser's scrollbar
            var scrollDiv = document.createElement("div");
            scrollDiv.style.cssText = "width: 100px; height: 100px; overflow: scroll; position: absolute; top: -9999px;";
            document.body.appendChild(scrollDiv);
            
            // 2. Calculate width (Offset - Client = Scrollbar Width)
            var scrollbarWidth = scrollDiv.offsetWidth - scrollDiv.clientWidth;
            document.body.removeChild(scrollDiv);
            
            // 3. Save it as a CSS Variable for style.css to use
            document.documentElement.style.setProperty('--starke-sb-width', scrollbarWidth + 'px');
            // ----------------------------------------------

            // HELPER: Apply gray color only to VISIBLE rows
            function starkeReStripe() {
                $('.starke-compare-table tbody tr').removeClass('starke-alt-row');
                $('.starke-compare-table tbody tr:visible').each(function(i) {
                    // Apply to every odd visible row (1st, 3rd, etc.)
                    if (i % 2 !== 0) $(this).addClass('starke-alt-row');
                });
            }

            // OVERRIDE: Hijack the plugin's load function
            window.load_smart_compare_table = function($popup_object) {
                
                var selector = (typeof the_compare_products_data !== 'undefined' && the_compare_products_data.compare_selector) 
                                ? the_compare_products_data.compare_selector 
                                : '#br_popup';
                
                var $target = $popup_object;

                if ( typeof($target) === 'undefined' || !$target || !$target.length ) {
                    $target = $(selector);
                }

                if ( !$target.length && $(selector).length ) {
                     var $owner = $(selector).data('br_popup_main');
                     if ( $owner && $($owner).length ) {
                         $target = $($owner);
                     } else {
                         $target = $(selector);
                     }
                }

                if ( !$target || !$target.length ) return;

                // 2. Call Custom AJAX with CACHE BUSTER
                // FIX: The _ts parameter forces the browser to fetch the new table state
                $.post(
                    '<?php echo admin_url('admin-ajax.php'); ?>', 
                    { 
                        action: 'starke_load_compare_table',
                        _ts: new Date().getTime() 
                    }, 
                    function(response) {
                        if( response.success ) {
                            console.log('Starke: AJAX success. Injecting content...');

                            // 1. Update the Active/Visible Popup (Fixes "Remove Button" updates)
                            // If the popup is already open, this updates the user's view immediately.
                            var $globalInner = $('.br_popup_inner');
                            if ( $globalInner.length ) {
                                $globalInner.html(response.data);
                            }

                            // 2. Update the Source Target (Fixes "First Open")
                            // If the popup hasn't been built yet, the global selector above finds nothing.
                            // We must populate the source $target so B-Rocket has content to initialize with.
                            $target.html('<div class="br_popup_inner">' + response.data + '</div>');
                            
                            // Seeding missing popup data
                            if ( typeof $target.data('br_popup_data') === 'undefined' ) {
                                $target.data('br_popup_data', { 
                                    opened: false,
                                    can_close_popup: true
                                });
                            }

                            // Initialize & Open
                            if ( typeof $target.br_popup === 'function' ) {
                                // Only initialize if not already set
                                if( !$target.data('br_popup_settings') ) {
                                     $target.br_popup(); 
                                }
                                
                                var data = $target.data('br_popup_data');
                                if ( data && !data.opened ) {
                                    $target.br_popup().open_popup();
                                }
                            }
                            
                            if( $('link[href*="font-awesome"]').length === 0 ) {
                                $('head').append('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">');
                            }

                            // Apply stripe colors immediately after popup loads
                            starkeReStripe();
                        }
                    }
                );
            };

            // Custom Helper Functions
            window.starkeRemoveOne = function(id) {
                // --- STEP 1: COOKIE HYGIENE (Fix Duplicates) ---
                var name = 'br_products_compare=';
                var decodedCookie = decodeURIComponent(document.cookie);
                var val = '';
                var ca = decodedCookie.split(';');
                
                // Get current value
                for(var i = 0; i < ca.length; i++) {
                    var c = ca[i].trim();
                    if (c.indexOf(name) == 0) val = c.substring(name.length, c.length);
                }
                
                // Filter out the ID we are removing
                var ids = val.split(',').filter(function(el) {
                    return el.length > 0 && !isNaN(el) && el != id; // Remove the ID
                });
                var newVal = ids.join(',');

                // A. Force Save to CORRECT Domain
                var correctDomain = '.www.starkemillwork.com';
                document.cookie = 'br_products_compare=' + newVal + '; Path=/; domain=' + correctDomain + '; Max-Age=' + (86400 * 7) + ';';

                // B. Force Delete "Bad" Domain (.starkemillwork.com)
                document.cookie = 'br_products_compare=; Path=/; domain=.starkemillwork.com; Max-Age=0; expires=Thu, 01 Jan 1970 00:00:00 UTC;';

                // C. Force Delete "Host Only" Duplicate (No domain set)
                document.cookie = 'br_products_compare=; Path=/; Max-Age=0; expires=Thu, 01 Jan 1970 00:00:00 UTC;';
                // -----------------------------------------------

                // 2. Perform the plugin removal (Updates UI/Internal State)
                if(typeof remove_products_compare === 'function') {
                    remove_products_compare(id);
                }

                // 3. SYNC: Shop Page Buttons
                var $wrapper = $('.starke-compare-wrapper[data-product-id="' + id + '"]');
                if ($wrapper.length) {
                    var $btn = $wrapper.find('.starke-compare-btn');
                    $btn.removeClass('active');
                    $btn.find('.compare-text').text('COMPARE');
                    $btn.find('.compare-checkbox').prop('checked', false);
                }

                // 4. SYNC: Single Product Page Button
                if (typeof window.starke3d_data !== 'undefined' && window.starke3d_data.productId == id) {
                    var $singleCompareBtn = $('#compare_button');
                    if ($singleCompareBtn.length) {
                        $singleCompareBtn.removeClass('active');
                        $singleCompareBtn.find('#compareText').text('COMPARE');
                        $singleCompareBtn.find('#compare_checkbox').prop('checked', false);
                        $singleCompareBtn.css('width', '');
                    }
                }

                // 5. CHECK IF EMPTY -> CLOSE POPUP
                if ( ids.length === 0 ) {
                    // List is empty -> Close Popup immediately
                    var $closeBtn = $('.br_popup_close');
                    
                    if ( $closeBtn.length ) {
                        $closeBtn.trigger('click');
                    } else {
                        // Fallback close
                        $('#br_popup, .br_popup_overlay').fadeOut();
                        $('body').removeClass('br_popup_open'); 
                    }
                } else {
                    // List still has items -> Reload Table
                    load_smart_compare_table();
                }
            };

            window.starkeClearAll = function() {
                // 1. Clear the Cookie (CORRECT DOMAIN)
                var correctDomain = '.www.starkemillwork.com';
                document.cookie = 'br_products_compare=; Path=/; domain=' + correctDomain + '; Max-Age=0; expires=Thu, 01 Jan 1970 00:00:00 UTC;';
                
                // 2. Clear Bad Domain (.starkemillwork.com)
                // Explicitly removes the duplicate you identified
                document.cookie = 'br_products_compare=; Path=/; domain=.starkemillwork.com; Max-Age=0; expires=Thu, 01 Jan 1970 00:00:00 UTC;';
                
                // 3. Clear Duplicates (Host Only / No Domain)
                document.cookie = 'br_products_compare=; Path=/; Max-Age=0; expires=Thu, 01 Jan 1970 00:00:00 UTC;';
                
                // 4. Remove any visual widgets on the page
                $('.br_widget_compare_product').remove(); 

                // 5. SYNC: Reset ALL compare buttons on the Shop Page immediately
                $('.starke-compare-btn').removeClass('active');
                $('.starke-compare-btn .compare-text').text('COMPARE');
                $('.starke-compare-btn .compare-checkbox').prop('checked', false);

                // 6. CLOSE THE POPUP (Simulate Click)
                var $closeBtn = $('.br_popup_close');
                
                if ( $closeBtn.length ) {
                    $closeBtn.trigger('click');
                } else {
                    // Fallback
                    $('#br_popup, .br_popup_overlay').fadeOut();
                    $('body').removeClass('br_popup_open'); 
                }
            };

            window.starkeToggleSame = function(btn) {
                $('.starke-row-same').toggle();
                $(btn).toggleClass('active');
                if($(btn).hasClass('active')) {
                    $(btn).text('Show attributes with same values');
                } else {
                    $(btn).text('Hide attributes with same values');
                }
                // Re-calculate stripes after hiding/showing
                starkeReStripe();
            };
            
            // --- NEW: Copy Share Link Function ---
            // This ONLY reads the cookie and copies to clipboard. It does not modify data.
            window.starkeCopyShareLink = function(btn) {
                var name = 'br_products_compare=';
                var decodedCookie = decodeURIComponent(document.cookie);
                var val = '';
                var ca = decodedCookie.split(';');
                
                // Read the existing cookie
                for(var i = 0; i < ca.length; i++) {
                    var c = ca[i].trim();
                    if (c.indexOf(name) == 0) val = c.substring(name.length, c.length);
                }
                
                // Clean IDs
                var cleanIds = val.split(',').filter(function(el) {
                    return el && el.trim().length > 0 && !isNaN(el) && el !== 'undefined';
                }).join(',');
                
                if (!cleanIds) {
                    alert('No products to share!');
                    return;
                }

                // Build Clean URL
                var cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                var shareUrl = cleanUrl + '?starke_share_compare=' + cleanIds;
                
                // Copy to Clipboard
                navigator.clipboard.writeText(shareUrl).then(function() {
                    var $btn = $(btn);
                    var originalHtml = $btn.html();
                    $btn.html('<i class="fa fa-check" style="margin-right:8px;"></i> Link Copied!');
                    $btn.addClass('active');
                    setTimeout(function(){ 
                        $btn.html(originalHtml); 
                        $btn.removeClass('active');
                    }, 2000);
                }).catch(function(err) {
                    console.error('Could not copy text: ', err);
                    alert('Could not copy link. Manually copy this URL:\n' + shareUrl);
                });
            };

            // --- NEW: Auto-Open on Share (Flash Cookie Method) ---
            // We check for the cookie set by PHP before the redirect.
            if ( document.cookie.indexOf('starke_trigger_popup=1') > -1 ) {
                //setTimeout(function() {
                    // 1. Open Popup
                    load_smart_compare_table();
                    
                    // 2. Delete the Trigger Cookie (So it doesn't open on every refresh)
                    // We delete both domain variations just to be safe.
                    var domain = '.' + window.location.hostname;
                    document.cookie = 'starke_trigger_popup=; Path=/; domain=' + domain + '; Max-Age=0; expires=Thu, 01 Jan 1970 00:00:00 UTC;';
                    document.cookie = 'starke_trigger_popup=; Path=/; Max-Age=0; expires=Thu, 01 Jan 1970 00:00:00 UTC;';
                //}, 100); 
            }

        })(jQuery);
    });
    </script>
    <?php
}, 1000);

/**
 * ===============================================================
 * STARKE COMPARE POPUP --- END
 * ===============================================================
*/

/**
 * ===============================================================
 * STARKE SAMPLE BUTTON: LOGIN HANDLER
 * ===============================================================
 * Handles clicks on [starke_sample_button] when the user is logged out.
 * The shortcode already sets data-action="login" in this scenario.
 */
add_action( 'wp_footer', 'starke_handle_sample_login_click', 100 );

function starke_handle_sample_login_click() {
    // Only run this script for non-logged-in users to keep page weight down
    if ( is_user_logged_in() ) {
        return;
    }
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        
        // Use event delegation to support WP Grid Builder AJAX loading
        $(document.body).on('click', '.starke-sample-btn[data-action="login"]', function(e) {
            
            e.preventDefault();
            e.stopPropagation(); // Stop any other click handlers

            // 1. Attempt to find the Header Login/Register Button (The Drawer)
            // Adjust the selector below if your header button class differs, 
            // but usually targeting the My Account link works for drawers.
            var $drawerBtn = $('header a[href*="my-account"]');

            if ( $drawerBtn.length ) {
                // Open the drawer
                $drawerBtn[0].click();
            } else {
                // Fallback: If no drawer button exists, redirect to the URL defined in the button
                var fallbackUrl = $(this).data('login-url');
                if ( fallbackUrl ) {
                    window.location.href = fallbackUrl;
                }
            }
        });

    });
    </script>
    <?php
}

/**
 * STARKE: FLOATING COMPARE BUTTON
 * Adds a persistent button to the bottom right.
 * CSS handles the positioning stability using calc(100vw).
 */
add_action('wp_footer', 'starke_render_floating_compare_button', 200);

function starke_render_floating_compare_button() {
    // 1. Don't show on Checkout, Cart, or Admin
    if ( is_admin() || is_checkout() || is_cart() || is_product() ) {
        return;
    }
    ?>
    <div id="starke-floating-compare" class="starke-floating-compare" style="display:none;">
        <button class="starke-compare-btn active" onclick="load_smart_compare_table();">
            <span class="compare-text">COMPARE <span id="starke-float-count"></span></span>
        </button>
    </div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        
        // --- Core Logic: Check Cookie & Show Button ---
        function checkCompareCookie() {
            var name = 'br_products_compare=';
            var decodedCookie = decodeURIComponent(document.cookie);
            var val = '';
            var ca = decodedCookie.split(';');
            
            // Smart Cookie Scanner
            for(var i = 0; i < ca.length; i++) {
                var c = ca[i];
                while (c.charAt(0) == ' ') c = c.substring(1);
                if (c.indexOf(name) == 0) val = c.substring(name.length, c.length);
            }
            
            var ids = val.split(',').filter(function(el) {
                return el.length > 0 && !isNaN(el);
            });
            var count = ids.length;
            
            // SELF-HEALING DOM:
            // If the span was deleted by the Clear All function, re-create it automatically.
            var $countSpan = $('#starke-float-count');
            if ( $countSpan.length === 0 ) {
                $('#starke-floating-compare .compare-text').html('COMPARE <span id="starke-float-count"></span>');
                $countSpan = $('#starke-float-count'); 
            }

            var $btnContainer = $('#starke-floating-compare');
            
            // UI Update Logic
            if ( count > 0 ) {
                $countSpan.text('(' + count + ')');
                if ( $btnContainer.is(':hidden') ) {
                     $btnContainer.fadeIn();
                }
            } else {
                if ( $btnContainer.is(':visible') ) {
                     $btnContainer.fadeOut();
                }
            }
        }

        // 1. Run on Page Load
        checkCompareCookie();

        // 2. Click Listener (Handles Add/Remove/Clear actions)
        $(document).on('click', '.starke-compare-btn, .starke-remove-icon, .starke-action-btn, .br_compare_button', function() {
            setTimeout(checkCompareCookie, 500);
        });
    });
    </script>
    <?php
}

/**
 * ===============================================================
 * STARKE SAMPLE BUTTON: LIMITED ACCESS HANDLER
 * ===============================================================
 * Intercepts clicks on the Sample button for restricted users,
 * opens the My Account drawer, and displays the Limited Access notice.
 */
add_action( 'wp_footer', 'starke_handle_sample_limited_click', 105 );

function starke_handle_sample_limited_click() {
    if ( ! is_user_logged_in() ) {
        return;
    }
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        
        $(document.body).on('click', '.starke-sample-btn[data-action="limited"]', function(e) {
            e.preventDefault();
            e.stopPropagation();

            // 1. Hide the DXF Architect message just in case it was previously triggered
            $('#starke-dxf-denial-msg').hide();
            
            // 2. Show the Limited Access message
            $('#starke-limited-access-msg').show();

            // 3. Open the drawer
            var $drawerBtn = $('header a[href*="my-account"]');
            if ( $drawerBtn.length ) {
                $drawerBtn[0].click();
            }
        });

    });
    </script>
    <?php
}

if ( is_admin() ) {
    // Creates custom 'Sample Inventory' column in WooCommerce Products admin list -- START
    
    /*
     * 1. Add the custom column to the Products list
     */
    function starke_add_sample_inventory_column( $columns ) {
        $new_columns = array();
        
        foreach ( $columns as $key => $title ) {
            $new_columns[$key] = $title;
            // Insert the new column right after the 'sku' column
            if ( 'sku' === $key ) {
                $new_columns['sample_inventory'] = 'Sample Inventory';
            }
        }
        
        // Fallback just in case the SKU column is hidden or missing
        if ( ! isset( $new_columns['sample_inventory'] ) ) {
            $new_columns['sample_inventory'] = 'Sample Inventory';
        }
        
        return $new_columns;
    }
    add_filter( 'manage_edit-product_columns', 'starke_add_sample_inventory_column', 20 );

    /*
     * 2. Populate the column with the ACF field data using get_field()
     */
    function starke_display_sample_inventory_column( $column, $post_id ) {
        // Updated to match the singular column key
        if ( 'sample_inventory' === $column ) {
            
            // CHANGED: Now strictly looking for 'sample_inventory'
            $inventory = get_field( 'sample_inventory', $post_id );
            
            if ( $inventory !== '' && $inventory !== false && $inventory !== null ) {
                // If inventory is low (e.g., 0), we can style it red to grab attention
                if ( is_numeric( $inventory ) && floatval( $inventory ) <= 0 ) {
                    echo '<strong style="color: #d63638;">' . esc_html( $inventory ) . '</strong>';
                } else {
                    echo '<strong>' . esc_html( $inventory ) . '</strong>';
                }
            } else {
                // Display a clean dash if the field is completely empty
                echo '<span style="color: #999;">-</span>';
            }
        }
    }
    add_action( 'manage_product_posts_custom_column', 'starke_display_sample_inventory_column', 10, 2 );

    /*
     * 3. Adjust the width and alignment of the new column
     */
    function starke_sample_inventory_column_css() {
        $screen = get_current_screen();
        if ( $screen && 'edit-product' === $screen->id ) {
            ?>
            <style type="text/css">
                /* Updated CSS selector to target the singular column name */
                .wp-list-table th.column-sample_inventory {
                    width: 120px; 
                    text-align: center;
                }
                .wp-list-table td.column-sample_inventory {
                    text-align: center;
                }
            </style>
            <?php
        }
    }
    add_action( 'admin_head', 'starke_sample_inventory_column_css' );
    
    // Creates custom 'Sample Inventory' column in WooCommerce Products admin list -- END
}
/**
 * ==============================================================================
 * SAMPLE INVENTORY MANAGEMENT AT CHECKOUT
 * ==============================================================================
 */

/**
 * 1. Transfer custom 'sample' cart item data to the order line item meta during checkout.
 */
add_action( 'woocommerce_checkout_create_order_line_item', 'starke_save_sample_data_to_order_item', 10, 4 );
function starke_save_sample_data_to_order_item( $item, $cart_item_key, $values, $order ) {
    // FIX: Use !empty() so it catches 1, true, or 'true' from the cart session
    if ( ! empty( $values['sample'] ) ) {
        // Save it exactly as 'sample' to match your quote system and PDF generator
        $item->add_meta_data( 'sample', true, true );
    }
}

/**
 * 2. Deduct 'sample_inventory' when an order is placed via the Store API (Blocks Checkout).
 * Covers 'pending' (Initial Checkout), 'processing' (Credit Card), and 'on-hold' (Check).
 */
add_action( 'woocommerce_store_api_checkout_order_processed', 'starke_deduct_sample_inventory_on_checkout', 10, 1 );
function starke_deduct_sample_inventory_on_checkout( $order ) {
    // FIX: Added 'pending' to catch the order immediately upon checkout creation
    $valid_statuses = array( 'pending', 'processing', 'on-hold', 'completed' );
    if ( ! in_array( $order->get_status(), $valid_statuses, true ) ) {
        return;
    }

    $inventory_deducted = false;

    // Loop through the items in the order
    foreach ( $order->get_items() as $item_id => $item ) {
        $product_id = $item->get_product_id();
        
        if ( ! $product_id ) {
            continue;
        }

        // IDENTIFY IF THE ITEM IS A SAMPLE
        // We look for the 'sample' meta key we saved in step 1
        $is_sample = $item->get_meta( 'sample', true );
        
        if ( ! empty( $is_sample ) ) {
            
            // Get the current sample inventory from ACF
            $current_inventory = get_field( 'sample_inventory', $product_id );
            
            if ( is_numeric( $current_inventory ) && floatval( $current_inventory ) > 0 ) {
                // Force quantity to 1 because only one sample per product is allowed per order
                $qty = 1;
                
                // Subtract the ordered quantity, ensuring it never drops below 0
                $new_inventory = max( 0, intval( $current_inventory ) - $qty );
                
                // Update the ACF field
                update_field( 'sample_inventory', $new_inventory, $product_id );
                $inventory_deducted = true;
            }
        }
    }

    // Add a single order note so admins know the inventory was automatically adjusted
    if ( $inventory_deducted ) {
        $order->add_order_note( 'System: Sample inventory was automatically deducted for the samples placed in this order.' );
    }
}

/**
 * ==============================================================================
 * ADMIN: SORT PRODUCTS ALPHABETICALLY BY DEFAULT
 * ==============================================================================
 */
add_action( 'pre_get_posts', 'starke_sort_admin_products_alphabetically' );

function starke_sort_admin_products_alphabetically( $query ) {
    // 1. Only run this in the WordPress admin area and on the main query
    if ( is_admin() && $query->is_main_query() ) {
        
        // 2. Make sure we are viewing the 'product' post type list
        global $typenow;
        if ( 'product' === $typenow ) {
            
            // 3. Only apply default sorting if you haven't manually clicked a column to sort
            if ( ! isset( $_GET['orderby'] ) ) {
                $query->set( 'orderby', 'title' );
                $query->set( 'order', 'ASC' );
            }
        }
    }
}

/**
 * ==============================================================================
 * WOOCOMMERCE, WPGB & SEARCHWP: EXCLUDE PRODUCTS WITHOUT IMAGES
 * ==============================================================================
 */

// 1. Hide from WooCommerce Main Shop/Category Pages on Initial Load (The Missing Piece)
add_action( 'woocommerce_product_query', 'starke_woo_hide_no_image_products' );
function starke_woo_hide_no_image_products( $q ) {
    $meta_query = $q->get( 'meta_query' );
    if ( ! is_array( $meta_query ) ) {
        $meta_query = [];
    }
    // Bulletproof check: Requires an actual Image ID greater than 0
    $meta_query[] = [
        'key'     => '_thumbnail_id',
        'value'   => '0',
        'compare' => '>',
        'type'    => 'NUMERIC'
    ];
    $q->set( 'meta_query', $meta_query );
}

/**
 * 2. EXCLUDE FROM WP GRID BUILDER: Specific Custom Grids Only
 * This intercepts the query ONLY for the specific Grid IDs listed below.
 */
add_filter( 'wp_grid_builder/grid/query_args', 'starke_wpgb_hide_no_image_products_isolated', 10, 2 );

function starke_wpgb_hide_no_image_products_isolated( $query_args, $grid_id ) {
    
    // --- SURGICAL TARGETING ---
    // List the Grid IDs that actually need this fix.
    // 3 = Baseboards, 4 = Casings, 5 = Crowns, 6 = Plinths
    // Replace '99' with the actual Grid ID of your Homepage "Newest Designs" grid!
    $targeted_grids = [ 3, 4, 5, 6, 2 ]; 

    // If the grid loading is NOT in our list, do nothing and return early.
    if ( ! in_array( $grid_id, $targeted_grids ) ) {
        return $query_args;
    }

    // If it IS in our list, inject the meta query to require an image
    if ( ! isset( $query_args['meta_query'] ) || ! is_array( $query_args['meta_query'] ) ) {
        $query_args['meta_query'] = [];
    }
    
    $query_args['meta_query'][] = [
        'key'     => '_thumbnail_id',
        'value'   => '0',
        'compare' => '>',
        'type'    => 'NUMERIC'
    ];

    return $query_args;
}

/**
 * 3. EXCLUDE FROM NATIVE WOOCOMMERCE RELATED PRODUCTS
 * WooCommerce calculates related products internally and spits out an array of IDs. 
 * This filters that final array to ensure none are missing images.
 */
add_filter( 'woocommerce_related_products', 'starke_filter_related_products_no_image', 10, 3 );

function starke_filter_related_products_no_image( $related_posts, $product_id, $args ) {
    $filtered_posts = [];
    
    foreach ( $related_posts as $related_id ) {
        // Only keep the product if it has a featured image attached
        if ( has_post_thumbnail( $related_id ) ) {
            $filtered_posts[] = $related_id;
        }
    }
    
    return $filtered_posts;
}

/**
 * 4. EXCLUDE FROM WOOCOMMERCE SHORTCODES & BLOCKS
 * Catch-all for standard WooCommerce elements like [recent_products] or [featured_products]
 */
add_filter( 'woocommerce_shortcode_products_query', 'starke_woo_shortcode_hide_no_image_products', 10, 3 );

function starke_woo_shortcode_hide_no_image_products( $query_args, $atts, $loop_name ) {
    if ( ! isset( $query_args['meta_query'] ) || ! is_array( $query_args['meta_query'] ) ) {
        $query_args['meta_query'] = [];
    }
    
    $query_args['meta_query'][] = [
        'key'     => '_thumbnail_id',
        'value'   => '0',
        'compare' => '>',
        'type'    => 'NUMERIC'
    ];
    
    return $query_args;
}

/**
 * 6. The "Engine-Level" Runtime Filter for SearchWP Live Search
 * This intercepts the raw results array before the Live Search dropdown even sees it.
 */
add_filter( 'searchwp\query\results', 'starke_searchwp_runtime_image_enforcer', 999, 2 );
function starke_searchwp_runtime_image_enforcer( $results, $query ) {
    if ( ! is_array( $results ) ) {
        return $results;
    }

    $filtered_results = array();

    foreach ( $results as $result ) {
        $post_id = 0;
        $is_product = false;

        // SearchWP v4 returns custom objects by default
        if ( isset( $result->id ) && isset( $result->source ) ) {
            $post_id = absint( $result->id );
            // Check if the source contains 'product' (e.g., 'post.product')
            if ( strpos( (string) $result->source, 'product' ) !== false ) {
                $is_product = true;
            }
        } 
        // Fallback: if it returns standard WP_Post objects
        elseif ( $result instanceof \WP_Post ) {
            $post_id = $result->ID;
            if ( $result->post_type === 'product' ) {
                $is_product = true;
            }
        } 
        // Fallback: if it returns just an array of IDs
        elseif ( is_numeric( $result ) ) {
            $post_id = absint( $result );
            if ( get_post_type( $post_id ) === 'product' ) {
                $is_product = true;
            }
        }

        // The Rule: If it is a product, it MUST have a featured image attached
        if ( $is_product && $post_id > 0 ) {
            if ( has_post_thumbnail( $post_id ) ) {
                $filtered_results[] = $result;
            }
        } else {
            // Keep all non-product results (pages, posts, etc.)
            $filtered_results[] = $result;
        }
    }

    return $filtered_results;
}

/**
 * ===============================================================
 * STARKE: SCROLL TO TOP BUTTON
 * Floating square button on the bottom left of the Shop page.
 * ===============================================================
 */
add_action('wp_footer', 'starke_render_scroll_to_top_button', 205);

function starke_render_scroll_to_top_button() {
    // Only show on the shop page (or if the URL contains '/shop' for safety)
    if ( ! is_shop() && ! is_product_category() ) {
        if ( strpos( $_SERVER['REQUEST_URI'], '/shop' ) === false ) {
            return;
        }
    }
    ?>
    
    <div id="starke-scroll-top-container">
        <button id="starke-scroll-top-btn" class="starke-compare-btn active" aria-label="Scroll to top">
            <i class="fa fa-arrow-up"></i>
        </button>
    </div>

    <style>
        #starke-scroll-top-container {
            position: fixed;
            bottom: 15px; 
            left: 15px;   
            z-index: 9999;
            display: none; 
        }
        
        #starke-scroll-top-btn {
            /* Hardcoded width/height makes it a perfect square */
            width: 46px; 
            height: 46px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            
            /* Match the Floating Compare Button's base shadow & transition exactly */
            box-shadow: 0 4px 15px rgba(0,0,0,0.4); 
            transition: transform 0.2s ease, background-color 0.2s ease; 
        }
        
        /* THE EXACT HOVER MATCH (Same as .starke-floating-compare .starke-compare-btn:hover) */
        #starke-scroll-top-btn:hover {
            transform: translateY(-3px);
            background-color: #555 !important;
        }
        
        #starke-scroll-top-btn i {
            font-size: 20px;
            margin: 0;
        }
    </style>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var $scrollTopContainer = $('#starke-scroll-top-container');
        var $scrollTopBtn = $('#starke-scroll-top-btn');

        // 1. Show the button only after the user has scrolled down 400px
        $(window).on('scroll', function() {
            if ($(this).scrollTop() > 400) {
                $scrollTopContainer.fadeIn();
            } else {
                $scrollTopContainer.fadeOut();
            }
        });

        // 2. Smoothly scroll back to the absolute top of the page when clicked
        $scrollTopBtn.on('click', function(e) {
            
            // --- THE FIREWALL ---
            e.preventDefault();
            e.stopPropagation();           // Stops the click from bubbling to the document
            e.stopImmediatePropagation();  // Stops the compare plugin from hijacking this button
            // --------------------

            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    });
    </script>
    <?php
}

/**
 * ===============================================================
 * STARKE: BROWSER HISTORY CACHE CONTROL BYPASS (BFCache Activator)
 * Overrides default WordPress/WooCommerce no-cache headers for logged-in 
 * users specifically on the catalog shop pages. This forces browsers 
 * to store a complete RAM snapshot of the layout state (bfcache), 
 * making history back navigation instant (0ms delay) with no data duplication.
 * ===============================================================
 */
add_action( 'template_redirect', 'starke_force_logged_in_shop_bfcache', 99999 );
function starke_force_logged_in_shop_bfcache() {
    if ( is_admin() ) return;

    // Check if the current view context is targeting the product catalog grid space
    if ( is_shop() || is_product_category() || ( isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/shop') !== false ) ) {
        
        // Use 'private' to completely prevent public CDN edge devices from caching user data, 
        // while giving the local user's browser permission to freeze the view state in its local history memory stack (bfcache)
        header('Cache-Control: private, max-age=0, must-revalidate', true);
        header('Pragma: cache', true);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time()) . ' GMT', true);
    }
}

/**
 * ===============================================================
 * STARKE: MASTER SCROLL WATCHDOG & SAFARI ALIGNMENT ENGINE
 * Runs alongside native browser bfcache to guarantee instant
 * layout restoration and flawless endless scrolling performance.
 * Clean Architectural Build: No style thrashing, no layout shifting,
 * optimized for absolute performance and reliability on all devices.
 * ===============================================================
 */
add_action( 'wp_footer', 'starke_wpgb_aggressive_scroll_watchdog', 999 );
function starke_wpgb_aggressive_scroll_watchdog() {
    if ( is_admin() || is_product() || is_cart() || is_checkout() ) return;
    ?>
    <script type="text/javascript">
    (function($) {
        
        if (window.location.pathname.indexOf('/shop') === -1 && !$('.wpgb-facet').length) {
            return; 
        }

        var isRestoring = false;
        var safariFrameAttempts = 0;

        // --- 1. CORE ENGINE: INSTANT BFCACHE SCROLL ALIGNMENT ---
        function checkAndRestoreScrollPosition() {
            var savedPos = parseInt(sessionStorage.getItem('starke_shop_scroll_pos'), 10) || 0;
            if (savedPos <= 150) return;

            var docHeight = document.documentElement.scrollHeight;
            var viewportHeight = window.innerHeight || document.documentElement.clientHeight;

            // If the full layout exists in memory (bfcache hit), snap to position instantly
            if (savedPos <= (docHeight - viewportHeight)) {
                isRestoring = true;
                
                function forceBfcacheSnap() {
                    window.scrollTo(0, savedPos);
                    
                    // Verify if Safari holds the position or tries to drop it to 0
                    if (Math.abs(window.scrollY - savedPos) > 8 && safariFrameAttempts < 10) {
                        safariFrameAttempts++;
                        requestAnimationFrame(forceBfcacheSnap);
                    } else {
                        isRestoring = false;
                        safariFrameAttempts = 0;
                    }
                }
                requestAnimationFrame(forceBfcacheSnap);
            }
        }

        // --- 2. MULTI-ENVIRONMENT LIFECYCLE HOOKS ---
        window.addEventListener('pageshow', function(event) {
            safariFrameAttempts = 0;
            checkAndRestoreScrollPosition();
        });

        // Clear historical target positions on direct site link menu interactions
        $(document).on('click', 'a[href*="/shop"]', function() {
            if ($(this).closest('.wpgb-card, .starke-product-col, .starke-compare-wrapper').length > 0) return;
            sessionStorage.removeItem('starke_shop_scroll_pos');
        });

        // --- 3. RUNTIME SCROLL RESOLUTION SAMPLERS ---
        $(window).on('scroll', function() {
            if (!isRestoring) {
                sessionStorage.setItem('starke_shop_scroll_pos', window.scrollY);
            }
        });

        $(document).on('click', '.wpgb-card a, .starke-product-col a', function() {
            sessionStorage.setItem('starke_shop_scroll_pos', window.scrollY);
        });

        // --- 4. PERFORMANCE-FIRST ENDLESS SCROLL WATCHDOG ---
        // Runs on a lightweight interval checking structural geometry parameters directly
        setInterval(function() {
            if (isRestoring) return;

            var $loadMoreBtn = $('.wpgb-load-more');
            if ($loadMoreBtn.length === 0 || !$loadMoreBtn.is(':visible')) return;

            // If the grid is actively fetching an AJAX chunk, wait until it finishes painting
            if ($('.wpgb-is-loading, .is-loading').length > 0) return;

            // If out of items (end of catalog), exit cleanly
            if ($loadMoreBtn.hasClass('wpgb-disabled') || $loadMoreBtn.prop('disabled')) return;

            var scrollY = window.scrollY || document.documentElement.scrollTop;
            var viewportHeight = window.innerHeight || document.documentElement.clientHeight;
            var docHeight = document.documentElement.scrollHeight;

            // Trigger Zone: If the viewport is within 3000px of the document floor
            if (docHeight - (scrollY + viewportHeight) < 5000) {
                
                // Fire programmatic click handling
                if (typeof $loadMoreBtn[0].click === 'function') {
                    $loadMoreBtn[0].click();
                } else {
                    $loadMoreBtn.trigger('click');
                }

                // Dispatch native scroll event tracking to force WPGB to process layout visibility calculations
                window.dispatchEvent(new Event('scroll'));
            }
        }, 250);

    })(jQuery);
    </script>
    <?php
}

add_action( 'wp_footer', 'starke_lightweight_visual_scrollbar', 999 );
function starke_lightweight_visual_scrollbar() {
    ?>
    <style>
        /* OVERRIDE: Kill the CSS animation so it doesn't pulse during AJAX loads */
        .starke-visual-scrollbar {
            transition: none !important; 
            opacity: 1 !important; 
            display: none; 
        }
        .starke-visual-scrollbar.is-visible {
            display: block !important;
        }
    </style>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        
        var scrollMemory = {};

        function buildScrollbars() {
            $('.wpgb-checkbox-facet').each(function() {
                var $facet = $(this);
                var listEl = $facet.find('.wpgb-hierarchical-list')[0];
                
                if (!listEl) return;

                var facetId = $facet.closest('.wpgb-facet').attr('data-id') || $facet.closest('.wpgb-facet').attr('class');

                // 1. RESTORE SCROLL POSITION
                if (scrollMemory[facetId] !== undefined) {
                    listEl.scrollTop = scrollMemory[facetId];
                    delete scrollMemory[facetId]; 
                }

                // 2. Inject the scrollbar
                var $scrollbar = $facet.find('.starke-visual-scrollbar');
                if ($scrollbar.length === 0) {
                    $facet.append('<div class="starke-visual-scrollbar"></div>');
                    $scrollbar = $facet.find('.starke-visual-scrollbar');
                }
                var scrollbarEl = $scrollbar[0];

                var topGap = 31; // The gap at the top

                // 3. The Math to position the thumb
                function updateThumb() {
                    if (!listEl.offsetParent) return; 

                    var scrollHeight = listEl.scrollHeight;
                    var clientHeight = listEl.clientHeight;

                    if (scrollHeight <= clientHeight + 2) {
                        scrollbarEl.classList.remove('is-visible');
                        return; 
                    } 
                    
                    var availableTrackHeight = clientHeight - topGap;
                    var thumbHeight = Math.max((clientHeight / scrollHeight) * availableTrackHeight, 20); 
                    var scrollPercentage = listEl.scrollTop / (scrollHeight - clientHeight);
                    var topPosition = topGap + (scrollPercentage * (availableTrackHeight - thumbHeight));

                    scrollbarEl.style.height = thumbHeight + 'px';
                    scrollbarEl.style.transform = 'translateY(' + topPosition + 'px)';
                    scrollbarEl.classList.add('is-visible');
                }

                updateThumb();

                // 4. --- NEW: DRAG AND DROP FUNCTIONALITY (High Performance) ---
                if (!scrollbarEl.dataset.dragBound) {
                    scrollbarEl.dataset.dragBound = 'true';
                    
                    scrollbarEl.addEventListener('mousedown', function(e) {
                        e.preventDefault(); 
                        
                        var startY = e.clientY;
                        var startScrollTop = listEl.scrollTop;
                        
                        var scrollHeight = listEl.scrollHeight;
                        var clientHeight = listEl.clientHeight;
                        var availableTrackHeight = clientHeight - topGap;
                        var thumbHeight = Math.max((clientHeight / scrollHeight) * availableTrackHeight, 20); 
                        
                        var scrollRatio = (scrollHeight - clientHeight) / (availableTrackHeight - thumbHeight);
                        
                        function onMouseMove(moveEvent) {
                            var deltaY = moveEvent.clientY - startY;
                            listEl.scrollTop = startScrollTop + (deltaY * scrollRatio);
                        }
                        
                        function onMouseUp() {
                            document.removeEventListener('mousemove', onMouseMove);
                            document.removeEventListener('mouseup', onMouseUp);
                            
                            // RESTORE: Let the user interact with the page normally again
                            document.body.style.pointerEvents = ''; 
                        }
                        
                        document.addEventListener('mousemove', onMouseMove);
                        document.addEventListener('mouseup', onMouseUp);
                        
                        // PERFORMANCE FIX: 
                        // Tells the browser to completely ignore checkboxes and hover states while dragging. 
                        // This stops the rendering engine from choking and guarantees native scroll smoothness.
                        document.body.style.pointerEvents = 'none'; 
                    });
                }

                // 5. Bind events only if this is a fresh DOM node
                if (!listEl.dataset.starkeWired) {
                    listEl.dataset.starkeWired = 'true';

                    listEl.addEventListener('scroll', function() {
                        window.requestAnimationFrame(updateThumb);
                        scrollMemory[facetId] = listEl.scrollTop;
                    }, { passive: true });

                    if (window.ResizeObserver) {
                        var ro = new ResizeObserver(function() {
                            window.requestAnimationFrame(updateThumb);
                        });
                        ro.observe(listEl);
                    }
                }
            });
        }

        buildScrollbars();

        // --- WP GRID BUILDER NATIVE API HOOKS ---
        if (typeof window.WP_Grid_Builder !== 'undefined') {
            window.WP_Grid_Builder.on('updating', function() {
                $('.wpgb-checkbox-facet').each(function() {
                    var $facet = $(this);
                    var listEl = $facet.find('.wpgb-hierarchical-list')[0];
                    if (listEl) {
                        var facetId = $facet.closest('.wpgb-facet').attr('data-id') || $facet.closest('.wpgb-facet').attr('class');
                        scrollMemory[facetId] = listEl.scrollTop;
                    }
                });
            });

            window.WP_Grid_Builder.on('rendered', function() {
                buildScrollbars();
            });
        }

        // --- BULLETPROOF MUTATION OBSERVER ---
        var rebuildPending = false;
        var domObserver = new MutationObserver(function(mutations) {
            var needsRebuild = false;
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes.length) {
                    for (var j = 0; j < mutations[i].addedNodes.length; j++) {
                        var node = mutations[i].addedNodes[j];
                        if (node.nodeType === 1 && (node.classList.contains('wpgb-hierarchical-list') || node.querySelector('.wpgb-hierarchical-list') || node.classList.contains('wpgb-facet'))) {
                            needsRebuild = true;
                            break;
                        }
                    }
                }
                if (needsRebuild) break;
            }

            if (needsRebuild && !rebuildPending) {
                rebuildPending = true;
                window.requestAnimationFrame(function() {
                    buildScrollbars();
                    rebuildPending = false;
                });
            }
        });

        domObserver.observe(document.body, { childList: true, subtree: true });

    });
    </script>
    <?php
}

// ==========================================================================
// AJAX ENDPOINT FOR BACKGROUND CACHE BUILDER
// ==========================================================================
add_action('wp_ajax_starke_async_rebuild_profiles', 'starke_ajax_rebuild_profiles_handler');
add_action('wp_ajax_nopriv_starke_async_rebuild_profiles', 'starke_ajax_rebuild_profiles_handler');

function starke_ajax_rebuild_profiles_handler() {
    $valid_profiles = [];
    if ( function_exists('starke_rebuild_profile_list_cache') ) {
        $valid_profiles = starke_rebuild_profile_list_cache();
    }
    
    // Send the newly built array directly back to the JavaScript that called it
    wp_send_json_success( $valid_profiles ); 
}

// ==========================================================================
// 3D CONFIGURATOR: DYNAMIC PROFILE LIST CACHE BUILDER
// ==========================================================================
/**
 * REBUILD CACHE: Set to 0 (Infinite)
 * Relies on Action Scheduler and save_post hooks for updates.
 */
function starke_rebuild_profile_list_cache() {
    $profile_query = new WP_Query([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_query'     => [
            ['key' => '_thumbnail_id', 'compare' => 'EXISTS']
        ],
        'fields'         => 'ids'
    ]);

    $valid_profiles = [];
    foreach ($profile_query->posts as $pid) {
        $valid_profiles[] = get_post_field('post_name', $pid);
    }
    
    // Set to 0 (Infinite). The Action Scheduler will be your primary refresh trigger.
    set_transient('starke_cached_profile_list', $valid_profiles, 0);
    
    // Clear lock
    update_option('starke_rebuild_lock_time', 0, false);
    
    return $valid_profiles;
}

/**
 * 1. ACTION SCHEDULER: Primary Background Driver
 */
add_action('init', 'starke_register_profile_cache_cron');
function starke_register_profile_cache_cron() {
    if ( !as_has_scheduled_action('starke_scheduled_profile_rebuild') ) {
        as_schedule_recurring_action(time(), 12 * HOUR_IN_SECONDS, 'starke_scheduled_profile_rebuild', [], 'sm-cache-maintenance');
    }
}
add_action('starke_scheduled_profile_rebuild', 'starke_rebuild_profile_list_cache');

/**
 * 2. HOOKS: Immediate Manual Updates
 */
add_action('save_post_product', 'starke_trigger_cache_rebuild_on_save', 10, 3);
function starke_trigger_cache_rebuild_on_save( $post_id, $post, $update ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    starke_rebuild_profile_list_cache();
}

add_action('deleted_post', 'starke_trigger_cache_rebuild_on_delete');
function starke_trigger_cache_rebuild_on_delete( $post_id ) {
    if ( get_post_type( $post_id ) === 'product' ) {
        starke_rebuild_profile_list_cache();
    }
}

/**
 * 3. FALLBACK: Safety Net (Only runs if transient is missing)
 */
add_action('wp_footer', 'starke_global_profile_cache_monitor');
function starke_global_profile_cache_monitor() {
    if ( is_admin() || ( function_exists('is_checkout') && is_checkout() ) ) return;

    if ( false === get_transient('starke_cached_profile_list') ) {
        starke_rebuild_profile_list_cache();
    }
}

/**
 * ===============================================================
 * STARKE COMPARE LIST: USER SESSION & IMPERSONATION SYNC
 * ===============================================================
 */

// 1. Save and Clear on Logout OR Switch Back
add_action('clear_auth_cookie', 'starke_save_and_clear_compare_cookie_on_logout');
add_action('wp_ajax_switch_back_to_admin', 'starke_save_and_clear_compare_cookie_on_logout', 1);
add_action('wp_ajax_inactivity_logout', 'starke_save_and_clear_compare_cookie_on_logout', 1);

function starke_save_and_clear_compare_cookie_on_logout() {
    $user_id = get_current_user_id();
    
    if ( $user_id && isset( $_COOKIE['br_products_compare'] ) ) {
        $compare_data = sanitize_text_field( $_COOKIE['br_products_compare'] );

        // Check if this is an Admin Impersonating a Customer
        if ( isset($_COOKIE['original_admin_id']) && isset($_COOKIE['impersonated_user_id']) ) {
            $admin_id    = intval($_COOKIE['original_admin_id']);
            $customer_id = intval($_COOKIE['impersonated_user_id']);

            $persistent_lists = get_user_meta( $admin_id, '_admin_persistent_compare_lists', true );
            if ( ! is_array( $persistent_lists ) ) { $persistent_lists = []; }

            $list_key = 'admin_' . $admin_id . '_user_' . $customer_id;
            $persistent_lists[ $list_key ] = $compare_data;

            update_user_meta( $admin_id, '_admin_persistent_compare_lists', $persistent_lists );
        } else {
            // Normal Customer Logout
            update_user_meta( $user_id, '_starke_compare_list', $compare_data );
        }
    }

    // WIPE the browser cookies ONLY if this is a normal customer logout.
    // If an Admin is impersonating, they are about to be logged back into their own account,
    // so we skip this to save 3 Set-Cookie headers and prevent Nginx 502 Buffer Overflows.
    if ( ! isset($_COOKIE['original_admin_id']) ) {
        $domain = '.' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
        setcookie('br_products_compare', '', time() - 3600, '/', $domain);
        setcookie('br_products_compare', '', time() - 3600, '/', '.starkemillwork.com');
        setcookie('br_products_compare', '', time() - 3600, '/');
    }
    
    unset($_COOKIE['br_products_compare']);
}

// 2. On Login: Restore the appropriate list
add_action('wp_login', 'starke_restore_compare_cookie_on_login', 10, 2);
function starke_restore_compare_cookie_on_login( $user_login, $user ) {
    
    $saved_list = '';
    $guest_list = '';

    $is_impersonation_active = isset($_COOKIE['original_admin_id']) && isset($_COOKIE['impersonated_user_id']);

    if ( $is_impersonation_active && $user->ID == $_COOKIE['impersonated_user_id'] ) {
        // SCENARIO A: Admin is logging into the Customer's account
        $admin_id    = intval($_COOKIE['original_admin_id']);
        $customer_id = intval($_COOKIE['impersonated_user_id']);

        $persistent_lists = get_user_meta( $admin_id, '_admin_persistent_compare_lists', true );
        $list_key = 'admin_' . $admin_id . '_user_' . $customer_id;

        if ( is_array( $persistent_lists ) && isset( $persistent_lists[ $list_key ] ) ) {
            $saved_list = $persistent_lists[ $list_key ];
        }
        
        // Discard the current browser cookie (it belongs to the admin's personal account)
        $guest_list = '';

    } elseif ( $is_impersonation_active && $user->ID == $_COOKIE['original_admin_id'] ) {
        // SCENARIO B: Admin is switching back to their OWN account
        $saved_list = get_user_meta( $user->ID, '_starke_compare_list', true );
        
        // Discard guest cookie (it holds the customer's list from the impersonation session)
        $guest_list = '';

    } else {
        // SCENARIO C: Normal Customer Login
        $saved_list = get_user_meta( $user->ID, '_starke_compare_list', true );
        $guest_list = isset( $_COOKIE['br_products_compare'] ) ? sanitize_text_field( $_COOKIE['br_products_compare'] ) : '';
    }

    // Merge and apply
    $merged_ids = array_filter( array_unique( explode( ',', $saved_list . ',' . $guest_list ) ) );
    $domain = '.' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');

    if ( ! empty( $merged_ids ) ) {
        $new_cookie_val = implode( ',', $merged_ids );
        setcookie('br_products_compare', $new_cookie_val, time() + (86400 * 7), '/', $domain);
        $_COOKIE['br_products_compare'] = $new_cookie_val;
    } else {
        setcookie('br_products_compare', '', time() - 3600, '/', $domain);
        unset($_COOKIE['br_products_compare']);
    }
}