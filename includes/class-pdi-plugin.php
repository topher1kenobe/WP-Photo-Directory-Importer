<?php
/**
 * Core plugin bootstrap class.
 *
 * @package WP_Photo_Directory_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core plugin bootstrap. Kept as a single class so hook registration is easy
 * to audit in one place; the actual remote-API and import logic live in
 * class-pdi-api.php and class-pdi-importer.php.
 */
class PDI_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var PDI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and lazily create) the singleton instance.
	 *
	 * @return PDI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers all plugin hooks. Private: use instance() instead of `new`.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_admin_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_action( 'media_buttons', array( $this, 'media_button' ), 20 );

		add_action( 'wp_ajax_pdi_search', array( 'PDI_API', 'ajax_search' ) );
		add_action( 'wp_ajax_pdi_terms', array( 'PDI_API', 'ajax_terms' ) );
		add_action( 'wp_ajax_pdi_import', array( 'PDI_Importer', 'ajax_import' ) );
	}

	/**
	 * Register (but don't necessarily enqueue) scripts/styles so the same
	 * handles can be pulled in from several different admin contexts.
	 */
	public function register_assets() {
		wp_register_style( 'pdi-admin', PDI_PLUGIN_URL . 'assets/css/admin.css', array(), PDI_VERSION );

		wp_register_style(
			'pdi-photo-browser',
			PDI_PLUGIN_URL . 'assets/css/photo-browser.css',
			array( 'dashicons' ),
			PDI_VERSION
		);

		// Built against wp-element rather than a bundler: the plugin ships no
		// build step, and WordPress already serves React under this handle.
		wp_register_script(
			'pdi-photo-browser',
			PDI_PLUGIN_URL . 'assets/js/photo-browser.js',
			array( 'wp-element' ),
			PDI_VERSION,
			true
		);

		wp_localize_script( 'pdi-photo-browser', 'PDI_Browser', $this->browser_settings() );

		wp_register_script( 'pdi-admin', PDI_PLUGIN_URL . 'assets/js/admin.js', array(), PDI_VERSION, true );

		wp_register_script(
			'pdi-media-modal',
			PDI_PLUGIN_URL . 'assets/js/media-modal.js',
			array( 'pdi-admin', 'media-views' ),
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
					'selected'      => __( 'Selected', 'pdi' ),
					'loadMore'      => __( 'Load more', 'pdi' ),
					'noResults'     => __( 'No photos found.', 'pdi' ),
					'error'         => __( 'Something went wrong. Please try again.', 'pdi' ),
					'useFeatured'   => __( 'Use as featured image', 'pdi' ),
					'viewInLibrary' => __( 'View in Media Library', 'pdi' ),
					'close'         => __( 'Close', 'pdi' ),
					'tabLabel'      => __( 'Photo Directory', 'pdi' ),
				),
			)
		);
	}

	/**
	 * Data handed to the browse app. Strings are localized here rather than
	 * through wp-i18n so the plugin keeps working without shipping compiled
	 * translation files, matching how pdi-admin already does it.
	 *
	 * @return array Settings consumed by assets/js/photo-browser.js.
	 */
	private function browser_settings() {
		/**
		 * Filters the URL of the "Import settings" link in the page header.
		 * Empty by default, which hides the link, since the plugin has no
		 * settings panel yet.
		 *
		 * @param string $url Settings panel URL.
		 */
		$settings_url = apply_filters( 'pdi_settings_url', '' );

		return array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'pdi_nonce' ),
			'libraryUrl'  => admin_url( 'upload.php' ),
			'settingsUrl' => esc_url_raw( $settings_url ),
			'perPage'     => 20,
			'strings'     => array(
				'title'               => __( 'Photo Directory', 'pdi' ),
				'description'         => __( 'Free, CC0-licensed photos contributed to WordPress.org. Import any photo straight into your Media Library. No attribution required, credit optional.', 'pdi' ),
				'importSettings'      => __( 'Import settings', 'pdi' ),
				'searchLabel'         => __( 'Search photos', 'pdi' ),
				'searchPlaceholder'   => __( 'Search photos by subject, place or tag', 'pdi' ),
				'search'              => __( 'Search', 'pdi' ),
				'allCategories'       => __( 'All categories', 'pdi' ),
				'anyOrientation'      => __( 'Any orientation', 'pdi' ),
				'colorLabel'          => __( 'Color', 'pdi' ),
				'anyColor'            => __( 'Any color', 'pdi' ),
				/* translators: %s: colour name, e.g. "Green" */
				'colorSwatch'         => __( 'Filter by %s', 'pdi' ),
				'sortLabel'           => __( 'Sort results', 'pdi' ),
				'sortRelevance'       => __( 'Most relevant', 'pdi' ),
				'sortNewest'          => __( 'Newest', 'pdi' ),
				/* translators: %s: formatted number of photos */
				'photoCount'          => __( '%s photos', 'pdi' ),
				/* translators: 1: formatted number of photos, 2: search term */
				'photoCountFor'       => __( '%1$s photos for “%2$s”', 'pdi' ),
				'filtersLabel'        => __( 'Filters', 'pdi' ),
				/* translators: %s: name of the filter being removed */
				'removeFilter'        => __( 'Remove filter: %s', 'pdi' ),
				'clearAll'            => __( 'Clear all', 'pdi' ),

				'recentlyAdded'       => __( 'Recently added', 'pdi' ),
				/* translators: %s: search term */
				'resultsFor'          => __( 'Results for “%s”', 'pdi' ),
				'inLibrary'           => __( 'In library', 'pdi' ),
				'import'              => __( 'Import', 'pdi' ),
				'importing'           => __( 'Importing…', 'pdi' ),
				'viewInLibrary'       => __( 'View in library', 'pdi' ),
				'loadMore'            => __( 'Load more photos', 'pdi' ),
				'loading'             => __( 'Loading…', 'pdi' ),
				/* translators: 1: number of photos shown so far, 2: total number available */
				'showingCount'        => __( 'Showing %1$s of %2$s', 'pdi' ),

				/* translators: %s: number of photos imported */
				'importedCount'       => __( '%s photos imported.', 'pdi' ),
				'importFailed'        => __( 'That photo could not be imported.', 'pdi' ),
				'alreadyImported'     => __( 'That photo is already in your Media Library.', 'pdi' ),
				'alreadyImportedBody' => __( 'It was imported earlier, so nothing was downloaded again.', 'pdi' ),

				/* translators: %s: search term */
				'emptyTitle'          => __( 'No photos match “%s”', 'pdi' ),
				'emptyTitleFiltered'  => __( 'No photos match these filters', 'pdi' ),
				'emptyBody'           => __( 'Narrow searches often come back empty. Try a broader term, or drop a filter.', 'pdi' ),
				'errorTitle'          => __( 'Couldn’t reach WordPress.org.', 'pdi' ),
				'tryAgain'            => __( 'Try again', 'pdi' ),
				'error'               => __( 'The Photo Directory API didn’t respond. Your Media Library is unaffected.', 'pdi' ),
			),
		);
	}

	/**
	 * Adds the "Media > Photo Directory" admin page.
	 */
	public function register_admin_page() {
		add_media_page(
			__( 'Photo Directory', 'pdi' ),
			__( 'Photo Directory', 'pdi' ),
			'upload_files',
			'pdi-photo-directory',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Renders the "Media > Photo Directory" admin page. Everything inside
	 * .wrap is rendered by assets/js/photo-browser.js, including the page
	 * heading, so that the title, description and "Import settings" link
	 * share one layout container. No h1 is printed here on purpose: a second
	 * one would leave the screen with two competing top-level headings.
	 */
	public function render_admin_page() {
		wp_enqueue_style( 'pdi-photo-browser' );
		wp_enqueue_script( 'pdi-photo-browser' );
		?>
		<div class="wrap pdi-wrap">
			<div id="pdi-browser" class="pdi-browser"></div>
		</div>
		<?php
	}

	/**
	 * Load the picker UI on post edit screens (both classic and block editor
	 * land on post.php/post-new.php) so the "Photo Directory" button and the
	 * native media modal tab have their script available.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function maybe_enqueue_admin_assets( $hook ) {
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && current_user_can( 'upload_files' ) ) {
			wp_enqueue_style( 'pdi-admin' );
			wp_enqueue_script( 'pdi-admin' );
			wp_enqueue_media();
			wp_enqueue_script( 'pdi-media-modal' );
		}
	}

	/**
	 * Enqueues the native media modal tab in the block editor (its Featured
	 * Image / Image block pickers use the same underlying wp.media frame as
	 * the classic editor).
	 */
	public function enqueue_block_editor_assets() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		wp_enqueue_style( 'pdi-admin' );
		wp_enqueue_script( 'pdi-admin' );
		wp_enqueue_media();
		wp_enqueue_script( 'pdi-media-modal' );
	}

	/**
	 * Classic editor entry point: adds a button next to "Add Media".
	 *
	 * @param string $editor_id ID of the editor instance the button belongs to.
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
