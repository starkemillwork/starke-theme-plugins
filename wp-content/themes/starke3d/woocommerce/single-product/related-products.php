<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * 1. Helper Function: Convert Comma-Separated SKUs to Product IDs
 */
function get_ids_from_acf_skus( $acf_field_name ) {
    // Get the current global product ID
    $product_id = get_the_ID(); 
    
    // Get the SKUs from ACF (e.g., "M100, M102, M500")
    $sku_string = get_field( $acf_field_name, $product_id );

    if ( empty( $sku_string ) ) {
        return []; // Return empty if field is blank
    }

    // Turn string into array and clean up whitespace
    $skus = array_map('trim', explode(',', $sku_string));
    $post_ids = [];

    foreach ( $skus as $sku ) {
        // WooCommerce helper to find ID by SKU
        $id = wc_get_product_id_by_sku( $sku );
        if ( $id ) {
            $post_ids[] = $id;
        }
    }

    return $post_ids;
}

/**
 * 2. WP Grid Builder Filter: Inject IDs into the Grids
 */
add_filter( 'wp_grid_builder/grid/query_args', function( $query_args, $grid_id ) {

    // CONFIGURATION: Map your Grid IDs to your ACF Field Names
    // Format: Grid_ID => 'acf_field_name'
    $map = [
        6 => 'plinths', // REPLACE 10 with your Plinths Grid ID
        3 => 'related_baseboards', // REPLACE 10 with your Baseboard Grid ID
        5 => 'related_crowns',     // REPLACE 11 with your Crown Grid ID
        4 => 'related_casings',    // REPLACE 12 with your Casing Grid ID
    ];

    // If the current grid is one of ours...
    if ( isset( $map[ $grid_id ] ) ) {
        // Get the IDs based on SKUs
        $ids = get_ids_from_acf_skus( $map[ $grid_id ] );

        if ( ! empty( $ids ) ) {
            // Tell the grid to show ONLY these IDs
            $query_args['post__in'] = $ids; 
            $query_args['orderby'] = 'post__in'; // Keep the order listed in ACF
        } else {
            // If no products found, force empty result so grid doesn't show random products
            $query_args['post__in'] = [0]; 
        }
    }

    return $query_args;

}, 10, 2 );

add_shortcode( 'custom_related_moldings', 'render_custom_related_moldings' );

function render_custom_related_moldings() {
    // 1. Define your sections
    $sections = [
        [
            'title' => 'Plinths',
            'field' => 'plinths',
            'grid'  => 6 // Your Grid ID
        ],
        [
            'title' => 'Related Baseboards',
            'field' => 'related_baseboards',
            'grid'  => 3 // Your Grid ID
        ],
        [
            'title' => 'Related Casings',
            'field' => 'related_casings',
            'grid'  => 4 // Your Grid ID
        ],
        [
            'title' => 'Related Crowns',
            'field' => 'related_crowns',
            'grid'  => 5 // Your Grid ID
        ],
    ];

    ob_start();
    
    echo '<div class="related-moldings-wrapper">';

    foreach ( $sections as $section ) {
        // --- THE FIX STARTS HERE ---
        
        // Instead of just getting the text, we try to get the actual valid IDs immediately.
        // We reuse the helper function you defined at the top of the file.
        $valid_ids = get_ids_from_acf_skus( $section['field'] );

        // If the array is empty (meaning no SKUs were found, OR all SKUs were invalid),
        // we SKIP this iteration entirely. The section HTML is never generated.
        if ( empty( $valid_ids ) ) {
            continue; 
        }

        // If we get here, we have at least one valid product, so we render the section.
        
        echo '<div class="related-molding-row">';
            
        // Inner Container
        echo '<div class="starke-inner-container">'; 
            
            // Render Header
            echo '<div class="related-header">';
            echo '<h3>' . esc_html( $section['title'] ) . '</h3>';
            echo '</div>';
    
            // Render Grid
            echo do_shortcode( '[wpgb_grid id="' . $section['grid'] . '"]' );
            
        echo '</div>'; // End Inner Container
        echo '</div>'; // End Row
        
        // --- THE FIX ENDS HERE ---
    }

    echo '</div>'; // End Wrapper

    return ob_get_clean();
}