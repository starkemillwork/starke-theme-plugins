<?php
/**
 * Cron
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
 * Handle cron
 *
 * @class WP_Grid_Builder_Caching\Includes\Cron
 * @since 1.0.0
 */
final class Cron {

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function __construct() {

		add_action( 'init', [ $this, 'schedule_event' ] );
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_filter( 'cron_schedules', [ $this, 'schedule_cron' ] );

	}

	/**
	 * Schedule cron job
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function schedule_event() {

		// To bypass cron job if needed.
		$cron = apply_filters( 'wp_grid_builder_caching/cron', true );

		// Schedule cron job to cleanup expired cache entries.
		if ( $cron && ! wp_next_scheduled( 'wp_grid_builder_caching/cleanup_cache' ) ) {
			wp_schedule_event( time(), 'wpgb_caching_interval', 'wp_grid_builder_caching/cleanup_cache' );
		}
	}

	/**
	 * Schedule cron
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @param array $schedules An array of non-default cron schedules. Default empty.
	 * @return array
	 */
	public function schedule_cron( $schedules ) {

		$interval = apply_filters( 'wp_grid_builder_caching/cron_interval', 24 * HOUR_IN_SECONDS );

		$schedules['wpgb_caching_interval'] = [
			'interval' => $interval,
			/* translators: %d: time in day */
			'display'  => __( 'Every day', 'wpgb-caching' ),
		];

		return $schedules;

	}
}
