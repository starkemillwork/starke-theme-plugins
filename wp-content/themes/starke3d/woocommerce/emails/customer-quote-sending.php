<?php
/**
 * Customer quote-sending email
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/customer-on-hold-order.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails
 * @version 7.3.0
 */

defined( 'ABSPATH' ) || exit;

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php 
// --- DETERMINE THE BEST FIRST NAME ---
$customer_first_name = '';
$user_id = $order->get_customer_id();

// 1. Try to get it from the User Account
if ( $user_id ) {
    $user_info = get_userdata( $user_id );
    if ( $user_info ) {
        $customer_first_name = $user_info->first_name;
        if ( empty( trim( $customer_first_name ) ) ) {
            $customer_first_name = get_user_meta( $user_id, 'billing_first_name', true );
        }
        if ( empty( trim( $customer_first_name ) ) && ! empty( $user_info->display_name ) && $user_info->display_name !== $user_info->user_email ) {
            $customer_first_name = $user_info->display_name;
        }
    }
}

// 2. Try the Order Billing Name
if ( empty( trim( $customer_first_name ) ) ) {
    $customer_first_name = $order->get_billing_first_name();
}

// 3. Fallback to the Email Prefix (and strictly prevent full emails from leaking through)
if ( empty( trim( $customer_first_name ) ) || strpos( $customer_first_name, '@' ) !== false ) {
    
    // FIX: Renamed variable to $customer_billing_email so it doesn't destroy the global $email object
    $customer_billing_email = $order->get_billing_email();
    
    // Safety check if order email is somehow missing
    if ( empty( trim( $customer_billing_email ) ) && isset( $user_info ) ) {
        $customer_billing_email = $user_info->user_email;
    }
    
    if ( ! empty( $customer_billing_email ) ) {
        $email_parts = explode( '@', $customer_billing_email );
        $customer_first_name = ucfirst( $email_parts[0] );
    }
}
?>
<p style="font-size: 1.2em; line-height: 1.5em;"><?php printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $customer_first_name ) ); ?></p>
<p style="font-size: 1.2em; line-height: 1.5em;"><?php esc_html_e( 'We are pleased to present the following proposal for your architectural molding project.', 'woocommerce' ); ?></p>
<?php
$quote_link = generate_link_for_quote( $order );
// Check if a valid quote link was generated
if ( $quote_link ) {
	?>
	<p>
		<?php
		// Create the clickable link
		// esc_url() is used to sanitize the URL for use in the href attribute
		// esc_html_e() is used to echo and escape the link text
		?>
		<a href="<?php echo esc_url( $quote_link ); ?>" style="font-size: 1.3em; font-weight: bold; color: #6431f6;">
			<?php esc_html_e( 'Access Your Molding Quote', 'woocommerce' ); ?>
		</a>
	</p>
	<?php
} else {
	?>
	<p><?php esc_html_e( 'This Molding Quote has expired.', 'woocommerce' ); ?></p>
	<?php
}

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
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

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );