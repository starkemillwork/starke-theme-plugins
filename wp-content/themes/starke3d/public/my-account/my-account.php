<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Customize My Account Menu
 * 1. Remove Downloads and Payment Methods.
 * 2. Rename 'Account details' and 'Log out'.
 * 3. REORDER: Move 'Quotes' before 'Orders'.
 */
function starke_customize_my_account_menu_items( $items ) {
    // --- 1. REMOVE ITEMS ---
    if ( isset( $items['downloads'] ) ) {
        unset( $items['downloads'] );
    }

    if ( isset( $items['payment-methods'] ) ) {
        unset( $items['payment-methods'] );
    }

    // --- 2. RENAME ITEMS (Capitalization) ---
    if ( isset( $items['edit-account'] ) ) {
        $items['edit-account'] = 'Account Details';
    }

    if ( isset( $items['customer-logout'] ) ) {
        $items['customer-logout'] = 'Log Out';
    }

    // --- 3. REORDER: Force 'Quotes' to appear before 'Orders' ---
    // Check if both exist to avoid errors
    if ( isset( $items['orders'] ) && isset( $items['quotes'] ) ) {
        $reordered_items = array();

        foreach ( $items as $key => $label ) {
            // When we hit 'orders', insert 'quotes' first, then 'orders'
            if ( 'orders' === $key ) {
                $reordered_items['quotes'] = $items['quotes'];
                $reordered_items['orders'] = $items['orders'];
            } 
            // When we hit 'quotes' (which usually comes later), skip it since we moved it up
            elseif ( 'quotes' === $key ) {
                continue;
            } 
            // Keep all other tabs in their original position
            else {
                $reordered_items[ $key ] = $label;
            }
        }

        return $reordered_items;
    }

    return $items;
}
// Priority 20 ensures this runs AFTER your quotes plugin adds its link
add_filter( 'woocommerce_account_menu_items', 'starke_customize_my_account_menu_items', 20 );

/**
 * Force the Page Title to always stay "My Account" 
 * even when viewing sub-pages like Orders, Addresses, etc.
 */
add_filter( 'the_title', 'starke_force_static_account_title', 10, 2 );

function starke_force_static_account_title( $title, $id = null ) {
    // Check if we are on the account page, in the main loop, and viewing an endpoint (like orders)
    if ( is_account_page() && is_main_query() && in_the_loop() && is_wc_endpoint_url() ) {
        // Return the static title you want
        return 'My Account';
    }
    return $title;
}

/**
 * 1. Add "PO Number / Job Name" column to My Account Orders list.
 * Inserts the column immediately after 'order-number'.
 */
function starke_add_po_number_column_to_my_account( $columns ) {
    $new_columns = array();

    foreach ( $columns as $key => $name ) {
        // Add the current column
        $new_columns[ $key ] = $name;

        // If this is the 'order-number' column, insert our new column right after it
        if ( 'order-number' === $key ) {
            $new_columns['po-number'] = __( 'PO Number / Job Name', 'woocommerce' );
        }
    }

    return $new_columns;
}
add_filter( 'woocommerce_my_account_my_orders_columns', 'starke_add_po_number_column_to_my_account' );

/**
 * DISPLAY: Custom Content for "PO Number / Job Name" Column
 * - Standard Orders: Shows PO/Job Name.
 * - Balance Invoices: 
 * - Completed: Normal Text.
 * - Pending/On-Hold: "Gold Pill" style matching .starke-custom-label.
 */
function starke_display_po_number_column_content( $order ) {
    
    // 1. Get the stored value
    $po_text = $order->get_meta( '_po_number_job_name', true );

    
        // 3. Standard Order Display
        if ( ! empty( $po_text ) ) {
            echo esc_html( $po_text );
        } else {
            echo '<span style="color: #999;">&mdash;</span>';
        }
    
}
add_action( 'woocommerce_my_account_my_orders_column_po-number', 'starke_display_po_number_column_content' );

/**
 * Rename "Order" column to "Quote" only on the Quotes page.
 */
function starke_rename_order_column_to_quote( $columns ) {
    // Check if we are specifically on the 'quotes' endpoint
    if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'quotes' ) ) {
        
        // If the 'order-number' column exists, change its label
        if ( isset( $columns['order-number'] ) ) {
            $columns['order-number'] = __( 'Quote', 'woocommerce' );
        }
    }

    return $columns;
}
// Priority 20 ensures this runs after default columns are set
add_filter( 'woocommerce_my_account_my_orders_columns', 'starke_rename_order_column_to_quote', 20 );

/**
 * DISPLAY: Custom "Order Number" Column Content for My Account
 * * Instead of filtering the order number globally (which breaks Admin views),
 * we manually retrieve and format the Starke Number only for this specific 
 * column on the My Account > Orders/Quotes pages.
 */
add_action( 'woocommerce_my_account_my_orders_column_order-number', 'starke_display_my_account_order_number' );
function starke_display_my_account_order_number( $order ) {
    // 1. Try to get the Starke Custom Number
    $starke_number = $order->get_meta( '_starke_order_number' );

    // 2. If Starke Number exists, use it with the correct prefix
    if ( ! empty( $starke_number ) ) {
        
        // Define Quote Statuses
        $quote_statuses = array( 
            'active-quote', 'expired-quote', 'pending-quote', 
            'deleted-quote', 'freight-quote', 'ordered-quote'
        );

        // Check status (remove 'wc-' prefix if present)
        $status = str_replace( 'wc-', '', $order->get_status() );

        if ( in_array( $status, $quote_statuses ) ) {
            // Quote Format: Q45 (Bold)
            echo '<strong>' . esc_html( '#Q' . $starke_number ) . '</strong>';
        } else {
            // Order Format: #45 (Bold)
            echo '<strong>' . esc_html( '#' . $starke_number ) . '</strong>';
        }

    } else {
        // 3. Fallback: Check for Legacy ID first, otherwise standard Database ID
        $legacy_id = $order->get_meta( '_legacy_order_id', true );
        $final_id = ! empty( $legacy_id ) ? $legacy_id : $order->get_id();
        echo '<strong>' . esc_html( '#' . $final_id ) . '</strong>';
    }
}

/**
 * 1. Add "Profiles" column BEFORE "Actions".
 * We rebuild the array to force the correct position.
 */
function starke_add_profiles_column_to_my_account( $columns ) {
    $new_columns = array();

    foreach ( $columns as $key => $name ) {
        // When we hit the 'order-actions' column, insert 'Profiles' first
        if ( 'order-actions' === $key ) {
            $new_columns['order-profiles'] = __( 'Profiles', 'woocommerce' );
        }

        // Then add the current column (including 'order-actions')
        $new_columns[ $key ] = $name;
    }
    
    // Safety check: If 'order-actions' wasn't found (rare theme cases),
    // ensure 'order-profiles' is still added at the end.
    if ( ! isset( $new_columns['order-profiles'] ) ) {
        $new_columns['order-profiles'] = __( 'Profiles', 'woocommerce' );
    }

    return $new_columns;
}
// Priority doesn't matter as much now, but 20 is safe.
add_filter( 'woocommerce_my_account_my_orders_columns', 'starke_add_profiles_column_to_my_account', 20 );

/**
 * 2. Display the Images and Numbers for the "Profiles" column.
 * - Fixes Sample Names (shows full name)
 * - Excludes "Charge" products
 * - NEW: Wraps the item in a link to the product page.
 */
function starke_display_profiles_column_content( $order ) {
    // Create a container for the grid of images
    echo '<div class="starke-order-profiles-grid">';

    // Loop through every item in the order
    foreach ( $order->get_items() as $item_id => $item ) {
        $product = $item->get_product();
        
        // Skip if product is missing (deleted)
        if ( ! $product ) {
            continue;
        }

        // --- STEP 1: Determine the Display Name ---
        $official_number = $item->get_meta( 'official_profile_number', true );
        $custom_name     = $item->get_meta( 'custom_name', true );
        
        // When profiles are swapped, the OLD custom name gets saved here,
        // and the item's MAIN name becomes the Official Number.
        $old_custom_name = $item->get_meta( 'custom_profile_number', true ); 

        if ( $order->get_status() === 'profiles-ready' && ! empty( $old_custom_name ) ) {
            // It is a swapped profile! 
            // The item name is now the Official Number, and we append the old custom name.
            $display_name = $item->get_name() . ' (' . $old_custom_name . ')';
        } else {
            // Default logic for other statuses or un-swapped items
            if ( ! empty( $official_number ) ) {
                $display_name = $official_number;
            } elseif ( ! empty( $custom_name ) ) {
                $display_name = $custom_name;
            } else {
                $display_name = $item->get_name();
            }
        }

        // --- STEP 2: Filter out "Charge" Products ---
        if ( stripos( $display_name, 'Charge' ) !== false ) {
            continue;
        }

        // --- STEP 3: Get the Image & Link ---
        $image = $product->get_image( 'thumbnail', array( 'class' => 'starke-profile-thumb' ) );
        $product_link = $product->get_permalink();

        // --- CHECK: Disable Link for Custom Profiles (unless Impersonating) ---
        $is_custom = false;
        // Check against global helper if available, otherwise manual check
        if ( function_exists('get_hidden_product_skus') ) {
            if ( in_array( $product->get_sku(), get_hidden_product_skus() ) ) $is_custom = true;
        } else {
            $custom_skus = ['XBASEBOARD', 'XCASING', 'XCROWN', 'XMISCELLANEOUS'];
            if ( in_array( $product->get_sku(), $custom_skus ) ) $is_custom = true;
        }

        // Only allow clicking if it's NOT a custom profile, OR if we are impersonating
        $allow_click = !$is_custom || ( function_exists('impersonation_is_active') && impersonation_is_active() );

        // --- STEP 4: Output the Item ---
        // 1. Always use <a> to keep the layout grid identical.
        $href_attr = $allow_click ? ' href="' . esc_url( $product_link ) . '"' : '';
        
        // 2. NEW: If not allowed, "pointer-events: none" kills ALL hover effects and the hand cursor.
        $style_attr = $allow_click ? '' : ' style="pointer-events: none; cursor: default; opacity: 1;"';

        echo '<a' . $href_attr . $style_attr . ' class="starke-profile-item">';
        echo $image; 
        echo '<span class="starke-profile-name">' . esc_html( $display_name ) . '</span>';
        echo '</a>';
    }

    echo '</div>';
}
add_action( 'woocommerce_my_account_my_orders_column_order-profiles', 'starke_display_profiles_column_content' );

/**
 * Customize the "Total" column to exclude Charge items from the item count.
 * This overrides the default output for the 'order-total' column.
 */
function starke_custom_order_total_column_content( $order ) {
    $count = 0;

    // Loop through all line items in the order
    foreach ( $order->get_items() as $item ) {
        // Skip if product data is corrupted/missing
        if ( ! $item->get_product() ) {
            continue;
        }

        $product_name = $item->get_name();

        // --- FILTER LOGIC ---
        // If the product name contains "Charge" (e.g. Setup Charge, Tooling Charge),
        // we skip it so it doesn't add to the total count.
        if ( stripos( $product_name, 'Charge' ) !== false ) {
            continue;
        }

        // Calculate net quantity (Original Qty - Refunded Qty)
        // Refunds return a negative number, so we add them.
        $qty = $item->get_quantity();
        $refunded = $order->get_qty_refunded_for_item( $item->get_id() );
        $net_qty = $qty + $refunded;

        // Add to running total (Counts rows for legacy orders, quantities for new orders)
        if ( $net_qty > 0 ) {
            if ( ! empty( $order->get_meta( '_legacy_order_id', true ) ) ) {
                $count++;
            } else {
                $count += $net_qty;
            }
        }
    }

    // Output the formatted string (e.g. "$120,955.65 for 15 items")
    // usage of _n() handles singular "item" vs plural "items" automatically.
    printf( 
        _n( '%1$s for %2$s item', '%1$s for %2$s items', $count, 'woocommerce' ), 
        $order->get_formatted_order_total(), 
        $count 
    );
}
// Hooking here forces WooCommerce to use our function instead of the default template logic
add_action( 'woocommerce_my_account_my_orders_column_order-total', 'starke_custom_order_total_column_content' );

/**
 * Generates custom Starke pagination HTML.
 * Independent of any plugin.
 * UPDATED: Forces 'quotes' to use ?paged= format to ensure pagination works.
 */
function starke_render_custom_pagination( $current_page, $total_pages, $endpoint ) {
    // 1. Sanity Check: Don't show if there's only 1 page
    if ( $total_pages <= 1 ) {
        return;
    }

    // 2. Calculate Base URL for the links
    $base_url = wc_get_endpoint_url( $endpoint );
    
    // --- NEW LOGIC START ---
    // If we are on the 'quotes' endpoint, FORCE query string format (?paged=2).
    // This guarantees your quotes.php template can read the page number correctly.
    if ( 'quotes' === $endpoint ) {
        $format  = '?paged=%#%';
        $base    = $base_url . '%_%'; // This tells WP where to inject the query string
    } 
    // Otherwise, use standard WordPress logic (for Orders, etc.)
    elseif ( get_option( 'permalink_structure' ) ) {
        $format  = '%#%';
        $base    = user_trailingslashit( $base_url ) . $format;
    } else {
        $format  = '?paged=%#%';
        $base    = $base_url . '%_%';
    }
    // --- NEW LOGIC END ---

    // 3. Get the raw links array from WordPress
    $links = paginate_links( array(
        'base'      => $base,
        'format'    => $format,
        'current'   => max( 1, $current_page ),
        'total'     => $total_pages,
        'type'      => 'array',
        'prev_text' => '« Previous',
        'next_text' => 'Next »',
        'end_size'  => 3,
        'mid_size'  => 3,
    ) );

    // 4. Output the Custom HTML
    if ( ! empty( $links ) ) {
        echo '<div class="starke-pagination-container">';
        echo '<nav class="starke-pagination-nav" aria-label="Page navigation">';
        echo '<ul class="starke-pagination-list">';

        foreach ( $links as $link ) {
            $li_class = 'starke-page-item';

            // Check if this is the "Active" page
            if ( strpos( $link, 'current' ) !== false ) {
                $number = strip_tags( $link );
                // Render active item
                echo '<li class="' . esc_attr( $li_class ) . ' active">';
                echo '<span class="starke-page-link current">' . $number . '</span>';
                echo '</li>';
            } else {
                // Check for Prev/Next to add helper classes
                if ( strpos( $link, 'prev' ) !== false ) {
                    $li_class .= ' starke-page-prev';
                } elseif ( strpos( $link, 'next' ) !== false ) {
                    $li_class .= ' starke-page-next';
                }

                // Clean the WP link and wrap in our class
                $link = str_replace( 'class="page-numbers"', 'class="starke-page-link"', $link );
                
                // Safety: If WP didn't add a class, strictly wrap it
                if ( strpos( $link, 'class=' ) === false ) {
                    $link = str_replace( '<a href', '<a class="starke-page-link" href', $link );
                }

                echo '<li class="' . esc_attr( $li_class ) . '">' . $link . '</li>';
            }
        }

        echo '</ul>';
        echo '</nav>';
        echo '</div>';
    }
}

/**
 * -----------------------------------------------------------------------------
 * STARKE ADDRESS BOOK - FLOATING LABEL EDITION
 * -----------------------------------------------------------------------------
 */

/**
 * 1. Swap the default Address Endpoint Handler.
 */
add_action( 'init', 'starke_swap_address_endpoint_handler' );

function starke_swap_address_endpoint_handler() {
    remove_action( 'woocommerce_account_edit-address_endpoint', 'woocommerce_account_edit_address' );
    add_action( 'woocommerce_account_edit-address_endpoint', 'starke_custom_address_endpoint_handler' );
}

function starke_custom_address_endpoint_handler( $type ) {
    $type = get_query_var( 'edit-address' );
    if ( empty( $type ) ) {
        starke_render_full_address_book();
    } else {
        woocommerce_account_edit_address( $type );
    }
}

/**
 * Helper function to format US phone numbers (XXX-XXX-XXXX)
 */
function starke_format_us_phone( $phone ) {
    if ( empty( $phone ) ) return '';
    
    // Strip all non-numeric characters
    $raw = preg_replace( '/[^0-9]/', '', $phone );
    
    // If it's a standard 10-digit number, format it
    if ( strlen( $raw ) === 10 ) {
        return preg_replace( '/(\d{3})(\d{3})(\d{4})/', '$1-$2-$3', $raw );
    }
    
    // Return original if it's an unusual length (like international)
    return $phone;
}

/**
 * 2. Render the Starke Address Book
 */
function starke_render_full_address_book() {
    $user_id = get_current_user_id();

    // --- DATA PREPARATION (Billing, Default Shipping, Saved) ---
    
    $billing_data = [
        'first_name' => get_user_meta( $user_id, 'billing_first_name', true ),
        'last_name'  => get_user_meta( $user_id, 'billing_last_name', true ),
        'company'    => get_user_meta( $user_id, 'billing_company', true ),
        'address_1'  => get_user_meta( $user_id, 'billing_address_1', true ),
        'address_2'  => get_user_meta( $user_id, 'billing_address_2', true ),
        'city'       => get_user_meta( $user_id, 'billing_city', true ),
        'state'      => get_user_meta( $user_id, 'billing_state', true ),
        'postcode'   => get_user_meta( $user_id, 'billing_postcode', true ),
        'phone'      => starke_format_us_phone( get_user_meta( $user_id, 'billing_phone', true ) ),
        'email'      => get_user_meta( $user_id, 'billing_email', true ),
        'country'    => 'US'
    ];

    $default_shipping_data = [
        'first_name' => get_user_meta( $user_id, 'shipping_first_name', true ),
        'last_name'  => get_user_meta( $user_id, 'shipping_last_name', true ),
        'company'    => get_user_meta( $user_id, 'shipping_company', true ),
        'address_1'  => get_user_meta( $user_id, 'shipping_address_1', true ),
        'address_2'  => get_user_meta( $user_id, 'shipping_address_2', true ),
        'city'       => get_user_meta( $user_id, 'shipping_city', true ),
        'state'      => get_user_meta( $user_id, 'shipping_state', true ),
        'postcode'   => get_user_meta( $user_id, 'shipping_postcode', true ),
        'phone'      => starke_format_us_phone( get_user_meta( $user_id, 'shipping_phone', true ) ),
        'country'    => 'US'
    ];

    $saved_addresses = get_user_meta( $user_id, 'saved_shipping_addresses', true );
    if ( ! is_array( $saved_addresses ) ) {
        $saved_addresses = [];
    } else {
        // --- Format existing saved addresses ---
        foreach ( $saved_addresses as &$addr ) {
            if ( ! empty( $addr['phone'] ) ) {
                $addr['phone'] = starke_format_us_phone( $addr['phone'] );
            }
        }
        unset($addr); // break reference
    }
    
    // --- RENDER LAYOUT ---
    ?>
    <div class="starke-address-book-wrapper">
        
        <div class="starke-address-header">
            <h3>Billing Address</h3>
        </div>
        
        <div class="starke-dashboard-grid address-grid" style="margin-bottom: 40px;">
            <div class="starke-dash-card address-card">
                <div class="address-badges">
                    <span class="starke-custom-label" style="background-color:#eee; color:#333;">Default Billing Address</span>
                </div>
                <div class="address-content">
                    <strong><?php echo esc_html( $billing_data['first_name'] . ' ' . $billing_data['last_name'] ); ?></strong><br>
                    <?php if(!empty($billing_data['company'])) echo esc_html( $billing_data['company'] ) . '<br>'; ?>
                    <?php echo esc_html( $billing_data['address_1'] ); ?><br>
                    <?php if(!empty($billing_data['address_2'])) echo esc_html( $billing_data['address_2'] ) . '<br>'; ?>
                    <?php echo esc_html( $billing_data['city'] . ', ' . $billing_data['state'] . ' ' . $billing_data['postcode'] ); ?><br>
                    <?php if(!empty($billing_data['phone'])) echo esc_html( $billing_data['phone'] ); ?><br>
                    <?php if(!empty($billing_data['email'])) echo esc_html( $billing_data['email'] ); ?>
                </div>
                <div class="address-actions">
                    <button class="starke-text-link" onclick='openStarkeAddressModal("billing", null, <?php echo json_encode($billing_data); ?>)'>Edit Address</button>
                </div>
            </div>
        </div>

        <div class="starke-address-header">
            <h3>Saved Shipping Addresses</h3>
            <?php if ( count( $saved_addresses ) < 24 ) : ?>
                <button class="starke-sample-btn request-mode" id="btn-add-new-address" onclick="openStarkeAddressModal('shipping_saved', null, null)">Add New</button>
            <?php endif; ?>
        </div>

        <div class="starke-dashboard-grid address-grid">
            
            <div class="starke-dash-card address-card default-card">
                <div class="address-badges">
                    <span class="starke-custom-label">Default Shipping Address</span>
                </div>
                <div class="address-content">
                    <strong><?php echo esc_html( $default_shipping_data['first_name'] . ' ' . $default_shipping_data['last_name'] ); ?></strong><br>
                    <?php if(!empty($default_shipping_data['company'])) echo esc_html( $default_shipping_data['company'] ) . '<br>'; ?>
                    <?php echo esc_html( $default_shipping_data['address_1'] ); ?><br>
                    <?php if(!empty($default_shipping_data['address_2'])) echo esc_html( $default_shipping_data['address_2'] ) . '<br>'; ?>
                    <?php echo esc_html( $default_shipping_data['city'] . ', ' . $default_shipping_data['state'] . ' ' . $default_shipping_data['postcode'] ); ?><br>
                    <?php if(!empty($default_shipping_data['phone'])) echo esc_html( $default_shipping_data['phone'] ); ?>
                </div>
                <div class="address-actions">
                    <button class="starke-text-link" onclick='openStarkeAddressModal("shipping_default", null, <?php echo json_encode($default_shipping_data); ?>)'>Edit Address</button>
                </div>
            </div>

            <?php foreach ( $saved_addresses as $index => $addr ) : 
                $addr_json = json_encode( $addr );
            ?>
                <div class="starke-dash-card address-card saved-card">
                    <div class="address-content">
                        <strong><?php echo esc_html( $addr['first_name'] . ' ' . $addr['last_name'] ); ?></strong><br>
                        <?php if(!empty($addr['company'])) echo esc_html( $addr['company'] ) . '<br>'; ?>
                        <?php echo esc_html( $addr['address_1'] ); ?><br>
                        <?php if(!empty($addr['address_2'])) echo esc_html( $addr['address_2'] ) . '<br>'; ?>
                        <?php echo esc_html( $addr['city'] . ', ' . $addr['state'] . ' ' . $addr['postcode'] ); ?><br>
                        <?php if(!empty($addr['phone'])) echo esc_html( $addr['phone'] ); ?>
                    </div>
                    <div class="address-actions">
                        <button class="starke-text-link" onclick='openStarkeAddressModal("shipping_saved", <?php echo $index; ?>, <?php echo $addr_json; ?>)'>Edit</button>
                        <span class="sep">|</span>
                        <button class="starke-text-link" onclick="starkeMakeDefault(<?php echo $index; ?>)">Make Default</button>
                        <span class="sep">|</span>
                        <button class="starke-text-link delete-link" onclick="starkeDeleteAddress(<?php echo $index; ?>)">Delete</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div id="starke-address-modal" class="starke-lightbox">
        <div class="starke-lightbox-content" style="max-width: 600px; width:95%; background: #fff; padding: 40px; border-radius: 8px; position:relative;">
            <span class="starke-lightbox-close" onclick="closeStarkeAddressModal()" style="color:#333; top: 15px; right: 25px; font-size: 2rem;">&times;</span>
            
            <h3 id="starke-modal-title" style="margin-top:0; margin-bottom: 25px; font-family:var(--wp--preset--font-family--inter);">Edit Address</h3>
            
            <form id="starke-address-form">
                <input type="hidden" id="starke_addr_context" name="context" value="">
                <input type="hidden" id="starke_addr_index" name="index" value="">

                <div class="starke-form-row">
                    <div class="field-half starke-float-wrapper">
                        <input type="text" id="sf_first_name" name="first_name" placeholder=" " required class="starke-input">
                        <label for="sf_first_name">First Name</label>
                    </div>
                    <div class="field-half starke-float-wrapper">
                        <input type="text" id="sf_last_name" name="last_name" placeholder=" " required class="starke-input">
                        <label for="sf_last_name">Last Name</label>
                    </div>
                </div>

                <div class="starke-form-row">
                    <div class="field-full starke-float-wrapper">
                        <input type="text" id="sf_company" name="company" placeholder=" " class="starke-input">
                        <label for="sf_company">Company (Optional)</label>
                    </div>
                </div>

                <div class="starke-form-row">
                    <div class="field-full starke-float-wrapper">
                        <input type="text" id="sf_address_1" name="address_1" placeholder=" " required class="starke-input" autocomplete="starke-custom-address">
                        <label for="sf_address_1">Address Line 1</label>
                    </div>
                </div>

                <div class="starke-form-row">
                    <div class="field-full starke-float-wrapper">
                        <input type="text" id="sf_address_2" name="address_2" placeholder=" " class="starke-input">
                        <label for="sf_address_2">Address Line 2 (Optional)</label>
                    </div>
                </div>

                <div class="starke-form-row">
                    <div class="field-half starke-float-wrapper">
                        <input type="text" id="sf_city" name="city" placeholder=" " required class="starke-input">
                        <label for="sf_city">City</label>
                    </div>
                    <div class="field-half starke-float-wrapper">
                        <select id="sf_state" name="state" required class="starke-input">
                            <option value="" disabled selected></option> <?php foreach( WC()->countries->get_states( 'US' ) as $code => $name ) echo "<option value='$code'>$name</option>"; ?>
                        </select>
                        <label for="sf_state">State</label>
                    </div>
                </div>

                <div class="starke-form-row">
                    <div class="field-half starke-float-wrapper">
                        <input type="text" id="sf_postcode" name="postcode" placeholder=" " required class="starke-input">
                        <label for="sf_postcode">Postcode</label>
                    </div>
                    <div class="field-half starke-float-wrapper">
                        <input type="text" id="sf_phone" name="phone" placeholder=" " required class="starke-input">
                        <label for="sf_phone">Phone</label>
                    </div>
                </div>

                <div class="starke-form-row" id="starke-email-row" style="display:none;">
                    <div class="field-full starke-float-wrapper">
                        <input type="email" id="sf_email" name="email" placeholder=" " class="starke-input">
                        <label for="sf_email">Email Address</label>
                    </div>
                </div>
                
                <div style="margin-top:30px; text-align:right; display:flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="starke-sample-btn" style="background:#eee; color:#333; border:1px solid #ddd; box-shadow:none;" onclick="closeStarkeAddressModal()">Cancel</button>
                    <button type="submit" class="starke-sample-btn request-mode">Save Address</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        /* --- LAYOUT UTILS --- */
        .starke-form-row { display: flex; gap: 20px; margin-bottom: 20px; }
        .field-half { flex: 1; }
        .field-full { width: 100%; }
        @media (max-width: 600px) {
            .starke-form-row { flex-direction: column; gap: 20px; }
        }

        /* --- Address Book Pill Labels (Gold) --- */
        .starke-custom-label {
            /* Gold background to match your theme */
            background-color: #fab83e !important; 
            color: #000000 !important; /* Black Text */
            
            /* Pill Shape */
            border-radius: 50px !important; 
            padding: 6px 14px !important; 
            
            /* Typography */
            font-family: "Inter", sans-serif !important;
            font-size: 0.75rem !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.05em !important;
            display: inline-block !important;
            line-height: 1 !important;
            
            /* Optional: Subtle shadow to make it pop */
            box-shadow: 0 1px 2px rgba(0,0,0,0.1) !important;
        }

        /* --- Action Buttons (Ghost Style - Uniform) --- */

        /* 1. Container Layout */
        .address-actions {
            display: flex !important;
            gap: 10px !important; /* Space between buttons */
            flex-wrap: nowrap !important; /* FIX: Force single row */
            border-top: 1px solid #eee;
            padding-top: 15px;
            margin-top: auto; /* Pushes to bottom of card */
        }

        /* 2. Hide the "|" separators */
        .address-actions .sep {
            display: none !important;
        }

        /* 3. The Unified Button Style (Purple Text) */
        .starke-text-link {
            display: inline-block !important;
            background: transparent !important;
            
            /* Structure: Subtle Gray Border */
            border: 1px solid #cccccc !important; 
            
            /* Action: Brand Purple Text */
            color: var(--wp--preset--color--primary, #6431F6) !important; 
            
            border-radius: 4px !important;
            padding: 8px 14px !important; 
            
            /* Typography */
            font-family: "Inter", sans-serif !important;
            font-size: 0.75rem !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            text-decoration: none !important;
            transition: all 0.2s ease !important;
            cursor: pointer !important;
            line-height: 1 !important;
            white-space: nowrap !important; /* FIX: Prevent button text from stacking */
        }

        /* 4. The "Starke Purple" Hover Effect (For ALL Buttons) */
        .starke-text-link:hover {
            /* Brand Purple Background & Border */
            background-color: var(--wp--preset--color--primary, #6431F6) !important;
            border-color: var(--wp--preset--color--primary, #6431F6) !important;
            
            /* White Text */
            color: #ffffff !important; 
            text-decoration: none !important;
        }

        /* 5. Delete Button Positioning Only */
        /* We removed the red colors, but kept the positioning logic */
        .starke-text-link.delete-link {
            margin-left: auto !important; /* Keeps it isolated on the far right */
        }
        
        /* --- Strict Card Grid Sizing --- */
        /* Forces cards to stay wide enough for the button row, stacking vertically if the screen gets too small */
        .starke-dashboard-grid.address-grid {
            display: grid !important;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)) !important;
            gap: 20px !important;
        }

        .starke-dash-card.address-card {
            width: 100% !important;
            max-width: 400px !important;
            box-sizing: border-box !important;
            display: flex;
            flex-direction: column;
        }

        /* --- Mobile Button Sizing --- */
        @media (max-width: 480px) {
            .address-actions {
                gap: 5px !important; /* Tighter gap on mobile */
            }
            .starke-text-link {
                padding: 8px 6px !important; /* Shrink horizontal padding */
                font-size: 0.65rem !important; /* Slightly smaller text */
            }
        }

        /* --- FLOATING LABEL CSS --- */
        
        /* 1. Define Variables based on your style.css */
        .starke-float-wrapper {
            --target-height: 56px;
            --target-font-size: 18px;
            /* Adjusted padding to forcefully push text down */
            --target-padding-top: 22px; 
            --target-padding-bottom: 8px;
            --target-padding-sides: 12px;
            --target-label-color: rgba(18, 18, 18, 0.7);
            --target-border-color: #000000;
            --target-border-radius: 4px;
            position: relative;
        }

        /* 2. Input Styling (Base) */
        .starke-input {
            box-sizing: border-box !important;
            width: 100% !important;
            min-height: var(--target-height) !important;
            height: var(--target-height) !important;
            font-size: var(--target-font-size) !important;
            font-family: "Inter", sans-serif !important;
            line-height: 1.2 !important; /* Resets line-height to prevent text floating up */
            
            /* Force padding to push text down away from the label */
            padding-top: var(--target-padding-top) !important;
            padding-bottom: var(--target-padding-bottom) !important;
            padding-left: var(--target-padding-sides) !important;
            padding-right: var(--target-padding-sides) !important;
            
            /* Force Black Border */
            border: 1px solid #000000 !important; 
            border-radius: var(--target-border-radius) !important;
            background-color: #ffffff !important;
            color: #000000 !important;
            outline: none !important;
            transition: all 0.2s ease;
            box-shadow: none !important; /* Removes any default theme shadows */
        }

        /* 3. Input Focus State */
        .starke-input:focus {
            border-color: #000000 !important;
            background-color: #ffffff !important;
        }

        /* 4. Label Styling (Default / Placeholder State) */
        .starke-float-wrapper label {
            position: absolute;
            left: var(--target-padding-sides);
            top: 19px; /* Centered vertically for 18px text */
            font-size: var(--target-font-size);
            font-family: "Inter", sans-serif;
            font-weight: 400;
            color: var(--target-label-color);
            pointer-events: none; 
            transition: all 0.2s ease;
            transform-origin: left top;
            margin: 0;
            line-height: 1;
            z-index: 10; /* Ensure label sits on top */
        }

        /* 5. The Magic: Floating State */
        .starke-input:focus + label,
        .starke-input:not(:placeholder-shown) + label {
            top: 6px; /* Moves label to top */
            transform: scale(0.75); /* Shrinks label */
            color: rgba(18, 18, 18, 0.6); 
        }

        /* 6. Special Handling for Select Dropdowns */
        .starke-float-wrapper select.starke-input {
            appearance: none !important;
            -webkit-appearance: none !important;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg' fill='%23000000'%3E%3Cpath d='M17.5 11.6L12 16l-5.5-4.4.9-1.2L12 14l4.5-3.6 1 1.2z'%3E%3C/path%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: right 12px center !important;
            background-size: 1.3em !important;
            padding-right: 30px !important; /* Space for arrow */
        }
        
        /* Force float for valid selects */
        .starke-float-wrapper select.starke-input:valid + label {
            top: 6px; 
            transform: scale(0.75);
        }
    </style>

    <script>
    function openStarkeAddressModal(context, index = null, data = null) {
        const modal = document.getElementById('starke-address-modal');
        const form = document.getElementById('starke-address-form');
        const title = document.getElementById('starke-modal-title');
        
        // 1. Reset Form
        form.reset();
        document.getElementById('starke_addr_context').value = context;
        document.getElementById('starke_addr_index').value = (index !== null) ? index : '';

        // 2. Handle Titles & Logic
        const emailRow = document.getElementById('starke-email-row');
        
        if (context === 'billing') {
            title.innerText = 'Edit Default Billing';
            emailRow.style.display = 'flex'; // Use flex to match row style
        } else if (context === 'shipping_default') {
            title.innerText = 'Edit Default Shipping';
            emailRow.style.display = 'none';
        } else {
            title.innerText = (data) ? 'Edit Saved Address' : 'Add New Shipping Address';
            emailRow.style.display = 'none';
        }

        // 3. Pre-fill Data
        if (data) {
            for (const [key, value] of Object.entries(data)) {
                const field = document.getElementById('sf_' + key);
                if (field) {
                    field.value = value;
                }
            }
        }

        modal.style.display = 'flex';
    }

    function closeStarkeAddressModal() {
        document.getElementById('starke-address-modal').style.display = 'none';
    }

    document.getElementById('starke-address-form').addEventListener('submit', function(e){
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('action', 'starke_save_address');
        formData.append('nonce', '<?php echo wp_create_nonce("starke_address_nonce"); ?>');

        const btn = this.querySelector('button[type="submit"]');
        const origText = btn.innerText;
        btn.innerText = 'Saving...';
        btn.disabled = true;

        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                location.reload(); 
            } else {
                alert('Error saving address: ' + (data.data || 'Unknown error'));
                btn.innerText = origText;
                btn.disabled = false;
            }
        })
        .catch(err => {
            alert('System error occurred.');
            btn.innerText = origText;
            btn.disabled = false;
        });
    });

    function starkeDeleteAddress(index) {
        if(!confirm('Are you sure you want to delete this saved address?')) return;
        const formData = new FormData();
        formData.append('action', 'starke_delete_address');
        formData.append('index', index);
        formData.append('nonce', '<?php echo wp_create_nonce("starke_address_nonce"); ?>');

        fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => { if(data.success) location.reload(); });
    }

    function starkeMakeDefault(index) {
        if(!confirm('Set this as your default shipping address?')) return;
        const formData = new FormData();
        formData.append('action', 'starke_make_default_address');
        formData.append('index', index);
        formData.append('nonce', '<?php echo wp_create_nonce("starke_address_nonce"); ?>');

        fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => { if(data.success) location.reload(); });
    }

    // --- NEW: Google Maps Autocomplete for My Account Address Modal ---
    function initStarkeMyAccountAutocomplete() {
        const addressInput = document.getElementById('sf_address_1');
        if (!addressInput) return;
        
        // Wait for the Google script to finish downloading
        if (!window?.google?.maps?.places) {
            setTimeout(initStarkeMyAccountAutocomplete, 500);
            return;
        }

        if (addressInput.dataset.autocompleteAttached) return;
        addressInput.dataset.autocompleteAttached = 'true';

        let sessionToken = new window.google.maps.places.AutocompleteSessionToken();
        
        // THE FIX: We will store the exact address string we auto-fill here
        let lastAutoFilledAddress = ''; 

        const dropdown = document.createElement('ul');
        dropdown.id = 'starke-myaccount-autocomplete-dropdown';
        dropdown.style.cssText = 'position: absolute; z-index: 1000; background: white; border: 1px solid #ccc; border-radius: 4px; width: 100%; max-height: 250px; overflow-y: auto; list-style: none; padding: 0; margin-top: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); display: none;';

        addressInput.parentNode.style.position = 'relative';
        addressInput.parentNode.appendChild(dropdown);

        let typingTimer;
        addressInput.addEventListener('input', (e) => {
            const val = e.target.value;

            // THE FIX: If the field is empty, OR if it exactly matches the address 
            // we just auto-filled, ignore the event so it doesn't loop!
            if (!val || val === lastAutoFilledAddress) {
                dropdown.style.display = 'none';
                return;
            }

            // If the user actually types something new, clear the memory so it searches
            lastAutoFilledAddress = '';

            clearTimeout(typingTimer);

            typingTimer = setTimeout(async () => {
                try {
                    const request = {
                        input: val,
                        sessionToken,
                        includedRegionCodes: ['US']
                    };

                    const { suggestions } = await window.google.maps.places.AutocompleteSuggestion.fetchAutocompleteSuggestions(request);

                    if (!suggestions || suggestions.length === 0) {
                        dropdown.style.display = 'none';
                        return;
                    }

                    dropdown.innerHTML = '';
                    dropdown.style.display = 'block';

                    for (const suggestion of suggestions) {
                        const prediction = suggestion.placePrediction;
                        if (!prediction) continue;

                        const li = document.createElement('li');
                        li.style.cssText = 'padding: 10px; cursor: pointer; border-bottom: 1px solid #eee; font-family: inherit; font-size: 14px;';

                        const mainText = prediction.mainText?.text || '';
                        const secondaryText = prediction.secondaryText?.text || '';

                        li.innerHTML = `<strong style="color: #6431F6;">${mainText}</strong> <span style="color: #666; font-size: 12px; margin-left: 5px;">${secondaryText}</span>`;

                        li.addEventListener('mouseover', () => (li.style.backgroundColor = '#f4f4f4'));
                        li.addEventListener('mouseout', () => (li.style.backgroundColor = 'white'));

                        li.addEventListener('click', async () => {
                            dropdown.style.display = 'none';

                            try {
                                const place = prediction.toPlace();
                                await place.fetchFields({ fields: ['addressComponents'], sessionToken: sessionToken });

                                let streetNumber = '';
                                let route = '';
                                let state = '';
                                let zip = '';

                                let locality = '';
                                let sublocality = '';
                                let neighborhood = '';

                                for (const component of place.addressComponents) {
                                    const types = component.types || [];

                                    if (types.includes('street_number')) streetNumber = component.longText;
                                    if (types.includes('route')) route = component.shortText;
                                    
                                    if (types.includes('locality')) locality = component.longText;
                                    if (types.includes('sublocality')) sublocality = component.longText;
                                    if (types.includes('administrative_area_level_3')) sublocality = component.longText;
                                    if (types.includes('neighborhood')) neighborhood = component.longText;
                                    
                                    if (types.includes('administrative_area_level_1')) state = component.shortText;
                                    if (types.includes('postal_code')) zip = component.longText;
                                }

                                let city = locality || sublocality || neighborhood || '';
                                const address1 = `${streetNumber} ${route}`.trim();

                                // THE FIX: Store the address we are about to inject
                                lastAutoFilledAddress = address1;

                                // Update the HTML inputs directly
                                document.getElementById('sf_address_1').value = address1;
                                document.getElementById('sf_city').value = city;
                                document.getElementById('sf_state').value = state;
                                document.getElementById('sf_postcode').value = zip;
                                document.getElementById('sf_address_2').value = '';

                                // Force the Floating Labels to pop up gracefully
                                ['sf_address_1', 'sf_city', 'sf_state', 'sf_postcode', 'sf_address_2'].forEach(id => {
                                    const el = document.getElementById(id);
                                    if (el) el.dispatchEvent(new Event('input', { bubbles: true }));
                                });

                                sessionToken = new window.google.maps.places.AutocompleteSessionToken();
                            } catch (error) {
                                console.error('Starke Place Details Error:', error);
                            }
                        });
                        dropdown.appendChild(li);
                    }
                } catch (error) {
                    console.error('Starke Places API Error:', error);
                }
            }, 300);
        });

        document.addEventListener('click', (e) => {
            if (e.target !== addressInput && e.target !== dropdown && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }

    // THE MISSING TRIGGER: This forces the script to actually run!
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initStarkeMyAccountAutocomplete);
    } else {
        initStarkeMyAccountAutocomplete();
    }
    </script>
    <?php
}

/**
 * 4. AJAX Handlers
 */
add_action('wp_ajax_starke_save_address', 'starke_ajax_save_address');
function starke_ajax_save_address() {
    check_ajax_referer('starke_address_nonce', 'nonce');
    $user_id = get_current_user_id();
    
    // Sanitize Input
    $data = [
        'first_name' => sanitize_text_field($_POST['first_name']),
        'last_name'  => sanitize_text_field($_POST['last_name']),
        'company'    => sanitize_text_field($_POST['company']),
        'address_1'  => sanitize_text_field($_POST['address_1']),
        'address_2'  => sanitize_text_field($_POST['address_2']),
        'city'       => sanitize_text_field($_POST['city']),
        'state'      => sanitize_text_field($_POST['state']),
        'postcode'   => sanitize_text_field($_POST['postcode']),
        'phone'      => starke_format_us_phone( sanitize_text_field($_POST['phone']) ),
        'country'    => 'US',
    ];

    $context = $_POST['context']; // billing, shipping_default, shipping_saved

    if ( $context === 'billing' ) {
        // Save to standard Woo Billing Meta
        $data['email'] = sanitize_email($_POST['email']);
        foreach($data as $key => $val) {
            update_user_meta($user_id, 'billing_' . $key, $val);
        }

    } elseif ( $context === 'shipping_default' ) {
        // Save to standard Woo Shipping Meta
        foreach($data as $key => $val) {
            if($key === 'email') continue;
            update_user_meta($user_id, 'shipping_' . $key, $val);
        }

    } elseif ( $context === 'shipping_saved' ) {
        // Save to Custom Array
        $saved = get_user_meta($user_id, 'saved_shipping_addresses', true) ?: [];
        
        $index_raw = $_POST['index'];
        
        if ( $index_raw !== '' && is_numeric( $index_raw ) ) {
            // EDIT EXISTING
            $index = intval($index_raw);
            if(isset($saved[$index])) {
                $saved[$index] = $data;
            }
        } else {
            // ADD NEW
            if(count($saved) < 24) {
                $saved[] = $data;
            } else {
                wp_send_json_error('Maximum of 5 addresses allowed.');
            }
        }
        update_user_meta($user_id, 'saved_shipping_addresses', $saved);
    }

    wp_send_json_success();
}

add_action('wp_ajax_starke_delete_address', 'starke_ajax_delete_address');
function starke_ajax_delete_address() {
    check_ajax_referer('starke_address_nonce', 'nonce');
    $user_id = get_current_user_id();
    $saved = get_user_meta($user_id, 'saved_shipping_addresses', true) ?: [];
    $index = intval($_POST['index']);
    
    if(isset($saved[$index])) {
        array_splice($saved, $index, 1);
        update_user_meta($user_id, 'saved_shipping_addresses', $saved);
    }
    wp_send_json_success();
}

add_action('wp_ajax_starke_make_default_address', 'starke_ajax_make_default_address');
function starke_ajax_make_default_address() {
    check_ajax_referer('starke_address_nonce', 'nonce');
    $user_id = get_current_user_id();
    $saved = get_user_meta($user_id, 'saved_shipping_addresses', true) ?: [];
    $index = intval($_POST['index']);

    if(isset($saved[$index])) {
        // 1. Get Current Default
        $current_default = [
            'first_name' => get_user_meta( $user_id, 'shipping_first_name', true ),
            'last_name'  => get_user_meta( $user_id, 'shipping_last_name', true ),
            'company'    => get_user_meta( $user_id, 'shipping_company', true ),
            'address_1'  => get_user_meta( $user_id, 'shipping_address_1', true ),
            'address_2'  => get_user_meta( $user_id, 'shipping_address_2', true ),
            'city'       => get_user_meta( $user_id, 'shipping_city', true ),
            'state'      => get_user_meta( $user_id, 'shipping_state', true ),
            'postcode'   => get_user_meta( $user_id, 'shipping_postcode', true ),
            'country'    => 'US',
            'phone'      => get_user_meta( $user_id, 'shipping_phone', true ),
        ];

        // 2. The chosen saved address becomes new default
        $new_default = $saved[$index];

        // 3. Update WC Core Meta
        foreach($new_default as $key => $val) {
            update_user_meta($user_id, 'shipping_' . $key, $val);
        }

        // 4. Move old default to saved list at the same index
        $saved[$index] = $current_default;
        update_user_meta($user_id, 'saved_shipping_addresses', $saved);
    }
    wp_send_json_success();
}

/**
 * -----------------------------------------------------------------------------
 * STARKE ACCOUNT DETAILS - FLOATING LABEL FORM (STYLED CARD)
 * -----------------------------------------------------------------------------
 */

/* 1. Remove Default Form & Add Custom One */
add_action( 'wp', 'starke_swap_edit_account_handler', 20 );

function starke_swap_edit_account_handler() {
    if ( is_account_page() ) {
        remove_action( 'woocommerce_account_edit-account_endpoint', 'woocommerce_account_edit_account' );
        add_action( 'woocommerce_account_edit-account_endpoint', 'starke_render_custom_edit_account_form' );
    }
}

