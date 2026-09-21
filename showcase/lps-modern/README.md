# LPS Modern showcase

Static, dependency-free preview of the Portuguese homepage rendered by the
`lps-modern` WordPress theme before reviewed CMS records exist. Reference:
FEEC/Unicamp (`fee.unicamp.br`) for section grammar — hero, journey cards,
research grid, about band, people, infrastructure, partners, CTA — rebuilt with
LPS content and the laboratory's own horizontal logo lockups.

## Run it

```bash
PORT=4177 node showcase/lps-modern/server.mjs
```

Then open `http://127.0.0.1:4177/`. No build step, no external request: fonts,
logos, and icons are all local files under `assets/`.

## Provenance

- `styles.css` is a copy of
  `wp-content/themes/lps-modern/assets/css/theme.css` with only the font path
  rewritten (`../fonts/` → `assets/fonts/`). The theme stylesheet is canonical;
  re-copy after any token change.
- `assets/lps-logo-*.svg` are copies of the horizontal lockups in
  `wp-content/themes/lps-modern/assets/img/logo/` (see `RIGHTS.md` there).
- Every fact on the page is curated from `content/corpus/records/` and the
  scrape report `docs/content/lps-google-sites-scrape-2026-09-21.md`. Nothing
  here is a reviewed publication — the theme replaces each curated block with
  reviewed CMS records as soon as they exist.
