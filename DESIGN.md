# LPS Institutional Design Contract — Unified Identity (Replacement)

Status: Binding contract for the public LPS theme and its editor-facing primitives. This revision
replaces the light-institutional, system-stack direction with a unified identity built around the
owner-supplied LPS artwork, the resolved blue palette, and self-hosted IBM Plex. It is a
replacement-world contract: product truth and constraints are preserved intact, while the prior
visual direction is retained only as an anti-reference. Where a clause is superseded, the
supersede table in section 1 says so explicitly. Nothing here weakens accessibility, rights, or
governance requirements, and this document specifies one direction, not a menu of styles.

The machine-readable half of this contract is `docs/design/design-contract.json`, validated by
`node scripts/lib/design-contract.mjs`. The JSON is the token specification task-07 synced into
`theme.json`/`theme.css`; this document is the human contract around it.

## 0. Contract Basis, Tags & Evidence

### Tag convention

| Tag | Meaning |
| --- | --- |
| MEASURED | Value read verbatim from worktree source, instrumented from a live capture, or carried forward unchanged from the incumbent contract. Basis is cited. |
| PROPOSAL | Design decision resolved by the plan but not yet frozen in `theme.json`/`theme.css`. Binding as direction; frozen in code only when the token sync lands (plan task-07). Values tagged PROPOSAL in the 2026-09-18 revision were frozen by task-07 and are re-tagged MEASURED. |
| UNAPPROVED | Public-facing copy, marks, or placements that require an authorized owner. Never ships as written until approved. |

### Evidence base

- Plan: `.omo/plans/lps-website-ulw-plan.md` task-02 and its "Resolved visual direction" — the
  palette, typography, geometry, motion dials, and logo amendment recorded here are the plan's
  resolved decisions, not new invention.
- Reference and incumbent captures, 2026-09-18: real Chromium via playwright-core at 1280x800 and
  375x720. FEEC (`fee.unicamp.br`), PEE (`pee.ufrj.br`), and the incumbent LPS site
  (`/pt-br/`, `/pt-br/pesquisa/`, `/pt-br/pessoas/` served by the development runtime at
  `e9f9ed6` plus unrelated local modifications) are stored with per-capture JSON metadata under
  `.omo/evidence/lps-website-ulw-plan/attempt-1/task-02/screenshots/`. Section 2 separates what
  the screenshots show from what the contract concludes.
- Logo verification, 2026-09-18: `assets/brand/lps_logo_vector.svg`, 53,019 bytes, SHA-256
  `f369f9e49c81e297d30fa8e240267667dddf8b89f0d26c494636f9429027a23b` — byte-identical to the
  supplied artwork recorded in the plan.
- Font verification, 2026-09-18: IBM Plex license is SIL OFL 1.1 with Reserved Font Name "Plex"
  (upstream `LICENSE.txt`). The `cmap` tables of `IBMPlexSans-Regular/Medium/SemiBold/Bold` and
  `IBMPlexMono-Regular/SemiBold` TTFs cover the full pt-BR diacritic set
  (á à â ã é ê í ó ô õ ú ü ç, uppercase included), ASCII, and typographic punctuation — 895 and
  1049 glyphs respectively, zero missing. The licensing and glyph check passes, so IBM Plex is
  the contract family; the system-stack fallback remains documented in case the self-hosted
  files cannot ship.
- Font vendoring, 2026-09-18 (task-07): the verified TTFs were subsetted to ASCII, Latin-1
  (full pt-BR diacritics) and general punctuation and written as woff2 into
  `wp-content/themes/lps-theme/assets/fonts/` — `ibm-plex-sans-{regular,medium,semibold,bold}`
  and `ibm-plex-mono-{regular,semibold}`, ~9.5–10 KiB per face, `font-display: swap`, OFL.txt
  retained beside them. The system stacks remain the recorded fallback inside each family
  stack; no remote font request exists anywhere in the theme.
- Contrast computation, 2026-09-18: every pair in `design-contract.json` was computed with WCAG
  2.2 relative luminance. Computed ratios are expectations, not a conformance claim; rendered
  review re-checks every actual pairing after the token sync.
- Governing ADRs unchanged: ADR-01 (integrated WordPress, no page builder), ADR-08 (`theme.json`
  is the single token source and the design contract is executable), ADR-10 (no third-party
  runtime requests, no cookies for anonymous visitors). See `docs/architecture/decision-record.md`.

### Skill application receipt

The six requested design skills contributed to this contract as one direction. Each row names the
concrete contribution and what was deliberately not adopted; the plan's precedence rule applies —
product requirements and approved artwork outrank any skill's generic defaults.

| Skill | Contribution in this contract | Deliberately not adopted |
| --- | --- | --- |
| `impeccable` | Mode-per-surface assignment (homepage Persuade, professor Read, offering Operate, editor Operate); replacement-world discipline — the incumbent look is evidence and anti-reference, never split into polish; bounded desktop/mobile critique via the captured screenshots. | Reopening settled requirements; replacing the owner's brand with a skill aesthetic. |
| `gpt-taste` | Strong focal composition: one mission statement and one primary action in the first viewport; readable title widths (measure caps, 32–56px heading band); contrast discipline (every text pair ≥ 4.5:1); varied content layouts across the four surfaces. | Mandatory cinematic motion, GSAP, fabricated randomization, placeholder photography, marketing-funnel treatment of course pages. |
| `high-end-visual-design` | Typographic care (per-role weights, tracking, line heights); balanced spacing rhythm (4/8px scale, 48–80px section gaps); coherent controls (one radius, one focus treatment); detail consistency (hairline rules, aligned metadata). | Luxury styling, double bezels, pill navigation, mandatory shadows and reveal animations, custom cursors. |
| `industrial-brutalist-ui` | Swiss Industrial Print archetype only: light substrate, visible compartmentalization via hairline rules, bimodal density (dense resource lists vs. calm reading), monospace reserved for technical metadata, square structural geometry. | Tactical Telemetry mode: dark terminal substrate, hazard red, crosshairs, scanlines, noise, all-caps body text, simulated telemetry, ASCII chrome. |
| `design-taste-frontend-v1` | Explicit dials `DESIGN_VARIANCE=3, MOTION_INTENSITY=2, VISUAL_DENSITY=5`; complete UI states (empty, error, long-content, lifecycle) specified per surface; responsive composition rules (one column on small screens, breakpoints follow content); dependency awareness (self-hosted fonts, no new runtime deps). | React/Tailwind imposition on the WordPress stack, continuous animation, perpetual micro-interactions, the newer Taste skill substituted for v1. |
| `brandkit` | Audience/positioning discipline (academic institution, not a product); consistent application specifications (desktop/mobile branding, news cover, course-document cover, social preview, print); logo usage rules with clear space, minimum sizes, and surface restrictions; the compact variant derived as a system decision, not decoration. | Generating a replacement logo, a brand board with no functioning page specification, or invented institutional evidence. |

## 1. Direction: Preserve vs Reconsider

### Preserved (product truth, not negotiable in this rewrite)

- Bilingual `pt-BR` (authoritative) and `en` (separately reviewed) with locale-prefixed routes;
  no machine translation, no flag widgets (ADR-06).
- No third-party runtime requests, no analytics, no public forms, no embeds, no consent banner,
  no cookies for anonymous visitors (ADR-10). The site works without JavaScript.
- `theme.json` is the single source for palette, font roles, type scale, and spacing (ADR-08).
  This contract and `design-contract.json` are amended before any new value lands in code.
- Accessibility floor: WCAG 2.2 AA and eMAG, 4.5:1 text contrast, 3:1 large text and UI graphics,
  7:1 body target, visible focus on every interactive element, 44px targets, mandatory reduced
  motion, reflow at 320 CSS px and 200% zoom.
- Native semantics first; DOM order equals reading and focus order; skip link, landmarks, one H1,
  sequential headings.
- Media governance: provenance, rights, credit, contextual alt or explicit decorative decision,
  dimensions, captions/transcripts, no autoplay.
- Content model, controlled vocabularies, immutable record identity, role/capability policy
  (ADR-02 through ADR-07): untouched by this document.
- The 4px spacing scale, the 80rem container, and the 68ch reading measure.
- The supplied LPS artwork is preserved byte-for-byte as a source asset; it is never redrawn,
  recolored, or given new geometry.

### Reconsidered (the old look is the anti-reference)

- The system-ui interface stack replaced by self-hosted IBM Plex Sans; the platform mono stack
  replaced by IBM Plex Mono scoped to codes and identifiers.
- Source Serif 4's reading-container role replaced by IBM Plex Sans throughout; the serif role
  is retired.
- The incumbent navy/signal palette replaced by the resolved blue palette (section 3).
- The text-only header wordmark replaced by the full-color supplied artwork in the masthead —
  an explicit amendment scoped to this owner-supplied LPS artwork only (section 6).
- Uniform square geometry relaxed to moderate 4px control radii; images stay rectangular.
- The incumbent homepage's eight-module grammar replaced by the plan's homepage composition
  (section 7); the navigation label set is amended to the plan's teaching-aware set.

