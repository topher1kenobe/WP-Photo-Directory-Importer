<?php
/**
 * Photo Directory REST API client.
 *
 * @package WP_Photo_Directory_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to the public WordPress Photo Directory REST API and normalizes its
 * responses into a predictable shape for our JS and importer to consume.
 *
 * NOTE: this is an unofficial integration against a third-party (if
 * WordPress-run) public API — its exact JSON shape isn't formally
 * documented. normalize_item() below deliberately checks several possible
 * locations for image size data so the plugin keeps working even if the
 * "primary" shape we expect turns out to be wrong. If imports start failing
 * or thumbnails don't load, this is the file to inspect first — dump the
 * raw response from self::search() to see what the API is actually
 * returning on your install.
 */
class PDI_API {

	const REMOTE_BASE = 'https://wordpress.org/photos/wp-json/wp/v2/photos';
	const CACHE_TTL   = 300; // 5 minutes.

	/**
	 * AJAX handler for `action=pdi_search`. Expects `search` and `page`
	 * POST parameters; returns a normalized list of photos as JSON.
	 */
	public static function ajax_search() {
		check_ajax_referer( 'pdi_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'pdi' ) ), 403 );
		}

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$page   = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;

		$result = self::search( $search, $page, 20 );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Search the Photo Directory, with a short-lived transient cache to
	 * avoid re-hitting the upstream API on repeat searches.
	 *
	 * @param string $search   Search term. Empty string returns latest photos.
	 * @param int    $page     1-indexed page number.
	 * @param int    $per_page Results per page.
	 * @return array|WP_Error {
	 *     @type array $photos     Normalized photo objects, see normalize_item().
	 *     @type int   $page       Current page.
	 *     @type int   $totalPages Total pages available upstream.
	 *     @type int   $total      Total item count upstream.
	 * }
	 */
	public static function search( $search = '', $page = 1, $per_page = 20 ) {
		$cache_key = 'pdi_search_' . md5( $search . '|' . $page . '|' . $per_page );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$args = array(
			'page'     => $page,
			'per_page' => $per_page,
			'_embed'   => 1,
		);
		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		$url = add_query_arg( $args, self::REMOTE_BASE );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'pdi_remote_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'The Photo Directory returned an error (HTTP %d).', 'pdi' ), $code )
			);
		}

		$items = json_decode( $body, true );
		if ( ! is_array( $items ) ) {
			return new WP_Error( 'pdi_bad_response', __( 'The Photo Directory returned an unexpected response.', 'pdi' ) );
		}

		$photos = array();
		foreach ( $items as $item ) {
			$photos[] = self::normalize_item( $item );
		}

