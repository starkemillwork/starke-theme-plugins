<?php
/**
 * Customer Reset Password Email
 *
 * OVERRIDDEN FOR STARKE:
 * 1. Professional "Security Update" verbiage.
 * 2. Uses Starke Purple text link (Dark Mode Safe) instead of a button.
 * 3. Removes generic "Someone requested" text.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: %s: Customer username */ ?>
<p style="margin-bottom: 20px;">
    <?php printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $user_login ) ); ?>
</p>

<p style="margin-bottom: 20px;">
    <?php esc_html_e( 'We received a request to reset the password for your Starke Millwork account. To proceed with creating a new password, please use the secure link below:', 'woocommerce' ); ?>
</p>

<?php
/*
 * Link: Reset Password
 * Text Only (No Background) - Starke Purple (#6431f6)
 * Matches the "Return to Checkout" style you requested previously.
 * ADDED #reset HASH TO SURVIVE WOOCOMMERCE REDIRECTS!
 */
?>
<p style="margin-bottom: 40px;">
    <a href="<?php echo esc_url( add_query_arg( array( 'key' => $reset_key, 'id' => $user_id ), wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) ) ) ); ?>#reset" style="color: #6431f6; font-weight: bold; font-size: 1.1em; text-decoration: none;">
        <?php esc_html_e( 'Reset Password &rsaquo;', 'woocommerce' ); ?>
    </a>
</p>

<p style="margin-bottom: 20px;">
    <?php esc_html_e( 'If you did not initiate this request, you can safely ignore this email. Your account security has not been compromised, and no changes will be made.', 'woocommerce' ); ?>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );