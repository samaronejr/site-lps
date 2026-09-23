# Architecture decision record

Each record states the decision as implemented in this repository, the reason, and the consequence
for a maintainer. A decision is only changed by superseding its record and re-running the affected
gates. Status values are `accepted` (implemented and verified locally) or
`accepted-pending-host` (implemented locally, live confirmation blocked by an external
precondition).

## ADR-01 — Integrated WordPress, not a headless split

**Status:** accepted.
**Decision:** one WordPress instance serves the public site. `wp-content/plugins/lps-content-model/`
owns the content model and all editorial rules; `wp-content/themes/lps-theme/` owns presentation.
There is no separate front-end application and no page builder.
**Why:** the editorial team must be able to publish, translate, correct and archive without a
developer or a deploy. A headless split would put the publishing path behind a build.
**Consequence:** structural behaviour belongs in the plugin, presentation in the theme. Never store
editorial rules in a template, and never store markup in the plugin except in the locked media and
render helpers (`wp-content/plugins/lps-content-model/includes/class-mediarenderer.php`).

## ADR-02 — The custom plugin owns the content model; Polylang owns locale routing only

**Status:** accepted.
**Decision:** the production plugin allowlist is exactly `lps-content-model`, `polylang` and
`two-factor` (`wp-content/mu-plugins/lps-security.php` intersects the activation lists with that
set). Polylang provides locale association and routing. Required-translation policy, stale
translation detection and locale field ownership stay in
`wp-content/plugins/lps-content-model/includes/class-translationpolicy.php`.
**Why:** translation obligations are institutional policy, not a plugin setting; keeping them in
owned code makes them testable.
**Consequence:** adding a plugin requires a plan amendment plus an update to the MU allowlist and
the release inventory review. See [the plugin and update policy](../operations/plugin-update-policy.md).

## ADR-03 — Custom post types with immutable record identity

**Status:** accepted. Amended 2026-09-19: the teaching tranche added five post types and the
`_lps_origin` provenance field; the decision and its consequence are unchanged.
**Decision:** fourteen registered post types — the nine governance types (`lps_person`,
`lps_organization`, `lps_research_area`, `lps_project`, `lps_publication`, `lps_news`,
`lps_opportunity`, `lps_event`, `lps_redirect`) plus the five teaching types (`lps_course`,
`lps_term`, `lps_offering`, `lps_unit`, `lps_resource`) — and core `page`, each carrying
`_lps_record_id`, `_lps_origin`, `_lps_state`, `_lps_owner_user_id`, `_lps_review_date`,
`_lps_published_slug` and full provenance metadata
(`wp-content/plugins/lps-content-model/includes/class-contracts.php`,
`class-teachingcontracts.php`).
**Why:** relationships, citations, redirects and imports must survive title, slug and locale
changes, and a record's origin must be resolvable without trusting a stored claim.
**Consequence:** never repurpose a record. Archive it (`_lps_archived_at`) and create a new record;
the first published slug is immutable and a change produces a one-hop redirect. A record whose
origin resolves to `ambiguous` is withheld from public surfaces until reconciled.

## ADR-04 — Two controlled taxonomies, no free tags

**Status:** accepted.
**Decision:** `lps_research_area_key` (people, projects, publications) and
`lps_application_domain` (projects) are the only taxonomies, seeded from
`content/taxonomies/controlled-vocabularies.yaml`. Unapproved terms are rejected with
`lps_unapproved_taxonomy_term`.
**Why:** navigation, facets and bilingual labels depend on a stable language-neutral vocabulary.
**Consequence:** a new term needs an authoritative current LPS source and editorial acceptance
before it can be used; see [the information architecture](../content/information-architecture.md).

## ADR-05 — Nine least-privilege roles enforced in a pure policy class

