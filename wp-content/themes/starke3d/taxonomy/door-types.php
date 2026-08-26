<?php
// Register Custom Taxonomy for Door Types
function register_door_type_taxonomy() {

    $labels = array(
        'name'                       => _x( 'Door Types', 'Taxonomy General Name', 'text_domain' ),
        'singular_name'              => _x( 'Door Type', 'Taxonomy Singular Name', 'text_domain' ),
        'menu_name'                  => __( 'Door Types', 'text_domain' ),
        'all_items'                  => __( 'All Door Types', 'text_domain' ),
        'parent_item'                => __( 'Parent Door Type', 'text_domain' ),
        'parent_item_colon'          => __( 'Parent Door Type:', 'text_domain' ),
        'new_item_name'              => __( 'New Door Type Name', 'text_domain' ),
        'add_new_item'               => __( 'Add New Door Type', 'text_domain' ),
        'edit_item'                  => __( 'Edit Door Type', 'text_domain' ),
        'update_item'                => __( 'Update Door Type', 'text_domain' ),
        'view_item'                  => __( 'View Door Type', 'text_domain' ),
        'separate_items_with_commas' => __( 'Separate types with commas', 'text_domain' ),
        'add_or_remove_items'        => __( 'Add or remove door types', 'text_domain' ),
        'choose_from_most_used'      => __( 'Choose from the most used', 'text_domain' ),
        'popular_items'              => __( 'Popular Door Types', 'text_domain' ),
        'search_items'               => __( 'Search Door Types', 'text_domain' ),
        'not_found'                  => __( 'Not Found', 'text_domain' ),
        'no_terms'                   => __( 'No door types', 'text_domain' ),
        'items_list'                 => __( 'Door types list', 'text_domain' ),
        'items_list_navigation'      => __( 'Door types list navigation', 'text_domain' ),
    );
    
    // Arguments for the taxonomy
    $args = array(
        'labels'                     => $labels,
        'hierarchical'               => true, // Use category-style UI
        'public'                     => true, // Make it public
        'publicly_queryable'         => true, // Allow queries
        'show_ui'                    => true, // Show the UI in the admin
        'show_in_menu'               => true, // Show in the admin menu (auto-under 'door')
        'show_admin_column'          => true, // Show column on 'door' post list
        'show_in_nav_menus'          => true, // Allow selection in Appearance > Menus
        'show_tagcloud'              => false, // Don't show in tag cloud widget
        'show_in_rest'               => true,  // Use the modern REST API interface
        
        // --- THIS IS THE NEW LINE ---
        // This hides the default WordPress meta box,
        // so you can use your ACF 'select' field as the only option.
        
        
        'rewrite'                    => array( 'slug' => 'door-type' ), // Nice URL slug
    );
    
    // Register the taxonomy
    register_taxonomy( 'door_type', array( 'door' ), $args );

}
add_action( 'init', 'register_door_type_taxonomy', 0 );

/**
 * Hide the default "Door Types" meta box on the 'door' edit screen.
 * This allows the ACF "Taxonomy" field to be the only selector.
 * The ID is the taxonomy slug ('door_type') followed by 'div'.
 * 'door' is the post type screen.
 * 'side' is the context (where the box appears).
 */
function hide_default_door_type_meta_box() {
    remove_meta_box( 'door_typediv', 'door', 'side' );
}
// We use 'add_meta_boxes' action, and add priority 11 to run it after the box is registered.
add_action( 'add_meta_boxes', 'hide_default_door_type_meta_box', 11 );