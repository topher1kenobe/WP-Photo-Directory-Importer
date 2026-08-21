<?php
/**
 * Plugin Name:       WP Photo Directory Importer
 * Plugin URI:        https://github.com/your-username/wp-photo-directory-importer
 * Description:       Search the WordPress Photo Directory (wordpress.org/photos) and import CC0 photos straight into your Media Library — usable as featured images or anywhere else.
 * Version:           1.3.11
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            ekamran, veeeharris, mattgaldino, telizarose, topher1kenobe, gusteci, michelleames
 * Author URI:        https://github.com/your-username
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pdi
 *
 * @package WP_Photo_Directory_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PDI_VERSION', '1.3.11' );
define( 'PDI_PLUGIN_FILE', __FILE__ );
define( 'PDI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PDI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PDI_PLUGIN_DIR . 'includes/class-pdi-api.php';
require_once PDI_PLUGIN_DIR . 'includes/class-pdi-importer.php';
require_once PDI_PLUGIN_DIR . 'includes/class-pdi-settings.php';
require_once PDI_PLUGIN_DIR . 'includes/class-pdi-plugin.php';

add_action( 'plugins_loaded', array( 'PDI_Plugin', 'instance' ) );
