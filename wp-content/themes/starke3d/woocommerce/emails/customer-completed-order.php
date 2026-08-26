<?php
/**
 * Customer Completed Order Email
 *
 * This template is overridden for Starke Millwork to handle Balance Invoices correctly.
 * It ensures the verbiage is accurate (confirming receipt of this payment ONLY)
 * and forces the layout to show the full Project breakdown.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ============================================================
 * STARKE LOGIC: Detect Balance Invoice & Get Parent Data
 * ============================================================
 */
$is_starke_balance_invoice = ('yes' === $order->get_meta( '_starke_is_balance_invoice', true ));
$display_id = $order->get_order_number();

if ( $is_starke_balance_invoice ) {
    $parent_id = $order->get_parent_id();
    $parent_order = wc_get_order( $parent_id );
    if ( $parent_order ) {
        // Use Parent Starke ID for display
        $starke_id = $parent_order->get_meta( '_starke_order_number' );
        $display_id = ! empty( $starke_id ) ? $starke_id : $parent_order->get_order_number();
    }
}
/* ============================================================ */


/**
 * Executes the e-mail header.
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: %s: Customer first name */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<?php 
// --- STARKE CUSTOM VERBIAGE SECTION ---
if ( $is_starke_balance_invoice ) : ?>
    
    <p>
        <?php esc_html_e( 'Thank you. This email confirms we have successfully received payment for the balance invoice detailed below.', 'woocommerce' ); ?>
    </p>
    <p>
        <?php printf( esc_html__( 'This payment has been successfully processed and applied to Project Order #%s.', 'woocommerce' ), esc_html($display_id) ); ?>
    </p>

    <?php 
    // CRITICAL FIX: We DO NOT call 'woocommerce_email_before_order_table' here.
    // That hook is what injects the "Check Payment Instructions" text. 
    // By skipping it, we ensure a clean email.
    ?>

<?php else : ?>
    
    <p>
        <?php esc_html_e( 'We are pleased to confirm that all payments for your project have been successfully verified. Your order has finished processing and is now moving directly into the fulfillment phase to prepare for shipment.', 'woocommerce' ); ?>
    </p>
    
    <?php
    // Keep the standard hook for normal orders so standard plugins work
    do_action( 'woocommerce_email_before_order_table', $order, $sent_to_admin, $plain_text, $email );
    ?>

<?php endif; 
// --- END STARKE SECTION ---
?>

<?php

/*
 * ============================================================
 * STARKE LAYOUT FIX: DO NOT SWAP THE ORDER OBJECT
 * * We pass the ORIGINAL Balance Invoice ($order) to the table.
 * * Why? Because 'email-order-details.php' checks for the meta key
 * '_starke_is_balance_invoice'. If we passed the Parent Order, 
 * that check would fail, and it would show the default layout.
 * * By passing the original order, 'email-order-details.php' detects
 * the flag and handles the data swapping internally.
 * ============================================================
 */

/**
 * Shows the order details table.
 * @hooked WC_Emails::order_details() Shows the order details table.
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );


/**
 * Shows order meta data.
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/**
 * Shows customer details.
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/**
 * Executes the email footer.
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );