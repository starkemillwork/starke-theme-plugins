<?php
// Register Custom Post Type for Doors
function register_door_post_type() {
    $labels = array(
        'name'                  => _x('Doors', 'Post Type General Name', 'text_domain'),
        'singular_name'         => _x('Door', 'Post Type Singular Name', 'text_domain'),
        'menu_name'             => __('Doors', 'text_domain'),
        'name_admin_bar'        => __('Door', 'text_domain'),
        'archives'              => __('Door Archives', 'text_domain'),
        'attributes'            => __('Door Attributes', 'text_domain'),
        'parent_item_colon'     => __('Parent Door:', 'text_domain'),
        'all_items'             => __('All Doors', 'text_domain'),
        'add_new_item'          => __('Add New Door', 'text_domain'),
        'add_new'               => __('Add New', 'text_domain'),
        'new_item'              => __('New Door', 'text_domain'),
        'edit_item'             => __('Edit Door', 'text_domain'),
        'update_item'           => __('Update Door', 'text_domain'),
        'view_item'             => __('View Door', 'text_domain'),
        'view_items'            => __('View Doors', 'text_domain'),
        'search_items'          => __('Search Door', 'text_domain'),
        'not_found'             => __('Not found', 'text_domain'),
        'not_found_in_trash'    => __('Not found in Trash', 'text_domain'),
        'featured_image'        => __('Featured Image', 'text_domain'),
        'set_featured_image'    => __('Set featured image', 'text_domain'),
        'remove_featured_image' => __('Remove featured image', 'text_domain'),
        'use_featured_image'    => __('Use as featured image', 'text_domain'),
        'insert_into_item'      => __('Insert into door', 'text_domain'),
        'uploaded_to_this_item' => __('Uploaded to this door', 'text_domain'),
        'items_list'            => __('Doors list', 'text_domain'),
        'items_list_navigation' => __('Doors list navigation', 'text_domain'),
        'filter_items_list'     => __('Filter doors list', 'text_domain'),
    );

    $args = array(
        'label'                 => __('Door', 'text_domain'),
        'description'           => __('Post Type for Doors', 'text_domain'),
        'labels'                => $labels,
        'supports'              => array('title', 'thumbnail', 'page-attributes'), // Supports Title, Featured Image, and Order
        'hierarchical'          => false,
        'public'                => true,
        'show_ui'               => true,
        'show_in_menu'          => true, // *** KEY CHANGE: This creates the top-level menu item ***
        'menu_position'         => 55, // Position in the menu (below WooCommerce/Products)
        'menu_icon'             => 'dashicons-admin-home', // A fitting icon for Doors
        'show_in_admin_bar'     => true,
        'show_in_nav_menus'     => true,
        'can_export'            => true,
        'has_archive'           => true,
        'exclude_from_search'   => true,   // Kept from your original files
        'publicly_queryable'    => false,  // Kept from your original files
        'capability_type'       => 'post',
        'show_in_rest'          => true, // Enable REST API support
        'rest_base'             => 'doors', 
    );

    register_post_type('door', $args); // Registered as 'door' (singular)
}
add_action('init', 'register_door_post_type', 0);

// Remove the 'Add Media' button for the 'door' custom post type
function remove_media_button_from_door() {
    global $pagenow;
    if ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) {
        if ( 'door' === get_post_type() ) {
            remove_action( 'media_buttons', 'media_buttons' );
        }
    }
}
add_action( 'admin_head', 'remove_media_button_from_door' );

// Remove the Visual/Text editor for the 'door' custom post type
// This is perfect for when you are using ACF for all content fields
function remove_editor_from_door() {
    remove_post_type_support( 'door', 'editor' );
}
add_action( 'init', 'remove_editor_from_door' );

// *** NOTE: We do NOT need the 'move_..._to_woocommerce_menu' function
// because 'show_in_menu' => true in register_post_type() already handled it.

/**
 * Sets the default sort order for the 'door' custom post type in the WP admin.
 * This uses the 'menu_order' field enabled by 'page-attributes' support.
 */
function sm_set_door_cpt_admin_order($query) {
    // We only want to affect the main query on the 'door' post type's admin screen.
    if (is_admin() && $query->is_main_query() && $query->get('post_type') === 'door') {
        
        // *** THIS IS THE FIX ***
        // Only apply this default sort if the user hasn't clicked a column header.
        // If 'orderby' is in the URL, $_GET['orderby'] will be set, so this code won't run.
        if ( empty( $_GET['orderby'] ) ) {
            // Set the query to order by our custom field, 'menu_order'.
            $query->set('orderby', 'menu_order');
            // Set the order to ascending (0, 1, 2, ...).
            $query->set('order', 'ASC');
        }
    }
}
add_action('pre_get_posts', 'sm_set_door_cpt_admin_order');

