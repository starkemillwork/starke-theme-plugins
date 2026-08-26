<?php
/**
 * Plugin Name: Add Order Creator & Starke Number Meta
 * Description: Adds creator meta and a custom sequential 'Starke Order/Quote Number' (_starke_order_number) to WooCommerce orders upon creation (HPOS compatible). Includes support for admin impersonation and correctly incremented quote editing revision numbers.
 * Version: 1.7
 * Author: Your Name
 * Author URI: Your Website
 * Requires Plugins: woocommerce
 * WC requires at least: 3.0
 * WC tested up to: [Enter current WooCommerce Version, e.g., 8.9]
 */

defined( 'ABSPATH' ) || exit; // Exit if accessed directly

/**
 * Class Additional_Order_Quote_Meta_Creator
 *
 * Handles adding creator user ID/name and Starke Order/Quote Number meta to WooCommerce orders.
 */
class Additional_Order_Quote_Meta_Creator {

    /** Meta key for customer username. */
    const CUSTOMER_USERNAME_META_KEY = '_customer_user_name';
    /** Meta key for creator's user ID. */
    const CREATOR_META_KEY_ID = '_creator_user_id';
    /** Meta key for creator's display name (with prefix). */
    const CREATOR_META_KEY_NAME = '_creator_user_name';
    /** Meta key for the custom Starke Order/Quote Number (Display e.g., 45, 45-1). */
    const STARKE_NUMBER_META_KEY = '_starke_order_number';
    /** Meta key for the zero-padded sortable Starke Order/Quote Number String (e.g., 45-000000, 45-000001). */
    const STARKE_NUMBER_SORTABLE_META_KEY = '_starke_order_number_sortable';
    /** Number of digits for revision padding (e.g., 6 allows up to 999999 revisions. */
    const STARKE_REVISION_PADDING = 6;
    /** Option key to store the last used Starke number sequence. */
    const STARKE_NUMBER_OPTION_KEY = 'starke_order_quote_last_number';



