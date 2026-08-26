<?php
/**
 * Plugin
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
 * Main Instance of the plugin
 *
 * @class WP_Grid_Builder_Caching\Includes\Plugin
 * @since 1.0.0
 */
final class Plugin {

	use Table;

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function __construct() {

		add_action( 'wp_initialize_site', [ $this, 'insert_site' ] );
		add_action( 'wpmu_drop_tables', [ $this, 'delete_site' ] );
		add_action( 'plugins_loaded', [ $this, 'textdomain' ] );
		add_action( 'wp_grid_builder/init', [ $this, 'init' ] );
		add_filter( 'wp_grid_builder/register', [ $this, 'register' ] );
		add_filter( 'wp_grid_builder/plugin_info', [ $this, 'plugin_info' ], 10, 2 );

		add_action( 'upgrader_process_complete', [ $this, 'update' ], 10, 2 );
		register_activation_hook( WPGB_CACHING_FILE, [ $this, 'activation' ] );
		register_deactivation_hook( WPGB_CACHING_FILE, [ $this, 'deactivation' ] );

	}

	/**
	 * Load plugin text domain.
	 *
	 * @since 1.0.1
	 */
	public function textdomain() {

		load_plugin_textdomain(
			'wpgb-caching',
			false,
			basename( dirname( WPGB_CACHING_FILE ) ) . '/languages'
		);
	}


	/**
	 * Init instances
	 *
	 * @since 1.0.1 Check for plugin compatibility.
	 * @since 1.0.0
	 * @access public
	 */
	public function init() {

		new Settings();
		new Cache();
		new purge();
		new Rest();
		new Cron();
		new Menu();

	}

	/**
	 * Register add-on
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $addons Holds registered add-ons.
	 * @return array
	 */
	public function register( $addons ) {

		$addons[] = [
			'name'    => 'Caching',
			'slug'    => WPGB_CACHING_BASE,
			'option'  => 'wpgb_caching',
			'version' => WPGB_CACHING_VERSION,
		];

		return $addons;

	}

	/**
	 * Set plugin info
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array  $info Holds plugin info.
	 * @param string $name Current plugin name.
	 * @return array
	 */
	public function plugin_info( $info, $name ) {

		if ( 'Caching' !== $name ) {
			return $info;
		}

		$info['icons'] = [
			'1x' => WPGB_CACHING_URL . 'assets/imgs/icon.png',
			'2x' => WPGB_CACHING_URL . 'assets/imgs/icon.png',
		];

		if ( ! empty( $info['info'] ) ) {

			$info['info']->banners = [
				'low'  => WPGB_CACHING_URL . 'assets/imgs/banner.png',
				'high' => WPGB_CACHING_URL . 'assets/imgs/banner.png',
			];
		}

		return $info;

	}

	/**
	 * Create custom tables on plugin update
	 *
	 * @since 1.0.1
	 * @access public
	 *
	 * @param array $upgrader_object Holds upgrader arguments.
	 * @param array $options Holds plugin options.
	 */
	public function update( $upgrader_object, $options ) {

		if ( 'update' !== $options['action'] || 'plugin' !== $options['type'] ) {
			return;
		}

		if ( empty( $options['plugins'] ) ) {
			return;
		}

		foreach ( $options['plugins'] as $plugin ) {

			if ( WPGB_CACHING_BASE === $plugin ) {

				$network_wide = is_plugin_active_for_network( WPGB_BASE );
				$this->create( $network_wide );
				$this->clear_table();
				break;

			}
		}
	}

	/**
	 * Create custom cache table
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param boolean $network_wide Whether to enable the plugin for all sites in the network.
	 */
	public function activation( $network_wide ) {

		$this->create( $network_wide );

	}

	/**
	 * Clean the scheduler on deactivation
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function deactivation() {

		wp_clear_scheduled_hook( 'wp_grid_builder_caching/cleanup_cache' );

	}

	/**
	 * Create custom cache table whenever a new site is created (multisite)
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param WP_Site|integer $new_site New site object | New site id.
	 */
	public function insert_site( $new_site ) {

		if ( ! is_plugin_active_for_network( WPGB_CACHING_BASE ) ) {
			return;
		}

		switch_to_blog( $new_site->id );
		$this->create();
		restore_current_blog();

	}

	/**
	 * Delete custom cache table whenever a site is delete (multisite)
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $tables New site object.
	 */
	public function delete_site( $tables ) {

		global $wpdb;

		$tables[] = "{$wpdb->prefix}wpgb_cache";

		return $tables;

	}
}
