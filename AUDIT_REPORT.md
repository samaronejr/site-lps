# LPS website audit report

Audit date: 2026-11-25  
Target: <https://157.151.14.113:8443/>  
Codebase: `samaronejr/site-lps`, branch `improve/lps-site-audit`, based on `bf5ef4b` (`main`/`design-tweaks`)  
Live release verified on the Oracle VM: `.omo/staging/releases/rbf5ef4b/wordpress`, matching the same commit.

## Scope and method

- Cross-checked the live service, release symlink, repository revision, deployment scripts, HTML/XML responses, sitemap inventory, HTTP headers, database state, static asset behaviour, and the theme/plugin source.
- Crawled the governed sitemap inventory with the repository SEO validator: 220 documents, including 212 HTML pages and 7 XML documents.
- Browser-audited representative institutional, archive, detail, teaching, person, organization, news, event, privacy, accessibility, contact, and visual-identity routes in both locales at desktop 1440×900 and mobile 375×812. The browser evidence includes console errors, failed requests, broken images, horizontal overflow, headings/landmarks, touch targets, axe-core WCAG 2.1/2.2 AA rules, paint timings, and screenshots.
- Constraints followed: no fabricated content, no server port/SSL/reverse-proxy/secret changes, and no deletion of governed records.

## Executive summary

The site already has a coherent bilingual institutional design, strong security headers, complete canonical/metadata coverage on ordinary pages, self-hosted fonts, responsive portrait derivatives, HTTP/2 compression, and a governed sitemap infrastructure. The highest-impact remaining problems are data integrity and delivery performance rather than broad visual redesign:

1. **Critical — duplicate English organization records are indexable.** The live database contains three published English copies for each of 26 organizations. The two stale copies per organization render the same title/description and advertise the newer translation in their hreflang block rather than themselves.
2. **High — homepage feature/course cards use raw post-type permalinks instead of the canonical public routes.** `/en/lps_project/…` and `/en/lps_course/…` are emitted on the homepage; course permalinks redirect, project permalinks render a second canonicalless address, and the crawler saw 502s under origin load.
3. **High — immutable static assets bypass the edge cache and repeatedly take seconds to load.** Fonts, SVG artwork, favicons, and portraits answer `x-lps-edge-cache: BYPASS`; repeated requests for the same asset still took about 3–6 seconds during the audit. Under browser parallelism some static/font/script requests returned 502.
4. **High — sitemap XML bypasses the edge cache and is expensive to generate.** Locale sitemaps answer `x-lps-edge-cache: BYPASS` and measured roughly 4–7 seconds TTFB while uncached.
5. **High — axe-core found WCAG AA violations in the shipped theme.** Decorative card numbers are 1.15:1, chronology years are 2.23:1, and footer telephone/email links are not distinguished from surrounding address text on desktop.
6. **High — live event copy says “40 anos” although the LPS was founded in 1996 and the repository documents the correct title as “30 anos”.** This is a factual content correction requiring owner approval; it has not been silently changed.
7. **Medium — homepage and archive sitemap `lastmod` values are regenerated as “now”.** They change on every sitemap render instead of reflecting the newest underlying content modification.

## Confirmed findings

### Critical

#### C1. Duplicate English organization records are indexable and corrupt hreflang reciprocity

- **Evidence:** the repository SEO crawl produced 254 errors: 100 `lps_seo_hreflang_not_reciprocal`, 50 `lps_seo_hreflang_not_self_referential`, 54 `lps_seo_duplicate_description`, and 50 `lps_seo_duplicate_title`.
- **Live database:** 102 published `lps_organization` posts: 26 Portuguese and 76 English. Each of 26 translation groups contains one Portuguese record and three English records.
- **Example:** `/en/organizations/argonne-2/`, `/en/organizations/argonne-3/`, and `/en/organizations/argonne-4/` all render the same `Argonne National Laboratory` title and near-identical document size. Their canonicals are self-referential, but their English hreflang alternate points only to `/en/organizations/argonne-4/`.
- **Data check:** the English copies have identical `post_content` and `post_excerpt` hashes; metadata differences are governance/system fields rather than factual organization content.
- **Impact:** duplicate indexable URLs dilute search signals, produce incorrect hreflang annotations, and triple the English organization inventory.
- **Recommended fix:** archive and detach the two stale English duplicates in each group, keep the translation-linked record, purge caches/sitemaps, and add a regression check that one locale translation group cannot contain multiple published same-locale records.

