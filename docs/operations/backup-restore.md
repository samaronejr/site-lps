# Encrypted backup, restore and rollback

This procedure covers the encrypted database/media/configuration backup, the restore-into-a-clean-
environment verification, and the application and content-revision rollback rehearsals.

All logic is implemented in `scripts/backup/recovery-core.mjs` (pure) and driven by
`scripts/backup/recovery.mjs` (CLI). Owners, retention and RPO/RTO values are read from
`docs/operations/recovery-objectives.json`, which derives its role names from
`docs/content/governance.md`. No owner name, retention window or recovery objective in this
repository is invented: an unassigned owner or an unapproved objective is reported as a launch
blocker.

## Safety contract

`recovery.mjs run` is the only mutating subcommand. It refuses to start unless **both**

- `LPS_RECOVERY_TARGET` names the target environment alias, and
- `--confirm-mutates-target` is passed.

Without both it exits `2` and performs nothing. `plan`, `backup`, `restore-verify` and
`rollback-rehearsal` are pure and safe at any time.

## Scope and encryption

| Component | Source | Artifact component |
| --- | --- | --- |
| Database | records, revisions, options | `database` |
| Media | `wp-content/uploads` tree | `media` |
| Configuration | runtime configuration snapshot | `config` |

Each item is hashed with SHA-256; each component carries a digest over its sorted
`item:hash` pairs; the artifact carries a manifest digest over the three component digests in the
fixed order `database`, `media`, `config`. The artifact is encrypted to the institutional recipient
key recorded in `recovery-objectives.json`.

**Current status:** `encryption.recipientKeyId` is `null` and the `administrator` role has no named
assignee, so `recovery.mjs plan` exits `1` with `backup-encryption-key-unassigned`,
`backup-owner-unassigned` and `backup-retention-unapproved`. Producing a real backup is blocked
until the institution supplies the key and the names.

## Retention

Proposed by this repository, **not yet owner-approved** (`retention.status: "proposed"`):

| Tier | Copies |
| --- | --- |
| Daily snapshots | 14 |
| Weekly snapshots | 8 |
| Monthly snapshots | 12 |
| Offsite copies | 1 |

## Commands

```
# Print stages, retention, owner and blockers (exits 1 while blockers remain)
node scripts/backup/recovery.mjs plan

# Produce an artifact from a source tree (refuses while blockers remain)
node scripts/backup/recovery.mjs backup --tree=<tree.json> --out=<artifact.json>

# Restore into a clean tree and compare record/file/config hashes
node scripts/backup/recovery.mjs restore-verify --backup=<artifact.json> --tree=<tree.json>

# Score application and content rollback against approved RPO/RTO
node scripts/backup/recovery.mjs rollback-rehearsal --measured=<measurements.json>
```

## Restore verification

`restore-verify` exits `0` only when every record, file and configuration entry restores with a
matching hash. Findings name the exact offending component and item:

| Class | Meaning |
| --- | --- |
| `corrupt_payload` | Payload hash differs from the manifest entry |
| `missing_payload` | Manifest lists the item but the artifact has no payload |
| `digest_mismatch` | Recomputed component digest differs from the declared digest |
| `hash_mismatch` | Restored item differs from the source tree |
| `missing_item` / `unexpected_item` | Restored tree does not match the source tree membership |

Fixture evidence: `tests/fixtures/recovery/corrupt-backup.json` (names `lps-source-0002`) and
`tests/fixtures/recovery/missing-media.json` (names `uploads/2026/03/lps-seminar.jpg`) both exit
`1`.

## RPO / RTO

`docs/operations/recovery-objectives.json` records two objectives:

| Objective | RPO | RTO | Approval |
| --- | --- | --- | --- |
| `application-rollback` | null | null | unapproved (`administrator`) |
| `content-revision-rollback` | null | null | unapproved (`publisher`) |

Because neither is approved, `rollback-rehearsal` returns status `blocked` and exits `1`, emitting
`rpo-rto-unapproved:application-rollback` and `rpo-rto-unapproved:content-revision-rollback` as
launch blockers. A numeric objective without a named approver and an approval timestamp is treated
as unapproved. An objective with no rehearsal measurement is `blocked`, never `pass`.

The approved-objective path is exercised against the fixture
`tests/fixtures/recovery/objectives-approved.json`, which is explicitly labelled fixture-only and is
not an LPS approval receipt.