### Per-clause supersede table

| Prior clause (incumbent DESIGN.md @ sha256 `3b0ff8ec`) | Replacement | Status |
| --- | --- | --- |
| §3 palette: `--color-paper` `#FFFFFF`, `--color-paper-muted` `#EFF1F4`, `--color-navy` `#003B5C`, `--color-signal` `#007A87`, `--color-ink` `#141A1F`, `--color-ink-soft` `#46515A`, `--color-rule` `#C9CDD1`, `--color-rule-strong` `#6F7A82` | `--color-canvas` `#F5F7FA`, `--color-surface` `#FFFFFF`, `--color-anchor` `#12304A`, `--color-action` `#165A96`, `--color-text` `#182B3A`, `--color-text-muted` `#526477`, `--color-rule-quiet` `#D7E0E8`, `--color-boundary-strong` `#74869A` | SUPERSEDED (frozen by task-07) |
| §3 semantic tokens (success/warning/error + washes) | Carried forward unchanged | PRESERVED |
| §4 `--font-interface` system-ui stack | `"IBM Plex Sans", system-ui, …` self-hosted OFL | SUPERSEDED (frozen by task-07) |
| §4 `--font-editorial` Source Serif 4 reading role | Retired; IBM Plex Sans owns reading | SUPERSEDED (frozen by task-07) |
| §4 `--font-mono` platform mono stack | `"IBM Plex Mono", ui-monospace, …` scoped to codes/identifiers | SUPERSEDED (frozen by task-07) |
| §1 "Text-only header wordmark"; `lps_logo_vector.svg` unapproved | Full-color artwork in the masthead for this owner-supplied LPS artwork only; UFRJ/COPPE marks remain text-only | AMENDED |
| §10 `--radius-square` `0` for all surfaces | 4px control radii; images remain rectangular | SUPERSEDED (frozen by task-07) |
| §6 eight-module homepage grammar | Plan homepage composition (section 7) | SUPERSEDED (PROPOSAL) |
| §9 nav labels `Sobre · Pesquisa · Pessoas · Publicações · Infraestrutura · Oportunidades · Notícias` | `O LPS · Pesquisa · Pessoas · Ensino · Notícias e eventos · Oportunidades` | SUPERSEDED (PROPOSAL) |
| §0, §5 grid/spacing, §7 component semantics, §8 motion, §11 accessibility constraints and rights debt | Carried forward, updated only where a superseded clause touches them | PRESERVED |

## 2. Reference Observations — Evidence vs Interpretation

Screenshot evidence is what the captures show; interpretation is what the contract concludes.
Nothing below copies a reference asset, color, mark, or layout.

### Screenshot evidence (captured 2026-09-18, metadata in the evidence manifest)

- **FEEC desktop (1280x800):** lime-green utility bar with `Notícias`, `Sou Professor`,
  `Sou Aluno`, a flag-icon `EN` locale widget, and a search icon; navy navigation bar with
  dropdown carets on seven items; a full-width hero carousel (`Conhecimento que conecta
  pessoas`) with arrow controls and dot indicators; FEEC and UNICAMP marks in the header.
- **FEEC mobile (375x720):** the same two-tier header collapses to a compact bar; the carousel
  remains the first content.
- **PEE desktop (1280x800):** three stacked header tiers — a navy `gov.br` federal bar, a
  maroon `COPPE UFRJ` band with its own nav row, and the PEE header (`Engenharia Elétrica UFRJ`
  mark, `Home/Programa/Destaques/…` nav with dropdown carets, flag-icon locale links, search);
  below, a large hero news feature with a kicker, headline, and `LEIA A NOTÍCIA` action over a
  laboratory photograph.
- **PEE mobile (375x720):** each header tier keeps its own hamburger (three separate disclosure
  buttons stacked); flag icons remain visible; the hero headline overflows the viewport width
  (`PEE PARTICI…` is clipped at the right edge).
- **Incumbent LPS desktop (1280x800):** navy utility strip (`Laboratório de Processamento de
  Sinais / UFRJ / COPPE`), a text-only `LPS` wordmark beside the lab name, a single navigation
  row (`Sobre Pesquisa Pessoas Publicações Infraestrutura Oportunidades Notícias`), a search
  box, `PT / EN` text stops, and a navy `Colabore` button; the body shows a large `LPS` heading
  and a "not yet published" notice; a navy journeys band and navy footer close the page.
- **Incumbent LPS mobile (375x720):** the wordmark shrinks to a small `LPS`; navigation hides
  behind a `Menu e ferramentas do site` disclosure; the same sparse body follows.

### Interpretation (contract conclusions, not measurements)

- The incumbent's text-only wordmark is the clause this contract amends: the owner-supplied
  artwork now carries identity in the masthead. Everything else about the incumbent shell —
  slim header, single nav row, two-stop locale, compact footer — is directionally retained.
- FEEC confirms the audience-path principle (`Sou Professor / Sou Aluno`) and the
  research-near-top ordering already adopted; its carousel, flag widget, gradient bar, and
  mega-nav remain rejected (incumbent section-2 measurements stand).
- PEE confirms the affiliation-stack reality (federal → COPPE → program) and a content-led
  hero; it also shows what to avoid: three competing disclosure buttons on mobile, flag-icon
  locales, and a hero headline that overflows the viewport. The LPS contract keeps one
  disclosure, text-label locales, and headings that adapt within the 32–56px band.
- The incumbent's sparse body is a content state, not a design failure: the homepage spec keeps
  the "remove empty optional sections" rule so a thin record set still looks intentional.

## 3. Color

Values are the plan's resolved palette. Ratios are computed (WCAG 2.2 relative luminance); the
floor is 4.5:1 text, 3:1 large text and UI graphics, 7:1 body target. The executable table with
per-pair expectations is `design-contract.json → colors`.

| Role | Token | Value | Usage | Tag |
| --- | --- | --- | --- | --- |
| Canvas | `--color-canvas` | `#F5F7FA` | General page background | MEASURED |
| Surface | `--color-surface` | `#FFFFFF` | Reading and form surfaces; the only approved field for full-color logo artwork | MEASURED |
| Institutional anchor | `--color-anchor` | `#12304A` | Navigation band and footer band; primary fills | MEASURED |
| Anchor depth | `--color-anchor-deep` | `#0C2237` | Hover/pressed depth inside anchor bands | MEASURED (derived) |
| Action | `--color-action` | `#165A96` | Links and controls on light surfaces; focus outline on light surfaces | MEASURED |
| Action depth | `--color-action-hover` | `#0F4A7E` | Link/control hover and pressed states on light surfaces | MEASURED (derived) |
| Text | `--color-text` | `#182B3A` | Main reading text | MEASURED |
| Muted text | `--color-text-muted` | `#526477` | Secondary readable text; never essential low-contrast hints | MEASURED |
| Quiet rule | `--color-rule-quiet` | `#D7E0E8` | Decorative separators only; never the sole boundary of a control | MEASURED |
| Strong boundary | `--color-boundary-strong` | `#74869A` | Necessary light-surface control boundaries (3.74:1 on surface) | MEASURED |
| Focus on dark | `--color-focus-on-dark` | `#FFFFFF` | Focus outline on anchor bands and other dark fills | MEASURED |
| Success / Warning / Error | `--color-success` `#216E4E`, `--color-warning` `#7A4A00`, `--color-error` `#A12622` | Status text/icon plus label; never color alone | CARRIED |
| Washes | `--color-info-wash` `#DDECEF`, `--color-success-wash` `#E0ECE5`, `--color-warning-wash` `#F2E8D2`, `--color-error-wash` `#F2DEDA` | Alert backgrounds | CARRIED |

### Color rules

- Canvas and text carry the page. Surface separates reading and form areas; anchor owns the
  navigation band, primary fills, and the footer band. Action appears only where it communicates
  an interactive, focused, or selected state.
- All text/background pairs meet WCAG 2.2 AA; primary body copy targets 7:1. The contract's
  computed pairs are expectations; rendered review re-checks every actual text, control, focus,
  hover, disabled, and validation pairing after the token sync.
- State is never encoded by color alone; pair it with text, weight, rule position, icon shape,
  or native control state.
- `--color-rule-quiet` is decorative-only: it never appears as a text color, a control boundary,
  or the sole carrier of meaning.
- No gradients, alpha fog, glass, glows, or decorative color fields. The waveform gradient inside
  the supplied artwork is the single permitted gradient — it is part of the artwork, not a
  decorative effect.
- Official identity colors may replace anchor/action only after documented provenance and
  contrast verification. Extend the contract before code.

## 4. Typography

### Families

| Role | Token | Stack | Owns | Tag |
| --- | --- | --- | --- | --- |
| Interface + reading | `--font-interface` | `"IBM Plex Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif` | Display, h1–h4, navigation, controls, tables, labels, captions, body and long-form reading | MEASURED (OFL 1.1, self-hosted woff2, `font-display: swap`) |
| Technical metadata | `--font-mono` | `"IBM Plex Mono", ui-monospace, "Cascadia Mono", "Segoe UI Mono", Menlo, Consolas, monospace` | Course codes, record identifiers, code samples, tabular schedule numerals only | MEASURED (OFL 1.1, self-hosted woff2, `font-display: swap`) |

