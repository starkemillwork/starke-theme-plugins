<?php
/**
 * Request
 *
 * @package   WP Grid Builder - Caching
 * @author    Loïc Blascos
 * @copyright 2019-2024 Loïc Blascos
 */

namespace WP_Grid_Builder_Caching\Includes;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle cache request
 *
 * @class WP_Grid_Builder_Caching\Includes\Request
 * @since 1.0.0
 */
abstract class Request {

	/**
	 * Holds requested grid/template identifiers
	 *
	 * @since 1.0.0
	 * @var string|array
	 */
	protected $ids = '';

	/**
	 * Nonce that was used in the form to verify
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $nonce = '';

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function __construct() {

		add_action( 'admin_post_wpgb_caching_clear', [ $this, 'maybe_handle' ] );

	}

	/**
	 * Handle admin post action
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function maybe_handle() {

		$this->request();
		$this->check_capa();
		$this->check_nonce();
		$this->clear_cache( $this->ids );
		$this->redirect();

	}

	/**
	 * Get requested ids and nonce
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function request() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$request = wp_unslash( $_GET );

		if ( ! isset( $request['ids'], $request['_wpnonce'] ) ) {
			return;
		}

		$this->ids   = $request['ids'];
		$this->nonce = $request['_wpnonce'];

	}

	/**
	 * Check user capability
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function check_capa() {

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->redirect();
		}
	}

	/**
	 * Check nonce
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function check_nonce() {

		$object = array_map( 'sanitize_key', (array) $this->ids );
		$action = 'wpgb_caching_clear_' . implode( '_', $object );

		if ( ! wp_verify_nonce( $this->nonce, $action ) ) {
			$this->redirect();
		}
	}

	/**
	 * Redirect request
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function redirect() {

		wp_safe_redirect( esc_url_raw( wp_get_referer() ), 302, 'WP Grid Builder Caching' );
		exit;

	}
}
