# LPS Institutional Design Contract — Replacement Direction

Status: Binding contract for the public LPS theme and its editor-facing primitives. This revision replaces the warm-paper "Scientific Editorial" look with a light institutional, sans-led direction. The standalone showcase under `showcase/scientific-editorial/` remains the executable reference; it is not production theme code.

This is a replacement-world contract: product truth and constraints are preserved intact, while the prior visual direction is retained only as an anti-reference. Where a clause is superseded, the supersede table in section 1 says so explicitly. Nothing here weakens accessibility, rights, or governance requirements, and this document specifies one direction, not a menu of styles.

## 0. Contract Basis, Tags & Research Log

### Tag convention

| Tag | Meaning |
| --- | --- |
| MEASURED | Value read verbatim from worktree source (`theme.json`, `theme.css`, prior DESIGN.md) or instrumented from live FEEC captures. Basis is cited. |
| PROPOSAL | Design decision with no instrumented source: new token, changed value, or changed role mapping. Binding as direction; frozen in code only when the token sync lands. |
| UNAPPROVED | Public-facing copy, marks, or placements that require an authorized owner. Never ships as written until approved. |

### Evidence base

- FEEC live deconstruction, 2026-09-16: real Chromium 151 via Playwright at 1440x900 and 390x844 across home, two research pages, and one institutional page; computed styles, DOM structure, tab stops, and no-image behavior captured. Full matrix: `.omo/evidence/lps-feec-redesign/task-2-feec.md`. Influence is limited to principles and composition; no FEEC copy, marks, colors, IA, or code is adopted.
- Replacement token candidates, 2026-09-16: `.omo/evidence/lps-feec-redesign/task-6-tokens.md` at HEAD `fd82071`. All candidate values in sections 3-6 reuse that evidence verbatim, with its MEASURED/PROPOSAL tags and computed contrast ratios.
- Governing ADRs: ADR-01 (integrated WordPress, no page builder), ADR-08 (`theme.json` is the single token source and the design contract is executable), ADR-10 (no third-party runtime requests, no cookies for anonymous visitors). See `docs/architecture/decision-record.md`.

### Research log (preserved from the prior contract, still true)

- Brief and plan: `.omo/drafts/lps-institutional-website.md` and Todo 6 of `.omo/plans/lps-institutional-website.md`; approved research-first, bilingual, no-startup-tropes direction stands.
- WIRED remains atmosphere-only: typographic hierarchy, hairline rules, square figures, disciplined whitespace. No WIRED copy, logo, proprietary type, palette, density, or weak focus treatment.
- Lazyweb harvest (2026-08-30, 4 viewed screens): metadata-forward publication rows, narrow reading measures, side taxonomy on wide screens, evidence before promotion. Rejected: gradient hero chrome, signup gating, tiny dense nav, vendor CTA emphasis.
- StyleGallery: `twelve-span-grid` for placement, `page-grid` for containment, `ram-grid` for record listings, `sticky-aside` for trust metadata. The document owns vertical scroll; DOM order is reading and focus order.
- Interaction reference (beui.dev): immediate, interruptible state feedback and explicit reduced-motion behavior. No spring, magnetic, morphing, or decorative movement.
- Asset provenance, 2026-08-30: UFRJ and COPPE home pages exposed current logo URLs but no reusable license or identity manual; `lps.ufrj.br` timed out. No institutional mark is copied. Production use stays blocked until an authorized owner supplies files, usage rules, and permission.
- Font provenance: Source Serif 4 is SIL OFL 1.1, self-hosted. Interface and mono roles use platform stacks. CJK falls through to platform CJK fonts.
- Imagen concepts: skipped; no image-generation tool in this harness. The browser-rendered showcase is the visual contract.

## 1. Direction: Preserve vs Reconsider

### Preserved (product truth, not negotiable in this rewrite)

