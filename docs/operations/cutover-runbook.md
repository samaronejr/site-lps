# Production cutover runbook

Scope: backup, restore, rollback, DNS and TLS readiness for the LPS institutional website cutover.

**Status of this document:** every step that requires a live staging host, a production zone, a
certificate authority, or execution-time approval is marked **PENDING**. A PENDING step has not been
executed and must not be reported as passed. Statically provable steps are marked **PASS** with the
artifact that proves them.

Owners are role identifiers from `docs/content/governance.md`. No person has been named to any role;
`owners.assignees` in `docs/operations/recovery-objectives.json` is `null` for every role. That is a
launch blocker, not a formatting gap.

## Decision points

| # | Decision | Owner role | Gate | Status |
| --- | --- | --- | --- | --- |
| D1 | Are backup owner, encryption key and retention approved? | administrator | `recovery.mjs plan` exits 0 | **BLOCKED** — exits 1 with 3 blockers |
| D2 | Does a restore into a clean environment reproduce every hash? | administrator | `recovery.mjs restore-verify` exits 0 | **PASS (fixture)** / **PENDING (live)** |
| D3 | Are RPO/RTO owner-approved for both rollback scopes? | administrator, publisher | `recovery.mjs rollback-rehearsal` exits 0 | **BLOCKED** — both unapproved |
| D4 | Is the DNS/TLS plan ready for both hosts? | administrator | `dns-tls.mjs check` exits 0 | **BLOCKED** — current live risk fixture fails |
| D5 | Is execution-time approval for external writes recorded? | administrator | approval receipt exists | **PENDING** |
| D6 | Is HSTS eligible? | administrator | 30 stable TLS days on both hosts | **PENDING** — 0 stable days today |

## Preconditions (all must hold before D5)

1. Named assignees exist for `deployer`, `administrator` and `publisher`. — **PENDING**
2. Encryption recipient key issued and held by the key custodian. — **PENDING**
3. Retention window approved by the administrator role. — **PENDING**
4. Owner-approved RPO/RTO recorded for application and content rollback. — **PENDING**
5. Staging runtime from Todo 27 available and healthy. — **PENDING**
6. Approved corpus reproduced by the Todo 26 rehearsal. — **PENDING** (Todo 26 tooling landed; live rehearsal outstanding)
7. `lps.ufrj.br` answers on 443 and both hosts present valid certificates. — **PENDING** (recorded as failing on 2026-08-30)

## Step 1 — Take the encrypted backup

Command:

```
node scripts/backup/recovery.mjs plan
LPS_RECOVERY_TARGET=<alias> node scripts/backup/recovery.mjs run --confirm-mutates-target
```

Evidence: `.omo/evidence/task-28/recovery/cli-transcript.log`

- Static: the plan enumerates database, media, config, encryption and verification stages, and
  refuses without both `LPS_RECOVERY_TARGET` and `--confirm-mutates-target` (exit 2). — **PASS**
- Live: no backup has been taken. — **PENDING**

Stop criteria: any blocker in `recovery.mjs plan` output. Do not proceed to Step 2.

## Step 2 — Restore into a clean environment and compare hashes

Command:

```
node scripts/backup/recovery.mjs restore-verify --backup=<artifact.json> --tree=<tree.json>
```

Evidence: `.omo/evidence/task-28/recovery/artifact.json`,
`.omo/evidence/task-28/recovery/cli-transcript.log`

- Static: a full backup -> restore -> hash-compare cycle over fixtures verifies 3 records, 2 files
  and 3 config entries, and refuses corrupt and missing-media artifacts by name. — **PASS**
- Live: restore into a clean staging host with smoke tests. — **PENDING**

Stop criteria: any finding of class `corrupt_payload`, `missing_payload`, `digest_mismatch`,
`hash_mismatch`, `missing_item` or `unexpected_item`. A backup without a verified restore is not a
backup.

## Step 3 — Rehearse application and content-revision rollback

Command:

```
node scripts/backup/recovery.mjs rollback-rehearsal --measured=<measurements.json>
```

Evidence: `.omo/evidence/task-28/recovery/rollback-governance-blocked.md` (current governance
config, blocked), `.omo/evidence/task-28/recovery/rollback-fixture-approved.md` (approved-objective
path exercised against a fixture).

