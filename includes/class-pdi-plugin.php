<?php
/**
 * Core plugin bootstrap class.
 *
 * @package Photo_Directory_Importer
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

		add_filter( 'all_plugins', array( $this, 'link_authors_to_profiles' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PDI_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );

		add_action( 'wp_ajax_pdi_search', array( 'PDI_API', 'ajax_search' ) );
		add_action( 'wp_ajax_pdi_terms', array( 'PDI_API', 'ajax_terms' ) );
		add_action( 'wp_ajax_pdi_import', array( 'PDI_Importer', 'ajax_import' ) );
	}

	/**
	 * The Plugins list table wraps the whole "Author" string in a single
	 * link to Author URI — there's no per-name linking in the standard
	 * plugin header format. Since every name in ours is already a
	 * wordpress.org username (matching readme.txt's Contributors field),
	 * this rewrites our own row's Author field into one link per name,
	 * each pointing at that person's own wordpress.org profile, instead of
	 * the whole list pointing at one shared URL.
	 *
	 * `get_plugins()` (which feeds this filter) reads Author/AuthorURI as
	 * plain, unlinked strings, so `$plugins[...]['Author']` here is still
	 * just the raw comma-separated header value — nothing to unwrap first.
	 *
	 * @param array $plugins Plugin data keyed by plugin file, as returned by get_plugins().
	 * @return array
	 */
	public function link_authors_to_profiles( $plugins ) {
		$basename = plugin_basename( PDI_PLUGIN_FILE );

		if ( empty( $plugins[ $basename ]['Author'] ) ) {
			return $plugins;
		}

		$usernames = array_filter( array_map( 'trim', explode( ',', $plugins[ $basename ]['Author'] ) ) );

		$links = array_map(
			function ( $username ) {
				return sprintf(
					'<a href="%s">%s</a>',
					esc_url( 'https://profiles.wordpress.org/' . rawurlencode( $username ) ),
					esc_html( $username )
				);
			},
			$usernames
		);

		$plugins[ $basename ]['Author']    = implode( ', ', $links );
		// Emptied so the list table doesn't also wrap these already-linked
		// names in one more outer <a> pointing at Author URI.
		$plugins[ $basename ]['AuthorURI'] = '';

		return $plugins;
	}

	/**
	 * Adds a "Settings" link to this plugin's row on the Plugins page,
	 * appearing first among the action links (so immediately next to
	 * "Deactivate").
	 *
	 * @param string[] $links Existing action links (Activate/Deactivate, Edit, etc.).
	 * @return string[]
	 */
	public function add_settings_link( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . PDI_Settings::PAGE_SLUG ) ),
				esc_html__( 'Settings', 'photo-directory-importer' )
			)
		);

		return $links;
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
			array( 'wp-element', 'wp-i18n' ),
			PDI_VERSION,
			true
		);
		wp_set_script_translations( 'pdi-photo-browser', 'photo-directory-importer', PDI_PLUGIN_DIR . 'languages' );

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
			array( 'media-views', 'wp-i18n' ),
			PDI_VERSION,
			true
		);
		wp_set_script_translations( 'pdi-media-modal', 'photo-directory-importer', PDI_PLUGIN_DIR . 'languages' );

		wp_localize_script( 'pdi-media-modal', 'PDI_Modal', $this->modal_settings() );

		wp_register_script( 'pdi-settings', PDI_PLUGIN_URL . 'assets/js/settings.js', array(), PDI_VERSION, true );

		wp_localize_script(
			'pdi-admin',
			'PDI_Settings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'pdi_nonce' ),
				'strings' => array(
					'search'        => __( 'Search photos…', 'photo-directory-importer' ),
					'import'        => __( 'Import', 'photo-directory-importer' ),
					'importing'     => __( 'Importing…', 'photo-directory-importer' ),
					'imported'      => __( 'Imported', 'photo-directory-importer' ),
					'selected'      => __( 'Selected', 'photo-directory-importer' ),
					'loadMore'      => __( 'Load more', 'photo-directory-importer' ),
					'noResults'     => __( 'No photos found.', 'photo-directory-importer' ),
					'error'         => __( 'Something went wrong. Please try again.', 'photo-directory-importer' ),
					'useFeatured'   => __( 'Use as featured image', 'photo-directory-importer' ),
					'viewInLibrary' => __( 'View in Media Library', 'photo-directory-importer' ),
					'close'         => __( 'Close', 'photo-directory-importer' ),
					'tabLabel'      => __( 'Photo Directory', 'photo-directory-importer' ),
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
					'label' => __( 'Full size (up to 2560px)', 'photo-directory-importer' ),
				),
				array(
					'value' => 'large',
					'label' => __( 'Large (1024px)', 'photo-directory-importer' ),
				),
				array(
					'value' => 'medium',
					'label' => __( 'Medium (600px)', 'photo-directory-importer' ),
				),
			),
			'strings'     => array(
				'title'               => __( 'Photo Directory', 'photo-directory-importer' ),
				'description'         => __( 'Free, CC0-licensed photos contributed to WordPress.org. Import any photo straight into your Media Library. No attribution required, credit optional.', 'photo-directory-importer' ),
				'importSettings'      => __( 'Import settings', 'photo-directory-importer' ),
				'searchLabel'         => __( 'Search photos', 'photo-directory-importer' ),
				'searchPlaceholder'   => __( 'Search photos by subject, place or tag', 'photo-directory-importer' ),
				'search'              => __( 'Search', 'photo-directory-importer' ),
				'allCategories'       => __( 'All categories', 'photo-directory-importer' ),
				'anyOrientation'      => __( 'Any orientation', 'photo-directory-importer' ),
				'colorLabel'          => __( 'Color', 'photo-directory-importer' ),
				'anyColor'            => __( 'Any color', 'photo-directory-importer' ),
				/* translators: %s: colour name, e.g. "Green" */
				'colorSwatch'         => __( 'Filter by %s', 'photo-directory-importer' ),
				'sortLabel'           => __( 'Sort results', 'photo-directory-importer' ),
				'sortRelevance'       => __( 'Most relevant', 'photo-directory-importer' ),
				'sortNewest'          => __( 'Newest', 'photo-directory-importer' ),
				/* translators: %s: formatted number of photos */
				'photoCount'          => __( '%s photos', 'photo-directory-importer' ),
				/* translators: 1: formatted number of photos, 2: search term */
				'photoCountFor'       => __( '%1$s photos for “%2$s”', 'photo-directory-importer' ),
				'filtersLabel'        => __( 'Filters', 'photo-directory-importer' ),
				/* translators: %s: name of the filter being removed */
				'removeFilter'        => __( 'Remove filter: %s', 'photo-directory-importer' ),
				'clearAll'            => __( 'Clear all', 'photo-directory-importer' ),

				'recentlyAdded'       => __( 'Recently added', 'photo-directory-importer' ),
				/* translators: %s: search term */
				'resultsFor'          => __( 'Results for “%s”', 'photo-directory-importer' ),
				'inLibrary'           => __( 'In library', 'photo-directory-importer' ),
				'import'              => __( 'Import', 'photo-directory-importer' ),
				'importing'           => __( 'Importing…', 'photo-directory-importer' ),
				'viewInLibrary'       => __( 'View in library', 'photo-directory-importer' ),
				'viewFull'            => __( 'View full', 'photo-directory-importer' ),
				'close'               => __( 'Close', 'photo-directory-importer' ),
				'loadMore'            => __( 'Load more photos', 'photo-directory-importer' ),
				'loading'             => __( 'Loading…', 'photo-directory-importer' ),
				/* translators: 1: number of photos shown so far, 2: total number available */
				'showingCount'        => __( 'Showing %1$s of %2$s', 'photo-directory-importer' ),

				'trayLabel'           => __( 'Selected photos', 'photo-directory-importer' ),
				/* translators: %s: photo title */
				'selectPhoto'         => __( 'Select %s', 'photo-directory-importer' ),
				/* translators: %s: photo title */
				'deselectPhoto'       => __( 'Deselect %s', 'photo-directory-importer' ),
				'clearSelection'      => __( 'Clear selection', 'photo-directory-importer' ),
				'hintSelect'          => __( 'Click a photo to select it, or import one on its own', 'photo-directory-importer' ),
				'importSize'          => __( 'Import size', 'photo-directory-importer' ),
				'addCredit'           => __( 'Add photographer credit to caption', 'photo-directory-importer' ),
				'editDetails'         => __( 'Edit alt text & captions', 'photo-directory-importer' ),
				'hideDetails'         => __( 'Hide details', 'photo-directory-importer' ),
				'fieldTitle'          => __( 'Title', 'photo-directory-importer' ),
				'fieldAlt'            => __( 'Alt text', 'photo-directory-importer' ),
				'fieldAltPlaceholder' => __( 'describe the photo', 'photo-directory-importer' ),
				'fieldCaption'        => __( 'Caption', 'photo-directory-importer' ),
				/* translators: 1: current photo number, 2: total photos, 3: photo title */
				'importProgress'      => __( 'Importing %1$s of %2$s: %3$s', 'photo-directory-importer' ),
				'cancel'              => __( 'Cancel', 'photo-directory-importer' ),
				'viewInMediaLibrary'  => __( 'View in Media Library', 'photo-directory-importer' ),
				'importedBody'        => __( 'Alt text and captions were saved with each file.', 'photo-directory-importer' ),
				/* translators: 1: number imported, 2: number that failed */
				'importedPartial'     => __( '%1$s imported, %2$s could not be imported.', 'photo-directory-importer' ),
				'importedPartialBody' => __( 'The photos that failed are still selected, so you can try them again.', 'photo-directory-importer' ),

				'importFailed'        => __( 'That photo could not be imported.', 'photo-directory-importer' ),
				'alreadyImported'     => __( 'That photo is already in your Media Library.', 'photo-directory-importer' ),
				'alreadyImportedBody' => __( 'It was imported earlier, so nothing was downloaded again.', 'photo-directory-importer' ),

				/* translators: %s: search term */
				'emptyTitle'          => __( 'No photos match “%s”', 'photo-directory-importer' ),
				'emptyTitleFiltered'  => __( 'No photos match these filters', 'photo-directory-importer' ),
				'emptyBody'           => __( 'Narrow searches often come back empty. Try a broader term, or drop a filter.', 'photo-directory-importer' ),
				'errorTitle'          => __( 'Couldn’t reach WordPress.org.', 'photo-directory-importer' ),
				'tryAgain'            => __( 'Try again', 'photo-directory-importer' ),
				'error'               => __( 'The Photo Directory API didn’t respond. Your Media Library is unaffected.', 'photo-directory-importer' ),
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
				'tabLabel'          => __( 'Photo Directory', 'photo-directory-importer' ),
				'searchPlaceholder' => __( 'Search photos', 'photo-directory-importer' ),
				'searchLabel'       => __( 'Search photos', 'photo-directory-importer' ),
				'allCategories'     => __( 'All categories', 'photo-directory-importer' ),
				'anyOrientation'    => __( 'Any orientation', 'photo-directory-importer' ),
				'sortLabel'         => __( 'Sort results', 'photo-directory-importer' ),
				'sortRelevance'     => __( 'Most relevant', 'photo-directory-importer' ),
				'sortNewest'        => __( 'Newest', 'photo-directory-importer' ),
				/* translators: %s: photo title */
				'selectPhoto'       => __( 'Select %s', 'photo-directory-importer' ),
				'inLibrary'         => __( 'In library', 'photo-directory-importer' ),
				'loading'           => __( 'Loading…', 'photo-directory-importer' ),
				'loadMore'          => __( 'Load more photos', 'photo-directory-importer' ),
				'noResults'         => __( 'No photos found. Try a broader term, or drop a filter.', 'photo-directory-importer' ),
				'error'             => __( 'The Photo Directory API didn’t respond. Your Media Library is unaffected.', 'photo-directory-importer' ),

				'detailsLabel'      => __( 'Photo details', 'photo-directory-importer' ),
				'detailsEmpty'      => __( 'Select a photo to see its details.', 'photo-directory-importer' ),
				/* translators: %s: photographer's display name */
				'byLine'            => __( 'By %s · CC0', 'photo-directory-importer' ),
				'fieldTitle'        => __( 'Title', 'photo-directory-importer' ),
				'fieldAlt'          => __( 'Alt text', 'photo-directory-importer' ),
				'fieldAltHint'      => __( 'describe the photo', 'photo-directory-importer' ),
				'fieldCaption'      => __( 'Caption', 'photo-directory-importer' ),
				'importSize'        => __( 'Import size', 'photo-directory-importer' ),

				'nothingSelected'   => __( 'No photos selected', 'photo-directory-importer' ),
				'importOnly'        => __( 'Import only', 'photo-directory-importer' ),
				'insertIntoPost'    => __( 'Insert into post', 'photo-directory-importer' ),
				/* translators: 1: current photo number, 2: total photos */
				'importingProgress' => __( 'Importing %1$s of %2$s…', 'photo-directory-importer' ),
				'importFailed'      => __( 'Some photos could not be imported.', 'photo-directory-importer' ),
				'viewFull'          => __( 'View full', 'photo-directory-importer' ),
				'close'             => __( 'Close', 'photo-directory-importer' ),
			),
		);
	}

	/**
	 * Adds the "Media > Photo Directory" admin page.
	 */
	public function register_admin_page() {
		add_media_page(
			__( 'Photo Directory', 'photo-directory-importer' ),
			__( 'Photo Directory', 'photo-directory-importer' ),
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
			esc_html__( 'Photo Directory', 'photo-directory-importer' )
		);
	}
}
