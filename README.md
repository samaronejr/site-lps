# LPS institutional website — maintainer handbook entry point

This repository contains the complete WordPress workspace for the public website of the
**Laboratório de Processamento de Sinais (LPS)**, a laboratory of **UFRJ/COPPE**. It is a
self-hosted block-theme WordPress site with one custom plugin that owns the content model. There is
no page builder, no analytics, no form, no third-party embed, and no headless split.

Everything a maintainer needs is in this repository. Nothing in this handbook depends on oral
knowledge, undocumented dashboard clicks, or a personal account. No credential, personal e-mail,
photo, or student-specific account appears in any document here; where an owner is required and
governance has not named one, the document states the **role** and records a launch blocker.

## Prerequisites

- Node.js `24.20.0` and npm `11.19.0` (see `.nvmrc` and `docs/architecture/runtime.md`).
- PHP `8.3` with Composer for the PHP gates. `php` may be absent from `PATH`; use the wrapper
  `tools/composer`, which is the supported entry point for every PHP command in this handbook.
- Install pinned JavaScript dependencies with `npm ci`.
- Install pinned PHP dependencies with `tools/composer install`.
- Start the local WordPress runtime with `npm run env:start` (WordPress Playground through
  `@wordpress/env`; no Docker required). Stop it with `npm run env:stop`.

## Repository layout

| Path | Owns |
| --- | --- |
| `wp-content/plugins/lps-content-model/` | Content types, taxonomies, metadata, relationships, publish gates, roles/capabilities, translation state, search index, import/export, redirects. |
| `wp-content/themes/lps-theme/` | `theme.json` tokens, block templates, template parts, patterns, public routes and rendering. |
| `wp-content/mu-plugins/lps-security.php` | Production plugin allowlist and security headers (must-use plugin). |
| `content/` | Inventory (`content/inventory/`), controlled vocabularies (`content/taxonomies/`), reviewed corpus (`content/corpus/`) and generated import package (`content/import/`). |
| `scripts/` | QA lanes, migration rehearsal, backup/recovery, DNS/TLS cutover, performance and accessibility tooling. |
| `tests/` | Vitest suites (`tests/js/`), Playwright journeys (`tests/e2e/`), PHP integration tests (`tests/php/`), fixtures (`tests/fixtures/`), documentation checker (`tests/docs/`). |
| `docs/` | Architecture, content governance, design, editorial handbook and operations documentation. |
| `dist/` | Build output produced by `npm run build`; never edited by hand. |

## One-command gates

| Command | Purpose |
| --- | --- |
| `npm run lint` | Biome over JavaScript/JSON. |
| `npm run test` | Vitest unit and contract suites. |
| `npm run build` | Produces `dist/` release artifacts. |
| `npm run test:e2e` | Playwright public and editorial journeys (needs a running site). |
| `npm run ci` | Lint, test, build and the Playwright test listing in one pass. |
| `tools/composer lint` | PHPCS (WordPress Coding Standards). |
| `tools/composer test` | PHPUnit contract and integration tests. |
| `tools/composer analyse` | PHPStan at level max. Provision the native PHP 8.3 runtime once with `tools/setup-php-native`; the full analysis then completes inside the 1536M limit in `tools/php-native.ini`. The recorded 512M exhaustion belonged to the PHP.wasm fallback; do not raise memory limits to work around it. |
| `node tests/docs/docs-checker.mjs` | Documentation checker: broken links, missing referenced files, unknown commands, removed prerequisites, denied capability claims, stale/expired documentation, and coverage of every setting, collection, role, recurring task, update path and recovery action. |

QA lanes: `npm run qa`, `npm run qa:content`, `npm run qa:links`, `npm run qa:schema`,
`npm run qa:seo`, `npm run qa:a11y`, `npm run qa:visual`, `npm run qa:security`,
`npm run qa:infrastructure`, `npm run qa:inventory`, `npm run qa:governance`, `npm run qa:ia`,
`npm run qa:design-system`.

## Rebuild the local environment from scratch

```bash
npm ci
tools/composer install
npm run env:start
npm run build
npm run test
node tests/docs/docs-checker.mjs
```

`npm run env:clean` resets the local WordPress database. Staging and production rebuilds follow
[the release runbook index](docs/operations/release-runbook-index.md); the staging host itself is a
pending external precondition.

## Documentation map

| Document | Read it when |
| --- | --- |
| [Architecture decision record](docs/architecture/decision-record.md) | You need the reason a structural choice was made before changing it. |
| [Supported runtime](docs/architecture/runtime.md) | You are upgrading WordPress, PHP, Node or an allowlisted plugin. |
| [Workspace inventory](docs/architecture/workspace-inventory.md) | You need the original do-not-touch boundary. |
| [Content model and data dictionary](docs/content/data-dictionary.md) | You are adding or auditing a field, relationship or setting. |
| [Editorial governance](docs/content/governance.md) | You need role responsibilities, staffing minimums and launch blockers. |
| [Editorial workflow](docs/content/editorial-workflow.md) | You need the publish, correction, takedown and freshness process. |
| [Source-priority policy](docs/content/source-priority.md) | Two sources disagree. |
| [Information architecture](docs/content/information-architecture.md) | You are adding a route, navigation item or facet. |
| [Design-system guide](docs/design/design-system-guide.md) | You are building or reviewing a public surface. |
| [Editorial quick start](docs/handbook/editorial-quick-start.md) | You are a new editor publishing your first record. |
| [Role workflows](docs/handbook/role-workflows.md) | You need the exact steps and limits of your role. |
| [Translation and freshness guide](docs/handbook/translation-freshness.md) | You are translating or re-reviewing content. |
| [Publication and import guide](docs/handbook/publication-import-guide.md) | You are importing, exporting or reconciling records. |
| [Accessibility authoring checklist](docs/handbook/accessibility-authoring-checklist.md) | You are writing content or adding media. |
| [Role acceptance simulations](docs/handbook/role-acceptance-simulations.md) | You are verifying that each role can complete its routine work. |
| [Privacy and security operations](docs/operations/privacy-security-operations.md) | You operate the site or answer a privacy request. |
| [Plugin and update policy](docs/operations/plugin-update-policy.md) | You are patching or upgrading a dependency. |
| [Release runbook index](docs/operations/release-runbook-index.md) | You are deploying, backing up, restoring or cutting over. |
| [Troubleshooting](docs/operations/troubleshooting.md) | A gate, import or environment is failing. |
| [Evidence retention policy](docs/operations/evidence-retention.md) | You are storing or pruning verification evidence. |

## Launch boundary

Local implementation is complete for the tranches recorded in `docs/`, but production launch is
blocked until the external preconditions are resolved. The current blockers are, verbatim from the
executable contracts:

- No person is named to any editorial role or collection ownership
  (`tests/fixtures/governance/role-collection-matrix.json`, launch blocker
  `named-role-and-collection-assignments`).
- No legal basis for public personal-data processing is documented (`privacy-legal-bases`).
- No owned public correction/takedown route exists (`public-correction-and-takedown-contact`).
- No accessibility reporting contact is named (`accessibility-contact`).
- No backup encryption recipient key, retention approval or approved RPO/RTO exists
  (`docs/operations/recovery-objectives.json`).
- The institution-managed staging host is not provisioned, so production cutover and the hosted
  migration rehearsal remain **PENDING**. The per-role end-to-end acceptance simulations run against
  the local staging runtime instead: `node scripts/acceptance/simulate.mjs` (see
  [role acceptance simulations](docs/handbook/role-acceptance-simulations.md)).

Do not resolve a blocker by inventing a name, an address or an approval. Record the documentary
source, or leave the blocker open.
