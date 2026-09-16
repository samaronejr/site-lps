# Design-system guide (Scientific Editorial)

The binding visual contract is `DESIGN.md`. The executable implementation is
`wp-content/themes/lps-theme/theme.json` (tokens, fluid scale, spacing) and
`wp-content/themes/lps-theme/assets/css/theme.css` (primitives). The standalone reference is
`showcase/scientific-editorial/index.html`; it is a reference, not production code.

This guide is the working summary for whoever builds or reviews a surface. It never introduces a
value that `DESIGN.md` and `theme.json` do not already define. Verify with
`npm run qa:design-system`, which rejects unapproved token, radius, shadow, font and motion values.

## Colour tokens

Every colour in a template comes from the `theme.json` palette; the same values are exposed as CSS
custom properties in `theme.css`.

| Token | Value | Use |
| --- | --- | --- |
| `--color-paper` | `#F7F4EC` | Primary canvas and reading surface. |
| `--color-paper-raised` | `#FFFEFA` | Inputs, selected rows, media mat — never card elevation. |
| `--color-paper-muted` | `#ECE8DE` | Quiet grouping, metadata fields. |
| `--color-ink` | `#141A1F` | Headlines and body. |
| `--color-ink-soft` | `#46515A` | Supporting text and captions. |
| `--color-navy` | `#003B5C` | Institutional anchor and primary actions. |
| `--color-navy-hover` | `#002B44` | Hover/pressed darkening. |
| `--color-signal` | `#007A87` | Focus, selected state, informative links. |
| `--color-signal-hover` | `#005F69` | Link hover/pressed. |
| `--color-rule` | `#C9CDD1` | Hairline structure. |
| `--color-rule-strong` | `#6F7A82` | Table heads and hard separation. |
| `--color-success` | `#216E4E` | Success text/icon plus label. |
| `--color-warning` | `#7A4A00` | Warning text/icon plus label. |
| `--color-error` | `#A12622` | Errors and destructive action text. |
| `--color-info-wash`, `--color-success-wash`, `--color-warning-wash`, `--color-error-wash` | washes | Alert backgrounds only. |
| `--color-focus-offset` | `#F7F4EC` | Focus-ring separation from dark fills. |
| `--color-brand-deep` | `#094c92` | Mark artwork only; forbidden as text, link, button, control, focus, rule, or surface. |
| `--color-brand-accent` | `#00aff1` | Mark artwork only; forbidden as text, link, button, control, focus, rule, or surface. |

Rules that reviewers enforce: state is never encoded by colour alone; the signal teal appears only
for interactive, selected, focused or data-key meaning; ordinary text meets 4.5:1 and large text and
UI graphics meet 3:1; no gradient, glass, glow or decorative colour field. Official UFRJ/COPPE
identity colours may replace navy or signal only with documented provenance and equal-or-better
contrast — no official identity asset is present in this repository, so the documented fallback
palette is in force. The brand-deep and brand-accent tokens are registered in `theme.json` under
`settings.custom`, outside the editor colour palette, and are reserved exclusively for the
institutional mark artwork (§9 Mark); they are never applied to text, links, buttons, controls,
focus indicators, rules, borders, icons, backgrounds, or any surface in the theme, editor, or
future templates.

## Typography

Three font roles, no proprietary face:

| Token | Stack | Owns |
| --- | --- | --- |
| `--font-editorial` | Source Serif 4 (self-hosted, SIL OFL) with Noto Serif/CJK and Georgia fallbacks | Display, deck, quotations, long-form reading. |
| `--font-interface` | `system-ui` platform stack | Navigation, controls, tables, labels, short body. |
| `--font-mono` | platform monospace stack | Identifiers, dates, axes, kickers, code-like metadata. |

Self-hosted font files are `wp-content/themes/lps-theme/assets/fonts/source-serif-4-regular.woff2`
and `source-serif-4-semibold.woff2`, licensed under
`wp-content/themes/lps-theme/assets/fonts/OFL.txt`.

The fluid scale is `--type-display`, `--type-h1`, `--type-h2`, `--type-h3`, `--type-h4`,
`--type-lead`, `--type-body`, `--type-reading`, `--type-small`, `--type-meta`, matching the
`theme.json` font sizes `display`, `h1`, `h2`, `h3`, `h4`, `lead`, `body`, `reading`, `small`,
`meta`. The `body` preset is fixed at `1rem` with preset-level `fluid: false`, preventing
WordPress from generating a smaller mobile minimum; the approved fluid heading sizes remain intact.
Measures are bounded by `--measure-reading`, `--measure-lead` and `--measure-interface`.
Body text is never below 16px; captions and meta never carry essential instructions alone; links
keep a visible underline; years, counts and identifiers use tabular numerals.

## Spacing, grid and geometry

The 4px scale is `--space-1` through `--space-24` (`theme.json` spacing sizes `1`–`24`); intentional
spacing uses only those steps. Layout uses `--grid-max` (80rem) with `--grid-gutter`, expressed by
the `lps-page-grid` container and `lps-content-limiter` for text measures. Geometry is square:
`--radius-square`, `--shadow-none`, and hairlines `--rule-thin`, `--rule-medium`, `--rule-focus`
instead of card shadows. Source order equals reading and focus order; CSS placement never reorders
semantic content.

Responsive contract: one readable column with no horizontal page overflow at 375px; paired
comparisons at 768px; the full 12-column rhythm at 1280px; no clipping at 200% zoom or 320 CSS px.

## Primitives in the theme

The theme ships these primitives as classes in `theme.css`, used by the block templates and patterns
rather than re-invented per page:

| Primitive | Class | Notes |
| --- | --- | --- |
| Global shell | `lps-site-header`, `lps-masthead`, `lps-site-footer`, `lps-footer-grid`, `lps-main-content` | Skip link target is the main content region. |
| Navigation | `lps-primary-nav`, `lps-nav-panel`, `lps-shell-disclosure`, `lps-shell-tools` | Keyboard-operable disclosure; no hover-only access. |
| Orientation | `lps-breadcrumbs`, `lps-locale`, `lps-search` | Locale control and search are persistent. |
| Editorial structure | `lps-editorial-section`, `lps-home-section`, `lps-home-journeys`, `lps-handoff` | Section label, content and contextual note. |
| Records | `lps-record`, `lps-listing`, `lps-meta`, `lps-kicker`, `lps-citation-downloads` | Metadata-forward rows, no chrome cards. |
| Text | `lps-reading`, `lps-lead`, `lps-breakable` | `lps-breakable` allows long DOI/URL breaking. |
| Controls | `lps-button`, `lps-button-primary`, `lps-cluster` | Minimum target size `--control-min`. |
| Search results | `lps-search-facet-value`, `lps-search-pagination` | Server-rendered facets and pagination. |
| States | `lps-empty-state`, `lps-not-found`, `lps-alert` with `lps-alert-info|success|warning|error` | Documented empty/error states, never a blank region. |
| Institutional | `lps-affiliation`, `lps-report-contact` | UFRJ/COPPE affiliation strip and barrier/privacy reporting route. |

Templates live in `wp-content/themes/lps-theme/templates/` (front page, archives and singles for
every record type, search, 404), parts in `wp-content/themes/lps-theme/parts/` and patterns in
`wp-content/themes/lps-theme/patterns/` (`page-shell.php`, `editorial-section.php`,
`empty-state.php`). Templates are locked so editors change content, not structure.

## Mark (institutional identity)

The institutional mark is `lps_logo_vector.svg`, the laboratory's own cleared artwork. It appears
in two contexts and seven variants:

### Full-colour lockup
Used in non-interactive identity-only contexts: footer identity block, an identity/about
page figure, press and download pages, and social share images. The lockup displays the wordmark
with the waveform gradient and brand colours; it is never interactive, never focusable, never placed
in navigation, and never used as an icon.

### Monochrome derivative
Used wherever the mark acts as UI chrome: home link in headers and footers, interactive
navigation or control instances, or any focusable element. The monochrome variant takes a single
`currentColor` fill, inheriting text colour from its surrounding context. It is never recoloured
with a brand token. Wherever a lockup's lettering would fall under the legibility floor or
duplicate an adjacent visible wordmark, the monochrome symbol (`lps-mark-mono-symbol.svg`,
waveform and baseline rule only) is the chrome variant; the shell header and footer use it inside
their home links, `aria-hidden` beside the visible wordmark.

### Wave-gradient and ornament
The `wave-gradient` exists only within the mark artwork itself. It is never extracted, repeated,
mirrored, tiled, or reused as a divider, watermark, background, or decorative surface. No other
waveform or gradient is permitted on cards, buttons, rules, focus rings, or any UI surface.

### Clear space and minimum size
The mark requires clear space on every side equal to at least the cap height of its lettering,
measured from the artwork's bounding box. The minimum display width is determined by the smallest
lettering in the variant; do not shrink below the legibility floor. On narrow screens, step down
the matrix (full lockup → compact lockup → symbol only); never scale below the floor.

### Brand colours are mark-only
The brand tokens `--color-brand-deep` and `--color-brand-accent` are never applied to any UI
surface. They exist only to render the mark artwork faithfully. Text, links, buttons, controls,
focus indicators, rules, borders, icons, backgrounds, and surfaces all use the approved palette
from the Colour tokens section, never the brand tokens. This restriction is enforced by registering
the brand tokens in `theme.json` under `settings.custom`, outside the editor colour picker, so
authors cannot accidentally apply them to content.

### Rights and publication
No mark variant is published until the rights record exists and is registered as cleared with a
local asset path. Remote hosting and hotlinking remain forbidden. Until the variant set (Todo 32)
registers the rights record and placement (Todo 33) integrates the mark, shipped surfaces remain
text-only institutional identity.

## Motion and interaction

Motion tokens are `--motion-fast`, `--ease-state` and `--press-shift`; they exist for immediate,
interruptible state feedback only. No parallax, carousel, autoplay, spring, morph or scroll-driven
decoration. Reduced motion removes movement rather than hiding content. Focus is always visible and
never obscured, using the signal outline with `--focus-offset`. Native `video[controls]` hosts
use that same keyboard-focus primitive without replacing or hiding the browser controls.

## Adding or changing a value

1. Extend `DESIGN.md` first with the role, value and usage rule.
2. Add the token to `wp-content/themes/lps-theme/theme.json`, then consume it in `theme.css`.
3. Run `npm run qa:design-system` and `npm run test -- tests/js/design-system-checker.test.mjs`.
4. Run `tools/composer test` for the theme contract suites, then capture fresh visual evidence at
   375, 768 and 1280 for the affected surfaces.

Never type a raw hex, radius, shadow, font family or duration into a template or pattern; the gate
rejects it and the review will reject it.
