# Role acceptance simulations (runnable specs — live execution PENDING)

Each simulation starts from a clean account and fixture, completes one routine end-to-end cycle for
one role, and attempts one forbidden action. Every step below is written as an agent-executable
instruction with an observable assertion, because the acceptance criterion is that a fresh agent can
follow the role guide with no hidden context.

**Execution status: PENDING for every simulation.** They require the institution-managed staging host
(Todo 27) and a live CMS with provisioned accounts. Nothing in this document has been executed
against a live environment, and no simulation may be reported as passed until it runs there. The
statically verifiable parts — the policy matrix, publish gates, translation staleness, import
determinism and redirect graph — are already covered by
`tools/composer test` and `npm run test`.

## Prerequisites

- A staging site from `docs/operations/infrastructure-preflight.md` with `npm run env:start`-shaped
  parity, reachable over HTTPS.
- Seven provisioned accounts, one per policy role, each individual and named, created by an
  administrator with collection assignments recorded.
- Two-Factor enrolled for the publisher and administrator accounts.
- A clean fixture corpus applied with `wp lps import apply --input=<package.json>` and verified with
  `wp lps import verify --input=<package.json>`.
- Playwright available: `npx playwright test --list` succeeds.

## Common harness

| Element | Value |
| --- | --- |
| Runner | `npx playwright test tests/e2e/todo20-authenticated.spec.mjs` is the existing authenticated-journey harness the simulations extend. |
| Waiting | Subscribe to the exact navigation, response or state event before triggering it; bounded timeouts only. No fixed sleep, no polling. |
| Evidence per run | Full transcript, HTTP status list, audit-ledger rows for the acted-on record, and a screenshot per asserted state. |
| Reset between runs | Restore the clean fixture; never continue from a previous simulation's residue. |

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

1. Build from a clean checkout: `npm ci`, `tools/composer install`, `npm run build`.
2. Deploy the `dist/` artifacts to staging per
   [the release runbook index](../operations/release-runbook-index.md).
3. Assert the deployed version answers health checks and that no content drift is introduced.
4. Roll back the release artifact and assert the prior version is restored.
5. Forbidden action: attempt to edit public content or approve an editorial revision with the deploy
   credential. Assert denial.

## Reporting

For each simulation record: role, account, fixture id, every command with its exit code, the asserted
observable state, the audit rows produced, the forbidden-action denial, and the evidence paths. A
simulation with any unexecuted step is reported PENDING, never partially passed.