    /** Constructor. Hooks into WooCommerce actions. */
    public function __construct() {
        // Hook for frontend order creation (checkout) - Runs BEFORE save
        //add_action( 'woocommerce_checkout_create_order', array( $this, 'add_creator_meta_frontend' ), 10, 2 );

        // Hook for backend order creation/update (admin screen) - Runs during save process
        add_action( 'woocommerce_process_shop_order_meta', array( $this, 'add_creator_meta_backend' ), 10, 1 );

        // Hook for NEW orders after initial save (catches wc_create_order, etc.) - Runs AFTER save
        add_action( 'woocommerce_new_order', array( $this, 'process_new_order_meta' ), 10, 1 );

        // Set Quote Link ID
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'set_quote_link_id' ), 10, 3 );
    }

    // --- Methods for Creator Meta (Largely unchanged - ensure they use correct constants if copy-pasting) ---
    /**
     * Adds creator meta data for orders created via the frontend checkout.
     * HOOK: woocommerce_checkout_create_order (runs before initial save)
     */
    public function add_creator_meta_frontend( $order, $data ) {
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) { $this->log_error( 'Invalid order object received in frontend hook.' ); return; }
        if ( $order->get_meta( self::CREATOR_META_KEY_ID, true ) !== '' ) { $this->log_info( sprintf( 'Order %s: Creator meta already exists in frontend hook. Skipping.', $order->get_id() ?: '(new)' ) ); return; }

        $creator_id = 0; $creator_name = 'Guest'; $log_source = '(Frontend Checkout)'; $name_prefix = '';
        if ( isset( $_COOKIE['original_admin_id'], $_COOKIE['impersonated_user_id'] ) ) {
            $creator_id = absint( $_COOKIE['original_admin_id'] ); $log_source = '(Frontend Checkout - Impersonated)'; $name_prefix = '(Admin) ';
            $this->log_info( sprintf( 'Impersonation session detected. Original Admin ID: %d, Impersonated User ID: %d', $creator_id, absint( $_COOKIE['impersonated_user_id'] ) ) );
        } else {
            $creator_id = get_current_user_id(); if ( $creator_id > 0 ) { $name_prefix = '(Customer) '; }
        }
        if ( $creator_id > 0 ) {
            $user_data = get_userdata( $creator_id );
            if ( $user_data ) { $creator_name = $user_data->display_name; }
            else { $creator_name = 'N/A'; $name_prefix = ''; $this->log_warning( sprintf( 'Order %s: Could not retrieve user data for creator ID %d (Frontend)', $order->get_id() ?: '(new)', $creator_id ) ); }
        }
        if ( ! empty( $name_prefix ) ) { if ( $creator_name !== 'Guest' && $creator_name !== 'N/A') { $creator_name = $name_prefix . $creator_name; } }

        $order->update_meta_data( self::CREATOR_META_KEY_ID, $creator_id );
        $order->update_meta_data( self::CREATOR_META_KEY_NAME, $creator_name );
        $this->log_info( sprintf( 'Order %s: Setting Creator Meta %s=%d, %s="%s" %s [Via Frontend Hook]', $order->get_id() ?: '(new)', self::CREATOR_META_KEY_ID, $creator_id, self::CREATOR_META_KEY_NAME, $creator_name, $log_source ) );

                // --- Add Customer Username Meta ---
        // Check if it already exists (though less likely in this hook)
        if ( $order->get_meta( self::CUSTOMER_USERNAME_META_KEY, true ) === '' ) {
            $customer_id = $order->get_customer_id(); // Get the actual customer ID for the order
            $customer_username_for_sorting = 'zz_guest'; // Default for guests (sorts last/first)

            if ($customer_id > 0) {
                $customer_user_data = get_userdata($customer_id);
                if ($customer_user_data) {
                    $customer_username_for_sorting = $customer_user_data->user_login;
                } else {
                    $this->log_warning( sprintf( 'Order %s: Could not retrieve user data for customer ID %d (Frontend)', $order->get_id() ?: '(new)', $customer_id ) );
                    // Keep 'zz_guest' or set to something else if needed
                }
            }
            // Save the username (or guest placeholder) to order meta
            $order->update_meta_data( self::CUSTOMER_USERNAME_META_KEY, $customer_username_for_sorting );
            $this->log_info( sprintf( 'Order %s: Setting Customer Username Meta %s="%s" [Via Frontend Hook]', $order->get_id() ?: '(new)', self::CUSTOMER_USERNAME_META_KEY, $customer_username_for_sorting ) );
        }
        // --- End Customer Username Meta ---
    }

    /**
     * Adds creator meta data for orders created via the WP admin backend.
     * HOOK: woocommerce_process_shop_order_meta (runs during save process)
     */
    public function add_creator_meta_backend( $order_id ) {
        if ( ! current_user_can( 'edit_shop_order', $order_id ) ) { return; }
        $order = wc_get_order( $order_id ); if ( ! $order ) { return; }
        if ( $order->get_meta( self::CREATOR_META_KEY_ID, true ) !== '' ) { $this->log_info( sprintf( 'Order %d: Creator meta already exists in backend hook. Skipping.', $order_id ) ); return; }
        if ( ! $order->meta_exists( self::CREATOR_META_KEY_ID ) ) {
            $creator_id = get_current_user_id(); $creator_name = 'N/A'; $name_prefix = '(Admin) ';
            if($creator_id > 0) {
                $user_data = get_userdata( $creator_id );
                if ( $user_data ) { $creator_name = $user_data->display_name; }
                else { $name_prefix = ''; $this->log_warning( sprintf( 'Order %d: Could not retrieve user data for creator ID %d (Backend)', $order_id, $creator_id ) ); }
            } else { $name_prefix = ''; $creator_name = 'System?'; $this->log_warning( sprintf( 'Order %d: Creator ID is 0 in backend context.', $order_id ) ); }
            if ( ! empty( $name_prefix ) ) { if ( $creator_name !== 'N/A' && $creator_name !== 'System?') { $creator_name = $name_prefix . $creator_name; } }

            $order->add_meta_data( self::CREATOR_META_KEY_ID, $creator_id, true );
            $order->add_meta_data( self::CREATOR_META_KEY_NAME, $creator_name, true );
            // $order->save(); // Rely on core save in this hook context
            $this->log_info( sprintf( 'Order %d: Setting Creator Meta %s=%d, %s="%s" (Backend - Initial Creation) [Via Backend Hook]', $order_id, self::CREATOR_META_KEY_ID, $creator_id, self::CREATOR_META_KEY_NAME, $creator_name ) );
        }

                    // --- Add/Update Customer Username Meta ---
        // Get current customer ID
        $customer_id = $order->get_customer_id(); // Use get_customer_id() for consistency
        $customer_username_for_sorting = 'zz_guest'; // Default for guests

        if ($customer_id > 0) {
            $customer_user_data = get_userdata($customer_id);
            if ($customer_user_data) {
                $customer_username_for_sorting = $customer_user_data->user_login;
            } else {
                $this->log_warning( sprintf( 'Order %d: Could not retrieve user data for customer ID %d (Backend)', $order_id, $customer_id ) );
            }
        }
        // Use update_meta_data to add if new or update if customer changed
        $order->update_meta_data( self::CUSTOMER_USERNAME_META_KEY, $customer_username_for_sorting );
        $this->log_info( sprintf( 'Order %d: Setting/Updating Customer Username Meta %s="%s" [Via Backend Hook]', $order_id, self::CUSTOMER_USERNAME_META_KEY, $customer_username_for_sorting ) );
        // No $order->save() needed here; meta is saved as part of the woocommerce_process_shop_order_meta process
        // --- End Customer Username Meta ---
    }

     /**
     * Adds creator meta AND Starke Order Number for orders created programmatically OR missed by other hooks.
     * HOOK: woocommerce_new_order (runs after initial save)
     */
    public function process_new_order_meta( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) { $this->log_error( sprintf( 'Order %d: Failed to retrieve order object in process_new_order_meta hook.', $order_id ) ); return; }

        // If the order belongs to a guest (customer ID is 0), do not process any of this meta.
        if ( $order->get_customer_id() === 0 ) {
            $this->log_info( sprintf( 'Order %d: Skipping meta processing because it is a guest order.', $order_id ) );
            return;
        }

        $meta_added = false;

        // --- Add Creator Meta (if not already added) ---
        if ( $order->get_meta( self::CREATOR_META_KEY_ID, true ) === '' ) {
            $creator_id   = 0; $creator_name = 'Guest'; $log_source = '(Programmatic)'; $name_prefix  = '';
            if ( isset( $_COOKIE['original_admin_id'], $_COOKIE['impersonated_user_id'] ) ) {
                $creator_id = absint( $_COOKIE['original_admin_id'] ); $log_source = '(Programmatic - Impersonated)'; $name_prefix = '(Admin) ';
                $this->log_info( sprintf( 'Order %d: Impersonation session detected programmatically.', $order_id ) );
            } elseif ( is_user_logged_in() ) {
                 $creator_id = get_current_user_id();
                 if( $creator_id > 0 ) {
                      $user_data = get_userdata( $creator_id );
                      if ($user_data && in_array('administrator', (array) $user_data->roles )) { $name_prefix = '(Admin) '; $log_source = '(Programmatic - Admin User)'; }
                      else { $name_prefix = '(Customer) '; $log_source = '(Programmatic - Customer User)'; }
                 }
            } else { $log_source = '(Programmatic - Guest)'; }

            if ( $creator_id > 0 ) {
                $user_data = get_userdata( $creator_id );
                if ( $user_data ) { $creator_name = $user_data->display_name; }
                else { $creator_name = 'N/A'; $name_prefix = ''; $this->log_warning( sprintf( 'Order %d: Could not retrieve user data for creator ID %d (Programmatic)', $order_id, $creator_id ) ); }
            }
            if ( ! empty( $name_prefix ) ) { if ( $creator_name !== 'Guest' && $creator_name !== 'N/A') { $creator_name = $name_prefix . $creator_name; } }

            $order->update_meta_data( self::CREATOR_META_KEY_ID, $creator_id );
            $order->update_meta_data( self::CREATOR_META_KEY_NAME, $creator_name );
            $meta_added = true;
            $this->log_info( sprintf( 'Order %d: Setting Creator Meta %s=%d, %s="%s" %s [Via New Order Hook]', $order_id, self::CREATOR_META_KEY_ID, $creator_id, self::CREATOR_META_KEY_NAME, $creator_name, $log_source ) );
        } else {
             $this->log_info( sprintf( 'Order %d: Creator meta already exists. Skipping add in New Order Hook.', $order_id ) );
        }

        // --- End Creator Meta ---

        // --- Add Customer Username Meta (if not already added) ---
        if ( $order->get_meta( self::CUSTOMER_USERNAME_META_KEY, true ) === '' ) {
            $customer_id = $order->get_customer_id(); // Get the actual customer ID
            $customer_username_for_sorting = 'zz_guest'; // Default for guests

            if ($customer_id > 0) {
                $customer_user_data = get_userdata($customer_id);
                if ($customer_user_data) {
                    $customer_username_for_sorting = $customer_user_data->user_login;
                } else {
                    $this->log_warning( sprintf( 'Order %d: Could not retrieve user data for customer ID %d (New Order Hook)', $order_id, $customer_id ) );
                }
            }
            $order->update_meta_data( self::CUSTOMER_USERNAME_META_KEY, $customer_username_for_sorting );
            $meta_added = true; // Mark that we added meta
            $this->log_info( sprintf( 'Order %d: Setting Customer Username Meta %s="%s" [Via New Order Hook]', $order_id, self::CUSTOMER_USERNAME_META_KEY, $customer_username_for_sorting ) );
        } else {
            $this->log_info( sprintf( 'Order %d: Customer Username meta already exists. Skipping add in New Order Hook.', $order_id ) );
        }
        // --- End Customer Username Meta ---
        
        // --- Generate and Add Starke Order/Quote Number ---
         // Ensure it hasn't been added already (e.g., if hook ran twice unexpectedly)
        if ( $order->get_meta( self::STARKE_NUMBER_META_KEY, true ) === '' ) {
            $starke_number = $this->generate_starke_number($order);
            if ($starke_number) {
                // Generate the sortable padded string ('45-000000', '45-000001')
                //$starke_number_sortable_string = $this->generate_sortable_starke_string($starke_number);
                $starke_number_sortable_number = $this->generate_sortable_starke_number($starke_number);
                
                $order->update_meta_data( self::STARKE_NUMBER_META_KEY, $starke_number );
                $order->update_meta_data( self::STARKE_NUMBER_SORTABLE_META_KEY, $starke_number_sortable_number );
                $meta_added = true; // Mark that we added meta
                //$this->log_info( sprintf( 'Order %d: Setting Starke Number %s="%s" [Via New Order Hook]', $order_id, self::STARKE_NUMBER_META_KEY, $starke_number ) );
                wc_get_logger()->log( 'debug', 'Order Failed to generate Starke Order Number: ' . print_r( [$order_id, self::STARKE_NUMBER_META_KEY, $starke_number], true ), array( 'source' => 'order_debug' ) );
            } else {
                 //$this->log_error( sprintf( 'Order %d: Failed to generate Starke Order Number.', $order_id ) );
                 wc_get_logger()->log( 'debug', 'Order Failed to generate Starke Order Number: ' . print_r( $order_id, true ), array( 'source' => 'order_debug' ) );
            }
        } else {
             //$this->log_info( sprintf( 'Order %d: Starke Number meta already exists. Skipping add in New Order Hook.', $order_id ) );
             wc_get_logger()->log( 'debug', 'Order Starke Number meta already exists. Skipping add in New Order Hook: ' . print_r( $order_id, true ), array( 'source' => 'order_debug' ) );
        }

        // Check if this new order was created from an existing quote.
        $original_quote_id = $order->get_meta('_editing_original_order_id', true);
        if ( ! empty($original_quote_id) ) {
            $original_quote = wc_get_order(absint($original_quote_id));
            
            // Check if the original quote exists and is a valid order object.
            if ($original_quote) {
                
                // Define the statuses that qualify as a "Quote" based on your quote.php file
                $valid_quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'freight-quote', 'deleted-quote'];
                
                // Only proceed if the original order's status is in our list of quote statuses
                if ( in_array( $original_quote->get_status(), $valid_quote_statuses ) ) {
                    
                    // Update the status of the original quote to 'ordered-quote'.
                    $original_quote->update_status('ordered-quote', 'The new order #' . $order->get_meta('_starke_order_number') . ' was created from this quote.'); 
                    
                    // Add a note to the new order referencing the original quote number.
                    $original_quote_starke_number = $original_quote->get_meta('_starke_order_number');
                    if ( ! empty($original_quote_starke_number) ) {
                        // Construct the note text for the NEW order.
                        $note_text = 'This order was created from Quote #Q' . $original_quote_starke_number . '.';
                        // This function adds the text to the "Order notes" panel for admin reference.
                        $order->add_order_note($note_text);
                        // This flag ensures the new order (and its note) is saved.
                        $meta_added = true;
                    }
                }
            }
        }

        // --- Save Order if Meta Was Added ---
        if ($meta_added) {
            $order->save();
             $this->log_info( sprintf( 'Order %d: Saving order with new meta data [Via New Order Hook]', $order_id ) );
        }
    }

    /**
     * Generates the next Starke Order/Quote number.
     * Handles sequential incrementing and revision numbers based on session and existing revisions.
     * Updates the persistent counter if a new sequential number is used.
     *
     * @return string|false The generated number or false on failure.
     */
    private function generate_starke_number($order) {
        $starke_number = false;

        // Prioritize the meta field set during block checkout. Fall back to the session for other flows (like saving a quote).
        $original_order_id = $order->get_meta('_editing_original_order_id', true);
        if (empty($original_order_id)) {
            $original_order_id = WC()->session ? WC()->session->get('editing_original_order_id') : null;
        }

        if ($original_order_id/* && strpos($order->get_status(), 'quote') !== false*/) {
            $original_order = wc_get_order(absint($original_order_id));

            //if ($original_order && $original_order->get_customer_id() == get_current_user_id()) {
                $base_number_str = $original_order->get_meta(self::STARKE_NUMBER_META_KEY, true);
                $this->log_info( sprintf( 'Starke Number: Edit mode detected from session. Base number from original order %d: "%s"', $original_order->get_id(), $base_number_str ) );

                $parts = explode( '-', $base_number_str );
                $base_num = absint($parts[0]); // Get the base number (e.g., 45 from "45" or "45-1")

                if ($base_num > 0) {
                    $highest_revision = 0;
                    // This ensures the search for the highest revision number includes trashed orders.
                    $statuses_to_search = array_keys( wc_get_order_statuses() );
                    $statuses_to_search[] = 'trash';
                    // Query orders with meta key _starke_order_number LIKE 'base_num-%'
                    $args = array(
                        'status'  => $statuses_to_search, // Search across all statuses, including trash.
                        'limit' => -1, // Get all matching orders
                        'return' => 'ids',
                        'meta_query' => array(
                            array(
                                'key'     => self::STARKE_NUMBER_META_KEY,
                                'value'   => $base_num . '-', // Find numbers starting with '45-'
                                'compare' => 'LIKE',
                            ),
                            /*// Add a check to only search for revisions of the same customer
                            array(
                                'key' => '_customer_user',
                                'value' => $original_order->get_customer_id(), // Use the customer ID from the verified original order
                                'compare' => '=',
                            )*/
                        ),
                    );
                    $existing_revisions = wc_get_orders( $args );

                    if (!empty($existing_revisions)) {
                        foreach ($existing_revisions as $order_id) {
                            $order = wc_get_order($order_id);
                            if ($order) {
                                $existing_starke_num = $order->get_meta(self::STARKE_NUMBER_META_KEY, true);
                                $rev_parts = explode('-', $existing_starke_num);
                                if (isset($rev_parts[1])) {
                                    $revision_num = absint($rev_parts[1]);
                                    if ($revision_num > $highest_revision) {
                                        $highest_revision = $revision_num;
                                    }
                                }
                            }
                        }
                    }
                    $this->log_info( sprintf( 'Starke Number: Found highest existing revision for base %d: %d', $base_num, $highest_revision ) );

                    // Calculate the next revision number
                    $next_revision = $highest_revision + 1;
                    $starke_number = $base_num . '-' . $next_revision;

                } else {
                    $this->log_error( sprintf( 'Starke Number: Could not parse base number from session value "%s"', $base_number_str ) );
                    $starke_number = $this->get_next_sequential_starke_number(); // Fallback
                }

            //} else {
            //    // SECURITY FAIL or invalid order: Log it and fall back to a new number.
            //    $this->log_warning( sprintf('Starke Number: SECURITY WARNING - Attempt to create revision failed. Session order ID %d does not exist or customer mismatch for user %d. Falling back to new number.', $original_order_id, get_current_user_id()));
            //    $starke_number = $this->get_next_sequential_starke_number();
            //}
            // Unset the session variable after using it
            //WC()->session->set('edit_mode_order_quote_number', null);
            //WC()->session->set('editing_original_order_id', null);
            //WC()->session->set('cart_is_active_quote', null);
            //WC()->session->set('cart_is_pending_quote', null);
            //WC()->session->set('cart_is_freight_quote', null);
            //WC()->session->set('cart_is_profiles_needed', null);
        } else {
            // Generate next sequential number
            $starke_number = $this->get_next_sequential_starke_number();
        }

        return $starke_number;
    }

    /**
     * Gets the next sequential Starke number and updates the counter.
     * @return string|false The next number or false on failure.
     */
    private function get_next_sequential_starke_number() {
        global $wpdb;
    
        $option_name = self::STARKE_NUMBER_OPTION_KEY;
        $option_table = $wpdb->options;
    
        // Start transaction
        $wpdb->query('START TRANSACTION');
    
        // Lock the row for update
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT option_value FROM {$option_table} WHERE option_name = %s FOR UPDATE",
                $option_name
            ),
            ARRAY_A
        );
    
        if ( $row ) {
            $last_number = absint( $row['option_value'] );
            $next_number = $last_number + 1;
    
            $updated = $wpdb->update(
                $option_table,
                array( 'option_value' => maybe_serialize( $next_number ) ),
                array( 'option_name' => $option_name )
            );
    
            if ( $updated !== false ) {
                $wpdb->query('COMMIT');
                $this->log_info( sprintf( 'Starke Number (LOCKED): Sequential number generated: %d.', $next_number ) );
                return (string) $next_number;
            }
        }
    
        // Something failed
        $wpdb->query('ROLLBACK');
        $this->log_error( sprintf( 'Starke Number (LOCKED): FAILED to update option "%s".', $option_name ) );
        return false;
    }
    
    

    /**
     * Generates the zero-padded sortable string value from the display Starke number.
     * Example: '45' -> '45-000000', '45-1' -> '45-000001', '45-10' -> '45-000010'
     *
     * @param string $starke_number The display number ('45', '45-1').
     * @return float The zero-padded sortable string. Returns empty string if input is invalid.
     */
    private function generate_sortable_starke_number( $starke_number ) {
        if ( empty( $starke_number ) ) {
            return 0.0; // Return 0 or a suitable default for empty
        }
    
        $parts = explode( '-', $starke_number );
        $base_num = absint( $parts[0] );
        $revision_num = isset( $parts[1] ) ? absint( $parts[1] ) : 0; // Default revision to 0
    
        if ( $base_num <= 0 ) {
            return 0.0; // Invalid base number
        }
    
        // Calculate the decimal part based on the revision number and padding
        // Ensure STARKE_REVISION_PADDING is defined and is the maximum expected digits for the revision
        $decimal_revision = $revision_num / pow( 10, self::STARKE_REVISION_PADDING );
    
        // Combine the base number and the decimal revision
        $sortable_number = (float) $base_num + $decimal_revision;
    
        return $sortable_number;
    }


    /**
     * Set a unique Quote Link ID when the order status changes to a quote status
     * or clear it when the status changes to a non-quote status.
     *
     * @param int $order_id The order ID.
     * @param string $old_status The old order status.
     * @param string $new_status The new order status.
     */
    public static function set_quote_link_id( $order_id, $old_status, $new_status ) {
        // Get the order object
        $order = wc_get_order( $order_id );

        // Ensure the order object is valid
        if ( ! $order ) {
            return;
        }

        // Define the meta key and quote statuses
        $meta_key = 'quote_link_id';
        $quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'freight-quote', 'deleted-quote', 'ordered-quote'];

        // --- Functionality to SET the ID when status becomes a quote status ---
        if ( in_array( $new_status, $quote_statuses, true ) ) {
            // Check if the meta key already exists to avoid overwriting
            if ( ! $order->get_meta( $meta_key ) ) {
                // Generate a unique random string (UUID v4)
                // Using WordPress's UUID generator for uniqueness
                $unique_id = wp_generate_uuid4();

                // Set the order metadata
                $order->update_meta_data( $meta_key, $unique_id );

                // Save the order to persist the meta data
                $order->save();
            }
        }
        // --- Functionality to CLEAR the ID when status is no longer a quote ---
        else {
            // Check if the meta key exists before trying to delete
            if ( $order->get_meta( $meta_key ) ) {
                // Delete the order metadata
                $order->delete_meta_data( $meta_key );

                // Save the order to persist the meta data deletion
                $order->save();
            }
        }
    }


    // --- Logging Helper Functions --- (Keep these)
    private function log_info( $message ) { if ( function_exists( 'wc_get_logger' ) ) { wc_get_logger()->info( $message, array( 'source' => 'additional-order-quote-meta' ) ); } }
    private function log_warning( $message ) { if ( function_exists( 'wc_get_logger' ) ) { wc_get_logger()->warning( $message, array( 'source' => 'additional-order-quote-meta' ) ); } }
    private function log_error( $message ) { if ( function_exists( 'wc_get_logger' ) ) { wc_get_logger()->error( $message, array( 'source' => 'additional-order-quote-meta' ) ); } }
}

// Instantiate the class to hook everything up.
new Additional_Order_Quote_Meta_Creator();