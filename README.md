# WP Photo Directory Importer

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
- **A "Photo Directory" panel** in the block editor sidebar, with a
  one-click *Use as featured image* action right after import.

## Installation

1. Download the [latest release](../../releases) or clone this repo.
2. Copy (or symlink) the plugin folder into `wp-content/plugins/`.
3. Activate **WP Photo Directory Importer** from the Plugins screen.

No configuration is required — no API key is needed, since the Photo
Directory API is public.

## How it works

| File | Responsibility |
|---|---|
| `wp-photo-directory-importer.php` | Plugin bootstrap: defines constants, loads the include files. |
| `includes/class-pdi-plugin.php` | Hook registration, asset registration, admin page, media button. |
| `includes/class-pdi-api.php` | Talks to the upstream Photo Directory REST API and normalizes its response (search + single-photo lookup, transient caching). |
| `includes/class-pdi-importer.php` | Downloads a chosen photo and sideloads it into the local Media Library via `media_handle_sideload()`, with de-duplication. |
| `assets/js/admin.js` | The search/grid picker UI. Renders inline on the admin page, or inside a modal when opened from the classic or block editor. |
| `assets/js/block-editor.js` | Registers the Photo Directory panel in the block editor sidebar and wires "Use as featured image" to `editPost()`. |

Every imported attachment gets three pieces of meta so you can trace it
back to its source:

| Meta key | Value |
|---|---|
| `_pdi_source_id` | The photo's ID on the Photo Directory (also used for de-duplication). |
| `_pdi_source_url` | The photo's permalink on wordpress.org/photos. |
| `_pdi_source_author` | The uploader's display name, when the API exposes it. |

All Photo Directory photos are released under **CC0** — no attribution is
legally required, but this metadata makes it easy to credit uploaders if
you'd like to.

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

## License

GPLv2 or later. See [LICENSE](LICENSE).

## Disclaimer

This is an independent, unofficial plugin built against a public API. It
is not affiliated with or endorsed by the WordPress Photo Directory or the
WordPress Foundation.
