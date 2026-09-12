# LPS Scientific Editorial Design System

Status: Binding contract for the public LPS theme and its editor-facing primitives. The standalone showcase under `showcase/scientific-editorial/` is the executable reference; it is not production theme code.

## 0. Research Log

- Brief and plan: read `.omo/drafts/lps-institutional-website.md` and Todo 6 of `.omo/plans/lps-institutional-website.md`; preserved the approved research-first, bilingual, no-startup-tropes direction.
- Existing system audit: no inherited component layer exists. Todo 1 supplies only a block-theme boundary (`style.css`, `theme.json`, `templates/index.html`) and content-plugin bootstrap. No visual token or reusable component was available to preserve.
- Embedded references: shortlisted WIRED, Notion, and IBM from `_INDEX.md`; selected `minimalist-skill.md` + `wired.md`. WIRED is atmosphere-only: typographic hierarchy, hairline rules, square figures, and disciplined whitespace. LPS uses no WIRED copy, logo, proprietary type, exact palette, content density, or weak focus treatment.
- FEEC runtime audit, 2026-08-30: real Playwright Chromium at 375, 768, and 1280 captured `getComputedStyle`, rest/hover/focus states, responsive screenshots, headings, media, and overflow in `.omo/evidence/task-6/research/feec/`. Lessons retained: explicit audience paths, research near the top, square geometry, direct institutional proof. Rejected: 12 navigation regions, duplicate headings, machine-translation flags, generic Montserrat-only hierarchy, broad `transition: all`, underspecified mobile controls, empty/zero-size image sources, carousel-like duplication, and focus that relies on browser defaults.
- Lazyweb: ran 3 desktop queries (`university research institute editorial website`, `scientific laboratory research publications`, `academic journal institutional website`), received 12 results, and directly viewed 4 screens (Illumina publications, Academia, EdSurge research, Wiley academic libraries). Harvested only layout grammar: metadata-forward publication rows, narrow reading measures, side taxonomy on wide screens, and evidence before promotion. Rejected gradient hero chrome, signup gating, tiny dense nav, and vendor-style CTA emphasis. Raw results and viewed receipts are in `.omo/evidence/task-6/research/lazyweb/`.
- StyleGallery: shortlisted `twelve-span-grid`, `page-grid`, and `grid-wrapper`; adopted `twelve-span-grid` for placement and `page-grid` for outer containment. Todo 17 additionally adopts `ram-grid` for opportunity/event/news listings and `sticky-aside` for verified trust metadata beside long institutional copy. The document owns vertical scroll; neither pattern creates nested scroll. DOM/source order remains the reading and focus order. Source: <https://github.com/changeroa/StyleGallery>.
- Interaction reference: inspected beui.dev `button` and `table` source. Adopted immediate, interruptible state feedback and explicit reduced-motion behavior; rejected spring, magnetic, morphing, virtualized, and decorative movement because this institutional surface needs no spatial animation.
- UI/UX database: queried scientific institutional editorial design and multilingual typography. It supported an open-source serif plus highly legible interface face and 4/8 rhythm. Rejected its pink accent, single-column landing pattern, exaggerated type, remote font imports, Roboto, and Playfair because they conflict with the approved fallback and multilingual institutional tone.
- Official asset provenance/rights, 2026-08-30: live UFRJ and COPPE home pages returned 200 and exposed current logo file URLs; `lps.ufrj.br` timed out. Neither retrieved page supplied a reusable asset license or digital identity manual. Therefore no UFRJ, COPPE, or LPS logo is copied into this contract/showcase. Production use is blocked until an authorized owner supplies official files, usage rules, and permission. HTTP receipts are in `.omo/evidence/task-6/research/provenance/`.
- Font provenance: Source Serif 4 is open source under the SIL Open Font License 1.1; UI and mono roles use platform stacks with no bundled proprietary files. CJK falls through to the platform's CJK serif/sans fonts to preserve glyph coverage.
- Imagen concepts: skipped because no Imagen/image-generation tool is available in this harness. No draft or reference-fidelity image was fabricated. The browser-rendered primitive showcase is the visual contract.

