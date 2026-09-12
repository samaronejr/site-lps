# Evidence retention policy

Verification evidence is part of the deliverable. A claim without its raw artifact is not a result.
This policy says what to keep, where, for how long, and what must never appear in an artifact.

## Prerequisites

- Write access to the evidence tree `.omo/evidence/` for the current task, and nothing else.
- The command logs and exit codes of whatever you are recording; a summary alone is not evidence.

## What counts as evidence

| Class | Examples | Retain |
| --- | --- | --- |
| Gate logs | Lint, unit, PHP, build, E2E output with the exit code recorded beside it | Until the release is superseded by a newer verified release, minimum one release cycle |
| RED/GREEN pairs | The failing run that proves a test detects the defect, and the passing run after the fix | Life of the test |
| QA reports | Machine-readable lane reports from the `npm run qa` lanes, plus the manual QA matrix | Until the same lane is re-run against a newer build |
| Migration artifacts | Plan, dry-run, apply and verify transcripts, reconciliation workbook, export hashes | Until the next successful rehearsal of the same corpus, minimum through cutover |
| Recovery artifacts | Backup manifests and digests, restore comparisons, rollback reports | Per the institution-approved records schedule; the proposed retention is 14 daily, 8 weekly, 12 monthly snapshots and 1 offsite copy, currently **unapproved** |
| Cutover receipts | Approval receipt, DNS/TLS readiness reports, health-check observations, final URL manifest | Permanently for the launch record |
| Accessibility and visual evidence | axe/pa11y results, keyboard transcripts, screenshots, accessibility trees | Until the next full capture of the same routes on a newer build |
| Infrastructure observations | DNS, TLS and HTTP captures with their capture timestamps | Until re-tested; keep the historical record of a failing state |

## Where evidence lives

- Task evidence: `.omo/evidence/task-<n>/`, with `red/`, `green/`, `gates/`, `qa/` subdirectories, a
  manual QA matrix and an interim report. Each gate log has a sibling exit-code file.
- Generated QA reports may also be written by the lane itself (for example the reports produced by
  `npm run qa:ia` and `npm run qa:inventory`) — keep the raw file, not a retyped summary.
- Never place evidence inside the web root of a deployed environment.
- Never edit another task's evidence directory. It is the record of that task's run.

## Freshness rules

- Evidence must be newer than the source it describes. A capture older than the build it claims to
  verify is stale and must be recaptured, not re-labelled.
- A log without an exit code is incomplete.
- A screenshot without the text steps that produced it is not a runbook and cannot substitute for one.
- Reused captures across builds are treated as an evidence defect, exactly like a product defect.

## What must never appear in evidence

- Credentials, tokens, salts, database passwords, MFA secrets or recovery codes.
- Personal data: personal e-mail addresses, student-specific accounts, personal photographs, or any
  identifiable data that is not already an approved public institutional fact.
- Request bodies, cookies, authorization headers or SQL payloads.
- Public search query strings, which are user-supplied personal data.

If a capture would contain any of the above, redact it at capture time and record that the redaction
happened. Do not retain an unredacted original "just in case".

## Log retention on the running site

Routine access and security logs expire within `30 days`. An incident hold beyond that is authorized
explicitly and documented with owner, purpose and expiry. Revisions, audit records and backups follow
the institution-approved records schedule with restricted restore access. See
[privacy and security operations](privacy-security-operations.md).

## Pruning

1. Confirm a newer verified evidence set exists for the same scope.
2. Confirm no open blocker, incident hold or launch decision depends on the older set.
3. Remove the superseded set as a whole, never selectively, so the remaining record stays coherent.
4. Keep permanently: the cutover receipts, the final URL manifest, the launch-blocker record, and the
   evidence behind any accepted risk or deviation.

## Ownership

Evidence retention is an `administrator` duty; the deployment and rollback record is a `deployer`
duty; content-correction records belong to the owning `section-editor` and `publisher`. No person is
named to any of these roles yet, which is an open launch blocker — see
[the release runbook index](release-runbook-index.md).
