<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Uninstall
 *
 * @package   WP Grid Builder - Caching
 * @author    Loïc Blascos
 * @copyright 2019-2024 Loïc Blascos
 */

namespace WP_Grid_Builder_Caching;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Uninstall class
 *
 * @class WP_Grid_Builder_Caching\Uninstall
 * @since 1.0.0
 */
class Uninstall {

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function __construct() {

		if ( is_multisite() ) {
			$this->drop_tables();
		} else {
			$this->drop_table();
		}
	}

	/**
	 * Drop custom table from each site
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function drop_tables() {

		global $wpdb;

		// Save current blog ID.
		$current  = $wpdb->blogid;
		$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );

		// Create tables for each blog ID.
		foreach ( $blog_ids as $blog_id ) {

			switch_to_blog( $blog_id );
			delete_option( 'wpgb_caching_plugin_info' );
			$this->drop_table();

		}

		// Go back to current blog.
		switch_to_blog( $current );

	}

	/**
	 * Drop custom tables from current site
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function drop_table() {

		global $wpdb;

		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpgb_cache" );

	}
}

new Uninstall();
