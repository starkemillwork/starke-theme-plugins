<?php
// Register Custom Post Type for Wood Stain
function register_stain_post_type() {
    $labels = array(
        'name'                  => _x('Stain', 'Post Type General Name', 'text_domain'),
        'singular_name'         => _x('Stain', 'Post Type Singular Name', 'text_domain'),
        'menu_name'             => __('Stain', 'text_domain'),
        'name_admin_bar'        => __('Stain', 'text_domain'),
        'archives'              => __('Stain Archives', 'text_domain'),
        'attributes'            => __('Stain Attributes', 'text_domain'),
        'parent_item_colon'     => __('Parent Stain:', 'text_domain'),
        'all_items'             => __('Stain Colors', 'text_domain'),
        'add_new_item'          => __('Add New Stain', 'text_domain'),
        'add_new'               => __('Add New', 'text_domain'),
        'new_item'              => __('New Stain', 'text_domain'),
        'edit_item'             => __('Edit Stain', 'text_domain'),
        'update_item'           => __('Update Stain', 'text_domain'),
        'view_item'             => __('View Stain', 'text_domain'),
        'view_items'            => __('View Stain', 'text_domain'),
        'search_items'          => __('Search Stain', 'text_domain'),
        'not_found'             => __('Not found', 'text_domain'),
        'not_found_in_trash'    => __('Not found in Trash', 'text_domain'),
        'featured_image'        => __('Featured Image', 'text_domain'),
        'set_featured_image'    => __('Set featured image', 'text_domain'),
        'remove_featured_image' => __('Remove featured image', 'text_domain'),
        'use_featured_image'    => __('Use as featured image', 'text_domain'),
        'insert_into_item'      => __('Insert into stain', 'text_domain'),
        'uploaded_to_this_item' => __('Uploaded to this stain', 'text_domain'),
        'items_list'            => __('Stain Options list', 'text_domain'),
        'items_list_navigation' => __('Stain list navigation', 'text_domain'),
        'filter_items_list'     => __('Filter stain list', 'text_domain'),
    );

    $args = array(
        'label'                 => __('Stain', 'text_domain'),
        'description'           => __('Post Type for Wood Stain', 'text_domain'),
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
        'rest_base'             => 'stain', // Optional: custom endpoint base
    );

    register_post_type('stain', $args);
}
add_action('init', 'register_stain_post_type', 0);

// Remove the 'Add Media' button for the 'stain' custom post type
function remove_media_button_from_stain() {
    global $pagenow;
    if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) {
        if ( 'stain' === get_post_type() ) {
            remove_action( 'media_buttons', 'media_buttons' );
        }
    }
}
add_action( 'admin_head', 'remove_media_button_from_stain' );

// Remove the Visual/Text editor for the 'stain' custom post type
function remove_editor_from_stain() {
    remove_post_type_support( 'stain', 'editor' );
}
add_action( 'init', 'remove_editor_from_stain' );

/**
 * Sets the default sort order for the 'stain' custom post type in the WP admin.
 */
function sm_set_stain_cpt_admin_order($query) {
    if (is_admin() && $query->is_main_query() && $query->get('post_type') === 'stain') {
        $query->set('orderby', 'menu_order');
        $query->set('order', 'ASC');
    }
}
add_action('pre_get_posts', 'sm_set_stain_cpt_admin_order');