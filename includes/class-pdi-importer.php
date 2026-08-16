<?php
/**
 * Handles downloading and sideloading photos into the Media Library.
 *
 * @package WP_Photo_Directory_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

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
	 * AJAX handler for `action=pdi_import`. Expects `photo_id` and
	 * optional `size` POST parameters; returns the resulting attachment.
	 */
	public static function ajax_import() {
		check_ajax_referer( 'pdi_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'pdi' ) ), 403 );
		}

		$photo_id = isset( $_POST['photo_id'] ) ? absint( $_POST['photo_id'] ) : 0;
		$size     = isset( $_POST['size'] ) ? sanitize_key( $_POST['size'] ) : 'full';

		if ( ! $photo_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing photo ID.', 'pdi' ) ) );
		}

		// If we've already imported this photo, just return the existing attachment
		// rather than downloading (and cluttering the library with) a duplicate.
		$existing = self::find_existing_attachment( $photo_id );
		if ( $existing ) {
			wp_send_json_success( self::attachment_response( $existing ) );
			return;
		}

		$photo = PDI_API::get_photo( $photo_id );
		if ( is_wp_error( $photo ) ) {
			wp_send_json_error( array( 'message' => $photo->get_error_message() ) );
		}

		$attachment_id = self::import_photo( $photo, $size );
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
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- exact-match lookup on a plugin-owned meta key, no indexed alternative available.
				'meta_key'    => self::META_SOURCE_ID,
				'meta_value'  => $photo_id,
			)
		);
		return $existing ? intval( $existing[0] ) : 0;
	}

	/**
	 * Downloads the chosen size of a normalized photo and sideloads it into
	 * the Media Library.
	 *
	 * @param array  $photo Normalized photo data from PDI_API::normalize_item().
	 * @param string $size  Preferred size key; falls back to 'full', then the largest available.
	 * @return int|WP_Error Attachment ID on success.
	 */
	public static function import_photo( $photo, $size = 'full' ) {
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

		$post_data = array(
			'post_title'   => $photo['title'],
			'post_content' => $photo['description'],
			'post_excerpt' => self::build_caption( $photo ),
		);

		$attachment_id = media_handle_sideload( $file_array, 0, $photo['title'], $post_data );

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			return $attachment_id;
		}

		if ( ! empty( $photo['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $photo['alt'] ) );
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
	 * Builds the attachment caption (post_excerpt): just the photographer
	 * credit line, when the upstream API exposes an author name. The
	 * photo's description text lives in post_content instead (the
	 * "Description" field), not here.
	 *
	 * @param array $photo Normalized photo data.
	 * @return string Caption text, or an empty string if no author is available.
	 */
	private static function build_caption( $photo ) {
		if ( empty( $photo['author'] ) ) {
			return '';
		}

		return sprintf(
			/* translators: %s: photographer's display name */
			__( 'Photo by %s, via the WordPress Photo Directory.', 'pdi' ),
			$photo['author']
		);
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
	 * @param int $attachment_id Local attachment ID.
	 * @return array {
	 *     @type int    $id       Attachment ID.
	 *     @type string $title    Attachment title.
	 *     @type string $thumbUrl Medium-size (or original) image URL.
	 *     @type string $editUrl  Admin edit-attachment URL.
	 * }
	 */
	private static function attachment_response( $attachment_id ) {
		$image = wp_get_attachment_image_src( $attachment_id, 'medium' );
		return array(
			'id'       => $attachment_id,
			'title'    => get_the_title( $attachment_id ),
			'thumbUrl' => $image ? $image[0] : wp_get_attachment_url( $attachment_id ),
			'editUrl'  => admin_url( 'post.php?post=' . $attachment_id . '&action=edit' ),
		);
	}
}
