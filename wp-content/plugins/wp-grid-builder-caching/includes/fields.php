<?php
/**
 * Caching fields
 *
 * @package   WP Grid Builder - Caching
 * @author    Loïc Blascos
 * @copyright 2019-2024 Loïc Blascos
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$options = [
	'grids'  => (array) $this->settings['caching_exclude_grids'],
	'facets' => (array) $this->settings['caching_exclude_facets'],
];

if ( ! wp_doing_ajax() ) {

	array_walk(
		$options,
		function( &$ids, $type ) {

			global $wpdb;

			if ( empty( $ids ) ) {
				return;
			}

			$holders = rtrim( str_repeat( '%d,', count( $ids ) ), ',' );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, name
					FROM {$wpdb->prefix}wpgb_{$type}
					WHERE id IN ($holders)",
					$ids
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( (array) $results as $item ) {
				$items[ $item->id ] = $item->name;
			}

			$ids = $items;

		}
	);

}

return [
	[
		'id'     => 'caching',
		'tab'    => 'caching',
		'type'   => 'section',
		'title'  => __( 'Cache Menu', 'wpgb-caching' ),
		'fields' => [
			[
				'id'      => 'caching_admin_bar_menu',
				'type'    => 'toggle',
				'label'   => __( 'Admin Bar', 'wpgb-caching' ),
				'tooltip' => __( 'Display a menu of quick actions in the admin bar to clear cache.', 'wpgb-caching' ),
				'default' => true,
			],
		],
	],
	[
		'id'     => 'caching',
		'tab'    => 'caching',
		'type'   => 'section',
		'title'  => __( 'Caching Rules', 'wpgb-caching' ),
		'fields' => [
			[
				'id'          => 'caching_exclude_grids',
				'type'        => 'select',
				'label'       => __( 'Exclude Grids', 'wpgb-caching' ),
				'width'       => 380,
				'search'      => true,
				'multiple'    => true,
				'options'     => $options['grids'],
				'async'       => 'search_grids',
				'placeholder' => _x( 'None', 'Exclude Grids default value', 'wpgb-caching' ),
			],

			[
				'id'          => 'caching_exclude_facets',
				'type'        => 'select',
				'label'       => __( 'Exclude Facets', 'wpgb-caching' ),
				'width'       => 380,
				'search'      => true,
				'multiple'    => true,
				'options'     => $options['facets'],
				'async'       => 'search_facets',
				'placeholder' => _x( 'None', 'Exclude Facets default value', 'wpgb-caching' ),
			],
		],
	],
	[
		'id'     => 'caching',
		'tab'    => 'caching',
		'type'   => 'section',
		'title'  => __( 'Cache Behaviour', 'wpgb-caching' ),
		'fields' => [
			[
				'id'      => 'caching_auto_purge',
				'type'    => 'toggle',
				'label'   => __( 'Automatic Purge', 'wpgb-caching' ),
				'tooltip' => __( 'Purge cache automatically when updating your posts, taxonomy terms, users and any related data.', 'wpgb-caching' ),
				'default' => true,
			],
			[
				'id'        => 'caching_duration',
				'type'      => 'group',
				'label'     => __( 'Cache Lifespan', 'wpgb-caching' ),
				'tooltip'   => __( 'The whole cache will be automatically cleared according to the time period you specified.', 'wpgb-caching' ),
				'separator' => '',
				'fields'    => [
					[
						'id'      => 'caching_lifespan_interval',
						'type'    => 'number',
						'min'     => 1,
						'max'     => 9999,
						'width'   => 80,
						'default' => 1,
					],
					[
						'id'      => 'caching_lifespan_unit',
						'type'    => 'select',
						'default' => 'DAY',
						'options' => [
							'MINUTE' => __( 'Minutes', 'wpgb-caching' ),
							'HOUR'   => __( 'Hours', 'wpgb-caching' ),
							'DAY'    => __( 'Days', 'wpgb-caching' ),
						],
					],
				],
			],
			[
				'id'      => 'caching_clear',
				'type'    => 'custom',
				'label'   => __( 'Cache Content', 'wpgb-caching' ),
				'content' => sprintf(
					'<button type="button" class="wpgb-caching-clear wpgb-button wpgb-button-small wpgb-red" data-clear="%3$s" data-nonce="%1$s">%4$s</button>
					<p class="wpgb-caching-stats wpgb-field-desc" data-nonce="%2$s">%5$s</p>',
					wp_create_nonce( 'wpgb_caching_clear_cache' ),
					wp_create_nonce( 'wpgb_caching_cache_stats' ),
					esc_attr__( 'Processing...', 'wpgb-caching' ),
					esc_html__( 'Clear cache', 'wpgb-caching' ),
					wp_doing_ajax() ? esc_html__( 'Please refresh the page to get cache stats.', 'wpgb-caching' ) : esc_html__( 'Loading cache stats...', 'wpgb-caching' )
				),
			],
			[
				'id'      => 'caching_info',
				'type'    => 'info',
				'content' => __( '<strong>If you change cache lifespan</strong>, please clear the cache to prevent any issue.<br>Otherwise, several cache versions from different lifespans can be mixed.', 'wpgb-caching' ),
			],
		],
	],
];
