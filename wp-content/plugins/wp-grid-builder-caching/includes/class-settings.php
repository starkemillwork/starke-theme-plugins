<?php
/**
 * Settings
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
 * Handle global settings
 *
 * @class WP_Grid_Builder_Caching\Includes\Settings
 * @since 1.0.0
 */
final class Settings extends Async {

	use Table;

	/**
	 * Holds plugin settings
	 *
	 * @since 1.0.0
	 * @var array
	 */
	protected $settings = [];

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function __construct() {

		parent::__construct();
		$this->settings();

		add_action( 'wp_grid_builder/settings/render_fields', [ $this, 'enqueue' ] );
		add_action( 'wp_grid_builder/admin/enqueue_script', [ $this, 'enqueue_script' ] );
		add_filter( 'wp_grid_builder/tabs/options', [ $this, 'register_tab' ] );
		add_filter( 'wp_grid_builder/settings/global_tabs', [ $this, 'add_global_tab' ] );
		add_filter( 'wp_grid_builder/controls/options', [ $this, 'register_controls' ] );
		add_filter( 'wp_grid_builder/defaults/options', [ $this, 'default_values' ], 99, 1 );
		add_filter( 'wp_grid_builder/settings/global_fields', [ $this, 'add_global_fields' ] );
		add_filter( 'wp_grid_builder_caching/bypass', [ $this, 'bypass_cache' ], 1, 2 );
		add_filter( 'wp_grid_builder_caching/lifespan', [ $this, 'cache_lifespan' ], 1, 1 );
		add_filter( 'wp_grid_builder_caching/cron_interval', [ $this, 'cache_lifespan' ], 1, 1 );

	}

	/**
	 * Get plugin settings and normalize
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function settings() {

		$this->settings = wp_parse_args(
			get_option( 'wpgb_global_settings', [] ),
			[
				'caching_exclude_grids'     => [],
				'caching_exclude_facets'    => [],
				'caching_lifespan_unit'     => '',
				'caching_lifespan_interval' => '',
			]
		);
	}

	/**
	 * Enqueue scripts
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $settings Holds settings arguments.
	 */
	public function enqueue( $settings ) {

		if ( empty( $settings['id'] ) || 'global' !== $settings['id'] ) {
			return;
		}

		wp_enqueue_script( 'wpgb-caching', WPGB_CACHING_URL . 'assets/js/build.js', [], WPGB_CACHING_VERSION, true );

	}

	/**
	 * Enqueue options script
	 *
	 * @since 1.2.0
	 * @access public
	 *
	 * @param string $handle Script handle to localize.
	 */
	public function enqueue_script( $handle ) {

		if ( 'app' !== $handle ) {
			return;
		}

		wp_enqueue_script(
			'wpgb-caching',
			WPGB_CACHING_URL . 'assets/js/admin.js',
			[ 'wpgb-components' ],
			WPGB_CACHING_VERSION,
			true
		);

		wp_set_script_translations( 'wpgb-caching', 'wpgb-caching', WPGB_CACHING_PATH . '/languages' );

	}

	/**
	 * Register settings tab
	 *
	 * @since 1.2.0
	 * @access public
	 *
	 * @param array $tabs Holds global settings tabs.
	 * @return array
	 */
	public function register_tab( $tabs = [] ) {

		array_push(
			$tabs,
			[
				'name'  => 'caching',
				'title' => __( 'Caching', 'wpgb-caching' ),
			]
		);

		return $tabs;

	}

	/**
	 * Register global settings tab
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $tabs Holds global settings tabs.
	 * @return array
	 */
	public function add_global_tab( $tabs ) {

		$tabs[] = [
			'id'       => 'caching',
			'label'    => __( 'Caching', 'wpgb-caching' ),
			'title'    => __( 'Caching', 'wpgb-caching' ),
			'subtitle' => __( 'Manage caching of your grids and facets.', 'wpgb-caching' ),
			'icon'     => WPGB_CACHING_URL . 'assets/svg/sprite.svg#wpgb-rocket-icon',
		];

		return $tabs;

	}

