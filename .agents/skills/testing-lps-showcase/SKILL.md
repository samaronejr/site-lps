---
name: testing-lps-showcase
description: How to run and adversarially test the institutional-redesign static showcase (server, routes, known-brittle areas: regenerated PNG assets and EN anchor links).
---

# Testing the LPS institutional-redesign showcase

## Run it

```bash
export PATH=/tmp/node-v24.20.0-linux-x64/bin:$PATH   # node lives here on this box
node /home/ubuntu/repos/site-lps/showcase/institutional-redesign/server.mjs 4173
```

- Serves `showcase/institutional-redesign/public` (committed static output) on `0.0.0.0`, port from `argv[2]` or `PORT` env (default 4173). No build step needed; `npm run build` at repo root is for the WP theme, not the showcase.
- 44 routes, PT (`/…`) + EN (`/en/…`), full list in `public/sitemap-preview.txt`. Zero `<script>` tags — everything is server-rendered static HTML; forms (search, sign-in) are inert.
- Known intentional 404s: `/painel/`, `/en/dashboard/` ("Edit my page" links — the authenticated area is not in the preview). Unknown routes get a styled 404 page.

## Adversarial checks worth re-running after regeneration

These were the brittle spots found on `devin-fix-cloud` — re-verify whenever assets/pages are regenerated:

1. **Anchor fragments** — EN pages link EN fragment names (`#history`, `#areas`, `#graduate`, `#materials`, `#partnerships`) while sections may only carry PT ids (`historia`, `linhas`, `graduacao`, `materiais`, `parcerias`) or prefixed EN ids (`about-history`, `teaching-graduate`, `infra-partners`). Script: for every `href="path#frag"`, resolve `path/index.html` and check `id="frag"` exists. Also confirm in-browser via `window.scrollY` after loading a deep `#frag` URL (broken anchor => scrollY stays 0).
2. **Reconstructed PNG edges** — rasterized mark PNGs may clip: check each PNG's bottom rows for a uniform white band that the source SVG's full-bleed `<rect>` should cover (`icon-maskable-512.png` and `favicon-32.png` had 87/10 clipped rows). Also compare `md5sum` of theme copy vs showcase copy under `wp-content/themes/lps-theme/assets/img/mark/` — they should be identical blobs.
3. **Webmanifest** — `public/assets/img/mark/site.webmanifest` may be orphaned (no `<link rel="manifest">` in HTML) and its `start_url` may point at a non-existent route (`/pt-br/` was used while the real PT root is `/`). Check both.
4. **Fonts** — verify decode+use, not just HTTP 200: `document.fonts.check('16px "Inter"')`, `('600 16px "Space Grotesk"')`, `('16px "JetBrains Mono"')`, plus `performance.getEntriesByType('resource')` filtered for `responseStatus >= 400`.

## WordPress theme

No PHP runtime on this box — the theme is NOT end-to-end testable here; restrict UI testing to the showcase. `scripts/check-theme.mjs` exists as a static gate (CSS-referenced fonts exist).
