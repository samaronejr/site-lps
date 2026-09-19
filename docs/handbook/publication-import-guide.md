# Publication and import guide

This is the operational path from reviewed source data to published records: build the import
package, plan, dry-run, apply, verify, export, and reconcile. The importer is
`wp-content/plugins/lps-content-model/includes/class-importer.php` with
`class-importplanner.php`, `class-reconciler.php` and `class-reportwriter.php`; the CLI surface is
registered in `class-commandregistration.php`.

## Prerequisites

- An `administrator` account for the `import` action, with MFA enabled.
- A validated inventory in `content/inventory/` (`npm run qa:inventory` exits 0).
- A reviewed corpus in `content/corpus/` (`npm run qa:content` exits 0).
- A built import package. Generate it with `node scripts/build-import-package.mjs`, which validates
  the corpus first and writes `content/import/launch-corpus.json` plus
  `content/import/locale-pairs.json`.
- A target environment you are allowed to write to. Never import into production directly.

## The command surface that actually exists

| Command | Effect |
| --- | --- |
| `wp lps import plan --input=<package.json>` | Pure. Prints the disposition plan plus the inventory reconciliation. |
| `wp lps import dry-run --input=<package.json>` | Pure. Prints the plan with a mutation guard that hashes database and upload state before and after and asserts they are unchanged. |
| `wp lps import apply --input=<package.json>` | Writes the planned records. Optional `--report-dir=<dir>` writes the reconciliation artifacts. |
| `wp lps import verify --input=<package.json>` | Reconciles target state against the package and the inventory; exits 1 unless the status is `verified`. |
| `wp lps export --output=<export.json>` | Writes the canonical export (records, relationships, redirects) with a summary. |
| `wp lps redirects verify` | Verifies the redirect graph: unique sources, no chain, no loop, no unsafe external target; exits 1 with the offending rows. |

Shared options: `--input=` (package file, also `LPS_IMPORT_INPUT`), `--assets-dir=` (staged local
assets, also `LPS_IMPORT_ASSETS_DIR`), `--inventory=` (inventory directory, also
`LPS_INVENTORY_DIR`). Every command prints deterministic JSON, so transcripts diff cleanly.

## Routine sequence

```bash
npm run qa:inventory
npm run qa:content
node scripts/build-import-package.mjs
wp lps import plan --input=content/import/launch-corpus.json
wp lps import dry-run --input=content/import/launch-corpus.json
wp lps import apply --input=content/import/launch-corpus.json --report-dir=<report-dir>
wp lps import verify --input=content/import/launch-corpus.json
wp lps export --output=<export.json>
wp lps redirects verify
```

Run `apply` twice on an unchanged package: the second run must plan zero writes and report every
record `unchanged`. That is the idempotency contract, asserted statically by
`npm run test -- tests/js/migration-rehearsal.test.mjs` and observed live in stage 4 of the rehearsal.

## Dispositions

| Disposition | Meaning |
| --- | --- |
| `create` | No target record exists for the source id; it will be inserted. |
| `update` | Target exists and an explicit `acceptSourceChanges` disposition was recorded. |
| `unchanged` | Target checksum equals the reviewed source checksum; no write is planned. |
| `quarantine` | The record has an unresolved blocker and is never written. |
| `blocked` | The whole run stopped before any write. |

## Failure classes and what to do

| Class | Trigger | Outcome | Fix |
| --- | --- | --- | --- |
| `duplicate_source_id` | The same source id appears twice | Run stops, zero writes | De-duplicate the inventory row, keep provenance for both sources |
| `redirect_loop` | The redirect graph has a cycle | Run stops, cycle path named | Repair the redirect chain to a single hop or a deliberate 410 |
| `changed_checksum` | Source checksum differs from the reviewed checksum | Record quarantined, reviewed target untouched | Re-review the source, then record `acceptSourceChanges` |
| `unavailable_asset` | A referenced asset cannot be retrieved | Record quarantined, siblings continue | Stage the asset locally with rights recorded, or drop the reference |
| `ambiguous_author` | An author matches more than one person | Record quarantined | Confirm the internal person match manually; never guess |
| `unresolved_author` | An author matches no person | Record quarantined | Create or correct the person record first |
| `invalid_locale` | Locale is not well-formed BCP47 | Record quarantined | Correct the locale tag (`pt-BR`, `en`) |
| `synthetic_record` | Record declares fixture data or a fixture provenance path | Record quarantined at plan; hard error `lps_import_synthetic_record` at the package boundary | Keep development fixtures out of the launch corpus |
| `legacy_scrape_source` | Source URL is `lps.ufrj.br`, `web.archive.org` or `archive.org` | Record quarantined at plan; hard error `lps_import_legacy_scrape_source` at the package boundary | Legacy/archive hosts are provenance only; re-source from a permitted current record |
| `catalog_source_missing` | Course/term record lacks an authoritative catalog source | Record quarantined; `lps_import_course_source_required` at the package boundary | Supply `_lps_catalog_source_url` / `_lps_term_source` from the official catalog or calendar |
| `lps_import_claim_unsourced` | A verified claim lacks its source URL or review date | Record quarantined | Attach the claim source and review date, or unverify |
| `lps_media_rights_unknown` | Media `rights_status` is not `cleared` | Media quarantined | Document rights holder and licence, or drop the asset |
| `lps_media_provenance_required` | Media lacks holder, credit, licence, source or checksum | Media quarantined | Complete the provenance row |
| `lps_media_alt_required` | Image media lacks `alt_text` and is not `decorative` | Media quarantined | Write reviewed alt text or record an explicit decorative decision |

Never resolve a quarantine by editing the target database. Fix the source data or the importer, then
re-run from `plan`.

## Reconciliation

`wp lps import verify` and `node scripts/migration/rehearsal.mjs reconcile --corpus=<package>` both
reconcile source, target and disposition counts. The rehearsal driver writes the workbook template
`docs/operations/migration-reconciliation-workbook.md`, whose sections are run identity, counts,
locales, dispositions, idempotency, route manifest, rollback and sign-off. Exit codes: `0` counts
reconcile and nothing is quarantined; `1` the run stopped, counts disagree or records are
quarantined; `2` usage error.

The full staging rehearsal — clean baseline, dry run, apply, re-apply, export, crawl, reconcile — is
documented in [the migration rehearsal runbook](../operations/migration-rehearsal.md). Its `run`
subcommand mutates staging and refuses to start without both `LPS_REHEARSAL_TARGET` and
`--confirm-mutates-staging`.

## Live rehearsal on the dedicated environment

`scripts/migration/launch-corpus-rehearsal.php` performs the dry-run/apply/re-apply/verify/export
sequence plus the reviewed-field conflict, media alt/credit staging and the failure package against
a real WordPress database inside the dedicated Playground environment. It prints one JSON transcript
and exits non-zero on any failed assertion; it resets imported state first, so it is re-runnable.
Invoke it through `wp-playground-cli php` with the worktree mounted at `/workspace` and the plugin
mounted at `/wordpress/wp-content/plugins/lps-content-model`.

## What is still PENDING

These rows require the Todo 27 staging host and cannot be closed from a workstation:

- Two clean staging imports producing identical record, relation, asset and export hashes.
- Stage 4 proving zero writes and zero duplicates on a live re-apply.
- Live export hash comparison against the reviewed corpus.
- Crawl manifest proving `200`, one-hop `301` or `410` for every inventoried path.
- Rollback restoring pre-import hashes.
- Operator timings and live step-by-step transcripts.

Until then, treat every import instruction here as verified only against fixtures
(`tests/fixtures/migration/`) and the pure planner.

## Publishing after an import

An import does not publish. Imported records enter the editorial workflow with their provenance and
review state, and a publisher releases them after review. Fields listed in
`_lps_import_reviewed_fields` are protected from silent overwrite on a later import.
