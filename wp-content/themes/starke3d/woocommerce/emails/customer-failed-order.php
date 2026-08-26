<?php
/**
 * Customer Failed Order Email
 *
 * OVERRIDDEN FOR STARKE:
 * 1. Professional transaction exception verbiage.
 * 2. Integrated Starke ID / PO Number data pulls.
 * 3. Secure direct portal access link ignoring native unstyled buttons.
 */

defined( 'ABSPATH' ) || exit;

/*
 * STARKE LOGIC: Resolve correct Display ID (Starke ID vs Order ID)
 */
$display_id = $order->get_order_number();

if ( 'yes' === $order->get_meta( '_starke_is_balance_invoice', true ) ) {
    $parent = wc_get_order( $order->get_parent_id() );
    if ( $parent ) {
        $starke_id = $parent->get_meta( '_starke_order_number', true );
        $display_id = ! empty( $starke_id ) ? $starke_id : $parent->get_order_number();
    }
} else {
    $po_number = $order->get_meta( '_po_number_job_name', true );
    if ( ! empty( $po_number ) ) {
        $display_id = 'PO ' . $po_number;
    } else {
        $starke_id = $order->get_meta( '_starke_order_number', true );
        if ( ! empty( $starke_id ) ) {
            $display_id = $starke_id;
        }
    }
}

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p style="margin-bottom: 20px;">
    <?php printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $order->get_billing_first_name() ) ); ?>
</p>

<p style="margin-bottom: 20px; font-size: 16px; line-height: 1.5em; color: #333;">
    We were attempting to finalize processing for <strong><?php echo esc_html( $display_id ); ?></strong>; however, your financial institution was unable to authorize the payment transaction request.
</p>

<div style="margin-top: 25px; margin-bottom: 25px; padding: 15px; background-color: #f9f9f9; border-left: 4px solid #6431f6; font-size: 15px; line-height: 1.5em; color: #333;">
    <strong>Account Standing Hold:</strong> Your project's active engineering details and staging slots remain intact for now. <!-- To maintain manufacturing timeline integrity, you may manually update the transaction or specify alternative processing details directly through your secure portal gateway link below. -->
</div>

<!-- <p style="margin-bottom: 40px; margin-top: 30px;">
    <a href="<?php /*echo esc_url( $order->get_checkout_payment_url() ); */?>" style="color: #6431f6; font-weight: bold; font-size: 16px; text-decoration: none;">
        Securely Retry Payment Authorization &rsaquo;
    </a>
</p> -->

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
    echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );