<?php
/**
 * Table
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
 * Handle custom table
 *
 * @class WP_Grid_Builder_Caching\Includes\Table
 * @since 1.0.0
 */
trait Table {

	/**
	 * Custom table
	 *
	 * @since 1.0.0
	 * @access private
	 * @var array
	 */
	private $table = '
		id BIGINT(20) unsigned NOT NULL auto_increment,
		grid VARCHAR(191) NOT NULL,
		name VARCHAR(191) NOT NULL,
		value LONGTEXT NOT NULL,
		expire DATETIME NOT NULL default "0000-00-00 00:00:00",
		PRIMARY KEY id (id)
	';

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @access public
	 */
	final public function __construct() {}

	/**
	 * Create custom Table
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param boolean $network_wide Network state.
	 */
	final public function create( $network_wide = false ) {

		if ( $network_wide && is_multisite() ) {
			$this->create_tables();
		} else {
			$this->create_table();
		}
	}

	/**
	 * Create custom tables (multisite)
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function create_tables() {

		global $wpdb;

		// Save current blog ID.
		$current  = $wpdb->blogid;
		$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );

		// Create tables for each blog ID.
		foreach ( $blog_ids as $blog_id ) {

			switch_to_blog( $blog_id );
			$this->create_table();

		}

		// Go back to current blog.
		switch_to_blog( $current );

	}

	/**
	 * Create custom table
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function create_table() {

		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpgb_cache
			($this->table) {$wpdb->get_charset_collate()};"
		);
	}

	/**
	 * Clear table
	 *
	 * @since 1.0.0
	 * @access public
	 */
	final public function clear_table() {

		global $wpdb;

		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wpgb_cache" );

	}

	/**
	 * Get row in table
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param string $args Holds grid and name to query.
	 * @return string|null
	 */
	final public function get_row( $args ) {

		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT value FROM {$wpdb->prefix}wpgb_cache
				WHERE grid = %s AND name = %s AND expire >= %s
				LIMIT 1",
				$args['grid'],
				$args['name'],
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Delete rows in table
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $grids Holds grid identifiers to delete.
	 */
	final public function delete_rows( $grids ) {

		global $wpdb;

		// Grids can holds integers or strings because of wpgb_render_template().
		$placeholders = rtrim( str_repeat( '%s,', count( $grids ) ), ',' );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wpgb_cache
				WHERE grid IN($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$grids
			)
		);
	}

	/**
	 * Cleanup expired rows in table
	 *
	 * @since 1.0.0
	 * @access public
	 */
	final public function cleanup_rows() {

		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wpgb_cache
				WHERE expire < %s",
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Insert row in table
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $columns Holds columns to insert in row table.
	 */
	final public function insert_row( $columns ) {

		global $wpdb;

		$lifespan = apply_filters( 'wp_grid_builder_caching/lifespan', 24 * HOUR_IN_SECONDS, $columns['grid'] );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}wpgb_cache
				(grid, name, value, expire)
				VALUES (%s, %s, %s, %s)",
				$columns['grid'],
				$columns['name'],
				$columns['value'],
				gmdate( 'Y-m-d H:i:s', time() + $lifespan )
			)
		);
	}
}
