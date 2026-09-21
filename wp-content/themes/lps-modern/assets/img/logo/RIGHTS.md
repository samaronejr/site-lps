# LPS horizontal lockups — provenance and rights record

This directory ships the **modern horizontal lockups** of the LPS institutional
mark (waveform symbol on the left, wordmark text on the right), built for the
`lps-modern` theme and the `showcase/lps-modern/` preview. It complements —
it does not replace — the cleared stacked variant set documented in
`wp-content/themes/lps-theme/assets/img/mark/RIGHTS.md`.

## Provenance

- **Waveform geometry:** byte-identical to the cleared source artwork
  (`lps_logo_vector.svg`, SHA-256
  `f369f9e49c81e297d30fa8e240267667dddf8b89f0d26c494636f9429027a23b`).
  The `reference-line` and `waveform` path `d` data and the `wave-gradient`
  / `baseline-gradient` elements are copied verbatim from
  `wp-content/themes/lps-theme/assets/img/mark/lps-mark-full.svg`; only a
  wrapping scale/translate transform positions them in the new canvas.
  Registered digests still match: `reference-line`
  `4ae0fcc8…`, `waveform` `df03d99b…`.
- **Wordmark text:** newly set in a system sans stack
  (`Arial, Helvetica Neue, system-ui, sans-serif`, weight 800) so the lockup
  stays legible at header sizes. The old full lockup's outlined micro-lettering
  fell below the legibility floor whenever it was scaled to navigation height;
  the horizontal arrangement with live text fixes that without redrawing the
  laboratory's letterforms as new artwork.
- **Rights holder:** the Laboratório de Processamento de Sinais (LPS) itself —
  the laboratory's own mark for its own institutional site.
- **Clearance scope:** same as the cleared set — self-hosted use on the LPS
  institutional website only. No UFRJ, COPPE, or third-party mark is included.
- **Created:** 2026-09-21 (modern refresh, DESIGN.md Amendment M1).

## Files

| File | Canvas | Use |
| --- | --- | --- |
| `lps-logo-horizontal.svg` | 1020×240 | Full lockup (symbol + LPS + lab name + tagline) on light surfaces: footer identity, hero/about figures, press page |
| `lps-logo-horizontal-reversed.svg` | 1020×240 | Full lockup in white for dark surfaces (footer, hero band) |
| `lps-logo-compact.svg` | 600×200 | Compact lockup (symbol + LPS) on light surfaces: header home-link image |
| `lps-logo-compact-reversed.svg` | 600×200 | Compact lockup in white for dark surfaces |
| `lps-logo-compact-mono.svg` | 600×200 | Compact lockup in `currentColor` for UI chrome that must inherit text color |
| `lps-waveform-symbol.svg` | 886.5×301 | Byte-identical copy of the cleared symbol variant; narrow contexts only |
| `favicon-32.png`, `apple-touch-icon.png`, `icon-192.png`, `icon-512.png` | — | Browser/OS slots, copied from the cleared set |

## Rules

- Never recolor, restroke, crop, rotate, skew, stretch, shadow, or animate the
  waveform; never extract its gradient onto any other surface.
- Header instances are `<img>` elements (never inline interactive SVG): the
  home link carries the accessible name (`LPS — início` / `LPS — home`) and the
  image itself is decorative (`alt=""`).
- Clear space on every side is at least the cap height of the `LPS` lettering.
  Below 160 px display width, step down to the waveform symbol alone.
- The reversed variants sit only on the theme's approved dark fills
  (`brand-ink` `#0a2f5c`, `ink` `#10161c`); the color variants sit only on
  light surfaces (`paper` `#ffffff`, `paper-soft` `#f2f5f8`).
