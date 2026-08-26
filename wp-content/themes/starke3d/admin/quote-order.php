<?php
use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * Plugin Name: WooCommerce Custom Order Statuses for Quotes
 * Description: Adds custom order statuses for Active, Expired, Pending, and Deleted Quotes to WooCommerce.
 * Version: 1.1
 * Author: Vern
 * Text Domain: quotes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Register custom order statuses for Quotes.
 */
function register_custom_quote_order_statuses() {
    $statuses = [
        'wc-active-quote' => [
            'label'                     => _x( 'Active Quote', 'Order status', 'quotes' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Active Quote <span class="count">(%s)</span>', 'Active Quote <span class="count">(%s)</span>', 'quotes' ),
        ],
        'wc-expired-quote' => [
            'label'                     => _x( 'Expired Quote', 'Order status', 'quotes' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Expired Quote <span class="count">(%s)</span>', 'Expired Quote <span class="count">(%s)</span>', 'quotes' ),
        ],
        'wc-pending-quote' => [
            'label'                     => _x( 'Pending Quote', 'Order status', 'quotes' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Pending Quote <span class="count">(%s)</span>', 'Pending Quote <span class="count">(%s)</span>', 'quotes' ),
        ],
        'wc-deleted-quote' => [
            'label'                     => _x( 'Deleted Quote', 'Order status', 'quotes' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Deleted Quote <span class="count">(%s)</span>', 'Deleted Quote <span class="count">(%s)</span>', 'quotes' ),
        ],
        'wc-freight-quote' => [
            'label'                     => _x( 'Freight Quote', 'Order status', 'quotes' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Freight Quote <span class="count">(%s)</span>', 'Freight Quote <span class="count">(%s)</span>', 'quotes' ),
        ],
        'wc-ordered-quote' => [
            'label'                     => _x( 'Ordered Quote', 'Order status', 'quotes' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Ordered Quote <span class="count">(%s)</span>', 'Ordered Quote <span class="count">(%s)</span>', 'quotes' ),
        ],
        'wc-profiles-needed' => [
            'label'                     => _x( 'Profiles Needed', 'Order status', 'quotes' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Profiles Needed <span class="count">(%s)</span>', 'Profiles Needed <span class="count">(%s)</span>', 'quotes' ),
        ],
        'wc-profiles-ready' => [
            'label'                     => _x( 'Profiles Ready', 'Order status', 'quotes' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Profiles Ready <span class="count">(%s)</span>', 'Profiles Ready <span class="count">(%s)</span>', 'quotes' ),
        ],
    ];

    foreach ($statuses as $status => $args) {
        register_post_status($status, $args);
    }
}
add_action( 'init', 'register_custom_quote_order_statuses' );

/**
 * Add custom statuses to the WooCommerce order status list.
 *
 * @param array $order_statuses Existing order statuses.
 * @return array Modified order statuses with custom statuses inserted sequentially.
 */
function add_custom_quote_statuses_to_order_statuses( $order_statuses ) {
    $quote_statuses = [
        'wc-active-quote' => _x( 'Active Quote', 'Order status', 'quotes' ),
        'wc-expired-quote' => _x( 'Expired Quote', 'Order status', 'quotes' ),
        'wc-pending-quote' => _x( 'Pending Quote', 'Order status', 'quotes' ),
        'wc-deleted-quote' => _x( 'Deleted Quote', 'Order status', 'quotes' ),
        'wc-freight-quote' => _x( 'Freight Quote', 'Order status', 'quotes' ),
        'wc-ordered-quote' => _x( 'Ordered Quote', 'Order status', 'quotes' ),
        'wc-profiles-needed' => _x( 'Profiles Needed', 'Order status', 'quotes' ),
        'wc-profiles-ready' => _x( 'Profiles Ready', 'Order status', 'quotes' ),
    ];

    // Place the custom statuses at the top of the order status list
    $order_statuses = array_merge($quote_statuses, $order_statuses);

    return $order_statuses;
}
add_filter( 'wc_order_statuses', 'add_custom_quote_statuses_to_order_statuses' );

/**
 * Customize the order number for all Quote statuses.
 */
function custom_quote_order_number( $order_id, $order ) {
    $quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'deleted-quote', 'freight-quote'];
    if ( in_array($order->get_status(), $quote_statuses, true) ) {
        return 'Q' . $order_id;
    }
    return $order_id;
}
add_filter( 'woocommerce_order_number', 'custom_quote_order_number', 10, 2 );

/**
 * For orders with Custom Profiles
 * Automatically updates the order status to 'Profiles Needed' if the order
 * is set to 'Processing' and contains specific product SKUs.
 *
 * This function hooks into WooCommerce's order status change action.
 *
 * @param int      $order_id   The ID of the order being changed.
 * @param string   $old_status The status the order is changing from.
 * @param string   $new_status The status the order is changing to.
 * @param WC_Order $order      The order object.
 */
add_action( 'woocommerce_order_status_changed', 'auto_update_status_for_custom_profiles_orders', 10, 4 );
function auto_update_status_for_custom_profiles_orders( $order_id, $old_status, $new_status, $order ) {
    // 1. Only proceed if the new status is 'Processing'.
    // We use the unprefixed 'processing' as that's what WooCommerce provides here.
    if ( 'processing' !== $new_status ) {
        return;
    }

    // 2. Define the specific SKUs that will trigger the status change.
    $target_skus = array( 'XBASEBOARD', 'XCASING', 'XCROWN', 'XMISCELLANEOUS' );
    $sku_found = false;

    // 3. Loop through each item in the order to check its SKU.
    foreach ( $order->get_items() as $item_id => $item ) {
        // Get the product object from the order item.
        $product = $item->get_product();

        // Check if the product exists and if its SKU is in our target list.
        if ( $product && in_array( $product->get_sku(), $target_skus ) ) {
            $sku_found = true;
            break; // A match was found, no need to check other items.
        }
    }

    // 4. If one of the target SKUs was found, update the order status.
    if ( $sku_found ) {
        // The first parameter is the new status slug (including the 'wc-' prefix).
        // The second parameter adds a note to the order, which is helpful for tracking.
        $order->update_status( 'wc-profiles-needed', 'Status automatically changed because the order contains custom profiles.' );
    }
}

add_filter( 'woocommerce_order_set_status', 'starke_prevent_backward_ach_status', 10, 2 );
function starke_prevent_backward_ach_status( $status, $order ) {
    // Only intervene if the system is trying to put the order 'on-hold'
    if ( 'on-hold' === $status ) {
        // Check if the order has already successfully made it to 'processing'
        if ( 'processing' === $order->get_status() ) {
            // Check if this is happening automatically via a webhook/REST API
            // (We want to allow human admins to manually put orders on hold if they need to)
            if ( ! is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
                // Block the downgrade by keeping the status as 'processing'
                return 'processing'; 
            }
        }
    }
    return $status;
}

/**
 * Register specific custom statuses as "Paid" for core WooCommerce logic.
 * * We INCLUDE: 'profiles-needed' because these are the 
 * original customer orders that have been paid for.
 * * We EXCLUDE: 'profiles-ready' because these are separate manufacturing 
 * entities/duplicates, and counting them would double-report revenue.
 */
function starke_mark_custom_statuses_as_paid( $statuses ) {
    $statuses[] = 'profiles-needed';
    
    return $statuses;
}
add_filter( 'woocommerce_order_is_paid_statuses', 'starke_mark_custom_statuses_as_paid' );

/**
 * Ensure specific statuses are included in WooCommerce Analytics reports.
 * Matching the logic above to ensure reports are accurate.
 */
function starke_add_statuses_to_analytics( $statuses ) {
    $statuses[] = 'profiles-needed';

    return $statuses;
}
add_filter( 'woocommerce_analytics_orders_stat_included_statuses', 'starke_add_statuses_to_analytics' );

//  ==========================================================================
//                          BACKEND-ONLY FUNCTIONS
//  ==========================================================================
if ( is_admin() ) {
    /**
     * Allow custom quote statuses to be editable in the admin.
     */
    function make_custom_quote_statuses_editable($editable, $order) {
        $quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'deleted-quote', 'freight-quote'];
        if ( in_array($order->get_status(), $quote_statuses, true) ) {
            $editable = true;
        }
        return $editable;
    }
    add_filter( 'wc_order_is_editable', 'make_custom_quote_statuses_editable', 10, 2 );

    add_action('admin_footer', 'starke_fix_orders_horizontal_scroll');
    function starke_fix_orders_horizontal_scroll() {
        echo '<style>
            /* 1. Lock the page horizontally using "clip" to protect the vertical scrollbar */
            body.wp-admin {
                overflow-x: clip !important;
            }
            #wpwrap {
                overflow-x: hidden !important;
            }
            .woocommerce-layout__header {
                max-width: 100% !important;
                box-sizing: border-box !important;
            }

            /* 2. Top Scrollbar Container */
            .starke-top-scroll-wrapper {
                width: 100%;
                box-sizing: border-box; 
                overflow-x: auto !important;
                overflow-y: hidden !important;
                margin-top: 15px;
                /* Matches the default WP table borders */
                border-left: 1px solid #c3c4c7;
                border-right: 1px solid #c3c4c7;
                border-top: 1px solid #c3c4c7;
                background: #fff;
                /* Hide by default, revealed by JS if needed */
                display: none; 
            }
            
            /* The invisible element that stretches the top scrollbar */
            .starke-top-scroll-dummy {
                height: 1px;
            }

            /* 3. Main Table Container */
            .starke-table-scroll-box {
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
                overflow-x: auto !important;
                border: 1px solid #c3c4c7; /* Default border on ALL sides */
                background: #fff;
            }
            
            /* When the top scrollbar is active, REMOVE the top border so it connects seamlessly */
            .starke-table-scroll-box.has-top-scrollbar {
                border-top: none;
            }

            /* Strip duplicate borders from the inner table so it looks seamless */
            .starke-table-scroll-box table.wp-list-table {
                border: none !important;
                margin: 0 !important;
            }
        </style>';
        
        echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                $("table.wc-orders-list-table").each(function() {
                    if (!$(this).parent().hasClass("starke-table-scroll-box")) {
                        
                        // 1. Wrap the table in its custom box
                        $(this).wrap("<div class=\'starke-table-scroll-box\'></div>");
                        var $tableBox = $(this).parent();
                        
                        // 2. Create the top scroll wrapper and the invisible stretching element
                        var $topScroll = $("<div class=\'starke-top-scroll-wrapper\'><div class=\'starke-top-scroll-dummy\'></div></div>");
                        $tableBox.before($topScroll);
                        var $dummy = $topScroll.find(".starke-top-scroll-dummy");
                        
                        // 3. Function to keep the dummy element the exact width of the real table
                        function syncWidths() {
                            var actualTableWidth = $tableBox.find("table").outerWidth();
                            var containerWidth = $tableBox.width();
                            
                            $dummy.width(actualTableWidth);
                            
                            // Only show the top scrollbar if the table is wider than the screen
                            if (actualTableWidth > containerWidth) {
                                $topScroll.show();
                                $tableBox.addClass("has-top-scrollbar"); // Removes the top border
                            } else {
                                $topScroll.hide();
                                $tableBox.removeClass("has-top-scrollbar"); // Restores the top border
                            }
                        }
                        
                        // Run it on load and whenever the browser resizes
                        syncWidths();
                        $(window).on("resize", syncWidths);
                        
                        // 4. Link the two scrollbars together so they move simultaneously
                        var isSyncingLeft = false;
                        var isSyncingRight = false;
                        
                        $topScroll.on("scroll", function() {
                            if (!isSyncingLeft) {
                                isSyncingRight = true;
                                $tableBox.scrollLeft($(this).scrollLeft());
                            }
                            isSyncingLeft = false;
                        });
                        
                        $tableBox.on("scroll", function() {
                            if (!isSyncingRight) {
                                isSyncingLeft = true;
                                $topScroll.scrollLeft($(this).scrollLeft());
                            }
                            isSyncingRight = false;
                        });
                    }
                });
            });
        </script>';
    }
    /**
     * Add custom CSS to color code custom order statuses in the admin Orders list.
     */
    function custom_order_status_colors() {
        ?>
        <style type="text/css">
            .order_status .status-active-quote {
                background: #72689b !important; /* Purple color */
                color: white !important;
            }
            .order_status .status-expired-quote {
                background: #c7483f !important; /* Red color */
                color: white !important;
            }
            .order_status .status-pending-quote {
                background:rgb(240, 138, 226) !important; /* Pink color */
                color: white !important;
            }
            .order_status .status-deleted-quote {
                background: #444444 !important; /* Dark grey color */
                color: white !important;
            }
            .order_status .status-freight-quote {
                background: #f5a623 !important; /* Orange color */
                color: white !important;
            }
            .order_status .status-ordered-quote {
                background: #0073aa !important; /* WordPress Blue */
                color: white !important;
            }
            .order_status .status-profiles-needed {
                background: #ff5722 !important; /* Deep orange */
                color: white !important;
            }
            .order_status .status-profiles-ready {
                background: #4caf50 !important; /* Amber */
                color: white !important;
            }
            /* Combined Header + Scrollbar Sticky Container */
            .starke-sticky-clone-container {
                position: fixed;
                z-index: 99;
                display: none;
                background: #fff !important;
                box-shadow: 0 4px 6px -4px rgba(0,0,0,0.15);
                height: 65px; /* Accommodation for Scrollbar height + Row height */
                overflow: hidden !important; /* Block container itself from introducing double bars */
                max-width: 100vw;
                top: 0; 
                margin: 0 !important;
                padding: 0 !important;
                border-right: 1px solid #c3c4c7 !important;
                border-left: 1px solid #c3c4c7 !important;
                box-sizing: border-box !important;
            }

            /* Isolate the cloned scrollbar layout within the fixed viewport container */
            .starke-sticky-clone-container .starke-top-scroll-wrapper {
                margin-top: 0 !important;
                border-right: none !important;
                border-left: none !important;
                border-top: none !important;
                width: 100% !important;
                display: block !important;
                overflow-x: auto !important;
            }

            /* Free the table display logic from viewport layout containment constraints */
            .starke-sticky-clone-container .starke-sticky-clone-table {
                margin-top: 0 !important;
                border: none !important;
                table-layout: fixed !important;
            }
            /* Cleanly hide the native bottom scrollbar entirely while keeping full scroll functionality */
            .starke-table-scroll-box {
                scrollbar-width: none; /* Hides scrollbar in Firefox */
            }

            .starke-table-scroll-box::-webkit-scrollbar {
                display: none; /* Hides scrollbar in Chrome, Safari, and Edge */
            }
            /* Hide the redundant bottom footer header row completely */
            .starke-table-scroll-box table.wp-list-table tfoot {
                display: none !important;
            }
        </style>
        <?php
    }
    add_action( 'admin_head', 'custom_order_status_colors' );


    /*function add_quote_button() {
        global $pagenow;
        // Only run on the WooCommerce Orders page (admin.php?page=wc-orders)
        if ( 'admin.php' === $pagenow && isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] ) {
            ?>
            <script type="text/javascript">
            (function($) {
                function insertAddQuoteButton(){
                    var addOrderBtn = $('.page-title-action').first();
                    if ( addOrderBtn.length && !$('.add-quote-btn').length ) {
                        $('<a>', {
                            href: 'admin.php?page=wc-orders&action=new&order_type=quote',
                            class: 'page-title-action add-quote-btn',
                            text: 'Add quote',
                            id: 'add-quote-button',
                            style: 'margin-left: 7px; margin-right: 10px;'
                        }).insertAfter(addOrderBtn);
                        clearInterval(checkInterval);
                    }
                }
                
                // Run immediately in case the element is already present
                insertAddQuoteButton();
                
                // Check every 100 milliseconds for the element
                var checkInterval = setInterval(insertAddQuoteButton, 100);
            })(jQuery);
            </script>
            <?php
        }
    }
    add_action( 'admin_head', 'add_quote_button' );*/

    /**
     * Removes the "Add order" and "Add quote" buttons from the WooCommerce Orders admin page.
     */
    function remove_add_order_and_quote_buttons() {
        // We only want this CSS to apply on the main WooCommerce Orders list page.
        if ( isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] ) {
            ?>
            <style type="text/css">
                /* * Hides the default WooCommerce "Add order" button (.page-title-action) 
                * and your custom "Add quote" button (.add-quote-btn).
                */
                .page-title-action, .add-quote-btn {
                    display: none !important;
                }
            </style>
            <?php
        }
    }
    add_action( 'admin_head', 'remove_add_order_and_quote_buttons' );

    /**
     * Plugin Name: WooCommerce Order and Quote Filter
     * Description: Adds a dropdown filter for Orders and Quotes before the "Dates" filter in the WooCommerce Orders page.
     * Version: 1.6
     * Author: Vernon Vaden
     * Text Domain: quotes
     */

    // Ensure WooCommerce is active
    if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    /**
     * Add custom order/quote filter dropdown BEFORE the "Dates" filter.
     */
    function add_custom_order_quote_filter() {
        if ( isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] ) {
            $selected_filter = isset($_GET['custom_order_quote_filter']) ? sanitize_text_field($_GET['custom_order_quote_filter']) : '';
            
            echo '<style>
                #custom_order_quote_filter { margin-right: 10px; }
            </style>';
            
            echo '<select name="custom_order_quote_filter" id="custom_order_quote_filter">';
                echo '<option value="" ' . selected( $selected_filter, '', false ) . '>' . esc_html__( 'Orders/Quotes', 'quotes' ) . '</option>';
                echo '<option value="orders" ' . selected( $selected_filter, 'orders', false ) . '>' . esc_html__( 'Orders', 'quotes' ) . '</option>';
                echo '<option value="quotes" ' . selected( $selected_filter, 'quotes', false ) . '>' . esc_html__( 'Quotes', 'quotes' ) . '</option>';
            echo '</select>';
        }
    }
    add_action( 'woocommerce_order_list_table_restrict_manage_orders', 'add_custom_order_quote_filter', 5 ); // Priority 5 ensures it runs before the "Dates" filter

    /**
     * Modify order query based on dropdown selection.
     */
    function filter_orders_by_status( $args ) {
        if ( isset( $_GET['custom_order_quote_filter'] ) ) {
            $filter_option = sanitize_text_field( $_GET['custom_order_quote_filter'] );

            if ( $filter_option === 'orders' ) {
                $args['status'] = array( 'wc-pending', 'wc-on-hold', 'wc-processing', 'wc-profiles-needed', 'wc-profiles-ready' );
            } elseif ( $filter_option === 'quotes' ) {
                // Fixed syntax errors and added profile statuses
                $args['status'] = array( 
                    'wc-active-quote', 
                    'wc-expired-quote', 
                    'wc-pending-quote', 
                    'wc-deleted-quote', 
                    'wc-freight-quote', 
                    'wc-ordered-quote'
                );
            }
        }

        return $args;
    }
    add_filter( 'woocommerce_order_query_args', 'filter_orders_by_status' );

    /**
     * Adds JavaScript to the admin footer ONLY on the WooCommerce Orders page.
     * This combines the logic from 'reset_order_status_filter_js' and 'reorder_order_filters'
     * and wraps it in a screen check to prevent conflicts on other admin pages.
     */
    function starke_wc_orders_admin_footer_scripts() {
        // Get the current screen
        $screen = get_current_screen();
        // The screen ID for the HPOS orders page is 'woocommerce_page_wc-orders'
        if ( $screen && 'woocommerce_page_wc-orders' === $screen->id ) {
            /**
             * Force refresh when the dropdown is changed & reset the order status filter.
             */
            function reset_order_status_filter_js() {
                if ( isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] ) {
                    ?>
                    <script type="text/javascript">
                    (function($) {
                        $(document).ready(function() {
                            var $dropdown = $('#custom_order_quote_filter');
                            var orderStatusFilter = $('ul.subsubsub a'); // Order status buttons
                            
                            // When dropdown changes, submit form
                            $dropdown.on('change', function() {
                                $(this).closest('form').submit();
                            });

                            // When a filter is active, visually reset order status buttons
                            if ($dropdown.val()) {
                                orderStatusFilter.removeClass('current'); // Remove active status from others
                                
                                orderStatusFilter.each(function() {
                                    if ($(this).text().trim() === 'All') {
                                        $(this).addClass('current'); // Highlight "All"
                                    }
                                });
                            }
                        });
                    })(jQuery);
                    </script>
                    <?php
                }
            }
            reset_order_status_filter_js();

            /**
             * Use jQuery to reorder the filter dropdowns and move the custom filter before the "Dates" filter.
             */
            function reorder_order_filters() {
                ?>
                <script type="text/javascript">
                    (function($) {
                        var observer = new MutationObserver(function(mutations) {
                            mutations.forEach(function(mutation) {
                                // Check if the custom filter and date filter are both available
                                var customFilter = $('#custom_order_quote_filter');
                                var dateFilter = $('select[name="m"]'); // The "Dates" filter

                                if (customFilter.length && dateFilter.length) {
                                    // If both filters exist, move the custom filter before the date filter
                                    customFilter.insertBefore(dateFilter);

                                    // Stop observing after the move is done to prevent unnecessary checks
                                    observer.disconnect();
                                }
                            });
                        });

                        // Start observing the document body for added/removed nodes
                        observer.observe(document.body, {
                            childList: true,  // Observe direct children
                            subtree: true      // Observe all descendants (not just direct children)
                        });
                    })(jQuery);
                </script>
                <?php
            }
            reorder_order_filters();

            function starke_sticky_table_header_js() {
    ?>
    <script type="text/javascript">
    (function() {
        var initialized = false;
        function initSticky() {
            var scrollBox = document.querySelector('.starke-table-scroll-box');
            var topBar = document.querySelector('.woocommerce-layout__header');
            var originalScrollbar = document.querySelector('.starke-top-scroll-wrapper');
            if (!scrollBox || !topBar || !originalScrollbar || initialized) return;
            initialized = true;

            var originalTable = scrollBox.querySelector('table.wp-list-table');
            var originalThead = originalTable ? originalTable.querySelector('thead') : null;
            if (!originalThead) return;

            var stickyContainer = document.createElement('div');
            stickyContainer.className = 'starke-sticky-clone-container';

            // 1. Append Cloned Scrollbar
            var cloneScrollbar = originalScrollbar.cloneNode(true);
            stickyContainer.appendChild(cloneScrollbar);
            
            // 2. Append Cloned Table Header
            var cloneTable = document.createElement('table');
            cloneTable.className = originalTable.className + ' starke-sticky-clone-table';
            cloneTable.style.width = originalTable.offsetWidth + 'px';
            cloneTable.appendChild(originalThead.cloneNode(true));
            stickyContainer.appendChild(cloneTable);

            document.body.appendChild(stickyContainer);

            var cloneScroll = stickyContainer.querySelector('.starke-top-scroll-wrapper');

            // 1. Sync Vertical Position & Initial Arrival Offset
            function syncPosition() {
                var topBarBottom = topBar.getBoundingClientRect().bottom;
                var boxRect = scrollBox.getBoundingClientRect();
                
                // Determine if the table currently needs horizontal scrolling
                var hasHorizontalScroll = originalTable.offsetWidth > scrollBox.clientWidth;
                
                // Set the baseline boundary for when the clone should activate
                var triggerRect = hasHorizontalScroll ? originalScrollbar.getBoundingClientRect() : originalThead.getBoundingClientRect();
                
                // Adjust the height of the container container depending on if the scrollbar is visible
                stickyContainer.style.height = hasHorizontalScroll ? '65px' : '50px';
                
                if (cloneScroll) {
                    cloneScroll.style.display = hasHorizontalScroll ? 'block' : 'none';
                }

                // TRIGGER: Only display the sticky header if the element has scrolled underneath the top admin bar
                if (triggerRect.top <= topBarBottom && boxRect.bottom > topBarBottom + 40) {
                    stickyContainer.style.display = 'block';
                    stickyContainer.style.top = topBarBottom + 'px';
                    stickyContainer.style.left = boxRect.left + 'px';
                    stickyContainer.style.width = boxRect.width + 'px';
                    stickyContainer.style.marginTop = '0px';

                    // Sync horizontal alignment natively if scrolling is active
                    if (hasHorizontalScroll) {
                        if (cloneScroll) {
                            cloneScroll.scrollLeft = scrollBox.scrollLeft;
                        }
                        cloneTable.style.marginLeft = -scrollBox.scrollLeft + 'px';
                    } else {
                        cloneTable.style.marginLeft = '0px';
                    }
                } else {
                    stickyContainer.style.display = 'none';
                }
            }

            // 2. HORIZONTAL SYNC: Main Table Scroll -> Cloned Header & Cloned Scrollbar
            scrollBox.addEventListener('scroll', function() {
                if (cloneScroll) {
                    cloneScroll.scrollLeft = scrollBox.scrollLeft;
                }
                cloneTable.style.marginLeft = -scrollBox.scrollLeft + 'px';
            }, { passive: true });

            // 3. HORIZONTAL SYNC: Cloned Scrollbar -> Main Table (Moves table when grabbed)
            if (cloneScroll) {
                cloneScroll.addEventListener('scroll', function() {
                    scrollBox.scrollLeft = cloneScroll.scrollLeft;
                }, { passive: true });
            }
            
            window.addEventListener('scroll', syncPosition, { passive: true });
            window.addEventListener('resize', syncPosition, { passive: true });
            syncPosition(); 
        }
        var observer = new MutationObserver(initSticky);
        observer.observe(document.body, { childList: true, subtree: true });
    })();
    </script>
    <?php
}
            starke_sticky_table_header_js();
        }
    }
    // Add the new, combined function to the admin footer
    add_action( 'admin_footer', 'starke_wc_orders_admin_footer_scripts' );


    // Adding custom statuses to admin order list bulk dropdown -- START
    function get_my_custom_order_statuses() {
        return [
            'active-quote'    => __( 'Change status to active quote', 'woocommerce' ),
            'expired-quote'   => __( 'Change status to expired quote', 'woocommerce' ),
            'pending-quote'   => __( 'Change status to pending quote', 'woocommerce' ),
            'deleted-quote'   => __( 'Change status to deleted quote', 'woocommerce' ),
            'freight-quote'   => __( 'Change status to freight quote', 'woocommerce' ),
            'ordered-quote'     => __( 'Change status to ordered quote', 'woocommerce' ),
            'profiles-needed'   => __( 'Change status to profiles needed', 'woocommerce' ),
            'profiles-ready'  => __( 'Change status to profiles ready', 'woocommerce' )
        ];
    }

    add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'add_bulk_actions_for_custom_statuses' );
    function add_bulk_actions_for_custom_statuses( $bulk_actions ) {
        foreach ( get_my_custom_order_statuses() as $status_slug => $label ) {
            $bulk_actions[ 'mark_' . $status_slug ] = $label;
        }

        $desired_order = [
            'mark_active-quote',
            'mark_pending-quote',
            'mark_freight-quote',
            'mark_ordered-quote',
            'mark_expired-quote',
            'mark_deleted-quote',
            'mark_processing',
            'mark_on-hold',
            'mark_profiles-needed',
            'mark_profiles-ready',
            'mark_completed',
            'mark_cancelled',
            'mark_refunded',
            'mark_failed',
            'trash'
        ];

        $ordered = [];
        foreach ( $desired_order as $key ) {
            if ( isset( $bulk_actions[ $key ] ) ) {
                $ordered[ $key ] = $bulk_actions[ $key ];
            }
        }
        return $ordered;
    }

    add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'handle_bulk_actions_for_custom_statuses', 10, 3 );
    function handle_bulk_actions_for_custom_statuses( $redirect_to, $action, $order_ids ) {
        $custom_statuses = get_my_custom_order_statuses();

        foreach ( $custom_statuses as $status_slug => $label ) {
            if ( $action === 'mark_' . $status_slug ) {
                foreach ( $order_ids as $order_id ) {
                    $order = wc_get_order( $order_id );
                    if ( $order ) {
                        $order->update_status( $status_slug, __( 'Order status changed via bulk action.', 'woocommerce' ) );
                    }
                }

                $redirect_to = add_query_arg( array(
                    'bulk_' . $status_slug . '_updated' => count( $order_ids ),
                ), $redirect_to );
            }
        }

        return $redirect_to;
    }

    /**
     * Customizes order list views:
     * 1. Removes 'wc-checkout-draft' from the view list.
     * 2. Recalculates and updates the 'wc-pending' count to exclude balance invoices.
    */
    add_filter( 'views_woocommerce_page_wc-orders', 'remove_draft_status_view_and_update_pending_payment_count' );
    function remove_draft_status_view_and_update_pending_payment_count( $views ) {
        // Check if the 'wc-checkout-draft' view exists and remove it.
        if ( isset( $views['wc-checkout-draft'] ) ) {
            unset( $views['wc-checkout-draft'] );
        }

        // --- FIXED: Direct database lookup to completely bypass array query interference ---
        global $wpdb;
        
        // Count standard orders with status 'wc-pending' that do NOT have the balance invoice meta key
        $real_pending_count = (int) $wpdb->get_var( "
            SELECT COUNT(DISTINCT orders.id) 
            FROM {$wpdb->prefix}wc_orders AS orders
            LEFT JOIN {$wpdb->prefix}wc_orders_meta AS meta 
                ON orders.id = meta.order_id AND meta.meta_key = '_starke_is_balance_invoice'
            WHERE orders.status = 'wc-pending' 
            AND meta.meta_value IS NULL
        " );

        // Target only the native 'wc-pending' key explicitly
        if ( isset( $views['wc-pending'] ) ) {
            // Matches your exact single-quote markup layout: <span class='count'>(44)</span>[cite: 1]
            $views['wc-pending'] = preg_replace(
                '/<span class=\'count\'>\(.*?\)<\/span>/',
                '<span class=\'count\'>(' . $real_pending_count . ')</span>',
                $views['wc-pending']
            );
        }

        return $views;
    }
    // Adding custom statuses to admin order list bulk dropdown -- END




    // Creates custom 'User Name' column in WooCommerce Orders admin list -- START
    /*
    *  Add a custom column for User Name to the WooCommerce Orders admin list
    */
    function ts_add_user_name_column($columns) {
        // Add a new column after the "Order Total" column
        $columns['user_name'] = 'User Name'; // The label for the new column

        // Return the updated columns array
        return $columns;
    }
    add_filter('manage_woocommerce_page_wc-orders_columns', 'ts_add_user_name_column');

    // Display the User Name in the custom column
    function ts_display_user_name_column($column, $post_id) {
        // Check if the column is the custom one we added for User Name
        if ('user_name' === $column) {
            // Get the order object
            $order = wc_get_order($post_id);

            // Get the user ID associated with this order
            $user_id = $order->get_user_id();

            // If there's a user associated with the order, get the user name (username)
            if ($user_id > 0) {
                $user = get_userdata($user_id); // Get the user object
                if ($user) {
                    echo esc_html($user->user_login); // Display the username
                }
            } else {
                echo 'Guest'; // If the order has no associated user (guest checkout)
            }
        }
    }
    add_action('manage_woocommerce_page_wc-orders_custom_column', 'ts_display_user_name_column', 10, 2);

    // Make the 'User Name' column sortable
    function ts_make_user_name_column_sortable($sortable_columns) {
        $sortable_columns['user_name'] = 'user_name'; // 'user_name' is the column ID
        return $sortable_columns;
    }
    add_filter('manage_woocommerce_page_wc-orders_sortable_columns', 'ts_make_user_name_column_sortable');

    // Set the sort logic for the 'User Name' column
    add_filter( 'woocommerce_order_query_args', 'order_list_user_name_column_sorter', 10, 1 );
    function order_list_user_name_column_sorter( $query_args ) {
        // Check if sorting by 'user_name' is requested via URL parameter
        // Using $_GET directly is common here as WC List Table generates links with these params.
        if ( isset( $_GET['orderby'] ) && $_GET['orderby'] === 'user_name' ) {
            // Set the meta key to sort by
            $query_args['meta_key'] = '_customer_user_name';
            $query_args['orderby'] = 'meta_value';

            // Set the 'order' direction (ASC or DESC).
            $query_args['order'] = isset( $_GET['order'] ) ? strtoupper( $_GET['order'] ) : 'ASC';
            // Ensure it's either ASC or DESC
            if ( ! in_array( $query_args['order'], ['ASC', 'DESC'] ) ) {
                $query_args['order'] = 'ASC';
            }
        }
        return $query_args;
    }
    // Creates custom 'User Name' column in WooCommerce Orders admin list -- END

    // Creates custom 'Author' column in WooCommerce Orders admin list -- START
    /*
    *  Add a custom column for Author to the WooCommerce Orders admin list
    */
    function ts_add_author_column($columns) {
        // Add a new column after the "User Name" column
        $columns['author_name'] = 'Author'; // The label for the new column

        // Return the updated columns array
        return $columns;
    }
    add_filter('manage_woocommerce_page_wc-orders_columns', 'ts_add_author_column');

    // Display the Author in the custom column
    function ts_display_author_column($column, $post_id) {
        // Check if the column is the custom one we added for Author
        if ('author_name' === $column) {
            // Get the order object
            $order = wc_get_order($post_id);

            // Get the meta value for the creator's username from the order object
            $author_name = $order->get_meta('_creator_user_name');

            // Display the author's username or a fallback message
            if (!empty($author_name)) {
                echo esc_html($author_name); // Display the author's username
            } else {
                echo 'N/A'; // If no author is found
            }
        }
    }
    add_action('manage_woocommerce_page_wc-orders_custom_column', 'ts_display_author_column', 10, 2);

    // Make the 'Author' column sortable
    function ts_make_author_column_sortable($sortable_columns) {
        $sortable_columns['author_name'] = 'author_name'; // 'author_name' is the column ID
        return $sortable_columns;
    }
    add_filter('manage_woocommerce_page_wc-orders_sortable_columns', 'ts_make_author_column_sortable');

    // Set the sort logic for the 'Author' column
    add_filter('woocommerce_order_query_args', 'order_list_author_column_sorter', 10, 1);
    function order_list_author_column_sorter($query_args) {
        // Check if sorting by 'author_name' is requested via URL parameter
        if (isset($_GET['orderby']) && $_GET['orderby'] === 'author_name') {
            // Set the meta key to sort by
            $query_args['meta_key'] = '_creator_user_name';
            $query_args['orderby'] = 'meta_value';

            // Set the 'order' direction (ASC or DESC)
            $query_args['order'] = isset($_GET['order']) ? strtoupper($_GET['order']) : 'ASC';
            // Ensure it's either ASC or DESC
            if (!in_array($query_args['order'], ['ASC', 'DESC'])) {
                $query_args['order'] = 'ASC';
            }
        }
        return $query_args;
    }
    // Creates custom 'Author' column in WooCommerce Orders admin list -- END

    // Creates custom 'Starke ID' column in WooCommerce Orders admin list -- START
    /*
    *  Add a custom column for Starke ID to the WooCommerce Orders admin list
    */
    function ts_add_starke_id_column($columns) {

        // 1. Define your custom column
        $starke_column_key = 'starke_id'; // Your custom column key
        $starke_column_title = 'Starke ID'; // Your custom column title

        // 2. Create a new array to build the desired order
        $reordered_columns = array();

        // 3. Add the checkbox column first, if it exists
        if (isset($columns['cb'])) {
            $reordered_columns['cb'] = $columns['cb'];
            unset($columns['cb']); // Remove checkbox from the original array
        }

        // 4. Add your custom 'Starke ID' column second
        $reordered_columns[$starke_column_key] = $starke_column_title;

        // 5. Add the rest of the original columns after your custom one
        $reordered_columns = array_merge($reordered_columns, $columns);

        // Optional: Remove the original 'order_number' column if 'starke_id' replaces its function
        // This is only necessary if you want to remove the default order number column
        //unset($reordered_columns['order_number']);

        return $reordered_columns;
    }
    add_filter('manage_woocommerce_page_wc-orders_columns', 'ts_add_starke_id_column');

    add_action('manage_woocommerce_page_wc-orders_custom_column', 'display_starke_order_number_column_with_prefix', 10, 2);
    function display_starke_order_number_column_with_prefix($column_id, $order) {
        // Check if it's our custom column
        if ('starke_id' === $column_id) { // Use the actual ID of your column

            // Ensure we have the WC_Order object (HPOS passes object, older versions might pass ID)
            if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
            }

            if (!$order) {
                echo 'N/A';
                return;
            }

            // Get the stored Starke Number (without prefix)
            $starke_number = $order->get_meta('_starke_order_number', true);

            // Get Billing Name for suffix
            $first_name = $order->get_billing_first_name();
            $last_name = $order->get_billing_last_name();
            $name_suffix = '';
            $full_name = trim($first_name . ' ' . $last_name);
            // Add suffix only if a name exists
            if (!empty($full_name)) {
                // Escape the name here before adding parentheses
                $name_suffix = ' ' . esc_html($full_name);
            }

            // Display logic
            if (!empty($starke_number)) {
                // Get the order status (raw status key like 'active-quote')
                $status = $order->get_status();
                $quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'deleted-quote', 'freight-quote', 'ordered-quote']; // Define your quote statuses

                // Determine the prefix
                $prefix = '#'; // Default prefix for regular orders
                if (in_array($status, $quote_statuses)) {
                    $prefix = '#Q'; // Prefix for quote statuses
                }

                // Define the color for the styled part
                $style_color = '#0073aa'; // Example: WordPress blue

                // Echo the styled prefix/number and the plain suffix
                // Use printf for cleaner formatting and escaping
                printf(
                    '<strong style="color: %s;">%s%s%s</strong>',
                    esc_attr($style_color),      // Escape color for style attribute
                    esc_html($prefix),           // Escape prefix
                    esc_html($starke_number),    // Escape number
                    $name_suffix                 // Suffix is already escaped or empty
                );

            } else {
                // Handle cases where the Starke number might be missing
                echo 'N/A';
                // If you wanted to show the name suffix even when number is missing:
                // echo 'N/A' . $name_suffix;
            }
        }
    }

    // Make the 'Starke ID' column sortable
    function ts_make_starke_id_column_sortable($sortable_columns) {
        $sortable_columns['starke_id'] = 'starke_id'; // 'starke_id' is the column ID
        return $sortable_columns;
    }
    add_filter('manage_woocommerce_page_wc-orders_sortable_columns', 'ts_make_starke_id_column_sortable');

    // Set the sort logic for the 'Starke ID' column
    add_filter('woocommerce_order_query_args', 'order_list_starke_id_column_sorter', 10, 1);
    function order_list_starke_id_column_sorter($query_args) {
        // Check if sorting by 'starke_id' is requested via URL parameter
        if (isset($_GET['orderby']) && $_GET['orderby'] === 'starke_id') {
            // Set the meta key to sort by
            $query_args['meta_key'] = '_starke_order_number_sortable';
            $query_args['orderby'] = 'meta_value_num';

            // Set the 'order' direction (ASC or DESC)
            $query_args['order'] = isset($_GET['order']) ? strtoupper($_GET['order']) : 'ASC';
            // Ensure it's either ASC or DESC
            if (!in_array($query_args['order'], ['ASC', 'DESC'])) {
                $query_args['order'] = 'ASC';
            }
        }
        return $query_args;
    }
    // Creates custom 'Starke ID' column in WooCommerce Orders admin list -- END

    // Creates custom 'Open Order/Quote as Customer' column in WooCommerce Orders admin list -- START
    /*
    *  Add a custom column for Open Quote/Order as Customer to the WooCommerce Orders admin list
    */
    function ts_add_edit_order_quote_column($columns) {
        // Add a new column after the "Order Total" column
        $columns['edit'] = 'Open Order/Quote'; // The label for the new column 'Open Order/Quote'

        // Return the updated columns array
        return $columns;
    }
    add_filter('manage_woocommerce_page_wc-orders_columns', 'ts_add_edit_order_quote_column');

    /**
     * Add custom CSS to adjust the width and alignment of custom columns.
     */
    function ts_custom_admin_column_width_css() {
        $screen = get_current_screen();

        if ( $screen && 'woocommerce_page_wc-orders' === $screen->id ) {
            ?>
            <style type="text/css">
                /* Open Order/Quote Column */
                .wp-list-table th.column-edit,
                .wp-list-table td.column-edit {
                    width: 120px; 
                }

                /* FORCE Override WooCommerce Core '8ch' limit for Order Total */
                .woocommerce_page_wc-orders .wp-list-table th.column-order_total,
                .woocommerce_page_wc-orders .wp-list-table td.column-order_total,
                .post-type-shop_order .wp-list-table th.column-order_total,
                .post-type-shop_order .wp-list-table td.column-order_total {
                    width: 90px !important;         /* Hard fixed width prevents collapsing AND beats the 8ch rule */
                    white-space: nowrap !important;  /* Completely stops wrapping */
                }

                /* Outstanding Amount Column - Centered & No Wrap */
                .wp-list-table th.column-outstanding_amount,
                .wp-list-table td.column-outstanding_amount {
                    width: 155px !important; 
                    white-space: nowrap !important; 
                    text-align: center !important;   /* Centers the header and the dollar amounts */
                }

                /* Amount Due Column - No Wrap */
                .wp-list-table th.column-amount_due,
                .wp-list-table td.column-amount_due {
                    width: 120px !important; 
                    white-space: nowrap !important; 
                }
            </style>
            <?php
        }
    }
    // Hook the CSS function into the admin head
    add_action( 'admin_head', 'ts_custom_admin_column_width_css' );



    // Creates custom 'Open Order/Quote as Customer' column in WooCommerce Orders admin list -- END

    // Adds new searchable fields to the Orders/Quotes Search bar dropdown list  -- START
    /**
     * Add the order meta keys to the list of fields searched
     * in the WooCommerce admin Orders list search box (HPOS compatible).
     * This affects the meta keys to search ONLY for the 'All' option in the search filter dropdown
     *
     * @param array $search_keys Existing array of meta keys to search.
     * @return array Modified array including the Starke number key.
     */
    add_filter('woocommerce_order_table_search_query_meta_keys', 'add_order_meta_to_order_search');
    function add_order_meta_to_order_search($search_keys) {
        // Add the meta key that stores the respective value
        $search_keys[] = '_starke_order_number';
        $search_keys[] = '_customer_user_name';
        $search_keys[] = '_po_number_job_name';
        $search_keys[] = '_samples_address_po_number_job_name';
        $search_keys[] = '_legacy_order_id';
        // Ensure keys are unique in case it was added elsewhere
        return array_unique($search_keys);
    }

    /**
     * Add "Starke ID" and "User Name" to the available search filter options dropdown
     * on the HPOS Orders list page.
     *
     * @param array $options Existing options [value => label].
     * @return array Modified options.
     */
    add_filter('woocommerce_hpos_admin_search_filters', 'add_custom_search_filters');
    function add_custom_search_filters($options) {
        // Define our custom options
        $custom_options = [
            'starke_id' => __('Starke ID', 'vern_shipping_block'),
            'user_name' => __('User Name', 'vern_shipping_block'),
            'po_job_name' => __('PO / Job Name', 'vern_shipping_block'),
            'legacy_id' => __('Legacy Order ID', 'vern_shipping_block'),
        ];

        // Insert custom options before 'All' if 'All' exists
        if (isset($options['all'])) {
            $all_option = $options['all']; // Save 'All' option
            unset($options['all']); // Remove 'All' temporarily
            // Merge custom options and then add 'All' back at the end
            $options = array_merge($options, $custom_options);
            $options['all'] = $all_option;
        } else {
            // If 'All' wasn't there, just merge them at the end
            $options = array_merge($options, $custom_options);
        }

        return $options;
    }

    /**
     * Modify the order query args based on the selected search scope and search term.
     * Handles searching by Starke ID or User Name specifically.
     *
     * @param array $query_args Existing query arguments.
     * @return array Modified query arguments.
     */
    add_filter('woocommerce_order_query_args', 'handle_custom_search_scope', 20);
    function handle_custom_search_scope($query_args) {

        // Use 'search-filter' based on the name attribute in ListTable::search_filter()
        $scope_param_name = 'search-filter';
        // Define the keys for our custom options
        $starke_id_option_value = 'starke_id';
        $user_name_option_value = 'user_name';
        $po_job_option    = 'po_job_name';
        $legacy_id_option = 'legacy_id';

        // Check the selected scope and if a search term was entered
        $search_scope = isset($_GET[$scope_param_name]) ? sanitize_text_field($_GET[$scope_param_name]) : '';
        $search_term = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $logger_exists = function_exists('wc_get_logger'); // Cache check

        // Prepare meta query array
        $meta_query = $query_args['meta_query'] ?? [];
        if (!is_array($meta_query)) { $meta_query = []; }

        $specific_search_applied = false;

        // --- Handle Starke ID Search ---
        if ($search_scope === $starke_id_option_value && !empty($search_term)) {
            $meta_query[] = [
                'key'     => '_starke_order_number', // Starke number meta key
                'value'   => $search_term,
                'compare' => 'LIKE', // Use LIKE for partial match as requested
            ];
            $specific_search_applied = true;
            if ($logger_exists) wc_get_logger()->debug("Custom Search Scope: Searching ONLY by _starke_order_number LIKE '{$search_term}'", ['source' => 'starke-search']);
        }
        // --- Handle User Name Search ---
        elseif ($search_scope === $user_name_option_value && !empty($search_term)) {
            $meta_query[] = [
                'key'     => '_customer_user_name', // Customer username meta key
                'value'   => $search_term,
                'compare' => 'LIKE', // Use LIKE for partial name match
            ];
            $specific_search_applied = true;
            if ($logger_exists) wc_get_logger()->debug("Custom Search Scope: Searching ONLY by _customer_user_name LIKE '{$search_term}'", ['source' => 'starke-search']);
        }
        // --- UPDATED: Search Logic for PO / Job Name ---
        elseif ($search_scope === $po_job_option && !empty($search_term)) {
            // Use an OR relation to search both meta fields simultaneously
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key'     => '_po_number_job_name',
                    'value'   => $search_term,
                    'compare' => 'LIKE',
                ],
                [
                    'key'     => '_samples_address_po_number_job_name',
                    'value'   => $search_term,
                    'compare' => 'LIKE',
                ],
            ];
            $specific_search_applied = true;
        }
        // --- Handle Legacy Order ID Search ---
        elseif ($search_scope === $legacy_id_option && !empty($search_term)) {
            $meta_query[] = [
                'key'     => '_legacy_order_id', 
                'value'   => $search_term,
                'compare' => 'LIKE', 
            ];
            $specific_search_applied = true;
        }

        // If a specific search scope was applied, modify the query args
        if ($specific_search_applied) {
            // Add relation if multiple meta queries might exist from other sources
            // $meta_query['relation'] = 'AND';
            $query_args['meta_query'] = $meta_query;

            // IMPORTANT: Remove the default search parameter ('s') to prevent
            // searching default fields when a specific scope is chosen.
            unset($query_args['s']);
        }
        // If scope is not one of ours or no search term, the query runs as normal.
        // The 'woocommerce_order_table_search_query_meta_keys' filter ensures
        // _starke_order_number and _customer_user_name are included in the default 'Search All Fields' search.

        return $query_args;
    }
    // Adds new searchable fields to the Orders/Quotes Search bar dropdown list  -- END



    /**
     * Change the 'Orders' column title to 'Orders/Quotes' on the WooCommerce Orders page (HPOS system).
     *
     * @param array $columns Existing order columns.
     * @return array Modified order columns.
     */
    function custom_change_orders_column_title_hpos( $columns ) {
        // Check if the 'Orders' column exists and modify its title.
        if ( isset( $columns['order_number'] ) ) {
            $columns['order_number'] = __( 'Order/Quote ID', 'quotes' );
        }
        return $columns;
    }
    add_filter( 'manage_woocommerce_page_wc-orders_columns', 'custom_change_orders_column_title_hpos' );



    /**
     * Set the default order status to 'Active Quote' when the 'Add Quote' button is clicked.
     */
    /*function custom_set_default_order_status_for_quotes( $order_data, $order ) {
        if ( isset( $_GET['order_type'] ) && 'quote' === $_GET['order_type'] ) {
            // Set the default status to 'Active Quote' for new quotes
            $order_data['status'] = 'wc-active-quote'; // 'wc-active-quote' is the status for active quotes
        }
        return $order_data;
    }
    add_filter( 'woocommerce_new_order_data', 'custom_set_default_order_status_for_quotes', 10, 2 );*/


    /**
     * Modify the 'Add new order' and 'Edit order' labels for the shop_order post type.
     */
    function customize_woocommerce_order_page_labels( $args ) {
        if ( isset( $_GET['page']) && 'wc-orders' === $_GET['page'] ) {
            $labels = $args['labels'];
            $labels['name'] = 'Orders/Quotes'; // Changes "Orders" to "Orders/Quotes"
            $labels['menu_name'] = 'Orders/Quotes'; // Changes "Orders" in the admin menu

            if ( isset( $_GET['action'] ) ) { 
                if ( isset( $_GET['order_type'] ) && 'new' === $_GET['action'] && 'quote' === $_GET['order_type'] ) {
                    $labels['add_new_item'] = __('Add new quote', 'quotes');      // For "Add New" button text and similar
                } else {
                    $labels['add_new_item'] = __('Add new order', 'quotes');      // For "Add New" button text and similar
                }
            }

            $args['labels'] = $labels; // Update the labels in the args array
        }
        return $args;
    }
    add_filter( 'woocommerce_register_post_type_shop_order', 'customize_woocommerce_order_page_labels' );

    // Adds an existing order meta data field as a searchable field for the Orders admin 'Search orders' filter
    function filter_woocommerce_shop_order_search_fields( $search_fields ) {
        // The desired meta key
        $meta_key = '_user_nickname';

        $search_fields[] = $meta_key;

        return $search_fields;
    }
    add_filter( 'woocommerce_order_table_search_query_meta_keys', 'filter_woocommerce_shop_order_search_fields', 10, 1 );

    // Metabox content
    function custom_metabox_content( $object ) {
        // Get the WC_Order object
        $order = is_a( $object, 'WP_Post' ) ? wc_get_order( $object->ID ) : $object;

        echo '<p>Number (ID): '.$order->get_order_number().'<p>';
        echo '<p>Order status: '.$order->get_status().'<p>';
        echo '<a>Test button</a>';
    }

    // Change Edit Order page headings based on order status (HPOS compatible)
    function modify_edit_page_headings() {
        
        // Ensure we are on the edit order page (HPOS compatible check)
        if ( isset( $_GET['page']) && 'wc-orders' === $_GET['page'] && isset( $_GET['action'] ) && 'edit' === $_GET['action'] && isset($_GET['id']) ) {
            
            // Retrieve the order ID from the WooCommerce query string
            $order_id = intval($_GET['id']); 
            $order = wc_get_order($order_id);
            
            if (!$order) {
                return; // Exit if the order is not found
            }

            // --- DETERMINING DISPLAY ID ---
            // Default to the database ID
            $display_id = $order_id; 
            
            // Check for the Starke Order Number
            $starke_num = $order->get_meta( '_starke_order_number', true );
            
            // If a Starke Number exists, use it.
            // This applies to ALL orders (Regular, Quotes, and Balance Invoices).
            // Balance Invoices generate their own sequential Starke ID on creation, so this will show that specific ID.
            if ( ! empty( $starke_num ) ) {
                $display_id = $starke_num;
            }

            // Get the order status (without the "wc-" prefix)
            $order_status = str_replace('wc-', '', $order->get_status());

            // Define Quote Statuses for logic
            $quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'deleted-quote', 'freight-quote', 'ordered-quote'];

            // Set heading text based on order status
            $editPageTitle_text = in_array($order_status, $quote_statuses) ? 'Edit quote' : 'Edit order';
            
            // --- STARKE: Check for Legacy Order ID ---
            $legacy_id = $order->get_meta( '_legacy_order_id', true );
            
            // Wrap the suffix in a styled span to make it darker gray and less pronounced
            $legacy_suffix = ! empty( $legacy_id ) ? ' <span style="color: #777; font-weight: normal; font-size: 0.9em;">(Legacy Order #' . esc_html( $legacy_id ) . ')</span>' : '';
            
            // Construct Heading: "Quote #Q1366 details" or "Order #1366 details (Legacy Order #888)"
            $prefix = in_array($order_status, $quote_statuses) ? 'Quote #Q' : 'Order #';
            
            // Move the legacy suffix to the very end of the string
            $orderDataHeading_text = $prefix . esc_html( $display_id ) . ' details' . $legacy_suffix;
            
            ?>
            <script>
                const editPageTitle = document.querySelector('.wp-heading-inline');
                if (editPageTitle) {
                    editPageTitle.textContent = <?php echo wp_json_encode( $editPageTitle_text ); ?>;
                }

                const orderDataHeading = document.querySelector('.woocommerce-order-data__heading');
                if (orderDataHeading) {
                    // Use innerHTML instead of textContent so the HTML span tag renders properly
                    orderDataHeading.innerHTML = <?php echo wp_json_encode( $orderDataHeading_text ); ?>;
                }
            </script>
            <?php
        }
    }
    add_action('woocommerce_admin_order_data_after_order_details', 'modify_edit_page_headings');

    /**
     * Remove unwanted metaboxes from the Order Edit screen.
     * Removes: Downloadable Product Permissions & Custom Fields.
     */
    function starke_remove_unwanted_order_metaboxes() {
        // Define screens for both HPOS (High Performance Order Storage) and Legacy views
        $screens = array( 'shop_order', 'woocommerce_page_wc-orders' );

        foreach ( $screens as $screen ) {
            // 1. Remove "Downloadable product permissions"
            remove_meta_box( 'woocommerce-order-downloads', $screen, 'normal' );

            // 2. Remove "Custom Fields"
            // 'postcustom' is the standard WP ID, but WooCommerce often uses 'order_custom'
            remove_meta_box( 'order_custom', $screen, 'normal' );
        }
    }
    add_action( 'add_meta_boxes', 'starke_remove_unwanted_order_metaboxes', 99 );

    // ==========================================================================
    // CUSTOM ADMIN SHIPPING & SAMPLES COLUMN DISPLAY
    // ==========================================================================

    /**
     * Adds the data to the order page in WordPress admin.
     * 1. Forces flex layout for even spacing.
     * 2. Gives the first column extra width.
     * 3. Hides "Edit" buttons/links.
     * 4. Linkifies and formats ALL phone numbers.
     * 5. INSTANTLY renames "Shipping" to "Linear Shipping" or "Samples Shipping" based on context.
     */
    add_action( 'woocommerce_admin_order_data_after_shipping_address', 'starke_display_sample_shipping_address_in_admin' );
    function starke_display_sample_shipping_address_in_admin( $order ) {
        // --- 1. Detect Order Type ---
        $has_samples = false;
        $has_standard = false;

        foreach ( $order->get_items() as $item ) {
            // Check for Sample
            // Logic matches your other functions: check metadata 'sample' or '(sample)' in name
            $name_lower = strtolower( $item->get_name() );
            $meta_sample = $item->get_meta( 'sample' );
            
            $is_item_sample = ( ! empty( $meta_sample ) ) || strpos( $name_lower, '(sample)' ) !== false;
            
            // Check for Charge/Fee masquerading as product
            $is_charge = strpos( $name_lower, 'tooling charge' ) !== false || strpos( $name_lower, 'setup charge' ) !== false;

            if ( $is_item_sample ) {
                $has_samples = true;
            } elseif ( ! $is_charge ) {
                // If it's not a sample and not a charge, it's a standard/linear product
                $has_standard = true;
            }
        }

        // Define Flags
        $is_mixed_cart = $has_samples && $has_standard;
        $is_samples_only = $has_samples && ! $has_standard;

        // --- NEW: Check for Pickup Location on the Standard Method ---
        $sample_shipping_rate_id = function_exists('get_samples_shipping_method') ? get_samples_shipping_method($order) : '';
        $is_pickup = false;
        $pickup_address_html = ''; // <-- We will store the formatted pickup address here

        foreach ( $order->get_shipping_methods() as $method ) {
            $method_id_in_order = $method->get_method_id() . ':' . $method->get_instance_id();
            
            // Target the standard method (skip the samples method)
            if ( $method_id_in_order !== $sample_shipping_rate_id ) {
                if ( strpos( $method->get_method_id(), 'pickup_location' ) !== false || strpos( $method_id_in_order, 'pickup_location' ) !== false ) {
                    $is_pickup = true;
                    
                    // Grab the saved pickup address from order meta
                    $saved_pickup_address = $order->get_meta('_standard_pickup_address', true);
                    $addr_array = [];

                    if ( ! empty( $saved_pickup_address ) && is_array( $saved_pickup_address ) ) {
                        $addr_array = $saved_pickup_address;
                    } elseif ( function_exists('starke_get_pickup_location_address') ) {
                        // Fallback just in case
                        $fallback_dest = [
                            'country'  => $order->get_shipping_country() ?: 'US',
                            'state'    => $order->get_shipping_state(),
                            'postcode' => $order->get_shipping_postcode(),
                            'city'     => $order->get_shipping_city(),
                            'address_1'=> $order->get_shipping_address_1(),
                            'address_2'=> $order->get_shipping_address_2(),
                        ];
                        $addr_array = starke_get_pickup_location_address( $method_id_in_order, $fallback_dest );
                    }

                    if ( ! empty( $addr_array ) ) {
                        // Format the array exactly how WooCommerce expects it
                        $formatted_args = [
                            'first_name' => '', 
                            'last_name'  => '',
                            'company'    => 'Starke Millwork Inc.',
                            'address_1'  => $addr_array['street'] ?? $addr_array['address_1'] ?? '', 
                            'address_2'  => $addr_array['address_2'] ?? '',
                            'city'       => $addr_array['city'] ?? '',
                            'state'      => $addr_array['state'] ?? '',
                            'postcode'   => $addr_array['postcode'] ?? '',
                            'country'    => $addr_array['country'] ?? 'US',
                        ];
                        $pickup_address_html = WC()->countries->get_formatted_address( $formatted_args );
                    }
                }
                break;
            }
        }

        // Determine Primary Column Label
        if ( $is_samples_only ) {
            $primary_label = 'Samples Shipping';
        } elseif ( $is_pickup ) {
            $primary_label = 'Linear Profiles Pickup Location';
        } else {
            $primary_label = 'Linear Profiles Shipping';
        }

        $samples_address = $order->get_meta( '_samples_full_shipping_address' );
        
        $formatted_samples_address = '';
        if ( ! empty( $samples_address ) && is_array( $samples_address ) ) {
            $formatted_samples_address = WC()->countries->get_formatted_address( $samples_address );
        }

        // --- Gather Primary / Linear Address Meta Fields ---
        $po_number_job_name = $order->get_meta('_po_number_job_name', true);
        $jobsite_contact    = $order->get_meta('_jobsite_contact', true);
        $jobsite_cell_raw   = $order->get_meta('_jobsite_contact_cell_number', true);
        
        // Format the jobsite contact cell phone number cleanly with dashes
        $jobsite_cell_display = $jobsite_cell_raw;
        if ( ! empty( $jobsite_cell_raw ) ) {
            $cleaned_cell = preg_replace( '/[^0-9]/', '', $jobsite_cell_raw );
            if ( strlen( $cleaned_cell ) === 10 ) {
                $jobsite_cell_display = preg_replace( '/(\d{3})(\d{3})(\d{4})/', '$1-$2-$3', $cleaned_cell );
            }
        }

        ?>
        <style type="text/css">
            /* --- LAYOUT FIXES --- */
            .order_data_column_container {
                display: flex !important;
                flex-direction: row !important;
                flex-wrap: nowrap !important;
                justify-content: space-between !important;
                width: 100% !important;
            }

            .order_data_column_container .order_data_column {
                width: 22% !important; 
                float: none !important;
                padding: 0 10px !important;
                box-sizing: border-box !important;
                margin-right: 0 !important;
            }

            /* Give the FIRST column (General details) significantly more width */
            .order_data_column_container .order_data_column:first-of-type {
                width: 34% !important;
            }

            /* --- UI CLEANUP --- */
            .order_data_column h3 .edit_address,
            .order_data_column h3 span {
                display: none !important;
            }

            /* --- INSTANT RENAME FIX (CSS PRE-LOADER) --- */
            /* 1. Target the 3rd column (Shipping) and hide the original text immediately */
            .order_data_column_container .order_data_column:nth-of-type(3) h3 {
                visibility: hidden; 
                position: relative;
            }
            /* 2. Show the Dynamic Label immediately via CSS before JS even runs */
            .order_data_column_container .order_data_column:nth-of-type(3) h3::after {
                content: '<?php echo esc_js( $primary_label ); ?>';
                visibility: visible;
                position: absolute;
                top: 0;
                left: 0;
                color: #1d2327; /* Standard WP Admin Heading Color */
            }

            /* 3. Class to reset CSS once JS takes over (The "Handover") */
            .order_data_column_container .order_data_column:nth-of-type(3) h3.js-renamed {
                visibility: visible !important;
            }
            .order_data_column_container .order_data_column:nth-of-type(3) h3.js-renamed::after {
                content: none !important;
                display: none !important;
            }

            /* Custom styling for job metadata parameters to look integrated into admin lists */
            .starke-admin-job-meta-wrap {
                margin-top: 12px;
                padding-top: 10px;
            }
            .starke-admin-job-meta-wrap p {
                margin: 4px 0 !important;
                line-height: 1.4 !important;
            }
        </style>

        <script type="text/javascript">
            // Use an IIFE (Immediately Invoked Function Expression)
            // This runs INSTANTLY as the browser parses the HTML, without waiting for 'ready'.
            (function() {
                try {
                    var cols = document.querySelectorAll('.order_data_column_container .order_data_column');
                    if (cols.length >= 3) {
                        var shippingCol = cols[2]; // The 3rd column
                        
                        // 1. Rename "Shipping" -> Dynamic Label
                        var shippingHeader = shippingCol.querySelector('h3');
                        if (shippingHeader) {
                            shippingHeader.innerText = '<?php echo esc_js( $primary_label ); ?>';
                            shippingHeader.classList.add('js-renamed');
                        }

                        // 2. Override the actual Address HTML with the Pickup Location
                        var pickupHtml = <?php echo json_encode( $pickup_address_html ); ?>;
                        if (pickupHtml && pickupHtml.trim() !== '') {
                            var addressDiv = shippingCol.querySelector('.address');
                            if (addressDiv) {
                                // Overwrite WooCommerce's default shipping address with our Pickup Location
                                addressDiv.innerHTML = '<p>' + pickupHtml + '</p>';
                            }
                        }
                    }

                    // 3. Format Phones (Run this immediately as well)
                    var phoneLinks = document.querySelectorAll('.order_data_column .address a[href^="tel:"]');
                    for (var i = 0; i < phoneLinks.length; i++) {
                        var link = phoneLinks[i];
                        var raw = link.textContent.replace(/\D/g, '');
                        if (raw.length === 10) {
                            link.textContent = raw.replace(/(\d{3})(\d{3})(\d{4})/, '$1-$2-$3');
                        }
                    }
                } catch(e) { console.error('VernShippingBlock UI Error:', e); }
            })();
        </script>
        
        <?php
        
        // --- APPEND JOB & PO DETAILS TO PRIMARY ADDRESS COLUMN ---
        echo '<div class="starke-admin-job-meta-wrap">';
        if ( ! empty( $po_number_job_name ) ) {
            echo '<p><strong>' . esc_html__( 'PO / Job Name:', 'quotes' ) . '</strong> ' . esc_html( $po_number_job_name ) . '</p>';
        }
        if ( ! empty( $jobsite_contact ) ) {
            echo '<p><strong>' . esc_html__( 'Jobsite Contact:', 'quotes' ) . '</strong> ' . esc_html( $jobsite_contact ) . '</p>';
        }
        if ( ! empty( $jobsite_cell_display ) ) {
            echo '<p><strong>' . esc_html__( 'Jobsite Phone:', 'quotes' ) . '</strong> <a href="tel:' . esc_attr( preg_replace( '/[^0-9]/', '', $jobsite_cell_raw ) ) . '">' . esc_html( $jobsite_cell_display ) . '</a></p>';
        }
        echo '</div>';

        // --- 2. Only Show Secondary Column if MIXED ---
        // If it's Samples Only, the primary column is already renamed to "Samples Shipping" above, 
        // so we don't show this extra column.
        // If it's Standard Only, we don't show this column either.
        if ( $is_mixed_cart ) {
            // The "Column Hack": Close the "Shipping" column div and start the new "Samples" column div
            echo '</div><div class="order_data_column">';

            // Updated Header
            echo '<h3>' . esc_html__( 'Samples Shipping', 'vern_shipping_block' ) . '</h3>';
            echo '<div class="address">';
            
            if ( ! empty( $formatted_samples_address ) ) {
                echo '<p>' . wp_kses_post( $formatted_samples_address ) . '</p>';
                
                if ( ! empty( $samples_address['phone'] ) ) {
                    $phone_raw = preg_replace( '/[^0-9]/', '', $samples_address['phone'] );
                    $phone_display = $samples_address['phone'];
                    if ( strlen( $phone_raw ) === 10 ) {
                        $phone_display = preg_replace( '/(\d{3})(\d{3})(\d{4})/', '$1-$2-$3', $phone_raw );
                    }
                    echo '<p><strong>' . esc_html__( 'Phone:', 'vern_shipping_block' ) . '</strong> <a href="tel:' . esc_attr( $phone_raw ) . '">' . esc_html( $phone_display ) . '</a></p>';
                }
            } else {
                echo '<p class="none_set">' . esc_html__( 'No samples address set.', 'vern_shipping_block' ) . '</p>';
            }
            
            echo '</div>';

            // Fetch secondary data
            $samples_po_job = $order->get_meta('_samples_address_po_number_job_name', true);

            // Append Samples specific PO/Job data to the bottom of the secondary column
            if ( ! empty( $samples_po_job ) ) {
                echo '<div class="starke-admin-job-meta-wrap">';
                echo '<p><strong>' . esc_html__( 'Samples PO / Job Name:', 'quotes' ) . '</strong> ' . esc_html( $samples_po_job ) . '</p>';
                echo '</div>';
            }
        }
    }


    /**
     * Removes the Stripe "Pay for Order" button by targeting its specific container.
     * Uses the exact HTML structure provided to avoid hiding the Status dropdown.
     */
    function starke_remove_admin_payment_link() {
        $screen = get_current_screen();
        $target_screens = array( 'shop_order', 'woocommerce_page_wc-orders' );

        if ( $screen && in_array( $screen->id, $target_screens ) ) {
            ?>
            <style>
                /* 1. CSS Safety: Hide the button and tip immediately */
                button.wc-stripe-pay-order { display: none !important; }
                button.wc-stripe-pay-order + .woocommerce-help-tip { display: none !important; }
            </style>
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // 2. JS Surgical Removal
                    // Find the Stripe Button
                    var $stripeBtn = $('.wc-stripe-pay-order');
                    
                    // Go up to the exact <p class="form-field"> wrapper you identified
                    var $container = $stripeBtn.closest('p.form-field');
                    
                    // SAFETY CHECK: Only hide this container if it DOES NOT contain the Status dropdown
                    if ( $container.length && $container.find('select[name="order_status"]').length === 0 && $container.find('#order_status').length === 0 ) {
                        $container.hide();
                    } else {
                        // Fallback: If the structure is weird/shared, just hide the button itself
                        $stripeBtn.hide();
                        $stripeBtn.next('.woocommerce-help-tip').hide();
                    }

                    // 3. Handle the Standard WC Text Link (if present) without breaking layout
                    // We hide the link itself, but NOT the parent container (to protect Status)
                    var $payLink = $('.order_data_column a[href*="order-pay"]');
                    if ( $payLink.length ) {
                        $payLink.hide();
                        // Also hide the "Customer payment page:" text label if it's a sibling
                        $payLink.parent().contents().filter(function() {
                            return this.nodeType === 3 && this.nodeValue.indexOf('payment page') > -1;
                        }).remove();
                    }
                });
            </script>
            <?php
        }
    }
    add_action( 'admin_head', 'starke_remove_admin_payment_link' );

    /**
     * Dynamically removes the 'Pending payment' filter button link from the admin list views
     * if the only pending orders remaining in the database are hidden balance invoices.
     */
    add_filter( 'views_woocommerce_page_wc-orders', 'starke_hide_pending_filter_for_balance_invoices', 999 );
    function starke_hide_pending_filter_for_balance_invoices( $views ) {
        // Only proceed if the pending view button link is natively present in the list
        if ( isset( $views['wc-pending'] ) ) {
            
            // Count if there are any standard pending orders that are NOT balance invoices
            $true_pending_orders = wc_get_orders( array(
                'status'     => 'wc-pending',
                'limit'      => 1, // We only need to find at least 1 to keep the button visible
                'return'     => 'ids',
                'meta_query' => array(
                    array(
                        'key'     => '_starke_is_balance_invoice',
                        'compare' => 'NOT EXISTS',
                    ),
                ),
            ));

            // If no true pending orders exist (meaning any remaining are exclusively balance invoices),
            // completely remove the 'Pending payment' filter button link from the admin screen.
            if ( empty( $true_pending_orders ) ) {
                unset( $views['wc-pending'] );
            }
        }
        return $views;
    }
}

