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

		add_action( 'admin_menu', array( 'PDI_Settings', 'register_page' ) );
		add_action( 'admin_init', array( 'PDI_Settings', 'register_setting' ) );

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

		wp_register_style(
			'pdi-media-tab',
			PDI_PLUGIN_URL . 'assets/css/media-tab.css',
			array( 'dashicons' ),
			PDI_VERSION
		);

		wp_register_script(
			'pdi-media-modal',
			PDI_PLUGIN_URL . 'assets/js/media-modal.js',
			array( 'media-views' ),
			PDI_VERSION,
			true
		);

		wp_localize_script( 'pdi-media-modal', 'PDI_Modal', $this->modal_settings() );

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
		 * Points at Settings > Photo Directory by default.
		 *
		 * @param string $url Settings panel URL.
		 */
		$settings_url = apply_filters(
			'pdi_settings_url',
			admin_url( 'options-general.php?page=' . PDI_Settings::PAGE_SLUG )
		);

		return array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'pdi_nonce' ),
			'libraryUrl'  => admin_url( 'upload.php' ),
			'settingsUrl' => esc_url_raw( $settings_url ),
			'perPage'     => 20,
			'sizes'       => array(
				array(
					'value' => 'full',
					'label' => __( 'Full size (up to 2560px)', 'pdi' ),
				),
				array(
					'value' => 'large',
					'label' => __( 'Large (1024px)', 'pdi' ),
				),
				array(
					'value' => 'medium',
					'label' => __( 'Medium (600px)', 'pdi' ),
				),
			),
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
				'viewFull'            => __( 'View full', 'pdi' ),
				'close'               => __( 'Close', 'pdi' ),
				'loadMore'            => __( 'Load more photos', 'pdi' ),
				'loading'             => __( 'Loading…', 'pdi' ),
				/* translators: 1: number of photos shown so far, 2: total number available */
				'showingCount'        => __( 'Showing %1$s of %2$s', 'pdi' ),

				'trayLabel'           => __( 'Selected photos', 'pdi' ),
				/* translators: %s: photo title */
				'selectPhoto'         => __( 'Select %s', 'pdi' ),
				/* translators: %s: photo title */
				'deselectPhoto'       => __( 'Deselect %s', 'pdi' ),
				/* translators: %s: number of photos selected */
				'selectedCount'       => __( '%s selected', 'pdi' ),
				'clearSelection'      => __( 'Clear selection', 'pdi' ),
				'hintSelect'          => __( 'Click a photo to select it, or import one on its own', 'pdi' ),
				/* translators: %s: number of photos selected */
				'hintSelected'        => __( '%s selected. Click a photo to add or remove it.', 'pdi' ),
				'importSize'          => __( 'Import size', 'pdi' ),
				'addCredit'           => __( 'Add photographer credit to caption', 'pdi' ),
				'editDetails'         => __( 'Edit alt text & captions', 'pdi' ),
				'hideDetails'         => __( 'Hide details', 'pdi' ),
				'importOne'           => __( 'Import 1 photo', 'pdi' ),
				/* translators: %s: number of photos to import */
				'importCount'         => __( 'Import %s photos', 'pdi' ),
				'fieldTitle'          => __( 'Title', 'pdi' ),
				'fieldAlt'            => __( 'Alt text', 'pdi' ),
				'fieldAltPlaceholder' => __( 'describe the photo', 'pdi' ),
				'fieldCaption'        => __( 'Caption', 'pdi' ),
				/* translators: 1: current photo number, 2: total photos, 3: photo title */
				'importProgress'      => __( 'Importing %1$s of %2$s: %3$s', 'pdi' ),
				'cancel'              => __( 'Cancel', 'pdi' ),
				'viewInMediaLibrary'  => __( 'View in Media Library', 'pdi' ),
				'importedOne'         => __( '1 photo imported.', 'pdi' ),
				'importedBody'        => __( 'Alt text and captions were saved with each file.', 'pdi' ),
				/* translators: 1: number imported, 2: number that failed */
				'importedPartial'     => __( '%1$s imported, %2$s could not be imported.', 'pdi' ),
				'importedPartialBody' => __( 'The photos that failed are still selected, so you can try them again.', 'pdi' ),

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
	 * Data handed to the "Photo Directory" tab inside the wp.media modal.
	 * Shares the AJAX endpoints and import sizes with the browse screen but
	 * carries its own strings, since the tab talks about inserting into a
	 * post rather than about the Media Library on its own.
	 *
	 * @return array Settings consumed by assets/js/media-modal.js.
	 */
	private function modal_settings() {
		$browser = $this->browser_settings();

		return array(
			'ajaxUrl' => $browser['ajaxUrl'],
			'nonce'   => $browser['nonce'],
			'sizes'   => $browser['sizes'],
			'strings' => array(
				'tabLabel'          => __( 'Photo Directory', 'pdi' ),
				'searchPlaceholder' => __( 'Search photos', 'pdi' ),
				'searchLabel'       => __( 'Search photos', 'pdi' ),
				'allCategories'     => __( 'All categories', 'pdi' ),
				'anyOrientation'    => __( 'Any orientation', 'pdi' ),
				'sortLabel'         => __( 'Sort results', 'pdi' ),
				'sortRelevance'     => __( 'Most relevant', 'pdi' ),
				'sortNewest'        => __( 'Newest', 'pdi' ),
				/* translators: %s: photo title */
				'selectPhoto'       => __( 'Select %s', 'pdi' ),
				'inLibrary'         => __( 'In library', 'pdi' ),
				'loading'           => __( 'Loading…', 'pdi' ),
				'loadMore'          => __( 'Load more photos', 'pdi' ),
				'noResults'         => __( 'No photos found. Try a broader term, or drop a filter.', 'pdi' ),
				'error'             => __( 'The Photo Directory API didn’t respond. Your Media Library is unaffected.', 'pdi' ),

				'detailsLabel'      => __( 'Photo details', 'pdi' ),
				'detailsEmpty'      => __( 'Select a photo to see its details.', 'pdi' ),
				/* translators: %s: photographer's display name */
				'byLine'            => __( 'By %s · CC0', 'pdi' ),
				'fieldTitle'        => __( 'Title', 'pdi' ),
				'fieldAlt'          => __( 'Alt text', 'pdi' ),
				'fieldAltHint'      => __( 'describe the photo', 'pdi' ),
				'fieldCaption'      => __( 'Caption', 'pdi' ),
				'importSize'        => __( 'Import size', 'pdi' ),

				'nothingSelected'   => __( 'No photos selected', 'pdi' ),
				/* translators: %s: number of photos selected */
				'selectedStatus'    => __( '%s selected · imported to your Media Library on insert', 'pdi' ),
				/* translators: %s: number of photos selected */
				'selectedStatusOne' => __( '1 selected · imported to your Media Library on insert', 'pdi' ),
				'importOnly'        => __( 'Import only', 'pdi' ),
				'insertIntoPost'    => __( 'Insert into post', 'pdi' ),
				/* translators: 1: current photo number, 2: total photos */
				'importingProgress' => __( 'Importing %1$s of %2$s…', 'pdi' ),
				'importFailed'      => __( 'Some photos could not be imported.', 'pdi' ),
				'viewFull'          => __( 'View full', 'pdi' ),
				'close'             => __( 'Close', 'pdi' ),
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
			wp_enqueue_style( 'pdi-media-tab' );
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
		wp_enqueue_style( 'pdi-media-tab' );
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