Two roles are justified: one high-legibility family for all reading and interface text, and one
mono for technical identifiers. Licensing and glyph coverage were verified 2026-09-18 (section
0). If the licensed self-hosted files cannot ship, the system stack is the recorded fallback and
the substitution is documented — the font decision does not reopen mid-execution. No remote font
loading (ADR-10). Faces evaluated and excluded remain excluded: Arial, Inter, Roboto,
Montserrat, WiredDisplay, BreveText, Apercu, WiredMono, Playfair, Source Serif 4 (retired role).

### Scale

Headings adapt within the 32–56px band rather than occupy an entire screen. Body is 16–18px;
line height 1.5–1.65; reading measure about 68ch.

| Role/token | Fluid size | Weight | Line height | Use |
| --- | --- | --- | --- | --- |
| `--type-display` | `clamp(2rem, 5vw, 3.5rem)` | 700 | 1.1 | Homepage mission statement only |
| `--type-h1` | `clamp(1.75rem, 4vw, 2.75rem)` | 700 | 1.15 | Page title |
| `--type-h2` | `clamp(1.375rem, 2.5vw, 2rem)` | 650 | 1.2 | Section head |
| `--type-h3` | `clamp(1.125rem, 1.8vw, 1.375rem)` | 650 | 1.25 | Subsection/record title |
| `--type-h4` | `1.125rem` | 650 | 1.3 | Compact heading |
| `--type-lead` | `clamp(1.125rem, 1.6vw, 1.25rem)` | 400 | 1.55 | Intro/deck, max 62ch |
| `--type-body` | `1rem` | 400 | 1.6 | Interface/body, max 72ch |
| `--type-reading` | `1.125rem` | 400 | 1.65 | Long-form reading, max 68ch |
| `--type-small` | `0.875rem` | 450 | 1.5 | Captions/help/nav links |
| `--type-meta` | `0.75rem` | 500 | 1.4 | Mono labels/metadata; never essential instructions alone |

### Type rules

- Portuguese and English wrap by phrase, never by forced `<br>`; sentence case; tabular numerals
  for schedules, years, counts, DOI fragments, and table data.
- IBM Plex Mono is restricted to course codes, identifiers, and code samples — never body text,
  never headings.
- Body text is never below 16px; captions/meta never carry essential instructions alone.
- Underlines remain visible for inline links; links are never distinguished by color alone.

## 5. Spacing, Grid & Rhythm

- Base unit 4px; the incumbent `--space-1` through `--space-24` scale is preserved unchanged.
- Maximum content width 80rem (1280px); 12-column grid on desktop, 8 conceptual columns at
  48–64rem, one column below 48rem. Outer inset `clamp(var(--space-4), 4vw, var(--space-10))`.
- Desktop section spacing 48–80px (`--space-12` to `--space-20`); smaller task-page and mobile
  gaps. Dense, orderly resource lists where the user is working; generous calm where the user is
  reading.
- Breakpoints follow content: one column on small screens; two columns only when both remain
  readable; full desktop navigation only when all labels fit.
- Source order equals reading/focus order. The document owns scrolling; no nested vertical
  scroll except an explicitly labeled table wrapper on narrow screens.
- At 320 CSS px and 200% zoom, controls wrap, labels remain visible, no text is clipped.

## 6. Identity: The Supplied Artwork

### Source asset