// Save custom meta data from cart to order item when an order is created
add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values, $order) {
    // These are the fields you want to copy from cart to order item
    $fields = [
        'linear_feet',
        'linear_feet_actual',
        'thickness',
        'thickness_actual',
        'width',
        'width_actual',
        'length',
        'first_rabbet',
        'first_rabbet_actual',
        'first_rabbet_thickness',
        'first_rabbet_thickness_actual',
        'first_rabbet_width',
        'first_rabbet_width_actual',
        'reliefangle',
        'reliefangle_actual',
        'backrelief',
        'backrelief_actual',
        'species',
        'species_actual',
        'finish',
        'finish_actual',
        'stain',
        'stain_actual',
        'sheen',
        'sheen_actual',
        'rabbet_setup_charge',
        'relief_angle_setup_charge',
        'quantity_discount',
        'price_per_foot',
        'knifecost',
        'markup',
        'waste',
        'similar_profiles',
        'custom_description',
        'custom_profile_number',
        'knife_cost_price'
    ];

    foreach ($fields as $field) {
        if (isset($values[$field])) {
            $item->add_meta_data($field, $values[$field], true);
        }
    }

    // Also save custom sample name if exists
    if (isset($values['custom_name'])) {
        $item->add_meta_data('custom_name', $values['custom_name'], true);
    }
    if (isset($values['sample'])) {
        $item->add_meta_data('sample', $values['sample'], true);
    }
}, 10, 4);


