# Performance hosting and CDN requirements

Owner: Deployer role. Scope: plan Todo 21 (cache, media, rendering, and Core Web Vitals budgets).
This document is a hosting contract: the theme and plugin already emit the correct cache and
media semantics, and the host must honour them for the field targets to hold.

## 1. Field and payload targets

| Target | Limit | Enforced by |
| --- | --- | --- |
| JavaScript, compressed | <= 120 KiB | `tests/js/performance-budget.test.mjs` |
| CSS, compressed | <= 50 KiB | same |
| Fonts | <= 100 KiB | same |
| Hero image | <= 200 KiB | same |
| Total initial payload | <= 750 KiB | same |
| Mobile P75 LCP | <= 2.5 s | Lighthouse gate + field RUM |
| INP | <= 200 ms | Lighthouse gate (TBT proxy) + field RUM |
| CLS | <= 0.1 | Lighthouse gate + field RUM |

Budgets are measured as **brotli transfer bytes** for text assets and raw bytes for fonts and
images. The host must therefore serve brotli (`Content-Encoding: br`) with gzip fallback; a host
without brotli invalidates the measured budget.

## 2. Required response handling

The site emits these headers on every public response (see
`wp-content/themes/lps-theme/includes/class-cachepolicy.php`):

- `Cache-Control: public, max-age=0, s-maxage=<ttl>, stale-while-revalidate=60` for anonymous HTML
  (`s-maxage=300`), anonymous query/facet/search states (`s-maxage=60`), and localized 404s
  (`s-maxage=60`).
- `Cache-Control: private, no-store, max-age=0` for everything personalized: authenticated
  requests, any `wordpress_logged_in_*` / `wordpress_sec_*` / `comment_author_*` / `wp-postpass_*`
  cookie, `/wp-admin/`, `/wp-login.php`, `/wp-cron.php`, `/wp-json/wp/v2/users`, previews, nonce
  URLs, unsafe methods, and any non-200/404 status.
- `Vary: Accept-Encoding` on shareable responses; `Vary: Accept-Encoding, Cookie` on private ones.
- `Surrogate-Key: lps-html lps-locale-<pt-br|en>` and `Surrogate-Control: max-age=<ttl>` on
  shareable responses.
- `X-LPS-Cache-Policy: <reason>` for operational debugging; the reason is one of
  `anonymous-html`, `anonymous-query`, `not-found`, `authenticated`, `private-request`, `preview`,
  `unsafe-method`, `error-status`, `unapproved-query`.

The CDN or reverse proxy **must**:

1. Honour `s-maxage` and `stale-while-revalidate`; never extend TTL beyond the emitted value.
2. Never store a response carrying `private` or `no-store`. Admin, login and authenticated HTML
   must bypass the shared cache entirely.
3. Include cookies in the cache key only as a bypass signal: presence of any WordPress
   authentication cookie must bypass, not fragment, the cache.
4. Strip unknown query parameters from the cache key, or bypass them. The application already
   refuses to mark unapproved query strings (for example `utm_*`) as cacheable, so campaign links
   must not multiply cache entries.
   Approved keys: `q`, `record`, `category`, `year`, `type`, `status`, `domain`, `area`, `page`.
5. Support surrogate-key (tag) purging. Key-based purge is what keeps publish invalidation narrow.

## 3. Publish invalidation

On every publish, unpublish, status change or delete, the theme computes the exact affected public
URLs (`CachePolicy::purge_targets()`) and fires:

```php
do_action( 'lps_cache_purge', array $targets, WP_Post $post );
```

`$targets` contains, canonically and sorted: the record permalink, its translated permalinks, its
post-type archive, its taxonomy archives, both locale homes, both locale search entries
(`/pt-br/busca/`, `/en/search/`), and `/sitemap.xml`. It never contains an admin URL, a login URL,
or a query string. The last batch is stored in the `lps_cache_last_purge` option for auditing.

Hosts wire their purge API to that action, for example:

```php
add_action( 'lps_cache_purge', function ( array $targets ): void {
    wp_remote_post( 'https://cdn.example/purge', array(
        'headers' => array( 'Authorization' => 'Bearer ' . LPS_CDN_TOKEN ),
        'body'    => wp_json_encode( array( 'urls' => $targets ) ),
    ) );
} );
```

Requirement: purge must complete within 60 seconds of publish, and must be scoped to the supplied
URLs (or the `lps-html` / `lps-locale-*` surrogate keys). A full-site flush on every publish is a
defect, not an acceptable implementation.

## 4. Static asset delivery

- `wp-content/themes/lps-theme/assets/**` is immutable per release: serve
  `Cache-Control: public, max-age=31536000, immutable`. Cache busting is the theme version query
  string that WordPress appends.
- Fonts are self-hosted, subset WOFF2. The approved payload is the four vendored faces
  (`inter-regular.woff2`, `inter-semibold.woff2`, `jetbrains-mono-regular.woff2`,
  `space-grotesk-semibold.woff2`, 81 KiB combined); only `inter-regular.woff2` and
  `space-grotesk-semibold.woff2` are preloaded for the first paint. Preloads carry `crossorigin`, so the host must send
  `Access-Control-Allow-Origin` for font requests if assets are served from a separate origin.
  No third-party font service may be introduced.
- Regenerating the subsets after a font upgrade:

  ```sh
  uv run --with "fonttools[woff]" pyftsubset <family>-<weight>.ttf \
    --output-file=<family>-<weight>.woff2 --flavor=woff2 \
    --layout-features="kern,liga,clig,calt,ccmp,locl,mark,mkmk" \
    --unicodes="U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD" \
    --no-hinting --desubroutinize
  ```

  The subset covers Latin-1 plus the Portuguese diacritic set; the OFL licence file ships beside it.
- Images: the media pipeline emits `<picture>` with AVIF, then WebP, then the stored original, all
  sharing one `sizes` value and the `<img>` intrinsic `width`/`height`. The host must serve
  `image/avif` and `image/webp` with correct `Content-Type` and must not re-encode or strip
  `<source>` negotiation. Only the single true LCP image is `fetchpriority="high"` and `loading="eager"`;
  everything else is `loading="lazy"`.

## 5. Origin requirements

- HTTP/2 or HTTP/3 with connection coalescing for the asset origin.
- TLS session resumption enabled; the preloaded fonts must not pay a second handshake.
- Origin response time (TTFB) for anonymous HTML under 200 ms at the 75th percentile, so a cache
  miss still fits the 2.5 s LCP budget on a mobile connection.
- Object cache (Redis or Memcached) for WordPress `wp_cache_*`; the search index and relationship
  queries assume a persistent object cache in production.
- No page-cache plugin may be installed. Caching is a host or CDN responsibility and the
  application already owns the correctness rules; a second cache layer would fragment invalidation.

## 6. Verification

- `npm run test -- tests/js/performance-budget.test.mjs` enforces the payload budgets.
- `tools/composer test --filter PerformancePolicyTest` enforces the cache and asset rules,
  including admin and authenticated non-caching and publish purge scope.
- `scripts/perf/lighthouse-audit.py <url> --label <template> --out <dir> --runs 3` captures the
  real-Chrome Lighthouse medians, network waterfall and Web Vitals per representative template.
