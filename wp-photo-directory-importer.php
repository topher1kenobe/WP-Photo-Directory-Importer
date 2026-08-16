<?php
/**
 * Plugin Name:       WP Photo Directory Importer
 * Plugin URI:        https://github.com/your-username/wp-photo-directory-importer
 * Description:       Search the WordPress Photo Directory (wordpress.org/photos) and import CC0 photos straight into your Media Library — usable as featured images or anywhere else.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Your Name
 * Author URI:        https://github.com/your-username
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pdi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PDI_VERSION', '1.0.0' );
define( 'PDI_PLUGIN_FILE', __FILE__ );
define( 'PDI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PDI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PDI_PLUGIN_DIR . 'includes/class-pdi-api.php';
require_once PDI_PLUGIN_DIR . 'includes/class-pdi-importer.php';

/**
 * Core plugin bootstrap. Kept as a single class so hook registration is easy
 * to audit in one place; the actual remote-API and import logic live in
 * includes/class-pdi-api.php and includes/class-pdi-importer.php.
 */
class PDI_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_admin_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_action( 'media_buttons', array( $this, 'media_button' ), 20 );

		add_action( 'wp_ajax_pdi_search', array( 'PDI_API', 'ajax_search' ) );
		add_action( 'wp_ajax_pdi_import', array( 'PDI_Importer', 'ajax_import' ) );
	}

	/**
	 * Register (but don't necessarily enqueue) scripts/styles so the same
	 * handles can be pulled in from several different admin contexts.
	 */
	public function register_assets() {
		wp_register_style( 'pdi-admin', PDI_PLUGIN_URL . 'assets/css/admin.css', array(), PDI_VERSION );

		wp_register_script( 'pdi-admin', PDI_PLUGIN_URL . 'assets/js/admin.js', array(), PDI_VERSION, true );

		wp_register_script(
			'pdi-block-editor',
			PDI_PLUGIN_URL . 'assets/js/block-editor.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'pdi-admin' ),
			PDI_VERSION,
			true
		);

		wp_localize_script(
			'pdi-admin',
			'PDI_Settings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'pdi_nonce' ),
				'strings' => array(
					'search'        => __( 'Search photos…', 'pdi' ),
					'import'        => __( 'Import', 'pdi' ),
					'importing'     => __( 'Importing…', 'pdi' ),
					'imported'      => __( 'Imported', 'pdi' ),
					'loadMore'      => __( 'Load more', 'pdi' ),
					'noResults'     => __( 'No photos found.', 'pdi' ),
					'error'         => __( 'Something went wrong. Please try again.', 'pdi' ),
					'useFeatured'   => __( 'Use as featured image', 'pdi' ),
					'viewInLibrary' => __( 'View in Media Library', 'pdi' ),
					'close'         => __( 'Close', 'pdi' ),
				),
			)
		);
	}

	public function register_admin_page() {
		add_media_page(
			__( 'Photo Directory', 'pdi' ),
			__( 'Photo Directory', 'pdi' ),
			'upload_files',
			'pdi-photo-directory',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
		wp_enqueue_style( 'pdi-admin' );
		wp_enqueue_script( 'pdi-admin' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WordPress Photo Directory', 'pdi' ); ?></h1>
			<p>
				<?php esc_html_e( 'Search the free, CC0-licensed WordPress Photo Directory and import photos directly into your Media Library.', 'pdi' ); ?>
			</p>
			<div id="pdi-app" class="pdi-app" data-context="page"></div>
		</div>
		<?php
	}

	/**
	 * Load the picker UI on post edit screens (both classic and block editor
	 * land on post.php/post-new.php) so the "Photo Directory" button and the
	 * Gutenberg sidebar panel both have their script available.
	 */
	public function maybe_enqueue_admin_assets( $hook ) {
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && current_user_can( 'upload_files' ) ) {
			wp_enqueue_style( 'pdi-admin' );
			wp_enqueue_script( 'pdi-admin' );
		}
	}

	public function enqueue_block_editor_assets() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		wp_enqueue_style( 'pdi-admin' );
		wp_enqueue_script( 'pdi-admin' );
		wp_enqueue_script( 'pdi-block-editor' );
	}

	/**
	 * Classic editor entry point: adds a button next to "Add Media".
	 */
	public function media_button( $editor_id ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		printf(
			' <button type="button" class="button pdi-open-modal" data-editor="%s"><span class="dashicons dashicons-camera" style="vertical-align:text-bottom;"></span> %s</button>',
			esc_attr( $editor_id ),
			esc_html__( 'Photo Directory', 'pdi' )
		);
	}
}

add_action( 'plugins_loaded', array( 'PDI_Plugin', 'instance' ) );
