# Accessibility conformance - WCAG 2.2 AA and eMAG

Owner: accessibility contact (unnamed - see launch blockers).
Scope: every public template, locale, state, interaction, and authored-media class of the LPS institutional website.
Standards: [WCAG 2.2](https://www.w3.org/TR/WCAG22/) level AA and the Brazilian
[eMAG](https://www.gov.br/governodigital/pt-br/acessibilidade-e-usuario/acessibilidade-digital/modelo-de-acessibilidade)
model. Portuguese is the authoritative locale; English is a published variant with the same conformance target.

## 1. How the gate runs

| Command | What it proves |
|---|---|
| `npm run qa:a11y` | Complete route/state inventory, template-source contract, rendered-document rules, stylesheet rules (contrast, focus, motion, print, target size), authored-corpus rules, plus the merged axe, pa11y, and keyboard-journey results. Fails when any of those inputs is missing, stale, or blocking. |
| `node scripts/qa/a11y/capture.mjs` | Captures the server-rendered document of every inventory route with its HTTP status and checksum. |
| `node scripts/qa/a11y/axe-run.mjs` | axe-core over every route at 1280 px and at the 320 px reflow width. |
| `node scripts/qa/a11y/pa11y-run.mjs` | pa11y (HTML_CodeSniffer, WCAG2AA) over every route. |
| `node scripts/qa/a11y/static-scan.mjs` | axe + pa11y + reflow + target-size measurement over the statically rendered surface seams; needs no server. |
| `node scripts/qa/a11y/static-keyboard.mjs` | Keyboard focus order, focus visibility, obscured-focus, trap sweep, and reduced-motion measurement over the same seams. |
| `npx playwright test tests/e2e/todo23-accessibility.spec.mjs` | Agent-operated keyboard journeys (Search, filter, language switch, Opportunity contact, citation download, barrier reporting) in both locales, plus reflow, text spacing, and reduced motion end to end. |
| `npx vitest run tests/js/a11y.test.mjs` | RED fixtures for missing alt, broken label, keyboard trap, low contrast, obscured focus, wrong language, and PDF-only essential content, each asserted down to the exact selector. |
| `tools/composer test --filter AccessibilitySurfacesTest` | PHP contract tests for the rendering repairs. |

## 2. Route, state, and locale inventory

The inventory lives in `scripts/lib/a11y-inventory.mjs` and is enforced: the gate fails when a shipped block
template is not claimed by a route, when the inventory claims a template the theme does not ship, or when a
route has no fresh captured document.

- 63 route/state entries covering both locale roots (`/pt-br/`, `/en/`).
- 24 of the 25 block templates are reached by at least one public URL.
- `index.html` is a required block-theme fallback that no public query resolves, because `front-page.html`,
  `archive.html`, `single.html`, `page.html` and `search.html` answer every public request. It is audited as
  template source (landmark, skip target, header and footer parts) rather than skipped.
- States covered: populated, empty, filtered, faceted, prompt, boundary error, not-found (404), photo withheld,
  opportunity open and closed, event cancelled, authored media, paginated.
- Authored-media classes covered: documented figure with caption, decorative image, data table inside a
  horizontally scrollable region, downloadable document with an HTML alternative, captioned video with a
  transcript, inline language change, person photograph, organisation logo.

## 3. Defects found and repaired in this task

| # | Defect | Criterion | Where | Repair |
|---|---|---|---|---|
| D1 | The English shell rendered Portuguese institutional strings with no language change. | WCAG 3.1.2 | `Shell::header_markup` | The affiliation line and the Portuguese wordmark subtitle now carry `lang="pt-BR"`. |
| D2 | Institutional pages rendered a second `h1` that duplicated the page title. | WCAG 1.3.1, 2.4.6 | `TrustSurfaces::render_institutional_page` with `page.html` | The article no longer repeats the title; it is named with `aria-label` and the template keeps the single `h1`. |
| D3 | The people filter published English-only research-area labels in the Portuguese interface. | WCAG 3.1.2 | `PublicSurfaces::people_listing` | Localized `area_options()`. |
| D4 | Applying a people filter changed the result set silently. | WCAG 4.1.3 | `PublicSurfaces::people_listing` | A `role="status"` region announces the localized result count. |
| D5 | Listing filters labelled their controls with storage keys (`year`, `q`). | WCAG 2.4.6, 3.3.2 | `DiscoverySurfaces::render_listing` | Localized `filter_label()`, explicit `label[for]`/`id` association, and a `role="status"` count. |
| D6 | Authored data tables scrolled horizontally inside a container no keyboard could reach or name. | WCAG 2.1.1, 1.4.10, 4.1.2 | core `table` block output | `Shell::make_tables_scrollable_by_keyboard()` adds `tabindex="0"`, `role="region"`, and a name from the caption. |
| D7 | Publication PDFs were published without a declared accessible HTML alternative. | WCAG 1.3.1 | `DiscoverySurfaces::render_publication` | The PDF link declares `data-html-alternative`; a record without HTML text states the gap instead of implying an equivalent. |
| D8 | Pagination links and facet checkboxes were smaller than the 24 px minimum target. | WCAG 2.5.8 | `assets/css/theme.css` | Pagination links, standalone paragraph links, and facet checkboxes carry their own target size. |
| D9 | Reduced motion did not apply: `:where()` neutralization lost to the component rules, so buttons still animated for 120 ms. | WCAG 2.3.3 | `assets/css/theme.css` | The reduced-motion block now neutralizes duration at the same specificity as the component rules, verified in Chromium with `prefers-reduced-motion: reduce`. |
| D10 | Portuguese record archives were titled in English (`<h1>Opportunities</h1>`, breadcrumb `Archives: Opportunities`), because the record types are registered with English labels. | WCAG 3.1.2, 2.4.6 | `Shell::archive_title_for` via `get_the_archive_title` | The archive heading and breadcrumb follow the locale of the route. |
| D11 | The core search-results page announced no result count and kept an English heading on Portuguese routes. | WCAG 4.1.3, 3.1.2 | `Shell::search_results_markup` via `render_block` | The heading is localized and the count is announced through `role="status"`. |
| D12 | Citation downloads (BibTeX, CSL-JSON) were 22 px high, below the 24 px minimum target. | WCAG 2.5.8 | `DiscoverySurfaces::render_publication`, `assets/css/theme.css` | The download pair is a control cluster with its own target size. Found by the live keyboard journey, not by automation. |

Every repair has a failing-before test: `tests/js/a11y.test.mjs` for the rule engine and
`wp-content/themes/lps-theme/tests/AccessibilitySurfacesTest.php` for the rendering seams
(8 of 10 PHP tests fail against the pre-repair code - see `.omo/evidence/task-23/logs/red-03-php-surfaces.log`).

## 4. Manual, agent-operated checklist

Each item is executed, not inferred. "Evidence" names the artifact that proves it.

| # | Check | Method | Result | Evidence |
|---|---|---|---|---|
| M1 | Semantics and landmarks: one `main`, named `nav` landmarks, header/footer present on every template | rule engine over rendered documents plus template source | pass | `logs/static-surface-audit.json`, `live/qa-a11y-report.json` |
| M2 | Heading order: one `h1`, no skipped level, no empty heading | rule engine | pass | same |
| M3 | Names, roles, values for every control | rule engine plus axe `button-name`, `link-name`, `label` | pass | `a11y/axe-static-report.json` |
| M4 | Alt text, captions, transcripts for every authored media class | rule engine plus authored corpus audit | pass | `logs/static-surface-audit.json`, corpus rules in `scripts/lib/a11y.mjs` |
| M5 | Contrast 4.5:1 text, 3:1 large text and UI | token mathematics plus axe `color-contrast` in a real browser | pass | `tests/js/a11y.test.mjs`, `a11y/axe-static-report.json` |
| M6 | Reflow at 320 CSS px with no horizontal scrolling | Chromium measurement of `scrollWidth - clientWidth` | pass | `a11y/static-measurements.json` |
| M7 | Text spacing and 400% zoom remain readable | Chromium with the WCAG text-spacing overrides at 320 px | pass | Playwright journey `text-spacing-zoom` |
| M8 | Target size 24 px minimum, inline-in-sentence exemption applied honestly | Chromium bounding boxes at 320 px | pass | `a11y/static-measurements.json` |
| M9 | Keyboard order follows DOM order | Chromium Tab sweep, document position per stop | pass | `a11y/static-keyboard-report.json` |
| M10 | Focus visible with at least a 2 px indicator | computed `outlineWidth`/`outlineStyle` at every stop | pass | same |
| M11 | Focus never obscured | `elementFromPoint` at the focused rect centre | pass | same |
| M12 | No keyboard trap | 60-stop sweep, repeated-element detection | pass | same |
| M13 | Disclosures and filters operable by keyboard | Playwright journeys, native `details`/`summary` and GET forms | pass | `live/keyboard-journeys.json` |
| M14 | Status and error messages announced | `role="status"` and `role="alert"` regions in every result and boundary state | pass | rule engine, journeys |
| M15 | Language of page and of parts, BCP47 | rule engine plus pa11y `Language` rules | pass | `a11y/pa11y-static-report.json` |
| M16 | Data tables: caption, scope, focusable scroll region | rule engine plus PHP contract test | pass | `AccessibilitySurfacesTest` |
| M17 | Downloads state their format; no PDF-only essential content | rule engine over rendered documents and authored corpus | pass | rule engine |
| M18 | Print does not hide content | stylesheet audit of the `@media print` block | pass | stylesheet rules |
| M19 | Reduced motion honoured | Chromium with `prefers-reduced-motion: reduce` | pass | `a11y/static-keyboard-report.json` |
| M20 | No third-party asset, tracker, or embed | rule engine over every rendered document | pass | rule engine |

## 5. Local verification environment

The local runtime is WordPress Playground (PHP 8.3 in WebAssembly, SQLite). Bringing a
fresh database up exposed three defects in the shared local fixtures, none of which is a
product defect and none of which is fixed here; they belong to Todo 26. The evidence run
works around each one in its own mounted copy under `.omo/evidence/task-23/runtime/mu/`:

| Fixture defect | Effect on a fresh database | Local workaround used for this run |
|---|---|---|
| `tests/fixtures/wp/lps-trust-seed.php` ran its whole `init` body on every request and recorded its guard option only at the end | Institutional pages were re-created on every request with suffixed slugs (`governanca-47`), and the body fatalled before the plugin migration existed | Diagnosed here and reported. The shared file was **fixed upstream during this session** (migration receipts, 19:40); the final evidence pass runs against that fixed shared file, and the temporary guarded copy used earlier has been removed |
| The content-model plugin migrates at `init:10`, after fixtures that write records | The first seeded write fatals with `Unable to append the immutable audit entry` because the audit table does not exist yet | A harness mu-plugin calls the plugin's own idempotent `Plugin::migrate()` at `init:0` |
| `tests/fixtures/wp/lps-development-bootstrap.php` claims every language-less post as `pt-br` at `init:1` | English records interrupted between insert and language assignment are permanently labelled Portuguese, so `/en/` routes 404 | A harness endpoint re-assigns the declared `_lps_locale` and re-associates the locale pairs |

A fresh database still converges over several requests: the seeds advance one queue step per
request, so the evidence run drives them with `runtime/seed-driver.mjs` and re-applies the
locale assignment and the publish step until every route answers, before any capture is taken.

Two further runtime properties are recorded because they shape the evidence, not the verdict:
the WebAssembly worker dies under sequential load (the capture runner restarts the server it
owns and resumes), and the seed fixtures rebuild only a partial rewrite-rule set on each boot
(the runner re-applies a hard flush after every restart).

## 6. Assistive-technology coverage and launch blockers

The Linux harness used by this task can run Chromium accessibility snapshots, axe-core, pa11y, and
agent-operated keyboard journeys. It cannot run a screen reader.

- **Not covered here, and therefore a launch blocker:** a human session with NVDA (Windows), JAWS (Windows),
  VoiceOver (macOS/iOS), TalkBack (Android), and Orca (Linux). No claim of screen-reader conformance is made
  from automated output.
- **Named accessibility contact:** unresolved. `docs/content/governance.md` keeps the corpus in draft while no
  accessibility contact, privacy contact, or publisher is named; the same gap blocks the published
  accessibility statement from naming a responsible person.
- Both blockers are reported, not waived.

## 7. Barrier reporting

The accessibility page publishes a role mailbox reachable by keyboard in both locales
(`/pt-br/acessibilidade/`, `/en/accessibility/`). The barrier-reporting journey is executed as part of the
Playwright keyboard suite in both locales.
