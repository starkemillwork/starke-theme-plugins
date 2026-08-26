<?php
/**
 * Admin Failed Order Email
 *
 * OVERRIDDEN FOR STARKE:
 * 1. Clear "Transaction Declined" internal alert.
 * 2. Uses Starke ID in the body text.
 * 3. Removes redundant buttons (relies on standard Order Header link).
 */

defined( 'ABSPATH' ) || exit;

// ==============================================================================
// 1. STARKE LOGIC: Resolve correct Display ID (Starke ID vs Order ID)
// ==============================================================================
$display_id = $order->get_order_number();

if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
    // If Balance Invoice, try to show Parent's Starke ID
    $parent = wc_get_order( $order->get_parent_id() );
    if ( $parent ) {
        $starke_id = $parent->get_meta( '_starke_order_number', true );
        $display_id = ! empty( $starke_id ) ? $starke_id : $parent->get_order_number();
    }
} else {
    // If Standard Order, show its own Starke ID
    $starke_id = $order->get_meta( '_starke_order_number', true );
    if ( ! empty( $starke_id ) ) {
        $display_id = $starke_id;
    }
}
// ==============================================================================

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p style="margin-bottom: 20px;">
    <?php 
    printf( 
        /* translators: %s: Order ID */
        esc_html__( 'Payment transaction failed for Order #%s.', 'woocommerce' ), 
        esc_html( $display_id ) 
    ); 
    ?>
</p>

<p style="margin-bottom: 30px;">
    <strong>Customer:</strong> <?php echo esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ); ?><br>
    <strong>Total Attempted:</strong> <?php echo $order->get_formatted_order_total(); ?><br>
    <strong>Status:</strong> <span style="color: #d63638; font-weight:bold;">Failed</span>
</p>

<p style="margin-bottom: 30px; font-style: italic; color: #666;">
    <?php esc_html_e( 'Action Item: Check the order notes for specific gateway error messages. If the customer does not retry successfully within 15 minutes, consider reaching out to offer a direct invoice.', 'woocommerce' ); ?>
</p>

<?php
/*
 * ============================================================
 * STARKE LAYOUT:
 * The 'email-order-details.php' template handles the 
 * Order # Link and the Items Table.
 * ============================================================
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );