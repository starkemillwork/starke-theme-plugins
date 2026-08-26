<?php
/*
Plugin Name: Login as Customer
Description: Allows administrators to log in as customers. USE WITH CAUTION.
Version: 2.7
Author: Vern
*/


// Define a single, consistent nonce action name.
define( 'LOGIN_AS_CUSTOMER_NONCE', 'login_as_customer_nonce' );

/**
 * SECURITY: Detect and destroy spoofed impersonation cookies
 */
add_action( 'init', 'starke_block_impersonation_spoofing', 1 );

function starke_block_impersonation_spoofing() {
    // If neither cookie is set, it's a normal visitor. Do nothing.
    if ( !isset($_COOKIE['original_admin_id']) && !isset($_COOKIE['impersonated_user_id']) ) {
        return;
    }

    $admin_id = isset($_COOKIE['original_admin_id']) ? intval($_COOKIE['original_admin_id']) : 0;
    $user_id = isset($_COOKIE['impersonated_user_id']) ? intval($_COOKIE['impersonated_user_id']) : 0;

    $cookie_token_name = 'impersonation_token_' . $user_id;
    $db_token_key = '_impersonation_token_' . $user_id;

    $cookie_token = isset($_COOKIE[$cookie_token_name]) ? $_COOKIE[$cookie_token_name] : '';
    $saved_token  = get_user_meta($admin_id, $db_token_key, true);

    // If the token is missing, or doesn't match the database, this is a spoofing attempt or a dead session.
    if ( empty($cookie_token) || empty($saved_token) || ! hash_equals($saved_token, $cookie_token) ) {
        
        // 1. Send headers to force the browser to delete the fake cookies
        setcookie('original_admin_id', '', time() - 3600, '/', COOKIE_DOMAIN);
        setcookie('impersonated_user_id', '', time() - 3600, '/', COOKIE_DOMAIN);
        setcookie($cookie_token_name, '', time() - 3600, '/', COOKIE_DOMAIN);
        setcookie('impersonated_user_admin_bar_setting', '', time() - 3600, '/', COOKIE_DOMAIN);
        setcookie('impersonation_start_time', '', time() - 3600, '/', COOKIE_DOMAIN);

        // 2. Unset them from PHP immediately so the rest of the code (like the Session Handler) acts like they don't exist
        unset($_COOKIE['original_admin_id']);
        unset($_COOKIE['impersonated_user_id']);
        unset($_COOKIE[$cookie_token_name]);

        // 3. Destroy the current WooCommerce session to dump the illegally loaded cart
        if ( function_exists('WC') && isset(WC()->session) ) {
            WC()->session->destroy_session();
        }
    }
}

/**
 * Intercept email links with the auto-impersonate flag.
 * Ensures the admin is authenticated first, generates a valid nonce, 
 * and seamlessly redirects them into the impersonated checkout.
 */
add_action('admin_init', 'starke_handle_email_auto_impersonate');
function starke_handle_email_auto_impersonate() {
    // Check that we are on the HPOS orders page and our custom URL flag is present
    if ( isset($_GET['page']) && $_GET['page'] === 'wc-orders' && isset($_GET['id']) && isset($_GET['starke_auto_impersonate']) ) {
        
        // Ensure the user clicking the link has the correct permissions
        if ( current_user_can('edit_users') && current_user_can('manage_woocommerce') ) {
            $order_id = intval($_GET['id']);
            $order = wc_get_order($order_id);
            
            // Verify order exists and has an attached customer account
            if ( $order && $order->get_customer_id() ) {
                $user_id = $order->get_customer_id();
                
                // Generate a fresh, valid nonce for this specific logged-in admin
                if ( ! defined( 'LOGIN_AS_CUSTOMER_NONCE' ) ) {
                    define( 'LOGIN_AS_CUSTOMER_NONCE', 'login_as_customer_nonce' );
                }
                $nonce = wp_create_nonce( LOGIN_AS_CUSTOMER_NONCE );
                
                // Build the AJAX URL to trigger your existing impersonation system
                $login_url = add_query_arg(
                    array(
                        'action'      => 'login_as_customer',
                        'user_id'     => $user_id,
                        'order_id'    => $order_id,
                        '_wpnonce'    => $nonce,
                        'destination' => 'checkout', // Forces redirect straight to checkout
                    ),
                    admin_url( 'admin-ajax.php' )
                );
                
                // Execute the redirect before the admin page renders
                wp_safe_redirect( $login_url );
                exit;
            }
        }
    }
}

// Add Login as Customer button to the Users list table -- START
/**
 * Add a custom 'Login as Customer' column to the Users admin list table.
 *
 * @param array $columns Existing columns.
 * @return array Modified columns array with the new column added.
 */
function ts_add_login_as_user_column( $columns ) {
    // Add the new column before the 'Posts' column, or adjust as needed.
    // To add it at the end: $columns['login_as_customer_button'] = __( 'Login as Customer', 'your-text-domain' );
    // To add it before 'Posts':
    $new_columns = array();
    foreach ( $columns as $key => $value ) {
        if ( $key === 'posts' ) {
            $new_columns['login_as_customer_button'] = __( 'Login as Customer', 'your-text-domain' );
        }
        $new_columns[ $key ] = $value;
    }
     // If 'posts' column wasn't found, add it at the end
    if (!isset($new_columns['login_as_customer_button'])) {
         $new_columns['login_as_customer_button'] = __( 'Login as Customer', 'your-text-domain' );
    }

    return $new_columns;
}
add_filter( 'manage_users_columns', 'ts_add_login_as_user_column' );

/**
 * Display the 'Login as Customer' button in the custom column on the Users page.
 *
 * @param string $output      Custom column output. Default empty.
 * @param string $column_name Name of the custom column.
 * @param int    $user_id     ID of the current user in the row.
 * @return string HTML output for the column (the button or placeholder).
 */
function ts_display_login_as_customer_button_column( $output, $column_name, $user_id ) {
    // Check if it's our custom column.
    if ( 'login_as_customer_button' === $column_name ) {

        // Get the target user object.
        $user_object = get_userdata( $user_id );
        if ( ! $user_object ) {
            return '—'; // User not found
        }

        // --- Replicate permission checks from add_login_as_customer_link() ---
        // Check if the current user can edit users and manage WooCommerce.
        if ( current_user_can( 'edit_users' ) && current_user_can( 'manage_woocommerce' ) ) {
            // Check if the current user is NOT the same as the target user.
            if ( get_current_user_id() != $user_id ) {
                // Check if the target user is NOT an administrator.
                if ( ! user_can( $user_id, 'administrator' ) ) {

                    // --- Checks passed, create the button ---
                    $button_text = __( 'Login as Customer', 'starke-domain' );

                    // Define the nonce action name (must match the one in login-as-user.php).
                    if ( ! defined( 'LOGIN_AS_CUSTOMER_NONCE' ) ) {
                        define( 'LOGIN_AS_CUSTOMER_NONCE', 'login_as_customer_nonce' );
                    }
                    $nonce = wp_create_nonce( LOGIN_AS_CUSTOMER_NONCE );

                    // Construct the URL (no order_id needed here).
                    $login_url = add_query_arg(
                        array(
                            'action'      => 'login_as_customer', // From login-as-user.php
                            'user_id'     => $user_id,
                            '_wpnonce'    => $nonce,
                            'destination' => 'shop',
                        ),
                        admin_url( 'admin-ajax.php' )
                    );

                    // Output the button HTML using the primary button style.
                    return sprintf(
                        '<a href="%s" class="button button-primary js-login-as-customer" title="%s" aria-label="%s">%s</a>',
                        esc_url( $login_url ),
                        esc_attr( $button_text ),
                        esc_attr( $button_text ),
                        esc_html( $button_text )
                    );
                }
            }
        }
        // --- End permission checks ---

        // If checks fail, return a placeholder.
        return '—';
    }

    // Return original output for other columns.
    return $output;
}
add_filter( 'manage_users_custom_column', 'ts_display_login_as_customer_button_column', 10, 3 );

