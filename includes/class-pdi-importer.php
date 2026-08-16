<?php
/**
 * Handles downloading and sideloading photos into the Media Library.
 *
 * @package WP_Photo_Directory_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Downloads a chosen photo and sideloads it into the local Media Library as
 * a real attachment (so it behaves exactly like any uploaded image —
 * usable as a featured image, in blocks, etc.).
 */
class PDI_Importer {

	const META_SOURCE_ID     = '_pdi_source_id';
	const META_SOURCE_URL    = '_pdi_source_url';
	const META_SOURCE_AUTHOR = '_pdi_source_author';

	/**
	 * Sizes the UI is allowed to ask for, mapped to the upstream size key
	 * each one downloads. Anything else falls back to the original.
	 *
	 * @return array Map of requested size => upstream size key.
	 */
	public static function allowed_sizes() {
		return array(
			'full'   => 'full',
			'large'  => 'large',
			'medium' => 'medium',
		);
	}

	/**
	 * AJAX handler for `action=pdi_import`. Expects `photo_id`, plus
	 * optional `size`, `add_credit` and per-photo `title`, `alt` and
	 * `caption` overrides; returns the resulting attachment.
	 */
	public static function ajax_import() {
		check_ajax_referer( 'pdi_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'pdi' ) ), 403 );
		}

		$photo_id = isset( $_POST['photo_id'] ) ? absint( $_POST['photo_id'] ) : 0;

		$size    = isset( $_POST['size'] ) ? sanitize_key( wp_unslash( $_POST['size'] ) ) : 'full';
		$allowed = self::allowed_sizes();
		$size    = isset( $allowed[ $size ] ) ? $allowed[ $size ] : 'full';

		$args = array(
			// Absent means "yes": credit is on by default, and the UI only
			// sends the flag when the editor turns it off.
			'add_credit' => ! isset( $_POST['add_credit'] ) || '0' !== sanitize_key( wp_unslash( $_POST['add_credit'] ) ),
			'title'      => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : null,
			'alt'        => isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : null,
			'caption'    => isset( $_POST['caption'] ) ? sanitize_textarea_field( wp_unslash( $_POST['caption'] ) ) : null,
		);

		if ( ! $photo_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing photo ID.', 'pdi' ) ) );
		}

		// If we've already imported this photo, just return the existing attachment
		// rather than downloading (and cluttering the library with) a duplicate.
		$existing = self::find_existing_attachment( $photo_id );
		if ( $existing ) {
			wp_send_json_success( self::attachment_response( $existing, true ) );
			return;
		}

		$photo = PDI_API::get_photo( $photo_id );
		if ( is_wp_error( $photo ) ) {
			wp_send_json_error( array( 'message' => $photo->get_error_message() ) );
		}

		$attachment_id = self::import_photo( $photo, $size, $args );
		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
		}