## 1. Atmosphere & Identity

**Scientific Editorial** feels like a peer-reviewed paper opened onto an active engineering laboratory: quiet, exact, warm enough for long reading, and visibly accountable. The signature is the **signal rule** - a thin deep-blue structural line interrupted by a short teal segment at meaningful transitions (active navigation, selected filters, figure legends, and focus), never as decoration. Research evidence leads; institutional identity supports it; authentic people and equipment prevent abstraction.

Content jobs follow the visitor's decision path: hook with mission, explain research, prove with outputs and methods, compare themes or opportunities, convert through Join/Collaborate/Partner actions, navigate records, and retain trust through provenance, dates, credits, and contacts.

## 2. Color

### Palette

| Role | Token | Value | Usage |
| --- | --- | --- | --- |
| Paper | `--color-paper` | `#F7F4EC` | Primary canvas and reading surface |
| Paper raised | `--color-paper-raised` | `#FFFEFA` | Inputs, selected rows, media mat; never card elevation |
| Paper muted | `--color-paper-muted` | `#ECE8DE` | Quiet grouping, code/metadata fields |
| Ink | `--color-ink` | `#141A1F` | Headlines and body |
| Ink soft | `--color-ink-soft` | `#46515A` | Supporting text and captions |
| Navy | `--color-navy` | `#003B5C` | Institutional anchor and primary actions |
| Navy hover | `--color-navy-hover` | `#002B44` | Hover/pressed darkening |
| Signal | `--color-signal` | `#007A87` | Focus, selected state, informative links |
| Signal hover | `--color-signal-hover` | `#005F69` | Link hover/pressed |
| Rule | `--color-rule` | `#C9CDD1` | Hairline structure |
| Rule strong | `--color-rule-strong` | `#6F7A82` | Table heads and hard separation |
| Success | `--color-success` | `#216E4E` | Text/icon plus label; never color alone |
| Warning | `--color-warning` | `#7A4A00` | Text/icon plus label; never color alone |
| Error | `--color-error` | `#A12622` | Errors and destructive action text |
| Info wash | `--color-info-wash` | `#DDECEF` | Informational alert background |
| Success wash | `--color-success-wash` | `#E0ECE5` | Success alert background |
| Warning wash | `--color-warning-wash` | `#F2E8D2` | Warning alert background |
| Error wash | `--color-error-wash` | `#F2DEDA` | Error alert background |
| Focus offset | `--color-focus-offset` | `#F7F4EC` | Focus-ring separation from dark fills |

### Color rules

- Paper, graphite, and navy hold the page. Signal teal appears only where it communicates an interactive, selected, focused, or data-key state.
- All text/background pairs meet WCAG 2.2 AA: 4.5:1 for ordinary text and 3:1 for large text and UI graphics. Primary body copy targets 7:1.
- State is never encoded by color alone; pair it with text, weight, rule position, icon shape, or native control state.
- Official identity colors may replace navy/signal only after documented provenance and contrast verification. Extend this table before code.
- No gradients, alpha fog, glass, glows, or decorative color fields.

## 3. Typography

### Families

- **Editorial/reading** `--font-editorial`: `"Source Serif 4", "Noto Serif", "Noto Serif CJK SC", "Noto Serif CJK JP", Georgia, serif`. Source Serif 4 is self-hosted when production assets are approved; `font-display: swap`. It owns display, deck, quotations, and long-form reading.
- **Interface/body** `--font-interface`: `system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`. It owns navigation, controls, tables, form labels, and short explanatory body. This avoids shipping a second large webfont and preserves platform multilingual metrics.
- **Technical metadata** `--font-mono`: `ui-monospace, "Cascadia Mono", "Segoe UI Mono", Menlo, Consolas, monospace`. It owns identifiers, dates, axes, kickers, and code-like metadata only.

Three roles are justified: scholarly reading, high-legibility interface, and aligned technical data. No proprietary face is permitted. Never use Arial, Inter, Roboto, Montserrat, WiredDisplay, BreveText, Apercu, or WiredMono.

### Scale

| Role/token | Fluid size | Weight | Line height | Tracking | Measure/use |
| --- | --- | --- | --- | --- | --- |
| `--type-display` | `clamp(2.75rem, 7vw, 5.75rem)` | 600 | 0.98 | `-0.035em` | Hero statement, max 11 words/3 lines |
| `--type-h1` | `clamp(2.25rem, 5vw, 4rem)` | 600 | 1.04 | `-0.025em` | Page title |
| `--type-h2` | `clamp(1.75rem, 3.5vw, 2.75rem)` | 600 | 1.12 | `-0.018em` | Major section |
| `--type-h3` | `clamp(1.375rem, 2vw, 1.75rem)` | 600 | 1.18 | `-0.01em` | Subsection/record title |
| `--type-h4` | `1.125rem` | 650 | 1.3 | `0` | Compact heading |
| `--type-lead` | `clamp(1.25rem, 2vw, 1.5rem)` | 400 | 1.5 | `0` | Intro/deck, max 62ch |
| `--type-body` | `1rem` | 400 | 1.65 | `0` | Default interface/body, max 72ch |
| `--type-reading` | `1.125rem` | 400 | 1.72 | `0` | Long-form editorial, max 68ch |
| `--type-small` | `0.875rem` | 450 | 1.5 | `0` | Captions/help |
| `--type-meta` | `0.75rem` | 600 | 1.4 | `0.075em` | Uppercase labels/metadata |

### Type rules

- Portuguese and English wrap by phrase, never by forced `<br>`. Use balanced wrapping for short headings where supported and ordinary wrapping as fallback.
- CJK uses `word-break: normal`, `line-break: strict`, and platform CJK fallbacks. Do not add letter spacing to CJK paragraphs. Avoid isolated particles/endings and one-glyph final lines by adjusting measure or type step, not clipping.
- Body text is never below 16px; captions/meta never carry essential instructions alone.
- Use tabular numerals for years, counts, DOI fragments, axes, and table data.
- Underlines remain visible for inline links. Never distinguish links by color alone.

## 4. Spacing & Layout

### Spacing scale

Base unit: 4px. Intentional spacing uses only these tokens.

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

- `--grid-max: 80rem` (1280px); document-centered.
- Mobile `< 48rem`: 4 conceptual columns, `--grid-gutter: 1rem`; all showcase regions span full width unless explicitly paired.
- Tablet `48rem-63.99rem`: 8 conceptual columns, `--grid-gutter: 1.5rem`; labels/notes may span 2 while content spans 6.
- Desktop `>= 64rem`: 12 columns, `--grid-gutter: 2rem`; section label 2 columns, primary content 7, contextual note 3. Figures may span 8-10; long-form text stays 6-7 columns.
- Outer inset: `clamp(var(--space-4), 4vw, var(--space-10))`.
- The `page-grid` contains the page and the `twelve-span-grid` places modules. The document owns scrolling. No nested vertical scroll except an explicitly labeled table wrapper on narrow screens; that wrapper receives keyboard focus and an accessible description.
- Source order equals reading/focus order. CSS placement must not reorder semantic content.
- Browser mechanics (`auto`, percentages, `minmax()`, `clamp()`, intrinsic sizing) remain raw; design intent resolves to tokens.

### Responsive/stress contract

- At 375px all primary content reflows to one readable column with no page-level horizontal overflow. Navigation wraps; it does not hide essential destinations in this showcase.
- At 768px paired comparisons may form two columns; labels remain adjacent to their content.
- At 1280px the full 12-column editorial rhythm is visible; text measure remains bounded.
- At 200% zoom and 320 CSS px, controls wrap, labels remain visible, and no text is clipped or ellipsized.
- Long URLs/DOIs may break with `overflow-wrap: anywhere`; ordinary words and CJK phrases use natural line breaking.
- Print strips interaction-only controls, retains URLs where useful, preserves figure captions, repeats table headers, and avoids splitting alerts/figures when possible.