/**
 * Add the 'Open Order/Quote as Customer' button to the custom column in the Orders admin list.
 *
 * This function hooks into the custom column action for the HPOS Orders table.
 * It generates a button that allows an admin to log in as the customer
 * associated with the order, using the functionality from login-as-user.php.
 *
 * @param string   $column  The ID of the column being displayed.
 * @param WC_Order $order   The order object for the current row.
 */
// Hook into the action that renders custom columns in the HPOS Orders table.
// Make sure the priority is appropriate if other functions hook into the same action for this column.
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'ts_display_edit_order_quote_button_column', 10, 2 );
function ts_display_edit_order_quote_button_column( $column, $order ) {
    // Check if it's our custom 'edit' column and we have a valid order object.
    if ( 'edit' === $column && $order instanceof WC_Order ) {

        // --- STARKE: Hide the button completely for imported Legacy Orders ---
        if ( ! empty( $order->get_meta( '_legacy_order_id', true ) ) ) {
            return;
        }

        // Get the customer ID associated with the order.
        $customer_id = $order->get_customer_id();

        // Only display the button if there is a valid customer ID associated with the order.
        // Admins cannot impersonate guests or themselves, and the AJAX handler adds further checks.
        if ( $customer_id > 0 && $customer_id !== get_current_user_id() ) {
            $order_id = $order->get_id();
            
            // Set button text.
            $button_text = __( 'Open as Customer', 'your-text-domain' );

            // Define the nonce action name (must match the one in login-as-user.php).
            // Ensure LOGIN_AS_CUSTOMER_NONCE is defined, or define it here if not globally available.
            if ( ! defined( 'LOGIN_AS_CUSTOMER_NONCE' ) ) {
                define( 'LOGIN_AS_CUSTOMER_NONCE', 'login_as_customer_nonce' );
            }

            // Create the nonce for security.
            $nonce = wp_create_nonce( LOGIN_AS_CUSTOMER_NONCE );

            // Construct the URL for the login-as-customer action.
            $login_url = add_query_arg(
                array(
                    'action'      => 'login_as_customer', // Defined in login-as-user.php
                    'user_id'     => $customer_id,
                    'order_id'    => $order_id,          // Pass order_id to potentially load the cart
                    '_wpnonce'    => $nonce,
                    'destination' => 'checkout',
                ),
                admin_url( 'admin-ajax.php' )
            );

            // Output the button HTML.
            printf(
                '<a href="%s" class="button wc-action-button button-primary js-edit-as-customer" title="%s" aria-label="%s">%s</a>',
                esc_url( $login_url ),
                esc_attr( $button_text ),
                esc_attr( $button_text ),
                esc_html( $button_text )
            );

        } else {
            // Optionally display a placeholder or nothing if no customer or trying to impersonate self.
             //echo '—'; // Indicate no action available
        }
    }
}

/**
 * Adds JavaScript to the admin footer to prevent double-clicks on all
 * impersonation buttons that are added to admin list tables.
 */
function sm_add_impersonation_button_disable_script() {
    // Get the current screen to ensure we only run this on the Users or Orders list pages.
    $screen = get_current_screen();
    if ( $screen && in_array( $screen->id, ['users', 'woocommerce_page_wc-orders'], true ) ) {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Target both button classes with a single selector.
                $('.js-login-as-customer, .js-edit-as-customer').on('click', function(e) {
                    var $link = $(this);
                    var $row = $link.closest('tr'); // Find the parent table row
                    
                    // Prevent the link from being clicked more than once.
                    if ($link.data('clicked')) {
                        e.preventDefault();
                        return;
                    }
                    $link.data('clicked', true);
                    
                    // Visually disable the link and the ENTIRE ROW, then change the button text.
                    $link.css('pointer-events', 'none').css('opacity', '0.65');
                    $row.css('opacity', '0.5').css('pointer-events', 'none');
                    $link.text('Logging in...');
                });
            });
        </script>
        <?php
    }
}
add_action('admin_footer', 'sm_add_impersonation_button_disable_script');


/**
 * Add custom CSS to adjust the width of the 'Login as Customer' column on the Users page.
 */
function ts_custom_users_column_width_css() {
    $screen = get_current_screen();
    // Check if we are on the Users list page.
    if ( $screen && 'users' === $screen->id ) {
        ?>
        <style type="text/css">
            /* Target the specific column header and cell by its ID ('login_as_customer_button') */
            .wp-list-table th#login_as_customer_button, /* ID selector for th */
            .wp-list-table td.column-login_as_customer_button { /* Class selector for td */
                width: 130px; /* Adjust this value as needed */
            }
        </style>
        <?php
    }
}
// Hook the CSS function into the admin head.
add_action( 'admin_head', 'ts_custom_users_column_width_css' );
// Add Login as Customer button to the Users list table -- END


// Add custom button to the 'Order Actions' meta box (Top)
function add_custom_button_to_order_actions_meta_box( $order_id ) {
    
    // Get the order object (Hook passes ID, not object)
    $order = wc_get_order($order_id);
    if (!$order) return;

    // --- FIX: Hide "Open Order as Customer" button for Balance Invoices ---
    // We don't want admins logging in to pay a balance invoice via this button; 
    // they should log in to the main order if needed.
    if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
        return;
    }

    // --- STARKE: Hide "Open Order as Customer" button for Legacy Orders ---
    if ( ! empty( $order->get_meta( '_legacy_order_id', true ) ) ) {
        return;
    }

    $user_id = $order->get_customer_id();
    if (!$user_id) return; // Don't show if no customer attached

    // Get the order status (without the "wc-" prefix)
    $order_status = str_replace('wc-', '', $order->get_status());

    // Set button text based on order status
    $button_text = in_array($order_status, ['active-quote', 'expired-quote', 'pending-quote', 'deleted-quote', 'freight-quote', 'ordered-quote']) ? 'Open Quote as Customer' : 'Open Order as Customer';
    
    ?>
    <button type="button" 
            class="button button-primary" 
            id="custom_order_action_button" 
            style="margin: 0;">
        <?php echo esc_html($button_text); ?>
    </button>
    
    <script>        
        document.getElementById('custom_order_action_button').addEventListener('click', function() {
            this.disabled = true;
            this.textContent = 'Logging in...';
            
            const orderId = <?php echo esc_js($order_id); ?>;
            const userId = <?php echo esc_js($user_id); ?>;
            const nonce = '<?php echo wp_create_nonce(LOGIN_AS_CUSTOMER_NONCE); ?>';
            const ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            const destination = 'checkout';

            // Redirect using the same logic as your original code
            window.location.href = `${ajaxUrl}?action=login_as_customer&user_id=${userId}&order_id=${orderId}&_wpnonce=${nonce}&destination=${destination}`;
        });
    </script>
    <?php
}
// Hook into the start of the Order Actions box (Priority 10 puts it inside the wrapper)
add_action('woocommerce_order_actions_start', 'add_custom_button_to_order_actions_meta_box', 10);

