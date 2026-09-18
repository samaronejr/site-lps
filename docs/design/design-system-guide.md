# Design-system guide (unified identity)

The binding visual contract is `DESIGN.md`: a unified identity built around the owner-supplied
LPS artwork, the resolved blue palette, and self-hosted IBM Plex. It replaces the
light-institutional, system-stack direction. `wp-content/themes/lps-theme/theme.json` remains
the single token source in code (ADR-08); the machine-readable contract is
`docs/design/design-contract.json`, validated by `node scripts/lib/design-contract.mjs`.

Rows below carry the contract tags from `DESIGN.md`. MEASURED means the value is frozen in
`theme.json` or `theme.css` today. The token sync (plan task-07) landed on 2026-09-18: every
PROPOSAL row below is now MEASURED, and `check-theme.mjs` gates contract↔:root parity so drift
is a hard failure. Verify with `npm run qa:design-system` for the frozen side and
`node scripts/lib/design-contract.mjs` for the contract side.

## Colour tokens

| Role | Token | Value | Usage | Tag |
| --- | --- | --- | --- | --- |
| Canvas | `--color-canvas` | `#F5F7FA` | General page background | MEASURED (replaces `--color-paper` `#FFFFFF`) |
| Surface | `--color-surface` | `#FFFFFF` | Reading and form surfaces; the only approved field for full-color logo artwork | MEASURED (replaces `--color-paper-raised`) |
| Institutional anchor | `--color-anchor` | `#12304A` | Navigation band and footer band; primary fills | MEASURED (replaces `--color-navy` `#003B5C`) |
| Anchor depth | `--color-anchor-deep` | `#0C2237` | Hover/pressed depth inside anchor bands | MEASURED (derived) |
| Action | `--color-action` | `#165A96` | Links and controls on light surfaces; focus outline on light surfaces | MEASURED (replaces `--color-signal`/`--color-signal-hover` for interaction) |
| Action depth | `--color-action-hover` | `#0F4A7E` | Link/control hover and pressed states | MEASURED (derived) |
| Text | `--color-text` | `#182B3A` | Main reading text | MEASURED (replaces `--color-ink` `#141A1F`) |
| Muted text | `--color-text-muted` | `#526477` | Secondary readable text; never essential low-contrast hints | MEASURED (replaces `--color-ink-soft` `#46515A`) |
| Quiet rule | `--color-rule-quiet` | `#D7E0E8` | Decorative separators only; never the sole boundary of a control | MEASURED (replaces `--color-rule` `#C9CDD1`) |
| Strong boundary | `--color-boundary-strong` | `#74869A` | Necessary light-surface control boundaries (3.74:1 on surface) | MEASURED (replaces `--color-rule-strong` `#6F7A82`) |
| Focus on dark | `--color-focus-on-dark` | `#FFFFFF` | Focus outline on anchor bands and other dark fills | MEASURED (replaces `--color-focus-offset`) |
| Success | `--color-success` | `#216E4E` | Status text/icon plus label; never color alone | MEASURED (carried) |
| Warning | `--color-warning` | `#7A4A00` | Status text/icon plus label; never color alone | MEASURED (carried) |
| Error | `--color-error` | `#A12622` | Errors, destructive text, invalid borders | MEASURED (carried) |
| Info wash | `--color-info-wash` | `#DDECEF` | Informational alert background | MEASURED (carried) |
| Success wash | `--color-success-wash` | `#E0ECE5` | Success alert background | MEASURED (carried) |
| Warning wash | `--color-warning-wash` | `#F2E8D2` | Warning alert background | MEASURED (carried) |
| Error wash | `--color-error-wash` | `#F2DEDA` | Error alert background | MEASURED (carried) |

Rules that reviewers enforce:

- Canvas and text carry the page; anchor owns the navigation band, primary fills, and the footer
  band; action appears only where it communicates an interactive, focused, or selected state.
