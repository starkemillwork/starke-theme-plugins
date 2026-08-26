<?php
/**
 * Cache
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
 * Handle cache
 *
 * @class WP_Grid_Builder_Caching\Includes\Cache
 * @since 1.0.0
 */
final class Cache extends Request {

	use Table;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function __construct() {

		parent::__construct();

		// Get cached response.
		add_action( 'wp_grid_builder/async/render', [ $this, 'get_cache' ] );
		add_action( 'wp_grid_builder/async/refresh', [ $this, 'get_cache' ] );

		// Set cache response.
		add_filter( 'wp_grid_builder/async/render_response', [ $this, 'set_cache' ], 10, 2 );
		add_filter( 'wp_grid_builder/async/refresh_response', [ $this, 'set_cache' ], 10, 2 );

		// Clear and cleanup cache.
		add_action( 'wp_grid_builder/updated', [ $this, 'clear_cache' ] );
		add_action( 'wp_grid_builder_caching/clear_cache', [ $this, 'clear_cache' ] );
		add_action( 'wp_grid_builder_caching/cleanup_cache', [ $this, 'cleanup_cache' ] );

	}

	/**
	 * Get cached response
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $atts Holds grid attributes.
	 */
	public function get_cache( $atts ) {

		if ( ! $this->can_cache( $atts ) ) {
			return;
		}

		$cache_name = $this->cache_name( $atts );

		if ( empty( $cache_name ) ) {
			return;
		}

		$cache_data = $this->get_row(
			[
				'grid' => $atts['id'],
				'name' => $cache_name,
			]
		);

		if ( $cache_data ) {

			$cache_data = json_decode( $cache_data );
			header( 'X-WP-Grid-Builder-Cache: true' );
			wp_send_json( $cache_data );

		}

		header( 'X-WP-Grid-Builder-Cache: false' );

	}

	/**
	 * Store response in cache
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $response Holds posts and facets response.
	 * @param array $atts     Holds grid/template attributes.
	 * @return array Holds posts and facets response
	 */
	public function set_cache( $response, $atts ) {

		if ( ! $this->can_cache( $atts ) ) {
			return $response;
		}

		$cache_name = $this->cache_name( $atts );

		if ( empty( $cache_name ) ) {
			return $response;
		}

		$this->insert_row(
			[
				'grid'  => $atts['id'],
				'name'  => $cache_name,
				'value' => wp_json_encode( $response ),
			]
		);

		return $response;

	}

	/**
	 * Clear cache entries
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $ids Holds grid/template identifiers.
	 */
	public function clear_cache( $ids = [] ) {

		if ( is_array( $ids ) && ! empty( $ids ) ) {
			$this->delete_rows( $ids );
		} else {
			$this->clear_table();
		}
	}

	/**
	 * Cleanup expired cache entries
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function cleanup_cache() {

		$this->cleanup_rows();

	}

	/**
	 * Check if we can cache response.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $atts Holds grid/template attributes.
	 * @return boolean
	 */
	public function can_cache( $atts ) {

		// We do not cache in preview mode or Gutenberg editor.
		if ( ! empty( $atts['is_preview'] ) || ! empty( $atts['is_gutenberg'] ) ) {
			return false;
		}

		// To bypass cache depending of current query args.
		if ( apply_filters( 'wp_grid_builder_caching/bypass', false, $atts ) ) {

			header( 'X-WP-Grid-Builder-Cache: bypass' );
			return false;

		}

		return true;

	}

	/**
	 * Build cache name from query args.
	 * We sort all facet names and values to keep cache consistency and to prevent to overload the cache table.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $atts Holds grid/template attributes.
	 * @return string URI
	 */
	public function cache_name( $atts ) {

		if ( ! function_exists( 'wpgb_get_url_search_params' ) ) {
			return '';
		}

		$params = wpgb_get_url_search_params();

		// If lang is used as query string we must use it to cache each lang.
		if ( ! empty( $atts['lang'] ) ) {
			$params['lang'] = (array) $atts['lang'];
		}

		// Sort param values.
		$params = array_map(
			function( $val ) {

				sort( $val );
				return $val;

			},
			$params
		);

		ksort( $params );

		$uri = add_query_arg( $params, $atts['permalink'] );
		$pre = strpos( current_filter(), 'refresh' ) !== false ? 'render' : 'refresh';

		return md5( $pre . $uri );

	}
}