- Bilingual `pt-BR` (authoritative) and `en` (separately reviewed) with locale-prefixed routes; no machine translation, no flag widgets (ADR-06).
- No third-party runtime requests, no analytics, no forms, no embeds, no consent banner, no cookies for anonymous visitors (ADR-10). The site works without JavaScript.
- `theme.json` is the single source for palette, font roles, type scale, and spacing (ADR-08). Extend this document and `theme.json` before any new value is used.
- Accessibility floor: WCAG 2.2 AA and eMAG, 4.5:1 text contrast, 3:1 large text and UI graphics, 7:1 body target, visible focus on every interactive element, 44px targets, mandatory reduced motion, reflow at 320 CSS px and 200% zoom.
- Native semantics first; DOM order equals reading and focus order; skip link, landmarks, one H1, sequential headings.
- Square geometry, hairline rules, no shadows, gradients, glass, or glow.
- Media governance: provenance, rights, credit, contextual alt or explicit decorative decision, dimensions, captions/transcripts, no autoplay.
- Text-only header wordmark. `lps_logo_vector.svg` is unapproved; any future mark placement is conditional on rights and written approval.
- Content model, controlled vocabularies, immutable record identity, role/capability policy (ADR-02 through ADR-07): untouched by this document.
- Three font roles, the 4px spacing scale, the 80rem container, and the 68ch reading measure.

### Reconsidered (the old look is the anti-reference)

- Warm-paper canvas replaced by a light institutional canvas.
- Serif-first hierarchy replaced by sans-led display, headings, navigation, and controls; Source Serif 4 retained only inside reading containers.
- Uniform hairline record rows replaced by differentiated section modules with an alternating band rhythm.
- The flat font ban list replaced by an evaluated, documented stack with explicit exclusions.
- Borders-only depth replaced by FEEC-informed flat contrast: band alternation plus rules, still zero elevation.
- The prior "From FEEC" deviation line replaced by the updated, live-measured rejection list in section 2.

### Per-clause supersede table

| Prior clause (DESIGN.md @ sha256 `a9f7e11e`) | Replacement | Status |
| --- | --- | --- |
| §1 atmosphere: "peer-reviewed paper" warmth on paper canvas | Light institutional canvas; laboratory evidence leads; identity supports | SUPERSEDED |
| §2 `--color-paper` `#F7F4EC`, `--color-paper-raised` `#FFFEFA`, `--color-paper-muted` `#ECE8DE` | `#FFFFFF`, `#FFFFFF`, `#EFF1F4` | SUPERSEDED (landed) |
| §2 `--color-focus-offset` `#F7F4EC` | `#FFFFFF` (retargeted with canvas) | SUPERSEDED (landed) |
| §3 serif owns display, deck, quotations, headings | Interface stack owns display, h1-h4, nav, controls, tables, labels; serif owns `.lps-reading` and `.wp-block-post-content` only | SUPERSEDED (landed) |
| §3 type weights 600-led headings, serif display tracking | Sans-led weights 650-750, retuned tracking/line-height per section 4 | SUPERSEDED (landed) |
| §3 ban list: "Never use Arial, Inter, Roboto, Montserrat, ..." | Evaluated-stack rule: three documented roles, platform stacks, OFL self-hosted serif; exclusions restated as evaluation outcomes | SUPERSEDED |
| §5 uniform hairline-separated record stacks as the default section grammar | Differentiated modules per section 6; record rows remain one module grammar among several | SUPERSEDED |
| §7 "borders-only editorial depth" | Flat contrast: white/section-gray band alternation, hairline rules, at most one interior navy band plus the navy footer band | SUPERSEDED |
| §8 "From FEEC" deviation line (2026-08-30 audit) | Updated rejection list from 2026-09-16 live captures, section 2 | SUPERSEDED |
| §0, §4 grid/spacing, §5 component semantics, §6 motion, §8 accessibility constraints and rights debt | Carried forward, updated only where a superseded clause touches them | PRESERVED |

## 2. FEEC Influence Boundary

FEEC is a principles-and-composition reference only. Every adopted item below is a pattern; every rejected item is a measured fact from the 2026-09-16 captures. FEEC brand colors (`#00427A` navy, `#B0D361` lime, `#FF7A00` orange), copy, photography, marks, information architecture, and technology are never adopted. Quoted FEEC text in the evidence file is measurement, not LPS voice.

### Adopted as principle (adapted to LPS tokens and IA)

