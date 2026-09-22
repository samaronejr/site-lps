# LPS institutional mark — provenance and rights record

This file is the provenance and rights record required by DESIGN.md §9 ("Rights
gate") for the institutional mark variant set shipped in this directory. It is
the record the `_lps_logo_url` / `_lps_logo_rights` publish-gate contract
consumes: `LPS_PublicRoutes::organization_record()` publishes `logo_url` only
when `_lps_logo_rights` is `cleared` **and** `_lps_logo_url` is a local path
(`is_local_path()` — leading `/`, no `://`). Remote hosting and hotlinking stay
forbidden.

## Registration (contract keys)

| Contract key | Value |
| --- | --- |
| `_lps_logo_url` | `/wp-content/themes/lps-theme/assets/img/mark/lps-mark-full.svg` |
| `_lps_logo_rights` | `cleared` |

These values are recorded here for the LPS organization record; the gate
evaluates them on the record itself at render time. Until they are set on the
record, the gate resolves to unpublished — which is the correct default.

## Provenance

- **Source artwork:** `lps_logo_vector.svg` (repository root of the source
  checkout), 53,019 bytes, SHA-256
  `f369f9e49c81e297d30fa8e240267667dddf8b89f0d26c494636f9429027a23b`.
- **Derivation:** "Vectorized from the supplied logo" — the file is a
  derivative of the laboratory's own supplied logo; all lettering is outlined
  (six paths, no font or raster dependency). Source viewBox `48 190 2052 301`
  (non-zero origin, 6.82:1); every shipped variant normalises the origin to
  `0 0` without altering path `d` data.
- **Rights holder:** the Laboratório de Processamento de Sinais (LPS) itself.
  This is the laboratory's own mark, supplied by the laboratory for its own
  institutional site — not a third-party asset. No UFRJ, COPPE, or other
  institutional mark is included or derived here.
- **Cleared by:** LPS (the rights owner) for use on its own institutional
  website, as recorded by the binding design contract amendment (DESIGN.md
  §1/§9, "the laboratory's own cleared mark").
- **Clearance date:** 2026-09-13 (date the design contract recorded the mark
  as cleared and this variant set was produced).
- **Clearance scope:** self-hosted use on the LPS institutional website only,
  in the variant forms and contexts below.

A second artwork set was registered on 2026-09-22: `lps-coppe-blue.svg`, the
official institutional lockup pairing the LPS mark with COPPE, Poli and UFRJ
lettering, supplied by the laboratory alongside this clearance amendment for
use on its own site — the laboratory is LPS, a COPPE/Poli-UFRJ unit, so the
combined artwork is likewise its own. `lps-coppe-blue-lockup.svg` is the same
artwork reframed to a tight viewBox (path `d` data byte-identical) for the
masthead slot.

## Permitted-use rules (DESIGN.md §9)

| Variant | File(s) | Permitted contexts | Never |
| --- | --- | --- | --- |
| Full-colour lockup | `lps-mark-full.svg` | Non-interactive identity only: footer identity block, an identity/about page figure, press and download pages, the social share image | Any interactive or focusable instance, navigation, control, or icon slot |
| Compact lockup | `lps-mark-compact.svg` | The same non-interactive contexts, where the full lockup would fall under its legibility floor | As above |
| Symbol only | `lps-mark-symbol.svg` | Non-interactive contexts too narrow for any lockup | Standing in for the institution name in text |
| Monochrome | `lps-mark-mono.svg` | Every instance where the mark acts as UI chrome: home link, header mark, footer link, any interactive or focusable instance — colour comes from `currentColor`, never a brand token | Carrying a brand colour |
| Monochrome symbol | `lps-mark-mono-symbol.svg` | The same UI-chrome contexts, wherever a lockup's lettering would fall under the §3 legibility floor or duplicate an adjacent visible wordmark — colour comes from `currentColor`, never a brand token | Carrying a brand colour; standing in for the institution name in text |
| Reversed on dark | `lps-mark-reversed.svg` | Non-interactive identity on a §2-approved dark fill (Navy `#003B5C` shipped) | Introducing a new dark surface for its own sake |
| Favicon / app icon | `lps-mark-favicon.svg`, `lps-mark-icon.svg`, `lps-mark-icon-maskable.svg`, `favicon-32.png`, `apple-touch-icon.png`, `icon-192.png`, `icon-512.png`, `icon-maskable-512.png` | Browser and OS chrome slots only | Any in-page icon slot |
| Social share image | `lps-mark-social.svg`, `lps-mark-social.png` | `og:image` / link-preview surfaces | Any other surface |
| COPPE/Poli/UFRJ lockup | `lps-coppe-blue.svg`, `lps-coppe-blue-lockup.svg` | Masthead brand slot (the home link), footer identity block, identity-page figure — full colour only on `--color-surface` white | Any dark, tinted, or non-white surface; recolouring or cropping the artwork |

Misuse (each rejected independently, per DESIGN.md §9): no recolouring,
regradienting, flattening, outlining, or altering lettering, proportions, or
colours; no rotation, skew, stretch, crop, distortion, shadow, glow, radius,
blur, border, or container chrome; no extracting `wave-gradient` or any part
of the artwork onto any other surface; no repeating, mirroring, tiling, or
reusing the waveform as ornament, divider, watermark, or background texture;
no brand colour on text, links, buttons, controls, focus rings, rules,
borders, icons, backgrounds, or surfaces; no use as a heading or as a
substitute for the institution name; no lockup with a UFRJ, COPPE, or
third-party mark in the variant set above (the separately registered
`lps-coppe-blue` lockup is the permitted joint artwork); no publication while
this record is unregistered; no hotlinking or remote hosting.

## Integrity

Path `d` data and every `<linearGradient>` element in the SVG variants are
byte-identical to the supplied source (asserted by the build script and by the
`design-guardrails` rule engine, which registers the artwork's SHA-256
digests). PNG variants are rasterisations of the SVG variants rendered by the
repository's own Chromium; they carry no additional artwork.

| File | SHA-256 |
| --- | --- |
| `lps-mark-full.svg` | `1bd674961de9a85cfdbb666032c2fba4d24e4445c846161f494a8d05e0533473` |
| `lps-mark-compact.svg` | `a52f6adc7dbb528d56c5caa3e49fa1c2b941e11f6f1f2962c759842bdf6becb9` |
| `lps-mark-symbol.svg` | `6b978a1c47a826e2d10be629a32b84d2a9e890f9f3e91b79714930cc1b30c7da` |
| `lps-mark-mono.svg` | `be0dbbe9fce819407d6428d177d6854fe8d95165650c402c1c26c37764f1e436` |
| `lps-mark-mono-symbol.svg` | `8ae547aeadf67db23429c7d90b1303a05c6271349e7b907ac14ab000d505b1d6` |
| `lps-coppe-blue.svg` | `5144e227ce3c4dc781d5084e48ddd03bade50103067e094aa15ca4817878a32d` |
| `lps-coppe-blue-lockup.svg` | `301f8a662024152842752b3b695fa6bbd53c227909aa2b7988707213a9fa3de4` |
| `lps-mark-reversed.svg` | `57e3379373d85ebd7bf281723266e40aa9b2006546c02d51132b61ec3e79b0c6` |
| `lps-mark-favicon.svg` | `2f4acc76326e012caf407ece8da1379dd8480f3f4bb9a2ea558f4b73f4e3f5d1` |
| `lps-mark-icon.svg` | `ca7aeecd8caf486c0ca2a46a5501b8926ba440f3139f87a5579eaf1d67d504ea` |
| `lps-mark-icon-maskable.svg` | `4dc45dda7d930239b2965d12a8978bfde3953acd7d42116b80c305d9a1551701` |
| `lps-mark-social.svg` | `9c8e78a5734d524b6e230c8e8aad9cbe371fbb769920acf220d37295ced33196` |
| `favicon-32.png` | `5972657560647f92ba7056a7d807b427be6773dda0d55b64a0536475d8d31b98` |
| `apple-touch-icon.png` | `b26f1f00e0032b7bf35f6892038f0e41c3477a00548dd353d7ca136eedb20597` |
| `icon-192.png` | `48520f977a5b86b0c6c7b8ecacb7ee903b869a4a59634d7b183336a3e0baa41c` |
| `icon-512.png` | `54291030543118485e14ea99d0b5f2a7daa709c51a148e85707c1809c48f8523` |
| `icon-maskable-512.png` | `a2d00b5b44da5c3d657690ea560318c2dd13bca2d32a15f8a298dd7ee4acffaa` |
| `lps-mark-social.png` | `be8d200dfb256544ba07f23ba967cec32358695d7b24fd02d1b18e0f22bf1f82` |
| `site.webmanifest` | `0bfd4d762bf5d4bba04e52af1f28ce43f1a0960062c21351acb6549ca17ac792` |