**Status:** accepted. Amended 2026-09-19: the teaching tranche added the offering-scoped
`professor` and `delegate` roles; the decision mechanism is unchanged.
**Decision:** the role/action matrix lives in
`wp-content/plugins/lps-content-model/includes/class-securitypolicy.php` and is mapped onto
WordPress capabilities by `class-roles.php`, with per-account collection assignment stored in
`_lps_assigned_collections` and MFA required for `publisher`, `administrator` and `professor` —
every role that can publish publicly. The two scoped roles hold no collection rights at all:
their access comes from persisted `_lps_teaching_grants` evaluated by
`class-teachingpolicy.php` on every request.
**Why:** authorization must be table-testable without a browser, and no editorial role may be a
WordPress administrator by convenience.
**Consequence:** capability changes are policy changes. The documented role tables are checked
against the policy source by `node tests/docs/docs-checker.mjs`, so a doc that claims a denied
action fails the gate.

## ADR-06 — Portuguese is authoritative; English is a separately reviewed locale

**Status:** accepted.
**Decision:** locale roots `/pt-br/` and `/en/` are permanent. Translators may write only localized
editorial fields in the `en` locale; shared identifiers, dates and relations stay with the
authoritative record. Staleness is computed from a source hash
(`_lps_source_hash` versus `_lps_reviewed_source_hash`), not from a timestamp.
**Why:** machine translation and mixed-language fallback are forbidden, and a timestamp cannot prove
that the reviewed English text still matches the Portuguese source.
**Consequence:** editing authoritative Portuguese content marks its English variant stale and blocks
publication until an independent reviewer clears it. See
[the translation and freshness guide](../handbook/translation-freshness.md).

## ADR-07 — Server-rendered search on a plugin-owned index table

**Status:** accepted.
**Decision:** the plugin maintains its own locale search index
(`wp-content/plugins/lps-content-model/includes/class-searchindex.php`,
`class-wpdbsearchstorage.php`) updated transactionally on publish and archive, queried through
prepared statements, and rendered server-side at `/pt-br/busca/` and `/en/search/`. Published
teaching records join the index: courses, offerings and released resources carry their codes,
instructors, term labels and authored languages, while terms and units stay internal. Each row
stores a lifecycle payload (offering term boundaries, resource release state) evaluated at query
time, so the `current`/`previous` offering filter and scheduled resource releases need no
scheduler and fail closed. Publication, correction, withdrawal and term transitions propagate
through the same synchronization path to the index and the delivery purge targets.
**Why:** core search cannot express accent-insensitive weighting, locale isolation or private-field
exclusion, and the site must work without JavaScript.
**Consequence:** search behaviour is a data contract, not a UI feature; changes require the search
contract tests to be updated first.

## ADR-08 — Design tokens live in `theme.json`, and the design contract is executable

**Status:** accepted.
**Decision:** `wp-content/themes/lps-theme/theme.json` holds the palette, three font roles, the
fluid type scale and the 4px spacing scale defined in `DESIGN.md`. `npm run qa:design-system`
rejects unapproved token, radius, shadow, font and motion values.
**Why:** an institutional editorial system degrades quickly when values are typed into components.
**Consequence:** extend `DESIGN.md` and `theme.json` before using a new value; see
[the design-system guide](../design/design-system-guide.md).

## ADR-09 — Idempotent, dry-run-first migration through WP-CLI

**Status:** accepted.
**Decision:** migration runs through `wp lps import plan`, `wp lps import dry-run`,
`wp lps import apply`, `wp lps import verify`, `wp lps export` and `wp lps redirects verify`.
Reviewed fields are never silently overwritten; defective rows are quarantined with an explicit
blocker; a re-apply of an identical corpus writes nothing.
**Why:** the legacy corpus is partly unreliable, so a migration must be repeatable and reversible
with a reconciliation report rather than a one-shot script.
**Consequence:** import defects are fixed in the source data or the importer, never by hand-editing
the target. See [the publication and import guide](../handbook/publication-import-guide.md).

## ADR-10 — No third-party runtime requests, no cookies for anonymous visitors

