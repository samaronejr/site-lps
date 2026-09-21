# LPS release readiness ledger (task 24)

Release: `rc-2026-09-20-9d347ef` — assembled by `node scripts/release/assemble.mjs`
from the reviewed source; per-file SHA-256 manifest at `dist/release-manifest.json`
(129 files, 1 649 853 bytes). The manifest verifies clean
(`node scripts/release/readiness.mjs check --root=dist`, exit 0):
hashes match, no secrets, no fixture content, every shipped file byte-identical
to its reviewed source counterpart.

Source provenance: commit `9d347ef` (baseline `490a820` plus the task-24 lane hygiene fixes)
(`scripts/lib/seo-snapshot.mjs` import order, `theme.css` specificity order —
both zero-rendering-change, both lint-clean after). The manifest records the
exact revision and working-tree status; re-assembly is deterministic
(`npm run build` then `node scripts/release/assemble.mjs`).

**Production authorization is a separate decision.** This ledger hands off a
release candidate with verified local provenance and explicit blockers. No
production deploy, no push, no PR happened on this lane (local commits only).

## Gate results (real exit codes, 2026-09-20, this worktree)

| Gate | Command | Exit | Classification |
| --- | --- | --- | --- |
| JS lint | `npm run lint` | 1 | pre-existing debt: 51 errors across ~40 files from tasks 1–23 (45 format drift, 13 useLiteralKeys, 3 organizeImports, plus a11y/important/regex/unused findings); the two files this lane touched are clean |
| JS unit | `npm run test` | 1 | 291 passed / 3 failed; the 3 failures are `tests/js/homepage-contract.test.mjs` expecting the pre-redesign homepage section sequence — fails identically on pristine baseline (verified via `git stash`), pre-existing |
| Build | `npm run build` | 0 | pass; all performance budgets PASS |
| PHP lint | `tools/composer lint` | 2 | pre-existing debt: 598 errors / 642 warnings across 67 PHP files from tasks 1–23; no PHP file touched on this lane. (First attempt exited 1 with `Could not open input file` because `vendor/` was a symlink outside the Playground mount; replaced with a real local copy of the identical vendor tree, re-ran for the real result.) |
| PHP unit | `tools/composer test` | 1 | 744 tests, 6368 assertions, 6 failures — all in `tests/php/TrustSeedBringUpTest.php` + `TrustSeedGuardTest.php` (trust seed writes 0 records; fails standalone too, not ordering-only), pre-existing |
| PHP analyse | `tools/composer analyse` | 255 | environmental BLOCKED: PHPStan crashes exhausting Playground PHP memory (1 GiB default; retry with `--memory-limit=2G` still OOMs at ~1.2 GB allocated in `FileReader.php`). No findings reported — the analysis cannot complete in this runtime; needs a native PHP runner or a higher-memory environment |
| Browser | `npm run test:e2e` (28 spec files x chromium/firefox/webkit) | 1 | executed, nonzero: 392 passed / 186 failed / 12 skipped / 328 did-not-run (serial cascades). Every one of the 186 failures is proven pre-existing or environmental (see E2E analysis); 0 introduced on this lane. 3 further specs cannot load on this lane (blocked prerequisites below) |
| Docs | `node tests/docs/docs-checker.mjs` | 0 | pass |
| RC assembly | `node scripts/release/assemble.mjs` | 0 | pass; manifest + correspondence + secret/fixture scans green |
| RC verification | `node scripts/release/readiness.mjs check --root=dist` | 0 | pass (129/129 hashes, 0 secrets, 0 fixture paths, 0 correspondence mismatches) |
| Tamper proof | tampered `release-manifest.json`, re-ran `check` | 1 | correctly rejected, naming `lps-content-model/includes/class-audit.php`; restored manifest re-verifies exit 0 |

`npm run test:e2e -- --list` (the CI listing shortcut) exits nonzero at baseline
because `tests/e2e/todo10-security.spec.mjs` throws at import time without
`LPS_TASK10_PASSWORD`/`LPS_TASK10_FIXTURE_RECEIPT`. That is a credential-gated
spec, not a substitute for execution: per the plan, list-only output never
counts as browser evidence.

## Task reconciliation (every task → receipt or blocker)

`receipts` = test files executed in this worktree's suites. Unit receipts below
all PASS in the 2026-09-20 full `vitest` run (24/25 files; only the unrelated
`homepage-contract.test.mjs` fails). E2E receipts execute in the `npm run test:e2e`
run recorded in result.json.

### task-01 — Record the real baseline and establish executable test fixtures
Implementation: `tests/fixtures/`, `scripts/lib/foundation-fixture.mjs`, baseline inventory docs.
Receipts: `tests/js/lps-redesign/task-01.test.mjs`, `tests/e2e/lps-redesign/task-01.spec.mjs`, `wp-content/plugins/lps-content-model/tests/LpsRedesignTask01Test.php`. Status: locally verified.

### task-02 — Specify the unified identity and representative real page layouts
Implementation: `docs/design/`, theme tokens/patterns.
Receipts: `tests/js/lps-redesign/task-02.test.mjs`, `tests/e2e/lps-redesign/task-02.spec.mjs`. Status: locally verified.

### task-03 — Define teaching entities, uniqueness and history contracts
Implementation: `wp-content/plugins/lps-content-model/includes/class-teaching*.php`.
Receipts: `tests/js/lps-redesign/task-03.test.mjs`, `wp-content/plugins/lps-content-model/tests/LpsRedesignTask03Test.php`. Status: locally verified.

### task-04 — Implement offering-scoped editorial authorization
Implementation: `wp-content/plugins/lps-content-model/includes/class-roles.php`, capability policy.
Receipts: `tests/e2e/lps-redesign/task-04.spec.mjs`, `wp-content/plugins/lps-content-model/tests/LpsRedesignTask04Test.php`. Status: locally verified.

### task-05 — Unify native authoring, provenance and teaching-language publication
Implementation: content-model authoring/provenance includes, `docs/content/`.
Receipts: `tests/js/lps-redesign/task-05.test.mjs`, `wp-content/plugins/lps-content-model/tests/LpsRedesignTask05Test.php`. Status: locally verified.

### task-06 — Build nonpublic upload quarantine and release prerequisites
Implementation: media quarantine includes (`class-mediauploads.php`, `class-mediastager.php`).
Receipts: `tests/e2e/lps-redesign/task-06.spec.mjs`, `wp-content/plugins/lps-content-model/tests/LpsRedesignTask06Test.php`. Status: locally verified.

### task-07 — Implement tokens and shared accessible primitives
Implementation: theme design tokens, shared primitives.
Receipts: `tests/js/lps-redesign/task-07.test.mjs`, `tests/e2e/lps-redesign/task-07.spec.mjs`. Status: locally verified.

### task-08 — Implement teaching persistence, canonical routes and relationships
Implementation: `class-teachingrecords.php`, `class-relationships.php`, teaching routes.
Receipts: `tests/e2e/lps-redesign/task-08.spec.mjs`, `wp-content/plugins/lps-content-model/tests/LpsRedesignTask08Test.php`. Status: locally verified.

### task-09 — Implement immutable resource versions and guarded downloads
Implementation: `class-teachingresources.php`, guarded download boundary.
Receipts: `tests/e2e/lps-redesign/task-09.spec.mjs`, `wp-content/plugins/lps-content-model/tests/LpsRedesignTask09Test.php`. Status: locally verified.

### task-10 — Replace the shared shell, navigation and logo integration
Implementation: `wp-content/themes/lps-theme/includes/class-shell.php`, templates, `assets/`.
Receipts: `tests/e2e/lps-redesign/task-10.spec.mjs`. Status: locally verified (browser); the stale `homepage-contract.test.mjs` expectation against the pre-redesign section order is verification debt (see gate table), not a task-10 regression.

### task-11 — Redesign the real dynamic homepage and governed image pipeline
Implementation: `class-homepage.php`, media pipeline (`class-mediarenderer.php`).
Receipts: `tests/e2e/lps-redesign/task-11.spec.mjs`, `wp-content/themes/lps-theme/tests/LpsRedesignTask11Test.php`. Status: locally verified.

### task-12 — Extend search and public-change propagation to teaching
Implementation: `class-searchindex.php`, `class-searchpolicy.php`, propagation.
Receipts: `tests/e2e/lps-redesign/task-12.spec.mjs`, `wp-content/plugins/lps-content-model/tests/LpsRedesignTask12Test.php`. Status: locally verified.

### task-13 — Build course directories, offerings, units and material views
Implementation: `class-teachingroutes.php`, teaching surfaces.
Receipts: `tests/e2e/lps-redesign/task-13.spec.mjs`, `wp-content/themes/lps-theme/tests/LpsRedesignTask13Test.php`. Status: locally verified.

### task-14 — Apply the identity across faculty and all existing public sections
Implementation: people/org/trust surfaces, templates.
Receipts: `tests/e2e/lps-redesign/task-14.spec.mjs`. Status: locally verified.

### task-15 — Implement safe next-term copy and correction history
Implementation: `class-teachingcopy.php`, history/audit.
Receipts: `tests/e2e/lps-redesign/task-15.spec.mjs`, `wp-content/plugins/lps-content-model/tests/LpsRedesignTask15Test.php`. Status: locally verified.