/**
 * Filters and reorders the metadata for a WooCommerce order item.
 *
 * This function ensures that the custom fields for an order item are displayed
 * in a specific, predefined order on the Edit Order screen and other views.
 *
 * @param array      $formatted_meta The original array of meta data objects from WooCommerce.
 * @param WC_Order_Item $order_item     The order item object.
 * @return array The new, reordered array of meta data objects.
 */
add_filter( 'woocommerce_order_item_get_formatted_meta_data', 'reorder_and_filter_order_item_meta', 10, 2 );
function reorder_and_filter_order_item_meta( $formatted_meta, $order_item ) {
    // This is the exact array and order from your quote.php file.
    $meta_titles = [
        'rabbet_setup_charge' => 'Rabbet Setup Charge (Under 100ft)',
        'relief_angle_setup_charge' => 'Relief Angle Setup Charge (Under 100ft)',
        'custom_description' => 'Description',
        'linear_feet' => 'Linear Feet',
        'quantity_discount' => 'Quantity Discount',
        'thickness' => 'Thickness',
        'width' => 'Width',
        'length' => 'Lengths',
        'first_rabbet' => 'Rabbet Position',
        'first_rabbet_thickness' => 'Rabbet Thickness',
        'first_rabbet_width' => 'Rabbet Width',
        'reliefangle' => 'Relief Angle',
        'backrelief' => 'Back Relief',
        'species' => 'Species',
        'finish' => 'Finish',
        'stain' => 'Stain',
        'sheen' => 'Sheen',
        'similar_profiles' => 'Similar Profiles',
        'custom_profile_number' => 'Custom Profile Number',
    ];

    // 1. Create a simple lookup array from the original meta data for efficient access
    $meta_by_key = [];
    foreach ( $formatted_meta as $meta ) {
        $meta_by_key[ $meta->key ] = $meta;
    }

    // 2. Build a new, ordered array by looping through your defined order
    $reordered_meta = [];
    foreach ( $meta_titles as $key => $label ) {
        // Check if this meta data exists for the current order item
        if ( isset( $meta_by_key[ $key ] ) ) {
            // Get the original meta object
            $meta_object = $meta_by_key[ $key ];
            // Update its display label to your custom one
            $meta_object->display_key = $label;
            // Add the modified object to our new, reordered array
            $reordered_meta[] = $meta_object;
        }
    }

    // 3. Return the newly ordered array to be displayed
    return $reordered_meta;
}