- All text/background pairs meet WCAG 2.2 AA: 4.5:1 text, 3:1 large text and UI graphics, 7:1
  body target. Computed pairs live in `design-contract.json`; rendered review re-checks every
  actual pairing after the token sync.
- `--color-rule-quiet` is decorative-only: never a text color, never a control boundary, never
  the sole carrier of meaning.
- State is never encoded by colour alone.
- No gradients, alpha fog, glass, glows or decorative colour fields. The waveform gradient inside
  the supplied artwork is the single permitted gradient.

## Typography

| Role | Token | Stack | Owns | Tag |
| --- | --- | --- | --- | --- |
| Interface + reading | `--font-interface` | `"IBM Plex Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif` | Display, h1–h4, navigation, controls, tables, labels, captions, body and long-form reading | MEASURED (OFL 1.1, self-hosted woff2, `font-display: swap`) |
| Technical metadata | `--font-mono` | `"IBM Plex Mono", ui-monospace, "Cascadia Mono", "Segoe UI Mono", Menlo, Consolas, monospace` | Course codes, record identifiers, code samples, tabular schedule numerals only | MEASURED (OFL 1.1, self-hosted woff2) |

Licensing and glyph coverage were verified 2026-09-18 (SIL OFL 1.1, Reserved Font Name "Plex";
full pt-BR diacritic coverage). If the licensed files cannot ship, the recorded fallback is the
system stack and the substitution is documented — the decision does not reopen mid-execution.
IBM Plex Mono is restricted to codes and identifiers; it is never body text or headings. Source
Serif 4's reading role is retired. Excluded faces stay excluded: Arial, Inter, Roboto,
Montserrat, WiredDisplay, BreveText, Apercu, WiredMono, Playfair.

Scale: headings adapt within the 32–56px band; body 16–18px; line height 1.5–1.65; reading
measure about 68ch. The full scale table is `design-contract.json → typography.scale`.

## Identity

- Source artwork: `assets/brand/lps_logo_vector.svg` (53,019 bytes, SHA-256
  `f369f9e49c81e297d30fa8e240267667dddf8b89f0d26c494636f9429027a23b`). Bytes preserved verbatim.
- Full-color artwork is the masthead identity — an amendment scoped to this owner-supplied LPS
  artwork only. UFRJ/COPPE marks remain text-only.
- Compact variant `assets/brand/lps_logo_compact.svg` (viewBox `64 200 1322 275`) is derived by
  path selection only: `reference-line`, `waveform`, `separator`, `lps-lettering` kept verbatim;
  `laboratory-name` and `computational-intelligence` dropped. No new geometry.
- Desktop masthead: full artwork 240–320px on a light surface. Mobile masthead: compact variant
  28–40px. Anchor (navy) surfaces: text wordmark only — the artwork's dark blues measure
  1.59–2.25:1 on `--color-anchor`.
- Clear space equals the waveform height; the logo link carries a meaningful accessible name
  without duplicate narration.

## Spacing, geometry, motion

- 4px base unit; the `--space-1`…`--space-24` scale is unchanged. Max content width 80rem;
  12-column grid on desktop; section spacing 48–80px.
- Control radius 4px; images rectangular; hairline rules and band alternation carry hierarchy;
  no shadows, no card chrome, no pill interfaces.
- Dials `DESIGN_VARIANCE=3, MOTION_INTENSITY=2, VISUAL_DENSITY=5`: short interruptible feedback
  only; `prefers-reduced-motion` removes movement without removing information; no scroll
  hijacking, marquees, pinned storytelling, or autoplay.

## Surfaces and applications

Four representative surfaces share one identity at different task densities — homepage
(Persuade, low), professor (Read, medium), populated offering (Operate, high), editor dashboard
(Operate, highest) — each with specified empty, error, and long-content states. Application
specifications cover desktop/mobile branding, news cover, course-document cover, social
preview, and print. The full tables are `DESIGN.md` sections 7–8 and
`design-contract.json → surfaces, applications`.