### High

#### H0. Homepage feature and course links bypass canonical routes

- **Evidence:** the rendered PT/EN homepages link project cards to `/pt-br/lps_project/…` or `/en/lps_project/…` and course rows to `/pt-br/lps_course/…` or `/en/lps_course/…` instead of `/projetos/`, `/projects/`, `/ensino/disciplinas/`, and `/en/teaching/courses/`.
- **Link crawl:** 8 of 135 checked internal links were reported broken, all in this raw-permalink/people group. Manual spot checks later returned 200/301, showing that several “broken” results were origin 502s under load rather than missing records — but the emitted addresses are still wrong or non-canonical.
- **Impact:** primary homepage journeys are fragile, duplicate project URLs can be indexed, and every raw CPT request adds avoidable origin work.
- **Recommended fix:** resolve card/course URLs through `SeoRoutes::record_path()`, canonicalize every governed CPT permalink/archive in one hop, and keep the locale-specific people slugs unchanged unless the owner approves slug migrations.

#### H1. Static assets bypass the shared edge cache

- **Evidence:** `GET /wp-content/themes/lps-theme/assets/fonts/inter-regular.woff2` repeatedly returned `x-lps-edge-cache: BYPASS`; repeat measurements were about 3.3–3.7 seconds TTFB. `lps-mark-full.svg` measured about 4.2 seconds total and a portrait WebP about 3.2 seconds on cold edge requests.
- **Cause:** the edge marks static assets `public, max-age=31536000, immutable` but not `s-maxage`, while its shared cache only stores responses with `s-maxage > 0`.
- **Impact:** first-time and edge-cold page loads wait on multiple origin-served binary assets even though the deploy process already flushes the shared cache on every release flip.
- **Recommended fix:** make immutable static responses shared-cacheable (`s-maxage`) and add a regression test. No port, TLS, or reverse-proxy topology change is needed.

#### H2. Sitemap XML bypasses the shared edge cache and is slow

- **Evidence:** `/sitemap-pt-br.xml` returns `x-lps-edge-cache: BYPASS`; measured TTFB was about 4.4–6.7 seconds during the audit. `/sitemap.xml` was about 4.4 seconds on a cold request.
- **Cause:** `SeoRoutes::send_xml()` sends no `Cache-Control`/`Surrogate-*` shared-cache contract, so the edge cannot store machine documents.
- **Impact:** crawlers and validation runs hit the WordPress Playground origin for every sitemap/feed request, increasing origin CPU load on a one-CPU VM.
- **Recommended fix:** emit a bounded anonymous shared-cache policy for sitemap and feed XML, purge all three sitemap paths plus feeds on content changes, and cover the behaviour in PHP/edge tests.

#### H3. Theme-level WCAG AA contrast and link-affordance violations

- **Evidence:** axe-core over 112 desktop/mobile page audits found 32 `color-contrast` node violations on infrastructure/news chronology surfaces and 29 `link-in-text-block` node violations affecting the footer telephone/email links across pages.
- **Examples:** numbered card indexes use `#e9eff6` on white (1.15:1); chronology years use `#0fb5e4` on `#f4f7fb` (2.23:1); footer contact links rely on a close color shift and are only underlined inside a compact-media rule.
- **Impact:** visible text fails WCAG 2.1/2.2 AA large-text contrast and inline links are not reliably distinguishable without color.
- **Recommended fix:** use existing darker boundary/accent-ink tokens and make footer address links underlined at every viewport.

#### H4. Live event copy uses the wrong anniversary number

- **Evidence:** `/pt-br/eventos/coloquio-40-anos-lps/` is live; `docs/operations/performance-sitemap-release.md` records that post 586 must instead be titled **Colóquio institucional: 30 anos do LPS** with excerpt **Sessão comemorativa dos 30 anos do laboratório**.
- **Impact:** factually incorrect institutional copy is public and indexed.
- **Constraint:** factual lab content decisions belong to the owner. This audit does not silently change it.
- **Recommended fix:** owner-approved live DB correction of title/excerpt, EN variant review/re-stamping, cache purge, and no slug change unless an explicit redirect is added.

