<?php
/**
 * Async
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
 * Handle asynchonous requests
 *
 * @class WP_Grid_Builder_Caching\Includes\Async
 * @since 1.0.0
 */
abstract class Async {

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function __construct() {

		add_action( 'wp_ajax_wpgb_caching', [ $this, 'maybe_handle' ] );

	}

	/**
	 * Send response
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * @param boolean $success Success state.
	 * @param string  $message Holds message for backend.
	 * @param string  $content Holds content for backend.
	 */
	protected function send_response( $success = true, $message = '', $content = '' ) {

		wp_send_json(
			[
				'success' => (bool) $success,
				'message' => wp_kses_decode_entities( $message ),
				'content' => wp_kses_decode_entities( $content ),
			]
		);
	}

	/**
	 * Maybe handle request
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function maybe_handle() {

		$this->capability();
		$method = $this->referer();

		if ( 'cache_stats' === $method ) {
			$this->cache_stats();
		}

		if ( 'clear_cache' === $method ) {
			$this->clear_cache();
		}
	}

	/**
	 * Check capability
	 *
	 * @since 1.0.0
	 * @access protected
	 */
	protected function capability() {

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->send_response( false );
		}
	}

	/**
	 * Check referer
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * @return string Requested method
	 */
	protected function referer() {

		$method = isset( $_POST['method'] ) ? sanitize_key( $_POST['method'] ) : '';
		$action = 'wpgb_caching_' . $method;

		if ( false === check_ajax_referer( $action, 'nonce', false ) ) {
			$this->send_response( false );
		}

		return $method;

	}

	/**
	 * Handle table stats request
	 *
	 * @since 1.0.0
	 * @access protected
	 */
	abstract protected function cache_stats();

	/**
	 * Handle clear cache request
	 *
	 * @since 1.0.0
	 * @access protected
	 */
	abstract protected function clear_cache();
}
