<?php
/**
 * Plugin Name:       PhotoFetch
 * Plugin URI:        https://github.com/your-username/photofetch
 * Description:       Search the WordPress Photo Directory (wordpress.org/photos) and import CC0 photos straight into your Media Library — usable as featured images or anywhere else.
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            ekamran, veeeharris, mattgaldino, telizarose, topher1kenobe, gusteci, michelleames
 * Author URI:        https://github.com/your-username
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       photofetch
 * Domain Path:       /languages
 *
 * @package PhotoFetch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PHOTOFETCH_VERSION', '2.0.0' );
define( 'PHOTOFETCH_PLUGIN_FILE', __FILE__ );
define( 'PHOTOFETCH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PHOTOFETCH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PHOTOFETCH_PLUGIN_DIR . 'includes/class-photofetch-api.php';
require_once PHOTOFETCH_PLUGIN_DIR . 'includes/class-photofetch-importer.php';
require_once PHOTOFETCH_PLUGIN_DIR . 'includes/class-photofetch-settings.php';
require_once PHOTOFETCH_PLUGIN_DIR . 'includes/class-photofetch-plugin.php';

add_action( 'plugins_loaded', array( 'PhotoFetch_Plugin', 'instance' ) );
