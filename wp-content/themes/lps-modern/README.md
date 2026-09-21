# LPS Modern theme

Modern institutional block theme for the Laboratório de Processamento de Sinais
(UFRJ/COPPE). FEEC-inspired marketing surface over the governed LPS content
model: sticky header with horizontal logo lockup, gradient hero with stats,
journey cards, research grid, projects, about/values, people, infrastructure,
latest, partners, CTA band, and a four-column dark footer.

## Activate

Appearance → Themes → **LPS Modern** → Activate. Requirements: WordPress ≥ 6.6,
PHP 8.3, the `lps-content-model` plugin active. No page builder, no external
request, no tracking. The sibling `lps-theme` is never loaded by this theme and
stays available as the instant fallback.

## Layout

| Path | Owns |
| --- | --- |
| `theme.json` | Palette, custom radius/shadow/gradient tokens, spacing, fluid type |
| `assets/css/theme.css` | Every component (canonical stylesheet; the showcase copies it) |
| `assets/img/logo/` | Horizontal lockups + favicons + `RIGHTS.md` provenance record |
| `assets/fonts/` | Self-hosted Source Serif 4 (SIL OFL) |
| `includes/class-modern-shell.php` | Topbar, header, footer, breadcrumbs, metadata, titles, search/archive localization |
| `includes/class-modern-homepage.php` | Ten locked sections, governed CMS features, curated-corpus fallbacks, icons |
| `templates/front-page.html` | The ten `lps-modern/home` blocks in locked order |
| `templates/` + `parts/` | Index, page, single, archive, search, 404, header/footer parts (all locked) |
| `scripts/check-modern.mjs` | Design-system gate (`npm run qa:modern`) |

## Content behavior

Reviewed CMS records always win: a record renders only when it is published,
review-approved, review-current, locale-matched, non-stale (EN), and safely
linked — the same bar as `lps-theme`. Sections without reviewed records render
curated fallback copy from `content/corpus/` and the 2026-09-21 scrape,
flagged `data-provenance="curated-corpus"`; the `latest` section renders an
honest empty state instead of inventing news. No contact route is asserted
beyond the published postal address until governance names one.

## Preview without WordPress

```bash
PORT=4177 node showcase/lps-modern/server.mjs
```

`showcase/lps-modern/` is the static pt-BR homepage. After changing
`assets/css/theme.css`, re-copy it to `showcase/lps-modern/styles.css` with the
font path rewritten (`../fonts/` → `assets/fonts/`), and copy any changed logo
SVG into `showcase/lps-modern/assets/`.

## Checks

```bash
npm run qa:modern
npm run test -- tests/js/lps-modern.test.mjs
npm run lint
```