// Main AJAX handler for "Login as Customer" action
add_action('wp_ajax_login_as_customer', 'login_as_customer');
function login_as_customer() {
    if (!current_user_can('edit_users') || !current_user_can('manage_woocommerce')) {
        wp_send_json_error('You do not have permission.');
        exit;
    }
    if (!isset($_GET['user_id']) || !isset($_GET['_wpnonce'])) {
        wp_send_json_error('Missing parameters.');
        exit;
    }
    if (!wp_verify_nonce($_GET['_wpnonce'], LOGIN_AS_CUSTOMER_NONCE)) {
        wp_send_json_error('Invalid nonce.');
        exit;
    }



    $user_id = intval($_GET['user_id']);
    $user = get_user_by('id', $user_id);
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null; // Order ID is optional

    if (!$user) {
        wp_send_json_error('Invalid user ID.');
        exit;
    }
    // If these cookies are present but the user passed the Admin capability checks above,
    // they are definitively an Admin and these cookies are just stale leftovers from a closed tab.
    // We clean up the old database token and let the script continue to initiate the new session!
    if (isset($_COOKIE['original_admin_id']) && isset($_COOKIE['impersonated_user_id'])) {
        $stale_admin_id = intval($_COOKIE['original_admin_id']);
        $stale_user_id  = intval($_COOKIE['impersonated_user_id']);
        delete_user_meta( $stale_admin_id, '_impersonation_token_' . $stale_user_id );
    }
    if (user_can($user_id, 'administrator')) {
        wp_send_json_error('Cannot log in as another administrator.');
        exit;
    }

    $admin_id = get_current_user_id();
    //$_SESSION['original_admin_id'] = $admin_id;
    //$_SESSION['impersonation_start_time'] = time(); // Store the timestamp

    // Store the original setting of the impersonated user.
    //$_SESSION['impersonated_user_id'] = $user_id;
    $original_admin_bar_setting = get_user_meta($user_id, 'show_admin_bar_front', true);
    //$_SESSION['impersonated_user_admin_bar_setting'] = $original_admin_bar_setting !== '' ? $original_admin_bar_setting : 'false';
    $original_admin_bar_setting = $original_admin_bar_setting !== '' ? $original_admin_bar_setting : 'false';

    impersonation_start($admin_id, $user_id, $original_admin_bar_setting);
    if ($order_id) {
        set_initiate_impersonation_order_quote_edit_mode(); // Flag to indicate editing mode for an existing order/quote is on by a valid impersonating admin
        set_editing_original_order_id($order_id); // Store the original order ID in a cookie
    }
    
    //start_admin_impersonation(get_current_user_id(), $user_id);

    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true);
    do_action('wp_login', $user->user_login, $user);

    $permalink = isset($_GET['destination']) ? sanitize_text_field($_GET['destination']) : 'myaccount';
    ////wp_safe_redirect(wc_get_page_permalink('myaccount'));
    wp_redirect(wc_get_page_permalink($permalink)); // Change back to 'myaccount' after testing
    exit;
}

/**
 * NEW: Securely clears the one-time impersonation/edit flag on a later hook
 * to prevent race conditions and ensure the cart is fully processed first.
 */
add_action('template_redirect', 'cleanup_impersonation_order_quote_load_flags');
function cleanup_impersonation_order_quote_load_flags() {
    // This cookie should only exist on the front-end for the impersonated user.
    if (is_admin()) {
        return;
    }

    // If the one-time "initiate edit mode" flag is present, clear it.
    // This function will now only run once per session, which is what we want.
    if (isset($_COOKIE['initiate_impersonation_order_quote_edit_mode'])) {
        unset_initiate_impersonation_order_quote_edit_mode();
        unset_editing_original_order_id();
    }
}

// Handles admin's cart data for the customer -- START
/*
* 
*/
// Disable default persistent cart for admin
add_filter( 'woocommerce_persistent_cart_enabled', 'disable_persistent_cart_for_impersonation' );
function disable_persistent_cart_for_impersonation( $enabled ) {
    // Check if an admin is impersonating a customer
    if ( impersonation_is_active() ) {
        return false; // Disable persistent cart for this session
    }
    return $enabled; // Keep default behavior otherwise
}

/**
 * Load the persistent cart from a custom user meta field
 * for an admin impersonating a customer.
 */
add_action( 'woocommerce_load_cart_from_session', 'load_admin_impersonation_persistent_cart', 20 );
function load_admin_impersonation_persistent_cart() {
    // Only proceed if impersonation is active.
    if (!is_user_logged_in() && !impersonation_is_active()) {
        return;
    }

    // Explicitly editing a specific order/quote. This takes priority.
    if (isset($_COOKIE['initiate_impersonation_order_quote_edit_mode']) && isset($_COOKIE['editing_original_order_id'])) {
        $order_id = intval($_COOKIE['editing_original_order_id']);
        if ($order_id) {
            load_order_quote_into_cart($order_id);
        }
        // IMPORTANT: We stop here to prevent the persistent cart from overwriting our work.
        return;
    }

    // Or load the saved-to-admin persistent cart for customer if not editing an order/quote.
    // Get the admin ID (the real admin) and the impersonated customer's ID.
    $admin_id = isset($_COOKIE['original_admin_id']) ? $_COOKIE['original_admin_id'] : null;
    $user_id = isset($_COOKIE['impersonated_user_id']) ? $_COOKIE['impersonated_user_id'] : null;

    if (!$admin_id || !$user_id) {
        return; // Exit early if we are in a background process with no session
    }

    // Retrieve all persistent carts stored in the admin's user meta.
    $persistent_carts = get_user_meta( $admin_id, '_admin_persistent_carts', true );
    if ( ! is_array( $persistent_carts ) ) {
        $persistent_carts = [];
    }

    // Build a unique key for this customer's cart in the array.
    //$cart_key = get_current_blog_id() . '_' . $customer_id;
    $cart_key = 'admin_' . $admin_id . '_user_' . $user_id;

    // Check if the cart exists in the stored array and load it.
    if ( isset( $persistent_carts[$cart_key]['cart'] ) && is_array( $persistent_carts[$cart_key]['cart'] ) ) {
        // Set cart contents
        $cart_contents = $persistent_carts[$cart_key]['cart'];
        WC()->cart->set_cart_contents( $cart_contents );

        // ALSO store it in the WooCommerce session
        WC()->session->set( 'cart', $cart_contents );
    }
}

