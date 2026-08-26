<?php
/**
 * WP-CLI
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
 * Handle cache with WP CLI
 *
 * @class WP_Grid_Builder_Caching\Includes\WP_CLI
 * @since 1.0.0
 */
class WP_CLI extends \WP_CLI_Command {

	/**
	 * Clear cache
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * ## EXAMPLES
	 *
	 *     # Clear whole cache.
	 *     $ wp wpgb-caching clear
	 *     Success: Cache cleared!.
	 *
	 *     # Clear grid cache.
	 *     $ wp wpgb-caching clear 1234
	 *     Success: Cache cleared fro grid 1234.
	 *
	 * @subcommand clear-cache
	 * @alias clear
	 *
	 * @param array $args Holds command args.
	 * @param array $assoc_args Holds command assoc_args.
	 */
	public function clear_cache( $args = [], $assoc_args = [] ) {

		do_action( 'wp_grid_builder_caching/clear_cache', $args );

		if ( empty( $args ) ) {
			\WP_CLI::success( 'Cache cleared!' );
		} else {

			array_map(
				function( $arg ) {
					\WP_CLI::success( sprintf( 'Cache cleared for %s!', $arg ) );
				},
				$args
			);

		}
	}

	/**
	 * Cleanup cache
	 *
	 * @since 1.0.0
	 * @access protected
	 *
	 * ## EXAMPLES
	 *
	 *     # Cleanup cache.
	 *     $ wp wpgb-caching cleanup
	 *     Success: Cache cleaned up!.
	 *
	 * @subcommand cleanup-cache
	 * @alias cleanup
	 *
	 * @param array $args Holds command args.
	 * @param array $assoc_args Holds command assoc_args.
	 */
	public function cleanup_cache( $args = [], $assoc_args = [] ) {

		do_action( 'wp_grid_builder_caching/cleanup_cache' );

		\WP_CLI::success( 'Cache cleaned up!' );

	}
}

\WP_CLI::add_command(
	'wpgb-caching',
	__NAMESPACE__ . '\WP_CLI',
	[
		'shortdesc' => 'WP Grid Builder - Caching Add-on',
		'longdesc'  =>
			'## EXAMPLES' . "\n\n" .
			'# Clear whole cache' . "\n" .
			'$ wp wpgb-caching clear' . "\n" .
			'Success: Cache cleared!.' . "\n\n" .
			'# Cleanup cache' . "\n" .
			'$ wp wpgb-caching cleanup' . "\n" .
			'Success: Cache cleaned up!.',
	]
);
