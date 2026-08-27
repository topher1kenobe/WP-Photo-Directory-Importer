<?php
/**
 * Settings page: choose whether imported photos get converted to a
 * different image format before they're added to the Media Library.
 *
 * @package Photo_Directory_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds Settings > Photo Directory, letting the site owner choose an
 * output format for imported photos when this server's image editor can
 * actually produce one. Deliberately gated on WebP support specifically:
 * if the server can't produce WebP, imported photos keep their original
 * format and no format picker is shown at all, since AVIF-only support
 * with no WebP is not a configuration this plugin tries to accommodate.
 */
class PDI_Settings {

	const OPTION_NAME         = 'pdi_image_format';
	const QUALITY_OPTION_NAME = 'pdi_image_quality';
	const PAGE_SLUG           = 'pdi-settings';
	const DEFAULT_QUALITY     = 82; // Matches core's own default JPEG compression quality.

	/**
	 * Registers the settings page under the Settings menu.
	 */
	public static function register_page() {
		add_options_page(
			__( 'Photo Directory', 'photo-directory-importer' ),
			__( 'Photo Directory', 'photo-directory-importer' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Registers both settings with the Settings API, so the page's <form>
	 * posts to options.php and gets WordPress's own nonce handling for free.
	 */
	public static function register_setting() {
		register_setting(
			'pdi_settings',
			self::OPTION_NAME,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_format' ),
				'default'           => self::default_format(),
			)
		);

		register_setting(
			'pdi_settings',
			self::QUALITY_OPTION_NAME,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_quality' ),
				'default'           => self::DEFAULT_QUALITY,
			)
		);
	}

	/**
	 * Formats this server's image editor can actually produce right now.
	 * 'original' (no conversion) is always the first entry. WebP and AVIF
	 * are added only when `wp_image_editor_supports()` confirms this
	 * server's registered image editor (GD or Imagick, whichever core
	 * picked) can encode that format — checking capability rather than
	 * assuming based on PHP version or extension presence alone.
	 *
	 * Returns just `array( 'original' => ... )` when WebP specifically
	 * isn't supported, which callers use as the signal to show a plain
	 * "not available" message instead of a picker.
	 *
	 * @return array Map of format value => label.
	 */
	public static function supported_formats() {
		$formats = array(
			'original' => __( 'Keep original format', 'photo-directory-importer' ),
		);

		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return $formats;
		}

		$formats['webp'] = __( 'Convert to WebP', 'photo-directory-importer' );

		if ( wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ) {
			$formats['avif'] = __( 'Convert to AVIF', 'photo-directory-importer' );
		}

		return $formats;
	}

	/**
	 * @return string 'webp' when this server can produce it, 'original' otherwise.
	 */
	public static function default_format() {
		$supported = self::supported_formats();
		return isset( $supported['webp'] ) ? 'webp' : 'original';
	}

	/**
	 * @param string $value Raw posted value.
	 * @return string A key guaranteed to exist in supported_formats().
	 */
	public static function sanitize_format( $value ) {
		$value     = sanitize_key( (string) $value );
		$supported = self::supported_formats();
		return isset( $supported[ $value ] ) ? $value : self::default_format();
	}

	/**
	 * The format to actually use at import time. Re-validates the stored
	 * option against live capability rather than trusting it outright, in
	 * case the environment changed (e.g. a staging clone without the same
	 * PHP image libraries) since the setting was last saved.
	 *
	 * @return string One of the keys returned by supported_formats().
	 */
	public static function get_format() {
		$stored    = get_option( self::OPTION_NAME, self::default_format() );
		$supported = self::supported_formats();
		return isset( $supported[ $stored ] ) ? $stored : self::default_format();
	}

	/**
	 * Clamps a posted quality value to the 1–100 range WP_Image_Editor
	 * expects, rather than rejecting an out-of-range value outright.
	 *
	 * @param mixed $value Raw posted value.
	 * @return int Integer between 1 and 100.
	 */
	public static function sanitize_quality( $value ) {
		$value = absint( $value );
		if ( $value < 1 ) {
			return 1;
		}
		if ( $value > 100 ) {
			return 100;
		}
		return $value;
	}

	/**
	 * The quality to use when converting to WebP or AVIF. Never consulted
	 * when the format is 'original', since nothing is re-encoded in that
	 * case — there's no "JPEG quality" setting here on purpose.
	 *
	 * @return int Integer between 1 and 100.
	 */
	public static function get_quality() {
		return self::sanitize_quality( get_option( self::QUALITY_OPTION_NAME, self::DEFAULT_QUALITY ) );
	}

	/**
	 * Renders the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$supported   = self::supported_formats();
		$can_convert = isset( $supported['webp'] );

		if ( $can_convert ) {
			wp_enqueue_script( 'pdi-settings' );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Photo Directory', 'photo-directory-importer' ); ?></h1>

			<?php if ( ! $can_convert ) : ?>
				<p>
					<?php esc_html_e( 'WebP conversion is not available on this server. Imported photos will keep their original format.', 'photo-directory-importer' ); ?>
				</p>
			<?php else : ?>
				<form action="options.php" method="post">
					<?php settings_fields( 'pdi_settings' ); ?>
					<h2><?php esc_html_e( 'Image format', 'photo-directory-importer' ); ?></h2>
					<p>
						<?php esc_html_e( 'Choose whether photos imported from the Photo Directory should be converted to a different format before they’re added to your Media Library.', 'photo-directory-importer' ); ?>
					</p>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Image format', 'photo-directory-importer' ); ?></legend>
						<?php foreach ( $supported as $value => $label ) : ?>
							<label style="display:block;margin-bottom:8px;">
								<input
									type="radio"
									class="pdi-format-radio"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
									<?php checked( self::get_format(), $value ); ?>
								/>
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>

					<div id="pdi-quality-row" style="margin-top:16px;<?php echo ( 'original' === self::get_format() ) ? ' display:none;' : ''; ?>">
						<h2><?php esc_html_e( 'Image quality', 'photo-directory-importer' ); ?></h2>
						<p>
							<label for="pdi-quality-input">
								<?php esc_html_e( 'Quality (1–100):', 'photo-directory-importer' ); ?>
							</label>
							<input
								type="number"
								id="pdi-quality-input"
								name="<?php echo esc_attr( self::QUALITY_OPTION_NAME ); ?>"
								value="<?php echo esc_attr( self::get_quality() ); ?>"
								min="1"
								max="100"
								step="1"
								class="small-text"
							/>
						</p>
						<p class="description">
							<?php esc_html_e( 'Higher keeps more detail but produces a larger file. Used only when converting to WebP or AVIF — the original format, if kept, is never re-encoded.', 'photo-directory-importer' ); ?>
						</p>
					</div>

					<?php submit_button(); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
