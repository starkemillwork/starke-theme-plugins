<?php
// Register Custom Post Type for Board Lengths
function register_lengths_post_type() {
    $labels = array(
        'name'                  => _x('Lengths', 'Post Type General Name', 'text_domain'),
        'singular_name'         => _x('Lengths', 'Post Type Singular Name', 'text_domain'),
        'menu_name'             => __('Lengths', 'text_domain'),
        'name_admin_bar'        => __('Lengths', 'text_domain'),
        'archives'              => __('Lengths Archives', 'text_domain'),
        'attributes'            => __('Lengths Attributes', 'text_domain'),
        'parent_item_colon'     => __('Parent Lengths:', 'text_domain'),
        'all_items'             => __('Lengths', 'text_domain'),
        'add_new_item'          => __('Add New Length', 'text_domain'),
        'add_new'               => __('Add New', 'text_domain'),
        'new_item'              => __('New Lengths', 'text_domain'),
        'edit_item'             => __('Edit Length', 'text_domain'),
        'update_item'           => __('Update Length', 'text_domain'),
        'view_item'             => __('View Lengths', 'text_domain'),
        'view_items'            => __('View Lengths', 'text_domain'),
        'search_items'          => __('Search Lengths', 'text_domain'),
        'not_found'             => __('Not found', 'text_domain'),
        'not_found_in_trash'    => __('Not found in Trash', 'text_domain'),
        'featured_image'        => __('Featured Image', 'text_domain'),
        'set_featured_image'    => __('Set featured image', 'text_domain'),
        'remove_featured_image' => __('Remove featured image', 'text_domain'),
        'use_featured_image'    => __('Use as featured image', 'text_domain'),
        'insert_into_item'      => __('Insert into lengths', 'text_domain'),
        'uploaded_to_this_item' => __('Uploaded to this lengths', 'text_domain'),
        'items_list'            => __('Lengths Options list', 'text_domain'),
        'items_list_navigation' => __('Lengths list navigation', 'text_domain'),
        'filter_items_list'     => __('Filter lengths list', 'text_domain'),
    );

    $args = array(
        'label'                 => __('Lengths', 'text_domain'),
        'description'           => __('Post Type for Wood Lengths', 'text_domain'),
        'labels'                => $labels,
        'supports'              => array('title', 'editor', 'thumbnail', 'page-attributes'),
        'hierarchical'          => false,
        'public'                => true,
        'show_ui'               => true,
        'show_in_menu'          => 'edit.php?post_type=product',
        'menu_position'         => 20,
        'menu_icon'             => 'dashicons-archive',
        'show_in_admin_bar'     => true,
        'show_in_nav_menus'     => true,
        'can_export'            => true,
        'has_archive'           => true,
        'exclude_from_search'   => true,
        'publicly_queryable'    => false,
        'capability_type'       => 'post',
        'show_in_rest'          => true, // Enable REST API support
        'rest_base'             => 'lengths', // Optional: custom endpoint base
    );

    register_post_type('lengths', $args);
}
add_action('init', 'register_lengths_post_type', 0);

// Remove the 'Add Media' button for the 'lengths' custom post type
function remove_media_button_from_lengths() {
    global $pagenow;
    if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) {
        if ( 'lengths' === get_post_type() ) {
            remove_action( 'media_buttons', 'media_buttons' );
        }
    }
}
add_action( 'admin_head', 'remove_media_button_from_lengths' );

// Remove the Visual/Text editor for the 'lengths' custom post type
function remove_editor_from_lengths() {
    remove_post_type_support( 'lengths', 'editor' );
}
add_action( 'init', 'remove_editor_from_lengths' );

/**
 * Sets the default sort order for the 'lengths' custom post type in the WP admin.
 */
function sm_set_lengths_cpt_admin_order($query) {
    if (is_admin() && $query->is_main_query() && $query->get('post_type') === 'lengths') {
        $query->set('orderby', 'menu_order');
        $query->set('order', 'ASC');
    }
}
add_action('pre_get_posts', 'sm_set_lengths_cpt_admin_order');