// Generate a unique link for the quote based on the order ID and its unique quote link id
function generate_link_for_quote( $order ) {
    // Get the order object if only the ID is passed
    if ( is_numeric( $order ) ) {
        $order = wc_get_order( $order );
    }

    // Ensure we have a valid order object
    if ( ! $order instanceof WC_Order ) {
        return false; // Invalid order provided
    }

    // Get the unique link ID from order metadata
    $unique_id = $order->get_meta( 'quote_link_id' );

    // Ensure the unique ID exists
    if ( empty( $unique_id ) ) {
        return false; // No unique link ID found for this order
    }

    // Get the Order ID
    $order_id = $order->get_id();

    $base_url = wc_get_page_permalink('checkout');

    // Add the order_id and unique_id as URL parameters
    $quote_url = add_query_arg(
        array(
            'quote'    => $order_id,
            'quote_id' => $unique_id,
        ),
        $base_url // The base URL of your quote handling page
    );

    return $quote_url;
}

/**
 * Handles incoming URL parameters for quote links.
 * Checks for 'quote' and 'quote_id' parameters, validates them,
 * FORCES password setup for new accounts, and loads the quote into the cart.
 */
function load_cart_from_quote_link() {
    if ( isset( $_GET['quote'], $_GET['quote_id'] ) ) {
        $quote_from_url    = absint( $_GET['quote'] ); 
        $quote_id_from_url = sanitize_text_field( $_GET['quote_id'] ); 

        if ( $quote_from_url > 0 && ! empty( $quote_id_from_url ) ) {
            $order = wc_get_order( $quote_from_url );

            if ( $order instanceof WC_Order ) {
                $unique_quote_link_id = $order->get_meta( 'quote_link_id' );
                $quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'freight-quote', 'deleted-quote', 'ordered-quote'];

                if ( ! empty( $unique_quote_link_id ) && $quote_id_from_url === $unique_quote_link_id && in_array( $order->get_status(), $quote_statuses, true ) ) {
                    // Securely store the original order ID in the WC_Session
                    if (WC()->session) {
                        WC()->session->set('editing_original_order_id', $quote_from_url);
                    }
                    load_order_quote_into_cart( $quote_from_url );
                }
            }
        }
    }
}
add_action( 'wp_loaded', 'load_cart_from_quote_link' );

