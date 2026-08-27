# Photo Directory Importer

Search the [WordPress Photo Directory](https://wordpress.org/photos) from
inside wp-admin and import CC0 photos straight into your site's Media
Library — ready to use as a featured image, in a block, in a gallery, or
anywhere else a regular upload would work.

![License: GPLv2 or later](https://img.shields.io/badge/license-GPLv2%20or%20later-blue.svg)
![Requires PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)
![Requires WordPress 5.8+](https://img.shields.io/badge/WordPress-5.8%2B-21759b.svg)

## What it does

The plugin proxies searches to the public Photo Directory REST API
(`https://wordpress.org/photos/wp-json/wp/v2/photos`) and, when you pick a
photo, downloads it and sideloads it into your own Media Library as a
normal attachment. From that point on it behaves exactly like any other
upload.

Three entry points are added to wp-admin:

- **Media → Photo Directory** — a dedicated search/browse page.
- **A "Photo Directory" button** next to *Add Media* in the classic editor.
- **A "Photo Directory" tab** inside the native WordPress media picker
  itself (Set Featured Image, Add Media, an Image block's Media Library
  button, etc.) — this works in both the classic and block editor, since
  they share the same underlying picker under the hood.

## Installation

1. Download the [latest release](../../releases) or clone this repo.
2. Copy (or symlink) the plugin folder into `wp-content/plugins/`.
3. Activate **Photo Directory Importer** from the Plugins screen.

No configuration is required — no API key is needed, since the Photo
Directory API is public.

## How it works

| File | Responsibility |
|---|---|
| `photo-directory-importer.php` | Plugin bootstrap: defines constants, loads the include files. |
| `includes/class-pdi-plugin.php` | Hook registration, asset registration, admin page, media button. |
| `includes/class-pdi-api.php` | Talks to the upstream Photo Directory REST API and normalizes its response (search + single-photo lookup, transient caching). |
| `includes/class-pdi-importer.php` | Downloads a chosen photo, optionally converts its format, and sideloads it into the local Media Library via `media_handle_sideload()`, with de-duplication and caption/credit handling. |
| `includes/class-pdi-settings.php` | The Settings > Photo Directory page: detects WebP/AVIF support and lets the site owner choose an output format and quality. |
| `assets/js/photo-browser.js` | The Media > Photo Directory browse screen (React via `wp-element`): search, filters, multi-select, bulk import. |
| `assets/js/admin.js` | The classic-editor "Photo Directory" button's pop-up picker. |
| `assets/js/media-modal.js` | Adds the "Photo Directory" tab to the native `wp.media` frame (Set Featured Image, Add Media, etc.) and hands imported photos to that frame's own selection/toolbar. |
| `assets/js/settings.js` | Shows/hides the quality field on Settings > Photo Directory based on which format is selected. |

Every imported attachment gets:

- **Title** — the upstream photo's own title, used as-is — *unless* it's a
  known generic placeholder (currently "Photo Detail", "Untitled",
  "Untitled Photo" — filterable via `pdi_generic_title_placeholders`), in
  which case a fallback title is derived from the photo's slug instead
  (e.g. `red-fox-in-snow` → "Red fox in snow"), falling back further to
  "Untitled photo" if the slug isn't usable either. Not shown in the
  search/browse picker grid, since a lot of these fallback titles aren't
  meaningful to look at while browsing — the title is still set on the
  imported attachment itself.
- **Alt text** — prefers a real alt-text field if the upstream API exposes
  one, otherwise falls back to the same text used for the Description
  field below. Never derived from the title.
- **Description** (`post_content`) — the photo's full description text.
- **Caption** (`post_excerpt`) — *only* the "Photo by {name}" credit
  line, when the API exposes an author name; empty otherwise.
- Three meta fields so you can trace it back to its source:

| Meta key | Value |
|---|---|
| `_pdi_source_id` | The photo's ID on the Photo Directory (also used for de-duplication). |
| `_pdi_source_url` | The photo's permalink on wordpress.org/photos. |
| `_pdi_source_author` | The uploader's display name, when the API exposes it. |

All Photo Directory photos are released under **CC0** — no attribution is
legally required, but the caption credit and meta above make it easy to
credit uploaders anyway.

## Image format conversion

**Settings > Photo Directory** lets you choose whether imported photos get
converted to a different format before they're added to the Media Library:

- If this server's image editor can produce WebP (checked live via
  `wp_image_editor_supports()`, not assumed from PHP version or extension
  presence alone), a picker appears: keep the original format, convert to
  WebP, or convert to AVIF (offered only when that's supported too). WebP
  is the default whenever it's available.
- A quality field (1–100, default 82 — matching core's own default JPEG
  compression quality) appears alongside the format picker whenever WebP
  or AVIF is selected, and is hidden when "Keep original format" is
  selected, since nothing gets re-encoded in that case.
- If WebP isn't supported, the page just says so — no picker, and photos
  keep their original format, same as before this feature existed.

Conversion happens in `PDI_Importer::maybe_convert_image()`, right after
download and before sideloading, so every generated thumbnail/medium/large
sub-size is produced from the converted file directly. It re-checks
support at import time rather than trusting the stored setting outright,
and falls back to the original file on any failure — conversion is meant
to be a nice-to-have, never something that can turn into a failed import.

## A note on the upstream API

The Photo Directory REST API is public, but its exact JSON shape isn't
formally documented. `PDI_API::normalize_item()` in
`includes/class-pdi-api.php` is written defensively, checking several
plausible locations for image size data (`media_details.sizes` on the
item itself, the same on an embedded `wp:featuredmedia` object, a bare
`source_url`, etc.) rather than assuming one exact shape. If wordpress.org
ever changes the response format and imports or thumbnails start
misbehaving, that function is the one place to look — it's easiest to
diagnose by comparing its checks against a live response from
`https://wordpress.org/photos/wp-json/wp/v2/photos?_embed=1`.

## Security

- Every AJAX endpoint (`pdi_search`, `pdi_terms`, `pdi_import`) requires
  both a valid nonce and the `upload_files` capability, and none are
  registered as `wp_ajax_nopriv_*` — logged-out visitors can't reach any
  of it.
- All outbound requests to the Photo Directory use hardcoded, HTTPS,
  first-party constants (`PDI_API::REMOTE_BASE`, `::TAXONOMY_BASE`) as the
  base URL; user input only ever becomes query-string values appended to
  those, never the host being requested.
- Before an image is sideloaded into the Media Library,
  `PDI_Importer::is_allowed_image_host()` requires its URL's host to be
  `wordpress.org`, `wp.com`, or `w.org` (or a subdomain of any of them —
  the Photo Directory's images actually come from `pd.w.org`, WordPress.org's
  own short domain, the same family as `s.w.org`). This guards specifically
  against a compromised or malicious upstream API response — not against
  user input, which can't reach this code path at all — since the plugin
  otherwise trusts whatever image URL the API returns for a given photo.
  If an import ever fails with "not on a trusted host," the error message
  includes the actual rejected hostname; add it via
  `pdi_allowed_image_hosts` if you recognize it as legitimate.

## Translations

Text domain is `photo-directory-importer` (must match the plugin's own
folder/slug — WordPress.org's Plugin Check tool, and translation loading
in general, both require this), with a bundled `.pot` file at
`languages/photo-directory-importer.pot`. Every PHP-side string uses
`__()`/`_e()`/`esc_html__()`/`esc_attr__()` etc.; every JS-side string is
localized server-side via `wp_localize_script()` — nothing is hardcoded in
the JS files themselves.

Dynamic counts ("N selected", "N photos imported") go through
`wp.i18n._n()` (loaded via `wp_set_script_translations()`) rather than a
hardcoded `count === 1` check, since English's two plural forms don't
generalize to languages with three, four, or six — `_n()` consults the
current locale's actual plural-forms rule instead. If you add a new
count-dependent string, use the `ni18n()` helper already defined near the
top of `photo-browser.js`/`media-modal.js` rather than branching on the
count directly.

Since WordPress 4.6, no `load_plugin_textdomain()` call is needed: core's
just-in-time loader automatically picks up a compiled
`photo-directory-importer-{locale}.mo`/`.json` from
`wp-content/languages/plugins/` once a translation exists, regardless of
whether the plugin is hosted on wordpress.org.

## Development

This plugin follows the [WordPress PHP Coding
Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/).
A ruleset is included at `phpcs.xml.dist`. To check the code:

```bash
composer require --dev wp-coding-standards/wpcs
composer require --dev dealerdirect/phpcodesniffer-composer-installer
vendor/bin/phpcs
```

### Ideas for contributions

- Filters/hooks around search args and import behavior.
- A settings screen for default import size.
- Support for the Photo Directory's tag/category taxonomy in search.
- Bulk import.

Pull requests welcome.

## Authors

ekamran, veeeharris, mattgaldino, telizarose, topher1kenobe, gusteci, michelleames

Each name above is a wordpress.org username. On the Plugins list page, each
one links to `https://profiles.wordpress.org/username` individually — the
standard plugin header format only supports one shared link for the whole
"Author" field, so `PDI_Plugin::link_authors_to_profiles()` rewrites the
row via the `all_plugins` filter to give each name its own link instead.

## License

GPLv2 or later. See the [full license text](https://www.gnu.org/licenses/gpl-2.0.html).

## Disclaimer

This is an independent, unofficial plugin built against a public API. It
is not affiliated with or endorsed by the WordPress Photo Directory or the
WordPress Foundation.
