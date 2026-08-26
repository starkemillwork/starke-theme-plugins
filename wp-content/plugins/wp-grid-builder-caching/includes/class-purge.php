<?php
/**
 * Purge
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
 * Handle auto purge
 *
 * @class WP_Grid_Builder_Caching\Includes\Purge
 * @since 1.0.2
 */
final class Purge {

	/**
	 * WordPress core actions
	 *
	 * @since 1.0.2
	 * @access public
	 * @var array
	 */
	public $core_actions = [
		// Post actions.
		'save_post',
		'delete_post',
		// Attachment actions.
		'edit_attachment',
		'add_attachment',
		// Term actions.
		'create_term',
		'edited_terms',
		'delete_term',
		'set_object_terms',
		// User actions.
		'user_register',
		'profile_update',
		'deleted_user',
		// Comment action.
		'wp_update_comment_count',
		// Permalink actions.
		'update_option_category_base',
		'update_option_tag_base',
		'permalink_structure_changed',
	];

	/**
	 * Plugin actions
	 *
	 * @since 1.0.2
	 * @access private
	 * @var array
	 */
	private $plugin_actions = [
		'wp_grid_builder/save/grid',
		'wp_grid_builder/save/card',
		'wp_grid_builder/save/facet',
		'wp_grid_builder/delete/grids',
		'wp_grid_builder/delete/cards',
		'wp_grid_builder/delete/facets',
	];

	/**
	 * Constructor
	 *
	 * @since 1.0.2
	 * @access public
	 */
	public function __construct() {

		if ( ! $this->can_purge() ) {
			return;
		}

		foreach ( $this->get_core_actions() as $action ) {
			add_action( $action, [ $this, 'purge' ] );
		}

		foreach ( $this->plugin_actions as $action ) {
			add_action( $action, [ $this, 'purge' ] );
		}
	}

	/**
	 * Check if auto purge is enabled
	 *
	 * @since 1.0.2
	 * @access public
	 */
	public function can_purge() {

		$settings = get_option( 'wpgb_global_settings', [] );

		// Auto purge is enabled by default (if not exists).
		return ! isset( $settings['caching_auto_purge'] ) || ! empty( $settings['caching_auto_purge'] );

	}

	/**
	 * Get actions that purge cache
	 *
	 * @since 1.0.2
	 * @access public
	 */
	public function get_core_actions() {

		// Allow 3rd party plugins or add-ons to add purge actions.
		return (array) apply_filters( 'wp_grid_builder_caching/purge_actions', $this->core_actions );

	}

	/**
	 * Purge cache
	 *
	 * @since 1.0.2
	 * @access public
	 */
	public function purge() {

		do_action( 'wp_grid_builder_caching/clear_cache' );

	}
}