- Explicit audience paths: FEEC puts "Sou Professor / Sou Aluno" shortcuts in its utility bar. LPS keeps the principle inside the journeys module and the Colabore route, not a second header tier.
- Research near the top: FEEC's research block is the third homepage section. LPS places research themes immediately after the hero.
- Alternating band rhythm: FEEC alternates white and navy bands at roughly 64-80px gaps. LPS alternates white and section-gray at `--space-16` to `--space-24`, reserving navy for the footer band, primary fills, and at most one interior band per page.
- One primary action per section: FEEC's single "Estude na FEEC" under the mission is the right density. LPS keeps one primary CTA per module.
- Static hero with an early H1: FEEC's mobile ordering puts the mission statement in the first viewport. LPS requires the mission H1, one evidence figure, and the primary CTA inside the first viewport at both breakpoints, with no rotation.
- Slim interior page header: breadcrumb plus H1 on canvas, no stock photo banner required. Breadcrumb current page is `aria-current` text, not a colored link.
- Compact footer with real contact routing: keep the idea, cut the scale.
- Mobile disclosure done right: FEEC's Elementor toggle already handles Enter and Escape correctly. LPS requires a real `<button>` with `aria-expanded`/`aria-controls`, a panel limited to the 7 primary items plus search and locale, Escape closes and returns focus.
- Two-stop locale control: text labels "PT/EN" bound to route pairs, never a multi-locale widget.

### Rejected (measured 2026-09-16, all rows in task-2 evidence)

- Two-tier 161px header with a 64px gradient utility bar (lime to navy) over a 97px logo/nav bar.
- 12-13 `<nav>` regions per page and a 73-link mega-nav; dropdowns only if keyboard and ARIA complete.
- 560px six-slide hero carousel; off-screen slides parked at x=-100000 still received keyboard focus with `outline:none`.
- Montserrat-only hierarchy carried by weight and color alone (body 16/400, H1 40/800, line-height 0.95).
- Gradient pill CTAs: 24px radius, navy-to-green fill, 1px white outline secondary pills.
- 801px footer with 214 links duplicating the header sitemap, plus a decorative circuit-board strip.
- GTranslate machine-translation widget: 9 locales, flag icons, several with empty `src`.
- Orange `#FF7A00` breadcrumb at 2.9:1 on white, fails AA.
- `div`-role-button hamburger and unlabeled 32px arrow controls.
- Empty-`src` image placeholders (36 on home) and a partner logo wall with visibly empty slots.
- Browser-default focus (`outline auto`) and focus that lands on off-screen carousel content.

## 3. Color

### Palette

Values reuse the task-6 candidates verbatim. Contrast ratios are computed in that evidence (WCAG 2.2 relative luminance); the floor is 4.5:1 text, 3:1 large text and UI graphics, 7:1 body target.

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

### Color rules

- White canvas and ink carry the page. Section gray separates modules; navy anchors the header rule, primary actions, and the footer band. Signal teal appears only where it communicates an interactive, selected, focused, or data-key state.
- Band alternation is structural, not decorative: adjacent modules never share a band color, and band color never carries meaning by itself.
- Signal is never body text on section gray (4.49:1 fails the text floor); use signal-hover for text on gray.
- All text/background pairs meet WCAG 2.2 AA; primary body copy targets 7:1.
- State is never encoded by color alone; pair it with text, weight, rule position, icon shape, or native control state.
- Official identity colors may replace navy/signal only after documented provenance and contrast verification. Extend this table before code.
- No gradients, alpha fog, glass, glows, or decorative color fields. FEEC's gradient bar and gradient pill are on the rejection list.

## 4. Typography

### Families

Sans-led hierarchy: display, headings, navigation, and controls use the interface stack. Source Serif 4 is retained only for reading containers. The system-ui stack was already evaluated (platform-native metrics, multilingual coverage, zero shipped bytes); it is not an Inter, Roboto, or Montserrat copy, and FEEC's Montserrat-only hierarchy stays rejected.

| Role | Token | Stack | Owns | Tag |
| --- | --- | --- | --- | --- |
| Interface + display | `--font-interface` | `system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif` | Display, h1-h4, nav, controls, tables, labels, captions, short body | MEASURED |
| Reading serif | `--font-editorial` | `"Source Serif 4", "Noto Serif", "Noto Serif CJK SC", "Noto Serif CJK JP", Georgia, serif` | `.lps-reading` and `.wp-block-post-content` only: body text and headings inside those containers | MEASURED (OFL 1.1, self-hosted woff2, `font-display: swap`) |
| Technical metadata | `--font-mono` | `ui-monospace, "Cascadia Mono", "Segoe UI Mono", Menlo, Consolas, monospace` | Dates, kickers, status, locale labels, code, tabular data | MEASURED (incumbent) |

Three roles are justified: high-legibility interface and display, scholarly long-form reading, and aligned technical data. No proprietary face is permitted. Faces evaluated and excluded remain excluded: Arial, Inter, Roboto, Montserrat, WiredDisplay, BreveText, Apercu, WiredMono, Playfair. New candidates enter only through this table with provenance and license recorded.

