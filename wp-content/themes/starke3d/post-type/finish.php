<?php
// Register Custom Post Type for Wood Finish
function register_finish_post_type() {
    $labels = array(
        'name'                  => _x('Finish', 'Post Type General Name', 'text_domain'),
        'singular_name'         => _x('Finish', 'Post Type Singular Name', 'text_domain'),
        'menu_name'             => __('Finish', 'text_domain'),
        'name_admin_bar'        => __('Finish', 'text_domain'),
        'archives'              => __('Finish Archives', 'text_domain'),
        'attributes'            => __('Finish Attributes', 'text_domain'),
        'parent_item_colon'     => __('Parent Finish:', 'text_domain'),
        'all_items'             => __('Finish Options', 'text_domain'),
        'add_new_item'          => __('Add New Finish', 'text_domain'),
        'add_new'               => __('Add New', 'text_domain'),
        'new_item'              => __('New Finish', 'text_domain'),
        'edit_item'             => __('Edit Finish', 'text_domain'),
        'update_item'           => __('Update Finish', 'text_domain'),
        'view_item'             => __('View Finish', 'text_domain'),
        'view_items'            => __('View Finish', 'text_domain'),
        'search_items'          => __('Search Finish', 'text_domain'),
        'not_found'             => __('Not found', 'text_domain'),
        'not_found_in_trash'    => __('Not found in Trash', 'text_domain'),
        'featured_image'        => __('Featured Image', 'text_domain'),
        'set_featured_image'    => __('Set featured image', 'text_domain'),
        'remove_featured_image' => __('Remove featured image', 'text_domain'),
        'use_featured_image'    => __('Use as featured image', 'text_domain'),
        'insert_into_item'      => __('Insert into finish', 'text_domain'),
        'uploaded_to_this_item' => __('Uploaded to this finish', 'text_domain'),
        'items_list'            => __('Finish Options list', 'text_domain'),
        'items_list_navigation' => __('Finish list navigation', 'text_domain'),
        'filter_items_list'     => __('Filter finish list', 'text_domain'),
    );

    $args = array(
        'label'                 => __('Finish', 'text_domain'),
        'description'           => __('Post Type for Wood Finish', 'text_domain'),
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
        'rest_base'             => 'finish', // Optional: custom endpoint base
    );

    register_post_type('finish', $args);
}
add_action('init', 'register_finish_post_type', 0);

// Remove the 'Add Media' button for the 'finish' custom post type
function remove_media_button_from_finish() {
    global $pagenow;
    if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) {
        if ( 'finish' === get_post_type() ) {
            remove_action( 'media_buttons', 'media_buttons' );
        }
    }
}
add_action( 'admin_head', 'remove_media_button_from_finish' );

// Remove the Visual/Text editor for the 'finish' custom post type
function remove_editor_from_finish() {
    remove_post_type_support( 'finish', 'editor' );
}
add_action( 'init', 'remove_editor_from_finish' );

/**
 * Sets the default sort order for the 'finish' custom post type in the WP admin.
 * This will make the admin list match the order set by the spreadsheet import.
 */
function sm_set_finish_cpt_admin_order($query) {
    // We only want to affect the main query on the 'finish' post type's admin screen.
    if (is_admin() && $query->is_main_query() && $query->get('post_type') === 'finish') {
        // Set the query to order by our custom field, 'menu_order'.
        $query->set('orderby', 'menu_order');
        // Set the order to ascending (0, 1, 2, ...).
        $query->set('order', 'ASC');
    }
}
add_action('pre_get_posts', 'sm_set_finish_cpt_admin_order');