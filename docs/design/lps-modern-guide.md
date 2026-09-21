# LPS Modern design guide (Amendment M1)

Working guide for the modern theme (`wp-content/themes/lps-modern/`) and its
static preview (`showcase/lps-modern/`). The binding text is DESIGN.md
Amendment M1; this guide never introduces a value the amendment, `theme.json`,
and `theme.css` do not already define. Verify with `npm run qa:modern`.

## Relationship to Scientific Editorial

`lps-theme` is untouched and stays the fallback. `lps-modern` is a second,
self-contained block theme (own `theme.json`, stylesheet, shell, homepage
renderer, templates, logo set) that shares only the `lps-content-model`
plugin. It reuses the plugin's review gates (`reviewed` state, fresh
`review_date`, locale match, no stale EN, safe record link) so a reviewed
record renders identically from a governance view — only the presentation is
new. Where no reviewed record exists, sections render curated fallback copy
flagged `data-provenance="curated-corpus"`, sourced from
`content/corpus/records/` and
`docs/content/lps-google-sites-scrape-2026-09-21.md`.

## FEEC reference mapping

| FEEC (`fee.unicamp.br`) | LPS Modern | Deviation (deliberate) |
| --- | --- | --- |
| Utility bar + sticky header + CTA | `lpsx-topbar` + sticky blurred `lpsx-header` + `Colabore` button | No machine-translation widget; PT/EN locale control links published variants only |
| Hero banner carousel | Static gradient hero with stats + summary card | No carousel/autoplay: static hero keeps content keyboard-reachable with zero motion |
| “Escolha a sua jornada” (3 cards) | Journeys: Estude / Pesquise / Seja parceiro | Same grammar, LPS routes |
| Research image cards (6) | Research grid (6) with geometric icons | Icons instead of photography until rights-cleared photos exist |
| Labs/services + about bands | Infrastructure checklist band + about/values split | Same split rhythm |
| Vestibular CTA banner | `Vamos construir o próximo sinal juntos` CTA band | Same conversion role, honest copy |
| Links úteis / parceiros / agenda | Partner chips; latest section with honest empty state | No invented news/events; empty state until CMS publishes |
| Rich dark footer | 4-column footer + legal bar, reversed lockup | Same information density |

## Tokens

Palette (`theme.json` + `:root`): `paper` `#ffffff`, `paper-soft` `#f2f5f8`,
`paper-muted` `#e7edf2`, `ink` `#10161c`, `ink-soft` `#45525e`,
`brand-ink` `#0a2f5c`, `brand` `#0d4f9c`, `brand-strong` `#083a75`,
`accent` `#0077a8`, `accent-strong` `#005f86`, `accent-soft` `#e3f2f9`,
`rule` `#d7dee5`, `rule-strong` `#8b98a5`, success/warning/error + washes,
`focus-light` `#8fd8ff` (focus on dark fills only).

Radius: `--radius-sm/md/lg/pill` = 6/12/20/999px. Shadows:
`--shadow-sm/md/lg`. Gradients: `--gradient-hero`, `--gradient-cta`,
`--gradient-bar` — the only gradient functions in the theme, all declared in
`:root`. Type: interface-first headings (750), Source Serif 4 for hero lead and
long-form (`wp-block-post-content`), mono for kickers/metadata. Spacing reuses
the 12-step 4px scale; container `--grid-max` is `76rem`.

Contrast notes: `brand` on white ≈ 8:1; `accent` on white ≈ 5:1 (safe for
text/links); `focus-light` on `brand-ink` ≈ 8.5:1; white body copy on the hero
gradient holds ≥ 4.5:1 at every stop.

## Components

Header: `lpsx-topbar`, `lpsx-header` (sticky, blurred veil), `lpsx-brand`
(compact-lockup `<img>`, decorative, inside a named home link),
`lpsx-menu` (native `details` disclosure; summary becomes a pill button on
mobile and hides on desktop where the panel is always visible), `lpsx-nav`
(current page gets an `accent-soft` pill), `lpsx-search`, `lpsx-locale`.

Homepage (`lps-modern/home` × 10, locked order hero → contact): `lpsx-hero`
with `lpsx-stats` + `lpsx-hero-card`; `lpsx-section` / `lpsx-section-alt` with
`lpsx-section-head` (kicker + H2 + optional lead + archive link);
`lpsx-cards` grids (2/3/4) of `lpsx-card` (icon, tag, linked H3, hover lift +
gradient top bar); `lpsx-split` bands; `lpsx-values`; `lpsx-checklist` (dark
panel); `lpsx-person` with `lpsx-avatar` initials; `lpsx-chips`; `lpsx-note`
(honesty/provenance callouts); `lpsx-empty`; `lpsx-cta`; `lpsx-footer` (identity
+ address + three nav columns + legal bar).

Buttons: `lpsx-btn` + `lpsx-btn-primary|secondary|light|ghost-light`, pill,
44px minimum, 1px press shift (removed under reduced motion).

Icons: project-original 24px geometric SVGs (`ModernHomepage::icon()`),
`currentColor`, 1.75px round caps. No emoji, no third-party icon set, no
waveform ornament outside the logo files.

## Logo usage

Full lockup (`lps-logo-horizontal.svg`, 1020×240) for footer identity, hero
card, about/press figures. Compact lockup (`lps-logo-compact.svg`, 600×200)
for the header home-link. Reversed variants on `brand-ink`/hero/CTA dark
fills only; mono (`currentColor`) where chrome must inherit text color. Header
and footer instances are `<img alt="">` inside links that carry the accessible
name. Clear space ≥ cap height of `LPS`; below 160px display width use
`lps-waveform-symbol.svg` alone. Full rules: `RIGHTS.md` in the logo
directory.

## Responsive, motion, print

One column at 375px; two-column cards/splits at 48rem; full header row, hero
split, and 3/4-column grids at 64rem; no page-level horizontal scroll at 320px
or 200% zoom. Motion is state-only (hover/focus/press + 2px card lift);
`prefers-reduced-motion` collapses all durations and removes the lift. Print
hides topbar/menu/CTA/footer/forms and flattens dark bands to paper.

## Changing the system

1. Amend DESIGN.md Amendment M1 first (role, value, usage rule).
2. Add the token to `lps-modern/theme.json`, consume it in `lps-modern`
   `theme.css`.
3. Re-copy `showcase/lps-modern/styles.css` from the theme stylesheet
   (font-path rewrite only) and the changed SVGs into `showcase/lps-modern/assets/`.
4. Run `npm run qa:modern`, `npm run test -- tests/js/lps-modern.test.mjs`,
   `npm run lint`, and `node tests/docs/docs-checker.mjs` when docs change.

Never type a raw color, gradient, radius, shadow, font, or duration into a
template or section renderer; the modern gate rejects it.
