=== WP Photo Directory Importer ===
Contributors: yourname
Tags: media, photos, importer, photo-directory
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
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
* **Photo Directory panel** in the block editor sidebar, with a one-click
  "use as featured image" action after import.

== Notes for developers ==

* This is an unofficial integration with a public but not formally
  documented API. `includes/class-pdi-api.php::normalize_item()` is where
  the raw JSON from the Photo Directory gets mapped into predictable
  `sizes`/`alt`/`author` fields — it tries several plausible shapes
  (`media_details.sizes`, an embedded featured-media object, a bare
  `source_url`, etc.). If wordpress.org changes the API's response shape,
  this is the one function that should need updating.
* Imports are deduplicated: each imported attachment gets a
  `_pdi_source_id` meta value, and re-importing the same photo returns the
  existing attachment instead of downloading it again.
* Search results are cached for 5 minutes via transients to avoid hammering
  the upstream API on repeat searches.
* All photos on the Photo Directory are released CC0 (no attribution
  required), but the plugin stores the original photo URL and author name
  (when available) as `_pdi_source_url` / `_pdi_source_author` attachment
  meta for your own reference.

== Changelog ==

= 1.0.0 =
* Initial release.
