=== WP Photo Directory Importer ===
Contributors: topher
Tags: media, photos, importer, photo-directory
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search the WordPress Photo Directory (wordpress.org/photos) and import CC0 photos straight into your Media Library.

== Description ==

This plugin adds a "Photo Directory" search UI to wp-admin that talks to the
public Photo Directory REST API at:

    https://wordpress.org/photos/wp-json/wp/v2/photos

You can search, preview, and import photos directly into your own Media
Library, where the imported image behaves exactly like any regular upload
— usable as a featured image, inside blocks, in a gallery, etc.

Entry points:

* **Media > Photo Directory** — a dedicated search/browse admin page.
* **"Photo Directory" button** next to Add Media in the classic editor.
* **"Photo Directory" tab** inside the native WordPress media picker itself
  (Set Featured Image, Add Media, an Image block's Media Library button,
  etc.) — works in both the classic and block editor, since they share the
  same underlying media picker.

== Notes for developers ==

* This is an unofficial integration with a public but not formally
  documented API. `includes/class-pdi-api.php::normalize_item()` is where
  the raw JSON from the Photo Directory gets mapped into predictable
  `sizes`/`alt`/`author` fields — it tries several plausible shapes
  (`media_details.sizes`, an embedded featured-media object, a bare
  `source_url`, etc.). If wordpress.org changes the API's response shape,
  this is the one function that should need updating.
* Title and alt text are sourced independently: alt text prefers a real
  alt-text field if the API exposes one, otherwise reuses the photo's
  description (that appears to be the closest thing to alt text the Photo
  Directory API offers — see `PDI_API::normalize_item()` for the exact
  priority order). It's never derived from the title. For title, known
  upstream placeholder strings (currently "Photo Detail", "Untitled",
  "Untitled Photo" — see `pdi_generic_title_placeholders` filter) are
  treated as no title at all, and a fallback title is derived from the
  photo's slug instead (e.g. `red-fox-in-snow` → "Red fox in snow"). If
  the slug itself isn't usable, the title falls back to "Untitled photo".
* The attachment caption (post_excerpt) is the photo's description with a
  "Photo by {name}" credit line appended, when the API exposes an author
  name.
* Imports are deduplicated: each imported attachment gets a
  `_pdi_source_id` meta value, and re-importing the same photo returns the
  existing attachment instead of downloading it again.
* Search results are cached for 5 minutes via transients to avoid hammering
  the upstream API on repeat searches.
* All photos on the Photo Directory are released CC0 (no attribution
  required), but the plugin stores the original photo URL and author name
  (when available) as `_pdi_source_url` / `_pdi_source_author` attachment
  meta for your own reference, in addition to the caption credit above.

== Changelog ==

= 1.2.3 =
* Alt text now falls back to the photo's description when the upstream
  API doesn't expose a dedicated alt-text field of its own — that appears
  to be the closest thing to alt text the Photo Directory API offers.

= 1.2.2 =
* Alt text was coming through empty for photos that, per direct testing,
  do have alt text upstream — the embedded-featured-media lookup this
  plugin relied on apparently isn't where the Photo Directory's custom
  post type actually stores it. Added speculative fallback checks
  (`alt_text` and `meta.alt_text`/`meta._wp_attachment_image_alt` on the
  item itself) pending confirmation against a real API response.

= 1.2.1 =
* The upstream Photo Directory substitutes a generic placeholder title
  (e.g. "Photo Detail") for photos the uploader never titled. That
  placeholder is now recognized and treated as no title, with a fallback
  title derived from the photo's slug instead (e.g. `red-fox-in-snow` →
  "Red fox in snow") rather than importing the literal placeholder text.
  Filterable via `pdi_generic_title_placeholders`.

= 1.2.0 =
* Fixed a bug where the attachment title was copied into the alt text
  field whenever the upstream photo had no alt text of its own, so the
  title and alt text would end up identical (and, for photos with a
  generic placeholder title, both fields would show that placeholder).
  Alt text is now only ever populated from the upstream photo's own alt
  text, and left blank otherwise.
* Added a photographer credit line to the imported attachment's caption
  when the upstream API exposes an author name.
* Removed the block editor sidebar panel; the native media modal tab
  (added in 1.1.0) covers the same use case without a separate panel.

= 1.1.0 =
* Added a "Photo Directory" tab inside the native WordPress media modal
  (Set Featured Image, Add Media, Image block, etc.), so photos can be
  searched and imported without leaving the picker you're already in.

= 1.0.0 =
* Initial release.
