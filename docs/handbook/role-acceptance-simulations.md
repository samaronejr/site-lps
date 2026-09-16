# Role acceptance simulations (runnable specs — executed on the local staging runtime)

Each simulation starts from a clean account and fixture, completes one routine end-to-end cycle for
one role, and attempts one forbidden action. Every step below is written as an agent-executable
instruction with an observable assertion, because the acceptance criterion is that a fresh agent can
follow the role guide with no hidden context.

**Execution status: RUNNABLE and executed against the local staging runtime** built by
`scripts/deploy/` (see [the staging deployment runbook](../operations/deploy.md)). The runner is
`node scripts/acceptance/simulate.mjs`; it provisions clean accounts and fixtures, drives every role
through the real REST/capability surface inside `wp eval-file` probes, performs a real build, deploy
and rollback for the deployer, asserts public URLs over the TLS edge, and removes every trace it
created. The latest run's per-check transcript, per-command exit codes and cleanup receipt live under
`.omo/evidence/task-29/acceptance/` (`results.json`, `commands.jsonl`, `s1..s7` transcripts,
`cleanup.json`). Execution on the institution-managed staging host remains pending with that host;
the simulations are written so the same runner works there unchanged.

The statically verifiable parts — the policy matrix, publish gates, translation staleness, import
determinism and redirect graph — are also covered by `tools/composer test` and `npm run test`.

## Prerequisites

- A running staging runtime: `node scripts/deploy/staging.mjs serve` (or an equivalent deployed
  release) reachable over HTTPS at the edge port. The runner refuses to run anywhere but a loopback
  staging host.
- Nothing else: accounts, MFA enrollment, fixtures and secrets are provisioned per run by
  `scripts/acceptance/probes/provision.php` and deleted by `scripts/acceptance/probes/cleanup.php`.
  Provisioning sweeps prior simulation residue first, so a run always starts from a clean account
  and fixture even after an interrupted run.
- Playwright available: `npx playwright test --list` succeeds.

## Common harness

| Element | Value |
| --- | --- |
| Runner | `node scripts/acceptance/simulate.mjs` — copies the probes into the staging ops volume, writes a per-run secrets file (mode 0600, deleted on cleanup), runs each probe through `wp eval-file`, and records every command's real exit code in `commands.jsonl`. |
| Waiting | Subscribe to the exact state change before triggering the action where a signal exists; where none exists (release health after a flip), bounded polling with a hard deadline. No unbounded waits. |
| Evidence per run | Full per-check transcript (`T29JSON` line per probe), HTTP status list asserted through the TLS edge, audit-ledger rows for the acted-on record, and the hash-chain verification. |
| Reset between runs | `cleanup.php` removes every `t29`-marked record in any status (including `lps_archived` and `trash`, which `WP_Query` 'any' cannot see), both directions of relationship rows, every `t29.*` account, staged uploads and the fixture option; `provision.php` sweeps again before creating fixtures. |

## S1 — Contributor: create and submit

1. Sign in as the contributor account; assert the dashboard exposes only assigned collections.
2. Create a draft in an assigned collection with provenance, owner and review date.
3. Attach one existing relationship and one rights-cleared media asset with per-usage alternative
   text.
4. Submit for review; assert `_lps_state` is `in_review` and an audit row records `submit` with the
   actor.
5. Forbidden action: attempt to publish the same record. Assert the action is denied, the state is
   unchanged and no revision is created.

## S2 — Translator: translate and hand off

1. Sign in as the translator; open the `en` variant of a reviewed Portuguese record.
2. Edit the localized editorial fields only; assert the shared-field write attempt returns the policy
   error.
3. Mark the translation ready and submit it.
4. Assert `_lps_reviewed_source_hash` is unchanged until a reviewer clears the translation, and that
   the variant cannot publish while stale.
5. Forbidden action: attempt to review or publish the translation you produced. Assert denial.

## S3 — Section editor: review, archive, hand off

1. Sign in as the section editor; open the review queue for an assigned collection.
2. Reject a record with a missing source, asserting it returns to `draft` with the finding recorded.
3. Approve a complete revision and send it to a publisher.
4. Review a translation produced by the translator account and clear it; assert the reviewed hash and
   reviewer id are stored.
5. Archive a superseded record; assert relationships and provenance survive and the state is
   `archived`.
6. Forbidden action: attempt to publish your own reviewed revision. Assert denial.

## S4 — Publisher: publish, correct, unpublish, restore

1. Sign in as the publisher; assert the MFA challenge is required and that a session without an
   enabled provider has no publish capability.
2. Publish the reviewed revision; assert the public URL returns 200 in both locales where required
   and that the audit ledger records `publish`.
3. Release a correction: publish a revised revision and assert the prior revision remains available.
4. Unpublish, then restore the previous approved revision; assert reversibility and the audit trail.
5. Change a published slug and assert a one-hop redirect exists and `wp lps redirects verify` exits 0.
6. Forbidden action: attempt to publish a record with an unresolved rights or privacy gate. Assert
   the publish gate blocks it with the named error.

## S5 — Administrator: provision, configure, import

1. Sign in as the administrator with MFA; create one individual account and assign one role plus
   collections.
2. Assert a role-named or shared account creation attempt is rejected.
3. Update one site setting from an official record; assert the audit row records `settings`.
4. Run `wp lps import dry-run --input=<package.json>` and assert the mutation guard reports the state
   hash unchanged.
5. Run `wp lps import apply --input=<package.json>` twice and assert the second run plans zero writes.
6. Forbidden action: attempt to bypass a publish gate through administrative access. Assert the gate
   still blocks.

## S6 — Privacy auditor: review and block

1. Sign in as the privacy auditor; open a record with a proposed public photograph and public e-mail.
2. Record a block on the missing rights evidence; assert the item stays non-public.
3. Escalate a takedown request together with the publisher; assert the request, action and actor are
   recorded and provenance is intact.
4. Forbidden action: attempt to publish or to edit editorial content. Assert denial.

## S7 — Deployer: deploy and roll back

1. Prove the credential boundary first: the deployer account gets 403 on create, edit, review and
   publish, and writes no audit rows (`s7-deployer.php`).
2. Build the release artifacts: `npm run build`.
3. Deploy a probe release to staging per
   [the release runbook index](../operations/release-runbook-index.md):
   `node scripts/deploy/staging.mjs deploy --release=<id>`.
4. Assert the deployed release answers `/lps-ops/health` with its own release id and that
   `wp lps import verify --input=<package.json> --inventory=<dir>` reports no content drift.
5. Roll back: `node scripts/deploy/staging.mjs rollback --to=<prior>`; assert health returns and
   `current` points at the prior release again.

## Reporting

For each simulation record: role, account, fixture id, every command with its exit code, the asserted
observable state, the audit rows produced, the forbidden-action denial, and the evidence paths. A
simulation with any unexecuted step is reported PENDING, never partially passed.
