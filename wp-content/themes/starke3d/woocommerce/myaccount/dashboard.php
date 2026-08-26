<?php
/**
 * My Account Dashboard
 *
 * Shows the first intro screen on the account dashboard.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/myaccount/dashboard.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 4.4.0
 */


/**
 * Custom Card Layout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$allowed_html = array(
	'a' => array(
		'href' => array(),
	),
);
?>

<p class="starke-dashboard-greeting">
	<?php
	printf(
		/* translators: 1: user display name 2: logout url */
		wp_kses( __( 'Hello %1$s (not %1$s? <a href="%2$s">Log out</a>)', 'woocommerce' ), $allowed_html ),
		'<strong>' . esc_html( $current_user->display_name ) . '</strong>',
		esc_url( wc_logout_url() )
	);
	?>
</p>

<p class="starke-dashboard-subtitle">
    <?php esc_html_e( 'From here you can view your recent activity and update your account information.', 'woocommerce' ); ?>
</p>

<div class="starke-dashboard-grid">

    <a href="<?php echo esc_url( wc_get_endpoint_url( 'orders' ) ); ?>" class="starke-dash-card">
        <div class="dash-card-icon"><i class="fas fa-shopping-bag"></i></div>
        <h3><?php esc_html_e( 'Orders', 'woocommerce' ); ?></h3>
        <p><?php esc_html_e( 'Track, return, or buy things again.', 'woocommerce' ); ?></p>
    </a>

    <a href="<?php echo esc_url( wc_get_endpoint_url( 'quotes' ) ); ?>" class="starke-dash-card">
        <div class="dash-card-icon"><i class="far fa-file-alt"></i></div>
        <h3><?php esc_html_e( 'Quotes', 'woocommerce' ); ?></h3>
        <p><?php esc_html_e( 'Open or check the status of your quotes.', 'woocommerce' ); ?></p>
    </a>

    <a href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address' ) ); ?>" class="starke-dash-card">
        <div class="dash-card-icon"><i class="fas fa-map-marker-alt"></i></div>
        <h3><?php esc_html_e( 'Addresses', 'woocommerce' ); ?></h3>
        <p><?php esc_html_e( 'Edit shipping and billing details.', 'woocommerce' ); ?></p>
    </a>

    <a href="<?php echo esc_url( wc_get_endpoint_url( 'edit-account' ) ); ?>" class="starke-dash-card">
        <div class="dash-card-icon"><i class="fas fa-user"></i></div>
        <h3><?php esc_html_e( 'Account Details', 'woocommerce' ); ?></h3>
        <p><?php esc_html_e( 'Update your password and email.', 'woocommerce' ); ?></p>
    </a>

</div>

<?php
	/**
	 * My Account dashboard.
	 * @since 2.6.0
	 */
	do_action( 'woocommerce_account_dashboard' );

	/**
	 * Deprecated woocommerce_before_my_account action.
	 * @deprecated 2.6.0
	 */
	do_action( 'woocommerce_before_my_account' );

	/**
	 * Deprecated woocommerce_after_my_account action.
	 * @deprecated 2.6.0
	 */
	do_action( 'woocommerce_after_my_account' );
/* Omit closing PHP tag at the end of PHP files to avoid "headers already sent" issues. */