### Scale

Sizes are retained (MEASURED). Weights, line heights, and tracking are the sans-led remap, frozen in `theme.css` (MEASURED).

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

Navigation and control weights: nav rest 650, `aria-current` 750, button and search labels 700 (MEASURED in `theme.css`; inside the contracted 600-750 band).

### Type rules

- Portuguese and English wrap by phrase, never by forced `<br>`. Use balanced wrapping for short headings where supported and ordinary wrapping as fallback.
- CJK uses `word-break: normal`, `line-break: strict`, and platform CJK fallbacks. Do not add letter spacing to CJK paragraphs. Avoid isolated particles/endings and one-glyph final lines by adjusting measure or type step, not clipping.
- Body text is never below 16px; captions/meta never carry essential instructions alone.
- Use tabular numerals for years, counts, DOI fragments, axes, and table data.
- Underlines remain visible for inline links. Never distinguish links by color alone.

## 5. Spacing, Grid & Band Rhythm

### Spacing scale

Base unit: 4px. Intentional spacing uses only these tokens (all MEASURED, incumbent).

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

### 12-column editorial grid

- `--grid-max: 80rem` (1280px); document-centered. Reading measure `--measure-reading: 68ch`; interface measure `--measure-interface: 72ch`; lead measure `--measure-lead: 62ch`.
- Mobile `< 48rem`: 4 conceptual columns, `--grid-gutter: 1rem`; regions span full width unless explicitly paired.
- Tablet `48rem-63.99rem`: 8 conceptual columns, `--grid-gutter: 1.5rem`; labels/notes may span 2 while content spans 6.
- Desktop `>= 64rem`: 12 columns, `--grid-gutter: 2rem`; section label 3 columns, primary content 7, contextual note 2. The label takes three columns because a two-column label cannot hold a Portuguese h2 without mid-word wrapping. Figures may span 8-10; long-form text stays 6-7 columns.
- Outer inset: `clamp(var(--space-4), 4vw, var(--space-10))`.
- The `lps-page-grid` contains the page and `lps-home-section`/`lps-editorial-section` place modules on it. The document owns scrolling. No nested vertical scroll except an explicitly labeled table wrapper on narrow screens; that wrapper receives keyboard focus and an accessible description.
- Source order equals reading/focus order. CSS placement must not reorder semantic content.
- Browser mechanics (`auto`, percentages, `minmax()`, `clamp()`, intrinsic sizing) remain raw; design intent resolves to tokens.

### Band rhythm (replaces uniform rows)

- Homepage and landing sections render as full-width bands alternating white canvas and section gray, separated by `--space-16` to `--space-24` internal rhythm. Adjacent modules never share a band color.
- Navy is reserved: the footer band, primary action fills, the header rule, and at most one interior band per page (MEASURED: the journeys module is that band). White text on navy is 11.80:1.
- Band color is a container property only; component internals (records, tables, forms) keep their own hairline structure inside the band.

### Responsive/stress contract

- At 375px all primary content reflows to one readable column with no page-level horizontal overflow. Navigation wraps or discloses; it never hides essential destinations.
- At 768px paired comparisons may form two columns; labels remain adjacent to their content.
- At 1280px the full 12-column editorial rhythm is visible; text measure remains bounded.
- At 200% zoom and 320 CSS px, controls wrap, labels remain visible, and no text is clipped or ellipsized.
- Long URLs/DOIs may break with `overflow-wrap: anywhere`; ordinary words and CJK phrases use natural line breaking.
- Print strips interaction-only controls, retains URLs where useful, preserves figure captions, repeats table headers, and avoids splitting alerts/figures when possible.

## 6. Homepage Thesis: Eight Differentiated Modules

The homepage body is a sequence of eight locked modules, each with a distinct job and a distinct surface grammar, rendered in this order by `front-page.html` between the header and footer shell. Modules are not interchangeable rows; each is specified once here and reused nowhere else on the page. The logical `evidence` stratum renders inside `research`, and the `contact` handoff renders inside `partners`, so the ten logical sections map onto the eight rendered modules. All public copy inside modules is UNAPPROVED until editorial sign-off (section 9).

| # | Module | Job | Surface grammar | Band |
| --- | --- | --- | --- | --- |
| 1 | Mission | State the mission, show one piece of evidence, offer one action | `--type-display` H1 + lead + one primary CTA + one rights-cleared evidence figure, all inside the first viewport at 375px and 1280px. No carousel, no autoplay. Keeps a compact not-published notice when no reviewed mission record exists | White |
| 2 | Journeys | Convert: join, collaborate, partner | Three explicit audience paths as text-led blocks with one CTA each; the single interior navy band. A journey whose destination is unpublished renders as a disabled action, never a link | Navy |
| 3 | Research themes | Route into the controlled vocabulary | Metadata-forward rows: area label + domain tags + owning-unit provenance, linked titles; the evidence stratum shares this band. Not image cards, no per-card carousel | Section gray |
| 4 | Featured projects | Prove with outputs | Numbered editorial records inside the shared muted band; dated, hairline-separated, no chrome | Section gray (shared with research) |
| 5 | People | Show the laboratory is real | Authentic people figures with provenance captions beside role-based routes | White |
| 6 | Infrastructure | Show capabilities | Equipment/capability records with provenance captions; shares the white band with people | White (shared with people) |
| 7 | Latest | Retain: publications, news, events | `lps-listing` of dated records without chrome; status text explicit | Section gray |
| 8 | Partners and funders | Attribute and route | Partner/funder records plus the collaboration/contact handoff, which keeps a compact not-published notice when no reviewed contact record exists | White |

The enclosing shell is the slim institutional header (text-only wordmark, 7 primary nav items, search, PT/EN toggle, navy 2px base rule) and the compact navy footer band (affiliation line, 4 utility links, contact route; no duplicated sitemap, no decorative strip).

Interior pages use a slim page-header module: breadcrumb (hairline-separated, current page as `aria-current` text) plus `--type-h1` on canvas. A banner image is optional and must carry real laboratory provenance; it is never required for the page to read correctly.

## 7. Components

Component semantics are preserved from the prior contract; surface details now follow the light canvas and sans-led hierarchy.

### Link
- **Structure**: semantic `<a href>` with descriptive text; optional trailing inline SVG for external/download semantics.
- **Variants**: inline, navigation, quiet metadata, external.
- **Spacing**: `--space-1`/`--space-2` only.
- **States**: default underline; hover uses signal-hover and thicker underline; active uses navy-hover; focus-visible uses a 3px signal outline with 3px offset; visited is deliberately not recolored in institutional navigation; disabled links are rendered as text, never fake anchors.
- **Accessibility**: no "click here"; external behavior named where unexpected; icons are `aria-hidden` when text supplies the name.
- **Motion**: color/decoration change in `--motion-fast`; instant under reduced motion.
- **Layout**: inline/cluster; document scroll.

### Button
- **Structure**: native `<button>` or true link styled as action; label plus optional project-original SVG.
- **Variants**: primary navy fill, secondary white with 2px navy rule, quiet text action, destructive. Square geometry; no pill radius, no gradient fill.
- **Spacing**: `--space-3` block and `--space-5` inline; minimum 44px target.
- **States**: default, hover, focus-visible, pressed (`transform: translateY(1px)` only), disabled, busy (`aria-busy` plus stable label), success/error text announced nearby.
- **Accessibility**: native disabled semantics, discernible name, no icon-only critical action.
- **Motion**: 120ms color/transform, interruptible; reduced motion removes transform and duration.
- **Layout**: cluster; no internal scroll.

### Navigation
- **Structure**: labeled `<nav><ul>`; current page uses `aria-current="page"`; locale and search are separate named groups. Exactly one primary nav and one footer nav per page.
- **Variants**: primary, local/section, utility.
- **Spacing**: `--space-2`, `--space-4`, `--space-6`.
- **States**: rest, hover, focus, pressed, current. Current state uses a 3px signal rule and weight 750, not color alone.
- **Accessibility**: 44px targets; wrapping labels; logical DOM order; no hover-only submenu; skip link precedes shell. The locale control is two stops maximum.
- **Motion**: color only; no sliding indicator.
- **Layout**: wrapping cluster on desktop; mobile disclosure uses a real `<button>` with `aria-expanded`/`aria-controls`, a panel limited to the 7 primary items plus search and locale, Escape closes and returns focus.

### Page Header (interior)
- **Structure**: breadcrumb list (`aria-current` on the current page, rendered as text) followed by `--type-h1`; optional provenance-cleared banner image below.
- **Spacing**: `--space-2`, `--space-4`, `--space-8`.
- **Accessibility**: breadcrumb is a labeled nav; the banner never carries the H1 as embedded image text.
- **Layout**: slim band on canvas; no required photo.

### Editorial Record Card (card without chrome)
- **Structure**: `<article>` with kicker, linked title, summary, metadata, optional figure.
- **Variants**: feature, compact, numbered, dated opportunity/event/news.
- **Spacing**: `--space-2`, `--space-4`, `--space-6`.
- **States**: only the actual title link responds; article itself is not falsely clickable. Opportunity and event state uses the status primitive with explicit text. No lift, scale, rounded container, fill, or shadow.
- **Accessibility**: one heading; link text remains meaningful out of context; date values use semantic `<time>`.
- **Motion**: none on article.
- **Layout**: `lps-listing` repeats records across the band with no internal scroll; each record remains a hairline-separated stack inside its band.

### Institutional Trust Layout
- **Structure**: primary `<article>` followed in source order by an `<aside>` named for verification and contact details.
- **Variants**: About/History/Governance, Collaboration/Contact, Privacy/Accessibility.
- **Spacing**: `--space-4`, `--space-6`, `--space-8`, `--space-12`.
- **States**: verified facts show source and last-reviewed date; stale or absent evidence becomes an explicit warning/block rather than a positive claim or invented contact.
- **Accessibility**: headings remain sequential; role contacts are descriptive `mailto:` links; source URLs are breakable; no form, cookie banner, or data collection appears.
- **Motion**: none.
- **Layout**: ordinary document flow on narrow screens; at desktop, `lps-institutional` places the `lps-verification` aside beside long copy (`position: sticky`) while the document remains the only vertical scroll owner.

### Figure
- **Structure**: `<figure>` containing rights-cleared media or project-original SVG/data graphic and `<figcaption>` with description, source, credit, and rights status.
- **Variants**: documentary image, technical diagram, data plot, media placeholder.
- **Spacing**: caption `--space-2`; outer rhythm `--space-6`.
- **States**: noninteractive by default; interactive data requires keyboard-equivalent table and named state.
- **Accessibility**: informative image has contextual alt; complex figure has nearby text/table; decorative asset uses empty alt; explicit dimensions/aspect ratio prevent shift. No empty-`src` placeholders; lazy loading uses the `loading` attribute.
- **Motion**: none.
- **Layout**: square geometry, full column width; no faux-science decoration. Content never depends on imagery: figures are evidence, never load-bearing for navigation or claims.

### Filter Group
- **Structure**: `<form>` or `<fieldset>` with `<legend>`, native checkboxes/radios, apply/reset actions, and result count/status.
- **Variants**: inline facets, stacked facets.
- **Spacing**: `--space-2`, `--space-4`, `--space-6`.
- **States**: rest, hover, focus, checked, disabled, empty, error. Native state is always preserved.
- **Accessibility**: shareable server-rendered results; color-independent selection; labels own 44px targets.
- **Motion**: color only; none under reduced motion.
- **Layout**: wrapping cluster or stack; document scroll.

### Data Table
- **Structure**: caption, semantic table, scoped headers, optional sortable buttons with `aria-sort`, and narrow-screen focusable overflow wrapper.
- **Variants**: scholarly records, technical measurements.
- **Spacing**: cell `--space-3`/`--space-4`.
- **States**: row rest/hover/focus-within/selected, sort state, empty/error. No zebra striping required; band alternation is a page-level device, not a row device.
- **Accessibility**: caption names purpose; units belong in headings; a linear list alternative is preferred when relationships matter more than comparison.
- **Motion**: none.
- **Layout**: full-width; wrapper alone may own horizontal scroll on narrow viewports.

### Form Field
- **Structure**: persistent `<label>`, native input/select/textarea, optional help, inline error linked by `aria-describedby`.
- **Variants**: default, required, optional, readonly, disabled, error, success.
- **Spacing**: `--space-2`, `--space-3`, `--space-4`.
- **States**: rest, hover, focus, valid, invalid, disabled, readonly; 2px structural border and 3px focus outline.
- **Accessibility**: no placeholder-only label; errors state cause and repair; first invalid field receives focus after submit. Public surfaces collect nothing (ADR-10); this primitive serves search, filters, and editor-facing UI.
- **Motion**: color only; instant under reduced motion.
- **Layout**: stack; paired fields collapse at 48rem.