/* 2. Render the Custom Form + Required CSS */
function starke_render_custom_edit_account_form() {
    $user_id = get_current_user_id();
    $user    = get_userdata( $user_id );

    if ( ! $user ) {
        return;
    }

    // --- NEW: Get Assigned Term (Admin Controlled) ---
    $assigned_term = get_user_meta( $user_id, '_starke_assigned_payment_term', true );
    if ( empty( $assigned_term ) ) {
        $assigned_term = 'no_terms';
    }
    
    // Helper for display text
    $term_labels = [
        '50_50'  => '50% Down / 50% on Delivery',
        'net_30' => 'Net 30 Terms'
    ];
    ?>

    <form class="starke-edit-account-form starke-card-style" action="" method="post" <?php do_action( 'woocommerce_edit_account_form_tag' ); ?> >

        <?php do_action( 'woocommerce_edit_account_form_start' ); ?>

        <div class="starke-form-row">
            <div class="field-half starke-float-wrapper">
                <input type="text" class="starke-input" name="account_first_name" id="account_first_name" autocomplete="given-name" value="<?php echo esc_attr( $user->first_name ); ?>" placeholder=" " required />
                <label for="account_first_name"><?php esc_html_e( 'First name', 'woocommerce' ); ?> <span class="required">*</span></label>
            </div>
            
            <div class="field-half starke-float-wrapper">
                <input type="text" class="starke-input" name="account_last_name" id="account_last_name" autocomplete="family-name" value="<?php echo esc_attr( $user->last_name ); ?>" placeholder=" " required />
                <label for="account_last_name"><?php esc_html_e( 'Last name', 'woocommerce' ); ?> <span class="required">*</span></label>
            </div>
        </div>

        <div class="starke-form-row">
            <div class="field-full starke-float-wrapper">
                <input type="text" class="starke-input" name="account_display_name" id="account_display_name" value="<?php echo esc_attr( $user->display_name ); ?>" placeholder=" " required />
                <label for="account_display_name"><?php esc_html_e( 'Display name', 'woocommerce' ); ?> <span class="required">*</span></label>
            </div>
        </div>
        <div class="starke-form-row" style="margin-top: -15px; margin-bottom: 25px;">
            <small style="color: #666; font-style: italic; font-family:var(--wp--preset--font-family--inter);">
                <?php esc_html_e( 'This will be how your name will be displayed in the account section.', 'woocommerce' ); ?>
            </small>
        </div>

        <div class="starke-form-row">
            <div class="field-full starke-float-wrapper">
                <input type="email" class="starke-input" name="account_email" id="account_email" autocomplete="email" value="<?php echo esc_attr( $user->user_email ); ?>" placeholder=" " required />
                <label for="account_email"><?php esc_html_e( 'Email address', 'woocommerce' ); ?> <span class="required">*</span></label>
            </div>
        </div>

        <?php
        // --- ARCHITECT STATUS LOGIC ---
        $arch_status = get_user_meta( $user_id, '_starke_architect_status', true );
        if ( ! $arch_status ) $arch_status = 'none';
        
        // Sync check: If they technically have the cap but status is wrong
        $has_access_cap = function_exists('starke_has_architect_access') && starke_has_architect_access($user_id);
        if ( $has_access_cap && $arch_status !== 'approved' && !user_can($user_id, 'manage_woocommerce') ) {
            $arch_status = 'approved';
        }
        ?>
        
        <div class="starke-account-access-section">
            
            <?php if ( $arch_status === 'approved' ) : ?>
                <div class="starke-dxf-denial-notice account-context" style="background: linear-gradient(135deg, #1c1235 0%, #2a1a5e 100%); border: 1px solid #2a1a5e;">
                    <div style="display:flex; align-items:center; gap:15px;">
                        <i class="fas fa-check-circle" style="font-size: 1.5rem; color: #FFC107;"></i>
                        <div>
                            <strong style="color: #FFC107;">Architect Access Approved</strong><br>
                            <span style="font-size: 0.85rem; color: #e0e0e0;">You have full access to download DXF drawing files.</span>
                        </div>
                    </div>
                </div>

            <?php elseif ( $arch_status === 'pending' ) : ?>
                <div class="starke-dxf-denial-notice account-context" style="background: linear-gradient(135deg, #1c1235 0%, #2a1a5e 100%); border: 1px solid #2a1a5e;">
                    <div style="display:flex; align-items:center; gap:15px;">
                        <i class="fas fa-clock" style="font-size: 1.5rem; color: #FFC107;"></i>
                        <div>
                            <strong style="color: #FFC107;">Architect Access Pending</strong><br>
                            <span style="font-size: 0.85rem; color: #e0e0e0;">Your Architect Access status is currently under review.</span>
                        </div>
                    </div>
                </div>

            <?php else : ?>
                <div class="starke-dxf-denial-notice account-context" style="background: linear-gradient(135deg, #1c1235 0%, #2a1a5e 100%); border: 1px solid #2a1a5e;">
                    <div style="display:flex; align-items:center; justify-content: space-between; gap:20px; width: 100%;">
                        <div>
                            <strong style="color: #FFC107;">Architect Access</strong><br>
                            
                            <span style="font-size: 0.85rem; color: #e0e0e0; display: block; margin-top: 5px; line-height: 1.4;">
                                You do not have active architect access.<br>
                                We reserve this access to architectural firms only. If you are not an architectural firm and would like a DXF, please email us. If you are an architectural firm or would like to request that access, please email us with your details for us to review.
                            </span>
                        </div>
                        
                        <div style="flex-shrink: 0;">
                            <a href="mailto:info@starkemillwork.com" style="color: #FFC107; font-weight: bold; text-decoration: underline;">
                                info@starkemillwork.com
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php 
            // Define Terms with Descriptions
            $term_details = [
                'no_terms' => [
                    'label' => 'No Terms',
                    'desc'  => 'Full payment needed at time of order.'
                ],
                '50_50'    => [
                    'label' => '50% Down / 50% on Delivery',
                    'desc'  => 'Pay 50% now to secure order. Remainder due on delivery.'
                ],
                'net_30'   => [
                    'label' => 'Net 30 Terms',
                    'desc'  => 'Full payment due within 30 days of invoice.'
                ]
            ];
            
            if ( $assigned_term !== 'no_terms' && isset( $term_details[$assigned_term] ) ) : 
                $current_detail = $term_details[$assigned_term];
            ?>
                <div class="starke-dxf-denial-notice account-context" style="background: linear-gradient(135deg, #1c1235 0%, #2a1a5e 100%); border: 1px solid #2a1a5e; margin-top: 20px;">
                    <div style="display:flex; align-items:flex-start; gap:15px;"> <i class="fas fa-file-invoice-dollar" style="font-size: 1.5rem; color: #FFC107; margin-top: 4px;"></i>
                        <div>
                            <strong style="color: #FFC107;">Available Payment Terms</strong><br>
                            <span style="font-size: 0.95rem; color: #fff; font-weight: 600; display:block; margin-top:4px; margin-bottom: 2px;">
                                <?php echo esc_html( $current_detail['label'] ); ?>
                            </span>
                            <span style="font-size: 0.85rem; color: #e0e0e0; font-style: italic;">
                                <?php echo esc_html( $current_detail['desc'] ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <fieldset style="margin-top: 40px; border: none; padding: 0;">
            <legend style="font-family: var(--wp--preset--font-family--inter); font-size: 1.25rem; font-weight: 500; margin-bottom: 20px; display: block; width: 100%; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                <?php esc_html_e( 'Password Change', 'woocommerce' ); ?>
            </legend>

            <div class="starke-form-row">
                <div class="field-full starke-float-wrapper">
                    <input type="password" class="starke-input" name="password_current" id="password_current" autocomplete="off" placeholder=" " />
                    <label for="password_current"><?php esc_html_e( 'Current password (leave blank to leave unchanged)', 'woocommerce' ); ?></label>
                </div>
            </div>

            <div class="starke-form-row">
                <div class="field-full starke-float-wrapper">
                    <input type="password" class="starke-input" name="password_1" id="password_1" autocomplete="off" placeholder=" " />
                    <label for="password_1"><?php esc_html_e( 'New password (leave blank to leave unchanged)', 'woocommerce' ); ?></label>
                </div>
            </div>

            <div class="starke-form-row">
                <div class="field-full starke-float-wrapper">
                    <input type="password" class="starke-input" name="password_2" id="password_2" autocomplete="off" placeholder=" " />
                    <label for="password_2"><?php esc_html_e( 'Confirm new password', 'woocommerce' ); ?></label>
                </div>
            </div>
        </fieldset>

        <?php do_action( 'woocommerce_edit_account_form' ); ?>

        <div class="starke-form-row" style="margin-top: 20px; margin-bottom: 0;">
            <?php wp_nonce_field( 'save_account_details', 'save-account-details-nonce' ); ?>
            <button type="submit" class="starke-sample-btn request-mode" name="save_account_details" value="<?php esc_attr_e( 'Save changes', 'woocommerce' ); ?>">
                <?php esc_html_e( 'Save changes', 'woocommerce' ); ?>
            </button>
            <input type="hidden" name="action" value="save_account_details" />
        </div>

        <?php do_action( 'woocommerce_edit_account_form_end' ); ?>
    </form>

    <style>
        /* --- Standard Form Styling --- */
        .starke-card-style {
            background: #fff;
            padding: 40px;
            border-radius: 8px;
            border: 1px solid #eee;
            box-shadow: 0px 0px 15px rgba(0, 0, 0, 0.2); 
            max-width: 800px;
            margin: 0 auto;
        }

        .starke-float-wrapper label .required {
            color: #cc1818 !important;
            text-decoration: none;
        }

        .starke-form-row { display: flex; gap: 20px; margin-bottom: 20px; }
        .field-half { flex: 1; }
        .field-full { width: 100%; }
        @media (max-width: 600px) {
            .starke-form-row { flex-direction: column; gap: 20px; }
            .starke-card-style { padding: 20px; }
        }

        /* --- Floating Label CSS --- */
        .starke-float-wrapper {
            --target-height: 56px;
            --target-font-size: 18px;
            --target-padding-top: 22px; 
            --target-padding-bottom: 8px;
            --target-padding-sides: 12px;
            --target-label-color: rgba(18, 18, 18, 0.7);
            --target-border-color: #000000;
            --target-border-radius: 4px;
            position: relative;
        }

        .starke-input {
            box-sizing: border-box !important;
            width: 100% !important;
            min-height: var(--target-height) !important;
            height: var(--target-height) !important;
            font-size: var(--target-font-size) !important;
            font-family: "Inter", sans-serif !important;
            line-height: 1.2 !important;
            padding-top: var(--target-padding-top) !important;
            padding-bottom: var(--target-padding-bottom) !important;
            padding-left: var(--target-padding-sides) !important;
            padding-right: var(--target-padding-sides) !important;
            border: 1px solid #000000 !important; 
            border-radius: var(--target-border-radius) !important;
            background-color: #ffffff !important;
            color: #000000 !important;
            outline: none !important;
            transition: all 0.2s ease;
            box-shadow: none !important;
        }

        .starke-input:focus {
            border-color: #000000 !important;
            background-color: #ffffff !important;
        }

        .starke-float-wrapper label {
            position: absolute;
            left: var(--target-padding-sides);
            top: 19px;
            font-size: var(--target-font-size);
            font-family: "Inter", sans-serif;
            font-weight: 400;
            color: var(--target-label-color);
            pointer-events: none; 
            transition: all 0.2s ease;
            transform-origin: left top;
            margin: 0;
            line-height: 1;
            z-index: 10;
        }

        .starke-input:focus + label,
        .starke-input:not(:placeholder-shown) + label {
            top: 6px; 
            transform: scale(0.75); 
            color: rgba(18, 18, 18, 0.6); 
        }
    </style>
    <?php
}







// --- NEW: Admin Backend Logic for Payment Terms ---

add_action( 'show_user_profile', 'starke_payment_terms_admin_fields', 20 );
add_action( 'edit_user_profile', 'starke_payment_terms_admin_fields', 20 );
add_action( 'user_new_form', 'starke_payment_terms_admin_fields', 20 );

function starke_payment_terms_admin_fields( $user ) {
    $is_new_user = ! is_object( $user );
    
    // Hide for Admins (only applies when editing an existing user)
    if ( ! $is_new_user && in_array( 'administrator', (array) $user->roles ) ) {
        return;
    }

    if ( $is_new_user ) {
        $current_term = 'no_terms';
        $notification_text = '<span style="color: #667;">(New user account creation)</span>';
    } else {
        $current_term = get_user_meta( $user->ID, '_starke_assigned_payment_term', true );
        if ( empty( $current_term ) ) {
            $current_term = 'no_terms';
        }

        // Email Tracking Logic
        $email_sent_time = get_user_meta( $user->ID, '_starke_terms_email_sent_time', true );
        $email_sent_for = get_user_meta( $user->ID, '_starke_terms_email_sent_for', true );
        $email_was_sent = ( $email_sent_time && $email_sent_for === $current_term );

        if ( $email_was_sent ) {
            $date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
            $formatted_date = date_i18n( $date_format, $email_sent_time );
            $notification_text = '<span style="color: #46b450;">(Notification email WAS sent on ' . $formatted_date . ')</span>';
        } else {
            $notification_text = '<span style="color: #ca4a1f;">(Notification email was NOT sent yet for this setting)</span>';
        }
    }
    ?>
    <h3 id="starke-payment-terms-settings">Starke Payment Terms</h3>
    
    <input type="hidden" name="starke_terms_admin_validation" value="1" />

    <table class="form-table">
        <tr>
            <th><label>Assigned Payment Term</label></th>
            <td>
                <fieldset>
                    <label style="margin-right: 20px; display:block; margin-bottom:5px;">
                        <input type="radio" name="starke_assigned_payment_term" value="no_terms" <?php checked( $current_term, 'no_terms' ); ?>> 
                        <strong>No Terms</strong> (Pay in Full - Default)
                    </label>
                    <label style="margin-right: 20px; display:block; margin-bottom:5px;">
                        <input type="radio" name="starke_assigned_payment_term" value="50_50" <?php checked( $current_term, '50_50' ); ?>> 
                        <strong>50/50</strong> (50% Down / 50% on Delivery)
                    </label>
                    <label style="margin-right: 20px; display:block; margin-bottom:5px;">
                        <input type="radio" name="starke_assigned_payment_term" value="net_30" <?php checked( $current_term, 'net_30' ); ?>> 
                        <strong>Net 30</strong> (Full payment due in 30 days)
                    </label>
                </fieldset>
                
                <p class="description" style="margin-top:10px;">
                    <?php echo $notification_text; ?>
                </p>
            </td>
        </tr>
        <tr>
            <th><label for="starke_notify_terms_change">Notification</label></th>
            <td>
                <label for="starke_notify_terms_change">
                    <input type="checkbox" name="starke_notify_terms_change" id="starke_notify_terms_change" value="1" />
                    <strong>Send Email Notification?</strong>
                </label>
                <p class="description">Check this to notify the customer of their Payment Terms update.</p>
            </td>
        </tr>
    </table>
    
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            var $radios = $('input[name="starke_assigned_payment_term"]');
            var $notifyCheckbox = $('#starke_notify_terms_change');
            
            setTimeout(function() {
                $radios.each(function() { this.defaultChecked = this.checked; });
                $notifyCheckbox.each(function() { this.defaultChecked = this.checked; });
            }, 100); 

            var initialStatus = $radios.filter(':checked').val();

            $radios.on('change', function() {
                var currentStatus = $radios.filter(':checked').val();
                if ( currentStatus !== initialStatus ) {
                    $notifyCheckbox.prop('checked', true);
                } else {
                    $notifyCheckbox.prop('checked', false);
                }
            });
        });
    </script>
    <?php
}

// ---------------------------------------------------------
// PAYMENT TERMS: SAVE HANDLER (TRIGGER)
// ---------------------------------------------------------
add_action( 'user_register', 'starke_save_payment_terms_admin_fields', 10, 1 );
add_action( 'profile_update', 'starke_save_payment_terms_admin_fields', 10, 2 );

function starke_save_payment_terms_admin_fields( $user_id, $old_user_data = null ) {
    if ( ! isset( $_POST['starke_terms_admin_validation'] ) ) return;
    if ( ! current_user_can( 'edit_user', $user_id ) ) return;

    $prev_term = get_user_meta( $user_id, '_starke_assigned_payment_term', true );
    $new_term  = isset($_POST['starke_assigned_payment_term']) ? sanitize_text_field($_POST['starke_assigned_payment_term']) : 'no_terms';

    // Save Data
    update_user_meta( $user_id, '_starke_assigned_payment_term', $new_term );
    update_user_meta( $user_id, '_starke_payment_terms', $new_term );

    // Handle Async Email Trigger
    if ( isset( $_POST['starke_notify_terms_change'] ) ) {
        
        // NEW: Check if we are creating a new user AND sending the default WP notification
        $is_bundling = doing_action( 'user_register' ) && ! empty( $_POST['send_user_notification'] );

        if ( ! $is_bundling ) {
            // ASYNC: Schedule the email to be sent by Action Scheduler (WP-CLI)
            as_enqueue_async_action( 
                'starke_async_send_payment_terms_email', 
                [ 
                    'user_id' => $user_id, 
                    'term'    => $new_term 
                ],
                'starke-emails'
            );
        }

        // Mark as sent immediately for UI feedback
        update_user_meta( $user_id, '_starke_terms_email_sent_time', current_time( 'timestamp' ) );
        update_user_meta( $user_id, '_starke_terms_email_sent_for', $new_term );

    } else {
        if ( $prev_term !== $new_term ) {
             delete_user_meta( $user_id, '_starke_terms_email_sent_time' );
             delete_user_meta( $user_id, '_starke_terms_email_sent_for' );
        }
    }
}

// ---------------------------------------------------------
// PAYMENT TERMS: ASYNC WORKER (SENDER)
// ---------------------------------------------------------
add_action( 'starke_async_send_payment_terms_email', 'starke_process_payment_terms_email_async', 10, 2 );

function starke_process_payment_terms_email_async( $user_id, $new_term ) {
    $user_info = get_userdata( $user_id );
    if ( ! $user_info ) return;

    $site_name = get_bloginfo( 'name' );
    $mailer    = WC()->mailer();
    // Borrow "Customer Note" for styling context
    $email_obj = $mailer->get_emails()['WC_Email_Customer_Note'] ?? null; 
    
    $term_definitions = [
        'no_terms' => [
            'label' => 'No Terms (Pay in Full)',
            'desc'  => 'Full payment needed at time of order.'
        ],
        '50_50'    => [
            'label' => '50% Down / 50% on Delivery',
            'desc'  => 'Pay 50% at checkout to secure order, and remaining 50% prior to delivery.'
        ],
        'net_30'   => [
            'label' => 'Net 30 Days',
            'desc'  => 'Full payment due within 30 days of invoice.'
        ]
    ];

    $selected_info = isset($term_definitions[$new_term]) ? $term_definitions[$new_term] : $term_definitions['no_terms'];
    $subject       = 'Payment Terms Update - ' . $site_name;
    $heading       = 'Payment Terms Update';

    // --- DETERMINE THE BEST FIRST NAME ---
    $customer_first_name = $user_info->first_name;
    if ( empty( trim( $customer_first_name ) ) ) {
        $customer_first_name = get_user_meta( $user_id, 'billing_first_name', true );
    }
    if ( empty( trim( $customer_first_name ) ) && ! empty( $user_info->display_name ) && $user_info->display_name !== $user_info->user_email ) {
        $customer_first_name = $user_info->display_name;
    }
    if ( empty( trim( $customer_first_name ) ) ) {
        $email_parts = explode( '@', $user_info->user_email );
        $customer_first_name = ucfirst( $email_parts[0] );
    }
    // -------------------------------------

    // --- TEXT SIZE STYLING ---
    $p_style = "font-size: 16px; line-height: 1.5em; color: #333; margin-bottom: 16px;";

    // Build Content
    $content = "<p style='{$p_style}'>Hello " . esc_html( $customer_first_name ) . ",</p>";
    $content .= "<p style='{$p_style}'>Your account payment terms have been updated.</p>";
    
    // Stacked Layout
    $content .= "<p style='{$p_style}'>Current Terms:<br>";
    $content .= "<strong style='font-size: 1.1em; display:inline-block; margin-top: 5px; margin-bottom: 4px; color: #000;'>" . esc_html($selected_info['label']) . "</strong><br>";
    $content .= "<span style='color: #555; font-style: italic; font-size: 0.95em;'>" . esc_html($selected_info['desc']) . "</span></p>";
    
    if ( $new_term === 'no_terms' ) {
        $content .= "<p style='{$p_style}'>Orders will now require full payment at the time of purchase.</p>";
    } else {
        $content .= "<p style='{$p_style}'>This option will now be available to you during checkout.</p>";
    }

    // Generate HTML
    if ( $email_obj ) {
        $header = wc_get_template_html( 'emails/email-header.php', array( 'email_heading' => $heading, 'email' => $email_obj ) );
        $footer = wc_get_template_html( 'emails/email-footer.php', array( 'email' => $email_obj ) );
        $final_message = $email_obj->style_inline( $header . $content . $footer );
    } else {
        $final_message = $mailer->wrap_message( $heading, $content );
    }

    $headers = array('Content-Type: text/html; charset=UTF-8');
    wp_mail( $user_info->user_email, $subject, $final_message, $headers );
}

