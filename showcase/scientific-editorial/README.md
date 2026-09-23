# LPS institutional primitive showcase

Standalone, non-production executable reference for root `DESIGN.md` — the
light institutional, sans-led replacement contract. The token layer in
`styles.css` mirrors `wp-content/themes/lps-theme/theme.json` (the single
source) and `assets/css/theme.css`; the checker verifies that parity.

```bash
node showcase/scientific-editorial/server.mjs
npm run qa:design-system
node showcase/scientific-editorial/scripts/verify-showcase.mjs
```

The showcase uses no copied institutional assets, tracking, external runtime
requests, component framework, or production theme code. Source Serif 4 files
are distributed under the included SIL OFL 1.1 license.

The superseded warm-paper "Scientific Editorial" world is archived, unmodified,
under `showcase/scientific-editorial-legacy/` — an anti-reference only, never a
source for new work.