/**
 * Save the impersonation cart persistently in the admin's user meta field.
 */
add_action( 'woocommerce_cart_updated', 'save_admin_impersonation_persistent_cart' );
function save_admin_impersonation_persistent_cart() {
    // Only run if impersonating and WC/Cart objects exist
    if ( impersonation_is_active() && function_exists('WC') && WC()->session && WC()->cart ) {
        $admin_id    = strval( $_COOKIE['original_admin_id'] );
        $customer_id = strval( $_COOKIE['impersonated_user_id'] );
        $blog_id     = get_current_blog_id();
        //$cart_key    = $blog_id . '_' . $customer_id;
        $cart_key = 'admin_' . $admin_id . '_user_' . $customer_id;

        // 1. Get the final cart state for this request
        //$cart_contents = WC()->cart->get_cart_for_session();
        $cart_contents = WC()->cart->get_cart();

        // 2. Get existing persistent carts for the admin
        $persistent_carts = get_user_meta( $admin_id, '_admin_persistent_carts', true );
        if ( ! is_array( $persistent_carts ) ) { $persistent_carts = []; }

        $persistent_carts[ $cart_key ] = array( 'cart' => $cart_contents );

        // 3. Update or remove the entry for this specific customer in the admin's meta
        /*if ( ! empty( $cart_contents ) ) {
            $persistent_carts[ $cart_key ] = array( 'cart' => $cart_contents );
        } elseif ( isset($persistent_carts[ $cart_key ]) ) {
             // Only remove if it exists, to avoid unnecessary updates if cart was already empty
             unset( $persistent_carts[ $cart_key ] );
        }*/

        // 4. Save the updated array back to admin meta (only if changed or non-empty)
        // Check if the data actually changed to avoid unnecessary DB writes
        $original_meta = get_user_meta( $admin_id, '_admin_persistent_carts', true );
        if ($original_meta !== $persistent_carts) {
             update_user_meta( $admin_id, '_admin_persistent_carts', $persistent_carts );
        }
    }
}

/*
* 
*/
// Handles admin's cart data for the customer -- END

// Force show admin bar for impersonating admins.
add_filter('show_admin_bar', 'impersonate_force_admin_bar');

function impersonate_force_admin_bar($show)
{
    if (isset($_COOKIE['original_admin_id'])) {
        return true; // Always show admin bar when impersonating.
    }
    return $show;
}

add_action('admin_bar_menu', 'add_switch_back_link', 999);

function add_switch_back_link($wp_admin_bar)
{
    if (isset($_COOKIE['original_admin_id'])) {
        $args = array(
            'id'    => 'switch_back',
            'title' => 'Switch Back to Admin',
            'href'  => wp_nonce_url(admin_url('admin-ajax.php?action=switch_back_to_admin'), 'switch_back_nonce'),
            'meta'  => array('class' => 'switch-back-link')
        );
        $wp_admin_bar->add_node($args);
    }
}

add_action('wp_ajax_switch_back_to_admin', 'handle_switch_back_to_admin');
add_action('wp_ajax_nopriv_switch_back_to_admin', 'handle_switch_back_to_admin');
function handle_switch_back_to_admin() {
    if (!isset($_COOKIE['original_admin_id'])) {
        wp_send_json_error('No original admin ID.');
        exit;
    }
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'switch_back_nonce')) {
        wp_send_json_error('Invalid nonce.');
        exit;
    }
    
    restore_admin_after_customer_logout();

    // Redirect to the WooCommerce Orders page.
    wp_redirect(admin_url('admin.php?page=wc-orders')); // Maybe use 'admin_url('admin.php?page=wc-orders')' or 'admin_url('users.php')'
    exit;
}

// Handle logout during impersonation.
// FIX: Changed hook from 'wp_logout' to 'clear_auth_cookie' at Priority 99.
// This safely intercepts the logout sequence right after our compare list saves, 
// but BEFORE WordPress sends its default clearing cookies, solving the Nginx 502 Bad Gateway.
add_action('clear_auth_cookie', 'handle_impersonation_logout', 99);

function handle_impersonation_logout() {
    if (isset($_COOKIE['original_admin_id'])) {
        restore_admin_after_customer_logout();	
        
        // Redirect to the WooCommerce Orders page.
        wp_redirect(admin_url('admin.php?page=wc-orders')); 
        exit;
    }
}

// AJAX handler for inactivity logout
add_action('wp_ajax_nopriv_inactivity_logout', 'handle_inactivity_logout');
add_action('wp_ajax_inactivity_logout', 'handle_inactivity_logout');

function handle_inactivity_logout() {
   if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'impersonation_inactivity_nonce' ) ) {
        wp_send_json_error( 'Invalid nonce.' );
        exit;
    }
    if (isset($_COOKIE['original_admin_id'])) {
        restore_admin_after_customer_logout();
    }
    // No redirect here; JavaScript will handle it.
    wp_send_json_success(); // Send success response
    exit;
}

// Enqueue the JavaScript for inactivity tracking.
add_action('wp_enqueue_scripts', 'enqueue_impersonation_inactivity_script');

function enqueue_impersonation_inactivity_script() {
    if (isset($_COOKIE['original_admin_id'])) {
        // Only enqueue when impersonating.
        wp_enqueue_script('impersonation-inactivity', get_stylesheet_directory_uri() . '/assets/js/login-as-user.js', array(), '1.0', true);
        // Pass the nonce to JavaScript
        $local_vars = array(
            'nonce' => wp_create_nonce( 'impersonation_inactivity_nonce' ),
			'ajaxurl' => admin_url('admin-ajax.php'), // Add this line!
        );

        wp_localize_script( 'impersonation-inactivity', 'impersonation_vars', $local_vars );
    }
}

