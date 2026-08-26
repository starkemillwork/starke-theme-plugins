<?php
// Register Custom Post Type for Saddles
function register_saddle_post_type() {
    $labels = array(
        'name'                  => _x('Saddles', 'Post Type General Name', 'text_domain'),
        'singular_name'         => _x('Saddle', 'Post Type Singular Name', 'text_domain'),
        'menu_name'             => __('Saddles', 'text_domain'),
        'name_admin_bar'        => __('Saddle', 'text_domain'),
        'archives'              => __('Saddle Archives', 'text_domain'),
        'attributes'            => __('Saddle Attributes', 'text_domain'),
        'parent_item_colon'     => __('Parent Saddle:', 'text_domain'),
        'all_items'             => __('All Saddles', 'text_domain'),
        'add_new_item'          => __('Add New Saddle', 'text_domain'),
        'add_new'               => __('Add New', 'text_domain'),
        'new_item'              => __('New Saddle', 'text_domain'),
        'edit_item'             => __('Edit Saddle', 'text_domain'),
        'update_item'           => __('Update Saddle', 'text_domain'),
        'view_item'             => __('View Saddle', 'text_domain'),
        'view_items'            => __('View Saddles', 'text_domain'),
        'search_items'          => __('Search Saddle', 'text_domain'),
        'not_found'             => __('Not found', 'text_domain'),
        'not_found_in_trash'    => __('Not found in Trash', 'text_domain'),
        'featured_image'        => __('Featured Image', 'text_domain'),
        'set_featured_image'    => __('Set featured image', 'text_domain'),
        'remove_featured_image' => __('Remove featured image', 'text_domain'),
        'use_featured_image'    => __('Use as featured image', 'text_domain'),
        'insert_into_item'      => __('Insert into saddle', 'text_domain'),
        'uploaded_to_this_item' => __('Uploaded to this saddle', 'text_domain'),
        'items_list'            => __('Saddles list', 'text_domain'),
        'items_list_navigation' => __('Saddles list navigation', 'text_domain'),
        'filter_items_list'     => __('Filter saddles list', 'text_domain'),
    );

    $args = array(
        'label'                 => __('Saddle', 'text_domain'),
        'description'           => __('Post Type for Saddles', 'text_domain'),
        'labels'                => $labels,
        'supports'              => array('title', 'thumbnail', 'page-attributes'), // Title, Featured Image, and Order
        'hierarchical'          => false,
        'public'                => true,
        'show_ui'               => true,
        'show_in_menu'          => 'edit.php?post_type=door', // <-- THIS IS THE CHANGE
      //'menu_position'         => 56, // No longer needed
      //'menu_icon'             => 'dashicons-admin-settings', // No longer needed
        'show_in_admin_bar'     => true,
        'show_in_nav_menus'     => true,
        'can_export'            => true,
        'has_archive'           => false, // Doesn't need an archive page
        'exclude_from_search'   => true,
        'publicly_queryable'    => false, // Doesn't need a single page
        'capability_type'       => 'post',
        'show_in_rest'          => true, // Enable REST API support
        'rest_base'             => 'saddles', 
    );

    register_post_type('saddle', $args); // Registered as 'saddle'
}
add_action('init', 'register_saddle_post_type', 0);

// Remove the Visual/Text editor for the 'saddle' custom post type
function remove_editor_from_saddle() {
    remove_post_type_support( 'saddle', 'editor' );
}
add_action( 'init', 'remove_editor_from_saddle' );

/**
 * Sets the default sort order for the 'saddle' CPT in the WP admin.
 */
function sm_set_saddles_cpt_admin_order($query) {
    if (is_admin() && $query->is_main_query() && $query->get('post_type') === 'saddle') {
        if ( empty( $_GET['orderby'] ) ) {
            $query->set('orderby', 'menu_order');
            $query->set('order', 'ASC');
        }
    }
}
add_action('pre_get_posts', 'sm_set_saddles_cpt_admin_order');