/**
 * FIX: 
 * 1. Prevent "Invalid User ID" Error.
 * 2. Prevent False "Unsaved Changes".
 * 3. GUARANTEED FIX: Hide -> Strip Identity -> Restore on Click/Submit/Reload.
 */

// 1. HEAD HOOK: Hide #email immediately via CSS to prevent Focus & Flash
add_action( 'admin_head-user-edit.php', 'starke_hide_email_css' );
add_action( 'admin_head-profile.php',   'starke_hide_email_css' );

function starke_hide_email_css() {
    // Hidden initially so LastPass can't focus it and User sees no flash
    echo '<style>input#email { visibility: hidden !important; }</style>';
}

// 2. FOOTER HOOK: The Logic to Fix & Reveal
add_action( 'admin_footer-user-edit.php', 'starke_fix_user_edit_scripts' );
add_action( 'admin_footer-profile.php',   'starke_fix_user_edit_scripts' );

function starke_fix_user_edit_scripts() {
    ?>
    <script type="text/javascript">
    (function() {
        // Runs immediately in the footer
        var email = document.getElementById('email');
        if (!email) return;

        // --- STEP 1: IMMEDIATE ISOLATION (LastPass Protection) ---
        // Capture the true value and strip identity immediately.
        var originalValue = email.defaultValue || email.value;
        email.value = originalValue;
        
        email.removeAttribute('id');
        email.removeAttribute('name');
        
        // Apply protections
        email.setAttribute('type', 'text');
        email.setAttribute('readonly', 'readonly');
        email.setAttribute('autocomplete', 'off');

        // Reveal the field (Safe to show now)
        email.style.cssText = 'visibility: visible !important; background-color: #fff;';

        // --- STEP 2: THE RESTORATION FUNCTION ---
        // Puts everything back to normal so WordPress/Browser is happy.
        function restoreIdentity() {
            // Only run if it hasn't been restored yet
            if (!email.getAttribute('name')) {
                email.setAttribute('id', 'email');
                email.setAttribute('name', 'email');
                
                // CRITICAL: Ensure the browser knows the value is "Clean" (Unsaved Changes Fix)
                if (email.value === originalValue) {
                    email.defaultValue = originalValue;
                }
                
                // Unlock visuals
                email.removeAttribute('readonly');
                email.removeAttribute('autocomplete'); 
                email.setAttribute('type', 'email');   
            }
        }

        // --- STEP 3: TRIGGERS (When to restore) ---

        // A. INTERACTION: Restore when you click/focus to edit
        email.addEventListener('click', restoreIdentity);
        email.addEventListener('focus', restoreIdentity);

        // B. SUBMIT: Restore right before saving data
        var form = email.closest('form');
        if (form) {
            form.addEventListener('submit', restoreIdentity);
        }

        // C. RELOAD/UNLOAD: Restore BEFORE the browser checks for changes
        // We use 'true' (Capture Phase) to ensure this runs BEFORE WordPress's check.
        window.addEventListener('beforeunload', function() {
            restoreIdentity();
        }, true);

    })();

    // --- LEGACY FIXES (General Form Cleanup) ---
    if (typeof jQuery !== 'undefined') {
        jQuery(function($) {
            // Fix Invalid User ID
            var $form = $('#your-profile');
            var userId = $('#user_id').val();
            if ( $form.length && userId ) {
                var action = $form.attr('action');
                if ( action && action.indexOf('user_id') === -1 ) {
                    var separator = action.indexOf('?') === -1 ? '?' : '&';
                    $form.attr('action', action + separator + 'user_id=' + userId);
                }
            }
            // General Form Cleanup
            setTimeout(function() {
                 $form.find(':input').each(function() {
                    if (this.type === 'checkbox' || this.type === 'radio') {
                        this.defaultChecked = this.checked;
                    } else {
                        this.defaultValue = this.value;
                    }
                });
            }, 200); 
        });
    }
    </script>
    <?php
}

/* =========================================================
   STARKE ARCHITECT ACCESS SYSTEM
   ========================================================= */

// 1. Add Checkbox to Standard "My Account" Register Page
add_action( 'woocommerce_register_form', 'starke_render_architect_checkbox_standard' );
function starke_render_architect_checkbox_standard() {
    ?>
    <p class="form-row form-row-wide">
        <label class="starke-architect-request">
            <input type="checkbox" name="starke_request_architect" value="1" />
            <span class="starke-req-text">Request Architect Access (Download DXF Drawings)</span>
        </label>
    </p>
    <?php
}

// 2. Save the Request & Notify Admin
/**
 * 1. TRIGGER: Process New Account & Schedule Email
 * - Runs for ALL new accounts. Passes a flag if Architect Access was requested.
 */
add_action( 'woocommerce_created_customer', 'starke_schedule_new_account_admin_email', 10, 3 );
function starke_schedule_new_account_admin_email( $customer_id, $new_customer_data, $password_generated ) {
    
    // Check if they requested architect access
    $requested_architect = ! empty( $_POST['starke_request_architect'] ) ? true : false;

    // Only update architect meta if they actually requested it
    if ( $requested_architect ) {
        update_user_meta( $customer_id, '_starke_architect_status', 'pending' );
        update_user_meta( $customer_id, '_starke_architect_status_initiated_by', 'customer_registration' );
    }

    // Schedule the email job and pass BOTH variables
    if ( function_exists( 'as_schedule_single_action' ) ) {
        as_schedule_single_action( 
            time(), 
            'starke_send_admin_new_account_email_job', 
            array( 
                'customer_id'         => $customer_id,
                'requested_architect' => $requested_architect 
            ) 
        );
    }
}

/**
 * 2. WORKER: Send the Admin Notification Email
 * - Dynamically adapts verbiage based on whether Architect Access was requested.
 */
// IMPORTANT: Notice the '2' at the end here so it accepts both arguments!
add_action( 'starke_send_admin_new_account_email_job', 'starke_process_admin_new_account_email', 10, 2 );
function starke_process_admin_new_account_email( $customer_id, $requested_architect = false ) {
    
    // Validate User
    $user_data = get_userdata( $customer_id );
    if ( ! $user_data ) {
        return;
    }

    // Get all administrator emails
    $admins = get_users( array( 'role' => 'administrator' ) );
    $recipient_emails = array();
    foreach ( $admins as $admin ) {
        $recipient_emails[] = $admin->user_email;
    }

    // Define the list of admin emails to exclude (if any)
    $excluded_emails = ['rath7v@gmail.com']; // 'danielle@starkemillwork.com', 'zac@starkemillwork.com'
    $recipient_emails = array_diff( $recipient_emails, $excluded_emails );

    // Stop if there are no recipients
    if ( empty( $recipient_emails ) ) {
        return;
    }

    $edit_link = admin_url( 'user-edit.php?user_id=' . $customer_id . '#starke-architect-settings' );
    
    // --- DYNAMIC CONTENT BASED ON REQUEST TYPE ---
    if ( $requested_architect ) {
        $subject    = 'Action Required: Architect Access Requested - ' . $user_data->user_login;
        $heading    = 'Architect Access Requested';
        $intro_text = 'A new Customer has registered an account and requested Architect Access.';
    } else {
        $subject    = 'New Customer Registration - ' . $user_data->user_login;
        $heading    = 'New Account Created';
        $intro_text = 'A new Customer has registered an account on the website.';
    }

    // --- CONSTRUCT HTML BODY ---
    ob_start();
    ?>
    <div style="font-size: 18px !important; line-height: 1.5; margin-bottom: 20px; color: #636363;">
        <?php echo esc_html( $intro_text ); ?>
    </div>
    
    <div style="margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 4px;">
        <ul style="list-style: none; padding: 0; margin: 0; font-size: 14px;">
            <li><strong>Username:</strong> <?php echo esc_html( $user_data->user_login ); ?></li>
            <li><strong>Email:</strong> <a href="mailto:<?php echo esc_attr( $user_data->user_email ); ?>"><?php echo esc_html( $user_data->user_email ); ?></a></li>
            <li><strong>User ID:</strong> <?php echo esc_html( $customer_id ); ?></li>
            <li><strong>Registration Date:</strong> <?php echo date_i18n( get_option( 'date_format' ), strtotime( $user_data->user_registered ) ); ?></li>
        </ul>
    </div>

    <?php if ( $requested_architect ) : ?>
        <p style="margin-bottom: 20px;">
            <a href="<?php echo esc_url( $edit_link ); ?>" style="color: #6431F6; font-size: 20px; font-weight: bold; text-decoration: underline;">Review & Approve User</a>
        </p>
        <p style="font-size: 12px; color: #999;">You can manage permissions in the "Starke Architect Settings" section of the user profile.</p>
    <?php else : ?>
        <p style="margin-bottom: 20px;">
            <a href="<?php echo esc_url( $edit_link ); ?>" style="color: #6431F6; font-size: 20px; font-weight: bold; text-decoration: underline;">View User Profile</a>
        </p>
    <?php endif; ?>
    
    <?php
    $content = ob_get_clean();

    // --- WRAP WITH WOOCOMMERCE TEMPLATE ---
    $header = wc_get_template_html( 'emails/email-header.php', array( 'email_heading' => $heading ) );
    $footer = wc_get_template_html( 'emails/email-footer.php' );
    
    $final_message = $header . $content . $footer;

    // --- APPLY INLINE STYLES ---
    try {
        $mailer = WC()->mailer();
        $email_object = $mailer->get_emails()['WC_Email_Customer_Note'] ?? null;
        if ( $email_object && method_exists( $email_object, 'style_inline' ) ) {
            $final_message = $email_object->style_inline( $final_message );
        }
    } catch ( Exception $e ) {}

    // --- SEND EMAIL ---
    $content_type_filter = function() { return 'text/html'; };
    add_filter( 'wp_mail_content_type', $content_type_filter );
    
    wp_mail( $recipient_emails, $subject, $final_message );
    
    remove_filter( 'wp_mail_content_type', $content_type_filter );
}

// 3. Add Settings to Admin User Profile
add_action( 'show_user_profile', 'starke_architect_admin_fields', 21 );
add_action( 'edit_user_profile', 'starke_architect_admin_fields', 21 );
add_action( 'user_new_form', 'starke_architect_admin_fields', 21 );