### task-16 — Deliver the faculty task dashboard and complete publication journeys
Implementation: `class-taskdashboard.php`, dashboard surfaces.
Receipts: `tests/e2e/lps-redesign/task-16.spec.mjs`, `wp-content/plugins/lps-content-model/tests/LpsRedesignTask16Test.php`. Status: locally verified.

### task-17 — Prepare the reviewed launch corpus and media selections
Implementation: `content/corpus/`, `content/import/launch-corpus.json`, `scripts/build-import-package.mjs`, `scripts/lib/content-corpus.mjs`.
Receipts: `tests/js/lps-redesign/task-17.test.mjs`, `wp-content/plugins/lps-content-model/tests/LpsRedesignTask17Test.php`. Status: locally verified.

### task-18 — Complete language navigation, search metadata, SEO and print
Implementation: SEO includes (`class-seopolicy.php`, `class-structureddata.php`), `scripts/lib/seo-*.mjs`.
Receipts: `tests/e2e/lps-redesign/task-18.spec.mjs`. Status: locally verified.

### task-19 — Synchronize governance, technical documentation and editor handbook
Implementation: `docs/` set, `docs/documentation-contract.json`, `tests/docs/docs-checker.mjs`.
Receipts: `tests/js/lps-redesign/task-19.test.mjs`, `tests/e2e/lps-redesign/task-19.spec.mjs`. Status: locally verified (docs-checker exit 0).

### task-20 — Run adversarial security and privacy verification
Implementation: hardening (`wp-content/mu-plugins/lps-security.php`, `class-hardening.php`, `class-securitypolicy.php`), security docs.
Receipts: `tests/e2e/lps-redesign/task-20.spec.mjs`, `wp-content/plugins/lps-content-model/tests/LpsRedesignTask20Test.php`, `tests/e2e/todo10-security.spec.mjs` (credential-gated). Status: locally verified; institutional security approval stays a hosted blocker.

### task-21 — Verify accessibility, responsive layouts and visual fidelity
Implementation: theme responsive CSS, `scripts/lib/a11y*.mjs`, reduced-motion handling.
Receipts: `tests/e2e/lps-redesign/task-21.spec.mjs`, `wp-content/themes/lps-theme/tests/AccessibilitySurfacesTest.php`. Status: locally verified; assistive-technology coverage names only what was actually used (see task-21 evidence).

### task-22 — Measure performance and validate time/cache behavior
Implementation: `scripts/lib/performance-budget.mjs`, `class-cachepolicy.php`, `class-delivery.php`, `docs/operations/performance-measurement.md`.
Receipts: `tests/js/lps-redesign/task-22.test.mjs`, `tests/e2e/lps-redesign/task-22.spec.mjs`. Status: locally verified as laboratory measurement; field percentiles (INP) remain unclaimed/hosted.

### task-23 — Rehearse staging deployment, media/database restore and rollback
Implementation: `scripts/deploy/local-rehearsal.mjs`, `scripts/backup/recovery*.mjs`, `scripts/migration/rehearsal*.mjs`, `scripts/cutover/dns-tls*.mjs`, runbooks in `docs/operations/`.
Receipts: `tests/js/lps-redesign/task-23.test.mjs`, `tests/e2e/lps-redesign/task-23.spec.mjs`. Status: local rehearsal contract verified; the authorized staging rehearsal is BLOCKED (no staging host/keys/approvals).

### task-24 — Assemble the release candidate and truthful readiness handoff
Implementation: `scripts/release/assemble.mjs`, `scripts/release/readiness.mjs`, this ledger.
Receipts: `tests/js/lps-redesign/task-24.test.mjs` (executed in the unit run), RC manifest + `readiness.mjs check` evidence, tamper-rejection log. Status: assembly complete; handoff verdict BLOCKED (see below) — truthfully, not as a pass.

## Hosted / external prerequisites (BLOCKED, not passed)

Three browser specs cannot load on this lane at all — each needs a
lane-local credential/targets pipeline or a live environment, none of which
may be fabricated. They are excluded from the executable set and recorded
here, not silently skipped:

- `tests/e2e/todo10-security.spec.mjs`: throws at import without
  `LPS_TASK10_PASSWORD` + `LPS_TASK10_FIXTURE_RECEIPT`. The password is
  operator-chosen and the receipt is produced by task-10's provisioning
  pipeline (blueprint boot + fixture step); no reproducible generator for
  that pipeline exists in this tree.
- `tests/e2e/todo20-authenticated.spec.mjs`: throws at import without
  `LPS_SECURITY_FIXTURE` — disposable accounts for task-20's throwaway env,
  never checked in by design; no generator in this tree.