function restore_admin_after_customer_logout() {
    $original_admin_id = impersonation_get_original_admin_id();
    $impersonated_user_id = impersonation_get_impersonated_user_id();
    
    // Construct the unique keys
    $db_token_key = '_impersonation_token_' . $impersonated_user_id;
    $cookie_token_name = 'impersonation_token_' . $impersonated_user_id;
    
    // NEW SECURITY CHECK
    $cookie_token = isset($_COOKIE[$cookie_token_name]) ? $_COOKIE[$cookie_token_name] : '';
    $saved_token  = get_user_meta($original_admin_id, $db_token_key, true);

    // If the tokens don't exist or don't match, abort immediately.
    if ( empty($cookie_token) || empty($saved_token) || ! hash_equals($saved_token, $cookie_token) ) {
        wp_die('Security check failed. Impersonation token invalid.');
    }

    // Restore the impersonated user's admin bar setting.
    update_user_meta($impersonated_user_id, 'show_admin_bar_front', impersonation_get_impersonated_user_admin_bar_setting());

    // --- THE 2FA INFINITE LOOP FIX ---
    
    // 1. End Impersonation Early: Clear the impersonation cookies and database tokens BEFORE triggering wp_login. 
    // This ensures that if the 2FA plugin takes over the screen, the impersonation is already safely cleaned up.
    impersonation_end($original_admin_id);

    // 2. Prevent Infinite Recursion: Unhook your custom logout listener.
    // This allows the 2FA plugin to temporarily clear the auth cookie to enforce the 2FA challenge 
    // without your script fighting back and causing an endless loop.
    remove_action('clear_auth_cookie', 'handle_impersonation_logout', 99);

    // 3. Log back in as the original admin. (The 2FA plugin will now safely intercept this).
    wp_set_current_user($original_admin_id);
    wp_set_auth_cookie($original_admin_id);
    do_action('wp_login', get_userdata($original_admin_id)->user_login, get_userdata($original_admin_id));
}

// --- Impersonation Helper Functions Using Cookies ---
function impersonation_is_active() {
    return isset($_COOKIE['original_admin_id'], $_COOKIE['impersonated_user_id']) &&
           !empty($_COOKIE['original_admin_id']) &&
           !empty($_COOKIE['impersonated_user_id']);
}

function impersonation_get_original_admin_id() {
    return strval($_COOKIE['original_admin_id'] ?? '');
}

function impersonation_get_impersonated_user_id() {
    return strval($_COOKIE['impersonated_user_id'] ?? '');
}

function impersonation_get_impersonation_start_time() {
    return strval($_COOKIE['impersonation_start_time'] ?? '');
}

function impersonation_get_impersonated_user_admin_bar_setting() {
    return boolval($_COOKIE['impersonated_user_admin_bar_setting'] ?? false);
}

function get_editing_original_order_id() {
    return intval($_COOKIE['editing_original_order_id'] ?? 0);
}

function set_editing_original_order_id($order_id) {
    setcookie('editing_original_order_id', $order_id, 0, '/', COOKIE_DOMAIN, is_ssl(), true);
}

function unset_editing_original_order_id() {
    setcookie('editing_original_order_id', '', time() - 3600, '/', COOKIE_DOMAIN);
}

function get_edit_mode_order_quote_number() {
    return strval($_COOKIE['edit_mode_order_quote_number'] ?? '0');
}

function set_edit_mode_order_quote_number($starke_order_number) {
    setcookie('edit_mode_order_quote_number', $starke_order_number, 0, '/', COOKIE_DOMAIN, is_ssl(), true);
}

function unset_edit_mode_order_quote_number() {
    setcookie('edit_mode_order_quote_number', '', time() - 3600, '/', COOKIE_DOMAIN);
}

function get_initiate_impersonation_order_quote_edit_mode() {
    return strval($_COOKIE['initiate_impersonation_order_quote_edit_mode'] ?? '');
}

function set_initiate_impersonation_order_quote_edit_mode() {
    setcookie('initiate_impersonation_order_quote_edit_mode', true, 0, '/', COOKIE_DOMAIN, is_ssl(), true);
}

function unset_initiate_impersonation_order_quote_edit_mode() {
    setcookie('initiate_impersonation_order_quote_edit_mode', '', time() - 3600, '/', COOKIE_DOMAIN);
}

function impersonation_start($admin_id, $user_id, $setting) {
    // Generate a random, unguessable token
    $token = wp_generate_password(32, false);
    
    // Create unique keys using the impersonated user's ID
    $db_token_key = '_impersonation_token_' . $user_id;
    $cookie_token_name = 'impersonation_token_' . $user_id;
    
    // Save the token to the admin's database profile using the unique key
    update_user_meta( $admin_id, $db_token_key, $token );

    // Set a 12-hour expiration instead of 0
    $expire = time() + (12 * HOUR_IN_SECONDS);

    // Secure, HTTP-only cookies
    setcookie('original_admin_id', $admin_id, $expire, '/', COOKIE_DOMAIN, is_ssl(), true);
    $_COOKIE['original_admin_id'] = $admin_id; // Makes available to current request

    setcookie('impersonated_user_id', $user_id, $expire, '/', COOKIE_DOMAIN, is_ssl(), true);
    $_COOKIE['impersonated_user_id'] = $user_id; // Makes available to current request

    setcookie('impersonation_start_time', time(), $expire, '/', COOKIE_DOMAIN, is_ssl(), true);
    setcookie('impersonated_user_admin_bar_setting', $setting, $expire, '/', COOKIE_DOMAIN, is_ssl(), true);
    setcookie($cookie_token_name, $token, $expire, '/', COOKIE_DOMAIN, is_ssl(), true);
}

function impersonation_end($admin_id) {
    // We must grab the user ID before we destroy the cookie!
    $impersonated_user_id = impersonation_get_impersonated_user_id();
    $db_token_key = '_impersonation_token_' . $impersonated_user_id;
    $cookie_token_name = 'impersonation_token_' . $impersonated_user_id;

    setcookie('original_admin_id', '', time() - 3600, '/', COOKIE_DOMAIN);
    setcookie('impersonated_user_id', '', time() - 3600, '/', COOKIE_DOMAIN);
    setcookie('impersonation_start_time', '', time() - 3600, '/', COOKIE_DOMAIN);
    setcookie('impersonated_user_admin_bar_setting', '', time() - 3600, '/', COOKIE_DOMAIN);
    setcookie($cookie_token_name, '', time() - 3600, '/', COOKIE_DOMAIN); // Clear unique token cookie
    
    setcookie('is_ship_mode', '', time() - 3600, '/');
    if ( isset($_COOKIE['is_ship_mode']) ) {
        unset($_COOKIE['is_ship_mode']);
    }
    
    WC()->session->set('edit_mode_order_quote_number', null);
    WC()->session->set('editing_original_order_id', null);
    WC()->session->set('cart_is_active_quote', null);
    WC()->session->set('cart_is_pending_quote', null);
    WC()->session->set('cart_is_freight_quote', null);
    WC()->session->set('cart_is_profiles_needed', null);

    // Delete the specific token from the DB
    delete_user_meta( $admin_id, $db_token_key ); 
    
    // Tell WooCommerce to regenerate session
	if (function_exists('WC') && WC()->session instanceof WC_Session_Handler) {
		WC()->session->destroy_session(); // deletes old session row
		WC()->session->set_customer_session_cookie(true); // issues a new session ID and cookie
	}
}



/**
 * Load order/quote items into the WooCommerce cart
 */