/**
 * Injects the JavaScript code to remove URL parameters into the page footer.
 * The script is only injected if the quote link parameters are present
 * and the current page is the WooCommerce checkout page.
 */
function remove_quote_link_parameters() {
    // Check if the quote link parameters are present in the URL AND if the current page is the WooCommerce checkout page.
    if ( isset( $_GET['quote'], $_GET['quote_id'] ) && is_checkout() ) {
        ?>
        <script>
            // Get the current URL
            var currentUrl = window.location.href;

            // Check if the URL contains the quote link parameters
            var urlParams = new URLSearchParams(window.location.search);

            if (urlParams.has('quote') || urlParams.has('quote_id')) {
                // Remove the specific parameters
                urlParams.delete('quote');
                urlParams.delete('quote_id');

                // Construct the new URL without the parameters
                var newUrl = window.location.pathname;
                var newSearch = urlParams.toString();

                if (newSearch) {
                    newUrl += '?' + newSearch;
                }

                // Use history.replaceState to change the URL in the address bar without reloading the page.
                try {
                    window.history.replaceState({}, document.title, newUrl);
                    console.log('URL parameters removed.');
                } catch (e) {
                    console.error('Failed to remove URL parameters using history.replaceState:', e);
                }
            }
        </script>
        <?php
    }
}
add_action( 'wp_footer', 'remove_quote_link_parameters' );


