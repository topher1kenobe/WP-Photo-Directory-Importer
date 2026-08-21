=== WP Photo Directory Importer ===
Contributors: ekamran, veeeharris, mattgaldino, telizarose, topher1kenobe, gusteci, michelleames
Tags: media, photos, importer, photo-directory
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.6
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
  alt-text field if the API exposes one, otherwise falls back to the same
  text used for the Description field (see `PDI_API::normalize_item()` for
  the exact priority order). It's never derived from the title. For title,
  known upstream placeholder strings (currently "Photo Detail", "Untitled",
  "Untitled Photo" — see `pdi_generic_title_placeholders` filter) are
  treated as no title at all, and a fallback title is derived from the
  photo's slug instead (e.g. `red-fox-in-snow` → "Red fox in snow"). If
  the slug itself isn't usable, the title falls back to "Untitled photo".
* The attachment's Description field (post_content) and alt text both get
  the photo's full description text; its Caption field (post_excerpt) gets
  *only* the photographer credit line (e.g. "Photo by Jane Smith, via the
  WordPress Photo Directory."), or is left empty if no author name is
  available.
* The search/browse picker UI doesn't display each photo's title — many
  photos on the Photo Directory only have a slug-derived fallback title
  (see above), which isn't meaningful to show while browsing. The title is
  still set on the imported attachment; it's just not shown in the grid.
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

= 1.3.6 =
* Added a "View full" button to the "Photo Directory" tab inside the
  native media picker (Set Featured Image, Add Media, etc.), next to the
  "Import only" button in the footer. Opens the currently-detailed
  photo's full-size image in a plain, image-only overlay (closes on
  Escape, clicking outside the image, or the close button).

= 1.3.5 =
* Added a "View full" button to every photo card on the Media > Photo
  Directory browse screen. Opens the photo's full-size image in a
  lightbox (closes on Escape or clicking outside the image), which
  includes its own Import button (or a "View in library" link, once the
  photo has been imported).

= 1.3.4 =
* Rebuilt **Media > Photo Directory** as a filterable browse screen
  (`assets/js/photo-browser.js`, React via `wp-element`): category,
  orientation, and color filters populated live from the Photo
  Directory's own taxonomies; sort by relevance or newest; multi-select
  with a bulk-import tray; per-photo title/alt/caption edits and a
  photographer-credit toggle before import.
* Rebuilt the "Photo Directory" tab inside the native media picker as a
  two-pane picker (thumbnail grid + detail sidebar) with the same
  filters, matching the browse screen.
* Photos already in the Media Library are now flagged in bulk for a
  whole result page at once (`PDI_Importer::find_existing_attachments()`)
  instead of one lookup per card.
* `_embed` now requests only the two relations the plugin reads
  (`author,wp:featuredmedia`) instead of every relation the API would
  otherwise expand, avoiding an unnecessary `wp:term` expansion per
  taxonomy per photo.
* Title fallback now prefers a short title derived from the photo's own
  description over a humanized slug, since most Photo Directory slugs are
  opaque hex strings (e.g. `6836a813f7`) with no human meaning to
  humanize in the first place.
* Normalized photo data now includes width, height, MIME type, and file
  size, and the computed credit line is available directly on the photo
  object for the new UI to prefill.
* Added Michelle Frechette as a contributor.
* Removed the bundled `LICENSE` file in favor of linking the license
  online.
* Fixed the search field's icon overlapping its placeholder text.

= 1.3.3 =
* Sanitize untrusted fields from the Photo Directory API before use: the
  slug now goes through `sanitize_title()`, the author name through
  `wp_strip_all_tags()`/`trim()`, and every image URL through
  `esc_url_raw()`.
* Moved the `wp-admin/includes/{media,file,image}.php` requires from
  file scope into `import_photo()` itself, so they're only parsed while
  an import is actually happening rather than on every page load
  (this file loads on every request via `plugins_loaded`, including the
  front end).
* Fixed a phpcs suppression bug: the slow-query warning for
  `meta_key`/`meta_value` was suppressed with a single `phpcs:ignore`
  comment, which only covers the next line and so didn't actually
  suppress the `meta_value` warning. Switched to `phpcs:disable`/
  `phpcs:enable` spanning both lines.
* Fixed invalid XML in `phpcs.xml.dist` — an unescaped `--` inside an XML
  comment (`--dev`) is illegal in XML and would cause strict parsers to
  reject the whole ruleset file.
* Added gusteci to the plugin's Author/Contributors list.

= 1.3.2 =
* Alt text now falls back to the same description text used for the
  Description field, rather than a separate excerpt-only source that was
  frequently empty even when the description itself had usable text.
* Reverted the 1.3.1 Caption/Description swap: Description (post_content)
  gets the photo's full description text again, and Caption (post_excerpt)
  goes back to containing only the photographer credit line.

= 1.3.1 =
* Swapped the Caption/Description field mapping: Caption (post_excerpt)
  now gets the photo's full description text, and Description
  (post_content) gets only the photographer credit line.
* Removed the (often meaningless, slug-derived) title text from the
  search/browse picker grid. The title is still set on import; it's just
  no longer shown while browsing.

= 1.3.0 =
* Alt text now falls back to the photo's excerpt specifically, rather than
  its (potentially much longer) content/description.
* The Caption field (post_excerpt) now contains only the photographer
  credit line; description text no longer appears in both the Description
  and Caption fields.

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