function load_order_quote_into_cart($order_id) {
    if (!class_exists('WC_Cart')) {
        return;
    }
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    WC()->session->set('is_loading_quote', true);
    WC()->cart->empty_cart();
    WC()->session->set('edit_mode_order_quote_number', null);
    WC()->session->set('editing_original_order_id', null);
    WC()->session->set('cart_is_active_quote', null);
    WC()->session->set('cart_is_pending_quote', null);
    WC()->session->set('cart_is_freight_quote', null);
    WC()->session->set('cart_is_profiles_needed', null);
    WC()->session->set('ltl_freight_approved', null);

    $is_impersonation_active = function_exists('impersonation_is_active') && impersonation_is_active();
    $order_status = $order->get_status();
    $starke_order_number = $order->get_meta('_starke_order_number', true);
    $quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'freight-quote', 'deleted-quote', 'ordered-quote'];
    $is_quote = in_array($order_status, $quote_statuses);

    // This ensures customers loading regular orders skip ALL of this logic.
    if (!empty($starke_order_number) && ($is_impersonation_active || $is_quote)) {
        WC()->session->set('edit_mode_order_quote_number', $starke_order_number);
        WC()->session->set('editing_original_order_id', $order_id);

        if ($order_status === 'active-quote') {
            if (class_exists('Quote_Lock_Controller')) {
                Quote_Lock_Controller::get_instance()->force_quote_lock();
                Quote_Lock_Controller::get_instance()->set_locked_quote_session($order, false);
                //if (isset($_COOKIE['initiate_impersonation_order_quote_edit_mode'])) {
                WC()->session->set('is_initial_cart_lock', true);
                //}
                WC()->session->set('cart_is_active_quote', true);
            }
        } elseif ($order_status === 'pending-quote' && $is_impersonation_active) {
                WC()->session->set('cart_is_pending_quote', true);
        } elseif ($order_status === 'freight-quote' && $is_impersonation_active) {
                WC()->session->set('cart_is_freight_quote', true);
                WC()->session->set('ltl_freight_cost', null);
        } elseif ($order_status === 'profiles-needed') {
            if (class_exists('Quote_Lock_Controller') && $is_impersonation_active) {
                Quote_Lock_Controller::get_instance()->force_quote_lock();
                Quote_Lock_Controller::get_instance()->set_locked_quote_session($order, true);
                WC()->session->set('cart_is_profiles_needed', true);
                WC()->session->set('is_initial_cart_lock', true);
            }
        }
    }

    // Restore Order Meta to Session
    $meta_to_session_keys = [
        '_jobsite_contact',
        '_jobsite_contact_cell_number',
        '_po_number_job_name',
        '_samples_address_po_number_job_name',
        '_samples_full_shipping_address',
        '_cc_emails',
        '_starke_payment_terms'
    ];
    foreach ($meta_to_session_keys as $key) {
        // Remove the leading underscore for the session key
        $session_key = ltrim($key, '_');
        $value = $order->get_meta($key, true);
        
        // FIX: Update the session regardless of whether value is empty or not.
        // This ensures stale session data is cleared when loading a different type of quote.
        if ( ! empty( $value ) ) {
            WC()->session->set($session_key, $value);
        } else {
            // Explicitly clear the session key if the order doesn't have this data
            WC()->session->set($session_key, null); 
        }
    }

    // Restore Products (including variations and metadata)
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $quantity = $item->get_quantity();
        $variation_id = $item->get_variation_id();
        $item_meta = $item->get_meta_data(); // Get all item meta data

        if ($product_id && get_post_status($product_id) === 'publish') {
            $cart_item_data = [];

            // Transfer metadata to the cart item
            foreach ($item_meta as $meta) {
                $key = $meta->key;
                $value = $meta->value;

                // Ensure booleans and integers are properly set
                if ($key === 'sample') {
                    $value = (bool) $value; // Convert numeric 1 to boolean true
                }

                // Exclude internal meta keys that shouldn't carry over or cause comparison issues
                if (strpos($key, '_') === 0 && !in_array($key, ['_line_total', '_line_subtotal', '_line_tax', '_line_tax_data'])) {
                     // Example: Skip keys like _product_id, _variation_id, _qty, _tax_class etc.
                     // Keep keys potentially needed for display or re-adding like custom ones.
                     // Adjust this logic based on exactly which meta needs to persist vs which are internal.
                     // For now, let's assume most hidden keys are internal unless specifically needed.
                     // If you have custom hidden keys starting with _, add them to an allowed list if needed.
                    // continue; // Option: Skip all hidden keys initially
                }
                $cart_item_data[$key] = $value;
            }
            $cart_item_data['is_loaded_from_order'] = true;
            WC()->cart->add_to_cart($product_id, $quantity, $variation_id, [], $cart_item_data);
        }
    }

    // Restore Fees
    //foreach ($order->get_fees() as $fee) {
    //    WC()->cart->add_fee($fee->get_name(), $fee->get_total(), true, $fee->get_tax_class());
    //}

    // Restore Payment Method
    WC()->session->set('chosen_payment_method', $order->get_payment_method());

    //wc_get_logger()->warning('$chosen_payment_method - login-as-user.php: ' . var_export(WC()->session->get( 'chosen_payment_method' ), true), ['source' => 'methods_debug29']);

    // Restore Billing and Shipping Addresses
    if (WC()->customer) {
        $billing_address = $order->get_address('billing');
        if (is_array($billing_address)) {
            foreach ($billing_address as $key => $value) {
                // Call the appropriate setter method on the customer object, e.g., set_billing_first_name()
                if (is_callable([WC()->customer, "set_billing_{$key}"])) {
                    WC()->customer->{"set_billing_{$key}"}($value);
                }
            }
        }

        $shipping_address = $order->get_address('shipping');
        if (is_array($shipping_address)) {
            foreach ($shipping_address as $key => $value) {
                // Call the appropriate setter method on the customer object, e.g., set_shipping_first_name()
                if (is_callable([WC()->customer, "set_shipping_{$key}"])) {
                    WC()->customer->{"set_shipping_{$key}"}($value);
                }
            }
        }
    }

    // Restore Shipping Methods
    $shipping_items = $order->get_items('shipping');
    $chosen_methods = [];
    if (!empty($shipping_items)) {
        $package_index = 0; 
        foreach ($shipping_items as $shipping_item) {
            // If this shipping item from the original quote is the LTL rate,
            // set its cost into the session.
            if ($shipping_item->get_name() === 'LTL Shipping' && !WC()->session->get('cart_is_freight_quote', false)) {
                WC()->session->set('ltl_freight_cost', $shipping_item->get_total());
                WC()->session->set('ltl_freight_approved', true);
            }

            $rate_id = $shipping_item->get_method_id() . ':' . $shipping_item->get_instance_id();
            $rate_label = $shipping_item->get_name(); // Get the stable label
            $chosen_methods[$package_index] = $rate_id;

            // --- FIX 1: Pre-populate Session Rates for Lock Controller ---
            // The Lock Controller checks 'shipping_for_package_X' immediately. 
            // Since calculation hasn't run yet, we must fake it so the check passes.
            $fake_rate = new WC_Shipping_Rate(
                $rate_id, 
                $rate_label, 
                $shipping_item->get_total(), 
                [], 
                $shipping_item->get_method_id(), 
                $shipping_item->get_instance_id()
            );
            // Inject into session
            WC()->session->set('shipping_for_package_' . $package_index, [ 
                'rates' => [ $rate_id => $fake_rate ] 
            ]);

            // --- FIX 2: Strict Index-Based Key Assignment ---
            // Package 0 MUST use 'primary_' keys, Package 1 MUST use 'samples_' keys.
            // This fixes "Samples Only" quotes (Index 0) failing because they were writing to 'samples_' keys.
            if ( $package_index === 1 ) {
                // Package 1 is ALWAYS Samples (in a mixed cart context)
                WC()->session->set('samples_delivery_method', $rate_label);
                if (strpos($rate_id, 'flat_rate:') !== false) {
                    WC()->session->set('samples_shipping_method', $rate_label);
                }
                WC()->session->set('samples_package_is_from_quote_order', true);
            } else {
                // Package 0 is ALWAYS Primary (Even if it contains samples only)
                WC()->session->set('primary_delivery_method', $rate_label);
                if (strpos($rate_id, 'flat_rate:') !== false) {
                    WC()->session->set('primary_shipping_method', $rate_label);
                }
                WC()->session->set('primary_package_is_from_quote_order', true);
            }

            $package_index++;
        }
    }
    if (!empty($chosen_methods)) {
        WC()->session->set('chosen_shipping_methods', $chosen_methods);
    }

    if ( isset( $_GET['quote'], $_GET['quote_id'] ) ) {
        // Sanitize and validate the input parameters
        $quote_from_url = absint( $_GET['quote'] ); // Ensure it's a positive integer
        $quote_id_from_url = sanitize_text_field( $_GET['quote_id'] ); // Sanitize the unique ID string
        // Retrieve the stored unique link ID from order metadata
        $unique_quote_link_id = $order->get_meta( 'quote_link_id' );
        $quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'freight-quote', 'deleted-quote', 'ordered-quote'];

        // Check if the stored unique ID matches the unique ID from the URL and if the order status is a quote status
        if ( ! empty( $unique_quote_link_id ) && $quote_id_from_url === $unique_quote_link_id && in_array( $order->get_status(), $quote_statuses, true ) ) {
            $is_a_quote_from_link = true;
        } else {
            $is_a_quote_from_link = false;
        }
    } else {
        $is_a_quote_from_link = false;
    }
    // Recalculate totals to apply changes
    if (!$is_a_quote_from_link) {
        //WC()->cart->calculate_totals(); // Maybe unnecessary since WooCommerce does this automatically
    }

    WC()->session->set('is_loading_quote', null);
}

