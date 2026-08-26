<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Class for handling balance invoice creation, display, and related payment management features.
class Starke_Payment_Manager {

    public function __construct() {
        // 1. Manual Button (Admin Order Screen - Sidebar)
        add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ), 1 );
        add_action( 'wp_ajax_starke_create_balance_invoice', array( $this, 'create_balance_invoice_ajax' ) );

        // --- NEW: Handle Manual Resend Button ---
        add_action( 'wp_ajax_starke_resend_invoice_email', array( $this, 'resend_invoice_email_ajax' ) );

        // 2. Net 30 Automation
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'schedule_net30_invoice' ), 60, 1 );
        add_action( 'starke_send_scheduled_invoice', array( $this, 'process_scheduled_invoice_check' ), 10, 1 );

        // 3. Display Logic: Handle Tax Lines
        add_filter( 'woocommerce_get_order_item_totals', array( $this, 'hide_tax_line_for_balance_invoices' ), 20, 2 );

        // --- NEW: Add Payment Terms Rows (Amount Paid / Balance Due) ---
        add_filter( 'woocommerce_get_order_item_totals', array( $this, 'add_custom_payment_terms_rows' ), 20, 3 );

        // 4. Suppress Admin "New Order" Email
        add_filter( 'woocommerce_email_recipient_new_order', array( $this, 'disable_admin_new_order_for_balance_invoices' ), 10, 2 );

        // --- NEW: Thank You Page Customizations (Balance Invoices) ---
        add_filter( 'woocommerce_order_number', array( $this, 'starke_override_thankyou_order_number' ), 20, 2 );
        
        // --- NEW: THE FIX for the Page Heading "Order received" ---
        add_filter( 'gettext', array( $this, 'starke_replace_order_received_text' ), 20, 3 );
        add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'starke_custom_thankyou_text' ), 20, 2 );
        add_filter( 'gettext', array( $this, 'starke_custom_product_column_header' ), 20, 3 );

        // --- NEW: Force Browser Tab Title (High Priority Override) ---
        add_filter( 'pre_get_document_title', array( $this, 'starke_force_browser_title' ), 999 );

        // 5. Remove "Order Again" button
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'remove_order_again_button_on_view_order' ), 5, 1 );

        // --- NEW: Hide Invoice Orders from Lists (HPOS Compatible - Stronger Hook) ---
        add_filter( 'woocommerce_order_query_args', array( $this, 'hide_invoices_from_admin_list_hpos' ) );
        add_filter( 'woocommerce_my_account_my_orders_query', array( $this, 'hide_invoices_from_my_account' ) );

        // --- NEW: AJAX Handlers for Consolidated View ---
        add_action( 'wp_ajax_starke_update_invoice_status', array( $this, 'ajax_update_invoice_status' ) );
        add_action( 'wp_ajax_starke_refund_invoice', array( $this, 'ajax_refund_invoice' ) );
        
        // --- NEW: Add Scripts & Styles for the Metaboxes ---
        add_action( 'admin_footer', array( $this, 'inject_admin_scripts' ) );
        add_action( 'admin_head', array( $this, 'inject_admin_styles' ) ); // <-- NEW: Runs immediately

        // Force Admin List to show True Grand Total (inc. Invoice Fees) ---
        add_filter( 'woocommerce_get_formatted_order_total', array( $this, 'display_true_grand_total_in_admin_list' ), 10, 2 );

        // --- NEW: Action Scheduler Email Worker ---
        add_action( 'starke_async_send_balance_invoice', array( $this, 'send_balance_invoice_worker' ), 10, 1 );

        // Changed Priority to 5 to run before most emails
        add_action( 'woocommerce_order_status_changed', array( $this, 'regenerate_pdf_on_status_change' ), 5, 4 );

        // --- NEW: Block Native Emails (Forces them to wait for the Async Worker) ---
        add_filter( 'woocommerce_email_recipient_customer_processing_order', array( $this, 'block_native_email_for_balance_invoice' ), 10, 2 );
        add_filter( 'woocommerce_email_recipient_customer_on_hold_order', array( $this, 'block_native_email_for_balance_invoice' ), 10, 2 );
        add_filter( 'woocommerce_email_recipient_customer_completed_order', array( $this, 'block_native_email_for_balance_invoice' ), 10, 2 );

        // --- NEW: Admin Balance Paid Notification (Async Worker) ---
        add_action( 'woocommerce_order_status_processing', array( $this, 'schedule_admin_balance_paid_email' ), 10, 2 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'schedule_admin_balance_paid_email' ), 10, 2 );
        add_action( 'starke_async_send_admin_balance_paid', array( $this, 'process_admin_balance_paid_email_worker' ), 10, 1 );

        // --- NEW: Async Balance Invoice Worker ---
        add_action( 'wp_ajax_starke_async_balance_process', array( $this, 'async_balance_process_handler' ) );
        add_action( 'wp_ajax_nopriv_starke_async_balance_process', array( $this, 'async_balance_process_handler' ) );

        // --- NEW: Sync Balance Invoice Notes to Parent Order ---
        add_action( 'woocommerce_order_note_added', array( $this, 'sync_balance_invoice_notes_to_parent' ), 10, 2 );

        // --- NEW: Suppress Payment Gateway Instructions in Emails for Net 30 ---
        add_action( 'woocommerce_email_before_order_table', array( $this, 'suppress_net30_gateway_email_instructions' ), 5, 4 );
        // --- NEW: Inject CC Emails for Balance Invoice Customer Emails ---
        add_filter( 'woocommerce_email_headers', array( $this, 'maybe_add_cc_headers_for_balance_invoice' ), 9999, 3 );

        // --- NEW: Change Payment Method Text on Thank You Page ---
        add_filter( 'woocommerce_order_get_payment_method_title', array( $this, 'starke_net30_thankyou_payment_text' ), 10, 2 );
    }

    /**
     * HIDE (HPOS): Exclude Balance Invoices from Admin Order List
     */
    public function hide_invoices_from_admin_list_hpos( $args ) {
        if ( ! is_admin() ) return $args;

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'woocommerce_page_wc-orders' !== $screen->id ) return $args;

        if ( isset( $_GET['s'] ) && ! empty( $_GET['s'] ) ) return $args;

        $meta_query = isset( $args['meta_query'] ) ? $args['meta_query'] : array();
        $meta_query[] = array(
            'key'     => '_starke_is_balance_invoice',
            'compare' => 'NOT EXISTS',
        );
        $args['meta_query'] = $meta_query;

        return $args;
    }

    /**
     * HIDE: Exclude Balance Invoices from My Account
     */
    public function hide_invoices_from_my_account( $args ) {
        $meta_query = isset( $args['meta_query'] ) ? $args['meta_query'] : [];
        $meta_query[] = array(
            'key'     => '_starke_is_balance_invoice',
            'compare' => 'NOT EXISTS',
        );
        $args['meta_query'] = $meta_query;
        return $args;
    }

    /**
     * REGISTER: Add all custom meta boxes
     * LOGIC: Sidebar is global. Detail boxes are conditional.
     */
    public function register_meta_boxes() {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

        // 1. Determine Order ID
        $order_id = 0;
        if ( isset( $_GET['id'] ) ) {
            $order_id = absint( $_GET['id'] );
        } elseif ( isset( $_GET['post'] ) ) {
            $order_id = absint( $_GET['post'] );
        }

        // 2. Logic Check
        $is_complex_order = false;
        
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $quote_statuses = array( 'active-quote', 'expired-quote', 'pending-quote', 'freight-quote', 'deleted-quote', 'ordered-quote', 'profiles-ready' );
                if ( in_array( $order->get_status(), $quote_statuses ) ) {
                    return;
                }
                $term = $order->get_meta( '_starke_payment_terms', true );
                $has_invoice = $order->get_meta( '_starke_balance_order_id', true );

                // Show details if terms exist OR if an invoice was manually created later
                if ( ( ! empty( $term ) && 'no_terms' !== $term ) || ! empty( $has_invoice ) ) {
                    $is_complex_order = true;
                }
            }
        }

        // 3. Sidebar Actions: SHOW FOR EVERYONE (The "Green Light" Dashboard)
        add_meta_box(
            'starke_payment_actions',
            'Payment Terms Actions',
            array( $this, 'render_payment_actions_meta_box' ),
            $screen, 
            'side',
            'high'
        );

        // 4. Detailed Boxes: ONLY SHOW FOR COMPLEX ORDERS
        if ( $is_complex_order ) {
            // Invoice Items (The Big Table)
            add_meta_box(
                'starke_invoice_items',
                'Balance Invoice Details',
                array( $this, 'render_invoice_items_meta_box' ),
                $screen,
                'normal',
                'low'
            );

            // Project Totals (The Summary)
            add_meta_box(
                'starke_project_totals',
                'Order Balance Summary',
                array( $this, 'render_project_totals_meta_box' ),
                $screen,
                'normal',
                'low'
            );
        }
    }

    /**
     * RENDER: Invoice Items Metabox
     * EXACT visual replica of the WooCommerce Order Items Metabox.
     * Final adjustments for header alignment, colspan, and bold totals.
     */
    public function render_invoice_items_meta_box( $post_or_order_object ) {
        $order = ( $post_or_order_object instanceof WC_Order ) ? $post_or_order_object : wc_get_order( $post_or_order_object->ID );
        if ( ! $order ) return;

        $invoice_id = $order->get_meta( '_starke_balance_order_id', true );
        if ( ! $invoice_id ) {
            echo '<p style="padding:10px; color:#666;">No balance invoice generated for this order yet.</p>';
            return;
        }

        $invoice = wc_get_order( $invoice_id );
        if ( ! $invoice ) return;

        // --- NEW: Use Starke ID if available ---
        $starke_invoice_num = $invoice->get_meta( '_starke_order_number', true );
        $display_invoice_id = ! empty( $starke_invoice_num ) ? $starke_invoice_num : $invoice_id;

        $currency = $invoice->get_currency();
        $invoice_status = $invoice->get_status();
        $statuses = wc_get_order_statuses();
        $total_refunded = $invoice->get_total_refunded();
        $remaining_refund_amount = $invoice->get_remaining_refund_amount();
        
        // --- NEW: Get specific tax codes for dynamic columns ---
        $order_taxes = $invoice->get_taxes(); 
        $has_taxes   = ! empty( $order_taxes );

        $tax_display = 'display:none;'; 
        $has_taxes = count( $invoice->get_items( 'tax' ) ) > 0;
        if ( $has_taxes ) $tax_display = '';

        ?>
        <style>
            /* 1. TABLE STRUCTURE */
            .starke_invoice_table { width: 100%; text-align: left; border-collapse: collapse; border: 1px solid #e5e5e5; background:#fff; margin: 0; }
            
            /* General Header Style */
            .starke_invoice_table thead th { 
                background: #f8f8f8; color: #333; font-weight: 600; vertical-align: middle; 
                padding: 1em 1em 1em 2em; border-bottom: 1px solid #e5e5e5;
            }

            /* NEW: Add extra padding to the rightmost header */
            .starke_invoice_table thead th.wc-order-edit-line-item {
                padding-right: 2em; 
            }

            /* Specific Header Alignment Fixes */
            .starke_invoice_table thead th.item { text-align: left; }
            .starke_invoice_table thead th.item_cost { text-align: right; }
            .starke_invoice_table thead th.quantity { text-align: center; }
            .starke_invoice_table thead th.line_cost { text-align: right; }
            .starke_invoice_table thead th.line_tax { text-align: right; }
            
            /* 2. TABLE COLUMNS */
            .starke_invoice_table td { vertical-align: top; padding: 1em 1em 1em 0.5em; border-bottom: 1px solid #e5e5e5; color: #555; }
            
            /* --- CHANGE: Thumbnail width increased to 90px --- */
            .starke_invoice_table td.thumb { width: 25px; text-align: center; padding: 1em 2em; }
            
            .starke_invoice_table td.name { color: #0073aa; font-weight: 600; }
            .starke_invoice_table td.item_cost { width: 15%; text-align: right; }
            .starke_invoice_table td.quantity { width: 7%; text-align: center; white-space: nowrap; }
            .starke_invoice_table td.line_cost { width: 15%; text-align: right; }
            .starke_invoice_table td.line_tax { width: 10%; text-align: right; }

            .starke_invoice_table .wc-order-item-thumbnail { margin: 0; display:inline-block; width: 100%; }
            /* Added !important to override default WP emoji sizing (1em) */
            .starke_invoice_table .wc-order-item-thumbnail img { 
                border: 1px solid #dfdfdf; 
                width: 100% !important; 
                height: auto !important; 
                display: block; 
            }
            .starke_invoice_table .wc-order-item-name { display: block; margin-bottom: 4px; color: #0073aa; text-decoration: none; font-weight: 600; }
            .starke_invoice_table .wc-order-item-sku { color: #666; display: block; }
            
            /* 3. INPUTS & REFUND ROWS */
            .starke-refund-input { margin-top: 4px; }
            .starke_invoice_table input.text { padding: 4px; }

            /* 4. TOTALS TABLE - BOLD FIXES */
            .starke-wc-order-totals { float: right; width: auto; text-align: right; border: 0; margin: 0; padding: 0; }
            .starke-wc-order-totals tbody tr td { border: 0; padding: 5px 0; background: transparent; }
            .starke-wc-order-totals td.label { color: #667; font-weight: 600; text-align: right; vertical-align: top; }
            /* Changed font-weight to 700 for bold amounts */
            .starke-wc-order-totals td.total { text-align: right; font-weight: 700; vertical-align: top; width: 10em; margin: 0 0 0 .5em; box-sizing: border-box; }
            /* Ensure amounts inside spans are also bold */
            .starke-wc-order-totals .amount { font-weight: 700; }
            
            .starke-wc-order-totals .wc-order-refund-amount { font-weight: 600; }
            .starke-wc-order-totals .text-#d63638 { color: #a00; }
            .starke-wc-order-totals .text-success { color: #46b450; }
            
            /* 5. SECTIONS */
            .starke-invoice-footer { padding: 1.5em 2em; border-top: 1px solid #e5e5e5; background: #f8f8f8; }
            .starke-refund-items { display:none; background: #f8f8f8; padding: 1.5em 2em; }
            .starke-refund-actions { text-align: right; padding-top: 12px; margin-top: 6px; border-top: 1px solid #dfdfdf;}
            .starke-header-actions { padding: 1em 2em; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
            .clear { clear: both; }

            .button.cancel-action { margin-right: 10px; }
            #woocommerce-order-items .wc-order-refund-items .refund-actions .cancel-action { float: right; }
            #starke_invoice_items .inside { padding: 0; margin: 0; }
            input[type=number], input[type=text] { line-height: normal; }
            #woocommerce-order-items .wc-order-totals .label { font-weight: 600; }
            #woocommerce-order-items .woocommerce_order_items_wrapper { overflow-x: visible; }
            #woocommerce-order-items .woocommerce_order_items_wrapper table.woocommerce_order_items thead th { font-weight: 600; color: #333; }
        </style>

        <div class="starke-header-actions">
            <div>
                <strong>Invoice #<?php echo esc_html( $display_invoice_id ); ?></strong> 
                <span class="status-label status-<?php echo esc_attr( $invoice_status ); ?>">
                    (<?php echo esc_html( wc_get_order_status_name( $invoice_status ) ); ?>)
                </span>
            </div>
            <div style="display:flex; align-items:center; gap:10px;">
                <label for="starke_invoice_status_select"><strong>Change Status:</strong></label>
                <select id="starke_invoice_status_select" data-invoice-id="<?php echo esc_attr( $invoice_id ); ?>">
                    <?php foreach ( $statuses as $status_key => $status_name ) : ?>
                        <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( 'wc-' . $invoice_status, $status_key ); ?>>
                            <?php echo esc_html( $status_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button button-secondary" id="starke_update_invoice_status">Update</button>
            </div>
        </div>

        <div id="starke-invoice-wrapper">
            <table class="starke_invoice_table" cellspacing="0" cellpadding="0">
                <thead>
                    <tr>
                        <th class="item sortable" colspan="2" data-sort="string-ins">Item</th>
                        <th class="item_cost sortable" data-sort="float">Price</th>
                        <th class="quantity sortable" data-sort="int">Qty</th>
                        <th class="line_cost sortable" data-sort="float">Total</th>
                        <?php 
                        // --- UPDATED: Dynamic Tax Columns ---
                        if ( $has_taxes ) {
                            foreach ( $order_taxes as $tax_id => $tax_item ) {
                                echo '<th class="line_tax tips">' . esc_html( $tax_item->get_label() ) . '</th>';
                            }
                        }
                        ?>
                        <th class="wc-order-edit-line-item" width="1%"></th>
                    </tr>
                </thead>
                <tbody id="starke-invoice-list">
                    <?php 
                    // UPDATED: Include 'fee' items so Credit Card Fees appear
                    foreach ( $invoice->get_items( array('line_item', 'fee') ) as $item_id => $item ) : 
                        $item_type       = $item->get_type(); // <-- NEW: Get type ('line_item' or 'fee')
                        $item_unit_price = $invoice->get_item_total( $item, false, false ); 
                        $item_total      = $item->get_total();
                        
                        // --- FIX: Pass item type to correctly fetch refunds for fees ---
                        $refunded_qty   = $invoice->get_qty_refunded_for_item( $item_id, $item_type );
                        $refunded_total = $invoice->get_total_refunded_for_item( $item_id, $item_type );
                        
                        // Handle Thumbnail (Safe for Fees)
                        $product = null;
                        $thumbnail = '📄'; 
                        
                        if ( is_a( $item, 'WC_Order_Item_Product' ) ) {
                            $product = $item->get_product();
                            if ( $product ) {
                                $thumbnail = $product->get_image( 'thumbnail', array( 'title' => '' ) );
                            }
                        } elseif ( is_a( $item, 'WC_Order_Item_Fee' ) ) {
                            $thumbnail = '<div style="font-size:20px;line-height:1;">💳</div>';
                        }
                    ?>
                    <tr class="item">
                        <td class="thumb">
                            <div class="wc-order-item-thumbnail"><?php echo $thumbnail; ?></div>
                        </td>
                        <td class="name">
                            <strong class="wc-order-item-name"><?php echo esc_html( $item->get_name() ); ?></strong>
                            <?php if ( $product && $product->get_sku() ) : ?>
                                <div class="wc-order-item-sku"><strong>SKU:</strong> <?php echo esc_html( $product->get_sku() ); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="item_cost">
                            <div class="view">
                                <?php echo wc_price( $item_unit_price, array( 'currency' => $currency ) ); ?>
                            </div>
                        </td>
                        <td class="quantity">
                            <div class="view">
                                <small class="times">&times;</small> <?php echo esc_html( $item->get_quantity() ); ?>
                                <?php if ( $refunded_qty < 0 ) : ?>
                                    <small class="refunded" style="color: #d63638; display:block; margin-top:4px; font-weight:600;"><?php echo esc_html( $refunded_qty ); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="starke-refund-input" style="display:none;">
                                <input type="number" class="text starke_refund_order_item_qty" name="refund_order_item_qty[<?php echo esc_attr( $item_id ); ?>]" value="" placeholder="0" size="4" style="width: 50px; text-align: center;" data-item-id="<?php echo esc_attr( $item_id ); ?>" data-price="<?php echo esc_attr( $item_unit_price ); ?>" max="<?php echo esc_attr( $item->get_quantity() ); ?>">
                            </div>
                        </td>
                        <td class="line_cost">
                            <div class="view">
                                <?php echo wc_price( $item_total, array( 'currency' => $currency ) ); ?>
                                <?php if ( $refunded_total > 0 ) : ?>
                                    <small class="refunded" style="color: #d63638; display:block; margin-top:4px; font-weight:600;">-<?php echo wc_price( $refunded_total, array( 'currency' => $currency ) ); ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="starke-refund-input" style="display:none;">
                                <input type="text" class="text starke_refund_line_total" id="starke_refund_line_total_<?php echo esc_attr( $item_id ); ?>" name="refund_order_item_line_total[<?php echo esc_attr( $item_id ); ?>]" data-limit="<?php echo esc_attr( $item_total ); ?>" value="" placeholder="0.00" style="width: 70px; text-align: right;">
                            </div>
                        </td>
                        
                        <?php 
                        // --- UPDATED: Dynamic Tax Cells Loop (FIXED) ---
                        if ( $has_taxes ) {
                            foreach ( $order_taxes as $tax_item ) {
                                // 1. Get the Rate ID for this specific column (e.g., PA Tax)
                                $rate_id = $tax_item->get_rate_id();
                                
                                // 2. Get all taxes stored on this specific item
                                $item_taxes = $item->get_taxes();
                                
                                // 3. Find the amount matching the Rate ID
                                $tax_amount = isset( $item_taxes['total'][ $rate_id ] ) ? $item_taxes['total'][ $rate_id ] : '';
                                
                                // --- FIX: Pass item type to correctly fetch refunded tax for fees ---
                                $tax_refunded = $invoice->get_tax_refunded_for_item( $item_id, $rate_id, $item_type );

                                ?>
                                <td class="line_tax">
                                    <div class="view">
                                        <?php echo ( '' !== $tax_amount ) ? wc_price( $tax_amount, array( 'currency' => $currency ) ) : '<span class="na">&ndash;</span>'; ?>
                                        <?php if ( $tax_refunded > 0 ) : ?>
                                            <small class="refunded" style="color: #d63638; display:block; margin-top:4px; font-weight:600;">-<?php echo wc_price( $tax_refunded, array( 'currency' => $currency ) ); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( '' !== $tax_amount && (float)$tax_amount > 0 ) : ?>
                                        <div class="starke-refund-input" style="display:none; margin-top: 4px;">
                                            <input type="text" class="text starke_refund_line_tax" 
                                                   data-item-id="<?php echo esc_attr( $item_id ); ?>" 
                                                   data-tax-id="<?php echo esc_attr( $rate_id ); ?>" 
                                                   value="" 
                                                   placeholder="0.00" 
                                                   style="width: 60px; text-align: right;">
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <?php
                            }
                        }
                        ?>

                        <td class="wc-order-edit-line-item"></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php
                    // --- NEW: INJECT NATIVE-LOOKING REFUND ROWS ---
                    if ( $invoice->get_refunds() ) {
                        foreach ( $invoice->get_refunds() as $refund ) {
                            $who_refunded = new WP_User( $refund->get_refunded_by() );
                            ?>
                            <tr class="refund" style="background-color: #fcfcfc;">
                                <td class="thumb">
                                    <div></div>
                                </td>
                                <td class="name" style="color: #3c434a; font-weight: 400;">
                                    <?php 
                                    // Match native WooCommerce format exactly
                                    echo esc_html( sprintf( __( 'Refund #%s', 'woocommerce' ), $refund->get_id() ) );
                                    echo ' - ' . esc_html( wc_format_datetime( $refund->get_date_created(), get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) ) );
                                    
                                    if ( $who_refunded->exists() ) {
                                        echo ' ' . sprintf( __( 'by %s', 'woocommerce' ), '<abbr class="refund_by" title="' . esc_attr( sprintf( __( 'ID: %d', 'woocommerce' ), $who_refunded->ID ) ) . '">' . esc_html( $who_refunded->display_name ) . '</abbr>' );
                                    }
                                    ?>
                                    <?php if ( $refund->get_reason() ) : ?>
                                        <p class="description" style="color: #3c434a;"><?php echo esc_html( $refund->get_reason() ); ?></p>
                                    <?php endif; ?>
                                    <input type="hidden" class="order_refund_id" name="order_refund_id[]" value="<?php echo esc_attr( $refund->get_id() ); ?>" />
                                </td>
                                <td class="item_cost"></td>
                                <td class="quantity"></td>
                                <td class="line_cost" style="color: #d63638; font-weight:600;">
                                    -<?php echo wc_price( $refund->get_amount(), array( 'currency' => $currency ) ); ?>
                                </td>
                                <?php 
                                // Process taxes for the refund row
                                if ( $has_taxes ) {
                                    $refund_taxes = $refund->get_taxes();
                                    foreach ( $order_taxes as $tax_item ) {
                                        $rate_id = $tax_item->get_rate_id();
                                        $refund_tax_amount = 0;
                                        foreach ( $refund_taxes as $rt ) {
                                            if ( $rt->get_rate_id() == $rate_id ) {
                                                $refund_tax_amount += abs( $rt->get_tax_total() + $rt->get_shipping_tax_total() );
                                            }
                                        }
                                        ?>
                                        <td class="line_tax" style="color: #d63638; font-weight:600;">
                                            <?php echo ( $refund_tax_amount > 0 ) ? '-'.wc_price( $refund_tax_amount, array( 'currency' => $currency ) ) : ''; ?>
                                        </td>
                                        <?php
                                    }
                                }
                                ?>
                                <td class="wc-order-edit-line-item"></td>
                            </tr>
                            <?php
                        }
                    }
                    ?>
                </tbody>
            </table>

            <div class="starke-invoice-footer" id="starke-invoice-normal-footer">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div class="invoice-refund">
                        <?php if ( $invoice->get_total() > 0 && $invoice->get_remaining_refund_amount() > 0 ) : ?>
                            <button type="button" class="button button-secondary" id="starke_toggle_refund_ui">Refund</button>
                        <?php endif; ?>
                    </div>
                    <div class="invoice-totals">
                        <table class="starke-wc-order-totals">
                            <?php 
                            // 1. Items Subtotal
                            ?>
                            <tr>
                                <td class="label">Items Subtotal:</td>
                                <td class="total"><?php echo wc_price( $invoice->get_subtotal(), array( 'currency' => $currency ) ); ?></td>
                            </tr>

                            <?php 
                            // 2. Fees (e.g. Credit Card Fee)
                            if ( $invoice->get_total_fees() > 0 ) : ?>
                                <tr>
                                    <td class="label">Fees:</td>
                                    <td class="total"><?php echo wc_price( $invoice->get_total_fees(), array( 'currency' => $currency ) ); ?></td>
                                </tr>
                            <?php endif; ?>

                            <?php
                            // 3. Split Taxes (The Breakdown)
                            // We loop through each tax item to show "PA Tax", "NJ Tax", etc. separately.
                            $tax_items = $invoice->get_items( 'tax' );
                            foreach ( $tax_items as $tax_item ) {
                                $tax_label = $tax_item->get_label();
                                $tax_amount = (float) $tax_item->get_tax_total() + (float) $tax_item->get_shipping_tax_total();
                                
                                if ( $tax_amount > 0 ) {
                                    ?>
                                    <tr>
                                        <td class="label"><?php echo esc_html( $tax_label ); ?>:</td>
                                        <td class="total"><?php echo wc_price( $tax_amount, array( 'currency' => $currency ) ); ?></td>
                                    </tr>
                                    <?php
                                }
                            }
                            ?>

                            <?php 
                            // 4. Invoice Total
                            $invoice_total = $invoice->get_total();
                            ?>
                            <tr>
                                <td class="label"><strong>Balance Invoice Total:</strong></td>
                                <td class="total"><strong><?php echo wc_price( $invoice_total, array( 'currency' => $currency ) ); ?></strong></td>
                            </tr>
                            
                            <?php
                            // 5. Amount Charged / Paid Logic
                            $invoice_charged = 0;
                            if ( ! $invoice->has_status( array( 'pending', 'failed', 'cancelled' ) ) ) {
                                $invoice_charged = $invoice_total;
                            }

                            $invoice_paid = 0;
                            if ( $invoice->is_paid() ) {
                                $invoice_paid = $invoice_total;
                            }
                            ?>
                            
                            <tr>
                                <td class="label">Amount Charged:</td>
                                <td class="total"><?php echo wc_price( $invoice_charged, array( 'currency' => $currency ) ); ?></td>
                            </tr>

                            <tr>
                                <td class="label">Amount Paid:</td>
                                <td class="total"><?php echo wc_price( $invoice_paid, array( 'currency' => $currency ) ); ?></td>
                            </tr>

                            <?php 
                            // --- NEW: STRIPE FEES DISPLAY (Mirrors Main Order Layout) ---
                            // 1. Get Stripe Data from the Invoice Order
                            $stripe_fee = $invoice->get_meta( '_stripe_fee', true );
                            $stripe_net = $invoice->get_meta( '_stripe_net', true );

                            // 2. SAFETY CHECK: Ensure we aren't looking at stale data copied from the Parent
                            //    We only show fees if the Invoice has a Transaction ID, AND it differs from the Parent's.
                            $invoice_txn_id = $invoice->get_transaction_id();
                            $parent_txn_id  = $order->get_transaction_id();

                            $has_valid_fee = ( '' !== $stripe_fee && '' !== $stripe_net );
                            $is_unique_transaction = ( ! empty( $invoice_txn_id ) && $invoice_txn_id !== $parent_txn_id );

                            // Only show if we have data AND it is verified as a unique payment
                            if ( $has_valid_fee && $is_unique_transaction ) {
                                
                                // 3. Format the "Via" Text (Date + Method)
                                $date_paid = $invoice->get_date_paid();
                                $date_str  = $date_paid ? $date_paid->date_i18n( get_option( 'date_format' ) ) : '';
                                $method    = $invoice->get_payment_method_title();
                                $via_text  = sprintf( '%s via %s', $date_str, $method );
                                ?>
                                
                                <tr>
                                    <td colspan="2" style="text-align: right; padding-right: 10.5em; padding-top: 10px; color: #777; font-weight: normal; display: block;">
                                        <?php echo esc_html( $via_text ); ?>
                                    </td>
                                </tr>

                                <?php if ( $total_refunded > 0 ) : ?>
                                <tr>
                                    <td class="label" style="color: #d63638 !important;">Refunded:</td>
                                    <td class="total" style="color: #d63638 !important;">-<?php echo wc_price( $total_refunded, array( 'currency' => $currency ) ); ?></td>
                                </tr>
                                <tr>
                                    <td class="label">Net Payment:</td>
                                    <td class="total"><?php echo wc_price( $invoice_paid - $total_refunded, array( 'currency' => $currency ) ); ?></td>
                                </tr>
                                <?php endif; ?>

                                <tr>
                                    <td class="label">Stripe Fee:</td>
                                    <td class="total">
                                        <?php 
                                        // Force negative sign for visual consistency
                                        echo wc_price( -1 * abs( (float)$stripe_fee ), array( 'currency' => $currency ) ); 
                                        ?>
                                    </td>
                                </tr>

                                <tr>
                                    <td class="label">Net payout:</td>
                                    <td class="total">
                                        <?php echo wc_price( (float)$stripe_net, array( 'currency' => $currency ) ); ?>
                                    </td>
                                </tr>
                                <?php
                            } else {
                                // Fallback: If no Stripe data is shown, still show the Refunded row
                                if ( $total_refunded > 0 ) : ?>
                                <tr>
                                    <td class="label" style="color: #d63638 !important;">Refunded:</td>
                                    <td class="total" style="color: #d63638 !important;">-<?php echo wc_price( $total_refunded, array( 'currency' => $currency ) ); ?></td>
                                </tr>
                                <?php endif; 
                            }
                            ?>
                            
                        </table>
                    </div>
                </div>
            </div>

            <div class="wc-order-data-row starke-refund-items" id="starke-invoice-refund-footer">
                <table class="starke-wc-order-totals">
                    <tbody>
                        <tr>
                            <td class="label">Amount already refunded:</td>
                            <td class="total">-<?php echo wc_price( $total_refunded, array( 'currency' => $currency ) ); ?></td>
                        </tr>
                        <tr>
                            <td class="label">Total available to refund:</td>
                            <td class="total"><?php echo wc_price( $remaining_refund_amount, array( 'currency' => $currency ) ); ?></td>
                        </tr>
                        <tr>
                            <td class="label" style="padding:2px 0;">
                                <label for="starke_refund_amount">
                                    <span class="woocommerce-help-tip" tabindex="0" aria-label="Refund the line items above. This will show the total amount to be refunded"></span> Refund amount:
                                </label>
                            </td>
                            <td class="total" style="padding:2px 0;">
                                <input type="text" id="starke_refund_amount" name="refund_amount" class="text" style="width:96%; text-align:right;" value="" readonly placeholder="0.00">
                                <div class="clear"></div>
                            </td>
                        </tr>
                        <tr>
                            <td class="label" style="padding:0;">
                                <label for="starke_refund_reason">
                                    <span class="woocommerce-help-tip" tabindex="0" aria-label="Note: the refund reason will be visible by the customer."></span> Reason for refund (optional):
                                </label>
                            </td>
                            <td class="total" style="padding:0;">
                                <input type="text" id="starke_refund_reason" name="refund_reason" class="text" style="width:96%;">
                                <div class="clear"></div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="clear"></div>
                <?php
                // --- NEW: Check if Gateway Supports API Refunds ---
                $payment_gateway    = wc_get_payment_gateway_by_order( $invoice );
                $can_refund_via_api = $payment_gateway && $payment_gateway->can_refund_order( $invoice );
                ?>
                <div class="starke-refund-actions">
                    <button type="button" class="button cancel-action" id="starke_cancel_refund">Cancel</button>
                    <button type="button" class="button button-primary tips" id="starke_do_refund" data-invoice-id="<?php echo esc_attr( $invoice_id ); ?>" data-api="false">
                        Refund <span class="wc-order-refund-amount"><span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol"><?php echo get_woocommerce_currency_symbol($currency); ?></span>0.00</span></span> manually
                    </button>
                    <?php if ( $can_refund_via_api ) : ?>
                        <button type="button" class="button button-primary tips" id="starke_do_api_refund" data-invoice-id="<?php echo esc_attr( $invoice_id ); ?>" data-api="true">
                            Refund <span class="wc-order-refund-amount"><span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol"><?php echo get_woocommerce_currency_symbol($currency); ?></span>0.00</span></span> via <?php echo esc_html( $payment_gateway->get_title() ); ?>
                        </button>
                    <?php endif; ?>
                    <input type="hidden" id="starke_refunded_amount" name="refunded_amount" value="0">
                    <div class="clear"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * RENDER: Project Totals Metabox (Summary View)
     */
    public function render_project_totals_meta_box( $post_or_order_object ) {
        $order = ( $post_or_order_object instanceof WC_Order ) ? $post_or_order_object : wc_get_order( $post_or_order_object->ID );
        if ( ! $order ) return;

        // 1. Get Natural Total (Source of Truth)
        $natural_total = (float) $order->get_meta( '_starke_natural_total', true );
        if ( $natural_total <= 0 ) {
            $natural_total = (float) $order->get_total(); 
        }

        // 2. Get Deferred Balance
        $deferred_amount = (float) $order->get_meta( '_starke_deferred_balance', true );

        // 3. Calculate "Paid on Parent Order"
        // Logic: Calculate what WAS charged, but only count it as paid if order is actually paid.
        if ( $deferred_amount > 0 ) {
            $parent_charged = $natural_total - $deferred_amount;
        } else {
            $parent_charged = (float) $order->get_total();
        }
        // STRICT PAYMENT CHECK:
        $paid_on_parent = $order->is_paid() ? $parent_charged : 0.00;
        
        // 4. Calculate Invoice Totals
        $invoice_id = $order->get_meta( '_starke_balance_order_id', true );
        $paid_on_invoice = 0.0;
        $invoice_total_amount = 0.0;
        
        if ( $invoice_id ) {
            $invoice = wc_get_order( $invoice_id );
            if ( $invoice ) {
                $invoice_total_amount = (float) $invoice->get_total();
                
                // If invoice is paid, record that amount (ignoring refunds)
                if ( $invoice->is_paid() ) {
                    $paid_on_invoice = $invoice_total_amount;
                }
            }
        }

        // 5. Calculate "Order Grand Total"
        if ( $invoice_id && $invoice_total_amount > 0 ) {
            $grand_total = $parent_charged + $invoice_total_amount;
        } else {
            $grand_total = $natural_total;
        }

        // 6. Calculate Net Balance Due
        $balance_due = $grand_total - $paid_on_parent - $paid_on_invoice;
        $balance_due = round( $balance_due, 2 );

        ?>
        <style>
            .starke-project-summary-table td { padding: 6px 10px; font-size: 14px; }
            .starke-summary-row { border-bottom: 1px solid #eee; }
            .starke-summary-final { font-weight: bold; font-size: 16px; border-top: 2px solid #ddd; background: #f9f9f9; }
        </style>
        <table class="starke-project-summary-table" style="width:100%; text-align:right;">
            <tr class="starke-summary-row">
                <td style="text-align:left;"><strong>Order Grand Total</strong></td>
                <td><?php echo wc_price( $grand_total, array( 'currency' => $order->get_currency() ) ); ?></td>
            </tr>
            <tr class="starke-summary-row">
                <td style="text-align:left; color:#667;">Paid on Order</td>
                <td>-<?php echo wc_price( $paid_on_parent, array( 'currency' => $order->get_currency() ) ); ?></td>
            </tr>
            <tr class="starke-summary-row">
                <td style="text-align:left; color:#667;">Paid on Balance Invoice</td>
                <td>-<?php echo wc_price( $paid_on_invoice, array( 'currency' => $order->get_currency() ) ); ?></td>
            </tr>
            <tr class="starke-summary-final">
                <td style="text-align:left;">Net Balance Due</td>
                <td style="<?php echo ( $balance_due > 0.01 ) ? 'color:#d63638;' : 'color:#0a9926;'; ?>">
                    <?php echo wc_price( $balance_due, array( 'currency' => $order->get_currency() ) ); ?>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * META BOX: Render Content (The "Mini-Ledger" Layout)
     * Displays 3 Clear Lines + Simplified Actions.
     * UPDATED: Now uses Centralized Helper to ensure rounding matches Emails/PDFs.
     */
    public function render_payment_actions_meta_box( $post ) {
        $order = wc_get_order( $post->ID );
        if ( ! $order ) return;

        // --- FIX: Use Centralized Logic for Splits (Rounding Consistency) ---
        // This ensures we get the specific rounding (Deposit rounded UP, Balance is Remainder)
        // instead of doing raw division which causes the 1-cent discrepancy.
        $splits = self::get_payment_splits( $order );
        
        $term = $splits['term'];
        $natural_total = $splits['project_total'];
        
        // ------------------------------------------------------------
        // 1. CALCULATE INITIAL PAYMENT STATUS
        // ------------------------------------------------------------
        $is_initial_paid = $order->is_paid();
        $initial_due = 0.00;

        if ( ! $is_initial_paid ) {
            // If NOT paid, calculate what SHOULD be due using the rounded split
            $initial_due = $splits['required_deposit'];
        }

        // ------------------------------------------------------------
        // 2. CALCULATE BALANCE INVOICE STATUS
        // ------------------------------------------------------------
        $balance_invoice_id = $order->get_meta( '_starke_balance_order_id', true );
        $balance_due = 0.00;
        $invoiceable_amount = 0.00;

        if ( $balance_invoice_id ) {
            $balance_order = wc_get_order( $balance_invoice_id );
            if ( $balance_order ) {
                $balance_order_total = (float) $balance_order->get_total();
                
                if ( $balance_order->is_paid() ) {
                    $balance_due = 0.00;
                } else {
                    $balance_due = $balance_order_total;
                }
            }
        } else {
            // No invoice created yet. Use the rounded deferred balance from splits.
            $potential_balance = $splits['deferred_balance'];
            
            // Safety rounding
            if ( $potential_balance < 0.05 ) $potential_balance = 0.00;
            
            $balance_due = $potential_balance;
            $invoiceable_amount = $potential_balance;
        }

        // Color Logic: Keep Red/Green for MONEY only (Financial Dashboard Standard)
        $initial_color = ( $initial_due > 0.01 ) ? '#d63638' : '#46b450';
        $balance_color = ( $balance_due > 0.01 ) ? '#d63638' : '#46b450';

        // Prepare Safe Price String for Data Attribute (used by external JS)
        $safe_price_string = html_entity_decode( strip_tags( wc_price( $invoiceable_amount ) ) );

        ?>
        <style>
            /* ... (Existing CSS remains unchanged) ... */
            .starke-ledger-row {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #eee;
                font-size: 13px;
            }
            .starke-ledger-label {
                font-weight: 600;
                color: #555;
            }
            .starke-ledger-value {
                font-weight: bold;
                text-align: right;
            }
        </style>

        <div style="padding: 0 5px;">
            
            <div class="starke-ledger-row" style="border-bottom: 2px solid #ddd;">
                <span class="starke-ledger-label">Order Total:</span>
                <span class="starke-ledger-value" style="color:#000; font-size:14px;">
                    <?php echo wc_price( $natural_total ); ?>
                </span>
            </div>

            <div class="starke-ledger-row">
                <span class="starke-ledger-label">Due from Initial Order:</span>
                <span class="starke-ledger-value" style="color: <?php echo $initial_color; ?>;">
                    <?php echo wc_price( $initial_due ); ?>
                </span>
            </div>

            <?php 
            // Only render the Balance Invoice row if the order has active terms (Net 30 or 50/50)
            if ( ! empty( $term ) && 'no_terms' !== $term ) : 
            ?>
            <div class="starke-ledger-row">
                <span class="starke-ledger-label">Due from Balance Inv:</span>
                <div style="text-align:right;">
                    <span class="starke-ledger-value" style="color: <?php echo $balance_color; ?>;">
                        <?php echo wc_price( $balance_due ); ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <div style="margin-top: 15px; text-align: center; color: #666; font-size: 12px;">
                <?php if ( 'net_30' === $term ) : ?>
                    <strong>Terms:</strong> Net 30
                <?php elseif ( '50_50' === $term ) : ?>
                    <strong>Terms:</strong> 50% Deposit / 50% on Delivery
                <?php else : ?>
                    <strong>Terms:</strong> No Terms (Full Payment)
                <?php endif; ?>
            </div>

            <?php 
            // Only show the bottom action area (Buttons / Explicit text) if there are terms
            if ( ! empty( $term ) && 'no_terms' !== $term ) : 
            ?>
            <hr style="margin: 15px 0;">

            <div style="text-align: center;">
                <?php if ( $balance_invoice_id ) : ?>
                    <div style="margin-top: 5px;">
                        <p style="margin: 0 0 8px 0; color:#1d2327; font-weight:bold; font-size: 15px;">
                            Invoice Created and Sent
                        </p>
                        
                        <button type="button" id="starke_resend_invoice_email" class="button button-secondary" style="width:auto; padding: 0 15px;" data-invoice-id="<?php echo $balance_invoice_id; ?>" data-payment-term="<?php echo esc_attr( $term ); ?>">
                            Resend Invoice Email
                        </button>
                        
                        <div style="margin-top:5px; min-height:20px;">
                            <span id="starke_resend_spinner" class="spinner" style="float:none; margin:0;"></span>
                            <span id="starke_resend_message" style="display:none; color:#46b450; font-weight:bold; margin-left:5px;">Email Sent!</span>
                        </div>
                    </div>

                <?php elseif ( $invoiceable_amount > 0.01 ) : ?>
                    <button type="button" id="starke_create_balance_invoice" class="button button-primary button-large" style="width:fit-content;" data-confirm-msg="Create Balance Invoice for <?php echo $safe_price_string; ?>?">
                        Create Balance Invoice
                    </button>
                    <p class="description" style="margin-top:5px;">Create invoice for <?php echo wc_price($invoiceable_amount); ?></p>

                <?php else : ?>
                    <p style="color: #1d2327; font-weight:bold;">No Balance Invoice Required.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php wp_nonce_field( 'starke_invoice_nonce', 'starke_invoice_nonce_field' ); ?>
        </div>
        <?php
    }

    /**
     * SCRIPT: Inject JS
     * FIX: We DO NOT hide .view anymore, we just show .starke-refund-input. 
     */
    public function inject_admin_scripts() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'shop_order' && $screen->id !== 'woocommerce_page_wc-orders' ) {
            return;
        }
        ?>
        <style>
            #starke-invoice-wrapper .wc-order-totals { border-top: 1px solid #eee; padding-top: 12px; }
            #starke-invoice-wrapper .wc-order-totals table { width: 100%; text-align: right; }
            #starke-invoice-wrapper .wc-order-totals td { padding: 6px 0; }
            #starke-invoice-wrapper .wc-order-totals .label { font-weight: 600; padding-right: 15px; }
            #starke-invoice-wrapper .wc-order-totals .total .woocommerce-Price-amount { font-weight: 700; font-size: 1.1em; }
            
            /* Force hide native Woo tax refund inputs if they are empty */
            #woocommerce-order-items td.line_tax .refund.starke-hide-empty-tax { display: none !important; }

            /* NEW: Native WooCommerce Dashicon for Line Item Refunds */
            #starke-invoice-wrapper small.refunded::before {
                font-family: Dashicons;
                speak: never;
                font-weight: 400;
                font-variant: normal;
                text-transform: none;
                line-height: 1;
                -webkit-font-smoothing: antialiased;
                margin: 0;
                text-indent: 0;
                content: "\f171";
                position: relative;
                top: auto;
                left: auto;
                margin: -1px 4px 0 0;
                vertical-align: middle;
                line-height: 1em;
            }

            /* NEW: Native WooCommerce Dashicon for Line Item Refunds */
            #starke-invoice-wrapper small.refunded::before {
                font-family: Dashicons;
                speak: never;
                font-weight: 400;
                font-variant: normal;
                text-transform: none;
                line-height: 1;
                -webkit-font-smoothing: antialiased;
                margin: 0;
                text-indent: 0;
                content: "\f171";
                position: relative;
                top: auto;
                left: auto;
                margin: -1px 4px 0 0;
                vertical-align: middle;
                line-height: 1em;
            }

            /* --- FIX: Native Woo Refund Icon Container Sizing --- */
            #starke-invoice-wrapper tr.refund td.thumb {
                padding: 1.5em 1em 1.5em 1.1em !important;
                vertical-align: top;
                width: 38px;
            }
            
            #starke-invoice-wrapper tr.refund td.thumb div {
                display: block;
                text-indent: -9999px;
                position: relative;
                height: 1em;
                width: 1em;
                font-size: 1.5em;
                line-height: 1em;
                vertical-align: middle;
                margin: 0 auto;
            }

            /* --- NEW: Native WooCommerce Icon for Balance Invoice Refund Rows --- */
            #starke-invoice-wrapper tr.refund .thumb div::before {
                font-family: WooCommerce;
                speak: never;
                font-weight: 400;
                font-variant: normal;
                text-transform: none;
                line-height: 1;
                margin: 0;
                text-indent: 0;
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                text-align: center;
                content: "\e014";
                color: #ccc;
            }
        </style>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // --- CLEAN UP VERBOSE REFUND BUTTONS ---
            // Forces both the native button and our custom button to use a clean, short name.
            $('.button.do-api-refund, #starke_do_api_refund').each(function() {
                var $btn = $(this);
                var html = $btn.html();
                
                // If the button text contains " via ", trim the fat off the end
                if (html && html.indexOf(' via ') > -1) {
                    var cleanHtml = html.substring(0, html.indexOf(' via ') + 5) + 'Credit Card';
                    $btn.html(cleanHtml);
                }
            });

            // --- SYNC NATIVE BUTTON UI ("PROCESSING...") ---
            var activeNativeBtn = null;
            var originalNativeBtnText = '';

            // --- VALIDATE & CAPTURE NATIVE WOOCOMMERCE REFUND BUTTONS ---
            // Intercepts the original order's refund buttons in the CAPTURE phase BEFORE WooCommerce 
            // can block the event. This allows us to safely grab the button and prep it for UI changes.
            document.addEventListener('click', function(e) {
                var targetBtn = e.target.closest('.do-manual-refund, .do-api-refund');
                
                if (targetBtn) {
                    var refundInput = document.getElementById('refund_amount');
                    var nativeAmount = refundInput ? parseFloat(refundInput.value) : 0;
                    
                    if (isNaN(nativeAmount) || nativeAmount <= 0) {
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation(); // Kills the WooCommerce confirmation popup
                        alert('Please enter a valid refund amount.');
                    } else {
                        // Amount is valid! Save this button so our AJAX hook can change its text
                        activeNativeBtn = $(targetBtn);
                        originalNativeBtnText = activeNativeBtn.text();
                    }
                }
            }, true); // 'true' forces this to run before jQuery/WooCommerce can block it

            // Intercept the WooCommerce AJAX request right as it fires
            $(document).ajaxSend(function(event, xhr, settings) {
                // Safely check if this is the refund AJAX call (Handles both String & Object formats)
                var isRefundCall = false;
                if (settings.data) {
                    if (typeof settings.data === 'string' && settings.data.indexOf('woocommerce_refund_line_items') > -1) {
                        isRefundCall = true;
                    } else if (typeof settings.data === 'object' && settings.data.action === 'woocommerce_refund_line_items') {
                        isRefundCall = true;
                    }
                }

                if (isRefundCall && activeNativeBtn) {
                    // Disable both buttons so they turn gray natively
                    $('.do-manual-refund, .do-api-refund').prop('disabled', true);
                    // Change the text to "Processing..."
                    activeNativeBtn.text('Processing...');
                }
            });

            // Reset if the AJAX fails
            $(document).ajaxComplete(function(event, xhr, settings) {
                var isRefundCall = false;
                if (settings.data) {
                    if (typeof settings.data === 'string' && settings.data.indexOf('woocommerce_refund_line_items') > -1) {
                        isRefundCall = true;
                    } else if (typeof settings.data === 'object' && settings.data.action === 'woocommerce_refund_line_items') {
                        isRefundCall = true;
                    }
                }

                if (isRefundCall && activeNativeBtn) {
                    $('.do-manual-refund, .do-api-refund').prop('disabled', false);
                    activeNativeBtn.text(originalNativeBtnText);
                    activeNativeBtn = null;
                }
            });

            // --- CONSOLIDATED UI CLEANER (Original Order Page) ---
            function starke_clean_ui() {
                // 1. Hide Empty Tax Refund Inputs
                // Native WooCommerce shows refund inputs even for $0.00 taxes. This forces them hidden.
                // UPDATED: Removed '.item' restriction so it scans Products, Fees, and Shipping rows.
                $('#woocommerce-order-items td.line_tax').each(function() {
                    var $cell = $(this);
                    var $view = $cell.find('.view');
                    var $refundInputDiv = $cell.find('.refund');

                    // If we've already hidden it, skip it to save performance
                    if ($refundInputDiv.hasClass('starke-hide-empty-tax')) return;

                    // Extract the number from the view text (e.g., "$0.00" or "-")
                    var amountString = $view.text().replace(/[^0-9.,-]+/g, '');
                    amountString = amountString.replace(',', '.');
                    var amount = parseFloat(amountString);

                    // If it is zero or NaN, apply our hiding class AND forcefully hide it
                    if (isNaN(amount) || Math.abs(amount) < 0.01) {
                        $refundInputDiv.addClass('starke-hide-empty-tax');
                        $refundInputDiv.hide(); // Double-protection against WooCommerce inline styles
                    } else {
                        $refundInputDiv.removeClass('starke-hide-empty-tax');
                    }
                });

                // 2. Inject Credit Card Icon (Persistent)
                $('#woocommerce-order-items tr.fee').each(function() {
                    var $row = $(this);
                    
                    // Check if we already added the icon to avoid duplicates
                    if ( $row.find('.starke-fee-icon').length > 0 ) return;

                    var nameText = $row.find('.name').text() + ($row.find('.name input').val() || '');
                    
                    if ( nameText.indexOf('Convenience Fee') > -1 ) {
                        $row.find('td.thumb').html('<div class="starke-fee-icon" style="font-size:24px; line-height:1;">💳</div>');
                    }
                });

                // --- NEW: 3. Force Native WooCommerce Money Placeholders to 0.00 ---
                // Targets the original order's line total, tax total, and master refund amount inputs
                $('#woocommerce-order-items input.refund_line_total, #woocommerce-order-items input.refund_line_tax, #refund_amount').attr('placeholder', '0.00');
            }

            // Run immediately on page load to prep the HTML
            starke_clean_ui();

            // Watch for ANY WooCommerce AJAX updates, redraws, or "Refund" button clicks
            var targetNode = document.getElementById('woocommerce-order-items');
            if ( targetNode ) {
                var observer = new MutationObserver(function() {
                    starke_clean_ui();
                });
                observer.observe(targetNode, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
            }

            // --- MOVE REFUND BUTTON INTO TOTALS ROW ---
            var $origBtnContainer = $('#woocommerce-order-items .wc-order-bulk-actions');
            var $origTotalsContainer = $('#woocommerce-order-items .wc-order-totals-items');

            // Move the button container inside the totals container (at the start)
            if ($origBtnContainer.length && $origTotalsContainer.length) {
                $origBtnContainer.prependTo($origTotalsContainer);
            }

            // --- UI: Toggle Refund Mode ---
            $('#starke_toggle_refund_ui').on('click', function(e) {
                e.preventDefault();
                $('#starke-invoice-normal-footer').hide();
                $('#starke-invoice-refund-footer').css('display', 'block');
                
                // Show inputs only, do NOT hide the price view
                $('#starke-invoice-wrapper .starke-refund-input').show();
            });

            $('#starke_cancel_refund').on('click', function(e) {
                e.preventDefault();
                $('#starke-invoice-refund-footer').hide();
                $('#starke-invoice-normal-footer').show();
                
                // Hide inputs
                $('#starke-invoice-wrapper .starke-refund-input').hide();
                
                // Clear inputs
                $('.starke_refund_line_total').val('');
                $('.starke_refund_order_item_qty').val('');
                $('.starke_refund_line_tax').val(''); // <-- Clears the new tax boxes
                $('#starke_refund_amount').val('');
                updateRefundTotal();
            });

            // --- UI: Live Calculation ---
            $('.starke_refund_order_item_qty').on('change keyup', function() {
                var qtyInput = $(this);
                var itemId = qtyInput.data('item-id');
                var unitPrice = parseFloat(qtyInput.data('price'));
                var qty = parseFloat(qtyInput.val());

                if (!isNaN(qty) && !isNaN(unitPrice)) {
                    var newTotal = qty * unitPrice;
                    $('#starke_refund_line_total_' + itemId).val(newTotal.toFixed(2)).trigger('change');
                } else if (qtyInput.val() === '') {
                    $('#starke_refund_line_total_' + itemId).val('').trigger('change');
                }
            });

            $('.starke_refund_line_total, .starke_refund_line_tax').on('change keyup', function() {
                var total = 0;
                // Add up item totals
                $('.starke_refund_line_total').each(function() {
                    var val = parseFloat($(this).val());
                    if (!isNaN(val)) total += val;
                });
                // Add up tax totals
                $('.starke_refund_line_tax').each(function() {
                    var val = parseFloat($(this).val());
                    if (!isNaN(val)) total += val;
                });
                $('#starke_refund_amount').val(total.toFixed(2)).trigger('change');
            });

            $('#starke_refund_amount').on('change keyup', function() {
                updateRefundTotal();
            });

            function updateRefundTotal() {
                var amount = parseFloat($('#starke_refund_amount').val());
                var btnManual = $('#starke_do_refund');
                var btnApi = $('#starke_do_api_refund');
                var currencySymbol = '<?php echo get_woocommerce_currency_symbol(); ?>';
                
                if (isNaN(amount) || amount <= 0) {
                    var defaultHtml = '<span class="woocommerce-Price-currencySymbol">' + currencySymbol + '</span>0.00';
                    btnManual.find('.wc-order-refund-amount .amount').html(defaultHtml);
                    btnManual.prop('disabled', true);
                    if(btnApi.length) {
                        btnApi.find('.wc-order-refund-amount .amount').html(defaultHtml);
                        btnApi.prop('disabled', true);
                    }
                } else {
                    var formatted = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2 }).format(amount);
                    var html = '<span class="woocommerce-Price-currencySymbol">' + currencySymbol + '</span>' + formatted;
                    btnManual.find('.wc-order-refund-amount .amount').html(html);
                    btnManual.prop('disabled', false);
                    if(btnApi.length) {
                        btnApi.find('.wc-order-refund-amount .amount').html(html);
                        btnApi.prop('disabled', false);
                    }
                }
            }

            // --- SYNC NATIVE REFUND LIVE CALCULATION ---
            // Forces original order native refund inputs to update the total instantly on keystroke, matching Starke behavior
            $('#woocommerce-order-items').on('keyup', '.refund_order_item_qty, .refund_line_total, .refund_line_tax', function() {
                $(this).trigger('change');
            });

            // --- FORMAT MONEY INPUTS ON BLUR ---
            // Forces input fields to display trailing zeros (e.g. "5" -> "5.00") when clicking or tabbing away
            $(document).on('blur', '.starke_refund_line_total, .starke_refund_line_tax, .refund_line_total, .refund_line_tax', function() {
                var inputField = $(this);
                // Make sure the box isn't empty before trying to format it
                if (inputField.val() !== '') {
                    var val = parseFloat(inputField.val());
                    if (!isNaN(val)) {
                        inputField.val(val.toFixed(2));
                    }
                }
            });

            // --- ACTION: Process Refund ---
            $('#starke_do_refund, #starke_do_api_refund').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var invoiceId = btn.data('invoice-id');
                var isApi = btn.data('api') === true; // Detects if the Stripe button was clicked
                var amount = parseFloat($('#starke_refund_amount').val());
                var reason = $('#starke_refund_reason').val();

                if (isNaN(amount) || amount <= 0) {
                    alert('Please enter a valid refund amount.');
                    return;
                }

                var confirmMsg = 'Are you sure you want to refund ' + amount;
                confirmMsg += isApi ? ' via the payment gateway?' : ' manually?';
                confirmMsg += ' This cannot be undone.';

                if (!confirm(confirmMsg)) return;

                // Disable both buttons to prevent double-clicks
                $('#starke_do_refund, #starke_do_api_refund').prop('disabled', true);
                var originalText = btn.text();
                btn.text('Processing...');

                // --- NEW: Build the itemized refund data object (including taxes) ---
                var refundItems = {};
                $('#starke-invoice-list tr.item').each(function() {
                    var qtyInput = $(this).find('.starke_refund_order_item_qty');
                    if (!qtyInput.length) return;
                    
                    var itemId = qtyInput.data('item-id');
                    var qty = parseFloat(qtyInput.val()) || 0;
                    var total = parseFloat($('#starke_refund_line_total_' + itemId).val()) || 0;
                    var taxes = {};
                    
                    // Grab the specific tax boxes for this item
                    $(this).find('.starke_refund_line_tax').each(function() {
                        var taxId = $(this).data('tax-id');
                        var taxVal = parseFloat($(this).val()) || 0;
                        if (taxVal > 0) taxes[taxId] = taxVal;
                    });
                    
                    if (qty > 0 || total > 0 || Object.keys(taxes).length > 0) {
                        refundItems[itemId] = { qty: qty, refund_total: total, refund_tax: taxes };
                    }
                });
                // --------------------------------------------------------------------

                $.post(ajaxurl, {
                    action: 'starke_refund_invoice',
                    invoice_id: invoiceId,
                    amount: amount,
                    reason: reason,
                    line_items: refundItems, // NEW: Pass exact items & taxes to PHP
                    api_refund: isApi ? 1 : 0, // Pass the flag to PHP
                    security: '<?php echo wp_create_nonce("starke_refund_nonce"); ?>'
                }, function(res) {
                    if(res.success) {
                        location.reload();
                    } else {
                        alert(res.data.message || 'Refund failed');
                        btn.text(originalText);
                        $('#starke_do_refund, #starke_do_api_refund').prop('disabled', false);
                    }
                });
            });

            // --- ACTION: Status Update ---
            $('#starke_update_invoice_status').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var select = $('#starke_invoice_status_select');
                var invoiceId = select.data('invoice-id');
                var newStatus = select.val();

                btn.prop('disabled', true).text('Updating...');

                $.post(ajaxurl, {
                    action: 'starke_update_invoice_status',
                    invoice_id: invoiceId,
                    status: newStatus,
                    security: '<?php echo wp_create_nonce("starke_status_nonce"); ?>'
                }, function(res) {
                    if(res.success) {
                        location.reload();
                    } else {
                        alert('Error updating status');
                        btn.prop('disabled', false).text('Update');
                    }
                });
            });

            // --- ACTION: Create Invoice ---
            $('#starke_create_balance_invoice').on('click', function(e) {
                e.preventDefault();
                if (!confirm('Create new Invoice Order for the balance and email the customer?')) return;
                var btn = $(this);
                var orderId = <?php echo isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['post']) ? intval($_GET['post']) : 0); ?>;
                btn.prop('disabled', true).text('Processing...');
                $.post(ajaxurl, {
                    action: 'starke_create_balance_invoice',
                    order_id: orderId,
                    security: $('#starke_invoice_nonce_field').val()
                }, function(res) {
                    if(res.success) { location.reload(); } 
                    else { alert(res.data.message); btn.prop('disabled', false).text('Try Again'); }
                });
            });
            
            // 4. Resend Email Button (Real Implementation)
            $('#starke_resend_invoice_email').on('click', function(e) {
                e.preventDefault();
                
                var btn = $(this);
                var term = btn.data('payment-term'); // Get the term from the button
                
                // Default Message
                var confirmMsg = 'Resend the invoice email to the customer?';

                // Only append the warning for Net 30
                if ( term === 'net_30' ) {
                    confirmMsg += ' This will also reset the 7-day reminder timer.';
                }

                if (!confirm(confirmMsg)) return;
                
                var invoiceId = btn.data('invoice-id');
                
                btn.prop('disabled', true).text('Sending...');
                // ... AJAX call continues ...
                
                $.post(ajaxurl, {
                    action: 'starke_resend_invoice_email',
                    invoice_id: invoiceId,
                    security: $('#starke_invoice_nonce_field').val()
                }, function(res) {
                    if(res.success) { 
                        alert(res.data.message);
                        btn.text('Sent!');
                    } else { 
                        alert(res.data.message); 
                        btn.prop('disabled', false).text('Try Again'); 
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * STYLE: Inject CSS to hide elements IMMEDIATELY and adjust global table styles.
     */
    public function inject_admin_styles() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'shop_order' && $screen->id !== 'woocommerce_page_wc-orders' ) {
            return;
        }
        ?>
        <style>
            /* 1. Hide Dangerous Global Action Buttons */
            .button.calculate-action,
            .button.add-line-item { 
                display: none !important; 
            }

            /* 2. Hide "Restock" Row */
            .wc-order-totals tr:has(label[for="restock_refunded_items"]) {
                display: none !important;
            }

            /* 3. HIDE EDIT/DELETE ACTIONS (Pencil & X icons) */
            .wc-order-edit-line-item-actions,
            .wc-order-edit-line-item .edit-order-item,
            .wc-order-edit-line-item .delete-order-item {
                display: none !important;
            }
            
            /* 4. ADJUST LAST COLUMN WIDTH & PADDING (Both Original & Custom Tables) */
            /* This targets the original WC table and your custom table if it uses the same class structure */
            #woocommerce-order-items .wc-order-edit-line-item,
            .starke_invoice_table .wc-order-edit-line-item {
                width: 0 !important;           
                padding-left: 0 !important;    
                padding-right: 1em !important; /* Adds the breathing room you wanted */
                text-align: center;
                border-right: none !important; /* Optional: cleaner look */
            }
            
            /* Ensure the header also aligns */
            #woocommerce-order-items th.wc-order-edit-line-item,
            .starke_invoice_table th.wc-order-edit-line-item {
                padding-right: 0 !important;
                padding-left: 0 !important;
            }

            /* --- NEW: RE-LAYOUT ORIGINAL ORDER TOTALS SECTION --- */
            #woocommerce-order-items .wc-order-totals-items {
                display: block !important;
                position: relative !important; /* ENABLED: Positioning context for the button */
                padding: 1.5em 2em !important; 
                background: #f8f9fa !important;
                border-top: 1px solid #e5e5e5 !important;
            }

            /* HIDE BLOCKERS: Hide "clear" divs only. */
            /* CHANGE: We removed the rule hiding sibling tables so Stripe can show its data. */
            #woocommerce-order-items .wc-order-totals-items .clear { 
                display: none !important; 
            }

            /* FORCE RIGHT ALIGNMENT */
            #woocommerce-order-items .wc-order-totals {
                float: none !important;
                margin-left: auto !important; /* Forces it to the far right */
                margin-right: 0 !important;
                border: none !important;
                padding: 0 !important;
            }

            /* --- ANTI-FLICKER LOGIC --- */

            /* 1. HIDE the refund button container globally to prevent the "Jump" */
            #woocommerce-order-items .wc-order-bulk-actions {
                display: none !important;
            }

            /* 2. SHOW the button ONLY when it is inside the totals section (after JS moves it) */
            #woocommerce-order-items .wc-order-totals-items .wc-order-bulk-actions {
                display: block !important;
                /* CENTERING MAGIC: */
                position: absolute !important;
                top: 50% !important;
                left: 2em !important; /* Matches the container padding */
                transform: translateY(-50%) !important; /* Shifts it up by half its own height to find true center */
                
                float: none !important;
                border: none !important;
                margin: 0 !important;
                padding: 0 !important;
                background: transparent !important;
                box-shadow: none !important;
            }

            /* --- NEW FIX: HIDE TOTALS CONTAINER DURING REFUND --- */
            /* When WooCommerce adds the 'refund-mode' class, hide the entire container */
            #woocommerce-order-items.refund-mode .wc-order-totals-items {
                display: none !important;
            }

            /* --- FIX: Fee Icon Visibility --- */
            /* 1. Reset the parent cell to ensure it doesn't hide content */
            #woocommerce-order-items tr.fee td.thumb {
                text-indent: 0 !important;    /* Fixes "off screen" text */
                overflow: visible !important; /* Allows content to show */
                position: relative !important;
            }
            
            /* 2. Position the wrapper div */
            #woocommerce-order-items tr.fee td.thumb .starke-fee-icon {
                display: block !important;
                width: 100% !important;
                text-align: center !important;
                text-indent: 0 !important;
            }

            /* 3. Style the Emoji Image itself */
            #woocommerce-order-items tr.fee td.thumb .starke-fee-icon img.emoji {
                display: inline-block !important;
                visibility: visible !important;
                opacity: 1 !important;
                width: 24px !important;
                height: 24px !important;
                position: static !important; /* Prevents absolute positioning weirdness */
                margin: 0 auto !important;
            }
            /* --- FIX: Hide the "Plus" Placeholder Icon --- */
            #woocommerce-order-items .woocommerce_order_items_wrapper table.woocommerce_order_items tr.fee .thumb div::before {
                display: none !important;
                content: none !important;
                box-shadow: none !important;
            }

            /* --- FIX: Add spacing below the Filter Bar --- */
            .alignleft.actions {
                margin-bottom: 9px;
            }

            /* --- HIDE STRIPE 'PAID' AMOUNT ROW (ZERO FLICKER CSS) --- */
            /* CSS targets the row sitting exactly above the Stripe "via" description row. */
            #woocommerce-order-items .wc-order-totals tr:has(+ tr span.description) {
                display: none !important;
            }

            /* --- NEW: MATCH ORIGINAL ORDER REFUND COLORS TO #d63638 --- */
            /* 1. Line item inline refund amounts and curved arrow icon */
            #woocommerce-order-items small.refunded,
            #woocommerce-order-items small.refunded .amount,
            #woocommerce-order-items small.refunded::before {
                color: #d63638 !important;
            }
            
            /* 2. Totals "Refunded" row (label and amount) */
            #woocommerce-order-items .wc-order-totals td.refunded-total,
            #woocommerce-order-items .wc-order-totals td.refunded-total .amount,
            #woocommerce-order-items .wc-order-totals tr.refunded td,
            #woocommerce-order-items .wc-order-totals tr.refunded th,
            #woocommerce-order-items .wc-order-totals tr.refunded .amount,
            #woocommerce-order-items .wc-order-totals .text-red {
                color: #d63638 !important;
            }

            /* --- FIX: MATCH ORIGINAL ORDER STRIPE TEXT EXACTLY TO BALANCE INVOICE --- */
            /* 1. Force the row to act like a single, full-width block */
            #woocommerce-order-items .wc-order-totals tr:has(span.description) {
                display: block !important;
                width: 100% !important;
            }
            
            /* 2. Make the text cell mimic the Balance Invoice's full-width cell */
            #woocommerce-order-items .wc-order-totals tr:has(span.description) td:first-child {
                display: block !important;
                width: 100% !important;
                text-align: right !important;
                padding-right: 20.5em !important;
                padding-top: 10px !important;
                box-sizing: border-box !important;
            }
            
            /* 3. Hide the empty right-side cell so it stops squishing the text */
            #woocommerce-order-items .wc-order-totals tr:has(span.description) td:not(:first-child) {
                display: none !important;
            }
            
            /* 4. Ensure the text stays on one line and matches the color */
            #woocommerce-order-items .wc-order-totals span.description {
                color: #777 !important;
                font-weight: normal !important;
                white-space: nowrap !important;
                display: inline !important;
            }
            
            
        </style>
        <?php
    }

    /**
     * AJAX: Update Invoice Status
     */
    public function ajax_update_invoice_status() {
        check_ajax_referer( 'starke_status_nonce', 'security' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) wp_send_json_error();

        $invoice_id = intval( $_POST['invoice_id'] );
        $status = sanitize_text_field( $_POST['status'] );
        
        $order = wc_get_order( $invoice_id );
        if ( $order ) {
            $order->update_status( $status, 'Status changed via Parent Order screen.' );
            wp_send_json_success();
        }
        wp_send_json_error();
    }

    /**
     * AJAX: Refund Invoice
     */
    public function ajax_refund_invoice() {
        check_ajax_referer( 'starke_refund_nonce', 'security' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) wp_send_json_error();

        $invoice_id = intval( $_POST['invoice_id'] );
        $amount     = floatval( $_POST['amount'] );
        $reason     = sanitize_text_field( $_POST['reason'] );
        $line_items = isset( $_POST['line_items'] ) ? $_POST['line_items'] : array();
        
        $api_refund = isset( $_POST['api_refund'] ) && $_POST['api_refund'] == '1';

        $order = wc_get_order( $invoice_id );
        if ( ! $order ) wp_send_json_error( ['message' => 'Invalid Order'] );

        // Create the refund using the itemized data
        $refund = wc_create_refund( array(
            'amount'         => $amount,
            'reason'         => $reason,
            'order_id'       => $invoice_id,
            'line_items'     => $line_items, // This ensures tax buckets are accurate
            'refund_payment' => $api_refund,
        ));

        if ( is_wp_error( $refund ) ) {
            wp_send_json_error( ['message' => $refund->get_error_message()] );
        }

        wp_send_json_success();
    }

    // --- EXISTING FUNCTIONS ---

    public function create_balance_invoice_ajax() {
        check_ajax_referer( 'starke_invoice_nonce', 'security' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );

        $parent_id = intval( $_POST['order_id'] );
        $result = $this->create_invoice_logic( $parent_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        } else {
            wp_send_json_success( [ 'message' => 'Invoice sent successfully!' ] );
        }
    }

    private function create_invoice_logic( $parent_id ) {
        $parent = wc_get_order( $parent_id );
        if ( ! $parent ) return new WP_Error( 'invalid', 'Invalid Order' );

        $amount = floatval( $parent->get_meta( '_starke_deferred_balance', true ) );
        if ( $amount <= 0 ) return new WP_Error( 'paid', 'No balance due.' );

        if ( $parent->get_meta( '_starke_balance_order_id', true ) ) {
            return new WP_Error( 'duplicate', 'Invoice already exists.' );
        }

        try {
            // 1. Create Order
            $invoice = wc_create_order([
                'customer_id' => $parent->get_customer_id(),
                'parent'      => $parent_id, 
                'status'      => 'pending'   
            ]);

            // 2. Copy Addresses
            $invoice->set_address( $parent->get_address('billing'), 'billing' );
            $invoice->set_address( $parent->get_address('shipping'), 'shipping' );

            // 3. Add Line Item
            $starke_num = $parent->get_meta( '_starke_order_number', true ) ?: $parent->get_id();
            $term = $parent->get_meta( '_starke_payment_terms', true );
            
            $base_name = "Balance Payment for Order #{$starke_num}";
            $item_name = $base_name;
            if ( $term === '50_50' ) $item_name .= " (50% Due on Delivery)";
            elseif ( $term === 'net_30' ) $item_name .= " (Net 30 Payment)";

            $item = new WC_Order_Item_Product();
            $item->set_name( $item_name );
            $item->set_quantity( 1 );
            $item->set_subtotal( $amount );
            $item->set_total( $amount );
            
            $item->set_subtotal_tax( 0 );
            $item->set_total_tax( 0 );
            $item->set_taxes( array( 'total' => array(), 'subtotal' => array() ) );

            $invoice->add_item( $item );

            // 4. Calculate
            $invoice->calculate_totals( false );
            
            // 5. Save & Link
            $invoice->update_meta_data( '_starke_is_balance_invoice', 'yes' );
            $invoice->update_meta_data( '_starke_parent_starke_number', $starke_num );
            $invoice->update_meta_data( '_po_number_job_name', $base_name );
            $invoice->save();

            $parent->update_meta_data( '_starke_balance_order_id', $invoice->get_id() );
            $parent->add_order_note( "Balance Invoice #" . $invoice->get_id() . " created." );
            $parent->save();

            if ( class_exists( 'Additional_Order_Quote_Meta_Creator' ) ) {
                $meta_creator = new Additional_Order_Quote_Meta_Creator();
                $meta_creator->process_new_order_meta( $invoice->get_id() );
                $invoice = wc_get_order( $invoice->get_id() );
            }

            // 6. Email Customer
            // NEW: Schedule it immediately for the next WP-CLI run
            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action( 
                    time(), 
                    'starke_async_send_balance_invoice', 
                    array( $invoice->get_id() ), 
                    'starke_payment_automation' 
                );
            } else {
                // Fallback just in case AS is missing
                WC()->mailer()->get_emails()['WC_Email_Customer_Invoice']->trigger( $invoice->get_id(), $invoice );
            }

            return true;

        } catch ( Exception $e ) {
            return new WP_Error( 'error', $e->getMessage() );
        }
    }

    public function hide_tax_line_for_balance_invoices( $total_rows, $order ) {
        if ( $order->get_meta( '_starke_is_balance_invoice', true ) === 'yes' ) {
            $has_fee = false;
            foreach ( $order->get_fees() as $fee ) {
                if ( stripos( $fee->get_name(), 'Convenience Fee' ) !== false ) {
                    $has_fee = true;
                    break;
                }
            }

            // Acknowledge the dynamically injected UI fee before it saves to DB
            if ( isset( $total_rows['card_fee'] ) ) {
                $has_fee = true;
            }

            if ( $has_fee ) {
                if ( isset( $total_rows['tax'] ) ) {
                    $total_rows['tax']['label'] = __( 'Tax on Fee:', 'woocommerce' );
                }
                foreach ( $total_rows as $key => $row ) {
                    if ( strpos( $key, 'tax' ) !== false && $key !== 'tax' ) {
                        if ( stripos( $row['label'], 'Fee' ) === false ) {
                            $total_rows[ $key ]['label'] = $row['label'] . ' ' . __( '(on Fee)', 'woocommerce' );
                        }
                    }
                }
            } else {
                if ( isset( $total_rows['tax'] ) ) {
                    unset( $total_rows['tax'] );
                }
                foreach ( $total_rows as $key => $row ) {
                    if ( strpos( $key, 'tax' ) !== false ) {
                        unset( $total_rows[ $key ] );
                    }
                }
            }
        }
        return $total_rows;
    }

    public function disable_admin_new_order_for_balance_invoices( $recipient, $order ) {
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            return $recipient;
        }
        if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
            return '';
        }
        return $recipient;
    }

    public function remove_order_again_button_on_view_order( $order ) {
        if ( ! $order ) return;
        if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
            remove_action( 'woocommerce_order_details_after_order_table', 'woocommerce_order_again_button' );
        }
    }

    public function schedule_net30_invoice( $order ) {
        if ( ! $order instanceof WC_Order ) return;

        $order_id = $order->get_id();
        $term     = $order->get_meta( '_starke_payment_terms', true );
            
        if ( 'net_30' === $term ) {
            $due_date = time() + ( 30 * DAY_IN_SECONDS );
            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action( 
                    $due_date, 
                    'starke_send_scheduled_invoice', 
                    array( $order_id ), 
                    'starke_payment_automation' 
                );
                $order->add_order_note( sprintf( 'Net 30: Automatic invoice scheduled for %s.', date_i18n( get_option( 'date_format' ), $due_date ) ) );
            }
        }
    }

    public function process_scheduled_invoice_check( $parent_order_id ) {
        $parent_order = wc_get_order( $parent_order_id );
        if ( ! $parent_order ) return;

        $balance_order_id = $parent_order->get_meta( '_starke_balance_order_id', true );

        if ( $balance_order_id ) {
            $balance_order = wc_get_order( $balance_order_id );
            // If invoice exists and is still unpaid (Pending/Failed)
            if ( $balance_order && $balance_order->has_status( array( 'pending', 'on-hold', 'failed' ) ) ) {
                
                // NEW: Use Action Scheduler to send the email
                if ( function_exists( 'as_schedule_single_action' ) ) {
                    as_schedule_single_action( time(), 'starke_async_send_balance_invoice', array( $balance_order->get_id() ), 'starke_payment_automation' );
                } else {
                    WC()->mailer()->get_emails()['WC_Email_Customer_Invoice']->trigger( $balance_order->get_id(), $balance_order );
                }

                $parent_order->add_order_note( 'Net 30 Reminder: Invoice email queued for sending.' );

                // Re-schedule check for 7 days later
                as_schedule_single_action( 
                    time() + ( 7 * DAY_IN_SECONDS ), 
                    'starke_send_scheduled_invoice', 
                    array( $parent_order_id ), 
                    'starke_payment_automation' 
                );
            } 
        } else {
            $result = $this->create_invoice_logic( $parent_order_id );
            if ( ! is_wp_error( $result ) ) {
                as_schedule_single_action( 
                    time() + ( 7 * DAY_IN_SECONDS ), 
                    'starke_send_scheduled_invoice', 
                    array( $parent_order_id ), 
                    'starke_payment_automation' 
                );
            }
        }
    }

    /**
     * ADMIN LIST: Show the "True Grand Total" (Project Value)
     * UPDATED: Now correctly shows the full value for Net 30 orders even before the invoice is sent.
     */
    public function display_true_grand_total_in_admin_list( $formatted_total, $order ) {
        // 1. Security Check: Only run in Admin Order Lists
        if ( ! is_admin() ) {
            return $formatted_total;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ) ) ) {
            return $formatted_total;
        }

        // 2. Get the Natural Total (The Source of Truth)
        // This is the full value of the order before any splitting or Net 30 deferral.
        $natural_total = (float) $order->get_meta( '_starke_natural_total', true );

        // If this meta doesn't exist, it's a standard order, so return default.
        if ( $natural_total <= 0 ) {
            return $formatted_total;
        }

        // 3. Logic: Check if we need to add Fees from a Balance Invoice
        $balance_invoice_id = $order->get_meta( '_starke_balance_order_id', true );
        $final_total = $natural_total;
        
        if ( $balance_invoice_id ) {
            $invoice = wc_get_order( $balance_invoice_id );
            if ( $invoice ) {
                // Formula: (Natural Total - Deferred Amount) + Invoice Total
                // This preserves the original charge + the new invoice (which includes fees)
                $deferred_amount = (float) $order->get_meta( '_starke_deferred_balance', true );
                $parent_charged = $natural_total - $deferred_amount;

                $final_total = $parent_charged + (float) $invoice->get_total();
            }
        }

        // 4. Return the Calculated Total
        // This ensures Net 30 orders show "$1,000" instead of "$0.00" immediately.
        return wc_price( $final_total, array( 'currency' => $order->get_currency() ) );
    }

    /**
     * WORKER: Sends the Balance Invoice Email via Action Scheduler
     * This runs in the background via WP-CLI.
     */
    public function send_balance_invoice_worker( $invoice_id ) {
        $invoice = wc_get_order( $invoice_id );
        if ( ! $invoice ) return;

        // Force email type to HTML to ensure professional look
        add_filter( 'wp_mail_content_type', function() { return 'text/html'; } );

        // Trigger the standard WC Invoice Email
        $mailer = WC()->mailer();
        $email = $mailer->get_emails()['WC_Email_Customer_Invoice'];
        if ( $email ) {
            $email->trigger( $invoice_id, $invoice );
            $invoice->add_order_note( 'Balance Invoice Email sent via Action Scheduler (Async).' );
        }

        remove_filter( 'wp_mail_content_type', function() { return 'text/html'; } );
    }

    /**
     * EMAIL: Customize Subject Line for Balance Invoices
     */
    public function custom_invoice_email_subject( $subject, $order ) {
        if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
            $parent_id = $order->get_parent_id();
            // "Balance Due for Order #1234"
            return sprintf( __( 'Action Required: Balance Payment for Order #%s', 'woocommerce' ), $parent_id );
        }
        return $subject;
    }

    /**
     * EMAIL: Customize Heading for Balance Invoices
     */
    public function custom_invoice_email_heading( $heading, $order ) {
        if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
            // "Pay Balance for Order #1234"
            return __( 'Balance Payment Required', 'woocommerce' );
        }
        return $heading;
    }

    /**
     * EMAIL: Professional Body Message with "Pay Balance" Link context
     */
    public function custom_invoice_email_message( $order, $sent_to_admin, $plain_text, $email ) {
        // Only target the Customer Invoice email type and Balance Invoices
        if ( 'customer_invoice' === $email->id && 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
            
            $parent_id = $order->get_parent_id();
            
            if ( $plain_text ) {
                echo "This is a balance invoice for your original order #{$parent_id}. Please submit payment to finalize your project.\n\n";
            } else {
                echo '<p style="margin-bottom: 20px;">' . 
                     sprintf( __( 'This is a balance invoice for your original order <strong>#%s</strong>. Please use the link below to submit payment and finalize your project.', 'woocommerce' ), $parent_id ) . 
                     '</p>';
                
                // Optional: Add a highly visible button if the default one isn't enough
                /*
                echo '<a href="' . esc_url( $order->get_checkout_payment_url() ) . '" style="background-color:#000; color:#fff; padding:12px 20px; text-decoration:none; border-radius:4px; font-weight:bold; display:inline-block; margin-bottom:20px;">' . 
                     __( 'Pay Balance Now', 'woocommerce' ) . 
                     '</a>';
                */
            }
        }
    }
    
    /**
     * AJAX: Manually Resend Invoice Email & Reset Schedule
     * Smart Logic: 
     * - Sends the email immediately (async).
     * - IF Net 30: Resets the automation timer to 7 days from now.
     * - IF 50/50: Does NOT start a schedule (one-off send).
     */
    public function resend_invoice_email_ajax() {
        check_ajax_referer( 'starke_invoice_nonce', 'security' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) wp_send_json_error( [ 'message' => 'Permission denied.' ] );

        $invoice_id = intval( $_POST['invoice_id'] );
        $invoice = wc_get_order( $invoice_id );

        if ( ! $invoice ) wp_send_json_error( [ 'message' => 'Invalid Invoice ID' ] );

        // 1. Send the Email (Async via Action Scheduler)
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time(), 'starke_async_send_balance_invoice', array( $invoice_id ), 'starke_payment_automation' );
        } else {
            WC()->mailer()->get_emails()['WC_Email_Customer_Invoice']->trigger( $invoice_id, $invoice );
        }

        // 2. SMART RESCHEDULE (Only for Net 30)
        $parent_id = $invoice->get_parent_id();
        if ( $parent_id ) {
            $parent_order = wc_get_order( $parent_id );
            $term = $parent_order ? $parent_order->get_meta( '_starke_payment_terms', true ) : '';

            // CRITICAL CHECK: Only touch the schedule if this is a Net 30 order
            if ( 'net_30' === $term && function_exists( 'as_unschedule_action' ) ) {
                
                // A. Cancel any currently pending reminder
                as_unschedule_action( 'starke_send_scheduled_invoice', array( $parent_id ), 'starke_payment_automation' );

                // B. Schedule a fresh one for 7 days from NOW
                as_schedule_single_action( 
                    time() + ( 7 * DAY_IN_SECONDS ), 
                    'starke_send_scheduled_invoice', 
                    array( $parent_id ), 
                    'starke_payment_automation' 
                );
                
                $invoice->add_order_note( 'Manual Resend: Email queued. Net 30 auto-reminder reset to 7 days from now.' );
            } else {
                // For 50/50 or others, just log that we sent it.
                $invoice->add_order_note( 'Manual Resend: Email queued.' );
            }
        }

        wp_send_json_success( [ 'message' => 'Email queued.' ] );
    }

    

    /**
     * WATCHER: Regenerate PDF on Status Change (Concrete Logic)
     * - IGNORES 'processing'/'on-hold' for Main Orders (handled by VernShippingBlock).
     * - HANDLES 'Paid' -> 'Unpaid' reversals (e.g., cancelling an order).
     * - HANDLES Balance Invoice updates (Regenerating the Parent).
     */
    public function regenerate_pdf_on_status_change( $order_id, $old_status, $new_status, $order ) {
        if ( ! $order ) return;

        // FORCE FRESH DATA: Clear cache to ensure we see the new status
        clean_post_cache( $order_id );

        // 1. IGNORE QUOTES (Always)
        $quote_statuses = ['active-quote', 'pending-quote', 'wc-active-quote', 'wc-pending-quote', 'freight-quote', 'wc-freight-quote', 'expired-quote', 'wc-expired-quote'];
        if ( in_array( $new_status, $quote_statuses ) || in_array( $old_status, $quote_statuses ) ) {
            return;
        }

        // 2. DEFINE STATUS GROUPS
        $paid_statuses   = ['processing', 'completed'];
        $unpaid_statuses = ['pending', 'on-hold', 'cancelled', 'failed', 'refunded'];

        // Check direction of change
        $is_becoming_paid   = in_array( $old_status, $unpaid_statuses ) && in_array( $new_status, $paid_statuses );
        $is_becoming_unpaid = in_array( $old_status, $paid_statuses ) && in_array( $new_status, $unpaid_statuses );

        // If financial status didn't change (e.g. processing -> completed), we generally don't need to regenerate
        // UNLESS it is a Balance Invoice (handled below).
        if ( ! $is_becoming_paid && ! $is_becoming_unpaid ) {
            // However, verify if we need to catch 'completed' if VernShipping doesn't hook it.
            // VernShipping hooks 'processing' and 'on-hold'. 
            // If we move processing -> completed, usually no PDF change is needed. 
            // So we can return here.
            // Exception: If it is a Balance Invoice, we might want to ensure parent is updated, 
            // but usually only the transition to "Paid" matters there.
            if ( 'yes' !== $order->get_meta( '_starke_is_balance_invoice', true ) ) {
                return;
            }
        }

        // 3. HANDLE BALANCE INVOICES (Asynchronous Non-Blocking)
        if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
            
            // Determine which email to send based on the new status
            $email_class = '';
            if ( 'processing' === $new_status ) {
                $email_class = 'WC_Email_Customer_Processing_Order';
            } elseif ( 'on-hold' === $new_status ) {
                $email_class = 'WC_Email_Customer_On_Hold_Order';
            } elseif ( 'completed' === $new_status ) {
                $email_class = 'WC_Email_Customer_Completed_Order';
            }

            // Fire the instant, non-blocking background request
            $ajax_url = admin_url( 'admin-ajax.php' );
            $nonce    = wp_create_nonce( 'starke_async_balance_nonce' );

            wp_remote_post( $ajax_url, [
                'method'   => 'POST',
                'timeout'  => 0.01,
                'blocking' => false,
                'body'     => [
                    'action'      => 'starke_async_balance_process',
                    'invoice_id'  => $order_id,
                    'email_class' => $email_class,
                    '_wpnonce'    => $nonce
                ],
                'headers'  => [ 'Connection' => 'close' ],
                'cookies'  => $_COOKIE
            ]);

            return; // Done. The checkout proceeds instantly while the background takes over.
        }

        // 4. HANDLE MAIN ORDER (The "Concrete" Fix)
        
        // Scenario A: Becoming Paid
        if ( $is_becoming_paid ) {
            // CRITICAL CHECK: Does the other plugin already handle this?
            // VernShippingBlock hooks 'processing' and 'on-hold'.
            if ( 'processing' === $new_status || 'on-hold' === $new_status ) {
                return; // STOP. Let the main checkout flow handle the PDF.
            }
            // If it skipped straight to 'completed' (rare, but possible), we regenerate.
            $this->trigger_pdf_generation( $order_id );
        }

        // Scenario B: Becoming Unpaid (Reversal)
        elseif ( $is_becoming_unpaid ) {
            // The main plugin does NOT handle reversals (e.g. processing -> pending).
            // We MUST regenerate to remove the "Paid" status from the PDF.
            $this->trigger_pdf_generation( $order_id );
        }
    }

    /**
     * PRIVATE HELPER: Call the PDF Generator safely
     */
    private function trigger_pdf_generation( $target_order_id ) {
        if ( ! class_exists( 'VernShippingBlock_Extend_Woo_Core' ) ) return;
        
        $generator = \VernShippingBlock_Extend_Woo_Core::get_instance();
        
        if ( method_exists( $generator, 'generate_order_quote_3d_pdf' ) ) {
            // We pass false/null for email args because we just want to update the file silently
            $generator->generate_order_quote_3d_pdf( $target_order_id, false, null );
            
            // Log it for debugging
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->info( "Status Change Trigger: Regenerating PDF for Order #{$target_order_id}", ['source' => 'starke-pdf-status-watch'] );
            }
        }
    }

    /**
     * 1. OVERRIDE ORDER NUMBER
     * Shows Parent Order ID (e.g. 1366-35) instead of Balance Invoice ID
     */
    public function starke_override_thankyou_order_number( $order_number, $order ) {
        if ( ! is_wc_endpoint_url( 'order-received' ) ) {
            return $order_number;
        }

        $target_order = $order;
        
        if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
            $parent_id = $order->get_parent_id();
            if ( $parent_id ) {
                $parent = wc_get_order( $parent_id );
                if ( $parent ) {
                    $target_order = $parent;
                }
            }
        }

        $starke_num = $target_order->get_meta( '_starke_order_number', true );
        return ! empty( $starke_num ) ? $starke_num : $order_number;
    }

    /**
     * OVERRIDE PAYMENT METHOD TITLE
     * Changes the displayed payment method to "Net 30 Terms" ONLY on the Order Received page.
     * This explicitly protects emails and the backend admin from being altered.
     */
    public function starke_net30_thankyou_payment_text( $method_title, $order ) {
        if ( ! $order ) return $method_title;

        // Strictly limit this change to the frontend Order Confirmation page
        if ( is_wc_endpoint_url( 'order-received' ) ) {
            $term = $order->get_meta( '_starke_payment_terms', true );
            if ( 'net_30' === $term ) {
                return __( 'Net 30 Terms', 'woocommerce' );
            }
        }
        
        return $method_title;
    }

    /**
     * TEXT REPLACEMENT: 
     * Catches "Order received" OR "Order completed" and swaps it.
     */
    public function starke_replace_order_received_text( $translated_text, $text, $domain ) {
        // FIX: Check for BOTH standard strings
        // "Order received" is used for On Hold/Pending
        // "Order completed" is used for Paid/Completed
        if ( 'woocommerce' === $domain && ( 'Order received' === $text || 'Order completed' === $text ) ) {
            
            if ( is_wc_endpoint_url( 'order-received' ) ) {
                 global $wp;
                 $order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
                 $order    = wc_get_order( $order_id );
                 
                 if ( $order && 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
                     $method = $order->get_payment_method();
                     
                     if ( 'cheque' === $method || 'bacs' === $method || 'cod' === $method ) {
                         return __( 'Invoice Submitted', 'woocommerce' );
                     } else {
                         return __( 'Balance Payment Received', 'woocommerce' );
                     }
                 }
            }
        }
        return $translated_text;
    }

    /**
     * 3. CHANGE THANK YOU MESSAGE
     * Context-aware message based on payment method
     */
    public function starke_custom_thankyou_text( $text, $order ) {
        if ( $order && 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
            $method = $order->get_payment_method();

            if ( 'cheque' === $method || 'bacs' === $method || 'cod' === $method ) {
                return __( 'Thank you. Your invoice has been submitted. Please verify the details below and send your payment to complete the balance.', 'woocommerce' );
            } else {
                // UPDATED VERBIAGE: 
                // Confirms THIS payment only. Does not imply the whole project is clear.
                return __( 'Thank you. We have successfully received your payment for this balance invoice.', 'woocommerce' );
            }
        }
        return $text;
    }

    /**
     * 4. CHANGE TABLE HEADER
     * "Product" -> "Description"
     */
    public function starke_custom_product_column_header( $translated_text, $text, $domain ) {
        if ( 'woocommerce' === $domain && 'Product' === $text && is_wc_endpoint_url( 'order-received' ) ) {
            global $wp;
            $order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
            $order    = wc_get_order( $order_id );

            if ( $order && 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
                return __( 'Description', 'woocommerce' );
            }
        }
        return $translated_text;
    }

    /**
     * 5. BROWSER TAB TITLE: Force Override
     */
    public function starke_force_browser_title( $title ) {
        if ( is_wc_endpoint_url( 'order-received' ) ) {
            global $wp;
            $order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
            $order    = wc_get_order( $order_id );

            if ( $order && 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
                $method = $order->get_payment_method();
                $new_title = '';

                if ( 'cheque' === $method || 'bacs' === $method || 'cod' === $method ) {
                    $new_title = __( 'Invoice Submitted', 'woocommerce' );
                } else {
                    // UPDATED
                    $new_title = __( 'Balance Payment Received', 'woocommerce' );
                }

                return $new_title . ' - ' . get_bloginfo( 'name' );
            }
        }
        return $title;
    }

    /**
     * CENTRALIZED HELPER: Calculates Payment Splits & Labels.
     * Strictly uses _starke_payment_terms.
     */
    public static function get_payment_splits( $order ) {
        // STRICT: Only use the Starke key as requested
        $term = $order->get_meta( '_starke_payment_terms', true );
        
        $natural_total = (float) $order->get_meta( '_starke_natural_total', true );
        
        // Fallback if natural total isn't set (e.g. legacy orders)
        if ( $natural_total <= 0 ) {
            $natural_total = (float) $order->get_total();
        }

        $data = [
            'term'             => $term,
            'project_total'    => $natural_total,
            'required_deposit' => 0.0,
            'deferred_balance' => 0.0,
            'balance_label'    => 'Balance Due',
        ];

        switch ( $term ) {
            case 'terms_50_50': 
            case '50_50':       
                // FIX: Explicitly Round the Deposit to 2 decimals (Round Half Up)
                // Example: 500.51 / 2 = 250.255 -> Rounds to 250.26
                $deposit = round( $natural_total / 2, 2 );
                
                // Balance is strictly the Remainder (Total - Deposit)
                // Example: 500.51 - 250.26 = 250.25
                $balance = round( $natural_total - $deposit, 2 );

                $data['required_deposit'] = $deposit;
                $data['deferred_balance'] = $balance;
                $data['balance_label']    = 'Balance Due (upon delivery)';
                break;

            case 'net_30':
                $data['required_deposit'] = 0.0;
                $data['deferred_balance'] = $natural_total;
                $data['balance_label']    = 'Balance Due (Net 30)';
                break;

            default: // No Terms (Standard)
                $data['required_deposit'] = $natural_total;
                $data['deferred_balance'] = 0.0;
                $data['balance_label']    = 'Balance Due';
                break;
        }

        return $data;
    }

    /**
     * Adds 'Amount Paid', 'Initial Balance Due', and 'Balance Due' rows.
     * FORCES 'Total' row to show the Natural (Project) Total.
     * Cleaned up: Removed redundant checks for "Amount Charged" rows.
     */
    public function add_custom_payment_terms_rows( $total_rows, $order, $tax_display ) {
        // --- 1. EMAIL SAFETY BYPASS (CRITICAL FIX) ---
        // If we are currently generating an email, we MUST skip the page checks below.
        // This ensures the "Completed Order" email gets the correct totals even if 
        // it triggers instantly while the user is still on the Checkout page.
        $is_email = did_action( 'woocommerce_email_header' );

        if ( ! $is_email ) {
            
            // --- 2. BLOCK FROM CHECKOUT & PAYMENT PAGES (Frontend Only) ---
            
            // A. Direct Page Checks
            if ( is_wc_endpoint_url( 'order-pay' ) ) {
                return $total_rows;
            }
            if ( is_checkout() && ! is_order_received_page() ) {
                return $total_rows;
            }

            // B. AJAX Referer Check (Switching Payment Methods)
            $referer = wp_get_referer();
            if ( $referer && false !== strpos( $referer, 'order-pay' ) ) {
                return $total_rows;
            }
        }

        // 2. STYLE SETTING
        // Frontend = Plain Text. Emails = 1.3em Bold.
        $is_frontend_page = ( ! is_admin() && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'view-order' ) ) );
        $apply_html_styles = ! $is_frontend_page;

        // 3. Get Centralized Data
        $splits = self::get_payment_splits( $order );
        $natural_total = $splits['project_total'];

        // --- 4. DATA FRESHNESS PROTOCOL (CORRECTED) ---
        // We clean the cache to ensure we aren't looking at old data.
        $fresh_order = $order;
        if ( $order->get_id() ) {
            clean_post_cache( $order->get_id() );
            $fresh_order = wc_get_order( $order->get_id() );
        }

        // Determine "Paid" Status using the FRESH object.
        // We use is_paid() to support ALL custom statuses (Processing, Completed, Fabrication, etc.)
        // 1. Start with Payment Method Check (Default assumption)
        $payment_method = $order->get_payment_method();

        // Add stripe_ach to the array of delayed offline methods!
        $delayed_methods = array( 'check', 'bacs', 'cheque', 'stripe_ach' ); 
        $is_paid_upfront = ! in_array( $payment_method, $delayed_methods );
        
        // OVERRIDE: Check if the database says it is paid.
        if ( $fresh_order && $fresh_order->is_paid() ) {
            $is_paid_upfront = true;
        }

        // 5. Calculate Values based on Terms
        $row_amount_paid = 0.0;
        $row_initial_due_val = 0.0;
        $row_final_due_val = 0.0;
        $row_final_due_label = $splits['balance_label'];

        if ( 'terms_50_50' === $splits['term'] || '50_50' === $splits['term'] ) {
            $row_final_due_val = $splits['deferred_balance'];
            if ( $is_paid_upfront ) {
                $row_amount_paid = $splits['required_deposit'];
                $row_initial_due_val = 0.0;
            } else {
                $row_amount_paid = 0.0;
                $row_initial_due_val = $splits['required_deposit'];
            }
        } elseif ( 'net_30' === $splits['term'] ) {
            $row_amount_paid = 0.0;
            $row_final_due_val = $splits['deferred_balance'];
        } else {
            $row_final_due_label = 'Balance Due';
            if ( $is_paid_upfront ) {
                $row_amount_paid = $splits['required_deposit']; 
                $row_final_due_val = 0.0; 
            } else {
                $row_amount_paid = 0.0;
                $row_final_due_val = $splits['required_deposit'];
            }
        }

        // --- 5b. CHECK LINKED BALANCE INVOICE STATUS (PRINCIPAL ONLY) ---
        // We calculate the amount paid based ONLY on the "Product" line items.
        // This ensures Credit Card Fees (which are "Fee" line items) are EXCLUDED 
        // from the "Amount Paid" total, keeping the Project Math clean.
        
        $balance_invoice_id = $order->get_meta( '_starke_balance_order_id', true );
        
        if ( $balance_invoice_id ) {
            // FORCE CLEAN CACHE
            clean_post_cache( $balance_invoice_id );

            $balance_invoice = wc_get_order( $balance_invoice_id );
            
            // Check if invoice exists AND is considered paid
            if ( $balance_invoice && $balance_invoice->is_paid() ) {
                
                $invoice_principal = 0.0;

                // Loop through ONLY 'line_item' types (The Balance Product).
                // This ignores 'fee' items entirely.
                foreach ( $balance_invoice->get_items( 'line_item' ) as $item ) {
                    $invoice_principal += (float) $item->get_total() + (float) $item->get_total_tax();
                }
                
                if ( $invoice_principal > 0 ) {
                    // 1. Add Principal to Total Amount Paid
                    $row_amount_paid += $invoice_principal;

                    // 2. Subtract Principal from Balance Due
                    $row_final_due_val -= $invoice_principal;
                    
                    // Safety: Ensure it doesn't go below zero
                    if ( $row_final_due_val < 0 ) {
                        $row_final_due_val = 0.0;
                    }
                }
            }
        }
        // -------------------------------------------------------------

        // 6. Define Styles
        if ( $apply_html_styles ) {
            $l_start = '<span style="font-size: 1.5em; font-weight: bold;">';
            $l_end   = '</span>';
            $v_start = '<span style="font-size: 1.3em; font-weight: normal;">';
            $v_end   = '</span>';
        } else {
            $l_start = ''; $l_end = '';
            $v_start = ''; $v_end = '';
        }

        // 7. Build Custom Rows
        $new_custom_rows = array();

        $new_custom_rows['starke_amount_paid'] = array(
            'label' => $l_start . 'Amount Paid:' . $l_end,
            'value' => $v_start . wc_price( $row_amount_paid ) . $v_end,
        );

        if ( $row_initial_due_val > 0.01 ) {
            $new_custom_rows['starke_initial_due'] = array(
                'label' => $l_start . 'Initial Balance Due:' . $l_end,
                'value' => $v_start . wc_price( $row_initial_due_val ) . $v_end,
            );
        }

        if ( $row_final_due_val > 0.01 || empty($splits['term']) || 'no_terms' === $splits['term'] ) {
            $new_custom_rows['starke_balance_due'] = array(
                'label' => $l_start . $row_final_due_label . ':' . $l_end,
                'value' => $v_start . wc_price( $row_final_due_val ) . $v_end,
            );
        }

        // --- NEW: CONSOLIDATE, SORT, & FORMAT ALL REFUNDS ---
        // We bypass WooCommerce's messy native formatting and gather ALL refund objects 
        // from both the Parent Order and the Balance Invoice.
        
        $all_refunds = array();

        // 1. Get Original Order Refunds
        foreach ( $order->get_refunds() as $refund ) {
            $all_refunds[] = $refund;
        }

        // 2. Get Balance Invoice Refunds
        if ( ! empty( $balance_invoice_id ) ) {
            $invoice_obj = wc_get_order( $balance_invoice_id );
            if ( $invoice_obj ) {
                foreach ( $invoice_obj->get_refunds() as $refund ) {
                    $all_refunds[] = $refund;
                }
            }
        }

        // 3. Sort all refunds by Date (Oldest to Newest)
        // This guarantees they will always flow top-to-bottom chronologically.
        usort( $all_refunds, function( $a, $b ) {
            $date_a = $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
            $date_b = $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;
            return $date_a <=> $date_b;
        });

        // 4. Build Clean Formatting
        $refund_rows = array();
        foreach ( $all_refunds as $refund ) {
            $reason = $refund->get_reason();
            
            // Format Label: "Refund:" with the note underneath in smaller text
            $label_html = __( 'Refund', 'woocommerce' ) . ':';
            if ( ! empty( $reason ) ) {
                $label_html .= '<br><small style="font-weight:normal; font-size:0.75em; color:#777; line-height:1.2; display:inline-block; margin-top:4px;">' . esc_html( $reason ) . '</small>';
            }

            $refund_key = 'starke_unified_refund_' . $refund->get_id();
            
            $refund_rows[ $refund_key ] = array(
                'label' => $l_start . $label_html . $l_end,
                'value' => $v_start . wc_price( -$refund->get_amount(), array( 'currency' => $refund->get_currency() ) ) . $v_end,
            );
        }

        // 2. Rebuild Array (Skipping refunds initially)
        $final_totals_array = array();
        
        foreach ( $total_rows as $key => $row ) {
            
            if ( 'payment_method' === $key ) continue;
            
            // Skip refunds here (we add them at the end)
            if ( strpos( $key, 'refund_' ) !== false ) continue;

            // INTERCEPT: The Total Row
            if ( 'order_total' === $key ) {
                $row['label'] = $l_start . 'Order Total:' . $l_end;
                $row['value'] = $v_start . wc_price( $natural_total, array( 'currency' => $order->get_currency() ) ) . $v_end;
                
                $final_totals_array[ $key ] = $row;

                // Append Custom Payment Rows (Amount Paid / Balance Due)
                foreach ( $new_custom_rows as $new_key => $new_row ) {
                    $final_totals_array[ $new_key ] = $new_row;
                }
                continue; 
            }
            
            $final_totals_array[ $key ] = $row;
        }

        // 3. Append Refund Rows at the very bottom
        foreach ( $refund_rows as $key => $row ) {
            $final_totals_array[ $key ] = $row;
        }

        return $final_totals_array;
    }

    /**
     * BLOCK NATIVE EMAILS:
     * Prevents WooCommerce from sending the email instantly during checkout,
     * ensuring it waits for our background worker to finish the PDF.
     */
    public function block_native_email_for_balance_invoice( $recipient, $order ) {
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) return $recipient;
        
        // If it's a balance invoice AND we are NOT inside our background worker... block the email.
        if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) && ! defined( 'STARKE_ASYNC_EMAIL_RUNNING' ) ) {
            return ''; 
        }
        return $recipient;
    }

    /**
     * THE BACKGROUND WORKER:
     * Runs silently in the background. Generates PDF safely, then fires the email.
     */
    public function async_balance_process_handler() {
        // 1. Verify Nonce
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'starke_async_balance_nonce' ) ) {
            wp_die();
        }

        $invoice_id  = isset( $_POST['invoice_id'] ) ? absint( $_POST['invoice_id'] ) : 0;
        $email_class = isset( $_POST['email_class'] ) ? sanitize_text_field( $_POST['email_class'] ) : '';

        $invoice = wc_get_order( $invoice_id );
        if ( ! $invoice ) wp_die();

        // 2. Generate the PDF for the Parent Order (Takes ~10 seconds, but we are in the background)
        $parent_id = $invoice->get_parent_id();
        if ( $parent_id ) {
            $this->trigger_pdf_generation( $parent_id );
        }

        // 3. Send the proper email with the newly generated PDF attached
        if ( ! empty( $email_class ) ) {
            // Define this constant so our block filter allows this specific email through
            define( 'STARKE_ASYNC_EMAIL_RUNNING', true );

            $mailer = WC()->mailer();
            $emails = $mailer->get_emails();

            if ( isset( $emails[ $email_class ] ) ) {
                $emails[ $email_class ]->trigger( $invoice_id, $invoice );
                $invoice->add_order_note( 'Async Worker: PDF Updated and ' . $email_class . ' sent.' );
            }
        }

        wp_die();
    }
    
    /**
     * SYNC: Copies any order notes made on the Balance Invoice to the Original Order
     * Includes HPOS compatibility and prefixes the note with the Starke ID.
     */
    public function sync_balance_invoice_notes_to_parent( $note_id, $order ) {
        // Ensure we have a valid order object
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        // Only proceed if this note was added to a Balance Invoice
        if ( 'yes' !== $order->get_meta( '_starke_is_balance_invoice', true ) ) {
            return;
        }

        // Static variable to prevent infinite loops during note creation
        static $syncing = false;
        if ( $syncing ) {
            return;
        }

        // Find the parent order
        $parent_id = $order->get_parent_id();
        if ( ! $parent_id ) {
            return;
        }

        $parent_order = wc_get_order( $parent_id );
        if ( ! $parent_order ) {
            return;
        }

        $note_content = '';
        $is_customer_note = 0;

        // HPOS Compatibility: Grab the note content safely
        if ( function_exists( 'wc_get_order_note' ) ) {
            $note = wc_get_order_note( $note_id );
            if ( $note ) {
                $note_content = $note->content;
                $is_customer_note = $note->customer_note ? 1 : 0;
            }
        } else {
            // Legacy WordPress Comments fallback
            $comment = get_comment( $note_id );
            if ( $comment ) {
                $note_content = $comment->comment_content;
                $is_customer_note = get_comment_meta( $note_id, 'is_customer_note', true );
            }
        }

        if ( empty( $note_content ) ) {
            return;
        }

        // Get the specific Starke ID for the prefix
        $starke_num = $order->get_meta( '_starke_order_number', true );
        $display_id = ! empty( $starke_num ) ? $starke_num : $order->get_id();

        // Format the new note
        $new_note = sprintf( '<strong>[Balance Invoice #%s]</strong> %s', $display_id, $note_content );

        // Add the note to the parent order
        $syncing = true;
        $parent_order->add_order_note( $new_note, (int) $is_customer_note, false );
        $syncing = false;
    }

    /**
     * EMAIL: Suppress gateway instructions (like mailing a check) for Net 30 orders
     */
    public function suppress_net30_gateway_email_instructions( $order, $sent_to_admin, $plain_text, $email ) {
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) return;

        // If this is a Net 30 order...
        if ( 'net_30' === $order->get_meta( '_starke_payment_terms', true ) ) {
            $payment_gateways = WC()->payment_gateways()->payment_gateways();
            $method = $order->get_payment_method();
            
            // Remove the native instructions block that the gateway tries to inject!
            if ( isset( $payment_gateways[ $method ] ) ) {
                remove_action( 'woocommerce_email_before_order_table', array( $payment_gateways[ $method ], 'email_instructions' ), 10 );
            }
        }
    }

    /**
     * ==============================================================================
     * ASYNC ADMIN EMAIL: SCHEDULE THE WORKER
     * Checks if the order is a balance invoice and queues the email in Action 
     * Scheduler, regardless of who triggered the status change.
     * ==============================================================================
     */
    public function schedule_admin_balance_paid_email( $order_id, $order ) {
        // 1. Target only balance invoices
        if ( 'yes' !== $order->get_meta( '_starke_is_balance_invoice', true ) ) {
            return;
        }

        // 2. Prevent duplicate emails (if it moves from processing -> completed)
        if ( 'yes' === $order->get_meta( '_admin_balance_paid_email_sent', true ) ) {
            return;
        }

        // 3. Queue it up in Action Scheduler
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( 
                time(), 
                'starke_async_send_admin_balance_paid', 
                array( $order_id ), 
                'starke-emails'
            );
            
            // Mark as sent so it doesn't double-fire later
            $order->update_meta_data( '_admin_balance_paid_email_sent', 'yes' );
            $order->save();
        }
    }

    /**
     * ==============================================================================
     * ASYNC ADMIN EMAIL: THE WORKER
     * Builds the HTML email, fetches the PDF from S3, and sends it to Admins.
     * ==============================================================================
     */
    public function process_admin_balance_paid_email_worker( $invoice_id ) {
        $invoice = wc_get_order( $invoice_id );
        if ( ! $invoice ) return;

        // --- 1. DETERMINE DISPLAY IDS (Targeting Parent Order) ---
        $parent_id = $invoice->get_parent_id();
        $parent_order = wc_get_order( $parent_id );
        $display_id = $invoice->get_order_number();

        if ( $parent_order ) {
            $starke_id = $parent_order->get_meta( '_starke_order_number', true );
            $display_id = ! empty( $starke_id ) ? $starke_id : $parent_order->get_order_number();
        }

        // --- 2. GET ADMIN RECIPIENTS ---
        $admins = get_users( array( 'role' => 'administrator' ) );
        $recipient_emails = array();
        foreach ( $admins as $admin ) {
            $recipient_emails[] = $admin->user_email;
        }

        // Optional: Exclude specific admin emails here if needed
        $excluded_emails = []; // ['danielle@starkemillwork.com', 'zac@starkemillwork.com']
        $recipient_emails = array_diff( $recipient_emails, $excluded_emails );

        if ( empty( $recipient_emails ) ) return;

        // --- 3. BUILD EMAIL CONTEXT ---
        $mailer = WC()->mailer();
        // Borrow "Completed Order" template for exact style matching
        $email_obj = $mailer->get_emails()['WC_Email_Customer_Completed_Order'] ?? null; 
        $site_name = get_bloginfo( 'name' );

        $subject = sprintf( '[%s] Payment Received: Balance Invoice for Order %s', $site_name, $display_id );
        $heading = 'Balance Payment Received';
        $customer_name = $invoice->get_billing_first_name() . ' ' . $invoice->get_billing_last_name();
        
        $p_style = "font-size: 16px; line-height: 1.5em; color: #333; margin-bottom: 20px;";

        // --- 4. CONSTRUCT HTML BODY ---
        ob_start();
        
        if ( $email_obj ) {
            echo wc_get_template_html( 'emails/email-header.php', array( 'email_heading' => $heading, 'email' => $email_obj ) );
        }
        ?>
        
        <p style="<?php echo $p_style; ?>">Hi Admin,</p>
        
        <p style="<?php echo $p_style; ?>">
            This email confirms that <strong><?php echo esc_html( $customer_name ); ?></strong> has successfully paid their balance invoice.
        </p>
        
        <p style="<?php echo $p_style; ?>">
            This payment has been verified and applied to Project <strong>Order #<?php echo esc_html( $display_id ); ?></strong>. The project is now fully paid and ready to proceed.
        </p>

        <?php
        // Inject the native order details table
        do_action( 'woocommerce_email_order_details', $invoice, true, false, $email_obj );
        
        // Inject the native customer details
        do_action( 'woocommerce_email_customer_details', $invoice, true, false, $email_obj );

        if ( $email_obj ) {
            echo wc_get_template_html( 'emails/email-footer.php', array( 'email' => $email_obj ) );
        }
        
        $content = ob_get_clean();

        // --- 5. APPLY INLINE STYLES ---
        if ( $email_obj && method_exists( $email_obj, 'style_inline' ) ) {
            $final_message = $email_obj->style_inline( $content );
        } else {
            $final_message = $mailer->wrap_message( $heading, $content );
        }

        // --- 6. FETCH PDF FROM S3 ---
        $attachments = [];
        // We want the PDF from the Parent Order, not the invoice itself
        $target_order_id = $parent_id ? $parent_id : $invoice_id; 
        
        // Because get_order_quote_pdf_from_s3_as_temp_file is in email.php, 
        // we check if it exists just to be safe.
        if ( function_exists( 'get_order_quote_pdf_from_s3_as_temp_file' ) ) {
            $pdf_path = get_order_quote_pdf_from_s3_as_temp_file( $target_order_id );
            
            if ( $pdf_path && file_exists( $pdf_path ) ) {
                $attachments[] = $pdf_path;
                
                register_shutdown_function(function($path) {
                    if (file_exists($path)) {
                        @unlink($path);
                    }
                }, $pdf_path);
            } else {
                error_log('Async Admin Email: Failed to attach S3 PDF for Order ' . $target_order_id);
            }
        }

        // --- 7. SEND EMAIL ---
        // We force WP to treat this as an HTML email for this specific send.
        $html_filter = function() { return 'text/html'; };
        add_filter( 'wp_mail_content_type', $html_filter );
        
        wp_mail( $recipient_emails, $subject, $final_message, '', $attachments );
        
        remove_filter( 'wp_mail_content_type', $html_filter );

        // --- 8. LOG IT ---
        $invoice->add_order_note( 'Admin notification email (Balance Paid) with PDF attached sent via Action Scheduler.' );
    }

    /**
     * Dynamically injects CC email headers onto the customer invoice email 
     * by retrieving them from the parent order's meta data.
     *
     * @param string $headers  The original email headers.
     * @param string $email_id The identifier for the current email type.
     * @param mixed  $order    The WooCommerce order object.
     * @return string          The modified email headers.
     */
    public function maybe_add_cc_headers_for_balance_invoice( $headers, $email_id, $order ) {
        // Only target the invoice/balance notification email types sent to customers
        if ( 'customer_invoice' === $email_id ) {
            if ( ! $order instanceof WC_Order ) {
                return $headers;
            }

            // Verify if this is a balance invoice
            if ( 'yes' !== $order->get_meta( '_starke_is_balance_invoice', true ) ) {
                return $headers;
            }

            // Grab the parent order ID to trace the original checkout CC configurations
            $parent_id = $order->get_parent_id();
            if ( ! $parent_id ) {
                return $headers;
            }

            $parent_order = wc_get_order( $parent_id );
            if ( ! $parent_order ) {
                return $headers;
            }

            // Retrieve the stored CC array from the parent order
            $cc_emails = $parent_order->get_meta( '_cc_emails', true );
            if ( ! is_array( $cc_emails ) || empty( $cc_emails ) ) {
                return $headers;
            }

            // Sanitize and filter out invalid email strings
            $valid_ccs = array_filter( array_map( 'sanitize_email', $cc_emails ), 'is_email' );
            
            if ( ! empty( $valid_ccs ) ) {
                $headers .= 'Cc: ' . implode( ', ', $valid_ccs ) . "\r\n";
            }
        }
        return $headers;
    }
} // Class End

new Starke_Payment_Manager();