### Alert
- **Structure**: status icon, concise heading, actionable message; `role="status"` for passive updates and `role="alert"` only for urgent errors.
- **Variants**: info, success, warning, error.
- **Spacing**: `--space-4`, `--space-6`.
- **States**: static, actionable, dismissible only when persistence policy is defined.
- **Accessibility**: icon/label duplicates color semantics; links explain recovery.
- **Motion**: no auto-dismiss or entrance animation.
- **Layout**: left signal rule, never floating toast in the institutional default.

### Media Block
- **Structure**: rights-status header, bounded media/placeholder, caption, transcript/download controls.
- **Variants**: image, video poster, audio/transcript, unavailable pending rights.
- **Spacing**: `--space-2`, `--space-4`, `--space-6`.
- **States**: ready, loading, unavailable, rights-blocked, caption/transcript available.
- **Accessibility**: no autoplay; native controls; captions/transcript; no public use while rights are unknown.
- **Motion**: media playback only by user action.
- **Layout**: intrinsic aspect ratio; no clipping.

### Icon
- **Structure**: project-original or approved SVG using `currentColor`; text labels remain primary.
- **Variants**: 16px inline, 20px control, 24px prominent; consistent 1.75px square-ended stroke.
- **Accessibility**: decorative icons `aria-hidden`; standalone icons require a visible or accessible name and 44px target.
- **Rules**: no emoji, raster UI icons, Lucide/Feather/Heroicons, mixed filled/outline families, flag icons for locales, or copied institutional marks.

## 8. Motion & Interaction

| Token | Value | Use |
| --- | --- | --- |
| `--motion-fast` | `120ms` | Hover/focus color and button press |
| `--motion-standard` | `180ms` | Disclosure/state tint if later required |
| `--ease-state` | `cubic-bezier(0.2, 0, 0, 1)` | Interruptible state response |

- Motion communicates only interaction/state. No load reveals, parallax, ambient drift, carousels, decorative graph animation, smooth-scroll hijacking, magnetic controls, or hover movement on noninteractive surfaces.
- Only `transform`, `opacity`, `filter`, color, and text-decoration may transition. Never use `transition: all` or animate layout.
- The only spatial movement in the primitive set is a 1px pressed button response. It does not alter layout and disappears under `prefers-reduced-motion: reduce`.
- Reduced motion sets all transition/animation durations to effectively zero and removes transforms. Content and state remain fully legible.
- Focus is not animated. Feedback appears within 100ms and never waits for motion.

## 9. Proposed Public Copy (UNAPPROVED)

All strings below are UNAPPROVED proposals for editorial review. They assert no facts beyond what the content model already publishes, they invent no marks, and none may ship without sign-off. The LPS acronym is never expanded in copy until the institution confirms the official form.

| Slot | PT-BR (authoritative draft) | EN (paired draft, separately reviewed) |
| --- | --- | --- |
| Wordmark (text-only) | `LPS` | `LPS` |
| Primary nav | Sobre · Pesquisa · Pessoas · Publicações · Infraestrutura · Oportunidades · Notícias | About · Research · People · Publications · Infrastructure · Opportunities · News |
| Primary CTA | `Colabore` | `Collaborate` |
| Hero mission | `Pesquisa em engenharia com evidência publicada e um laboratório aberto à colaboração.` | `Engineering research with published evidence and a laboratory open to collaboration.` |
| Journeys module label | `Escolha como participar` | `Choose how to take part` |
| Footer affiliation line | `LPS — COPPE/UFRJ` (formal affiliation wording pending institutional confirmation) | `LPS — COPPE/UFRJ` (same) |
| Footer utility links | Contato · Eventos · Privacidade · Acessibilidade | Contact · Events · Privacy · Accessibility |
| Locale toggle | `PT` / `EN` text labels bound to route pairs | same |

## 10. Depth & Surface

Strategy: **flat contrast**. Band alternation and hairline rules establish hierarchy; nothing casts a shadow.

| Token | Value | Use |
| --- | --- | --- |
| `--rule-hairline` | `1px solid var(--color-rule)` | Sections, records, table rows |
| `--rule-strong` | `2px solid var(--color-navy)` | Controls, header base rule, major boundaries |
| `--rule-signal` | `3px solid var(--color-signal)` | Current/focus/alert marker |
| `--radius-square` | `0` | All rectangular surfaces |
| `--shadow-none` | `none` | Every primitive |

