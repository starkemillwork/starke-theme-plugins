<?php
/**
 * Menu
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
 * Handle admin bar menu.
 *
 * @class WP_Grid_Builder_Caching\Includes\Menu
 * @since 1.0.0
 */
final class Menu {

	/**
	 * Holds all grid/template identifiers
	 *
	 * @since 1.0.0
	 * @access protected
	 * @var string
	 */
	protected $ids = [];

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function __construct() {

		$options = get_option( 'wpgb_global_settings', [] );

		// Amdin bar menu is activated by default (not isset).
		if (
			isset( $options['caching_admin_bar_menu'] ) &&
			empty( $options['caching_admin_bar_menu'] )
		) {
			return;
		}

		add_action( 'admin_bar_menu', [ $this, 'admin_bar_menu' ], 99 );
		add_filter( 'wp_grid_builder/facet/render_args', [ $this, 'get_ids' ], PHP_INT_MAX );

	}

	/**
	 * Add menu to admin bar
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $wp_admin_bar Holds admin bar nodes.
	 */
	public function admin_bar_menu( $wp_admin_bar ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$post_url  = admin_url( 'admin-post.php' );
		$this->ids = array_filter( array_unique( $this->ids ) );

		$wp_admin_bar->add_menu(
			[
				'id'    => 'wpgb_caching_cache',
				'title' => __( 'Gridbuilder ᵂᴾ', 'wpgb-caching' ),
				'href'  => add_query_arg(
					[
						'page' => 'wp-grid-builder',
					],
					admin_url( 'admin.php' )
				),
			]
		);

		$wp_admin_bar->add_menu(
			[
				'id'     => 'wpgb_caching_clear_all',
				'parent' => 'wpgb_caching_cache',
				'title'  => __( 'Clear cache (all)', 'wpgb-caching' ),
				'href'   => wp_nonce_url(
					add_query_arg(
						[
							'action' => 'wpgb_caching_clear',
							'ids'    => 'all',
						],
						$post_url
					),
					'wpgb_caching_clear_all'
				),
			]
		);

		if ( ! is_admin() && ! empty( $this->ids ) ) {

			$wp_admin_bar->add_menu(
				[
					'id'     => 'wpgb_caching_clear_page',
					'parent' => 'wpgb_caching_cache',
					'title'  => __( 'Clear cache (this page)', 'wpgb-caching' ),
					'href'   => wp_nonce_url(
						add_query_arg(
							[
								'action' => 'wpgb_caching_clear',
								'ids'    => array_map( 'urlencode', $this->ids ),
							],
							$post_url
						),
						'wpgb_caching_clear_' . implode( '_', array_map( 'sanitize_key', $this->ids ) )
					),
				]
			);
		}
	}

	/**
	 * Get grid identifiers from facets.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $args Holds grid/template args.
	 * @return array
	 */
	public function get_ids( $args ) {

		$this->ids[] = $args['grid'];

		return $args;

	}
}