// My Account page Quotes -- START

// Register Quotes endpoint
function custom_add_quotes_endpoint() {
    add_rewrite_endpoint('quotes', EP_ROOT | EP_PAGES);
}
add_action('init', 'custom_add_quotes_endpoint');

function custom_add_quotes_link_my_account($items) {
    $new_items = [];
    foreach ($items as $key => $value) {
        $new_items[$key] = $value;
        if ($key === 'orders') {
            // Add Quotes link right after Orders
            $new_items['quotes'] = __('Quotes', 'text-domain');
        }
    }
    return $new_items;
}
add_filter('woocommerce_account_menu_items', 'custom_add_quotes_link_my_account');

// Register the 'page' query var for pagination
function custom_quotes_query_vars($vars) {
    $vars[] = 'quotes';
    $vars[] = 'paged';
    return $vars;
}
add_filter('woocommerce_get_query_vars', 'custom_quotes_query_vars', 0);

/**
 * Change the number of orders displayed per page in My Account > Orders
 */
function starke_change_orders_per_page( $args ) {
    // Set this to the number of orders you want to show (e.g., 50)
    $args['posts_per_page'] = 25; 
    return $args;
}
add_filter( 'woocommerce_my_account_my_orders_query', 'starke_change_orders_per_page' );

// Sets up the HTML for the Quotes endpoint
add_action('woocommerce_account_quotes_endpoint', 'custom_quotes_content');
function custom_quotes_content() {

    global $wp_query;
    $logger = wc_get_logger();
    $context = ['source' => 'custom-quotes-pagination'];
    
    $customer_id = get_current_user_id();
    //$current_page = max(1, absint(get_query_var('paged', 1)));
    $current_page = max( 1, (int) filter_input( INPUT_GET, 'paged' ) );
    $posts_per_page = 25;

    // By default, customers only see active, expired, and ORDERED quotes.
    $statuses_to_query = ['active-quote', 'expired-quote', 'ordered-quote', 'freight-quote'];
    // Admins see everything
    if ( ( function_exists('impersonation_is_active') && impersonation_is_active() ) || current_user_can('manage_woocommerce') ) {
        array_push($statuses_to_query, 'pending-quote', 'expired-quote', 'deleted-quote', 'ordered-quote');
    }

    $customer_orders = wc_get_orders([
        'customer_id'    => $customer_id,
        'status'         => $statuses_to_query,
        'limit'          => $posts_per_page,
        'paginate'       => true,
        'paged'          => $current_page,
    ]);

    $has_orders = !empty($customer_orders->orders);
    $wp_button_class = ' wp-element-button';
    wc_get_template(
        'myaccount/quotes.php',
        [
            'customer_orders' => $customer_orders,
            'has_orders'      => $has_orders,
            'current_page'    => $current_page,
            'max_num_pages'   => $customer_orders->max_num_pages,
            'quotes_endpoint' => wc_get_account_endpoint_url('quotes'),
            'wp_button_class' => $wp_button_class,
        ]
    );
}

/**
 * Handles the 'load_quote_to_cart' action initiated from on-site action buttons.
 *
 * Verifies the request, loads the quote items into the cart,
 * and redirects the user to the checkout page.
 */
add_action('template_redirect', 'sm_handle_load_quote_to_cart_action');
function sm_handle_load_quote_to_cart_action() {
    if (!isset($_GET['action']) || $_GET['action'] !== 'load_quote_to_cart') {
        return;
    }
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'load_quote_to_cart')) {
        wc_add_notice(__('Security check failed', 'woocommerce'), 'error');
        return;
    }

    $quote_id = absint($_GET['quote_id']);
    if (!$quote_id) {
        wc_add_notice(__('Invalid Quote ID', 'woocommerce'), 'error');
        return;
    }

    // Use your existing function to load the quote to the cart
    $result = load_order_quote_into_cart($quote_id);

    if (is_wp_error($result)) {
        wc_add_notice($result->get_error_message(), 'error');
        return;
    }

    // Redirect to checkout
    wp_safe_redirect(wc_get_checkout_url());
    exit;
}


/**
 * Handles the 'delete_quote' action from a URL.
 *
 * Verifies the request, checks if the quote is active,
 * and updates its status to 'deleted-quote'.
 */
add_action('template_redirect', 'sm_handle_delete_quote_action');
function sm_handle_delete_quote_action() {
    if ( !isset($_GET['delete_quote']) || !isset($_GET['_wpnonce']) ) {
        return;
    }
    $quote_id = absint($_GET['delete_quote']);
    if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_quote_' . $quote_id)) {
        wc_add_notice(__('Security check failed.', 'woocommerce'), 'error');
        return;
    }
    
    $order = wc_get_order($quote_id);
    
    // 1. Define allowed statuses for deletion
    $allowed_statuses = array( 'active-quote', 'expired-quote' );

    // 2. Allow Pending Quotes ONLY if impersonating
    if ( function_exists('impersonation_is_active') && impersonation_is_active() ) {
        $allowed_statuses[] = 'pending-quote';
    }
    
    // 3. Check Status and Delete
    if ( $order && in_array( $order->get_status(), $allowed_statuses ) ) {
        $order->update_status('deleted-quote');
        wc_add_notice(__('Quote successfully deleted.', 'woocommerce'), 'success');

        // NEW: Determine Redirect URL (Stay on same page)
        $redirect_url = wc_get_account_endpoint_url('quotes');
        
        // If 'paged' was passed in the URL, add it back to the redirect
        if ( isset( $_GET['paged'] ) && absint( $_GET['paged'] ) > 1 ) {
            $redirect_url = add_query_arg( 'paged', absint( $_GET['paged'] ), $redirect_url );
        }

        wp_safe_redirect( $redirect_url );
        exit;
    } else {
        wc_add_notice(__('Unable to delete this quote. It may no longer be active or expired.', 'woocommerce'), 'error');
    }
}


/*function custom_quotes_breadcrumb($crumbs, $breadcrumb) {
    if (is_wc_endpoint_url('quotes')) {
        $crumbs[] = [esc_url(wc_get_account_endpoint_url('quotes')), __('Quotes', 'text-domain')];
    }
    return $crumbs;
}
add_filter('woocommerce_get_breadcrumb', 'custom_quotes_breadcrumb', 20, 2);*/

/*function custom_modify_order_actions($actions, $order) {
    if ($order->get_status() === 'active-quote') {
        $actions['convert_to_order'] = [
            'url'  => site_url('/convert-to-order/?order_id=' . $order->get_id()),
            'name' => __('Convert to Order', 'text-domain'),
        ];
    }
    return $actions;
}
add_filter('woocommerce_my_account_my_orders_actions', 'custom_modify_order_actions', 10, 2);
*/


/**
 * Exclude quote order statuses from My Account Orders list.
 *
 * @param array $args Query arguments for fetching orders.
 * @return array Modified query arguments.
*/
function exclude_quotes_from_my_account_orders_query( $args ) {
    $excluded_statuses = [
        'active-quote',
        'expired-quote',
        'pending-quote',
        'deleted-quote',
        'freight-quote',
        'ordered-quote'
    ];

    // CHANGED: Also exclude 'profiles-ready' unless an Admin is impersonating or managing options.
    // This ensures regular customers cannot see the internal 'profiles-ready' orders.
    $is_admin_or_impersonator = ( function_exists('impersonation_is_active') && impersonation_is_active() ) || current_user_can('manage_woocommerce');
    
    if ( ! $is_admin_or_impersonator ) {
        $excluded_statuses[] = 'profiles-ready';
    }

    // If status is already set in the query, merge with excluded
    if ( isset( $args['status'] ) && is_array( $args['status'] ) ) {
        $args['status'] = array_diff( $args['status'], $excluded_statuses );
    } else {
        $args['status'] = array_diff( wc_get_order_statuses(), $excluded_statuses );
    }

    return $args;
}
add_filter( 'woocommerce_my_account_my_orders_query', 'exclude_quotes_from_my_account_orders_query' );

/**
 * Add 'quotes' to WooCommerce's recognized query variables.
 * This is necessary for is_wc_endpoint_url('quotes') to work correctly.
 */
function add_quotes_endpoint_query_var( $vars ) {
    // You can also add it like this if 'quotes' is the only custom endpoint
    $vars['quotes'] = 'quotes';
    return $vars;
}
add_filter( 'woocommerce_get_query_vars', 'add_quotes_endpoint_query_var', 10, 1 );

// My Account page Quotes -- END

/**
 * Generates a pretty URL for downloading quote PDFs.
 *
 * @param int    $order_id The ID of the quote.
 * @param string $nonce    The security nonce generated for the download.
 * @return string The pretty URL for the PDF.
 */
function set_quote_pdf_url($order_id, $nonce) {
    // Example format: home_url('/quote-pdf/123/abcd12345/')
    return home_url('/quote-pdf/' . intval($order_id) . '/' . esc_attr($nonce) . '/');
}

/**
 * Generates a pretty URL for downloading order PDFs.
 *
 * @param int    $order_id The ID of the order.
 * @param string $nonce    The security nonce generated for the download.
 * @return string The pretty URL for the PDF.
 */
function set_order_pdf_url($order_id, $nonce) {
    // Example format: home_url('/order-pdf/123/abcd12345/')
    return home_url('/order-pdf/' . intval($order_id) . '/' . esc_attr($nonce) . '/');
}






// Check if Order/Quote PDF is ready or not
/*add_action('wp_ajax_check_pdf_status', 'check_pdf_status_callback');
add_action('wp_ajax_nopriv_check_pdf_status', 'check_pdf_status_callback');

function check_pdf_status_callback() {
    $order_id = intval($_POST['order_id'] ?? 0);
    if (!$order_id) {
        wp_send_json_error(['message' => 'Invalid order ID']);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
    }

    // Replace '_pdf_s3_key' with your actual meta key name
    $pdf_key = $order->get_meta('_pdf_s3ObjectKey');

    if ($pdf_key) {
        //$pdf_url = 'https://your-bucket.s3.amazonaws.com/' . $pdf_key; // Customize for your actual S3 URL
    wp_send_json_success(['pdf_ready' => true]); // 'pdf_url' => $pdf_url
    }

    wp_send_json_success(['pdf_ready' => false]);
}*/



/**
 * ==========================================================================
 * QUOTE EXPIRATION SCHEDULER
 * ==========================================================================
 *
 * This code automatically schedules and handles the expiration of quotes
 * based on the 'quote_duration' setting in Starke Commerce options.
 */

// Make sure Action Scheduler is available
if (!function_exists('as_schedule_single_action')) {
    return;
}

/**
 * 1. SCHEDULE or UNSCHEDULE the quote expiration when an order status changes.
 *
 * This function is the main controller. It watches for an order becoming
 * an 'active-quote' to schedule an expiration, and watches for it changing
 * away from 'active-quote' to cancel a scheduled expiration.
 *
 * @param int      $order_id   The ID of the order being changed.
 * @param string   $old_status The status the order is changing from.
 * @param string   $new_status The status the order is changing to.
 * @param WC_Order $order      The order object.
 */
function sm_handle_quote_expiration_scheduling($order_id, $old_status, $new_status, $order) {
    
    // Define the unique hook and arguments for our scheduled action
    $hook_name = 'sm_expire_quote_action';
    $args      = ['order_id' => $order_id];
    $group     = 'starke_quote_expiration';

    // First, always try to cancel any existing scheduled expiration for this order.
    // This is a failsafe that prevents duplicate scheduled actions and handles all
    // status changes cleanly (e.g., from 'active-quote' to 'expired-quote').
    as_unschedule_action($hook_name, $args, $group);

    // If the new status is 'active-quote', we need to schedule the expiration.
    if ('active-quote' === $new_status) {
        // Get the quote duration (in days) from your Starke Commerce settings.
        $options = get_option('starke_commerce_options');
        $quote_duration_days = isset($options['quote_duration']) ? absint($options['quote_duration']) : 0;

        // Only proceed if the duration is a positive number.
        if ($quote_duration_days > 0) {
            // Calculate the exact time for the expiration event.
            $expiration_timestamp = time() + ($quote_duration_days * DAY_IN_SECONDS);
            // Schedule the single event with Action Scheduler.
            as_schedule_single_action($expiration_timestamp, $hook_name, $args, $group);
            // Save the expiration timestamp as order meta.
            $order->update_meta_data('_quote_expiration_date', $expiration_timestamp);
            $order->save();
        }
    }
}
// Hook our controller function into WooCommerce's status change action.
add_action('woocommerce_order_status_changed', 'sm_handle_quote_expiration_scheduling', 10, 4);

/**
 * 2. EXECUTE the scheduled action to expire the quote.
 *
 * This function runs when the scheduled time arrives. It performs the final
 * checks and changes the order status to 'expired-quote'.
 *
 * @param int $order_id The order ID passed from the scheduled action's arguments.
 */
function sm_execute_expire_quote($order_id) {
    if (empty($order_id)) {
        return;
    }

    $order = wc_get_order($order_id);

    if (!$order) {
        return;
    }

    // CRITICAL CHECK: Before expiring, make sure the order is still an 'active-quote'.
    // This prevents this action from overwriting a status that was changed manually
    // after the expiration was scheduled (e.g., the customer paid for the quote).
    if ($order->has_status('active-quote')) {
        $order->update_status('expired-quote', 'Quote automatically expired based on duration setting.');
    }
}
// Hook our execution function to the custom hook we defined.
add_action('sm_expire_quote_action', 'sm_execute_expire_quote', 10, 1);

/**
 * 1. Unschedule expiration when an active quote is moved to the trash.
 *
 * This function hooks into the post status transition and checks if an order
 * with the status 'wc-active-quote' is being moved to 'trash'.
 *
 * @param string  $new_status The new status of the post.
 * @param string  $old_status The old status of the post.
 * @param WP_Post $post       The post object.
 */
add_action( 'transition_post_status', 'sm_unschedule_expiration_on_trash', 10, 3 );
function sm_unschedule_expiration_on_trash( $new_status, $old_status, $post ) {
    // We only care when a WooCommerce order is being moved to the trash.
    if ( 'trash' !== $new_status || 'shop_order' !== $post->post_type ) {
        return;
    }

    // Only act if the order being trashed was an 'active-quote'.
    if ( 'wc-active-quote' === $old_status ) {
        $order_id  = $post->ID;
        $hook_name = 'sm_expire_quote_action';
        $args      = ['order_id' => $order_id];
        $group     = 'starke_quote_expiration';

        // Unscheduling a non-existent action is safe, so this is a reliable cleanup.
        as_unschedule_action( $hook_name, $args, $group );
    }
}

/**
 * 2. Unschedule expiration right before an active quote is permanently deleted.
 *
 * This function hooks into the action that runs just before a post is
 * deleted from the database.
 *
 * @param int $post_id The ID of the post being deleted.
 */
add_action( 'before_delete_post', 'sm_unschedule_expiration_on_delete', 10, 1 );
function sm_unschedule_expiration_on_delete( $post_id ) {
    // Check if this is a WooCommerce order.
    if ( get_post_type( $post_id ) !== 'shop_order' ) {
        return;
    }

    $order = wc_get_order( $post_id );

    // Check if the order exists and has the 'active-quote' status before deletion.
    if ( $order && $order->has_status('active-quote') ) {
        $hook_name = 'sm_expire_quote_action';
        $args      = ['order_id' => $post_id];
        $group     = 'starke_quote_expiration';

        as_unschedule_action( $hook_name, $args, $group );
    }
}

