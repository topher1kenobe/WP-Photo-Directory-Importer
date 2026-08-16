# WP Photo Directory Importer — Documentation

Version 1.1.0 · Last reviewed 2026-08-16

This file contains three documents:

1. [User Guide](#user-guide) — for site owners and editors
2. [Troubleshooting Guide](#troubleshooting-guide) — internal reference for technical support
3. [Feature Overview](#feature-overview) — client handoff, usually for marketing

---
---

# User Guide

## Introduction

The WP Photo Directory Importer adds a photo search tool to the WordPress admin. It connects to the public WordPress Photo Directory at wordpress.org/photos, where every photo is released under the CC0 license. Site editors can search that library, pick a photo, and import it into the site's own Media Library without leaving the admin.

Once a photo is imported, it becomes a normal attachment. It works as a featured image, inside blocks, in galleries, and anywhere else an uploaded image works.

## How It Works

The plugin adds four ways to reach the photo search.

| Entry Point | Where It Appears | What It Does |
|---|---|---|
| **Media > Photo Directory** | A page under the **Media** menu | Shows the search grid directly on the page |
| **Photo Directory** tab | Inside the standard WordPress media picker, next to **Upload files** and **Media Library** | Shows the search grid inside the picker that is already open |
| **Photo Directory** button | Next to **Add Media** in the classic editor | Opens the search grid in a pop-up window |
| **Photo Directory** panel | The block editor sidebar | Opens the search grid in a pop-up window from an **Import a photo…** button |

The search grid works the same way in all four places. It loads the newest photos when it opens. Typing a term and selecting **Search photos…** returns matching results, 20 at a time. A **Load more** button appears when more results are available.

Each result shows a thumbnail, a caption line, and an **Import** button. Selecting **Import** downloads the photo and saves it to the site's Media Library.

### Inside The Media Picker

The **Photo Directory** tab is the most direct route for most tasks. It appears inside the standard WordPress media picker, which is the window that opens from **Set featured image**, from **Add Media**, and from the **Media Library** button on an Image block.

In that tab, selecting **Import** does three things at once: it saves the photo to the Media Library, marks the card **Selected**, and hands the photo back to the picker with it already chosen. The picker then returns to its **Media Library** view with the new photo highlighted. Finishing the job uses the picker's own button, which reads **Set featured image**, **Insert into post**, or **Select** depending on where the picker was opened. In short, an imported photo behaves from that point on exactly like a photo that was already in the library.

### Everywhere Else

On the **Media > Photo Directory** page, and in the pop-up window opened from the classic editor button or the block editor sidebar panel, an imported card shows an **Imported** label and a **View in Media Library** link that opens the new attachment in a new browser tab.

In the classic editor and the block editor pop-up window, imported cards also show a **Use as featured image** button that sets the photo as the featured image for the post being edited. This button does not appear on the **Media > Photo Directory** page, because that page is not tied to a specific post.

### What Lands In The Media Library

Imported photos appear in **Media > Library** alongside regular uploads. The photo's descriptive sentence from the Photo Directory is saved as the attachment's description and caption.

Two fields are worth setting by hand after import: the **Title** and the **Alternative Text**. The Photo Directory's public data currently returns the same generic value, `Photo detail`, for every photo's title, and it returns no alt text at all. The plugin stores those values as it receives them. The photo's real description is saved correctly, so it is a good starting point for writing both fields. Setting the alt text matters for readers who use screen readers.

The plugin also saves three hidden values on each attachment so the original photo can be traced later:

| Stored Value | What It Holds |
|---|---|
| `_pdi_source_id` | The photo's ID on the Photo Directory |
| `_pdi_source_url` | The photo's page address on wordpress.org/photos |
| `_pdi_source_author` | The name of the person who uploaded the photo, when the Photo Directory provides it |

The plugin uses `_pdi_source_id` to prevent duplicates. Importing the same photo a second time returns the attachment that already exists instead of downloading a second copy.

Photos on the WordPress Photo Directory are released under CC0, so credit is optional. The stored author name and source address make it straightforward to add credit when a site chooses to.

## Before You Begin

- The site runs WordPress 5.8 or later and PHP 7.4 or later.
- The server can make outbound HTTP requests to wordpress.org. The plugin reads from a public address, so no API key or account is needed.
- The user account has the **Upload Files** capability. Administrators, Editors, and Authors have this capability by default. Accounts without it will not see the photo search.
- The **Photo Directory** button next to **Add Media** appears only on sites that use the classic editor. Sites running the block editor alone will use the media picker tab, the sidebar panel, or the Media page instead.

## Steps To Set Up

1. Copy the `WP-Photo-Directory-Importer` folder into the site's `wp-content/plugins/` directory.
2. Sign in to the WordPress admin.
3. Go to **Plugins > Installed Plugins**.
4. Find **WP Photo Directory Importer** and select **Activate**.

The plugin has no settings screen. It is ready to use as soon as it is active.

## Steps To Test

### Test The Media Page

1. Go to **Media > Photo Directory**. A page headed **WordPress Photo Directory** opens, and a grid of recent photos loads.
2. Type a common search term, such as `flower`, into the search field and select **Search photos…**. Matching photos appear in the grid.
3. Select **Load more** at the bottom of the grid. A further 20 photos are added below the first set.
4. Select **Import** on any photo. The button changes to **Importing…**, then to an **Imported** label.
5. Select **View in Media Library**. The attachment opens in a new tab. Confirm the description field holds a sentence describing the photo.
6. Set the **Title** and **Alternative Text** fields for that attachment and select **Update**.
7. Go to **Media > Library** and confirm the photo appears there alongside regular uploads.
8. Return to **Media > Photo Directory**, search for the same photo, and import it again. The plugin returns the existing attachment rather than adding a duplicate to the library.

### Test The Media Picker Tab

9. Open a post in the block editor.
10. In the sidebar, open the **Featured image** panel and select **Set featured image**. The standard WordPress media picker opens.
11. Select the **Photo Directory** tab at the top of the picker, next to **Upload files** and **Media Library**.
12. Search for a photo and select **Import**. The card is marked **Selected**, and the picker returns to its **Media Library** view with the new photo highlighted.
13. Select **Set featured image** in the picker. The picker closes and the photo appears in the **Featured image** panel.
14. Publish or update the post, then view it on the front end to confirm the featured image displays.

### Test The Sidebar Panel

15. Still in the block editor, find the **Photo Directory** panel in the sidebar and select **Import a photo…**.
16. Import a photo in the pop-up window, then select **Use as featured image**. The pop-up closes and the featured image is replaced.

After testing, any unwanted photos can be removed from **Media > Library** the same way as any other attachment.

---
---

# Troubleshooting Guide

Internal reference for technical support. Every issue below has been traced to the plugin's own code paths and verified against version 1.1.0 running on WordPress 7.1 and PHP 8.2.

## Problem: Every Imported Photo Is Named "Photo Detail" And Has The Same Alt Text

### Cause

This is expected behavior with the current upstream data, not a broken install. The Photo Directory REST API returns `title.rendered` as the literal string `Photo detail` for every record. That value is the label of the photo's web page, not the photo's name. The API also returns an empty `alt_text` on the embedded media object, so `PDI_API::normalize_item()` falls back to the title, and the same generic string is written to `_wp_attachment_image_alt`.

The photo's real descriptive text is present in `content.rendered`, and the plugin already saves it as the attachment's description and caption.

### Solution

1. Confirm the description field on the attachment holds a real sentence. If it does, the import worked correctly and only the title and alt text need attention.
2. Advise the site owner to set the **Title** and **Alternative Text** on each imported attachment. The saved description is a good source for both.
3. For a permanent fix, `normalize_item()` in `includes/class-pdi-api.php` would need to derive the title and alt text from `content.rendered` rather than from `title.rendered`. Note that alt text of `Photo detail` is worse for screen reader users than no alt text at all, so this is worth prioritizing on accessibility grounds.
4. To find affected attachments already in a library, query for attachments with the `_pdi_imported` meta key.

## Problem: The Photo Directory Page, Tab, Or Button Does Not Appear

### Cause

Every entry point is gated on the `upload_files` capability. The **Media > Photo Directory** page, the classic editor button, the block editor assets, and both AJAX handlers all check it. Accounts below Author level, or roles with the capability removed by another plugin, will not see the tool. A second possibility is that the plugin is installed but not active.

### Solution

1. Confirm **WP Photo Directory Importer** is listed as active under **Plugins > Installed Plugins**.
2. Confirm the reporting user's role includes **Upload Files**. Administrator, Editor, and Author roles include it by default.
3. If a role editor plugin is in use, check whether `upload_files` has been removed from the affected role.
4. If only the classic editor button is missing, check whether the site actually uses the classic editor. The button prints on the `media_buttons` hook, which the block editor never fires. On a block editor site this button is unreachable by design.

## Problem: The Photo Directory Tab Is Missing From The Media Picker

### Cause

The tab is added by `assets/js/media-modal.js`, which patches `wp.media.view.MediaFrame.Select` and `wp.media.view.MediaFrame.Post`. It is enqueued only on `post.php` and `post-new.php`, and it depends on both `pdi-admin` and WordPress's own `media-views` script. The file also guards itself: if `wp.media`, `wp.media.view.MediaFrame`, or `window.PDI` is unavailable when it runs, it exits without doing anything.

### Solution

1. Confirm the screen is a post edit screen. The tab is not added on other admin screens, including **Media > Library**.
2. Open the browser console and look for the warning `WP Photo Directory Importer: could not add media modal tab.` That message means `wp.media`'s internals changed shape and the patch was skipped. The plugin fails quietly here on purpose, so the rest of the media picker keeps working.
3. Confirm `media-modal.js` and `admin.js` both load on the page. Both are required.
4. If a custom media frame is in use, check whether it extends `MediaFrame.Select` or `MediaFrame.Post`. Frames built from other base classes will not receive the tab.

## Problem: The Search Grid Shows "Something Went Wrong. Please Try Again."

### Cause

This is the generic error string used when the AJAX request fails, when the server returns a non-200 response, or when the browser request itself is rejected. The most common root causes are a blocked outbound request, an expired security token, or a network timeout. The plugin allows 15 seconds for a search request.

### Solution

1. Ask the user to reload the admin page and try again. A stale `pdi_nonce` returns a failure that clears on reload.
2. Open the browser network tab and inspect the `admin-ajax.php` request with `action=pdi_search`. The response body carries the real message.
3. A response of `-1` with HTTP 403 is a rejected security token. A JSON body with a permission message is a capability problem; see the section above.
4. If the response reports an HTTP status from the Photo Directory, the upstream API returned an error. Confirm the site can reach `https://wordpress.org/photos/wp-json/wp/v2/photos` from the server, not just from the browser.
5. Check whether the site defines `WP_HTTP_BLOCK_EXTERNAL` in `wp-config.php`. If it does, add `wordpress.org` to `WP_ACCESSIBLE_HOSTS`.
6. Check for a firewall, proxy, or security plugin that blocks outbound requests from PHP.

## Problem: The Search Returns "No Photos Found." For A Term That Should Match

### Cause

The plugin passes the search term straight to the upstream API and reports whatever comes back. It also caches each search response in a transient for five minutes, keyed on the search term, page number, and results per page. A search run during a brief upstream outage can return an empty result that is then served from the cache for up to five minutes.

### Solution

1. Wait five minutes and repeat the search, or clear the site's transients to expire the cached result immediately.
2. Compare the result against a direct browser request to `https://wordpress.org/photos/wp-json/wp/v2/photos?search=TERM`. A matching empty result confirms the upstream library has no photos for that term.
3. Note that the plugin searches only the Photo Directory's own text fields. It does not query the Photo Directory's tags or categories.

## Problem: Thumbnails Are Blank Or Imports Fail With "No Downloadable Image Was Found For This Photo."

### Cause

The Photo Directory REST API is public but not formally documented, so its response shape can change. `PDI_API::normalize_item()` in `includes/class-pdi-api.php` checks four possible locations for image size data, in order:

1. `media_details.sizes` on the item itself
2. `media_details.sizes` on the embedded `wp:featuredmedia` object, then that object's bare `source_url`
3. A top-level `sizes` field on the item
4. A bare `source_url` on the item

When none of these produce a URL, the photo has no thumbnail in the grid, and an import attempt returns the "no downloadable image" error.

### Solution

1. Request a live response from `https://wordpress.org/photos/wp-json/wp/v2/photos?_embed=1` and inspect the JSON structure.
2. Compare that structure against the four checks in `normalize_item()`.
3. If the API has moved its image data to a new location, add a matching check to `normalize_item()`. This is the single function that maps upstream JSON into the `sizes`, `alt`, and `author` fields the rest of the plugin uses.
4. If only some photos are affected, the issue is limited to those upstream records rather than the API shape. Import a different photo to confirm.

## Problem: An Import Starts But Never Completes

### Cause

The plugin requests the largest size the API offers, which can be a very large file. It allows 20 seconds to fetch the photo's details and 30 seconds to download the file. Slow connections, low PHP memory limits, or a low `max_execution_time` can cut the process short. A failed download leaves no attachment behind, since the plugin removes the temporary file when sideloading fails.

### Solution

1. Check the server error log for a PHP fatal error, memory exhaustion, or a timeout during the import attempt.
2. Confirm `wp-content/uploads` is writable by the web server user.
3. Raise `memory_limit` and `max_execution_time` if the log points to either limit.
4. Retry the import with a smaller photo to confirm whether file size is the deciding factor.

## Problem: The Imported File Is Smaller Than The Original Photo

### Cause

This is standard WordPress behavior, not a plugin fault. The plugin requests the full-size file. WordPress then applies its own large-image threshold, which is 2560 pixels on the longest side by default, and stores a scaled copy as the main attachment file. In a verified import, a 2873 by 2154 original was stored as 2560 by 1919.

### Solution

1. Confirm the attachment metadata includes an `original_image` value. When it does, the untouched original is on disk next to the scaled copy, and nothing was lost.
2. To keep full-resolution files as the main attachment, return `false` from the `big_image_size_threshold` filter. Note this affects every upload on the site, not only Photo Directory imports.

## Problem: A Photo Imports Twice Instead Of Reusing The Existing Attachment

### Cause

Duplicate protection relies on a `get_posts()` lookup against the `_pdi_source_id` attachment meta key. If that meta value was removed, changed, or lost during a migration, the plugin treats the photo as new and downloads it again.

### Solution

1. Query the `postmeta` table for `_pdi_source_id` and confirm the value matches the upstream photo ID.
2. If the meta is missing on a previously imported attachment, re-adding `_pdi_source_id` with the correct photo ID restores duplicate detection for that photo.
3. Remove any extra copies from **Media > Library**.

## Problem: The "Use As Featured Image" Button Is Missing

### Cause

That button appears only in the plugin's own pop-up window, and only when the window was opened with a post to attach to. Three cases explain every report:

- On the **Media > Photo Directory** page, the button is correctly absent, because that page is not tied to a post.
- Inside the media picker tab, the button is replaced by the picker's own **Set featured image** button. An imported card shows **Selected** instead.
- In the classic editor and block editor pop-up windows, the button should be present.

### Solution

1. Confirm which entry point the user opened, and compare it against the three cases above.
2. If the button is missing from the classic or block editor pop-up window, check the browser console. In the classic editor the action depends on `wp.media.featuredImage`; in the block editor it depends on the `core/editor` data store.

## Problem: A Deprecation Warning Appears In The Console On Post Edit Screens

### Cause

`assets/js/block-editor.js` builds the sidebar panel with `wp.editPost.PluginDocumentSettingPanel`. WordPress marks that export as deprecated from version 6.6 onward, and WordPress 7.1 logs a deprecation notice through `deprecateSlot()`. The export still forwards to the current API, so the panel renders normally.

### Solution

1. Confirm the panel still appears and works. The warning alone does not break anything today.
2. To clear the warning and guard against future removal, change the import to `wp.editor.PluginDocumentSettingPanel` and update the script's registered dependency from `wp-edit-post` to `wp-editor`.

## Known Documentation Gaps In The Plugin Package

These affect the plugin's own bundled files, not site behavior. They are worth correcting before any public release.

| File | Issue |
|---|---|
| `readme.txt` | The **Entry points** list names three entry points. The media picker tab added in 1.1.0 appears only in the changelog. |
| `README.md` | Same three-entry-point list, and the file responsibility table omits `assets/js/media-modal.js`. |
| `wp-photo-directory-importer.php` | The **Plugin URI** and **Author URI** headers still contain the placeholder `your-username`. |

## Additional Resources

- WP Photo Directory Importer User Guide
- WP Photo Directory Importer Feature Overview
- WordPress Photo Directory: `https://wordpress.org/photos`
- Upstream API endpoint: `https://wordpress.org/photos/wp-json/wp/v2/photos`
- Key files: `includes/class-pdi-api.php`, `includes/class-pdi-importer.php`, `includes/class-pdi-plugin.php`, `assets/js/media-modal.js`

---
---

# Feature Overview

## Overview

The WP Photo Directory Importer brings the WordPress Photo Directory into the WordPress admin. The Photo Directory is a community photo library hosted at wordpress.org/photos, and every photo in it is released under the CC0 license. CC0 photos can be used for any purpose, including commercial work, with no license fee and no required credit.

The plugin lets site editors search that library and add photos to their own site without visiting an external site, downloading a file, and uploading it again. Imported photos become standard Media Library items and behave like any other uploaded image.

The plugin is free, requires no account, and requires no API key.

## Target User

The plugin fits teams that publish regularly and need supporting imagery without a stock photo budget or a licensing review step. That includes:

- **Content and editorial teams** who add images to posts on a weekly or daily rhythm and want a source that is cleared for use in advance.
- **Small businesses and solo site owners** who maintain their own sites and do not have a photographer or a paid image subscription.
- **Agencies and site builders** who need placeholder or supporting imagery during a build, with no risk of a licensing problem carrying into launch.
- **Nonprofits, schools, and community organizations** working with limited budgets.
- **WordPress community sites** that prefer to source material from the WordPress project's own ecosystem.

The plugin is a strong fit for sites where the barrier to good imagery is process and licensing rather than design skill. It fits less well for sites that need highly specific subject matter, since the Photo Directory is a community library rather than a commercial catalog.

## How It Works

The clearest way to see the feature is through the standard WordPress image picker. When someone sets a featured image or adds an image to a page, the familiar picker window opens with its usual **Upload files** and **Media Library** tabs. This plugin adds a third tab: **Photo Directory**.

Selecting that tab turns the picker into a photo search. Typing a word returns a grid of matching photos from the community library, loading more on request. Selecting **Import** on a photo saves it to the site's own Media Library and hands it straight back to the picker with the photo already chosen. Finishing the job uses the same button the picker always had. The photo is now an ordinary library item, so it behaves from that point on exactly like a photo that had been uploaded weeks ago.

For browsing rather than picking, a dedicated page under the **Media** menu shows the same search on its own. Two more entry points sit inside the post editor for people who prefer to work from the sidebar.

Every import records where the photo came from and who uploaded it, so credit can be added when a site chooses to give it. Importing the same photo twice reuses the copy already in the library, which keeps things tidy.

## Key Benefits

- **A free, pre-cleared image source.** Every photo on the WordPress Photo Directory is CC0, so it can be used commercially with no license fee and no required attribution.
- **It appears inside the picker people already use.** The **Photo Directory** tab sits in the standard WordPress image picker, so finding a photo happens in the same window and the same moment as choosing one. There is no separate trip to a search page and back.
- **Four entry points that match how people work.** The media picker tab, a standalone Media page for browsing, and in-editor access from both the classic editor and the block editor.
- **A workflow contained inside WordPress.** Search, preview, and import happen in the admin, replacing the visit-download-upload cycle that an external stock site requires.
- **Real Media Library attachments.** Imported photos are ordinary attachments, so they work with existing themes, page builders, image optimization plugins, and CDNs with no special handling.
- **Photo descriptions carried over.** Each photo's descriptive sentence is saved with the attachment, which gives editors a starting point for titles, captions, and alt text.
- **Duplicate protection.** Repeat imports of the same photo reuse the existing attachment instead of adding another copy.
- **Source tracking on every import.** The original photo address and the uploader's name are stored with the attachment, so credit remains available even though CC0 does not require it.
- **No account, no key, no setup screen.** The plugin works as soon as it is activated.
- **Complements existing media tools.** The plugin adds a source of images. It does not replace the Media Library, image optimization plugins, or a digital asset manager, and it runs alongside them.
- **Audience reach.** WordPress powers a large share of the web, and the Photo Directory is maintained by the WordPress community itself, which makes it a natural fit for sites already invested in that ecosystem.

### Note For Planning

Photo titles and alt text currently arrive as a generic placeholder from the Photo Directory's public data, so editors set those two fields by hand after import. The photo's description carries over correctly. Marketing material should avoid claiming that imported photos are fully labelled on arrival until this is addressed.
