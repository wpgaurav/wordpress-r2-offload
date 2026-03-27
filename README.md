# WordPress R2 Offload

Three WordPress mu-plugins that offload static assets to Cloudflare R2. Drop them in, define two constants, and every image, stylesheet, script, and font serves from your R2 bucket.

Pair with the [Cloudflare R2 Pull-Through Cache Worker](https://github.com/wpgaurav/cloudflare-r2-wordpress-cdn) for the complete setup.

## Architecture

```
                         ┌─────────────────────┐
                         │   WordPress Origin   │
                         │                      │
                         │  ┌────────────────┐  │
                         │  │ gt-r2-cdn.php  │──┼──→ Rewrites asset URLs
                         │  └────────────────┘  │    to r2.example.com
                         │                      │
                         │  ┌────────────────┐  │
  Cloudflare Worker ────▶│  │ gt-r2-image-   │──┼──→ Resizes images
  (resize request)       │  │ resizer.php    │  │    on demand
                         │  └────────────────┘  │
                         │                      │
                         │  ┌────────────────┐  │
  Media Library edit ───▶│  │ r2-purge-on-   │──┼──→ Purges stale R2
                         │  │ update.php     │  │    objects
                         │  └────────────────┘  │
                         └─────────────────────┘
```

## Plugins

### 1. `gt-r2-cdn.php` — URL Rewriter

Rewrites all static asset URLs from `example.com` to `r2.example.com`. Hooks into:

- `wp_get_attachment_url` — media library URLs
- `script_loader_src` / `style_loader_src` — enqueued JS and CSS
- `wp_calculate_image_srcset` — responsive image srcset
- `the_content` / `render_block` — inline URLs in post content
- Output buffer — catches anything the filters miss

**Skips:** PHP/HTML files, `wp-content/cache/`, `manifest.webmanifest`, third-party domains.

### 2. `gt-r2-image-resizer.php` — On-Demand Image Resizer

Generates resized image variants when the Cloudflare Worker requests them. The Worker calls:

```
https://example.com/?gt_r2_resize=1&path=wp-content/uploads/2024/01/photo.jpg&w=800&h=600
```

Protected by a shared secret (`X-Resize-Secret` header). Uses WordPress's built-in image editor (GD or Imagick). Falls back to downloading from R2 if the original file isn't on the local filesystem.

### 3. `r2-purge-on-update.php` — Cache Purge

Automatically purges R2 objects when attachments are updated, replaced, or deleted. Also purges AVIF and WebP variants.

Includes a WP-CLI command:

```bash
wp r2-purge 1234    # Purge a single attachment
wp r2-purge all     # Purge all image attachments
```

## Installation

### 1. Upload the mu-plugins

Copy all three `.php` files to `wp-content/mu-plugins/`:

```bash
scp gt-r2-cdn.php gt-r2-image-resizer.php r2-purge-on-update.php \
  user@server:/var/www/example.com/wp-content/mu-plugins/
```

### 2. Add constants to `wp-config.php`

```php
/** Cloudflare R2 CDN */
define( 'R2_CDN_BASE', 'https://r2.example.com' );
define( 'R2_CDN_HOST', 'r2.example.com' );
define( 'R2_PURGE_SECRET', 'your-purge-secret-here' );
define( 'GT_R2_RESIZE_SECRET', 'your-resize-secret-here' );
```

### 3. Deploy the Worker

Set up the companion [Cloudflare Worker](https://github.com/wpgaurav/cloudflare-r2-wordpress-cdn) to handle R2 requests.

## How the Lazy Migration Works

There's no bulk upload step. When a visitor requests an asset:

1. The **URL rewriter** sends the browser to `r2.example.com/path/to/file.css`
2. The **Cloudflare Worker** checks R2 — miss on first request
3. The Worker fetches from your origin, stores in R2, and serves the response
4. Next request is an R2 hit — origin is never contacted again

Your R2 bucket fills itself over days as real traffic flows. High-traffic assets get cached first, which is exactly what you want.

## wp-config.php Constants Reference

| Constant | Used By | Description |
|---|---|---|
| `R2_CDN_BASE` | URL rewriter, image resizer | Full CDN URL (`https://r2.example.com`) |
| `R2_CDN_HOST` | Cache purge | CDN hostname only (`r2.example.com`) |
| `R2_PURGE_SECRET` | Cache purge | Bearer token for the Worker's `/_purge` endpoint |
| `GT_R2_RESIZE_SECRET` | Image resizer | Shared secret sent in `X-Resize-Secret` header |

## Related

- [Cloudflare R2 Pull-Through Cache Worker](https://github.com/wpgaurav/cloudflare-r2-wordpress-cdn) — the Cloudflare Worker that serves assets from R2
- [Full setup guide on gauravtiwari.org](https://gauravtiwari.org/cloudflare-r2-wordpress/)

## License

MIT
