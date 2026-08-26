<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
//require_once get_stylesheet_directory() . '/public/classes/price-engine.php';

// Enqueue and localize price engine script
function enqueue_price_engine_script() {
	if ( is_user_logged_in() ) {
		wp_enqueue_script(
			'price-engine',
			get_stylesheet_directory_uri() . '/assets/js/live-pricer.js',
			array( 'jquery' ),
			null,
			true
		);

		// Pass the AJAX URL to the script
		wp_localize_script( 'price-engine', 'pricing_ajax', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'price_engine_nonce' ),
		) );
	}
}
add_action( 'wp_enqueue_scripts', 'enqueue_price_engine_script');

// The AJAX function version
function calculate_pricing_ajax() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'You must be logged in to see pricing.' ), 403 );
		wp_die(); // Halt execution
	}
	if ( function_exists( 'starke_is_account_limited' ) && starke_is_account_limited() ) {
		wp_send_json_error( array( 'message' => 'Account Limited: Pricing calculations are disabled.' ), 403 );
		wp_die();
	}
	// Ensure this is an AJAX request
	if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
		wp_send_json_error( 'Invalid request.' );
	}
	// Validate the nonce
	if ( ! check_ajax_referer( 'price_engine_nonce', 'nonce', false ) ) {
		wp_send_json_error( 'Invalid nonce. Please refresh the page and try again.' );
		return;
	}

	// Get referrer
	$referer = wp_get_referer();
	// Use WooCommerce's helper to get the product ID from the URL
	$product_id = url_to_postid($referer);

	// Collect inputs
	$linear_feet = isset($_POST['linear_feet']) ? intval($_POST['linear_feet']) : 0;
	$linear_feet = $linear_feet < 0 ? 0 : $linear_feet;
	$width = isset($_POST['width']) ? floatval($_POST['width']) : 0;
	$width = $width < 0 ? 0 : $width;
	$thickness = isset($_POST['thickness']) ? floatval($_POST['thickness']) : 0;
	$thickness = $thickness < 0 ? 0 : $thickness;
	$lengths = isset($_POST['lengths']) ? sanitize_text_field($_POST['lengths']) : '';
	$rabbet_position = isset($_POST['rabbet_position']) ? sanitize_text_field($_POST['rabbet_position']) : 'OFF';
	$relief_angle = isset($_POST['relief_angle']) ? filter_var($_POST['relief_angle'], FILTER_VALIDATE_BOOLEAN ) : false;
	$species = isset($_POST['species']) ? intval($_POST['species']) : -1;
	$finish_option = isset($_POST['finish_option']) ? intval($_POST['finish_option']) : -1;

	// Set markup and waste inputs for function when product is a custom profile
	if (is_custom_profile($product_id)) {
		$markup = isset($_POST['markup']) ? floatval($_POST['markup']) : 0;
		$waste = isset($_POST['waste']) ? floatval($_POST['waste']) : 0;
	} else {
		// If not a custom profile, set markup and waste to null
		$markup = 0;
		$waste = 0;
	}
	
	$response = Price_Engine::calculate_pricing($linear_feet, $width, $thickness, $lengths, $rabbet_position, $relief_angle, $species, $finish_option, $product_id, $markup, $waste);
	// Return the calculated values
	wp_send_json_success($response);
}
add_action( 'wp_ajax_calculate_pricing', 'calculate_pricing_ajax');
add_action( 'wp_ajax_nopriv_calculate_pricing', 'calculate_pricing_ajax');