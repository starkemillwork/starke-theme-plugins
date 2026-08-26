<?php
/**
 * REST API
 *
 * @package   WP Grid Builder - Caching
 * @author    Loïc Blascos
 * @copyright 2019-2024 Loïc Blascos
 */

namespace WP_Grid_Builder_Caching\Includes;

// Exit if not accessed with WP-CLI.
if ( ! defined( 'WP_CLI' ) ) {
	return;
}

/**
 * REST API Routes
 *
 * @class WP_Grid_Builder_Caching\Includes\Rest
 * @since 1.2.0
 */
class Rest {

	/**
	 * Constructor
	 *
	 * @since 1.2.0
	 * @access public
	 */
	public function __construct() {

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

	}

	/**
	 * Register REST routes
	 *
	 * @since 1.2.0
	 * @access public
	 */
	public function register_routes() {

		register_rest_route(
			'wpgb-caching',
			'/cache',
			[
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'clear_cache' ],
					'permission_callback' => [ $this, 'permission' ],
				],
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'cache_status' ],
					'permission_callback' => [ $this, 'permission' ],
				],
			]
		);
	}

	/**
	 * Handle REST API permission callback
	 *
	 * @since 1.2.0
	 * @access public
	 *
	 * @return WP_Error|boolean
	 */
	public function permission() {

		if ( ! current_user_can( 'manage_options' ) ) {

			$status = 401;

			if ( is_user_logged_in() ) {
				$status = 403;
			}

			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have enough permissions to complete the operation.', 'wpgb-caching' ),
				[ 'status' => $status ]
			);
		}

		return true;

	}

	/**
	 * Clear cache
	 *
	 * @since 1.2.0
	 * @access public
	 */
	public function clear_cache() {

		do_action( 'wp_grid_builder_caching/clear_cache' );

		return rest_ensure_response(
			[
				'message' => __( 'Cache cleared.', 'wpgb-caching' ),
				'data'    => '',
				'code'    => 'success',
			]
		);
	}

	/**
	 * Get cache status
	 *
	 * @since 1.2.0
	 * @access public
	 */
	public function cache_status() {

		global $wpdb;

		return rest_ensure_response(
			[
				'message' => '',
				'data'    => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpgb_cache" ),
				'code'    => 'success',
			]
		);
	}
}
