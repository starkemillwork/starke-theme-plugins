<?php
/**
 * Admin Cancelled Order Email
 *
 * OVERRIDDEN FOR STARKE:
 * 1. Replaces generic "Notification..." text with urgent "STOP PRODUCTION" alert.
 * 2. Uses Starke ID logic.
 * 3. Adds operational checklist for the admin team.
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

<p style="margin-bottom: 20px; font-size: 1.2em; color: #d63638; font-weight: bold;">
    <?php 
    printf( 
        /* translators: %s: Order ID */
        esc_html__( 'Order #%s has been Cancelled.', 'woocommerce' ), 
        esc_html( $display_id ) 
    ); 
    ?>
</p>

<div style="background-color: #ffe8e8; border-left: 5px solid #d63638; padding: 15px; margin-bottom: 30px;">
    <strong style="color: #d63638;">ACTION REQUIRED:</strong>
    <ul style="margin: 10px 0 0 20px; color: #d63638;">
        <li><strong>STOP PRODUCTION:</strong> Ensure all work on this project is halted immediately.</li>
    </ul>
</div>

<p style="margin-bottom: 30px;">
    <strong>Customer:</strong> <?php echo esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ); ?><br>
    <strong>Order Total:</strong> <?php echo $order->get_formatted_order_total(); ?>
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