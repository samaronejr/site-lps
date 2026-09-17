# Design-system guide (light institutional)

The binding visual contract is `DESIGN.md`: a light institutional, sans-led direction that
replaces the warm-paper Scientific Editorial look. `wp-content/themes/lps-theme/theme.json` is
the single source for palette, font roles, type scale and spacing (ADR-08). The same values are
exposed as CSS custom properties in `wp-content/themes/lps-theme/assets/css/theme.css`, and that
stylesheet is also loaded in the editor via `add_editor_style`, so the editor sees the same
tokens. The standalone showcase `showcase/scientific-editorial/index.html` mirrors the tokens and
remains the executable reference; it is not production theme code.

This guide is the working summary for whoever builds or reviews a surface. It never introduces a
value that `DESIGN.md` and `theme.json` do not already define. Verify with
`npm run qa:design-system` (`node scripts/run-qa.mjs design-system`), which rejects unapproved
token, radius, shadow, font and motion values.

Rows below carry the contract tags from `DESIGN.md`. MEASURED means the value is frozen in
`theme.json` or `theme.css` today; the token freeze has landed, so every value in this guide is
MEASURED. Parenthetical notes record what each value replaced in the warm-paper system.

## Colour tokens

Every colour in a template comes from the `theme.json` palette; the same values are exposed as
CSS custom properties in `theme.css`.

| Role | Token | Value | Usage | Tag |
| --- | --- | --- | --- | --- |
| Canvas | `--color-paper` | `#FFFFFF` | Page canvas, primary reading surface | MEASURED (replaced `#F7F4EC`) |
| Raised surface | `--color-paper-raised` | `#FFFFFF` | Inputs, selected rows, media mat; flat, never elevation | MEASURED (replaced `#FFFEFA`) |
| Section gray | `--color-paper-muted` | `#EFF1F4` | Alternating section band, quiet grouping, code/metadata fields, disabled fills | MEASURED (replaced `#ECE8DE`) |
| Ink | `--color-ink` | `#141A1F` | Headlines and body; 17.54:1 on white | MEASURED (incumbent) |
| Ink soft | `--color-ink-soft` | `#46515A` | Supporting text, captions, metadata; 8.12:1 on white | MEASURED (incumbent) |
| Navy | `--color-navy` | `#003B5C` | Institutional anchor: header rule, primary fills, footer band, wordmark text; 11.80:1 on white | MEASURED (incumbent) |
| Navy hover | `--color-navy-hover` | `#002B44` | Hover/pressed darkening, footer band depth | MEASURED (incumbent) |
| Signal | `--color-signal` | `#007A87` | Focus outline, current-nav rule, alert edge, selected state; 5.08:1 on white | MEASURED (incumbent) |
| Signal hover | `--color-signal-hover` | `#005F69` | Link text rest and hover/pressed; 7.39:1 on white | MEASURED (incumbent) |
| Rule | `--color-rule` | `#C9CDD1` | Hairline structure; decorative, never sole state carrier | MEASURED (incumbent) |
| Rule strong | `--color-rule-strong` | `#6F7A82` | Input borders, table heads, hard separation; 4.39:1 on white | MEASURED (incumbent) |
| Success | `--color-success` | `#216E4E` | Status text/icon plus label; never color alone | MEASURED (incumbent) |
| Warning | `--color-warning` | `#7A4A00` | Status text/icon plus label; never color alone | MEASURED (incumbent) |
| Error | `--color-error` | `#A12622` | Errors, destructive text, invalid borders | MEASURED (incumbent) |
| Info wash | `--color-info-wash` | `#DDECEF` | Informational alert background | MEASURED (incumbent) |
| Success wash | `--color-success-wash` | `#E0ECE5` | Success alert background | MEASURED (incumbent) |
| Warning wash | `--color-warning-wash` | `#F2E8D2` | Warning alert background | MEASURED |
| Error wash | `--color-error-wash` | `#F2DEDA` | Error alert background | MEASURED |
| Focus offset | `--color-focus-offset` | `#FFFFFF` | Focus-ring separation on dark fills | MEASURED (retargeted from `#F7F4EC`) |

Rules that reviewers enforce:

- White canvas and ink carry the page. Section gray separates modules; navy anchors the header
  rule, primary actions and the footer band. Signal teal appears only where it communicates an
  interactive, selected, focused or data-key state.
- Band alternation is structural, not decorative: adjacent modules never share a band color, and
  band color never carries meaning by itself.
- Signal is never body text on section gray (4.49:1 fails the text floor); use signal-hover for
  text on gray.
- All text/background pairs meet WCAG 2.2 AA: 4.5:1 text, 3:1 large text and UI graphics, 7:1
  body target.
- State is never encoded by colour alone; pair it with text, weight, rule position, icon shape or
  native control state.
- Official identity colours may replace navy or signal only after documented provenance and
  contrast verification. Extend the table before code. No official identity asset is present in
  this repository, so the documented palette is in force.
- No gradients, alpha fog, glass, glows or decorative colour fields.

## Typography

Sans-led hierarchy: display, headings, navigation and controls use the interface stack. Source
Serif 4 is retained only for reading containers. Three font roles, no proprietary face:

| Role | Token | Stack | Owns | Tag |
| --- | --- | --- | --- | --- |
| Interface + display | `--font-interface` | `system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif` | Display, h1-h4, nav, controls, tables, labels, captions, short body | MEASURED |
| Reading serif | `--font-editorial` | `"Source Serif 4", "Noto Serif", "Noto Serif CJK SC", "Noto Serif CJK JP", Georgia, serif` | `.lps-reading` and `.wp-block-post-content` only: body text and headings inside those containers | MEASURED (OFL 1.1, self-hosted woff2, `font-display: swap`) |
| Technical metadata | `--font-mono` | `ui-monospace, "Cascadia Mono", "Segoe UI Mono", Menlo, Consolas, monospace` | Dates, kickers, status, locale labels, code, tabular data | MEASURED (incumbent) |

Faces evaluated and excluded stay excluded: Arial, Inter, Roboto, Montserrat, WiredDisplay,
BreveText, Apercu, WiredMono, Playfair. New candidates enter only through this table with
provenance and license recorded.

Self-hosted font files are `wp-content/themes/lps-theme/assets/fonts/source-serif-4-regular.woff2`
and `source-serif-4-semibold.woff2`, licensed under
`wp-content/themes/lps-theme/assets/fonts/OFL.txt`.

The fluid scale matches the `theme.json` font sizes `display`, `h1`, `h2`, `h3`, `h4`, `lead`,
`body`, `reading`, `small`, `meta`. Sizes are retained; the sans-led weights, line heights and
tracking below are frozen in `theme.css`:

| Role/token | Fluid size | Weight | Line height | Tracking | Measure/use |
| --- | --- | --- | --- | --- | --- |
| `--type-display` | `clamp(2.75rem, 7vw, 5.75rem)` | 700 | 1.05 | `-0.02em` | Mission hero, sans, max 11 words/3 lines |
| `--type-h1` | `clamp(2.25rem, 5vw, 4rem)` | 700 | 1.08 | `-0.02em` | Page title, wordmark, sans |
| `--type-h2` | `clamp(1.75rem, 3.5vw, 2.75rem)` | 700 | 1.15 | `-0.015em` | Section heads, sans |
| `--type-h3` | `clamp(1.375rem, 2vw, 1.75rem)` | 650 | 1.2 | `-0.01em` | Subsection/record title, sans |
| `--type-h4` | `1.125rem` | 650 | 1.3 | `0` | Compact heading, sans |
| `--type-lead` | `clamp(1.25rem, 2vw, 1.5rem)` | 400 | 1.5 | `0` | Intro/deck, sans, max 62ch |
| `--type-body` | `1rem` | 400 | 1.65 | `0` | Interface/body, max 72ch |
| `--type-reading` | `1.125rem` | 400 | 1.72 | `0` | Long-form serif, max 68ch |
| `--type-small` | `0.875rem` | 450 | 1.5 | `0` | Captions/help/nav links |
| `--type-meta` | `0.75rem` | 600 | 1.4 | `0.075em` | Mono labels/metadata |

The `body` preset is fixed at `1rem` with preset-level `fluid: false`, preventing WordPress from
generating a smaller mobile minimum. Navigation and control weights: nav rest 650,
`aria-current` 750, button and search labels 700, inside the contracted 600-750 band.

Type rules reviewers enforce: Portuguese and English wrap by phrase, never by forced `<br>`; CJK
uses `word-break: normal`, `line-break: strict` and platform CJK fallbacks with no added letter
spacing; body text is never below 16px; captions and meta never carry essential instructions
alone; inline links keep a visible underline and are never distinguished by colour alone; years,
counts, DOI fragments, axes and table data use tabular numerals.

## Spacing, grid and geometry

Base unit 4px. Intentional spacing uses only these tokens (`theme.json` spacing sizes `1` to
`24`):

| Token | Value | Use |
| --- | --- | --- |
| `--space-1` | `0.25rem` | Rule/label proximity |
| `--space-2` | `0.5rem` | Inline icon/label, metadata |
| `--space-3` | `0.75rem` | Compact control internals |
| `--space-4` | `1rem` | Base gutter and field rhythm |
| `--space-5` | `1.25rem` | Comfortable control padding |
| `--space-6` | `1.5rem` | Content grouping |
| `--space-8` | `2rem` | Component groups |
| `--space-10` | `2.5rem` | Section prelude |
| `--space-12` | `3rem` | Major mobile break |
| `--space-16` | `4rem` | Major tablet break |
| `--space-20` | `5rem` | Major desktop break |
| `--space-24` | `6rem` | Maximum editorial pause |

The 12-column editorial grid: `--grid-max` is `80rem` (1280px), document-centered. Reading
measure `--measure-reading` is `68ch`, interface measure `--measure-interface` is `72ch`, lead
measure `--measure-lead` is `62ch`. Mobile under `48rem` uses 4 conceptual columns with
`--grid-gutter: 1rem`; tablet `48rem` to `63.99rem` uses 8 columns with `1.5rem`; desktop at
`64rem` and up uses 12 columns with `2rem`, where the section label takes 3 columns, primary
content 7 and the contextual note 2. Outer inset is
`clamp(var(--space-4), 4vw, var(--space-10))`. The `lps-page-grid` container holds the page and
`lps-content-limiter` bounds text measures. The document owns vertical scroll; the only nested
scroll is an explicitly labeled, keyboard-focusable table wrapper on narrow screens. Source
order equals reading and focus order; CSS placement never reorders semantic content.

Band rhythm replaces uniform rows: homepage and landing sections render as full-width bands
alternating white canvas and section gray, separated by a `--space-16` to `--space-24` internal
rhythm. Navy is reserved for the footer band, primary action fills, the header rule and at most
one interior band per page. Band colour is a container property only; component internals keep
their own hairline structure inside the band.

Geometry is square and flat. Depth tokens:

| Token | Value | Use |
| --- | --- | --- |
| `--rule-hairline` | `1px solid var(--color-rule)` | Sections, records, table rows |
| `--rule-strong` | `2px solid var(--color-navy)` | Controls, header base rule, major boundaries |
| `--rule-signal` | `3px solid var(--color-signal)` | Current/focus/alert marker |
| `--radius-square` | `0` | All rectangular surfaces |
| `--shadow-none` | `none` | Every primitive |

`theme.css` exposes the matching width primitives `--rule-thin`, `--rule-medium` and
`--rule-focus` (`0.0625rem`, `0.125rem`, `0.1875rem`), the `--focus-offset` distance
(`0.1875rem`), the `--press-shift` press distance (`0.0625rem`) and the `--control-min` minimum
target (`2.75rem`, 44px). No card chrome, rounded containers, shadows, gradients, blur, glass or
faux depth.

Responsive contract: one readable column with no horizontal page overflow at 375px; paired
comparisons may form two columns at 768px; the full 12-column rhythm is visible at 1280px; no
clipping or ellipsizing at 200% zoom or 320 CSS px. Long URLs and DOIs may break with
`overflow-wrap: anywhere` (`lps-breakable`). Print strips interaction-only controls, keeps
useful URLs, preserves figure captions, repeats table headers and avoids splitting alerts and
figures when possible.