	/**
	 * Register controls
	 *
	 * @since 1.2.0
	 * @access public
	 *
	 * @param array $controls Holds registered controls.
	 * @return array
	 */
	public function register_controls( $controls = [] ) {

		return array_merge(
			$controls,
			include WPGB_CACHING_PATH . 'includes/controls.php'
		);
	}

	/**
	 * Register default control values
	 *
	 * @since 1.2.0
	 * @access public
	 *
	 * @param array $values Holds default values.
	 * @return array
	 */
	public function default_values( $values = [] ) {

		return array_merge(
			$values,
			[
				'caching_exclude_grids'     => [],
				'caching_exclude_facets'    => [],
				'caching_lifespan_interval' => 1,
				'caching_lifespan_unit'     => 'DAY',
				'caching_auto_purge'        => true,
				'caching_admin_bar_menu'    => true,
			]
		);
	}

	/**
	 * Register global settings fields
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $fields Holds post fields.
	 * @return array
	 */
	public function add_global_fields( $fields ) {

		$settings = include WPGB_CACHING_PATH . 'includes/fields.php';

		return array_merge( $fields, $settings );

	}

	/**
	 * Handle table stats request
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function cache_stats() {

		global $wpdb;

		$results = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s',
				$wpdb->dbname,
				$wpdb->prefix . 'wpgb_cache'
			)
		);

		$results = array_shift( $results );

		$rows  = ! empty( $results->TABLE_ROWS ) ? $results->TABLE_ROWS : 0; // @codingStandardsIgnoreLine.
		$data  = ! empty( $results->DATA_LENGTH ) ? $results->DATA_LENGTH : 0; // @codingStandardsIgnoreLine.
		$index = ! empty( $results->INDEX_LENGTH ) ? $results->INDEX_LENGTH : 0; // @codingStandardsIgnoreLine.
		$size  = round( ( $data + $index ) / 1024 / 1024, 2 );

		$stats = sprintf(
			/* translators: %1$s Number of rows in cache table, %2$s cache table length. */
			_n( 'Cache table: &#126;%1$d row (&#126;%2$.2fMB)', 'Cache table: &#126;%1$d rows (&#126;%2$.2fMB)', max( 1, $rows ), 'wpgb-caching' ),
			(int) $rows,
			(float) $size
		);

		$this->send_response( true, '', esc_html( $stats ) );

	}

	/**
	 * Clear whole cache
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function clear_cache() {

		$this->clear_table();
		$this->send_response( true, '', esc_html__( 'Cache cleared!', 'wpgb-caching' ) );

	}

	/**
	 * Bypass cache for excluded grids and facets
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param boolean $bypass Should cache or not.
	 * @param array   $atts   Holds request attributes.
	 * @return boolean
	 */
	public function bypass_cache( $bypass, $atts ) {

		global $wpdb;

		if ( ! isset( $atts['id'] ) || ! function_exists( 'wpgb_get_url_search_params' ) ) {
			return $bypass;
		}

		$grids  = (array) $this->settings['caching_exclude_grids'];
		$facets = (array) $this->settings['caching_exclude_facets'];
		$params = array_keys( wpgb_get_url_search_params() );

		if ( in_array( $atts['id'], array_map( 'intval', $grids ), true ) ) {
			return true;
		}

		if ( empty( $facets ) || empty( $params ) ) {
			return false;
		}

		$facets = array_intersect( $facets, wpgb_get_facet_ids( $params ) );

		return count( $facets ) > 0;

	}

	/**
	 * Set cache lifespan
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param integer $lifespan Cache lifespan.
	 * @return integer
	 */
	public function cache_lifespan( $lifespan ) {

		if ( ! empty( $this->settings['caching_lifespan_interval'] ) ) {

			$units    = array_fill_keys( [ 'MINUTE', 'HOUR', 'DAY' ], 1 );
			$unit     = $this->settings['caching_lifespan_unit'];
			$unit     = isset( $units[ $unit ] ) ? $unit : 'DAY';
			$lifespan = (int) $this->settings['caching_lifespan_interval'] * constant( $unit . '_IN_SECONDS' );

		}

		// Set cron job interval to 1 hour at minimum.
		if ( 'wp_grid_builder_caching/cron_interval' === current_filter() ) {
			$lifespan = max( $lifespan, HOUR_IN_SECONDS );
		}

		return $lifespan;

	}
}
