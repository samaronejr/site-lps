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
5. **Tight-viewBox SVG crops** — for any logo/lockup SVG that uses a `viewBox` to crop a larger artwork (e.g. `lps-coppe-blue-lockup.svg`), verify the crop rectangle actually contains the paths: load the SVG in playwright and run `getBBox()` on each `<path>` — any path extending past the viewBox min/max is visibly clipped (the `108 160 1600 780` lockup once severed the tagline text and letterform bottoms). Also render the SVG standalone and screenshot it — crop defects are obvious at full size.
6. **Route names** — the EN identity page is `/en/visual-identity/` (NOT `/en/identity/` — that 404s; all internal links use the former).

Playwright recipe: `const { chromium } = createRequire('/home/ubuntu/repos/site-lps/package.json')('playwright-core');` then `chromium.launch({ executablePath: '/home/ubuntu/.local/bin/google-chrome' })` — playwright-core ships in repo node_modules; run scripts from /tmp via createRequire since bare import resolution walks up from the script dir.

## WordPress staging site (runs locally via Playground)

A full WP staging site CAN run on this box via `scripts/deploy/staging.mjs` (WordPress Playground, PHP 8.3, SQLite):

```bash
export PATH=/tmp/node-v24.20.0-linux-x64/bin:$PATH   # REQUIRED — a serve spawned without it dies ("nohup: failed to run command 'node'") and the edge keeps listening but 502s everything
node /home/ubuntu/repos/site-lps/scripts/deploy/staging.mjs serve   # (re)starts origin :8927 + TLS edge :8443 idempotently
```

- Public URL `https://127.0.0.1:8443` (self-signed — playwright needs `ignoreHTTPSErrors: true`); edge proxies to origin `http://127.0.0.1:8927`. Live release = `.omo/staging/current` symlink → `.omo/staging/releases/<id>/`. Shared SQLite DB at `.omo/staging/ops/db/.ht.sqlite` (query with python3 `sqlite3` — no sqlite3 CLI installed).
- If `8443` returns 502 instantly: the origin died; run `serve` again. `.omo/staging/ops/logs/origin.log` shows boot/serve history.
- **Admin login (user `samarone`, administrator role)**: password is a provisioned secret (`LPS_STAGING_PASSWORD` via `env` binding). TOTP is enforced: after `/pt-br/entrar/` creds POST, the stock `wp-login.php?action=validate_2fa` page asks `authcode`. If the saved TOTP secrets are malformed, read the seed from the DB (`SELECT meta_value FROM wp_usermeta WHERE user_id=2 AND meta_key='_two_factor_totp_key'` — base32), generate the 6-digit code with HMAC-SHA1 TOTP in-process; use it and discard (never log the seed). Deterministic path: do the whole flow via `context.request` POSTs — the entrar creds POST returns the 2FA form INLINE (200, not a redirect); parse its hidden inputs (provider/wp-auth-id/wp-auth-nonce) then POST authcode to `wp-login.php?action=validate_2fa`. Browser-context cookies then authenticate page visits; save with `ctx.storageState`.
- **Edge denylist gotcha**: `scripts/deploy/edge.mjs` `denied()` 403s any URL containing `/includes/`, `/tests/`, `debug`, `.log/.sql/.sqlite` — this catches legitimate plugin assets like `two-factor/includes/qrcode-generator/qrcode.js` (403 on profile.php). `/wp-includes/` is NOT affected (segment match).
- **Theme CSP**: `lps-content-model/includes/class-hardening.php` sends a nonced CSP (`script-src 'self' 'nonce-…'`) on ALL responses incl. wp-admin — WP core inline scripts (userSettings, _wpColorScheme, no-js→js swap) are blocked by design; an `admin_globals()` shim restores ajaxurl/pagenow only. Expect ~10 "Refused to execute inline script" console lines per admin page.
- Post-login landing is stock `/wp-admin/`; the themed member area is `/pt-br/painel/` (+ `/en/dashboard/`, `/pt-br/painel/revisao/`, `/pt-br/painel/nova-oferta/`). `/pt-br/area-do-professor/` 302s into painel.
- Desktop header renders the disclosure nav panel permanently open (vertical nav column + tools) — `.lps-masthead-nav` is never emitted by the shell; check whether that's still intended vs DESIGN.md's "navigation row".
- `scripts/check-theme.mjs` exists as a static gate (CSS-referenced fonts exist).