`assets/brand/lps_logo_vector.svg` — 53,019 bytes, SHA-256
`f369f9e49c81e297d30fa8e240267667dddf8b89f0d26c494636f9429027a23b`, viewBox `48 190 2052 301`
(aspect ≈ 6.82:1). The bytes are preserved verbatim as a source asset; the earlier name
`lps_logo_vector_transparent.svg` referred to this same artwork. The file contains six paths:
`reference-line` (baseline rule), `waveform` (the signal figure), `separator` (vertical rule),
`lps-lettering` (the "LPS" letters), `laboratory-name` ("Laboratório de Processamento de
Sinais"), and `computational-intelligence` ("Inteligência Computacional"), plus three gradients
(`wave-gradient`, `baseline-gradient`, `lps-gradient`).

### The full-color-header amendment

The repository's standing rule — text-only marks in the header, with `lps_logo_vector.svg`
unapproved — is **amended for this owner-supplied LPS artwork only**: the full-color artwork is
the masthead identity. UFRJ/COPPE marks remain text-only until their rights owner supplies files
and rules. Institutional use/rights records remain a launch gate; this amendment authorizes the
design, not the legal sign-off.

### Compact variant — documented path mapping

`assets/brand/lps_logo_compact.svg` is a derivative created by **selecting and recombining
existing source paths only** — no new geometry, no altered paths, no recolor. Every kept path's
`d` attribute is byte-identical to the source (verified by the contract validator and the
task-02 test suite).

| Mapping | Source element | Disposition |
| --- | --- | --- |
| Kept | `reference-line`, `waveform`, `separator`, `lps-lettering` | Verbatim into the compact file |
| Dropped | `laboratory-name`, `computational-intelligence` | Omitted — the long descriptive lettering cannot stay legible at mobile sizes |
| Kept | `wave-gradient`, `baseline-gradient`, `lps-gradient` | Verbatim — the waveform gradient stays inside the artwork |
| viewBox | Union of kept-path bounds x[72, 1378.1] y[208.03, 467.33] padded 8 units per side | `64 200 1322 275` (aspect ≈ 4.81:1) |

### Usage rules

- **Desktop masthead:** full artwork at 240–320px rendered width on a light surface; clear space
  equal to the waveform height on all sides; the home link carries a meaningful accessible name
  (`LPS — página inicial`) without duplicate narration of the embedded `<title>`.
- **Mobile masthead:** compact variant at 28–40px rendered height. The full lockup is never
  shrunk to fit — its descriptive lettering would fall below legibility.
- **Anchor (navy) surfaces:** text wordmark only. The artwork's dark blues measure 1.59–2.25:1
  on `--color-anchor`, so full-color artwork is restricted to light surfaces; the footer band
  uses the `LPS` text wordmark.
- The artwork never receives container chrome, recoloring, effects, or a substitute redraw.
- No compact variant existed in the repo before this contract; the mapping above is the
  documentation the plan requires.

## 7. Surface Specifications — One Identity, Four Task Densities

All four surfaces share the masthead/nav/footer shell, the palette, IBM Plex, the 4/8px rhythm,
and the logo rules. They differ only in task density — the same identity doing different work.

### 7.1 Homepage — Persuade, low density

First viewport: masthead with the full-color artwork, a concise lab introduction, and the two
primary links `Conheça a pesquisa` and `Disciplinas e materiais`. One authentic feature image
appears only when rights-cleared. Below: a research feature with linked areas; differentiated
news and upcoming-event content; a compact teaching entrance; people/collaboration links; the
institutional footer.

- **Empty:** optional sections with no reviewed records are removed; orientation and task
  entrances remain. No fixed grid of empty panels.
- **Error:** a failed featured record degrades to its text links; never a broken-image box.
- **Long content:** long Portuguese titles wrap by phrase inside the 12-column grid; news lists
  paginate.
- **Image-free:** the image-free version looks intentional — mission, links, and records carry
  the page without a placeholder box.

### 7.2 Professor — Read, medium density

Reviewed identity, affiliation and role; optional cleared portrait; research interests and
scholarly links; projects and publications through shared relationships; `Disciplinas e
materiais` grouped into `Em andamento`, `Próximas ofertas`, `Ofertas anteriores` with explicit
term and section.

- **Empty:** no current offering collapses `Em andamento`; `Ofertas anteriores` carries the
  teaching record.
- **Error:** a missing portrait renders the text layout; no placeholder silhouette.
- **Long content:** twenty prior terms paginate by year; three co-teachers list fully; long
  names wrap naturally.
- **Attribution:** past teaching attribution is preserved when login access or affiliation
  changes. No individually branded microsites.

### 7.3 Populated offering — Operate, high density

Term and section header with the teaching team; syllabus snapshot; schedule; venue or approved
class link; optional LMS handoff; ordered units with stable anchors; material links near the top
on mobile. Each resource shows descriptive title, format, size when known, authored language,
version/update information, and accessibility alternative when required. External URLs are
identified as external without promising their contents.

- **Empty:** an offering with no released materials shows syllabus and schedule with an explicit
  `materiais ainda não publicados` notice.
- **Error:** a withdrawn resource shows its withdrawn state; an invalid external link is
  identified as external.
- **Long content:** one hundred resources group under their units with stable anchors; mixed
  authored languages are labelled per resource.
- **Scan pending:** a resource whose scan is pending shows state text and no download control.

### 7.4 Editor dashboard — Operate, highest density

A task interface, not a promotional surface: `Meu perfil`, `Minhas disciplinas`, `Criar oferta`,
`Adicionar material`, `Enviar notícia`; structured fields with context-aware defaults and locked
presentation; previews and revisions.

- **Empty:** a professor with no assigned offerings sees the task list and an explicit
  assignment notice, not a blank panel.
- **Error:** validation failure names the field, cause, and repair; access denied is a distinct
  state from validation failure.
- **Long content:** long offering lists paginate; duplicate section attempts surface the
  uniqueness error.
- **Lifecycle:** saving, draft saved, in review, scheduled, public, validation failure, scan
  pending/failed, and access denied are visually distinct states; a saved draft is never styled
  as a successful publication. Destructive and historical-changing actions explain their effect
  and require confirmation. Upload progress is truthful.

## 8. Application Specifications

| Application | Logo variant | Specification |
| --- | --- | --- |
| Desktop branding | full | Masthead on canvas: artwork at 240–320px, clear space equal to the waveform height, home-link accessible name; separate primary navigation row below the masthead |
| Mobile branding | compact | Artwork at 28–40px on a light masthead; labelled disclosure button for navigation; the full lockup is never shrunk to fit |
| News cover | none in image | Cover images carry no embedded logo; the compact lockup may sit on a light caption mat beside the image when a cover template requires identification |
| Course-document cover | full | Generated covers place the artwork top-left on white at minimum 40mm width; course code in IBM Plex Mono; offering term and section below the rule |
| Social preview | full | `og:image` 1200x630: artwork centered on `--color-canvas` with the page title in `--color-text`; no photography without provenance; no text inside the artwork's clear space |
| Print | full | Full-color artwork on white at the top of the first page; navigation and interactive controls are stripped; URLs retained where useful; figure captions preserved; table headers repeat |

## 9. Components

Component semantics are preserved from the incumbent contract; surface details follow the new
palette and 4px control radii. The full component clauses (link, button, navigation, page
header, editorial record, institutional trust layout, figure, filter group, data table, form
field, alert, media block, icon) carry forward with these amendments:

- **Button:** primary fill is `--color-action` with `--color-surface` label (7.15:1); secondary
  is `--color-surface` with a 2px `--color-boundary-strong` boundary; radius 4px; pressed state
  keeps the 1px translate; minimum 44px target.
- **Link:** rest and hover use `--color-action`/`--color-action-hover` on light surfaces;
  underline always visible; focus-visible uses a 3px `--color-action` outline with 3px offset
  (3px `--color-focus-on-dark` on anchor bands).
- **Navigation:** current page uses `aria-current="page"` plus a 3px `--color-action` rule and
  weight 750 — never color alone; the mobile disclosure is a real `<button>` with
  `aria-expanded`/`aria-controls`, Escape closes and returns focus.
- **Form field:** 2px `--color-boundary-strong` structural border (3.74:1 on surface), 4px
  radius, 3px `--color-action` focus outline; errors state cause and repair.
- **Alert:** left `--color-action` rule on the matching wash; status text uses the semantic
  tokens with icon/label duplicating color semantics.
- **Icon:** project-original or approved SVG using `currentColor`; text labels remain primary;
  no emoji, raster UI icons, copied institutional marks, or flag icons for locales.

## 10. Motion & Interaction

Dials: `DESIGN_VARIANCE=3`, `MOTION_INTENSITY=2`, `VISUAL_DENSITY=5`.

| Token | Value | Use |
| --- | --- | --- |
| `--motion-fast` | `120ms` | Hover/focus color and button press |
| `--motion-standard` | `180ms` | Disclosure/state tint |
| `--ease-state` | `cubic-bezier(0.2, 0, 0, 1)` | Interruptible state response |

- Motion communicates only interaction/state. No load reveals, parallax, ambient drift,
  carousels, decorative animation, smooth-scroll hijacking, magnetic controls, marquees, pinned
  storytelling, or autoplay media.
- Only `transform`, `opacity`, `filter`, color, and text-decoration may transition. Never
  `transition: all`; never animate layout properties.
- `prefers-reduced-motion` removes movement without removing information; state remains fully
  perceivable.
- CSS visual order must match document reading and keyboard order. Focus is not animated;
  feedback appears within 100ms.

## 11. Depth & Surface

Strategy: **flat contrast with hairline structure**. Band alternation (canvas ↔ surface) and
hairline rules establish hierarchy; nothing casts a shadow.

| Token | Value | Use |
| --- | --- | --- |
| `--rule-hairline` | `1px solid var(--color-rule-quiet)` | Decorative separators only |
| `--rule-boundary` | `2px solid var(--color-boundary-strong)` | Control boundaries |
| `--rule-anchor` | `2px solid var(--color-anchor)` | Header base rule, major boundaries |
| `--rule-focus` | `3px solid var(--color-action)` | Current/focus/alert marker on light surfaces |
| `--radius-control` | `4px` | Controls and inputs only |
| `--radius-image` | `0` | All imagery stays rectangular |
| `--shadow-none` | `none` | Every primitive |

No generic card chrome, pill interfaces, box shadows, gradients, blur, glass, or faux depth.
Photographs and technical figures provide material reality; band rhythm and rules establish
hierarchy.

## 12. Accessibility Constraints & Accepted Debt

### Constraints (carried forward unchanged)

- Target WCAG 2.2 AA and eMAG; body contrast ≥ 4.5:1, large text/UI graphics ≥ 3:1, core reading
  copy targets 7:1.
- Every interactive element has a visible `:focus-visible` state — 3px outline with 3px offset,
  `--color-action` on light surfaces, `--color-focus-on-dark` on anchor bands; focus is never
  clipped or covered.
- Native semantics first; keyboard order equals DOM and visual order; skip links, landmarks, one
  H1, sequential headings, table captions/scopes, form labels, live-region restraint.
- Targets at least 44×44 CSS px with 8px separation where adjacent.
- Reflow at 320 CSS px and 200% zoom without two-dimensional page scrolling; a labeled table
  wrapper may scroll horizontally as a documented exception.
- `pt-BR`, `en`, and CJK stress text must not clip, produce tofu, or force semantic phrase
  breaks; language changes use `lang` attributes.
- Media requires provenance, rights, credit, contextual alt/decorative decision, dimensions,
  captions/transcripts, no autoplay.
- Color, position, sound, or motion never carries meaning alone; errors identify the field,
  cause, and repair.

### Accepted debt

| Item | Location | Why accepted | Owner / exit |
| --- | --- | --- | --- |
| ~~PROPOSAL token values are unfrozen in code~~ — resolved 2026-09-18 | `theme.json`, `theme.css` | Task-07 landed the values; `check-theme.mjs` now gates contract↔:root parity so drift is a hard failure | Closed by task-07; the contract gate re-runs in `qa:design-system` |
| Institutional use/rights records for the artwork | Section 6 | The amendment authorizes the design; legal sign-off is a separate launch gate | Institutional communications/rights owner |
| UFRJ/COPPE marks remain text-only | Masthead affiliation line, footer | No rights owner has supplied files or rules | Rights owner supplies authorized assets |
| ~~IBM Plex woff2 files are not yet vendored~~ — resolved 2026-09-18 | `assets/fonts/` | Task-07 vendored the six approved subset faces and retained `OFL.txt`; the system stack remains the in-stack fallback | Closed by task-07 |
| Public copy remains UNAPPROVED | All surfaces | Draft strings exist so surfaces have real measures; none are approved institutional voice | Editorial owner approves or replaces each string; EN variants pass independent review per ADR-06 |
| No human screen-reader session | Fixture and captures | The harness supplies Chromium accessibility snapshots and keyboard evidence, not a representative human AT study | Plan-wide accessibility review runs supported AT smoke evidence |
| Reference screenshots are observation, not endorsement | Section 2 | FEEC/PEE inform wayfinding and composition; no assets, colors, or copy are adopted | Standing rule; re-capture on reference redesign |

## 13. Proposed Public Copy (UNAPPROVED)

All strings below are UNAPPROVED proposals for editorial review. They assert no facts beyond
what the content model already publishes, invent no marks, and none may ship without sign-off.
The LPS acronym is never expanded in copy until the institution confirms the official form.

| Slot | PT-BR (authoritative draft) | EN (paired draft, separately reviewed) |
| --- | --- | --- |
| Logo link accessible name | `LPS — página inicial` | `LPS — home` |
| Primary nav | O LPS · Pesquisa · Pessoas · Ensino · Notícias e eventos · Oportunidades | About · Research · People · Teaching · News and events · Opportunities |
| Utility | Busca · PT / EN · Área docente | Search · PT / EN · Faculty area |
| Homepage primary links | `Conheça a pesquisa` · `Disciplinas e materiais` | `Explore the research` · `Courses and materials` |
| Offering empty state | `Materiais ainda não publicados` | `Materials not yet published` |
| Footer affiliation line | `LPS — COPPE/UFRJ` (formal wording pending institutional confirmation) | same |
| Footer utility links | Contato · Eventos · Privacidade · Acessibilidade | Contact · Events · Privacy · Accessibility |
