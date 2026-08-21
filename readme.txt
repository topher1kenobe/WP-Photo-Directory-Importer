=== WP Photo Directory Importer ===
Contributors: ekamran, veeeharris, mattgaldino, telizarose, topher1kenobe, gusteci, michelleames
Tags: media, photos, importer, photo-directory
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.12
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

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/wp-photo-directory-importer` directory, or install the plugin through the **Plugins > Add New** screen in wp-admin directly.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. That's it — no configuration is needed and no API key or account is required. Look under **Media > Photo Directory**, use the "Photo Directory" button next to Add Media in the classic editor, or use the "Photo Directory" tab inside the native media picker (Set Featured Image, Add Media, an Image block's Media Library button, etc.).

== Frequently Asked Questions ==

= Do I need an API key or account to use this? =

No. The Photo Directory REST API is public, so the plugin works as soon as it's activated.

= Do I need to credit the photographer? =

No — every photo on the Photo Directory is released under CC0, so attribution isn't legally required. The plugin adds a photographer credit line to each imported photo's caption anyway, when the API provides an author name, so credit is there if you choose to show it.

= Why does an imported photo's title look like a URL slug instead of a real title? =

Some photos on the Photo Directory only have a generic placeholder title, because the original uploader never set a real one. The plugin recognizes those placeholders and falls back to a title built from the photo's slug or description instead, rather than importing the placeholder text as-is. It's a best-effort fallback, not guaranteed to read naturally — worth a glance before publishing.

= Why don't I see the "Photo Directory" button in the block editor? =

That button is added next to Add Media via the `media_buttons` hook, which only the classic editor fires. In the block editor, use the "Photo Directory" tab inside the native media picker instead — it opens the same way from Set Featured Image, Add Media, or an Image block's Media Library button.

= Can I choose what size image gets imported? =

Yes. Both the Media > Photo Directory browse screen and the media picker tab let you choose Full, Large, or Medium before importing.

= Can imported photos be converted to WebP or AVIF? =

Yes, if your server's image editor supports it. Go to **Settings > Photo Directory** to choose — WebP is used automatically whenever your server can produce it. If it can't, the page says so plainly and photos keep their original format. A quality field appears alongside the format choice whenever WebP or AVIF is selected; it has no effect when keeping the original format, so it's hidden in that case.

= Where does the plugin store which photos I've already imported? =

Each imported attachment gets a `_pdi_source_id` meta value matching its ID on the Photo Directory. Importing the same photo again returns the existing attachment instead of downloading a duplicate.

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
* Image format conversion (Settings > Photo Directory,
  `PDI_Settings::get_format()`) happens in
  `PDI_Importer::maybe_convert_image()`, right after download and before
  sideloading. It re-checks `wp_image_editor_supports()` at import time
  rather than trusting the stored setting outright, and falls back to the
  original file on any failure.
* Search results are cached for 5 minutes via transients to avoid hammering
  the upstream API on repeat searches.
* All photos on the Photo Directory are released CC0 (no attribution
  required), but the plugin stores the original photo URL and author name
  (when available) as `_pdi_source_url` / `_pdi_source_author` attachment
  meta for your own reference, in addition to the caption credit above.

== Changelog ==

= 1.3.12 =
* On the Plugins list page, each contributor's name now links to their
  own wordpress.org profile (`https://profiles.wordpress.org/username`)
  instead of the whole comma-separated author list linking to one shared
  GitHub URL — the standard plugin header format only supports one link
  for the entire "Author" string, so this rewrites the row directly via
  the `all_plugins` filter.
* Added a "Settings" link to the plugin's row on the Plugins page,
  appearing first among the action links, right next to "Deactivate".

= 1.3.11 =
* Added a quality field to **Settings > Photo Directory**, shown only
  when converting to WebP or AVIF (hidden automatically when "Keep
  original format" is selected — quality has no meaning there, since
  nothing gets re-encoded). Defaults to 82, matching WordPress core's own
  default JPEG compression quality. Clamped to 1–100 on save regardless
  of what's posted.

= 1.3.10 =
* Added a **Settings > Photo Directory** page. If this server's image
  editor (GD or Imagick, whichever core picked) can actually produce
  WebP — checked live via `wp_image_editor_supports()`, not assumed from
  PHP version or extension presence — a format picker appears: keep the
  original format, convert to WebP, or convert to AVIF (the latter only
  offered when supported too). WebP is the default whenever it's
  available. If WebP isn't supported, the page simply says so and no
  photos are converted, same as before this release.