- Static: an unapproved objective yields `blocked` and a launch blocker; a measured breach of RPO or
  RTO yields `fail` naming the objective; an unrehearsed objective yields `blocked`. — **PASS**
- Live: real rollback of one release and one content revision on staging, with measured RPO/RTO. —
  **PENDING**

Stop criteria: status other than `pass`. An objective with no owner-approved RPO/RTO is a launch
blocker; do not substitute an estimate.

## Step 4 — Validate the DNS/TLS cutover plan

Command:

```
node scripts/cutover/dns-tls.mjs check --plan=<plan.json>
```

Evidence: `.omo/evidence/task-28/cutover/cli-transcript.log`,
`.omo/evidence/task-28/cutover/reports/*.md`

Checks, all statically proven over fixtures:

| Check | Proves |
| --- | --- |
| `host-coverage` | Root and `www` both have DNS records and certificate SAN coverage |
| `canonicalization` | `www` reaches the canonical host in exactly one 301 hop |
| `redirect-loop` | The redirect graph is acyclic |
| `certificate-validity` | Both hosts present a certificate valid at the reference time |
| `certificate-renewal-path` | Issuance and owned automated renewal with lead time exist |
| `hsts-timing` | HSTS stays off until both hosts have the required stable TLS days |
| `health-checks` | Checks cover both hosts with status, interval and failure threshold, and pass |
| `rollback-plan` | A rollback command and named thresholds exist |

- Static: healthy baseline exits 0; the five failure fixtures and the recorded current live risk each
  exit 1 naming the offending host, redirect edge or check id. — **PASS**
- Live: no DNS query, TLS handshake or HTTP request was made by this tooling. — **PENDING**

Stop criteria: `check` exits non-zero. `dns-tls.mjs apply` additionally refuses without
`LPS_CUTOVER_TARGET` plus `--confirm-mutates-dns`, and refuses any plan that is not ready.

## Step 5 — TTL lowering, DNS change, certificate issuance

**PENDING — requires explicit execution-time approval.** This repository must not lower TTL, change
DNS records, request production certificates, or enable HSTS. The current recorded state
(`.omo/evidence/task-2`, `docs/operations/infrastructure-preflight.md`) is:

- `lps.ufrj.br:443` — connection timeout, no certificate observed.
- `www.lps.ufrj.br:443` — certificate expired 2025-12-27, chain ends on a third-party host.

Both conditions are modelled in `tests/fixtures/cutover/current-live-risk.json`, which fails
readiness by design.

## Step 6 — HSTS

**PENDING.** HSTS remains disabled until both hosts have served a valid certificate for
`requiredStableTlsDays` (30) consecutive days. Enabling it earlier is refused by the `hsts-timing`
check because the header is irreversible for its max-age.

## Step 7 — Health checks and observation

**PENDING.** Health checks are defined in the plan for both hosts with expected status, interval and
failure threshold. Live subscription and observation belong to Todo 30.

## Rollback

Command (from the plan's `rollback.command`):

```
LPS_CUTOVER_TARGET=<zone alias> node scripts/cutover/dns-tls.mjs rollback --plan=<plan.json> --confirm-mutates-dns
```

Thresholds that force rollback:

| Threshold | Condition |
| --- | --- |
| `tls-invalid` | Either host serves an invalid or expired certificate |
| `health-check-failed` | Any health check fails 3 consecutive intervals |
| `canonical-broken` | The canonical host does not answer 200 within 5 minutes |

Application and content rollback follow Step 3 once RPO/RTO are approved.

## Evidence paths

| Artifact | Path |
| --- | --- |
| Recovery CLI transcript | `.omo/evidence/task-28/recovery/cli-transcript.log` |
| Backup artifact | `.omo/evidence/task-28/recovery/artifact.json` |
| Rollback reports | `.omo/evidence/task-28/recovery/rollback-*.md` |
| Cutover CLI transcript | `.omo/evidence/task-28/cutover/cli-transcript.log` |
| Readiness reports | `.omo/evidence/task-28/cutover/reports/*.md` |
| RED / GREEN test logs | `.omo/evidence/task-28/red/`, `.omo/evidence/task-28/green/` |
| Gate logs and exits | `.omo/evidence/task-28/gates/` |
| Manual QA matrix | `.omo/evidence/task-28/task-28-manual-qa.md` |