- `tests/e2e/todo22-corpus.spec.mjs`: throws at import without
  `.omo/evidence/task-22/live/spot-check-targets.json` — live spot-check
  targets with a TOTP secret and editor credentials for a LIVE environment.
  A live prerequisite by construction.

Other hosted prerequisites:

1. Staging host provisioning + hosted deployment (cron, cache, logs, monitoring).
2. Staging migration rehearsal on an authorized target (two clean imports, zero-write proof).
3. Backup encryption recipient key, retention approval, real encrypted backup artifact.
4. Owner-approved RPO/RTO for `application-rollback` / `content-revision-rollback`.
5. Valid TLS + canonicalization + HSTS preconditions for `lps.ufrj.br` / `www.lps.ufrj.br`.
6. Institutional owner, legal/rights approval, accessible contact, scanner/scheduler integration.
7. Execution-time approval receipts for external writes (cutover decision D5).
8. PHPStan clean analysis (environmental: needs >1 GiB PHP memory or a native PHP runner).
9. Per-role end-to-end acceptance simulations on staging (`docs/handbook/role-acceptance-simulations.md`).

## Local verification debt (pre-existing, fixable without hosting)

- `npm run lint` exit 1: 51 diagnostics across ~40 task 1–23 files (mostly format drift + safe autofixes; a few need judgment: `noImportantStyles` x2, `noControlCharactersInRegex` x2, `useValidAnchor` x1).
- `npm run test` exit 1: `homepage-contract.test.mjs` (3 tests) pins the pre-redesign homepage order against the redesigned implementation.
- `tools/composer lint` exit 2: 598 errors / 642 warnings across 67 PHP files.
- `tools/composer test` exit 1: 6 trust-seed bring-up/guard failures (seed writes 0 records).
- `tools/composer analyse`: crashed on the Playground 1 GiB memory limit before reporting findings.

## E2E analysis (all failures classified, none introduced)

Full suite on this lane: 28 of 31 spec files (the 3 unloadable ones are
recorded under blocked prerequisites) x chromium/firefox/webkit = 918
listed; actual outcome **392 passed / 186 failed / 12 skipped (by design:
`test.skip` sentinels) / 328 did-not-run**. The did-not-run count is the
mechanical consequence of `mode: serial` suites aborting their remaining
tests after a first failure — not silently skipped coverage.

- Dedicated-environment specs (task-09/11/12/13/14/15/16/18 first
  failures): every one fails at first navigation with connection-refused
  (chromium) / NS_ERROR_CONNECTION_REFUSED (firefox) / Could-not-connect
  (webkit). Port survey at run time: 8892, 8895–8900 answer nothing — the
  lane-provisioned servers these specs expect do not exist here, while the
  self-provisioning suites (task-19 :8902, task-20 :8903, task-22 :8905,
  task-23 :8906) boot their own servers and pass. Environmental.
- Shared-env specs (task-06/07/08/21, todo16-people, todo20-privacy,
  todo23-accessibility on :8888): re-ran all 7 on chromium against pristine
  baseline via `git stash` — per-spec failure counts identical to this lane
  (1/2/1/4/2/6/16 in both runs; 32 failed / 28 passed / 45 did-not-run
  each). Pre-existing, byte for byte.
- Visual specs (todo24-interaction-states): 12/12 fail identically on
  baseline and on this lane (separate stash A/B). Headless hover/focus and
  keyboard-order assertions; pre-existing. task-10/task-11 — the heaviest
  theme consumers — pass fully (0 fails x3 browsers), confirming the
  task-24 CSS reorder is rendering-neutral.
- task-18 + task-22 (chromium+webkit): stash A/B identical (2 failed /
  20 did-not-run / 12 passed in both). Pre-existing.
- todo20-privacy external-request failures reproduce identically on
  baseline; the sandbox network answers egress the hermetic lane
  environment blocked. Environmental.
- Suite side effect: task-19.spec.mjs rewrites `docs/handbook/images/*.png`
  screenshots on every run; the 6 files it dirtied were reverted with
  `git checkout` and are not part of this lane.

## Verdict

**BLOCKED — not ready for final review, not authorized for production.**

The release candidate itself is assembled and provenance-verified (manifest,
correspondence, secret/fixture scans, tamper rejection all green), and every
task links to an executed local receipt or an explicit blocker above. What
blocks readiness is (a) the local verification debt — the project's own gates
do not pass, and a candidate that fails its gates cannot be called ready — and
(b) the hosted prerequisites, none of which can be satisfied locally. Clearing
(a) is code work on earlier lanes; clearing (b) needs institutional
provisioning and authorizations. Neither may be waived by this handoff.