* Conversion happens right after download, before the file is sideloaded,
  so every generated thumbnail/medium/large sub-size is produced from the
  converted file directly rather than converted a second time after the
  fact.
* A conversion failure for any reason falls back to importing the
  original file untouched — this is meant to be a nice-to-have, never
  something that can turn a failed conversion into a failed import.
* The "Import settings" link in the Media > Photo Directory page header,
  present but hidden since 1.3.4, now points at the new settings page.

= 1.3.9 =
* Added `w.org` to the default trusted image hosts (`pdi_allowed_image_hosts`).
  The Photo Directory serves its images from `pd.w.org` specifically —
  `w.org` is WordPress.org's own short domain, the same family as
  `s.w.org`, its well-known static-assets host. The 1.3.8 fix (adding
  `wp.com` for Photon) turned out not to be where these images actually
  live; this corrects it based on the real rejected host.

= 1.3.8 =
* **Fixed a regression from 1.3.7 that broke every import.** The trusted-host
  check added in 1.3.7 only allowed `wordpress.org` (and its subdomains),
  but the Photo Directory serves uploaded images through Automattic's
  Photon CDN (`i0.wp.com`, `i1.wp.com`, etc.) rather than from
  wordpress.org's own domain directly — so every single import was
  rejected as "untrusted." `wp.com` (and its subdomains) is now included
  in the default allowed hosts.
* The "not on a trusted host" error message now includes the actual
  rejected hostname, so a similar mismatch in the future is immediately
  diagnosable without digging through network requests, and can be
  resolved on the spot via the `pdi_allowed_image_hosts` filter.

= 1.3.7 =
Security review and hardening. No user-facing behavior changes; all four
items below were fixed as defense-in-depth rather than in response to an
exploited vulnerability.

* **Sideloaded image URLs are now pinned to a trusted host.**
  `PDI_Importer::import_photo()` downloads whichever image URL the Photo
  Directory API returns for a photo; that URL is data returned *by* the
  API, not something this plugin generates itself. Added
  `is_allowed_image_host()`, checked before `download_url()` is ever
  called, requiring the URL's host to be `wordpress.org` or a subdomain of
  it (filterable via `pdi_allowed_image_hosts`). Nothing a site visitor or
  lower-privileged user supplies can influence which host this plugin
  talks to for search or photo lookups — those always go to the hardcoded
  `REMOTE_BASE`/`TAXONOMY_BASE` constants — so this specifically guards
  against a compromised or malicious upstream API response causing this
  site to download and store a file from an attacker-controlled server.
* **Removed an unused `innerHTML` code path.** `assets/js/admin.js`'s `el()`
  helper had a dead `'html'` attribute branch (`e.innerHTML = attrs.html`)
  that nothing in the codebase called, but that would become an XSS sink
  the moment anything did. Removed it entirely.
* **Added `rel="noopener noreferrer"`** to the "View in Media Library"
  link in `assets/js/admin.js`, which opens in a new tab
  (`target="_blank"`). Its destination is fully server-generated today, so
  this wasn't exploitable, but it's standard, zero-cost protection against
  reverse-tabnabbing regardless.
* **Added explicit `return` statements after every `wp_send_json_error()`
  call** in `class-pdi-api.php` (`ajax_search()`, `ajax_terms()`) and
  `class-pdi-importer.php` (`ajax_import()`) that wasn't already the last
  statement in its function. `wp_send_json_error()` calls `wp_die()`,
  which WordPress's AJAX handling turns into an immediate `die()`, so
  execution already halted in normal operation — this wasn't currently
  exploitable. But code after those calls was relying on that implicit
  behavior instead of stating it, which would fall through with
  invalid/stale state (e.g. continuing with a photo ID of 0, or treating a
  `WP_Error` object as an array) if `wp_die()`'s behavior were ever
  filtered, as some testing harnesses deliberately do.

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

== Upgrade Notice ==

= 1.3.9 =
Corrects the trusted image-host allowlist added in 1.3.7, which could block all imports on some installs via a wrong default in 1.3.7 (and a partial fix in 1.3.8). Recommended if you're running 1.3.7 or 1.3.8.