**Status:** accepted.
**Decision:** self-hosted fonts, no analytics, no forms, no chatbot, no embeds, no consent banner;
Polylang's language cookie is disabled and locale URLs are authoritative; CSP, HSTS and upload
isolation are defined in `docs/operations/security-policy.md`.
**Why:** LGPD exposure and third-party dependency are avoided by not collecting anything.
**Consequence:** any future analytics, form, embed or vendor requires a privacy review and a plan
amendment before implementation.

## ADR-11 — Operations tooling is pure by default, mutation is opt-in

**Status:** accepted-pending-host.
**Decision:** `scripts/migration/rehearsal.mjs`, `scripts/backup/recovery.mjs` and
`scripts/cutover/dns-tls.mjs` keep planning, reconciliation and verification pure; every mutating
subcommand requires both a target environment variable and an explicit `--confirm-*` flag.
**Why:** release tooling must be safe to run and testable on a workstation without touching an
environment.
**Consequence:** live rehearsal, restore, DNS and TLS steps remain PENDING until the staging host
exists; the pure paths are already covered by fixtures. See
[the release runbook index](../operations/release-runbook-index.md).

## ADR-12 — Documentation is verified by a checker, not by review alone

**Status:** accepted.
**Decision:** `tests/docs/docs-checker.mjs` plus `docs/documentation-contract.json` verify links,
referenced files, documented commands, prerequisites, role capability claims, documentation
freshness against source hashes, and coverage of every production setting, collection, role,
recurring task, update path and recovery action. `tests/js/docs-checker.test.mjs` proves the checker
catches each defect class with purpose-built fixtures under `tests/fixtures/docs/`.
**Why:** handoff documentation rots silently; a command that no longer exists is worse than no
documentation.
**Consequence:** when you change a command, a role capability or a documented source file, run
`node tests/docs/docs-checker.mjs` and repair the named step. Updating a source hash in the contract
is an explicit re-review, not a formality.

## ADR-13 — Faculty work happens on a server-rendered task dashboard, not in wp-admin

**Status:** accepted.
**Decision:** the authenticated faculty surface is a frozen set of locale routes
(`/pt-br/painel/`, `/en/dashboard/`) rendered server-side by
`wp-content/themes/lps-theme/includes/class-dashboardsurfaces.php` and bound by
`class-dashboardroutes.php`. Every form posts to `admin-post.php` handlers in
`wp-content/plugins/lps-content-model/includes/class-taskdashboard.php` that re-check the
persisted grant on the server. The task list is derived from the account's role and grants, so a
professor sees only the tasks their scope covers and a delegate never sees publish controls.
**Why:** faculty should complete units, materials, releases, news and profile proposals without
learning the full editorial interface, and a simplified UI must never widen what the policy
allows.
**Consequence:** the dashboard grants nothing by itself — every action re-runs the same
`SecurityPolicy`/`TeachingPolicy` checks the REST boundary uses. Handbook instructions for
faculty must name the labels the renderer actually emits; the Portuguese guide is
[the faculty dashboard guide](../handbook/guia-docente-painel.md).

## ADR-14 — Teaching files are immutable versions behind a guarded delivery route

**Status:** accepted-pending-host.
**Decision:** a teaching resource is one immutable local version or one external URL, never both.
Versions live outside the web root under `LPS_TEACHING_STORAGE_ROOT`, are registered in
`{prefix}lps_resource_version_registry` under an `lpsver:<sha256>` identifier, and are delivered
only through the guarded download route after the scan boundary clears them. A correction mints
a new version; withdrawal flips `_lps_release_state` to `withdrawn` and the route denies.
**Why:** released course files must not be guessable URLs, a scanner failure must fail closed,
and a correction must never silently mutate a file students already downloaded.
**Consequence:** `LPS_TEACHING_STORAGE_ROOT`, `LPS_TEACHING_PUBLIC_ROOT` and
`LPS_TEACHING_SCANNER_APPROVED` are required production settings; the test-only scanner adapter
never satisfies the approval. See
[privacy and security operations](../operations/privacy-security-operations.md).
