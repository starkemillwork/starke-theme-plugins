<?php
/**
 * Customer On-Hold Order Email (Balance Invoice Submitted)
 *
 * OVERRIDDEN FOR STARKE:
 * 1. Detects Balance Invoices vs Normal Orders.
 * 2. Uses "Invoice Submitted" verbiage for Balance Invoices.
 * 3. Uses "Payment Verified" verbiage for Normal Orders (Fixed).
 * 4. Uses Parent Order data for Balance Invoice Totals logic.
 */

defined( 'ABSPATH' ) || exit;

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

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: %s: Customer first name */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<?php 
// --- STARKE CUSTOM VERBIAGE SECTION ---
if ( $is_starke_balance_invoice ) : ?>
    
    <p>
        <?php esc_html_e( 'Thank you. We have received your balance invoice submission. Your payment is currently being verified.', 'woocommerce' ); ?>
    </p>
    <p>
        <?php printf( esc_html__( 'Your order will be finalized and released for shipment once all payments on your account have been verified for Project Order #%s.', 'woocommerce' ), esc_html($display_id) ); ?>
    </p>

    <?php do_action( 'woocommerce_email_before_order_table', $order, $sent_to_admin, $plain_text, $email ); ?>

<?php else : ?>
    
    <p>
        <?php esc_html_e( 'We have received your order details and are preparing for production. Your payment is currently waiting to be verified. Once cleared, we will proceed with the next steps for your project.', 'woocommerce' ); ?>
    </p>
    <?php do_action( 'woocommerce_email_before_order_table', $order, $sent_to_admin, $plain_text, $email ); ?>

<?php endif; 
// --- END STARKE SECTION ---
?>

<?php
/*
 * ============================================================
 * STARKE LAYOUT FIX: PASS ORIGINAL ORDER
 * The 'email-order-details.php' template will detect the flag 
 * and swap the data to show the correct Project Totals.
 * ============================================================
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );