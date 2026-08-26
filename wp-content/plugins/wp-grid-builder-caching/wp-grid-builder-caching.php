<?php
/**
 * WP Grid Builder Caching Add-on
 *
 * @package   WP Grid Builder - Caching
 * @author    Loïc Blascos
 * @link      https://www.wpgridbuilder.com
 * @copyright 2019-2024 Loïc Blascos
 *
 * @wordpress-plugin
 * Plugin Name:  WP Grid Builder - Caching
 * Plugin URI:   https://www.wpgridbuilder.com
 * Description:  Speed up loading time when filtering grids by caching content and facets.
 * Version:      1.2.1
 * Author:       Loïc Blascos
 * Author URI:   https://www.wpgridbuilder.com
 * License:      GPL-3.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:  wpgb-caching
 * Domain Path:  /languages
 */

namespace WP_Grid_Builder_Caching;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPGB_CACHING_VERSION', '1.2.1' );
define( 'WPGB_CACHING_FILE', __FILE__ );
define( 'WPGB_CACHING_BASE', plugin_basename( WPGB_CACHING_FILE ) );
define( 'WPGB_CACHING_PATH', plugin_dir_path( WPGB_CACHING_FILE ) );
define( 'WPGB_CACHING_URL', plugin_dir_url( WPGB_CACHING_FILE ) );

require_once WPGB_CACHING_PATH . 'includes/class-autoload.php';
require_once WPGB_CACHING_PATH . 'includes/class-wp-cli.php';

new Includes\Plugin();
