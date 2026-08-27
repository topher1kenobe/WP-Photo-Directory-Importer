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

	/**
	 * The only two link relations normalize_item() reads. Naming them keeps
	 * `wp:term` out of the response, which the endpoint would otherwise
	 * expand into a full object per taxonomy per photo. The taxonomy IDs
	 * this plugin needs are already on the item itself.
	 */
	const EMBED_RELATIONS = 'author,wp:featuredmedia';

	const TAXONOMY_BASE   = 'https://wordpress.org/photos/wp-json/wp/v2/';
	const CACHE_TTL       = 300; // 5 minutes.
	const TERMS_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Taxonomies the browse UI can filter on, in the order they're shown.
	 *
	 * @var string[]
	 */
	const FILTER_TAXONOMIES = array( 'photo-categories', 'photo-orientations', 'photo-colors' );

	/**
	 * AJAX handler for `action=pdi_search`. Expects `search` and `page`
	 * POST parameters, plus optional `category`, `orientation`, `color`
	 * (term IDs) and `sort`; returns a normalized list of photos as JSON.
	 */
	public static function ajax_search() {
		check_ajax_referer( 'pdi_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'pdi' ) ), 403 );
			return;
		}

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$page   = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;

		$filters = array(
			'category'    => isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0,
			'orientation' => isset( $_POST['orientation'] ) ? absint( $_POST['orientation'] ) : 0,
			'color'       => isset( $_POST['color'] ) ? absint( $_POST['color'] ) : 0,
			'sort'        => isset( $_POST['sort'] ) ? sanitize_key( wp_unslash( $_POST['sort'] ) ) : '',
		);

		$result = self::search( $search, $page, 20, $filters );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
			return;
		}

		// Resolved outside search() on purpose: the search payload is cached
		// for five minutes, but which photos are already in the library
		// changes the moment one is imported.
		$result['importedMap'] = PDI_Importer::find_existing_attachments( wp_list_pluck( $result['photos'], 'id' ) );
		$result['libraryUrl']  = admin_url( 'upload.php' );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler for `action=pdi_terms`. Returns the filterable taxonomy
	 * terms so the UI can populate its dropdowns from the directory itself
	 * rather than from a hardcoded list that silently rots.
	 */
	public static function ajax_terms() {
		check_ajax_referer( 'pdi_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'pdi' ) ), 403 );
			return;
		}

		wp_send_json_success( self::get_filter_terms() );
	}

	/**
	 * Search the Photo Directory, with a short-lived transient cache to
	 * avoid re-hitting the upstream API on repeat searches.
	 *
	 * @param string $search   Search term. Empty string returns latest photos.
	 * @param int    $page     1-indexed page number.
	 * @param int    $per_page Results per page.
	 * @param array  $filters  Optional. Term IDs for `category`, `orientation`
	 *                         and `color`, plus a `sort` key. See normalize_filters().
	 * @return array|WP_Error {
	 *     @type array $photos     Normalized photo objects, see normalize_item().
	 *     @type int   $page       Current page.
	 *     @type int   $totalPages Total pages available upstream.
	 *     @type int   $total      Total item count upstream.
	 * }
	 */
	public static function search( $search = '', $page = 1, $per_page = 20, $filters = array() ) {
		$filters = self::normalize_filters( $filters, $search );

		$cache_key = 'pdi_search_' . md5( wp_json_encode( array( $search, $page, $per_page, $filters ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$args = array(
			'page'     => $page,
			'per_page' => $per_page,
			'_embed'   => self::EMBED_RELATIONS,
			'orderby'  => $filters['sort'],
			'order'    => 'desc',
		);
		if ( '' !== $search ) {
			$args['search'] = $search;
		}
		if ( $filters['category'] ) {
			$args['photo-categories'] = $filters['category'];
		}
		if ( $filters['orientation'] ) {
			$args['photo-orientations'] = $filters['orientation'];
		}
		if ( $filters['color'] ) {
			$args['photo-colors'] = $filters['color'];
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
	 * Fills in defaults and clamps the browse filters to values the upstream
	 * API will actually accept.
	 *
	 * The Photo Directory exposes no popularity signal of any kind (no view,
	 * download or favourite count), so the only orderings available are
	 * `relevance` and `date`. Relevance additionally requires a search term:
	 * asking for it while browsing returns HTTP 400, so it degrades to date
	 * whenever the query is empty.
	 *
	 * @param array  $filters Raw filters.
	 * @param string $search  Search term the filters will be applied to.
	 * @return array {
	 *     @type int    $category    photo-categories term ID, 0 for any.
	 *     @type int    $orientation photo-orientations term ID, 0 for any.
	 *     @type int    $color       photo-colors term ID, 0 for any.
	 *     @type string $sort        Either 'relevance' or 'date'.
	 * }
	 */
	private static function normalize_filters( $filters, $search ) {
		$filters = wp_parse_args(
			$filters,
			array(
				'category'    => 0,
				'orientation' => 0,
				'color'       => 0,
				'sort'        => '',
			)
		);

		$sort = 'relevance' === $filters['sort'] ? 'relevance' : 'date';
		if ( 'relevance' === $sort && '' === $search ) {
			$sort = 'date';
		}

		return array(
			'category'    => absint( $filters['category'] ),
			'orientation' => absint( $filters['orientation'] ),
			'color'       => absint( $filters['color'] ),
			'sort'        => $sort,
		);
	}

	/**
	 * Fetches the filterable taxonomy terms from the directory, cached for a
	 * day since they change very rarely.
	 *
	 * Returns an empty term list (rather than a WP_Error) for any taxonomy
	 * that fails to load, so one unreachable taxonomy degrades a single
	 * dropdown instead of breaking the whole screen.
	 *
	 * @return array Map of taxonomy name => list of [ id, slug, name, count, hex ].
	 */
	public static function get_filter_terms() {
		$cached = get_transient( 'pdi_filter_terms' );
		if ( false !== $cached ) {
			return $cached;
		}

		$out = array();
		foreach ( self::FILTER_TAXONOMIES as $taxonomy ) {
			$out[ $taxonomy ] = self::fetch_terms( $taxonomy );
		}

		set_transient( 'pdi_filter_terms', $out, self::TERMS_CACHE_TTL );

		return $out;
	}

	/**
	 * Fetches every term of one taxonomy, ordered by how many photos use it.
	 *
	 * @param string $taxonomy Taxonomy REST base, e.g. 'photo-colors'.
	 * @return array List of [ id, slug, name, count, hex ].
	 */
	private static function fetch_terms( $taxonomy ) {
		$url = add_query_arg(
			array(
				'per_page' => 100,
				'orderby'  => 'count',
				'order'    => 'desc',
				'_fields'  => 'id,slug,name,count',
			),
			self::TAXONOMY_BASE . $taxonomy
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array();
		}

		$terms = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$swatches = self::color_swatches();
		$out      = array();

		foreach ( $terms as $term ) {
			if ( empty( $term['id'] ) || empty( $term['slug'] ) ) {
				continue;
			}
			$slug  = sanitize_title( $term['slug'] );
			$out[] = array(
				'id'    => intval( $term['id'] ),
				'slug'  => $slug,
				'name'  => isset( $term['name'] ) ? trim( wp_strip_all_tags( html_entity_decode( $term['name'], ENT_QUOTES, 'UTF-8' ) ) ) : $slug,
				'count' => isset( $term['count'] ) ? intval( $term['count'] ) : 0,
				'hex'   => isset( $swatches[ $slug ] ) ? $swatches[ $slug ] : '',
			);
		}

		return $out;
	}

	/**
	 * Swatch colours used to render the photo-colors filter. Keyed by the
	 * upstream term slug; the six the design specifies come straight from
	 * the wp-admin palette, the rest are chosen to sit alongside them.
	 *
	 * @return array Map of term slug => hex colour.
	 */
	private static function color_swatches() {
		/**
		 * Filters the hex colour used for each photo-colors swatch.
		 *
		 * @param array $swatches Map of term slug => hex colour.
		 */
		return apply_filters(
			'pdi_color_swatches',
			array(
				'black'  => '#1d2327',
				'blue'   => '#3858e9',
				'green'  => '#2c8f4a',
				'yellow' => '#d9a51b',
				'red'    => '#d63638',
				'white'  => '#f6f7f7',
				'gray'   => '#8c8f94',
				'silver' => '#c3c4c7',
				'brown'  => '#7a5c3e',
				'orange' => '#e07c24',
				'pink'   => '#e0669b',
				'purple' => '#7c4bab',
				'violet' => '#9a6fd4',
			)
		);
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

		$url = add_query_arg( array( '_embed' => self::EMBED_RELATIONS ), self::REMOTE_BASE . '/' . $id );

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
	 *     @type int    $width       Pixel width of the full-size original.
	 *     @type int    $height      Pixel height of the full-size original.
	 *     @type string $mime        MIME type of the original, e.g. 'image/jpeg'.
	 *     @type int    $filesize    Size of the original in bytes, 0 when unknown.
	 *     @type array  $terms       Map of taxonomy name => list of term IDs.
	 *     @type string $credit      Photographer credit line the importer would write as
	 *                               the caption, so the UI can prefill it.
	 * }
	 */
	public static function normalize_item( $item ) {
		$id    = isset( $item['id'] ) ? intval( $item['id'] ) : 0;
		$title = isset( $item['title']['rendered'] ) ? trim( wp_strip_all_tags( $item['title']['rendered'] ) ) : '';
		$link  = isset( $item['link'] ) ? esc_url_raw( $item['link'] ) : '';
		$slug  = isset( $item['slug'] ) && is_string( $item['slug'] ) ? sanitize_title( $item['slug'] ) : '';

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

		$sizes    = array();
		$alt      = '';
		$mime     = '';
		$filesize = 0;

		// 1) Sizes living directly on the item (mirrors the core /wp/v2/media shape).
		if ( ! empty( $item['media_details']['sizes'] ) && is_array( $item['media_details']['sizes'] ) ) {
			$sizes = self::extract_sizes( $item['media_details']['sizes'] );
		}

		// 2) Sizes on the embedded featured media object (?_embed).
		if ( ! empty( $item['_embedded']['wp:featuredmedia'][0] ) ) {
			$media = $item['_embedded']['wp:featuredmedia'][0];

			if ( empty( $sizes ) && ! empty( $media['media_details']['sizes'] ) ) {
				$sizes = self::extract_sizes( $media['media_details']['sizes'] );
			}
			if ( ! empty( $media['mime_type'] ) && is_string( $media['mime_type'] ) ) {
				$mime = sanitize_mime_type( $media['mime_type'] );
			}
			if ( ! empty( $media['media_details']['filesize'] ) ) {
				$filesize = intval( $media['media_details']['filesize'] );
			}
			if ( empty( $alt ) && ! empty( $media['alt_text'] ) ) {
				$alt = $media['alt_text'];
			}
			if ( empty( $sizes ) && ! empty( $media['source_url'] ) ) {
				$source_url = esc_url_raw( $media['source_url'] );
				if ( $source_url ) {
					$sizes['full'] = array(
						'url'    => $source_url,
						'width'  => isset( $media['media_details']['width'] ) ? intval( $media['media_details']['width'] ) : 0,
						'height' => isset( $media['media_details']['height'] ) ? intval( $media['media_details']['height'] ) : 0,
					);
				}
			}
		}

		// 3) A custom top-level "sizes" field.
		if ( empty( $sizes ) && ! empty( $item['sizes'] ) && is_array( $item['sizes'] ) ) {
			$sizes = self::extract_sizes( $item['sizes'] );
		}

		// 4) Last resort: a single source_url on the item itself.
		if ( empty( $sizes ) && ! empty( $item['source_url'] ) ) {
			$source_url = esc_url_raw( $item['source_url'] );
			if ( $source_url ) {
				$sizes['full'] = array(
					'url'    => $source_url,
					'width'  => 0,
					'height' => 0,
				);
			}
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
		// field at all. Fall back to the description — the only
		// reliably-populated text this API offers — shortened only when it
		// runs past a reasonable alt-text length.
		if ( empty( $alt ) && ! empty( $description ) ) {
			$alt = self::alt_from_description( $description );
		}

		$author = '';
		if ( ! empty( $item['_embedded']['author'][0]['name'] ) ) {
			$author = $item['_embedded']['author'][0]['name'];
		}
		$author = is_string( $author ) ? trim( wp_strip_all_tags( $author ) ) : '';

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

		// Almost every photo in the directory arrives with the placeholder
		// title stripped above and an opaque slug like "6836a813f7", so the
		// uploader's own description is the only human-readable text left to
		// build a title from. The slug is only humanized when it actually
		// reads like words.
		if ( '' === $title && $description ) {
			$title = self::title_from_description( $description );
		}
		if ( '' === $title && $slug && ! self::is_opaque_slug( $slug ) ) {
			$title = self::humanize_slug( $slug );
		}

		$full = ! empty( $sizes['full'] ) ? $sizes['full'] : self::largest_size( $sizes );

		$photo = array(
			'id'          => $id,
			'title'       => $title ? $title : __( 'Untitled photo', 'pdi' ),
			'description' => $description,
			'link'        => $link,
			'slug'        => $slug,
			'author'      => $author,
			'alt'         => $alt,
			'thumbUrl'    => $thumb,
			'sizes'       => $sizes,
			'width'       => $full ? intval( $full['width'] ) : 0,
			'height'      => $full ? intval( $full['height'] ) : 0,
			'mime'        => $mime,
			'filesize'    => $filesize,
			'terms'       => self::extract_terms( $item ),
		);

		$photo['credit'] = PDI_Importer::build_credit_line( $photo );

		return $photo;
	}

	/**
	 * Picks the size with the largest pixel area from a photo's size map.
	 *
	 * @param array $sizes Map of size name => [ url, width, height ].
	 * @return array|null The largest size entry, or null when the map is empty.
	 */
	private static function largest_size( $sizes ) {
		$best = null;
		$area = -1;
		foreach ( $sizes as $size ) {
			$size_area = intval( $size['width'] ) * intval( $size['height'] );
			if ( $size_area > $area ) {
				$area = $size_area;
				$best = $size;
			}
		}
		return $best;
	}

	/**
	 * Pulls the taxonomy term IDs off a raw item so the UI can tell which
	 * category, colours and orientation a photo belongs to.
	 *
	 * @param array $item Raw item from the upstream `/wp/v2/photos` response.
	 * @return array Map of taxonomy name => list of term IDs.
	 */
	private static function extract_terms( $item ) {
		$out = array();
		foreach ( self::FILTER_TAXONOMIES as $taxonomy ) {
			$ids = array();
			if ( ! empty( $item[ $taxonomy ] ) && is_array( $item[ $taxonomy ] ) ) {
				$ids = array_values( array_filter( array_map( 'intval', $item[ $taxonomy ] ) ) );
			}
			$out[ $taxonomy ] = $ids;
		}
		return $out;
	}

	/**
	 * Builds a short title out of a photo's description, cut on a word
	 * boundary so it stays readable in a single-line card.
	 *
	 * @param string $description Plain-text description.
	 * @return string Title text, or an empty string if nothing usable remains.
	 */
	private static function title_from_description( $description ) {
		$text = trim( preg_replace( '/\s+/', ' ', $description ) );
		if ( '' === $text ) {
			return '';
		}

		/**
		 * Filters how many characters of the description may be used as a title.
		 *
		 * @param int $length Maximum title length in characters.
		 */
		$length = (int) apply_filters( 'pdi_description_title_length', 60 );

		if ( mb_strlen( $text ) > $length ) {
			$words = explode( ' ', $text );
			$text  = '';
			foreach ( $words as $word ) {
				$candidate = '' === $text ? $word : $text . ' ' . $word;
				if ( '' !== $text && mb_strlen( $candidate ) > $length ) {
					break;
				}
				$text = $candidate;
			}
			$text  = rtrim( mb_substr( $text, 0, $length ), " \t\n\r\0\x0B.,;:-" );
			$text .= '…';
		}

		return ucfirst( $text );
	}

	/**
	 * Derives concise alt text from a photo's description.
	 *
	 * The Photo Directory exposes no dedicated alt-text field, so the
	 * description is the only text available. A description that already fits
	 * within the alt-text length is used as-is — the 125-character figure is
	 * guidance, not a hard limit, so there's nothing to gain by cutting text
	 * that's already short enough. Only longer descriptions get shortened:
	 * first to the opening sentence if that fits, otherwise hard-capped on a
	 * word boundary with no trailing ellipsis (which reads oddly aloud).
	 * Editors can still override alt text in the import UI before importing.
	 *
	 * @param string $description Plain-text description.
	 * @return string Concise alt text, or an empty string if nothing usable remains.
	 */
	private static function alt_from_description( $description ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $description ) );
		if ( '' === $text ) {
			return '';
		}

		/**
		 * Filters the maximum length of description-derived alt text.
		 *
		 * @param int $length Maximum alt-text length in characters. Default 125.
		 */
		$max = (int) apply_filters( 'pdi_alt_max_length', 125 );

		// Anything already within the limit is left exactly as written.
		if ( mb_strlen( $text ) > $max ) {
			if ( preg_match( '/^(.+?[.!?])(?:\s|$)/u', $text, $m ) && mb_strlen( $m[1] ) <= $max ) {
				// The opening sentence fits, so it makes the cleanest caption.
				$text = $m[1];
			} else {
				// No sentence break inside the cap; trim to the last whole word.
				$clipped = mb_substr( $text, 0, $max );
				$space   = mb_strrpos( $clipped, ' ' );
				if ( false !== $space && $space > 0 ) {
					$clipped = mb_substr( $clipped, 0, $space );
				}
				$text = rtrim( $clipped, " \t\n\r\0\x0B.,;:-" );
			}
		}

		/**
		 * Filters the alt text derived from a photo's description.
		 *
		 * @param string $alt         Derived alt text.
		 * @param string $description Full plain-text description.
		 */
		return apply_filters( 'pdi_alt_from_description', $text, $description );
	}

	/**
	 * Whether a slug is an opaque upstream identifier rather than words.
	 * The Photo Directory names most photos with a short hex string, which
	 * makes for a meaningless title.
	 *
	 * @param string $slug Post slug.
	 * @return bool True when the slug carries no human meaning.
	 */
	private static function is_opaque_slug( $slug ) {
		return 1 === preg_match( '/^[0-9a-f]{6,}$/i', $slug );
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
			$url = is_string( $url ) ? esc_url_raw( $url ) : '';
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