// Set the impersonated user ID in the admin user's meta
function start_admin_impersonation( $admin_id, $customer_id ) {
    update_user_meta( $admin_id, '_impersonated_user_id', $customer_id );
}

// Remove the impersonated user ID from the admin user's meta
function stop_admin_impersonation( $admin_id ) {
    delete_user_meta( $admin_id, '_impersonated_user_id' );
}


// --- Orders Page Integration ---
/**
 * Adds and reliably positions the impersonation control next to the Orders page title on the admin Orders List page.
 */
function add_impersonation_control() {
    // We only need to run this on the WooCommerce HPOS Orders page.
    // This now checks that we are on the 'wc-orders' page AND that no action (like 'edit') is set.
    if ( ! isset( $_GET['page'] ) || 'wc-orders' !== $_GET['page'] || (isset( $_GET['action']) && ($_GET['action'] === 'edit' || $_GET['action'] === 'new') ) ) {
        return;
    }

    // --- Prepare the HTML and PHP variables ---
    $nonce = wp_create_nonce(LOGIN_AS_CUSTOMER_NONCE);
    $admin_users = get_users(array('role' => 'administrator'));
    $admin_user_ids = array_map(function($user) { return $user->ID; }, $admin_users);
    $current_user_id = get_current_user_id();
    if (!in_array($current_user_id, $admin_user_ids)) {
        $admin_user_ids[] = $current_user_id;
    }
    $exclude_ids = wp_json_encode($admin_user_ids);
    ?>

    <style type="text/css">
        #select2-impersonation-user-select-results .select2-results__message {
            padding-bottom: 11px !important;
        }
    </style>

    <div id="impersonation-container" style="display: none; position: relative;">
        <select class="wc-customer-search" id="impersonation-user-select" style="width: 240px;" data-placeholder="Select a Customer" data-action="woocommerce_json_search_customers" data-exclude="<?php echo esc_attr($exclude_ids); ?>"></select>
        <button type="button" id="impersonation-button" class="button wc-action-button button-primary" style="margin-left: 5px;">View Customer Account</button>
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            var impersonationContainer = $('#impersonation-container');
            var pageTitle = $('.wrap h1.wp-heading-inline');

            if (pageTitle.length && impersonationContainer.length) {
                // Move the container right after the page title heading
                impersonationContainer.insertAfter(pageTitle);

                // Reveal it with alignment styles
                impersonationContainer.css({
                    'display': 'inline-block',
                    'margin-left': '18px',
                    'vertical-align': 'middle',
                    'margin-bottom': '6px'
                });
            }

            // --- Custom Select2 Initializer with "Add Customer" Option ---
            var $selectElement = $('#impersonation-user-select');

            // Destructively unbind WooCommerce's default initialization if it fired early
            if ($selectElement.hasClass('select2-hidden-accessible')) {
                $selectElement.select2('destroy');
            }

            $selectElement.select2({
                allowClear: true,
                placeholder: $selectElement.data('placeholder'),
                minimumInputLength: 1,
                escapeMarkup: function(markup) { 
                    return markup; // Crucial: Allows our raw HTML button string to render safely
                },
                language: {
                    noResults: function() {
                        // Grab what the admin currently typed into the search input field
                        var typedTerm = jQuery('.select2-search__field').val() || '';
                        var baseUrl = '<?php echo esc_url(admin_url("user-new.php")); ?>';
                        
                        // Standard Regex pattern to validate a formal email structure
                        var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        var finalUrl = baseUrl;

                        // Only append the prefilled parameter if the admin has typed a valid email format
                        if (emailRegex.test(typedTerm.trim())) {
                            finalUrl += '?prefilled_email=' + encodeURIComponent(typedTerm.trim());
                        }
                        return '<span style="display: inline-block; vertical-align: middle;">No matches found</span><a href="' + finalUrl + '" class="button button-secondary button-small" style="margin-left:10px; padding: 0 8px; font-size:12px; height:24px; line-height:23px; vertical-align:middle;">Add Customer</a>';
                    }
                },
                ajax: {
                    url: '<?php echo admin_url("admin-ajax.php"); ?>',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            term: params.term,
                            action: $selectElement.data('action'),
                            security: '<?php echo wp_create_nonce("search-customers"); ?>',
                            exclude: $selectElement.data('exclude')
                        };
                    },
                    processResults: function(data) {
                        var terms = [];
                        if (data) {
                            $.each(data, function(id, text) {
                                terms.push({ id: id, text: text });
                            });
                        }
                        return { results: terms };
                    },
                    cache: true
                }
            });

            // The click handler for the view button.
            $('#impersonation-button').on('click', function() {
                var $button = $(this);
                var userId = $('#impersonation-user-select').val();
                if (userId) {
                    $button.css('opacity', '0.65').css('pointer-events', 'none').text('Logging in...');
                    window.location.href = '<?php echo admin_url("admin-ajax.php?action=login_as_customer&user_id="); ?>' + userId + '&_wpnonce=<?php echo $nonce; ?>' + '&destination=shop';
                }
            });
        });
    </script>
    <?php
}
// Using a later hook ('admin_footer') ensures all elements, including the title, are present.
add_action( 'admin_footer', 'add_impersonation_control' );