/**
 * Add product images to Order Confirmation and View Order pages.
 * Excludes virtual fee charges (Setup: 444, Tooling: 2843).
 */
add_filter( 'woocommerce_order_item_name', 'starke_add_product_image_to_order_item_name', 10, 3 );
function starke_add_product_image_to_order_item_name( $item_name, $item, $is_visible ) {
    // Only display on Order Received (Thank You) and View Order endpoints
    if ( ! is_order_received_page() && ! is_view_order_page() ) {
        return $item_name;
    }

    $product = $item->get_product();
    if ( ! $product ) {
        return $item_name;
    }

    // Exclude specific IDs for Tooling/Setup charges
    // 444 = Setup Charge, 2843 = Tooling/Knife Charge
    $excluded_ids = [ 444, 2843 ];
    if ( in_array( $product->get_id(), $excluded_ids ) ) {
        return $item_name;
    }

    // Fetch the thumbnail with custom styling for alignment
    $thumbnail = $product->get_image( 'thumbnail', array( 'class' => 'starke-order-conf-img' ) );

    // Prepend the image to the item name
    return $thumbnail . $item_name;
}

/**
 * Render "Expired Quote" Popup on Checkout.
 * Uses the EXACT structure and classes as the Orders/Quotes popup to ensure identical styling.
 */
add_action( 'wp_footer', 'starke_render_expired_quote_popup_checkout' );
function starke_render_expired_quote_popup_checkout() {
    // 1. Only run on Checkout page
    if ( ! is_checkout() || is_order_received_page() ) {
        return;
    }

    // 2. Check if we are editing a quote/order
    if ( ! WC()->session ) {
        return;
    }
    
    $editing_id = WC()->session->get( 'editing_original_order_id' );
    if ( ! $editing_id ) {
        return;
    }

    // 3. Check if the original status was 'expired-quote'
    $original_order = wc_get_order( $editing_id );
    if ( ! $original_order || $original_order->get_status() !== 'expired-quote' ) {
        return;
    }

    // --- NEW: Check Cookie ---
    // If the cookie exists, the user has already clicked "OK" for this load.
    // If the cookie does NOT exist (because they just clicked "Open" in quotes.php), show the popup.
    if ( isset( $_COOKIE['starke_seen_expired_' . $editing_id] ) ) {
        return; 
    }

    ?>
    <div id="starke-expired-popup-overlay" class="starke-popup-overlay" style="display: block;"></div>

    <div id="starke-expired-popup" class="infoPopUp2" style="display: flex;">
        
        <div id="infoHeader_div">
            <label id="infoPopUpTitle_label">Quote Expired</label>
            <button type="button" id="starke-expired-close-x" style="visibility: hidden;">X</button>
        </div>

        <div id="infoContent_div">
            <div id="starke-popup-text">
                This quote has expired.<br><br>
                Pricing has been automatically updated to reflect our current pricing.
            </div>
            
            <div class="popup-actions" style="justify-content: center;">
                <button type="button" id="starke-expired-ok-btn">OK, I Understand</button>
            </div>
        </div>

    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const popup = document.getElementById('starke-expired-popup');
        const overlay = document.getElementById('starke-expired-popup-overlay');
        const okBtn = document.getElementById('starke-expired-ok-btn');
        // Pass the ID to JS so we can set the specific cookie
        const quoteId = "<?php echo $editing_id; ?>";

        // --- ROBUST SCROLL LOCK FIX ---
        // 1. Calculate Scrollbar Width
        const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
        
        // 2. Target BOTH 'html' and 'body'. 
        // This solves the intermittent issue where the browser sometimes uses 'html' to scroll.
        const scrollTargets = [document.documentElement, document.body];
        
        // 3. Apply styles with 'important' priority to ensure they aren't overwritten by the theme.
        scrollTargets.forEach(el => {
            el.style.setProperty('overflow', 'hidden', 'important');
            el.style.setProperty('padding-right', scrollbarWidth + 'px', 'important');
        });
        // ------------------------------

        // Close Function
        function closeExpiredPopup() {
            // Set cookie so it doesn't show again ON REFRESH.
            document.cookie = "starke_seen_expired_" + quoteId + "=true; path=/";

            popup.style.display = 'none';
            overlay.style.display = 'none';
            
            // Unlock Scroll (Remove properties from both elements)
            scrollTargets.forEach(el => {
                el.style.removeProperty('overflow');
                el.style.removeProperty('padding-right');
            });
        }

        okBtn.addEventListener('click', closeExpiredPopup);
    });
    </script>
    <?php
}

// Handle PDF Download Request for Quotes and Orders 3D PDF buttons (gets called by rewrite rules in location.conf)
add_action('wp_ajax_download_order_quote_pdf', 'handle_pdf_download_request'); // For logged-in users
function handle_pdf_download_request() {
	// --- Security Check (CRITICAL) ---
	// 1. Verify Nonce: To prevent CSRF attacks.
	// 2. Authorize User: Ensure the user is logged in AND is authorized to download THIS specific PDF.

	// Example: Basic nonce check
	if ( !isset($_GET['nonce']) || !wp_verify_nonce( $_GET['nonce'], 'download_pdf_order_quote_nonce' ) ) {
		die('Security check failed.');
	}

	// Get the Order ID from the URL (no longer receiving S3 key from frontend)
	if (!isset($_GET['order_id'])) {
		die('Invalid request: Missing Order ID.');
	}
	$order_id = intval(sanitize_text_field($_GET['order_id']));

	if (!$order_id) {
		die('Invalid Order ID provided.');
	}

	// --- IMPORTANT: Authorize the user for THIS SPECIFIC PDF ---
	$current_user_id = get_current_user_id();

	// 1. Ensure user is logged in
	if ( !is_user_logged_in() ) {
		die('Access denied. Please log in to download this file.');
	}

	// 2. Retrieve the WooCommerce Order
	// We need WooCommerce's wc_get_order() function, ensure WooCommerce is active.
	if ( ! function_exists( 'wc_get_order' ) ) {
		die('WooCommerce is not active. Cannot verify order details.');
	}
	$order = wc_get_order($order_id);

	// 3. Validate Order existence and ownership
	if (!$order) {
		die('Order not found for the provided ID.');
	}

	$order_customer_id = $order->get_customer_id(); // This is the ID of the user who placed the order

	// 4. Authorization Logic:
	//    a. Get the current logged-in user's roles
	$current_user = wp_get_current_user();

	//    b. Check if the current user is an administrator or Shop Manager (optional but common for staff)
	//       Admins and Shop Managers can download any order PDF for management purposes.
	if ( $current_user && in_array('administrator', $current_user->roles) ) {
		// Authorized: Admin
		// Proceed to get S3 key
	}
	//    c. Check if the current user is the customer who placed the order
	else if ( $current_user_id === $order_customer_id ) {
		// Authorized: User is the owner of the order
		// Proceed to get S3 key 
	}
	//    d. If neither, deny access
	else {
		die('Access denied. You do not have permission to download this PDF.');
	}

	// --- Retrieve S3 Object Key from Order Metadata ---
	// IMPORTANT: Replace '_s3_pdf_object_key' with the actual meta key you use to store the S3 key
	// when you upload the PDF from your Node.js generator.
	$s3ObjectKey = $order->get_meta('_pdf_s3ObjectKey'); // Use $order->get_meta() for WooCommerce 3.x+

	if (!empty($s3ObjectKey)) {
			//die('PDF not found for this order. S3 object key is missing from order metadata.');
		//}


		// --- S3 Client Initialization ---
		// or IAM Roles if configured correctly on Elastic Beanstalk.
		$s3Client = new S3Client([
			'region' => getenv('AWS_REGION') ?: 'us-east-1', // Fallback to a default if not set
			'version' => 'latest',
		]);

		$bucketName = getenv('S3_PDF_BUCKET_NAME'); // Ensure this env var is set on WordPress EB

		if (!$bucketName) {
			die('S3_PDF_BUCKET_NAME environment variable not set.');
		}

		try {
			// Get the object from S3
			$result = $s3Client->getObject([
				'Bucket' => $bucketName,
				'Key'    => $s3ObjectKey,
			]);

			// Set headers for download
			header('Content-Type: ' . $result['ContentType']); // Use content type from S3
			header('Content-Disposition: inline; filename="' . basename($s3ObjectKey) . '"');
			header('Content-Length: ' . $result['ContentLength']); // For better download progress

			// Read the body of the object and output it
			echo $result['Body'];

			exit; // Terminate script execution after file download
		} catch (AwsException $e) {
			// Log the error (e.g., to WordPress error logs or a custom log)
			error_log('S3 PDF download error: ' . $e->getMessage());
			if ($e->getAwsErrorCode() === 'NoSuchKey') {
				die('File not found or no longer exists in S3.');
			} else {
				die('Error downloading PDF: ' . $e->getAwsErrorCode());
			}
		} catch (Exception $e) {
			error_log('General PDF download error: ' . $e->getMessage());
			die('An unexpected error occurred during download.');
		}
	}

	// Refresh PDF page every second until the PDF is ready
	$check_count = isset($_GET['check']) ? intval($_GET['check']) : 0;
	if ($check_count >= 15) {
		die('The PDF is not yet available. Please try again shortly.');
	}


	// Force immediate output before script begins
	@ini_set('output_buffering', 'off');
	@ini_set('zlib.output_compression', 0);
	@ini_set('implicit_flush', 1);
	ob_implicit_flush(1);
	while (ob_get_level()) {
		@ob_end_flush();
	}
	echo str_repeat(' ', 2048); // padding to trigger early flush
	flush();

	header('Content-Type: text/html; charset=utf-8');
	echo '<!DOCTYPE html>';
	echo '<html><head>';
	echo '<meta http-equiv="refresh" content="1;url=' . esc_url( add_query_arg('check', $check_count + 1) ) . '">';
	echo '<title>Creating PDF...</title>';
	echo '<style>
		html, body {
			height: 100%;
			margin: 0;
			padding: 0;
			background: black;
		}
		body { 
			display: flex;
			justify-content: center;
			align-items: center;
			flex-direction: column;
			text-align: center;
			padding: 0 0px;
			color: white; 
			background: black; 
			font-family: sans-serif;
		}
		@media (min-width: 768px) {
			body { font-size: 2.0rem; }
			h1 { font-size: 3rem; }
			p { font-size: 1.8rem; }
		}
	</style>';
	
	echo '<style>
		.spinner {
			margin: 1.5em auto;
			width: 60px;
			height: 60px;
			border: 13px solid #ccc;
			border-top: 13px solid #000;
			border-radius: 50%;
			animation: spin 1s linear infinite;
		}
		@keyframes spin {
			to { transform: rotate(360deg); }
		}
	</style>';


	echo '</head><body>';
	echo '<div>';
	echo '<h1>Creating PDF...</h1>';
	echo '<p>PDF will automatically download once ready.</p>';
	echo '<div>';
	echo '<div class="spinner"></div>';
	echo '</body></html>';

	
	
	// Add padding to force early output flush past buffering thresholds
	for ($i = 0; $i < 500; $i++) {
		echo '<div style="display:none">.</div>';
	}
		echo '</body></html>';

	while (ob_get_level()) {
		@ob_end_flush();
	}
	@flush();

	exit;
}

/**
 * Prevent guests from placing orders on the block-based checkout.
 *
 * This function hooks into `woocommerce_store_api_checkout_order_processed`.
 * When a guest tries to check out, an order record is created just before this hook fires.
 * This function immediately deletes that order record using the HPOS-compatible method
 * and then throws an exception to halt the checkout process.
 *
 * @param \WC_Order $order The order object being processed.
 * @throws RouteException If a guest attempts to place an order, halting the process.
 */
add_action( 'woocommerce_store_api_checkout_order_processed', 'starke_block_guest_checkout_before_payment' );

function starke_block_guest_checkout_before_payment( $order ) {
    if ( $order && $order->get_customer_id() === 0 ) {
        // The `true` parameter forces a permanent deletion.
        $order->delete( true );

        // Now, throw the exception to halt the checkout process and display the error message to the user.
        throw new RouteException(
            'woocommerce_checkout_must_be_logged_in',
            __( 'You must be logged in to place an order. Please log in or create an account to continue.', 'woocommerce' ),
            403 // HTTP 403 Forbidden status
        );
    }
}

// An admin-side only block
if ( is_admin() ) {
    // Creates custom '3D PDF' column in WooCommerce Orders admin list -- START
    /*
     * Add a custom column for 3D PDFs to the WooCommerce Orders admin list
     */
    function ts_add_pdf_download_column($columns) {
        $columns['order_3d_pdf'] = '3D PDF'; 
        return $columns;
    }
    add_filter('manage_woocommerce_page_wc-orders_columns', 'ts_add_pdf_download_column');

    /*
     * Display the button if the PDF exists
     */
    function ts_display_pdf_download_column($column, $post_id) {
        if ('order_3d_pdf' === $column) {
            $order = wc_get_order($post_id);
            if (!$order) return;

            // Check if the PDF has been generated and saved to S3
            $pdf_key = $order->get_meta('_pdf_s3ObjectKey', true);
            
            if (!empty($pdf_key)) {
                $order_id = $order->get_id();
                $nonce = wp_create_nonce('download_pdf_order_quote_nonce');
                
                $status = $order->get_status();
                $quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'deleted-quote', 'freight-quote', 'ordered-quote'];
                
                if (in_array($status, $quote_statuses)) {
                    $pdf_url = set_quote_pdf_url($order_id, $nonce);
                } else {
                    $pdf_url = set_order_pdf_url($order_id, $nonce);
                }

                // Updated to 'button-primary' for the blue color and text to 'PDF'
                echo '<a href="' . esc_url($pdf_url) . '" target="_blank" class="button button-primary" style="display: block; text-align: center;">PDF</a>';
            } else {
                // Check if this is an imported legacy order
                if ( ! empty( $order->get_meta( '_legacy_order_id', true ) ) ) {
                    echo '<span style="color: #999;"></span>';
                } else {
                    echo '<span style="color: #999;">Not Ready</span>';
                }
            }
        }
    }
    add_action('manage_woocommerce_page_wc-orders_custom_column', 'ts_display_pdf_download_column', 10, 2);

    /*
     * Adjust the width of the new column so the button fits nicely
     */
    function ts_custom_pdf_column_width_css() {
        $screen = get_current_screen();
        if ( $screen && 'woocommerce_page_wc-orders' === $screen->id ) {
            ?>
            <style type="text/css">
                .wp-list-table th.column-order_3d_pdf,
                .wp-list-table td.column-order_3d_pdf {
                    width: 50px; /* Slightly tightened since 'PDF' is a short word */
                    text-align: center;
                }
            </style>
            <?php
        }
    }
    add_action( 'admin_head', 'ts_custom_pdf_column_width_css' );
    // Creates custom '3D PDF' column in WooCommerce Orders admin list -- END

    // Creates custom '3D PDF' button in the Order Actions meta box -- START
    // 1. Open a Flexbox wrapper to center the buttons perfectly
    function ts_order_actions_custom_wrapper_start() {
        echo '<div style="display: flex; justify-content: center; align-items: center; gap: 14px; margin: 10px 0 15px 0; width: 100%;">';
    }
    add_action( 'woocommerce_order_actions_start', 'ts_order_actions_custom_wrapper_start', 4 );

    // 2. Output the PDF button (Priority 5)
    function ts_add_pdf_button_to_order_actions( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // --- STARKE: Hide the PDF button entirely for Legacy Orders ---
        if ( ! empty( $order->get_meta( '_legacy_order_id', true ) ) ) {
            return;
        }

        $pdf_key = $order->get_meta( '_pdf_s3ObjectKey', true );
        
        if ( ! empty( $pdf_key ) ) {
            $nonce = wp_create_nonce( 'download_pdf_order_quote_nonce' );
            $status = $order->get_status();
            $quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'deleted-quote', 'freight-quote', 'ordered-quote'];
            
            if ( in_array( $status, $quote_statuses ) ) {
                $pdf_url = set_quote_pdf_url( $order_id, $nonce );
            } else {
                $pdf_url = set_order_pdf_url( $order_id, $nonce );
            }

            // Display the active blue PDF button with NO margins (wrapper handles it)
            echo '<a href="' . esc_url( $pdf_url ) . '" target="_blank" class="button button-primary" style="margin: 0;">PDF</a>';
        } else {
            // Display a greyed-out 'N/A' button with NO margins
            echo '<span class="button" style="margin: 0; color: #999; cursor: not-allowed;" disabled>N/A</span>';
        }
    }
    add_action( 'woocommerce_order_actions_start', 'ts_add_pdf_button_to_order_actions', 12, 1 );

    // 3. Close the Flexbox wrapper
    function ts_order_actions_custom_wrapper_end() {
        echo '</div>';
    }
    add_action( 'woocommerce_order_actions_start', 'ts_order_actions_custom_wrapper_end', 15 );
    
    // Creates custom '3D PDF' button in the Order Actions meta box -- END
}