Section gray is a flat grouping field, not elevation. No generic card chrome, rounded rectangle containers, box shadows, drop shadows, gradients, blur, glass, or faux depth. Photographs and technical figures provide material reality; band rhythm and rules establish hierarchy.

## 11. Accessibility Constraints, Deviations & Accepted Debt

### Constraints

- Target WCAG 2.2 AA and eMAG; body contrast >= 4.5:1, large text/UI graphics >= 3:1, and core reading copy targets 7:1.
- Every interactive element has a visible `:focus-visible` state with at least a 3px signal outline and 3px offset; focus is never clipped or covered.
- Native semantics first. Keyboard order equals DOM and visual reading order. Skip links, landmarks, one H1, sequential headings, table captions/scopes, form labels, descriptions, and live-region restraint are mandatory.
- Targets are at least 44 by 44 CSS px with 8px separation where adjacent.
- Reflow works at 320 CSS px and 200% zoom without two-dimensional page scrolling. A labeled table wrapper may scroll horizontally as a documented exception.
- Portuguese (`pt-BR`), English (`en`), and CJK stress text must not clip, produce tofu, or force semantic phrase breaks. Language changes use `lang` attributes.
- Media requires provenance, rights, credit, contextual alt/decorative decision, dimensions, captions/transcripts, and no autoplay.
- `prefers-reduced-motion` is mandatory; state remains perceivable with no animation.
- Color, position, sound, or motion never carries meaning alone. Errors identify the field, cause, and repair.
- At production integration, automated axe/Playwright checks augment but do not replace keyboard, accessibility-tree, zoom, CJK, and visual review.

### Reference deviations

- **From FEEC**: adopt audience-path ordering, early research placement, band alternation, single-CTA density, static hero with early H1, slim page headers, compact footer, correct mobile disclosure, and a two-stop locale control. Reject the two-tier gradient header, mega-nav duplication, hero carousel, Montserrat-only hierarchy, gradient pills, the 214-link footer, machine-translation flags, sub-AA breadcrumb color, div-role controls, empty-`src` media, and browser-default focus (section 2, all measured).
- **From WIRED**: preserve rule/whitespace discipline, square geometry, and separated type roles; replace every proprietary typeface and brand token; reduce news density; add robust focus, form semantics, multilingual metrics, and calm institutional color.
- **From Lazyweb screens**: preserve evidence rows and metadata adjacency; reject gradient corporate hero fields, signup gates, tiny utility nav, sponsor-feed density, and promotional metrics.
- **From minimalist reference**: preserve macro-whitespace and restraint; reject rounded bento boxes, shadow-lift cards, remote placeholder photography, ambient gradients, and generic reveal animation.

### Accepted debt

| Item | Location | Why accepted | Owner / exit |
| --- | --- | --- | --- |
| Official UFRJ/COPPE/LPS marks are represented by text only | Header wordmark, showcase, future shell | Live pages prove current files exist but do not prove reuse rights or provide identity rules; `lps_logo_vector.svg` is unapproved and its placement is conditional | Institutional communications/rights owner supplies authorized source files, license/permission, and clear-space/color rules before any mark ships |
| ~~PROPOSAL token values are unfrozen in code~~ — resolved | `theme.json`, `theme.css`, showcase | The token freeze landed: palette slugs, the sans-led font remap, `--motion-standard`, and `--color-focus-offset` are all in `theme.json`/`theme.css` | Closed; `check-design-system.mjs` and `theme-contract.test.mjs` pass against the frozen values |
| Source Serif 4 CJK glyphs depend on platform fallback | Multilingual typography | Bundling full Noto CJK subsets would add substantial weight before final locale corpus is known | Font packaging selects tested, subsetted open-source CJK fallback if target platforms show metric/tofu defects |
| No human screen-reader session in this task | Standalone showcase | Linux harness supplies Chromium accessibility snapshots and keyboard/axe evidence, not a representative human AT study | Plan-wide accessibility review runs supported AT smoke evidence; any unsupported platform remains explicit |
| No exact visual-reference image | Research log | Imagen tooling unavailable and FEEC/WIRED are hierarchy/atmosphere sources, not clone targets | Primitive browser captures are the contract; production pages receive fresh visual QA |
| UNAPPROVED public copy | Section 9 | Draft strings exist so modules have real measures to design against; none are approved institutional voice | Editorial owner approves or replaces each string; EN variants pass independent review per ADR-06 before publication |
