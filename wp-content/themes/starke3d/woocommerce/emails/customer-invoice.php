<?php
/**
 * Customer Invoice / Order Details Email
 *
 * This template handles the "Balance Invoice" email sent to customers.
 * OVERRIDDEN for Starke to provide a high-end "Project Ready" notification
 * rather than a generic bill.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes the e-mail header.
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: %s: Customer first name */ ?>
<p><?php printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $order->get_billing_first_name() ) ); ?></p>

<?php if ( $order->has_status( 'pending' ) ) : ?>
    <p>
        <?php esc_html_e( 'The invoice for your project\'s final balance is now available.', 'woocommerce' ); ?>
    </p>
    <p>
        <?php esc_html_e( 'Please submit using the payment link below. Your order will be finalized and released for shipment once all payments on your order have been verified.', 'woocommerce' ); ?>
    </p>

    <p>
		<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" style="font-size: 1.3em; font-weight: bold; color: #6431f6; text-decoration: none;">
			<?php esc_html_e( 'Pay Balance Now', 'woocommerce' ); ?>
		</a>
	</p>

<?php else : ?>
    <p>
		<?php printf( esc_html__( 'Here are the details of your order placed on %s:', 'woocommerce' ), esc_html( wc_format_datetime( $order->get_date_created() ) ) ); ?>
	</p>
<?php endif; ?>

<?php
/**
 * Shows the order details table.
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
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