function starke_architect_admin_fields( $user ) {
    $is_new_user = ! is_object( $user );

    // Hide for Admins (only applies when editing an existing user)
    if ( ! $is_new_user && in_array( 'administrator', (array) $user->roles ) ) {
        return;
    }

    if ( $is_new_user ) {
        $current_status = 'denied';
        $notification_text = '<span style="color: #666;">(New user account creation)</span>';
    } else {
        $current_status = get_user_meta( $user->ID, '_starke_architect_status', true );
        if ( empty( $current_status ) ) $current_status = 'denied'; 
    
        $email_sent_time = get_user_meta( $user->ID, '_starke_architect_email_sent_time', true );
        $email_sent_status = get_user_meta( $user->ID, '_starke_architect_email_sent_for_status', true );
        $email_was_sent = ( $email_sent_time && $email_sent_status === $current_status );
        $initiated_by = get_user_meta( $user->ID, '_starke_architect_status_initiated_by', true );

        if ( $current_status === 'pending' && $initiated_by === 'customer' ) {
            $notification_text = '<span style="color: #ca4a1f;">(Pending review triggered by customer request)</span>';
        } else {
            $notification_text = '<span style="color: #ca4a1f;">(Customer notification email was NOT sent yet for this status)</span>';
            if ( $email_was_sent ) {
                $date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
                $formatted_date = date_i18n( $date_format, $email_sent_time );
                $notification_text = '<span style="color: #46b450;">(Customer notification email WAS sent on ' . $formatted_date . ')</span>';
            }
        }
    }
    ?>
    <h3 id="starke-architect-settings">Starke Architect Access</h3>
    
    <input type="hidden" name="starke_architect_admin_validation" value="1" />

    <table class="form-table">
        <tr>
            <th><label>Current Status</label></th>
            <td>
                <fieldset>
                    <label style="margin-right: 20px;">
                        <input type="radio" name="starke_architect_status" value="pending" <?php checked( $current_status, 'pending' ); ?>> 
                        <strong>Pending</strong> (Under Review)
                    </label>
                    <br>
                    <label style="margin-right: 20px;">
                        <input type="radio" name="starke_architect_status" value="approved" <?php checked( $current_status, 'approved' ); ?>> 
                        <strong style="color: #46b450;">Approved</strong> (Grant Access)
                    </label>
                    <br>
                    <label>
                        <input type="radio" name="starke_architect_status" value="denied" <?php checked( $current_status, 'denied' ); ?>> 
                        <strong style="color: #cc1818;">Denied</strong> (No Access)
                    </label>
                </fieldset>
                
                <p class="description" style="margin-top:10px;">
                    <?php echo $notification_text; ?>
                </p>
            </td>
        </tr>
        <tr>
            <th><label for="starke_notify_user">Notification</label></th>
            <td>
                <label for="starke_notify_user">
                    <input type="checkbox" name="starke_notify_user" id="starke_notify_user" value="1" />
                    <strong>Send Email Notification?</strong>
                </label>
                <p class="description">Check this to notify the customer of their Architect Access update.</p>
            </td>
        </tr>
    </table>
    
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            var $radios = $('input[name="starke_architect_status"]');
            var $notifyCheckbox = $('#starke_notify_user');
            
            setTimeout(function() {
                $radios.each(function() { this.defaultChecked = this.checked; });
                $notifyCheckbox.each(function() { this.defaultChecked = this.checked; });
            }, 100); 

            var initialStatus = $radios.filter(':checked').val();

            $radios.on('change', function() {
                var currentStatus = $radios.filter(':checked').val();
                if ( currentStatus !== initialStatus ) {
                    $notifyCheckbox.prop('checked', true);
                } else {
                    $notifyCheckbox.prop('checked', false);
                }
            });
        });
    </script>
    <?php
}

// ---------------------------------------------------------
// ARCHITECT: SAVE HANDLER (TRIGGER)
// ---------------------------------------------------------
add_action( 'user_register', 'starke_save_architect_admin_fields', 10, 1 );
add_action( 'profile_update', 'starke_save_architect_admin_fields', 10, 2 );

function starke_save_architect_admin_fields( $user_id, $old_user_data = null ) {
    
    if ( ! isset( $_POST['starke_architect_admin_validation'] ) ) return;
    if ( ! current_user_can( 'edit_user', $user_id ) ) return;

    $prev_status = get_user_meta( $user_id, '_starke_architect_status', true );
    $new_status  = isset($_POST['starke_architect_status']) ? sanitize_text_field($_POST['starke_architect_status']) : 'denied';

    // Update Status
    update_user_meta( $user_id, '_starke_architect_status', $new_status );
    update_user_meta( $user_id, '_starke_architect_status_initiated_by', 'admin' );
    
    if ( $new_status === 'approved' ) {
        update_user_meta( $user_id, '_starke_architect_access', '1' );
    } else {
        delete_user_meta( $user_id, '_starke_architect_access' );
    }
    
    // Handle Async Email Trigger
    if ( isset( $_POST['starke_notify_user'] ) ) {
        
        // NEW: Check if we are creating a new user AND sending the default WP notification
        $is_bundling = doing_action( 'user_register' ) && ! empty( $_POST['send_user_notification'] );

        if ( ! $is_bundling ) {
            // ASYNC: Schedule via Action Scheduler
            as_enqueue_async_action( 
                'starke_async_send_architect_email', 
                [ 
                    'user_id'     => $user_id, 
                    'new_status'  => $new_status,
                    'prev_status' => $prev_status
                ],
                'starke-emails'
            );
        }

        // Update meta immediately for UI
        update_user_meta( $user_id, '_starke_architect_email_sent_time', current_time( 'timestamp' ) );
        update_user_meta( $user_id, '_starke_architect_email_sent_for_status', $new_status );

    } else {
        if ( $prev_status !== $new_status ) {
             delete_user_meta( $user_id, '_starke_architect_email_sent_time' );
             delete_user_meta( $user_id, '_starke_architect_email_sent_for_status' );
        }
    }
}

// ---------------------------------------------------------
// ARCHITECT: ASYNC WORKER (SENDER)
// ---------------------------------------------------------
add_action( 'starke_async_send_architect_email', 'starke_process_architect_email_async', 10, 3 );

function starke_process_architect_email_async( $user_id, $new_status, $prev_status ) {
    $user_info = get_userdata( $user_id );
    if ( ! $user_info ) return;

    $site_name = get_bloginfo( 'name' );
    $mailer    = WC()->mailer();
    // Borrow "Customer Note" for styling context (Logo, Colors, etc.)
    $email_obj = $mailer->get_emails()['WC_Email_Customer_Note'] ?? null; 

    // --- DETERMINE THE BEST FIRST NAME ---
    $customer_first_name = $user_info->first_name;
    if ( empty( trim( $customer_first_name ) ) ) {
        $customer_first_name = get_user_meta( $user_id, 'billing_first_name', true );
    }
    if ( empty( trim( $customer_first_name ) ) && ! empty( $user_info->display_name ) && $user_info->display_name !== $user_info->user_email ) {
        $customer_first_name = $user_info->display_name;
    }
    if ( empty( trim( $customer_first_name ) ) ) {
        $email_parts = explode( '@', $user_info->user_email );
        $customer_first_name = ucfirst( $email_parts[0] );
    }
    // -------------------------------------
    
    $subject   = '';
    $heading   = '';
    $content   = '';
    $should_send = false;
    
    // --- TEXT SIZE STYLING ---
    // Universal styling for better readability on all devices
    $p_style = "font-size: 16px; line-height: 1.5em; color: #333; margin-bottom: 16px;";

    // LOGIC A: PENDING
    if ( $new_status === 'pending' ) {
        $subject = 'Architect Access Under Review - ' . $site_name;
        $heading = 'Access Under Review';
        $content = "<p style='{$p_style}'>Hello " . esc_html( $customer_first_name ) . ",</p>";
        $content .= "<p style='{$p_style}'>Your Architect Access status is currently <strong>under review</strong>.</p>";
        $content .= "<p style='{$p_style}'>While under review, you will not have access to download DXF drawings.</p>";
        $content .= "<p style='{$p_style}'>We will notify you via email once a final decision has been made.</p>";
        $should_send = true;
    }
    // LOGIC B: APPROVAL (Universal Phrasing)
    elseif ( $new_status === 'approved' ) {
        $subject = 'Architect Access Approved - ' . $site_name;
        $heading = 'Access Approved';
        
        $content = "<p style='{$p_style}'>Hello " . esc_html( $customer_first_name ) . ",</p>";
        
        // Universal message: Works for requests AND manual admin grants
        $content .= "<p style='{$p_style}'>Good news! Your account has been granted <strong>Architect Access</strong>.</p>";

        $content .= "<p style='{$p_style}'>You can now log in to download DXF drawings for our profiles.</p>";
        $content .= "<p style='{$p_style}'><a href='" . esc_url( wc_get_page_permalink( 'myaccount' ) ) . "'>Login to your Account</a></p>";
        $should_send = true;
    } 
    // LOGIC C: REVOKED
    elseif ( $prev_status === 'approved' && $new_status === 'denied' ) {
        $subject = 'Architect Access Updates - ' . $site_name;
        $heading = 'Access Updates';
        $content = "<p style='{$p_style}'>Hello " . esc_html( $customer_first_name ) . ",</p>";
        $content .= "<p style='{$p_style}'>We are writing to inform you that your Architect Access permissions have been updated.</p>";
        $content .= "<p style='{$p_style}'>We have removed your access to download DXF drawings at this time.</p>";
        $content .= "<p style='{$p_style}'>If you believe this change was made in error, please contact us.</p>";
        $should_send = true;
    } 
    // LOGIC D: DENIED
    elseif ( $new_status === 'denied' ) {
        $subject = 'Regarding your Architect Access Request - ' . $site_name;
        $heading = 'Access Request Status';
        $content = "<p style='{$p_style}'>Hello " . esc_html( $customer_first_name ) . ",</p>";
        $content .= "<p style='{$p_style}'>We have reviewed your request for Architect Access.</p>";
        $content .= "<p style='{$p_style}'>Unfortunately, we are unable to grant access to DXF downloads at this time.</p>";
        $content .= "<p style='{$p_style}'>If you have questions regarding this decision or you think the decision was made in error, please contact us by replying to this email. Thank you.</p>";
        $should_send = true;
    }

    if ( $should_send ) {
        // Generate HTML with Theme Styles
        if ( $email_obj ) {
            $header = wc_get_template_html( 'emails/email-header.php', array( 'email_heading' => $heading, 'email' => $email_obj ) );
            $footer = wc_get_template_html( 'emails/email-footer.php', array( 'email' => $email_obj ) );
            $final_message = $email_obj->style_inline( $header . $content . $footer );
        } else {
            $final_message = $mailer->wrap_message( $heading, $content );
        }

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail( $user_info->user_email, $subject, $final_message, $headers );
    }
}

// 5. Global Helper Function for checking access
function starke_has_architect_access( $user_id = null ) {
    if ( ! $user_id ) $user_id = get_current_user_id();
    if ( ! $user_id ) return false;
    if ( user_can( $user_id, 'manage_woocommerce' ) ) return true;
    return get_user_meta( $user_id, '_starke_architect_access', true ) === '1';
}

/**
 * CUSTOM SHORTCODE: Direct Fetch Search Link
 */
add_shortcode( 'starke_direct_search_link', 'starke_render_direct_search_link' );

function starke_render_direct_search_link() {
    $email = isset( $_POST['field_3'] ) ? sanitize_text_field( $_POST['field_3'] ) : '';
    $email = strip_tags( $email );
    $url = admin_url( 'users.php' ); 

    if ( ! empty( $email ) ) {
        $url = admin_url( 'users.php?s=' . urlencode( $email ) );
    }

    return '<a href="' . esc_url( $url ) . '">Search for this Customer</a>';
}

/**
 * Change default role to 'customer' only on the Admin "Add New User" screen.
 */
add_filter( 'pre_option_default_role', 'starke_set_admin_add_user_default_role' );

function starke_set_admin_add_user_default_role( $default_role ) {
    global $pagenow;
    
    // Check if we are in the admin area and on the 'user-new.php' page
    if ( is_admin() && 'user-new.php' === $pagenow ) {
        return 'customer';
    }

    return $default_role;
}

/**
 * ===============================================================
 * STARKE ACCOUNT ACCESS LEVEL (Admin User Profile Control)
 * ===============================================================
 * Adds a radio button toggle to restrict customer accounts.
 */

// 1. Display the field on the admin user profile page
add_action( 'show_user_profile', 'starke_add_account_access_level_field', 22 );
add_action( 'edit_user_profile', 'starke_add_account_access_level_field', 22 );

function starke_add_account_access_level_field( $user ) {
    // Only allow admins/shop managers to see and change this
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    // Hide this setting if the user being edited is an Administrator
    if ( in_array( 'administrator', (array) $user->roles ) ) {
        return;
    }

    // Get current access level (default to 'full' if not set)
    $access_level = get_user_meta( $user->ID, '_starke_account_access_level', true );
    if ( empty( $access_level ) ) {
        $access_level = 'full';
    }
    ?>
    <h3>Starke Account Access</h3>
    <table class="form-table">
        <tr>
            <th><label>Customer Privileges</label></th>
            <td>
                <label style="display:inline-block; margin-bottom:10px;">
                    <input type="radio" name="starke_account_access_level" value="full" <?php checked( $access_level, 'full' ); ?> />
                    <strong>Full Access</strong><br>
                    <span class="description" style="margin-left: 20px; display:block;">Standard customer privileges. Can view pricing, request samples, and place orders.</span>
                </label>
                <br>
                <label style="display:inline-block;">
                    <input type="radio" name="starke_account_access_level" value="limited" <?php checked( $access_level, 'limited' ); ?> />
                    <strong>Limited Access</strong><br>
                    <span class="description" style="color: #d63638; margin-left: 20px; display:block;">Restricted. Cannot view pricing, request samples, or place orders.</span>
                </label>
            </td>
        </tr>
    </table>
    <?php
}

// 2. Save the field when the admin updates the user profile
add_action( 'personal_options_update', 'starke_save_account_access_level_field' );
add_action( 'edit_user_profile_update', 'starke_save_account_access_level_field' );

function starke_save_account_access_level_field( $user_id ) {
    // Only allow admins/shop managers to save this
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return false;
    }

    if ( isset( $_POST['starke_account_access_level'] ) ) {
        $new_access_level = sanitize_text_field( $_POST['starke_account_access_level'] );
        $old_access_level = get_user_meta( $user_id, '_starke_account_access_level', true );

        // Update the meta value
        update_user_meta( $user_id, '_starke_account_access_level', $new_access_level );

        // FORCE LOGOUT & CLEAR CART: If they are being changed to 'limited' from 'full'
        if ( $new_access_level === 'limited' && $old_access_level !== 'limited' ) {
            
            // 1. Destroy all active WordPress login sessions for this specific user
            $session_manager = WP_Session_Tokens::get_instance( $user_id );
            $session_manager->destroy_all();

            // 2. Empty their saved WooCommerce persistent cart
            delete_user_meta( $user_id, '_woocommerce_persistent_cart_1' );
        }
    }
}

// 3. Global Helper Function (to make checking access easier across your site)
function starke_is_account_limited( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return false; // Not logged in
    }
    
    // Admins and Shop Managers are never limited
    if ( user_can( $user_id, 'manage_woocommerce' ) ) {
        return false;
    }

    return get_user_meta( $user_id, '_starke_account_access_level', true ) === 'limited';
}

// =========================================================================
// STARKE: UNIVERSAL PASSWORD SUCCESS MESSAGE
// =========================================================================
add_filter( 'woocommerce_add_success', 'starke_universal_password_success_message' );

function starke_universal_password_success_message( $message ) {
    // Intercept the specific password reset success notice and universally swap the verbiage
    if ( stripos( $message, 'password has been reset successfully' ) !== false ) {
        return __( 'Your password has been successfully set.', 'woocommerce' );
    }
    
    // Return all other success messages normally
    return $message;
}

// =========================================================================
// STARKE: DISABLE ADMIN PASSWORD CHANGE NOTIFICATIONS
// =========================================================================

// 1. Disable the admin email triggered by WooCommerce (My Account page)
add_filter( 'woocommerce_disable_password_change_notification', '__return_true' );

// 2. Disable the admin email triggered by core WordPress (Lost Password flow)
remove_action( 'after_password_reset', 'wp_password_change_notification' );

// Disable the default WordPress admin notification for new user registrations
add_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );

/**
 * Change Browser Tab Title for Password Reset Pages Server-Side
 * This prevents the "Lost Password" text from flashing before JS loads.
 */
add_filter( 'document_title_parts', 'starke_fix_password_tab_title', 999 );
function starke_fix_password_tab_title( $title ) {
    // Ensure WooCommerce functions exist and we are on the lost password endpoint
    if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'lost-password' ) ) {
        
        // Check for your custom 'show-reset-form' URL parameter first
        if ( isset( $_GET['show-reset-form'] ) ) {
            $title['title'] = 'Create Password';
        } 
        // Fallback for standard WooCommerce reset URLs
        elseif ( isset( $_GET['key'] ) && isset( $_GET['login'] ) ) {
            if ( isset( $_GET['id'] ) ) {
                $title['title'] = 'Reset Password';
            } else {
                $title['title'] = 'Create Password';
            }
        } 
        // If neither exists, they are on the generic screen
        else {
             $title['title'] = 'Lost Password';
        }
    }
    return $title;
}

/**
 * Sync the WooCommerce Page/Endpoint title with the Browser Tab
 * This ensures themes/SEO plugins don't override the tab title with "Lost Password"
 */
add_filter( 'woocommerce_endpoint_lost-password_title', 'starke_sync_password_endpoint_title', 999 );
function starke_sync_password_endpoint_title( $title ) {
    
    // Check for your custom 'show-reset-form' URL parameter
    if ( isset( $_GET['show-reset-form'] ) ) {
        return __( 'Create Password', 'woocommerce' );
    } 
    // Fallback for standard WooCommerce reset URLs
    elseif ( isset( $_GET['key'] ) && isset( $_GET['login'] ) ) {
        if ( isset( $_GET['id'] ) ) {
            return __( 'Reset Password', 'woocommerce' );
        } else {
            return __( 'Create Password', 'woocommerce' );
        }
    }
    
    return $title;
}

/**
 * ==============================================================================
 * PREVENT WOOCOMMERCE FROM SYNCING ACCOUNT DETAILS TO BILLING ADDRESS
 * ==============================================================================
 * Natively, WooCommerce attempts to keep the Billing Name/Email in sync with the 
 * Account Details. This forcefully stops that behavior by taking a snapshot 
 * before the save and restoring it immediately after.
 */
add_action( 'woocommerce_save_account_details_errors', 'starke_backup_billing_details_before_save', 10, 2 );
function starke_backup_billing_details_before_save( $errors, $user ) {
    // Store the exact database values of the billing address right before the save
    $GLOBALS['starke_old_billing_first'] = get_user_meta( $user->ID, 'billing_first_name', true );
    $GLOBALS['starke_old_billing_last']  = get_user_meta( $user->ID, 'billing_last_name', true );
    $GLOBALS['starke_old_billing_email'] = get_user_meta( $user->ID, 'billing_email', true );
}

add_action( 'woocommerce_save_account_details', 'starke_restore_billing_details_after_save', 999, 1 );
function starke_restore_billing_details_after_save( $user_id ) {
    // Restore the exact database values, overriding any WooCommerce automatic syncing
    if ( isset( $GLOBALS['starke_old_billing_first'] ) ) {
        update_user_meta( $user_id, 'billing_first_name', $GLOBALS['starke_old_billing_first'] );
    }
    if ( isset( $GLOBALS['starke_old_billing_last'] ) ) {
        update_user_meta( $user_id, 'billing_last_name', $GLOBALS['starke_old_billing_last'] );
    }
    if ( isset( $GLOBALS['starke_old_billing_email'] ) ) {
        update_user_meta( $user_id, 'billing_email', $GLOBALS['starke_old_billing_email'] );
    }
}

/**
 * ===============================================================
 * SYNC USERNAME AND EMAIL ON ADMIN "ADD NEW USER" SCREEN
 * ===============================================================
 * Mirrors the inputs in real-time so Username and Email are exactly the same.
 */
add_action( 'admin_footer-user-new.php', 'starke_sync_admin_new_user_fields' );

function starke_sync_admin_new_user_fields() {
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            var usernameField = document.getElementById('user_login');
            var emailField = document.getElementById('email');

            if (usernameField && emailField) {
                // When typing in the Username field, update the Email field
                usernameField.addEventListener('input', function() {
                    emailField.value = this.value;
                });

                // When typing in the Email field, update the Username field
                emailField.addEventListener('input', function() {
                    usernameField.value = this.value;
                });
            }
        });
    </script>
    <?php
}

/**
 * ===============================================================
 * SECURITY: BLOCK CUSTOMERS FROM THE WORDPRESS BACKEND
 * ===============================================================
 * Prevents non-admins from accessing /wp-admin/profile.php or any other backend page.
 */
add_action( 'admin_init', 'starke_block_customer_admin_access' );

function starke_block_customer_admin_access() {
    // 1. Allow standard background AJAX requests to function normally
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
        return;
    }

    // 2. Allow administrators, shop managers, or anyone who can manage WooCommerce
    if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_users' ) ) {
        return;
    }

    // 3. If they made it here, they are a customer. Kick them to the homepage instantly.
    wp_safe_redirect( home_url() );
    exit;
}

/**
 * Fix: Persist redirect_to URL across 2FA login steps using a Cookie.
 */

// 1. Capture the 'redirect_to' parameter when the login page is loaded
add_action( 'login_init', 'starke_capture_redirect_url' );
function starke_capture_redirect_url() {
    if ( isset( $_REQUEST['redirect_to'] ) ) {
        // Save the URL to a cookie for 1 hour
        setcookie( 'starke_login_redirect', $_REQUEST['redirect_to'], time() + 3600, '/', COOKIE_DOMAIN, is_ssl(), true );
    }
}

// 2. Use the cookie to redirect after 2FA (if it exists)
add_filter( 'login_redirect', 'starke_enforce_persistent_redirect', 999, 3 );
function starke_enforce_persistent_redirect( $redirect_to, $requested_redirect_to, $user ) {
    
    // Only proceed if we have our custom cookie
    if ( isset( $_COOKIE['starke_login_redirect'] ) ) {
        $saved_redirect = esc_url_raw( $_COOKIE['starke_login_redirect'] );
        
        // Clear the cookie so it doesn't affect future logins
        setcookie( 'starke_login_redirect', '', time() - 3600, '/', COOKIE_DOMAIN );

        // If the redirect is not the dashboard (which is usually what 2FA defaults to), use our saved URL
        if ( ! strpos( $saved_redirect, 'wp-admin' ) ) {
            return $saved_redirect;
        }
    }
    
    // Fallback: If no cookie or if it's the dashboard, use standard logic
    return $redirect_to;
}

/**
 * Remove the "× quantity" display next to product names on the View Order page for legacy orders.
 */
add_filter( 'woocommerce_order_item_quantity_html', 'starke_remove_legacy_order_item_quantity_html', 10, 2 );
function starke_remove_legacy_order_item_quantity_html( $html, $item ) {
	// Get the parent order object safely
	$order = $item->get_order();
	
	if ( $order ) {
		// If it's a legacy order, return an empty string to completely hide the quantity string
		if ( ! empty( $order->get_meta( '_legacy_order_id', true ) ) ) {
			return '';
		}
	}
	
	return $html;
}

/**
 * Zero-Shipping Legacy Tax Restoration
 * Pulls true line-item taxes directly from item metadata to override the $0.00 display on frontend legacy imports.
 */
add_filter( 'woocommerce_get_order_item_totals', 'starke_pull_exact_metadata_tax_row', 99, 2 );
function starke_pull_exact_metadata_tax_row( $total_rows, $order ) {
	if ( is_admin() || ! is_object( $order ) ) {
		return $total_rows;
	}

	$legacy_id = $order->get_meta( '_legacy_order_id', true );
	$shipping_total = (float) $order->get_shipping_total();

	if ( ! empty( $legacy_id ) && $shipping_total === 0.0 ) {
		$metadata_tax_sum = 0.0;
		$items = $order->get_items( [ 'line_item', 'shipping', 'fee' ] );
		
		foreach ( $items as $item ) {
			$item_taxes = $item->get_taxes()['total'] ?? [];
			foreach ( $item_taxes as $tax_amount ) {
				$metadata_tax_sum += (float) $tax_amount;
			}
		}

		if ( $metadata_tax_sum > 0 && isset( $total_rows['tax'] ) ) {
			$total_rows['tax']['value'] = wc_price( $metadata_tax_sum, array( 'currency' => $order->get_currency() ) );
		}

		// Scan and drop the custom billing metric rows by matching their exact row label signatures
		foreach ( $total_rows as $key => $row_data ) {
			if ( isset( $row_data['label'] ) ) {
				$clean_label = trim( strip_tags( $row_data['label'] ) );
				if ( strpos( $clean_label, 'Amount Paid' ) !== false || strpos( $clean_label, 'Balance Due' ) !== false ) {
					unset( $total_rows[ $key ] );
				}
			}
		}
	}

	return $total_rows;
}

/**
 * Remove the "Order Again" button from the My Account View Order page.
 */
remove_action( 'woocommerce_order_details_after_order_table', 'woocommerce_order_again_button' );

/**
 * Clean up the View Order introduction paragraph and section headings strictly for legacy orders.
 * Hides redundant theme rows and forces a clean "Legacy document details" title layout.
 */
add_action( 'woocommerce_order_details_before_order_table', 'starke_rewrite_legacy_order_view_header', 5, 1 );
function starke_rewrite_legacy_order_view_header( $order ) {
	if ( is_admin() || ! is_object( $order ) ) {
		return;
	}

	// Target only imported legacy orders
	$legacy_id = $order->get_meta( '_legacy_order_id', true );
	if ( ! empty( $legacy_id ) ) {
		
		$formatted_date = wc_format_datetime( $order->get_date_created() );
		?>
		<p class="starke-legacy-view-order-id" style="margin-bottom: 5px; font-family: var(--wp--preset--font-family--inter, sans-serif); font-size: 1.1rem; color: #121212; font-weight: 400;">
			<strong><?php esc_html_e( 'Legacy Document:', 'woocommerce' ); ?></strong> <?php echo esc_html( '#' . $legacy_id ); ?>
		</p>

		<p class="starke-legacy-view-order-header" style="margin-bottom: 25px; font-family: var(--wp--preset--font-family--inter, sans-serif); font-size: 1.1rem; color: #121212; font-weight: 400;">
			<strong><?php esc_html_e( 'Legacy Document Date:', 'woocommerce' ); ?></strong> <?php echo esc_html( $formatted_date ); ?>
		</p>

		<h2 class="starke-legacy-details-title" style="font-family: var(--wp--custom--font-family--body); color: var(--wp--preset--color--heading); font-size: var(--wp--preset--font-size--xxx-large); font-weight: var(--wp--custom--font-weight--medium); letter-spacing: -1px; line-height: var(--wp--custom--line-height--xx-small); margin-top: var(--wp--preset--spacing--medium); margin-bottom: var(--wp--preset--spacing--x-small);">
			<?php esc_html_e( 'Legacy Document Details', 'woocommerce' ); ?>
		</h2>

		<style>
			/* 1. Target and hide the native root introduction sentence */
			.woocommerce-MyAccount-content > p:first-of-type {
				display: none !important;
			}
			
			/* 2. Surgically target and hide ONLY the theme's default title class */
			h2.woocommerce-order-details__title {
				display: none !important;
			}

			/* 3. Ensure our forced custom replacement elements stay explicitly visible */
			.starke-legacy-view-order-id,
			.starke-legacy-view-order-header,
			.starke-legacy-details-title {
				display: block !important;
			}
		</style>
		<?php
	}
}