## Primitives in the theme

The theme ships these primitives as classes in `theme.css`, used by the block templates and
patterns rather than re-invented per page:

| Primitive | Class | Notes |
| --- | --- | --- |
| Global shell | `lps-site-header`, `lps-masthead`, `lps-site-footer`, `lps-footer-grid`, `lps-main-content` | Skip link target is the main content region. |
| Navigation | `lps-primary-nav`, `lps-nav-panel`, `lps-shell-disclosure`, `lps-shell-tools` | Keyboard-operable disclosure; no hover-only access. |
| Orientation | `lps-breadcrumbs`, `lps-locale`, `lps-search` | Locale control and search are persistent. |
| Editorial structure | `lps-editorial-section`, `lps-home-section`, `lps-home-journeys`, `lps-handoff` | Section label, content and contextual note. |
| Records | `lps-record`, `lps-listing`, `lps-meta`, `lps-kicker`, `lps-citation-downloads` | Metadata-forward rows, no chrome cards. |
| Text | `lps-reading`, `lps-lead`, `lps-breakable` | `lps-reading` is the serif scope; `lps-breakable` allows long DOI/URL breaking. |
| Controls | `lps-button`, `lps-button-primary`, `lps-cluster` | Minimum target size `--control-min`. |
| Search results | `lps-search-facet-value`, `lps-search-pagination` | Server-rendered facets and pagination. |
| States | `lps-empty-state`, `lps-not-found`, `lps-alert` with `lps-alert-info|success|warning|error` | Documented empty/error states, never a blank region. |
| Institutional | `lps-affiliation`, `lps-report-contact` | UFRJ/COPPE affiliation strip and barrier/privacy reporting route. |

Templates live in `wp-content/themes/lps-theme/templates/` (front page, archives and singles for
every record type, search, 404), parts in `wp-content/themes/lps-theme/parts/` and patterns in
`wp-content/themes/lps-theme/patterns/` (`page-shell.php`, `editorial-section.php`,
`empty-state.php`). Templates are locked so editors change content, not structure.

## Motion and interaction

| Token | Value | Use |
| --- | --- | --- |
| `--motion-fast` | `120ms` | Hover/focus color and button press |
| `--motion-standard` | `180ms` | Disclosure/state tint if later required |
| `--ease-state` | `cubic-bezier(0.2, 0, 0, 1)` | Interruptible state response |

`--motion-fast`, `--motion-standard` and `--ease-state` are all frozen in `theme.css`.

Motion communicates only interaction and state. No load reveals, parallax, ambient drift,
carousels, decorative graph animation, smooth-scroll hijacking, magnetic controls or hover
movement on noninteractive surfaces. Only `transform`, `opacity`, `filter`, color and
text-decoration may transition; `transition: all` and layout animation are forbidden. The only
spatial movement is the 1px pressed-button response (`--press-shift`), which does not alter
layout and disappears under `prefers-reduced-motion: reduce`. Reduced motion sets all durations
to effectively zero and removes transforms while content and state stay fully legible. Focus is
not animated, is always visible and never obscured, using the signal outline with
`--focus-offset`. Feedback appears within 100ms and never waits for motion. Native
`video[controls]` hosts use that same keyboard-focus primitive without replacing or hiding the
browser controls.

## Adding or changing a value

1. Extend `DESIGN.md` first with the role, value and usage rule.
2. Add the token to `wp-content/themes/lps-theme/theme.json`, then consume it in `theme.css`
   (and mirror it in the showcase). `theme.json` is the single source; the CSS variables, the
   editor styles and the showcase follow it.
3. Run `npm run qa:design-system` and `npm run test -- tests/js/design-system-checker.test.mjs`.
4. Run `tools/composer test` for the theme contract suites, then capture fresh visual evidence at
   375, 768 and 1280 for the affected surfaces.

Never type a raw hex, radius, shadow, font family or duration into a template or pattern; the
gate rejects it and the review will reject it.