### Medium

#### M1. Homepage and archive sitemap `lastmod` values are always the render time

- **Evidence:** `SeoRoutes::sitemap_entries()` uses `gmdate('c')` for the locale home entry and every archive entry. The first sitemap entries changed to the crawl time (`2026-11-24T23:49:41+00:00`).
- **Impact:** search engines receive noisy modification signals and cannot trust these `lastmod` values.
- **Recommended fix:** derive each archive timestamp from the newest visible record in that archive and the homepage timestamp from the newest visible homepage/featured content.

#### M2. Typography documentation and implementation have drifted

- **Evidence:** the executable theme uses self-hosted Inter, Space Grotesk, and JetBrains Mono; older sections of `DESIGN.md` still describe IBM Plex as the contract family. The impeccable detector reports Inter/Space Grotesk as generic fonts, but the project’s 2026 revision intentionally selected them, so the detector result itself is not treated as a visual defect.
- **Impact:** future maintenance can follow the wrong contract and regress the owner-approved visual system.
- **Recommended fix:** amend the stale documentation section instead of changing the shipped type system.

#### M3. Repository lint has non-blocking hygiene warnings

- **Evidence:** `npm run lint -- --max-diagnostics=20` exits 0 but reports repeated `useLiteralKeys` warnings in e2e tests and an unused `LIGHTHOUSE_AUDIT` constant in the performance policy test.
- **Impact:** low immediate user impact, but warning noise can hide future regressions.
- **Recommended fix:** mechanical cleanup in tests and the unused constant.

## Positive findings

- Public HTML pages consistently carry a unique title, meta description, canonical link, robots directive, Open Graph metadata, locale alternates, and JSON-LD.
- Security headers are strong: CSP, `frame-ancestors 'none'`, HSTS, `nosniff`, restrictive permissions policy, no-referrer policy, and no cross-domain policy.
- The edge already negotiates HTTP/2 and Brotli/gzip; compressed homepage HTML is about 29 KiB despite a much larger identity body.
- No third-party runtime requests are required for the core visual system; fonts and brand assets are self-hosted.
- Public pages work without client JavaScript; the rendered design system is coherent and institution-specific.
- Portrait derivatives exist in 112/400/800 widths with WebP and JPEG fallbacks.
- The deployment process flushes the shared cache on release flips, which makes static shared caching safe to implement.

## Browser verification log

Detailed browser output is stored at `.omo/audit-2026-11-24/browser-live-before.json` with screenshots under `.omo/audit-2026-11-24/screenshots/`. Baseline coverage was 112 page audits: 56 representative routes × desktop/mobile.

Baseline results:

- All 112 document navigations returned 200; there were no uncaught page errors and no horizontal overflow.
- Every audited page had one `main` landmark and one visible `h1`; header/nav/main/footer landmarks were present.
- 20 console errors were recorded, concentrated on `/en/research/` and `/en/projects/`; they were static/font/script/image 502s caused by uncached parallel origin asset requests, not application JavaScript exceptions.
- 18 bad static responses and 9 aborted static requests were recorded on the same two desktop pages.
- axe-core found the contrast/link-affordance violations described in H3. It found no missing landmark, link-name, image-alt, heading-order, or keyboard-critical violations in the audited pages.
- Median page performance was dominated by origin latency: desktop elapsed averaged about 10.0s and mobile about 6.3s; worst desktop routes timed out at about 30s. This is a delivery/cache finding, not a client bundle problem (the only public script is about 1.1 KiB compressed).
- Detector false positives reviewed and not counted as defects: lazy below-viewport images naturally report `naturalWidth=0` before scrolling; SVGs can lack intrinsic dimensions; the two “empty” masthead anchors have accessible `aria-label`s; the mobile navigation probe looked for a `button` while the accessible disclosure is a native `summary` element.
- Keyboard focus order on the mobile homepage starts with the skip link, locale/search disclosures, sign-in, brand, menu disclosure, and content links, with visible focus outlines.

## Completed changes

Implemented and deployed to the live Oracle service as release `r77f8df4` (implementation commits `81aae2e` through `77f8df4`):

1. **Duplicate organization cleanup (C1):** remapped the nine project partner/funder relationships that pointed at stale English organization copies to their canonical translation records, then archived all 50 stale duplicate English organization records through the governed archive path. No governed records were hard-deleted and no factual organization copy was rewritten. Post-repair database verification: 52 published organization records, 50 archived organization records, zero relationship rows targeting the 50 stale organization IDs, and no translation group with more than two published organization records. Archived duplicate URLs now redirect in one hop; for example `/en/organizations/eletrobras-cepel-2/` → `/en/organizations/eletrobras-cepel-4/`, while the legitimate canonical `/en/organizations/ufrj-3/` remains 200.
2. **Canonical homepage journeys (H0):** homepage research/project/news/partner/course record cards now resolve URLs through `SeoRoutes::record_path()`. Live markup verification found zero raw `/en/lps_project/` or `/en/lps_course/` links on the English homepage, with canonical teaching and project routes present instead. Default CPT archive/singular requests now canonicalize through the governed public route table.
3. **Static asset shared caching (H1):** immutable static responses now emit `public, max-age=31536000, s-maxage=31536000, immutable`; `?ver=` is retained as a cache-relevant key only for static assets while unapproved tracking keys still bypass/collapse safely. Live verification: the theme JavaScript asset returns HTTP/2 200 with the new shared-cache policy and the second request is an edge `HIT`. The homepage measured about 1.19 seconds through the edge after warmup instead of the multi-second baseline.
4. **Machine-document caching and `lastmod` (H2/M1):** sitemaps and locale feeds now carry a bounded anonymous shared-cache contract (`s-maxage=300`, stale-while-revalidate), locale surrogate keys, and publish-time purge coverage. Live verification: `/sitemap.xml` returns HTTP/2 200 with that cache contract. Home/archive `lastmod` values are now derived from underlying modified content instead of render time.
5. **WCAG contrast/link affordance (H3):** numbered card indexes moved from the near-white surface token to the darker boundary token; chronology years use the darker accent-ink token; footer address email/telephone links are underlined at every viewport. The existing navy/teal system, section grammar, cards, spacing, and owner-approved typography were retained.
6. **Hreflang corruption hardening (C1):** a corrupted translation group can no longer make an orphan duplicate advertise another record as its own address. Archived duplicate organization URLs resolve dynamically to their canonical translation record without hard-coding individual slugs.
7. **Repository hygiene/documentation:** the unused performance-test constant and formatting error were fixed; the operation document review metadata was re-stamped after the edge/SEO source changes. Skill updates from `npx skills update` were split into a separate pull request so this remediation diff remains reviewable.

Validation completed:

- `composer analyse`: PHPStan passed with zero errors.
- `composer test`: 799 PHPUnit tests / 6,391 assertions passed.
- Focused SEO/cache PHPUnit tests: 30 tests / 152 assertions passed.
- Focused edge Vitest suite: 6 tests passed; affected documentation-checker suites: 25 tests passed.
- `npm run lint`: exit 0; remaining output is non-blocking warnings in pre-existing e2e tests.
- `npm run build`: passed all payload budgets (`total 757,820 B` versus the `768,000 B` limit).
- Live smoke checks after deployment: homepage 200; canonical organization 200; duplicate organization 301; static asset and sitemap cache headers as specified; zero raw homepage project/course post-type links.
- Browser matrix after deployment: 32 page audits (16 principal PT/EN routes × desktop/mobile). One desktop `/en/contact/` navigation timed out while the origin was being overloaded by an audit crawler; after restarting the service and re-testing without concurrent crawl load it returned 200 with zero console errors and zero failed/bad asset responses. Across the matrix there was no horizontal overflow.
- Computed-style accessibility verification: footer contact links are underlined at both widths (contrast 16.03:1); chronology years measured 16.32:1; infrastructure card index numbers measured 3.6:1 and render as large text, passing the WCAG large-text 3:1 threshold.



## Remaining recommendations requiring owner input

1. Approve the factual anniversary correction from “40 anos” to “30 anos” in the live PT event and review the EN variant.
2. Provide any missing replacement photos/copy only if sections are intentionally empty; no placeholder content has been invented.