## 5. Components

### Link
- **Structure**: semantic `<a href>` with descriptive text; optional trailing inline SVG for external/download semantics.
- **Variants**: inline, navigation, quiet metadata, external.
- **Spacing**: `--space-1`/`--space-2` only.
- **States**: default underline; hover uses signal-hover and thicker underline; active uses navy-hover; focus-visible uses a 3px signal outline with 3px offset; visited is deliberately not recolored in institutional navigation; disabled links are rendered as text, never fake anchors.
- **Accessibility**: no “click here”; external behavior named where unexpected; icons are `aria-hidden` when text supplies the name.
- **Motion**: color/decoration change in `--motion-fast`; instant under reduced motion.
- **Layout**: inline/cluster; document scroll.

### Button
- **Structure**: native `<button>` or true link styled as action; label plus optional project-original SVG.
- **Variants**: primary navy fill, secondary paper with 2px navy rule, quiet text action, destructive.
- **Spacing**: `--space-3` block and `--space-5` inline; minimum 44px target.
- **States**: default, hover, focus-visible, pressed (`transform: translateY(1px)` only), disabled, busy (`aria-busy` plus stable label), success/error text announced nearby.
- **Accessibility**: native disabled semantics, discernible name, no icon-only critical action.
- **Motion**: 120ms color/transform, interruptible; reduced motion removes transform and duration.
- **Layout**: cluster; no internal scroll.

### Navigation
- **Structure**: labeled `<nav><ul>`; current page uses `aria-current="page"`; locale and search are separate named groups.
- **Variants**: primary, local/section, utility.
- **Spacing**: `--space-2`, `--space-4`, `--space-6`.
- **States**: rest, hover, focus, pressed, current. Current state uses a 3px signal rule and weight, not color alone.
- **Accessibility**: 44px targets; wrapping labels; logical DOM order; no hover-only submenu; skip link precedes shell.
- **Motion**: color only; no sliding indicator.
- **Layout**: wrapping cluster on showcase; production mobile disclosure must retain native button semantics and focus management.

### Editorial Record Card (card without chrome)
- **Structure**: `<article>` with kicker, linked title, summary, metadata, optional figure.
- **Variants**: feature, compact, numbered, dated opportunity/event/news.
- **Spacing**: `--space-2`, `--space-4`, `--space-6`.
- **States**: only the actual title link responds; article itself is not falsely clickable. Opportunity and event state uses the existing status primitive with explicit text. No lift, scale, rounded container, fill, or shadow.
- **Accessibility**: one heading; link text remains meaningful out of context; date values use semantic `<time>`.
- **Motion**: none on article.
- **Layout**: `ram-grid` repeats records with `minmax(min(16rem, 100%), 1fr)` and no internal scroll; each record remains a hairline-separated stack.

### Institutional Trust Layout
- **Structure**: primary `<article>` followed in source order by an `<aside>` named for verification and contact details.
- **Variants**: About/History/Governance, Collaboration/Contact, Privacy/Accessibility.
- **Spacing**: `--space-4`, `--space-6`, `--space-8`, `--space-12`.
- **States**: verified facts show source and last-reviewed date; stale or absent evidence becomes an explicit warning/block rather than a positive claim or invented contact.
- **Accessibility**: headings remain sequential; role contacts are descriptive `mailto:` links; source URLs are breakable; no form, cookie banner, or data collection appears.
- **Motion**: none.
- **Layout**: ordinary document flow on narrow screens; at desktop, `sticky-aside` places the verification aside beside long copy while the document remains the only vertical scroll owner.

### Figure
- **Structure**: `<figure>` containing rights-cleared media or project-original SVG/data graphic and `<figcaption>` with description, source, credit, and rights status.
- **Variants**: documentary image, technical diagram, data plot, media placeholder.
- **Spacing**: caption `--space-2`; outer rhythm `--space-6`.
- **States**: noninteractive by default; interactive data requires keyboard-equivalent table and named state.
- **Accessibility**: informative image has contextual alt; complex figure has nearby text/table; decorative asset uses empty alt; explicit dimensions/aspect ratio prevent shift.
- **Motion**: none.
- **Layout**: square geometry, full column width; no faux-science decoration.

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
- **States**: row rest/hover/focus-within/selected, sort state, empty/error. No zebra striping required.
- **Accessibility**: caption names purpose; units belong in headings; a linear list alternative is preferred when relationships matter more than comparison.
- **Motion**: none.
- **Layout**: full-width; wrapper alone may own horizontal scroll on narrow viewports.

### Form Field
- **Structure**: persistent `<label>`, native input/select/textarea, optional help, inline error linked by `aria-describedby`.
- **Variants**: default, required, optional, readonly, disabled, error, success.
- **Spacing**: `--space-2`, `--space-3`, `--space-4`.
- **States**: rest, hover, focus, valid, invalid, disabled, readonly; 2px structural border and 3px focus outline.
- **Accessibility**: no placeholder-only label; errors state cause and repair; first invalid field receives focus after submit.
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
- **Rules**: no emoji, raster UI icons, Lucide/Feather/Heroicons, mixed filled/outline families, or copied institutional marks.

## 6. Motion & Interaction

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

## 7. Depth & Surface

Strategy: **borders-only editorial depth**.

| Token | Value | Use |
| --- | --- | --- |
| `--rule-hairline` | `1px solid var(--color-rule)` | Sections, records, table rows |
| `--rule-strong` | `2px solid var(--color-navy)` | Controls and major boundaries |
| `--rule-signal` | `3px solid var(--color-signal)` | Current/focus/alert marker |
| `--radius-square` | `0` | All rectangular surfaces |
| `--shadow-none` | `none` | Every primitive |

Paper-raised is a flat reading contrast, not elevation. No generic card chrome, rounded rectangle containers, box shadows, drop shadows, gradients, blur, glass, or faux depth. Photographs and technical figures provide material reality; rules establish hierarchy.

## 8. Accessibility Constraints, Deviations & Accepted Debt

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

- **From FEEC**: preserve research-first hierarchy and audience pathways; reject its broad machine-language menu, duplicated modules/headings, carousel density, default/weak state handling, tiny/empty media, and opaque controls.
- **From WIRED**: preserve rule/whitespace discipline, square geometry, and separated type roles; replace every proprietary typeface and brand token; reduce news density; add robust focus, form semantics, multilingual metrics, and calm institutional color.
- **From Lazyweb screens**: preserve evidence rows and metadata adjacency; reject gradient corporate hero fields, signup gates, tiny utility nav, sponsor-feed density, and promotional metrics.
- **From minimalist reference**: preserve macro-whitespace and restraint; reject rounded bento boxes, shadow-lift cards, remote placeholder photography, ambient gradients, and generic reveal animation.

### Accepted debt

| Item | Location | Why accepted | Owner / exit |
| --- | --- | --- | --- |
| Official UFRJ/COPPE/LPS marks are represented by text only | Showcase and future shell | Live pages prove current files exist but do not prove reuse rights or provide identity rules; LPS host is unavailable | Institutional communications/rights owner supplies authorized source files, license/permission, and clear-space/color rules before production integration |
| Source Serif 4 CJK glyphs depend on platform fallback | Multilingual typography | Bundling full Noto CJK subsets would add substantial weight before final locale corpus is known | Todo 13 font packaging selects tested, subsetted open-source CJK fallback if target platforms show metric/tofu defects |
| No human screen-reader session in this task | Standalone showcase | Linux harness supplies Chromium accessibility snapshots and keyboard/axe evidence, not a representative human AT study | Todo 24 runs plan-wide accessibility review and supported AT smoke evidence; any unsupported platform remains explicit |
| No exact visual-reference image | Research log | Imagen tooling unavailable and FEEC/WIRED are hierarchy/atmosphere sources, not clone targets | Primitive browser captures are the contract; production pages receive fresh visual QA in Todo 24 |