		wp_send_json_success( self::attachment_response( $attachment_id ) );
	}

	/**
	 * Look up a local attachment previously imported from a given upstream photo.
	 *
	 * Queries on meta_key/meta_value, which phpcs flags as a potentially slow
	 * query since postmeta isn't indexed on those columns by default. There's
	 * no core-supported alternative for this lookup shape (a custom mapping
	 * table would be overkill for what is, at most, a few thousand rows per
	 * site), so the warning is intentionally suppressed here rather than
	 * "fixed" by switching to a less accurate lookup.
	 *
	 * @param int $photo_id Upstream Photo Directory ID.
	 * @return int Local attachment ID, or 0 if this photo hasn't been imported yet.
	 */
	public static function find_existing_attachment( $photo_id ) {
		$existing = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => 1,
				'fields'      => 'ids',
				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- exact-match lookup on a plugin-owned meta key, no indexed alternative available.
				'meta_key'    => self::META_SOURCE_ID,
				'meta_value'  => $photo_id,
				// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $existing ? intval( $existing[0] ) : 0;
	}

	/**
	 * Resolve a whole page of upstream photo IDs to the local attachments
	 * they were imported as, so the grid can mark them without asking one
	 * question per card.
	 *
	 * Runs two queries regardless of how many photos are passed in: one to
	 * find the matching attachments, one to prime their meta cache.
	 *
	 * @param int[] $photo_ids Upstream Photo Directory IDs.
	 * @return array Map of upstream photo ID => local attachment ID.
	 */
	public static function find_existing_attachments( $photo_ids ) {
		$photo_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $photo_ids ) ) ) );
		if ( empty( $photo_ids ) ) {
			return array();
		}

		$attachment_ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'numberposts'            => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- batched exact-match lookup on a plugin-owned meta key, deliberately replacing one query per card.
					array(
						'key'     => self::META_SOURCE_ID,
						'value'   => $photo_ids,
						'compare' => 'IN',
					),
				),
			)
		);

		if ( empty( $attachment_ids ) ) {
			return array();
		}

		update_meta_cache( 'post', $attachment_ids );

		$map = array();
		foreach ( $attachment_ids as $attachment_id ) {
			$photo_id = absint( get_post_meta( $attachment_id, self::META_SOURCE_ID, true ) );
			// Keep the earliest import when a photo somehow has more than one.
			if ( $photo_id && ! isset( $map[ $photo_id ] ) ) {
				$map[ $photo_id ] = intval( $attachment_id );
			}
		}

		return $map;
	}

	/**
	 * Downloads the chosen size of a normalized photo and sideloads it into
	 * the Media Library.
	 *
	 * @param array  $photo Normalized photo data from PDI_API::normalize_item().
	 * @param string $size  Preferred size key; falls back to 'full', then the largest available.
	 * @param array  $args  {
	 *     Optional. Import options supplied by the editor.
	 *
	 *     @type bool        $add_credit Whether to write the photographer credit as the
	 *                                   caption. Default true.
	 *     @type string|null $title      Editor-supplied title, overriding the photo's own.
	 *     @type string|null $alt        Editor-supplied alt text.
	 *     @type string|null $caption    Editor-supplied caption, overriding the credit line.
	 * }
	 * @return int|WP_Error Attachment ID on success.
	 */
	public static function import_photo( $photo, $size = 'full', $args = array() ) {
		// Loaded here rather than at file scope: these are wp-admin includes and
		// are only needed while actually importing, so front-end requests should
		// not pay for parsing them.
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$args = wp_parse_args(
			$args,
			array(
				'add_credit' => true,
				'title'      => null,
				'alt'        => null,
				'caption'    => null,
			)
		);

		if ( empty( $photo['sizes'] ) ) {
			return new WP_Error( 'pdi_no_image', __( 'No downloadable image was found for this photo.', 'pdi' ) );
		}

		if ( ! empty( $photo['sizes'][ $size ]['url'] ) ) {
			$source_url = $photo['sizes'][ $size ]['url'];
		} elseif ( ! empty( $photo['sizes']['full']['url'] ) ) {
			$source_url = $photo['sizes']['full']['url'];
		} else {
			$largest    = self::largest_size( $photo['sizes'] );
			$source_url = $largest['url'];
		}

		if ( empty( $source_url ) ) {
			return new WP_Error( 'pdi_no_image', __( 'No downloadable image was found for this photo.', 'pdi' ) );
		}

		$tmp_file = download_url( $source_url, 30 );
		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$file_array = array(
			'name'     => self::guess_filename( $source_url, $photo ),
			'tmp_name' => $tmp_file,
		);

		$title   = ( null !== $args['title'] && '' !== $args['title'] ) ? $args['title'] : $photo['title'];
		$alt     = ( null !== $args['alt'] ) ? $args['alt'] : $photo['alt'];
		$caption = $args['caption'];

		// An editor-supplied caption wins outright. Otherwise the credit line
		// is written only when crediting is switched on.
		if ( null === $caption ) {
			$caption = $args['add_credit'] ? self::build_credit_line( $photo ) : '';
		}

		$post_data = array(
			'post_title'   => $title,
			'post_content' => $photo['description'],
			'post_excerpt' => $caption,
		);

		$attachment_id = media_handle_sideload( $file_array, 0, $title, $post_data );

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			return $attachment_id;
		}

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		update_post_meta( $attachment_id, self::META_SOURCE_ID, $photo['id'] );
		update_post_meta( $attachment_id, self::META_SOURCE_URL, $photo['link'] );
		if ( ! empty( $photo['author'] ) ) {
			update_post_meta( $attachment_id, self::META_SOURCE_AUTHOR, $photo['author'] );
		}
		update_post_meta( $attachment_id, '_pdi_imported', 1 );

		return $attachment_id;
	}

	/**
	 * Builds the photographer credit line, when the upstream API exposes
	 * an author name. Used as the attachment's Caption field
	 * (post_excerpt); the photo's own description text goes in the
	 * Description field (post_content) — and, as alt text — instead.
	 *
	 * @param array $photo Normalized photo data.
	 * @return string Credit line, or an empty string if no author is available.
	 */
	private static function build_credit_line( $photo ) {
		if ( empty( $photo['author'] ) ) {
			return '';
		}

		$credit = sprintf(
			/* translators: %s: photographer's display name */
			__( 'Photo by %s, via the WordPress Photo Directory.', 'pdi' ),
			$photo['author']
		);

		/**
		 * Filters the photographer credit written into the attachment caption.
		 *
		 * @param string $credit Credit line.
		 * @param array  $photo  Normalized photo data.
		 */
		return apply_filters( 'pdi_credit_line', $credit, $photo );
	}

	/**
	 * Picks the size with the largest pixel area from a photo's size map.
	 *
	 * @param array $sizes Map of size name => [ url, width, height ].
	 * @return array The largest size entry, e.g. [ url, width, height ].
	 */
	private static function largest_size( $sizes ) {
		$best = null;
		foreach ( $sizes as $s ) {
			$area = intval( $s['width'] ) * intval( $s['height'] );
			if ( null === $best || $area > $best['_area'] ) {
				$s['_area'] = $area;
				$best       = $s;
			}
		}
		return $best ? $best : reset( $sizes );
	}

	/**
	 * Derives a sensible local filename for a downloaded photo, falling back
	 * to the photo's slug/ID if the source URL has no usable basename.
	 *
	 * @param string $url   Source image URL.
	 * @param array  $photo Normalized photo data.
	 * @return string Sanitized filename.
	 */
	private static function guess_filename( $url, $photo ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$base = $path ? basename( $path ) : '';
		if ( ! $base || false === strpos( $base, '.' ) ) {
			$slug = ! empty( $photo['slug'] ) ? $photo['slug'] : 'photo-' . $photo['id'];
			$base = sanitize_file_name( $slug ) . '.jpg';
		}
		return sanitize_file_name( $base );
	}

	/**
	 * Builds the small JSON-friendly attachment payload sent back to the browser.
	 *
	 * @param int  $attachment_id Local attachment ID.
	 * @param bool $already_in_library Whether this attachment was surfaced by the
	 *                                 duplicate guard rather than freshly imported.
	 * @return array {
	 *     @type int    $id       Attachment ID.
	 *     @type string $title    Attachment title.
	 *     @type string $thumbUrl   Medium-size (or original) image URL.
	 *     @type string $editUrl    Admin edit-attachment URL.
	 *     @type string $libraryUrl Media Library URL focused on this attachment.
	 * }
	 */
	private static function attachment_response( $attachment_id, $already_in_library = false ) {
		$image = wp_get_attachment_image_src( $attachment_id, 'medium' );
		return array(
			'id'               => $attachment_id,
			'title'            => get_the_title( $attachment_id ),
			'thumbUrl'         => $image ? $image[0] : wp_get_attachment_url( $attachment_id ),
			'editUrl'          => admin_url( 'post.php?post=' . $attachment_id . '&action=edit' ),
			'libraryUrl'       => admin_url( 'upload.php?item=' . $attachment_id ),
			'alreadyInLibrary' => (bool) $already_in_library,
		);
	}
}