		$result = array(
			'photos'     => $photos,
			'page'       => $page,
			'totalPages' => intval( wp_remote_retrieve_header( $response, 'x-wp-totalpages' ) ),
			'total'      => intval( wp_remote_retrieve_header( $response, 'x-wp-total' ) ),
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Fetch a single photo directly (used at import time so we always
	 * import against fresh, complete data rather than a cached search hit).
	 *
	 * @param int $id Upstream Photo Directory ID.
	 * @return array|WP_Error Normalized photo data, see normalize_item().
	 */
	public static function get_photo( $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return new WP_Error( 'pdi_bad_id', __( 'Invalid photo ID.', 'pdi' ) );
		}

		$url = add_query_arg( array( '_embed' => 1 ), self::REMOTE_BASE . '/' . $id );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'pdi_remote_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'The Photo Directory returned an error (HTTP %d).', 'pdi' ), $code )
			);
		}

		$item = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $item ) ) {
			return new WP_Error( 'pdi_bad_response', __( 'The Photo Directory returned an unexpected response.', 'pdi' ) );
		}

		return self::normalize_item( $item );
	}

	/**
	 * Normalize one raw Photo Directory API item into a predictable shape.
	 *
	 * @param array $item Raw item from the upstream `/wp/v2/photos` response.
	 * @return array {
	 *     @type int    $id          Upstream photo ID.
	 *     @type string $title       Plain-text title.
	 *     @type string $description Plain-text description/caption.
	 *     @type string $link        Permalink on wordpress.org/photos.
	 *     @type string $slug        Upstream slug.
	 *     @type string $author      Uploader display name, if available.
	 *     @type string $alt         Alt text. Prefers a real alt-text field if the API exposes
	 *                               one; otherwise falls back to the same text as $description.
	 *     @type string $thumbUrl    Best-guess thumbnail URL for grid display.
	 *     @type array  $sizes       Map of size name => [ url, width, height ].
	 * }
	 */
	public static function normalize_item( $item ) {
		$id    = isset( $item['id'] ) ? intval( $item['id'] ) : 0;
		$title = isset( $item['title']['rendered'] ) ? trim( wp_strip_all_tags( $item['title']['rendered'] ) ) : '';
		$link  = isset( $item['link'] ) ? esc_url_raw( $item['link'] ) : '';
		$slug  = isset( $item['slug'] ) ? $item['slug'] : '';

		// The upstream Photo Directory substitutes a generic placeholder
		// title (e.g. "Photo Detail") for photos the uploader never titled.
		// Treat that the same as an empty title rather than importing it
		// as a real title for every untitled photo.
		if ( '' !== $title && in_array( strtolower( $title ), self::generic_title_placeholders(), true ) ) {
			$title = '';
		}

		$description = '';
		if ( ! empty( $item['content']['rendered'] ) ) {
			$description = wp_strip_all_tags( $item['content']['rendered'] );
		} elseif ( ! empty( $item['excerpt']['rendered'] ) ) {
			$description = wp_strip_all_tags( $item['excerpt']['rendered'] );
		}

		$sizes = array();
		$alt   = '';

		// 1) Sizes living directly on the item (mirrors the core /wp/v2/media shape).
		if ( ! empty( $item['media_details']['sizes'] ) && is_array( $item['media_details']['sizes'] ) ) {
			$sizes = self::extract_sizes( $item['media_details']['sizes'] );
		}

		// 2) Sizes on the embedded featured media object (?_embed).
		if ( empty( $sizes ) && ! empty( $item['_embedded']['wp:featuredmedia'][0] ) ) {
			$media = $item['_embedded']['wp:featuredmedia'][0];

			if ( ! empty( $media['media_details']['sizes'] ) ) {
				$sizes = self::extract_sizes( $media['media_details']['sizes'] );
			}
			if ( empty( $alt ) && ! empty( $media['alt_text'] ) ) {
				$alt = $media['alt_text'];
			}
			if ( empty( $sizes ) && ! empty( $media['source_url'] ) ) {
				$sizes['full'] = array(
					'url'    => $media['source_url'],
					'width'  => isset( $media['media_details']['width'] ) ? intval( $media['media_details']['width'] ) : 0,
					'height' => isset( $media['media_details']['height'] ) ? intval( $media['media_details']['height'] ) : 0,
				);
			}
		}

		// 3) A custom top-level "sizes" field.
		if ( empty( $sizes ) && ! empty( $item['sizes'] ) && is_array( $item['sizes'] ) ) {
			$sizes = self::extract_sizes( $item['sizes'] );
		}

		// 4) Last resort: a single source_url on the item itself.
		if ( empty( $sizes ) && ! empty( $item['source_url'] ) ) {
			$sizes['full'] = array(
				'url'    => $item['source_url'],
				'width'  => 0,
				'height' => 0,
			);
		}

		// Alt text fallbacks beyond the embedded featured-media object above.
		if ( empty( $alt ) && ! empty( $item['alt_text'] ) ) {
			$alt = $item['alt_text'];
		}
		if ( empty( $alt ) && ! empty( $item['meta']['alt_text'] ) ) {
			$alt = $item['meta']['alt_text'];
		}
		if ( empty( $alt ) && ! empty( $item['meta']['_wp_attachment_image_alt'] ) ) {
			$alt = $item['meta']['_wp_attachment_image_alt'];
		}
		$alt = is_string( $alt ) ? trim( wp_strip_all_tags( $alt ) ) : '';

		// The Photo Directory doesn't appear to expose a dedicated alt-text
		// field at all. Fall back to the same description text used for
		// the attachment's Description field, since that's the only
		// reliably-populated text this API offers.
		if ( empty( $alt ) && ! empty( $description ) ) {
			$alt = $description;
		}

		$author = '';
		if ( ! empty( $item['_embedded']['author'][0]['name'] ) ) {
			$author = $item['_embedded']['author'][0]['name'];
		}

		$thumb = '';
		foreach ( array( 'medium', 'thumbnail', 'medium_large' ) as $want ) {
			if ( ! empty( $sizes[ $want ]['url'] ) ) {
				$thumb = $sizes[ $want ]['url'];
				break;
			}
		}
		if ( ! $thumb && ! empty( $sizes ) ) {
			$first = reset( $sizes );
			$thumb = $first['url'];
		}

		if ( '' === $title && $slug ) {
			$title = self::humanize_slug( $slug );
		}

		return array(
			'id'          => $id,
			'title'       => $title ? $title : __( 'Untitled photo', 'pdi' ),
			'description' => $description,
			'link'        => $link,
			'slug'        => $slug,
			'author'      => $author,
			'alt'         => $alt,
			'thumbUrl'    => $thumb,
			'sizes'       => $sizes,
		);
	}

	/**
	 * Title strings the upstream Photo Directory uses as a generic
	 * placeholder when a photo has no real title of its own. Matched
	 * case-insensitively; these are treated the same as an empty title.
	 *
	 * @return string[] Lowercased placeholder strings.
	 */
	private static function generic_title_placeholders() {
		/**
		 * Filters the list of upstream title strings treated as "no real title".
		 *
		 * @param string[] $placeholders Lowercased placeholder strings.
		 */
		return apply_filters(
			'pdi_generic_title_placeholders',
			array( 'photo detail', 'untitled', 'untitled photo' )
		);
	}

	/**
	 * Turns a URL slug into a reasonably readable fallback title, e.g.
	 * "red-fox-in-snow" becomes "Red fox in snow". Used when the upstream
	 * photo has no real title (or only a generic placeholder one).
	 *
	 * @param string $slug Post slug.
	 * @return string Humanized text, or an empty string if the slug yields nothing usable.
	 */
	private static function humanize_slug( $slug ) {
		$text = str_replace( array( '-', '_' ), ' ', $slug );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		return $text ? ucfirst( $text ) : '';
	}

	/**
	 * Extracts a name => [ url, width, height ] map from a raw sizes array,
	 * accepting either `source_url` (core /wp/v2/media shape) or `url` keys.
	 *
	 * @param array $raw_sizes Raw sizes array from the upstream API.
	 * @return array Map of size name => [ url, width, height ].
	 */
	private static function extract_sizes( $raw_sizes ) {
		$out = array();
		foreach ( $raw_sizes as $name => $data ) {
			if ( empty( $data ) || ! is_array( $data ) ) {
				continue;
			}
			$url = '';
			if ( ! empty( $data['source_url'] ) ) {
				$url = $data['source_url'];
			} elseif ( ! empty( $data['url'] ) ) {
				$url = $data['url'];
			}
			if ( ! $url ) {
				continue;
			}
			$out[ $name ] = array(
				'url'    => $url,
				'width'  => isset( $data['width'] ) ? intval( $data['width'] ) : 0,
				'height' => isset( $data['height'] ) ? intval( $data['height'] ) : 0,
			);
		}
		return $out;
	}
}
