<?php
/**
 * Caching controls
 *
 * @package   WP Grid Builder - Caching
 * @author    Loïc Blascos
 * @copyright 2019-2024 Loïc Blascos
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	[
		'type'        => 'fieldset',
		'panel'       => 'caching',
		'legend'      => __( 'Caching Rules', 'wpgb-caching' ),
		'description' => __( 'Include and exclude content from cache.', 'wpgb-caching' ),
		'fields'      => [
			'caching_exclude_grids'  => [
				'type'        => 'select',
				'label'       => __( 'Exclude Grids', 'wpgb-caching' ),
				'async'       => 'wpgb/v2/objects?object=grids&fields=id,name',
				'placeholder' => _x( 'None', 'Exclude Grids default value', 'wpgb-caching' ),
				'isCreatable' => true,
				'multiple'    => true,
			],
			'caching_exclude_facets' => [
				'type'        => 'select',
				'label'       => __( 'Exclude Facets', 'wpgb-caching' ),
				'async'       => 'wpgb/v2/objects?object=facets&fields=id,name',
				'placeholder' => _x( 'None', 'Exclude Facets default value', 'wpgb-caching' ),
				'multiple'    => true,
			],
		],
	],
	[
		'type'        => 'fieldset',
		'panel'       => 'caching',
		'legend'      => __( 'Behaviour', 'wpgb-caching' ),
		'description' => __( 'Manage caching duration and actions.', 'wpgb-caching' ),
		'fields'      => [
			'caching_duration'       => [
				'type'   => 'row',

				'fields' => [
					'caching_lifespan_interval' => [
						'type'  => 'number',
						'label' => __( 'Cache Lifespan', 'wpgb-caching' ),
						'min'   => 1,
						'max'   => 9999,
					],
					'caching_lifespan_unit'     => [
						'type'        => 'select',
						'label'       => __( 'Cache Lifespan Unit', 'wpgb-caching' ),
						'hideLabel'   => true,
						'isClearable' => false,
						'options'     => [
							[
								'value' => 'MINUTE',
								'label' => __( 'Minutes', 'wpgb-caching' ),
							],
							[
								'value' => 'HOUR',
								'label' => __( 'Hours', 'wpgb-caching' ),
							],
							[
								'value' => 'DAY',
								'label' => __( 'Days', 'wpgb-caching' ),
							],
						],
					],
				],
			],
			'caching_auto_purge'     => [
				'type'  => 'toggle',
				'label' => __( 'Automatic Purge', 'wpgb-caching' ),
				'help'  => __( 'Purge cache automatically when updating content.', 'wpgb-caching' ),
			],
			'caching_admin_bar_menu' => [
				'type'  => 'toggle',
				'label' => __( 'Admin Bar', 'wpgb-caching' ),
				'help'  => __( 'Display a quick-action menu in the admin bar.', 'wpgb-caching' ),
			],
			'caching_clear'          => [
				'type'    => 'custom',
				'content' => '',
			],
		],
	],
	[
		'type'    => 'tip',
		'panel'   => 'caching',
		'content' => __( '<strong>If you change cache lifespan</strong>, please clear the cache to prevent any issue. Otherwise, several cache versions from different lifespans can be mixed.', 'wpgb-caching' ),
	],
];
