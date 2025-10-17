# Elementor CSS Regenerator

**Requires:** WordPress 5.0+, Elementor, PHP 7.4+

## The Problem

When Elementor updates or when you manually clear Elementor's CSS cache, all generated CSS files in
`/wp-content/uploads/elementor/css/` are deleted. This creates a critical issue with cached pages:

1. **Cached pages** still reference the old CSS files (e.g., `post-71783.css?ver=1760646381`)
2. **Files don't exist** anymore - they were deleted by Elementor
3. **CSS won't regenerate** because users are served the cached HTML (WordPress never runs)
4. **Users see broken pages** until cache expires or is manually cleared

This is especially problematic with:

- Page caching plugins (W3 Total Cache, WP Rocket, etc.)
- CDN caching (Cloudflare, KeyCDN, etc.)
- Server-level caching (Varnish, Nginx FastCGI)
- Template-based pages (one template = hundreds of cached pages affected)

## The Solution

This plugin intercepts 404 requests for missing Elementor CSS files and:

1. **Detects** the missing CSS file request
2. **Regenerates** the file using Elementor's API
3. **Serves** the file immediately to the user
4. **Caches** the regenerated file for subsequent requests

**Result:** First visitor after cache clear gets their CSS generated on-demand. No broken pages, no manual cache
clearing needed.

## How It Works

### Request Flow

```
Cached Page Loads
    ↓
References: post-71783.css?ver=1760646381
    ↓
File Doesn't Exist (404)
    ↓
Plugin Intercepts (before 404 template)
    ↓
Validates Post & Elementor Usage
    ↓
Regenerates CSS File
    ↓
Serves File with Proper Headers
    ↓
User Receives CSS (No Broken Styles!)
    ↓
Subsequent Requests: File Exists (Served by Web Server)
```

### Technical Details

- **Hook:** `template_redirect` (priority 1, before 404 template loads)
- **Detection:** Fast `strpos()` check eliminates non-Elementor 404s, then minimal regex on filename only
- **Query Strings:** Automatically strips cache-busting parameters like `?ver=1760646381`
- **Race Conditions:** Uses WordPress transients to prevent duplicate regeneration
- **Validation:** Checks post exists and is built with Elementor before regenerating
- **Error Handling:** Logs errors when WP_DEBUG is enabled

### Supported File Types

- `post-{id}.css` - Individual page/post CSS
- `loop-{id}.css` - Loop template CSS (also uses Post class internally)

## Installation

### Method 1: Direct Upload

1. Download the `elementor-css-regenerator` folder
2. Upload to `/wp-content/plugins/`
3. Activate via WordPress Admin > Plugins

### Method 2: ZIP Install

1. Zip the `elementor-css-regenerator` folder
2. WordPress Admin > Plugins > Add New > Upload Plugin
3. Activate the plugin

## Requirements

- WordPress 5.0 or higher
- Elementor (free or Pro)
- PHP 7.4 or higher

## Compatibility

Works with all caching solutions:

- ✅ W3 Total Cache
- ✅ WP Rocket
- ✅ WP Super Cache
- ✅ LiteSpeed Cache
- ✅ Cloudflare APO
- ✅ KeyCDN
- ✅ Varnish
- ✅ Nginx FastCGI Cache
- ✅ Server-level caching

## Performance Impact

- **First request:** ~100-500ms delay while CSS regenerates (one-time per file)
- **Subsequent requests:** Zero impact (file exists, served by web server)
- **Lock mechanism:** Prevents multiple simultaneous regenerations
- **No database queries:** Only runs on actual 404s for CSS files

## Frequently Asked Questions

### Does this replace Elementor's CSS regeneration?

No, this complements it. Elementor still handles CSS generation normally. This plugin only activates when a CSS file is
missing and requested.

### Will this slow down my site?

Only the first request to a missing CSS file experiences a small delay. After regeneration, files are served normally by
your web server/CDN.

### Do I need to clear my cache after Elementor updates?

No, that's the point! This plugin eliminates the need to clear cache after Elementor CSS clears.

### What about template-based pages?

Works perfectly. When a cached page using a template is visited, both the page CSS and template CSS are regenerated
on-demand.

### Does this work with Elementor Pro?

Yes, works with both free and Pro versions.

### What if my site uses Cloudflare?

Perfect! The plugin regenerates the file, Cloudflare caches it, and all subsequent requests are served from Cloudflare's
edge network.

## Troubleshooting

### CSS files still returning 404

- Verify Elementor is active and loaded
- Check file permissions on `/wp-content/uploads/elementor/css/`
- Enable WP_DEBUG and check error logs

### Files regenerate but styles still broken

- Check if your caching plugin is caching 404 responses
- Verify .htaccess is properly configured to pass 404s to WordPress
- Test with caching disabled to isolate the issue

### Race condition errors

- Increase the transient lock timeout in code (default: 30 seconds)
- Check server resources (high load may cause timeouts)

## Changelog

### 1.0.0 (2025-10-17)

- Initial release
- On-demand CSS regeneration for missing Elementor files
- Support for post and loop CSS files
- Proper HTTP 200 status headers when serving regenerated files
- Performance optimized detection (fast strpos + minimal regex)
- Race condition prevention with transient locks
- Automatic query string handling
- Validation for post existence and Elementor usage
- Comprehensive debug logging when WP_DEBUG enabled
- Works with all major caching solutions

## License

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html

This plugin is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License
as published by the Free Software Foundation.