// Enqueue Select2 ONLY on the Orders page.
add_action('admin_enqueue_scripts', 'enqueue_impersonation_scripts');

function enqueue_impersonation_scripts($hook) {
    if ('edit.php' !== $hook || !isset($_GET['post_type']) || 'shop_order' !== $_GET['post_type']) {
        return; // Only on the Orders page.
    }
    wp_enqueue_style('select2');
    wp_enqueue_script('select2');
}

/**
 * Unifies the cart clearing process for both normal and impersonated checkouts.
 * This function should be the ONLY one handling cart clearing after an order.
 */
add_action( 'woocommerce_store_api_checkout_order_processed', 'unified_checkout_cart_cleanup', 10, 1 );
function unified_checkout_cart_cleanup( $order ) {
    if ( ! $order || ! function_exists('WC') || ! WC()->session || ! WC()->cart ) {
        return;
    }
	if ( $order->get_customer_id() === 0 ) {
		return;
	}

    // --- Scenario 1: Active Admin Impersonation Checkout ---
    if ( function_exists('impersonation_is_active') && impersonation_is_active() ) {
        
        $admin_id    = intval( $_COOKIE['original_admin_id'] );
        $customer_id = intval( $_COOKIE['impersonated_user_id'] );

        if ( $admin_id && $customer_id ) {
            // Step 1: Clear the persistent cart from the admin's user meta. THIS IS THE MISSING STEP.
            $persistent_carts = get_user_meta( $admin_id, '_admin_persistent_carts', true );
            if ( is_array( $persistent_carts ) ) {
                $cart_key = 'admin_' . $admin_id . '_user_' . $customer_id;
                if ( isset( $persistent_carts[ $cart_key ] ) ) {
                    unset( $persistent_carts[ $cart_key ] );
                    update_user_meta( $admin_id, '_admin_persistent_carts', $persistent_carts );
                }
            }
        }
    }

    // --- Universal Cleanup for BOTH Scenarios ---
    // This runs for normal customers, and runs AFTER the persistent cart is cleared for impersonations.
    WC()->session->set('edit_mode_order_quote_number', null);
    WC()->session->set('editing_original_order_id', null);
    WC()->session->set('cart_is_active_quote', null);
    WC()->session->set('cart_is_pending_quote', null);
    WC()->session->set('cart_is_freight_quote', null);
    WC()->session->set('cart_is_profiles_needed', null);
    WC()->session->set('primary_package_is_from_quote_order', null);
    WC()->session->set('samples_package_is_from_quote_order', null);
    WC()->cart->empty_cart( true );
    WC()->session->destroy_session();
    WC()->session->set_customer_session_cookie( true );
}

/**
 * Clears the edit mode cookies when the cart is emptied manually.
 */
function cleanup_edit_mode_on_cart_empty() {
    // Check for our temporary flag.
    /*if ( WC()->session && WC()->session->get('is_loading_quote') ) {
        // This is a programmatic quote load, not a manual empty.
        // Remove the flag and do nothing else.
        WC()->session->set('is_loading_quote', null);
        return;
    }*/

    // If the flag was not found, it's a manual empty. Clear the cookies.
    WC()->session->set('edit_mode_order_quote_number', null);
    WC()->session->set('editing_original_order_id', null);
    WC()->session->set('cart_is_active_quote', null);
    WC()->session->set('cart_is_pending_quote', null);
    WC()->session->set('cart_is_freight_quote', null);
    WC()->session->set('cart_is_profiles_needed', null);
    WC()->session->set('primary_package_is_from_quote_order', null);
    WC()->session->set('samples_package_is_from_quote_order', null);
}
add_action('woocommerce_cart_emptied', 'cleanup_edit_mode_on_cart_empty', 10, 0);

/**
 * NEW: Clears the edit mode cookies when the LAST item is manually removed from the cart.
 */
function cleanup_edit_mode_on_last_item_removed() {
    // This function runs after an item is removed.
    // We check if the cart is now empty.
    if ( WC()->cart && WC()->cart->is_empty() ) {
        // If the cart is empty, it means the last item was just removed.
        // Clear the edit mode state.
        WC()->session->set('edit_mode_order_quote_number', null);
        WC()->session->set('editing_original_order_id', null);
        WC()->session->set('cart_is_active_quote', null);
        WC()->session->set('cart_is_pending_quote', null);
        WC()->session->set('cart_is_freight_quote', null);
        WC()->session->set('cart_is_profiles_needed', null);
        WC()->session->set('primary_package_is_from_quote_order', null);
        WC()->session->set('samples_package_is_from_quote_order', null);
    }
}
add_action('woocommerce_cart_item_removed', 'cleanup_edit_mode_on_last_item_removed', 10, 0);

/**
 * Auto-populates the Username and Email fields on the Add New User screen
 * ONLY if a valid prefilled_email parameter is passed in the URL.
 */
function starke_prefill_new_user_fields() {
    // Only execute on the Add New User screen
    $screen = get_current_screen();
    if ( ! $screen || 'user' !== $screen->id || 'add' !== $screen->action ) {
        return;
    }

    // Check if our custom URL parameter exists
    if ( isset( $_GET['prefilled_email'] ) && ! empty( $_GET['prefilled_email'] ) ) {
        $prefilled_value = sanitize_text_field( wp_unslash( $_GET['prefilled_email'] ) );
        
        // Server-side safety validation: double-check that the passed value is a legitimate email address
        if ( is_email( $prefilled_value ) ) {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var prefilledVal = <?php echo wp_json_encode( $prefilled_value ); ?>;
                    
                    // Target the core WordPress Username and Email fields
                    var $userLogin = $('#user_login');
                    var $userEmail = $('#email');

                    if ( $userLogin.length ) {
                        $userLogin.val(prefilledVal);
                    }
                    if ( $userEmail.length ) {
                        $userEmail.val(prefilledVal);
                    }
                });
            </script>
            <?php
        }
    }
}
add_action( 'admin_footer', 'starke_prefill_new_user_fields' );