/**
 * Auto-set Featured Image for 'Door' posts.
 * LOGIC: Meta Search for ACTUAL Filename (bypassing Slug collisions).
 */
add_action('save_post_door', 'starke_auto_set_door_featured_image', 10, 3);

function starke_auto_set_door_featured_image($post_id, $post, $update) {
    // 1. Safety Checks
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    // 2. Init Logger
    $logger = wc_get_logger();
    $context = array( 'source' => 'starke-door-debug' );

    $door_slug = $post->post_name; // e.g. '8040'
    
    if (empty($door_slug) || $door_slug === 'auto-draft') return;

    $logger->info( "--- START: Updating Door '{$door_slug}' (ID: {$post_id}) ---", $context );

    // 3. Define the filenames we are looking for
    // We want '8040.jpg', '8040.png', '8040.gif'
    // We use a wildcard search for the path: '%/8040.jpg'
    $possible_extensions = array('jpg', 'jpeg', 'png', 'gif');
    
    // We will build a meta query to find ANY attachment that ends in these filenames
    $meta_query = array('relation' => 'OR');
    
    foreach ($possible_extensions as $ext) {
        $meta_query[] = array(
            'key'     => '_wp_attached_file',
            'value'   => '/' . $door_slug . '.' . $ext,
            'compare' => 'LIKE' // Looks for '.../8040.png'
        );
        // Also check for files at the root (no slash)
        $meta_query[] = array(
            'key'     => '_wp_attached_file',
            'value'   => $door_slug . '.' . $ext,
            'compare' => '=' 
        );
    }

    // 4. Run the Query
    $image_args = array(
        'post_type'      => 'attachment',
        'post_mime_type' => array('image/jpeg', 'image/png', 'image/gif'),
        'posts_per_page' => 1, // We only need the first perfect match
        'post_status'    => 'inherit',
        'meta_query'     => $meta_query,
        'orderby'        => 'date', // Get the most recent upload if duplicates exist
        'order'          => 'DESC'
    );
    
    $images = get_posts($image_args);

    if (empty($images)) {
        $logger->warning( "FAIL: No image file found ending in '{$door_slug}.(jpg|png|gif)'", $context );
        return;
    }

    // 5. Verify the Match (Double Check)
    // The 'LIKE' query might accidentally match '18040.png' if we aren't careful.
    // So we do a quick PHP check on the found image.
    $found_image = $images[0];
    $file_path = get_post_meta($found_image->ID, '_wp_attached_file', true);
    $file_name = basename($file_path); // Extracts '8040.png' from '2025/02/8040.png'
    
    // Strip extension to compare
    $name_without_ext = pathinfo($file_name, PATHINFO_FILENAME);

    if ($name_without_ext === $door_slug) {
        // SUCCESS
        if (get_post_thumbnail_id($post_id) != $found_image->ID) {
            set_post_thumbnail($post_id, $found_image->ID);
            $logger->info( "SUCCESS: Linked image '{$file_name}' (ID: {$found_image->ID}) to door.", $context );
        } else {
             $logger->info( "SKIPPING: Correct image already attached.", $context );
        }
    } else {
        $logger->warning( "MISMATCH: Found '{$file_name}' but it is not an exact match for '{$door_slug}'.", $context );
    }
}

/**
 * Auto-trigger the featured image search when opening the edit screen,
 * so the user doesn't have to click "Update" first.
 */
function starke_trigger_featured_image_on_edit_screen() {
    // Make sure we are in the admin and editing a specific post
    if ( ! is_admin() || ! isset( $_GET['post'] ) || ! isset( $_GET['action'] ) || $_GET['action'] !== 'edit' ) {
        return;
    }

    $post_id = intval( $_GET['post'] );
    $post = get_post( $post_id );

    // Only run this for 'door' post types
    if ( ! $post || $post->post_type !== 'door' ) {
        return;
    }

    // If a featured image is already set in the database, do nothing
    if ( has_post_thumbnail( $post_id ) ) {
        return;
    }

    // Run your existing function to search and attach the image right now!
    starke_auto_set_door_featured_image( $post_id, $post, false );
}
// Hook into the screen loading process before the HTML is rendered
add_action( 'current_screen', 'starke_trigger_featured_image_on_edit_screen' );