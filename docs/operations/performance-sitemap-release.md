# Sitemap and performance release

## Sitemap routing

Playground injects `/internal/shared/mu-plugins/sitemap-redirect.php`, which redirects
`/sitemap.xml` to `/wp-sitemap.xml` at MU-plugin load time, before the theme can
dispatch machine documents. The theme intentionally disables core sitemaps to
enforce publication, translation and privacy gates. The combination produces a
redirect to a disabled endpoint (and a cached 404 on some deployments).

The edge maps the sitemap index and locale sitemap paths to the theme's
`lps_seo_document` query endpoint. `/wp-sitemap.xml` is an alias for the governed
index. Public URLs, robots.txt and surrogate-key purge targets remain unchanged.
Locale maps include governed institutional pages as well as archives and records;
English page keys resolve through their Portuguese authority.
Never enable core sitemaps as a workaround: they bypass the governed index.

## Transport and assets

The TLS listener negotiates HTTP/2 with HTTP/1.1 fallback; port 8080 still redirects.
Origin requests explicitly ask for identity encoding. The shared cache stores only
identity bodies, so its keys and surrogate purges are independent of transport
encoding. On MISS, HIT and STALE, the edge negotiates Brotli/gzip per response,
merges `Vary: Accept-Encoding`, and recalculates Content-Length. Range responses,
already encoded bodies, and no-transform responses are not recompressed.

`scripts/build.mjs` minifies CSS with Lightning CSS. Exclusively scoped dashboard,
intranet and editorial-preview rules are removed from the public sheet and shipped
in `theme-private.css`, loaded for signed-in users. Public styles are inlined to
remove a render-blocking round trip, without script-dependent deferred styling.
Inline URLs are rebased to the theme assets directory, preserving fonts and decoration.
The readable source remains `wp-content/themes/lps-theme/assets/css/theme.css`;
all payload budgets are unchanged.

Portrait derivatives live in `content/media/uploads/people/`, not in the import
package: `media: []` is unchanged. Each original has 112/400/800px-wide JPEG and
WebP derivatives, preserving its aspect ratio. `<picture>` supplies WebP and JPEG
fallbacks, responsive sizes and intrinsic dimensions. Missing derivative sets
fall back atomically to the original. Rights/privacy checks remain at the callers.
The 56px thumbnail and larger hover/profile image have separate size hints; the
card composition and square hover crop are unchanged.

## Anniversary correction after merge — not deployed here

A whole-repository search found no anniversary copy in the corpus, theme,
showcase or tests. The erroneous copy exists only in live native content.
Read-only REST inspection identified post **586**, record
`lps:event:635b04f2-a21c-47c2-9b22-cd8d7b047528`, locale `pt-br`, published slug
`coloquio-40-anos-lps`. Its title must become **Colóquio institucional: 30 anos do LPS**
and its excerpt must say **Sessão comemorativa dos 30 anos do laboratório**.
Do not silently change the immutable published slug; a future URL change needs
an explicit redirect. Review any English translation and re-stamp its source hash
after changing the Portuguese authority. No live database writes were performed.
