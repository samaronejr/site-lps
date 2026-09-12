# Migration rehearsal runbook (staging)

Rehearses the approved-corpus migration end to end on staging: clean baseline,
dry run, apply, re-apply, export, crawl, reconcile. Every stage writes a full
transcript. Nothing in this runbook may be pointed at production.

## Components

| path | purpose |
| --- | --- |
| `scripts/migration/rehearsal.mjs` | Driver with `plan`, `reconcile` and `run` subcommands. |
| `scripts/migration/rehearsal-core.mjs` | Pure planning, classification and reconciliation logic. |
| `tests/fixtures/migration/*.json` | Approved-corpus baseline plus the five failure fixtures. |
| `tests/js/migration-rehearsal.test.mjs` | Server-free coverage of every rule below. |
| `docs/operations/migration-reconciliation-workbook.md` | Workbook template filled per rehearsal. |

## Safe-by-default execution

`plan` and `reconcile` are pure and can run at any time. `run` mutates a staging
database, so it refuses to start unless both of these are supplied:

```bash
export LPS_REHEARSAL_TARGET=@staging     # wp-cli alias for the staging site
node scripts/migration/rehearsal.mjs run \
  --package=dist/import-package.json \
  --dir=.omo/evidence/task-26/rehearsal-live \
  --confirm-mutates-staging
```

Without both, the driver exits `2` and performs no work.

## Stage order

```bash
node scripts/migration/rehearsal.mjs plan --package=dist/import-package.json
```

| # | stage | command | transcript |
| --- | --- | --- | --- |
| 1 | reset | `wp db reset --yes` | `transcripts/01-reset.log` |
| 2 | import-dry-run | `wp lps import dry-run <package>` | `transcripts/02-import-dry-run.log` |
| 3 | import-apply | `wp lps import <package>` | `transcripts/03-import-apply.log` |
| 4 | reimport-apply | `wp lps import <package>` | `transcripts/04-reimport-apply.log` |
| 5 | export | `wp lps export --output=<export>` | `transcripts/05-export.log` |
| 6 | crawl | `node scripts/run-qa.mjs links` | `transcripts/06-crawl.log` |
| 7 | reconcile | `node scripts/migration/rehearsal.mjs reconcile --corpus=<package>` | `transcripts/07-reconcile.log` |

Every stage is `stopOnFailure`: a non-zero stage exit ends the rehearsal and
later stages never run, so a broken import can never be masked by a later step.

## Dispositions

| disposition | meaning |
| --- | --- |
| `create` | No target record for the source id; the record will be inserted. |
| `update` | Target exists and an explicit `acceptSourceChanges` disposition was recorded. |
| `unchanged` | Target checksum equals the reviewed source checksum; no write is planned. |
| `quarantine` | Record has an unresolved blocker; it is never written. |
| `blocked` | The whole run stopped before any write. |

## Failure classes

| class | trigger | outcome |
| --- | --- | --- |
| `duplicate_source_id` | Same source id appears more than once. | Stop the run; zero writes planned. |
| `redirect_loop` | Redirect graph contains a cycle. | Stop the run; the cycle path is named. |
| `changed_checksum` | Source checksum differs from the reviewed checksum. | Quarantine; the reviewed target is never overwritten. |
| `unavailable_asset` | A referenced asset is not retrievable. | Quarantine that record; healthy siblings still import. |
| `ambiguous_author` | An author resolves to more than one person. | Quarantine that record; no guess is made. |
| `unresolved_author` | An author resolves to no person. | Quarantine that record. |
| `invalid_locale` | Locale is not a well-formed BCP47 tag. | Quarantine that record. |

Stops happen before any write is planned, so a defective corpus cannot produce a
partial, silent overwrite. Quarantines are per record and always carry an
explicit blocker string.

## Reconciliation and exit codes

```bash
node scripts/migration/rehearsal.mjs reconcile \
  --corpus=<package> [--target=<target-state.json>] --out=<workbook.md>
```

| exit | meaning |
| --- | --- |
| `0` | Counts reconcile exactly and nothing is quarantined. |
| `1` | The run stopped, counts did not reconcile, or records remain quarantined. |
| `2` | Usage error, or `run` invoked without target and confirmation. |

Locale counts are reported under canonical BCP47 tags (`pt-BR`, `en`).

## Idempotency contract

Re-applying the identical corpus to the target produced by the first apply must
yield `unchanged` for every record and an empty write list. This is asserted by
`tests/js/migration-rehearsal.test.mjs` and must also be observed live in stage 4
by comparing stage 3 and stage 4 transcripts and export hashes.

## Still pending (requires the live environment)

These rows cannot be closed from the static tranche and are tracked as PENDING:

- Todo 25 gate clearance before any staging execution.
- Two clean staging imports producing identical record/relation/asset/export hashes.
- Stage 4 proving zero writes and zero duplicates on a live re-apply.
- Live export hash comparison against the Todo 22 reviewed corpus.
- Crawl manifest proving `200` / one-hop `301` / `410` for every inventoried path.
- Rollback restoring pre-import hashes.
- Operator timings and step-by-step live transcripts.