// ==========================================================================
// CUSTOM COLUMNS: OUTSTANDING AMOUNT & AMOUNT DUE
// ==========================================================================

/**
 * 1. Add columns immediately after the "Order Total" column
 */
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'ts_add_financial_columns' );
function ts_add_financial_columns( $columns ) {
    $reordered = array();
    
    foreach ( $columns as $key => $title ) {
        $reordered[ $key ] = $title;
        
        // Insert right after the Order Total
        if ( 'order_total' === $key ) {
            $reordered['outstanding_amount'] = 'Outstanding Amount';
            $reordered['amount_due']         = 'Amount Due';
        }
    }
    
    return $reordered;
}

/**
 * 2. Populate the columns with the correct math (Read-Only, Highly Performant)
 */
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'ts_display_financial_columns', 10, 2 );
function ts_display_financial_columns( $column_id, $order ) {
    if ( ! in_array( $column_id, ['outstanding_amount', 'amount_due'] ) ) {
        return;
    }

    // HPOS compatibility fallback
    if ( ! is_object( $order ) ) {
        $order = wc_get_order( $order );
    }
    
    if ( ! $order ) return;

    // Skip quote statuses (Display a dash)
    $quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'freight-quote', 'deleted-quote', 'ordered-quote', 'profiles-ready'];
    if ( in_array( $order->get_status(), $quote_statuses ) ) {
        echo '<span style="color:#999;">-</span>';
        return;
    }

    // Cancelled/Refunded/Failed = Nothing is due/outstanding
    if ( $order->has_status( ['cancelled', 'failed', 'refunded', 'trash'] ) ) {
        echo '<span style="color: #46b450;">' . wc_price( 0, ['currency' => $order->get_currency()] ) . '</span>';
        return;
    }

    // Failsafe if the payment manager class isn't loaded
    if ( ! class_exists( 'Starke_Payment_Manager' ) ) {
        echo wc_price( $order->get_total(), ['currency' => $order->get_currency()] );
        return;
    }

    // Calculate using your central splits logic
    $splits = Starke_Payment_Manager::get_payment_splits( $order );
    
    // Initial Order Math
    $initial_paid = $order->is_paid();
    $initial_due  = $initial_paid ? 0.00 : $splits['required_deposit'];

    // Balance Invoice Math
    $balance_invoice_id = $order->get_meta( '_starke_balance_order_id', true );
    $balance_due        = 0.00;
    $unbilled_deferred  = 0.00;

    if ( $balance_invoice_id ) {
        $invoice = wc_get_order( $balance_invoice_id );
        // If the invoice is sent but not paid, that amount is now DUE
        if ( $invoice && ! $invoice->is_paid() && ! $invoice->has_status(['cancelled', 'failed', 'refunded']) ) {
            $balance_due = (float) $invoice->get_total();
        }
    } else {
        // Invoice not sent yet. The deferred balance is Outstanding, but NOT Due.
        $unbilled_deferred = $splits['deferred_balance'];
    }

    $amount_due_now = $initial_due + $balance_due;
    $outstanding    = $amount_due_now + $unbilled_deferred;

    // Render Outstanding Amount
    if ( 'outstanding_amount' === $column_id ) {
        if ( $outstanding > 0 ) {
            echo wc_price( $outstanding, ['currency' => $order->get_currency()] );
        } else {
            echo '<span style="color: #46b450;">' . wc_price( 0, ['currency' => $order->get_currency()] ) . '</span>';
        }
    }

    // Render Amount Due
    if ( 'amount_due' === $column_id ) {
        if ( $amount_due_now > 0 ) {
            echo '<strong style="color: #d63638;">' . wc_price( $amount_due_now, ['currency' => $order->get_currency()] ) . '</strong>';
        } else {
            echo '<span style="color: #46b450;">' . wc_price( 0, ['currency' => $order->get_currency()] ) . '</span>';
        }
    }
}

/**
 * 3. Register the columns as sortable
 */
add_filter( 'manage_woocommerce_page_wc-orders_sortable_columns', 'ts_make_financial_columns_sortable' );
function ts_make_financial_columns_sortable( $sortable_columns ) {
    $sortable_columns['outstanding_amount'] = 'outstanding_amount';
    $sortable_columns['amount_due']         = 'amount_due';
    return $sortable_columns;
}

/**
 * 4. Apply sorting logic based on the saved metadata
 */
add_filter( 'woocommerce_order_query_args', 'ts_financial_columns_sorter', 10, 1 );
function ts_financial_columns_sorter( $query_args ) {
    if ( isset( $_GET['orderby'] ) ) {
        if ( 'outstanding_amount' === $_GET['orderby'] ) {
            $query_args['meta_key'] = '_starke_outstanding_sort';
            $query_args['orderby']  = 'meta_value_num';
            $query_args['order']    = isset( $_GET['order'] ) ? strtoupper( $_GET['order'] ) : 'DESC';
        } elseif ( 'amount_due' === $_GET['orderby'] ) {
            $query_args['meta_key'] = '_starke_amount_due_sort';
            $query_args['orderby']  = 'meta_value_num';
            $query_args['order']    = isset( $_GET['order'] ) ? strtoupper( $_GET['order'] ) : 'DESC';
        }
    }
    return $query_args;
}

/**
 * 5. Sync Data: Update sorting metadata whenever order status changes
 */
add_action( 'woocommerce_order_status_changed', 'ts_update_financial_meta_on_status_change', 10, 4 );
function ts_update_financial_meta_on_status_change( $order_id, $old_status, $new_status, $order ) {
    if ( ! class_exists( 'Starke_Payment_Manager' ) ) return;

    // If a Balance Invoice is paid/changed, we need to recalculate the PARENT order
    if ( 'yes' === $order->get_meta('_starke_is_balance_invoice', true) ) {
        $parent_id = $order->get_parent_id();
        if ( $parent_id ) {
            $parent_order = wc_get_order( $parent_id );
            if ( $parent_order ) {
                ts_calculate_and_save_financial_meta( $parent_order );
            }
        }
        return;
    }

    // Otherwise, calculate this standard order
    ts_calculate_and_save_financial_meta( $order );
}

/**
 * 6. Safety Net: Update metadata when an order is manually saved/edited
 * (Ensures manual line-item edits keep the sorting perfectly accurate)
 */
add_action( 'woocommerce_update_order', 'ts_update_financial_meta_on_order_save', 10, 2 );
function ts_update_financial_meta_on_order_save( $order_id, $order ) {
    // Prevent infinite loops if saving the metadata triggers this hook again
    static $is_running = false;
    if ( $is_running || ! class_exists( 'Starke_Payment_Manager' ) ) return;

    $is_running = true;

    // If a Balance Invoice is edited, recalculate the PARENT order
    if ( 'yes' === $order->get_meta('_starke_is_balance_invoice', true) ) {
        $parent_id = $order->get_parent_id();
        if ( $parent_id ) {
            $parent_order = wc_get_order( $parent_id );
            if ( $parent_order ) {
                ts_calculate_and_save_financial_meta( $parent_order );
            }
        }
    } else {
        // Otherwise, calculate this standard order
        ts_calculate_and_save_financial_meta( $order );
    }

    $is_running = false;
}

/**
 * Helper Function: Runs the math and saves it silently so it can be sorted
 */
function ts_calculate_and_save_financial_meta( $order ) {
    $quote_statuses = ['active-quote', 'expired-quote', 'pending-quote', 'freight-quote', 'deleted-quote', 'ordered-quote', 'profiles-ready'];
    
    if ( in_array( $order->get_status(), $quote_statuses ) || $order->has_status(['cancelled', 'failed', 'refunded', 'trash']) ) {
        $order->update_meta_data( '_starke_amount_due_sort', 0 );
        $order->update_meta_data( '_starke_outstanding_sort', 0 );
        $order->save_meta_data();
        return;
    }

    $splits       = Starke_Payment_Manager::get_payment_splits( $order );
    $initial_paid = $order->is_paid();
    $initial_due  = $initial_paid ? 0.00 : $splits['required_deposit'];

    $balance_invoice_id = $order->get_meta( '_starke_balance_order_id', true );
    $balance_due        = 0.00;
    $unbilled_deferred  = 0.00;

    if ( $balance_invoice_id ) {
        $invoice = wc_get_order( $balance_invoice_id );
        if ( $invoice && ! $invoice->is_paid() && ! $invoice->has_status(['cancelled', 'failed', 'refunded']) ) {
            $balance_due = (float) $invoice->get_total();
        }
    } else {
        $unbilled_deferred = $splits['deferred_balance'];
    }

    $amount_due_now = $initial_due + $balance_due;
    $outstanding    = $amount_due_now + $unbilled_deferred;

    // Save for the HPOS sorting query
    $order->update_meta_data( '_starke_amount_due_sort', $amount_due_now );
    $order->update_meta_data( '_starke_outstanding_sort', $outstanding );
    $order->save_meta_data();
}

add_action( 'woocommerce_customer_reset_password', 'starke_auto_login_and_resume_quote', 10, 1 );

function starke_auto_login_and_resume_quote( $user ) {
    // Mark the account as fully activated so the interceptor ignores them next time
    update_user_meta( $user->ID, '_starke_password_set_done', 'yes' );
    update_user_meta( $user->ID, '_starke_has_logged_in_before', 'yes' );
    
    // Auto-login the user seamlessly
    wp_set_current_user( $user->ID );
    wp_set_auth_cookie( $user->ID );
    
    // CHANGE: Default redirect to My Account page instead of Checkout
    $redirect_url = wc_get_page_permalink( 'myaccount' );
    
    // Retrieve the quote ID from the database
    $pending_quote_id = get_user_meta( $user->ID, '_starke_quote_link_for_redirect', true );
    
    if ( ! empty( $pending_quote_id ) ) {
        $order = wc_get_order( $pending_quote_id );
        
        if ( $order ) {
            // Only if they have a pending quote, switch the destination to checkout
            $redirect_url = wc_get_checkout_url(); 
            
            $redirect_url = add_query_arg( array(
                'quote'    => $pending_quote_id,
                'quote_id' => $order->get_meta( 'quote_link_id' )
            ), $redirect_url );
        }
        
        // Clear the memory from the database so it doesn't loop next time
        delete_user_meta( $user->ID, '_starke_quote_link_for_redirect' );
    }

    // Preempt the native WooCommerce redirect and send them to the appropriate page
    wp_safe_redirect( $redirect_url );
    exit;
}

add_action( 'wp_login', 'starke_track_user_first_login', 10, 2 );

function starke_track_user_first_login( $user_login, $user ) {
    // CRITICAL FIX: If an admin is impersonating the user, DO NOT track this as a real login!
    if ( isset( $_REQUEST['action'] ) && 'login_as_customer' === $_REQUEST['action'] ) {
        return; 
    }
    
    // If it is a normal login, tag them as an active user
    update_user_meta( $user->ID, '_starke_has_logged_in_before', 'yes' );
}




























// ==========================================================================
// ACTION SCHEDULER UTILITY: SYNC HISTORICAL FINANCIAL DATA - START (ONE-TIME USE FOR SITE ORDERS MIGRATION)
// (USED FOR SETTING THE 'OUTSTANDING AMOUNT' AND 'AMOUNT DUE' ADMIN ORDERS LIST COLUMNS FOR IMPORTED LEGACY ORDERS)
// ==========================================================================

/**
 * 1. The Worker Function: This does the actual math and saving for a single order.
 */
/*add_action( 'starke_sync_single_order_financials', 'starke_as_sync_single_order' );
function starke_as_sync_single_order( $order_id ) {
    $order = wc_get_order( $order_id );
    
    // Make sure the order exists and our math function is loaded
    if ( $order && function_exists( 'ts_calculate_and_save_financial_meta' ) ) {
        ts_calculate_and_save_financial_meta( $order );
    }
}*/

/**
 * 2. The Trigger: This finds all missing orders and queues them up in Action Scheduler.
 */
/*add_action( 'admin_init', 'starke_trigger_as_financial_sync' );
function starke_trigger_as_financial_sync() {
    // Only run if the specific URL parameter is present and user is an admin
    if ( ! isset( $_GET['starke_queue_sync'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    // Ensure Action Scheduler is active
    if ( ! function_exists( 'as_schedule_single_action' ) ) {
        wp_die( 'Action Scheduler is not active on this site.' );
    }

    // Fetch ONLY the IDs of orders that do NOT have the sorting meta key yet
    $orders = wc_get_orders( array(
        'limit'      => -1, // Get all of them
        'return'     => 'ids',
        'meta_query' => array(
            array(
                'key'     => '_starke_amount_due_sort',
                'compare' => 'NOT EXISTS'
            )
        )
    ));

    // If no orders are found, we are done!
    if ( empty( $orders ) ) {
        wp_die( 
            '<h1 style="color:#46b450;">Nothing to Sync!</h1>
             <p>All historical orders already have the financial metadata.</p>
             <a href="' . admin_url('admin.php?page=wc-orders') . '" class="button button-primary">Return to Orders</a>' 
        );
    }

    // Queue them up in Action Scheduler
    $group = 'starke_financial_sync';
    foreach ( $orders as $order_id ) {
        // Schedule each order to be processed immediately (Action Scheduler will batch them safely)
        as_schedule_single_action( time(), 'starke_sync_single_order_financials', array( $order_id ), $group );
    }

    // Success message
    wp_die( 
        '<h1 style="color:#46b450;">Success! Tasks Queued.</h1>
         <p>Successfully queued <strong>' . count( $orders ) . '</strong> orders for background syncing.</p>
         <p>Action Scheduler will now process these in the background. It will not slow down your site.</p>
         <p>You can monitor the progress by going to <strong>WooCommerce > Status > Scheduled Actions > Pending</strong>.</p>
         <a href="' . admin_url('admin.php?page=wc-orders') . '" class="button button-primary">Return to Orders</a>' 
    );
}*/

// ==========================================================================
// ACTION SCHEDULER UTILITY: SYNC HISTORICAL FINANCIAL DATA - END
// ==========================================================================





