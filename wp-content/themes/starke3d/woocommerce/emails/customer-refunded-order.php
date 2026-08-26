<?php
/**
 * Customer Refunded Order Email
 *
 * OVERRIDDEN FOR STARKE:
 * 1. "Transaction Receipt" verbiage.
 * 2. EXPLICITLY shows the Refund Amount in the body text.
 * 3. Handles Partial vs Full refunds intelligently.
 * 4. Integrated Starke ID logic.
 */

defined( 'ABSPATH' ) || exit;

/*
 * STARKE LOGIC: Detect Balance Invoice & Get Parent Data
 */
$display_id = $order->get_order_number();
if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
    $parent_id = $order->get_parent_id();
    $parent_order = wc_get_order( $parent_id );
    if ( $parent_order ) {
        $starke_id = $parent_order->get_meta( '_starke_order_number' );
        $display_id = ! empty( $starke_id ) ? $starke_id : $parent_order->get_order_number();
    }
} else {
    $starke_id = $order->get_meta( '_starke_order_number' );
    if ( ! empty( $starke_id ) ) {
        $display_id = $starke_id;
    }
}

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: %s: Customer first name */ ?>
<p style="margin-bottom: 20px;"><?php printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<p style="margin-bottom: 20px;">
    <?php
    if ( $partial_refund && is_object( $refund ) ) {
        /* PARTIAL REFUND: Specific amount */
        $refund_amount = wc_price( abs( $refund->get_amount() ), array( 'currency' => $order->get_currency() ) );
        
        printf(
            __( 'A partial refund of <strong>%s</strong> has been issued for Order #%s. The funds have been returned to your original payment method.', 'woocommerce' ),
            $refund_amount, 
            esc_html( $display_id )
        );
    } else {
        /* FULL REFUND: Full order total */
        $refund_amount = $order->get_formatted_order_total();

        printf(
            __( 'A full refund of <strong>%s</strong> has been processed for Order #%s. The funds have been returned to your original payment method.', 'woocommerce' ),
            $refund_amount,
            esc_html( $display_id )
        );
    }
    ?>
</p>

<p style="margin-bottom: 30px;">
    <?php esc_html_e( 'Please allow 3-5 business days for this transaction to appear on your statement, depending on your financial institution.', 'woocommerce' ); ?>
</p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );