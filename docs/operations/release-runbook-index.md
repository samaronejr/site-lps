# Release runbook index (backup, restore, deploy, cutover)

This is the map, not a second copy. The executable runbooks already exist and are authoritative:

| Runbook | Owns | Status |
| --- | --- | --- |
| [Migration rehearsal](migration-rehearsal.md) | Clean staging baseline, dry run, apply, re-apply, export, crawl, reconcile | Tooling and fixtures verified; live rehearsal **PENDING** staging host |
| [Migration reconciliation workbook](migration-reconciliation-workbook.md) | The per-rehearsal record: run identity, counts, locales, dispositions, idempotency, route manifest, rollback, sign-off | Template ready; filled per live run (**PENDING**) |
| [Encrypted backup, restore and rollback](backup-restore.md) | Backup scope and encryption, retention, restore verification, RPO/RTO scoring | Local backup/restore/rollback rehearsal **VERIFIED** (task-23 evidence); encrypted artifact, retention approval and RPO/RTO approval still **BLOCKED** |
| [Production cutover runbook](cutover-runbook.md) | Decision points D1–D6, preconditions, seven cutover steps, rollback thresholds, evidence paths | Statically provable steps PASS; every live step **PENDING** |
| [Infrastructure preflight](infrastructure-preflight.md) | Observed DNS, TLS, HTTP and the operational capability checklist | Root and `www` TLS recorded as failing on 2026-08-30 |
| [Performance hosting and CDN requirements](performance-hosting.md) | Payload and field budgets, response handling, publish invalidation, origin requirements | Budgets enforced locally; host configuration **PENDING** |
| [Security and privacy policy](security-policy.md) | Release boundary, authentication, headers, uploads, secrets, retention | Local verification only; institutional approval **PENDING** |

Do not duplicate their steps here. Read them, and record their results in their own evidence paths.

## Prerequisites

- `npm ci`, `tools/composer install` and `npm run build` complete from a clean checkout.
- The reviewed corpus and import package exist (`node scripts/build-import-package.mjs`).
- A target environment alias, plus the explicit confirmation flag, for any mutating command.

## Deployment

The deploy runbook is [deploy.md](deploy.md): artifact-based deploy and
git-revert rollback, verified end-to-end by the local rehearsal in task 23
(`.omo/evidence/lps-website-ulw-plan/attempt-1/task-23/`). The hosted staging
deployment remains **PENDING** on the institution-managed host. The deployable facts are:

- Build with `npm run build`. Deploy `dist/lps-content-model` to
  `wp-content/plugins/lps-content-model`, `dist/lps-theme` to `wp-content/themes/lps-theme` and
  `dist/mu-plugins/lps-security.php` to `wp-content/mu-plugins`.
- Keep the database, repository, fixtures, logs, tests, lockfiles, credentials and evidence archives
  outside the web root.
- Apply the required production configuration flags in
  [privacy and security operations](privacy-security-operations.md) before enabling traffic.
- Rollback is artifact-based: keep the previous `dist/` release and its lockfiles.

Everything else about deployment — cron, object cache, purge wiring, structured logs, uptime checks,
alert routing and maintenance mode — is **PENDING** on the staging host and must not be reported as
configured.

## Recovery actions that exist today

All of these are pure and safe to run on a workstation; only `run` mutates and it refuses to start
without both `LPS_RECOVERY_TARGET` and `--confirm-mutates-target`.

| Action | Command | Current result |
| --- | --- | --- |
| Plan the encrypted backup | `node scripts/backup/recovery.mjs plan` | Exits 1 with `backup-encryption-key-unassigned`, `backup-owner-unassigned`, `backup-retention-unapproved` |
| Produce a backup artifact | `node scripts/backup/recovery.mjs backup --tree=<tree.json> --out=<artifact.json>` | Refuses while those blockers remain |
| Verify a restore | `node scripts/backup/recovery.mjs restore-verify --backup=<artifact.json> --tree=<tree.json>` | Passes against fixtures; live restore **PENDING** |
| Score rollback objectives | `node scripts/backup/recovery.mjs rollback-rehearsal --measured=<measurements.json>` | `blocked`: both objectives unapproved |
| Validate the DNS/TLS plan | `node scripts/cutover/dns-tls.mjs check --plan=<plan.json>` | Fails on the current live-risk fixture, as intended |
| Roll back DNS/TLS | `node scripts/cutover/dns-tls.mjs rollback --plan=<plan.json> --confirm-mutates-dns` | **PENDING**: requires `LPS_CUTOVER_TARGET` and execution-time approval |
| Rehearse the migration | `node scripts/migration/rehearsal.mjs plan --package=<package.json>` | Pure; the mutating `run` is **PENDING** staging |

Recovery objectives are recorded in `docs/operations/recovery-objectives.json`:
`application-rollback` (approver role administrator) and `content-revision-rollback` (approver role
publisher). Both have `null` RPO and RTO and status `unapproved`, so neither may be presented as a
tested capability.

## Owner roles and the missing names

| Duty | Role | Assignee |
| --- | --- | --- |
| Backup operator | `deployer` | none — launch blocker |
| Restore verifier | `administrator` | none — launch blocker |
| Content rollback | `publisher` | none — launch blocker |
| Cutover approver | `administrator` | none — launch blocker |
| Backup encryption key custodian | `administrator` | none — launch blocker |

No person is named anywhere in this repository. Do not substitute a person; obtain the assignment and
record its source.

## Consolidated PENDING list for the live tranche

1. Staging host provisioning, hosted deployment, cron, cache, logs and monitoring (the deploy runbook exists; the host does not).
2. Two clean staging migration imports with identical hashes, and stage-4 zero-write proof.
3. Live crawl manifest for `200` / one-hop `301` / `410` across every inventoried path.
4. Backup encryption recipient key, retention approval, and a real encrypted backup artifact.
5. Restore into a clean live environment with hash comparison and smoke tests (the local rehearsal
   verified the contract on a disposable Playground target; the hosted restore is still pending).
6. Owner-approved RPO/RTO for `application-rollback` and `content-revision-rollback`, then a hosted
   rollback rehearsal with measurements (the local git-revert rollback rehearsal passed; scoring
   against approved objectives stays blocked).
7. Valid TLS on `lps.ufrj.br` and `www.lps.ufrj.br`, one-hop canonicalization, 30 stable TLS days
   before HSTS.
8. Execution-time approval receipt for external writes (decision D5).
9. Live health checks, alert routing test and the observation window.
10. Per-role end-to-end acceptance simulations from
    [role acceptance simulations](../handbook/role-acceptance-simulations.md).
