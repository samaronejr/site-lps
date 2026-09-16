# Task 24 fixture and inventory contract

This helper prepares disposable QA content, not production content or final visual approval.
Run from the Wave 3 worktree. Node dependencies are read-only shared dependencies.

## Reproduction

The historical input root defaults to the preserved original task-24 evidence. Override
LPS_TASK24_HISTORY only with an identical archived copy; identity is enforced, not
assumed. The provisioner reads it and copies WordPress plus a consistent attempt-4
SQLite backup. It never runs a historical command or mounts a historical writable path.
The saved attempt-5 fixture-fixed.sqlite is provenance, not an unexplained substitute
for the migration. The migration starts from the unfixed attempt-4 database.

### Declared inputs and identity validation

tests/fixtures/task24/archive-identity.json is the checked-in expected identity of every
provisioning input. Nothing is copied before it verifies:

- the imported WordPress tree (file count and an ordered path:digest rollup) and the
  attempt-4 database and attempt-5 provenance database by sha256;
- tests/fixtures/media/assets, the durable source of the repaired 24a/24b media bytes;
- every declared durable mu-plugin source.

A missing input exits non-zero with `missing-provisioning-input`; a changed byte, an extra
file or a corrupt database exits non-zero with `archive-identity-mismatch`. After copying,
the provisioned tree is re-hashed against the same rollup, so exit 0 means the inputs were
validated and faithfully reproduced, never that a step was skipped. The observed identity,
the manifest path and the manifest's own hash are recorded in manifests/identity.json. The
frozen 146-file product source identity is unchanged: it still hashes every theme and
plugin file of the QA copy and is reported as sourceHash. That count follows the worktree,
so a dirty tree legitimately reports a different count and hash.

mu-plugins are never imported from the archive. The archive's mu-plugins, wp-content/cache,
wp-content/upgrade, wp-content/themes, wp-content/uploads/lps-qa-media, its adversarial
probe and its logs are excluded; the three zero-byte archived a11y stubs carried no
behaviour and are not reproduced. The runtime set is provisioned from the repository and
mirrors the .wp-env.json mapping. lps-0-content-model-loader.php (loads the content model
before the seeds run) and lps-zz-t24-harness.php (serves the ?lps_t24= fixture states the
crosscheck requires) are frozen QA recoveries kept in tests/fixtures/wp/ and pinned by
sha256. The remaining seeds are live fixtures owned by their own lanes: they must exist and
carry bytes, and their observed hashes are recorded per run instead of being pinned twice
against version control.

The fixture administrator is a required input, validated by ID and granted role only; no
login, e-mail address or password material is read, recorded or published. The migration
resolves the acting administrator at runtime and fails with 500 when none exists or it
lacks the capabilities it needs, instead of assuming user 1 exists in the archive.

Commands, each exiting non-zero on any unmet input:

```sh
node scripts/qa/task24/prepare.mjs            # provision, validating every declared input
node scripts/qa/task24/identity-inputs.mjs    # report observed input identities (read-only)
node --test scripts/qa/task24/provision.test.mjs
```

LPS_TASK24_ROOT relocates the provisioned root (default .omo/evidence/task-24k) for an
isolated run. The green phase re-checks the earlier run's mu-plugins and administrator
before starting a server, so stale or edited fixture state fails immediately.

Use an asynchronous monitored supervisor around each complete invocation:

```sh
flock -x -w 1800 .omo/evidence/heavy-qa.lock env LPS_TASK24_LOCKED=1 node scripts/qa/task24/run.mjs baseline-red
flock -x -w 1800 .omo/evidence/heavy-qa.lock env LPS_TASK24_LOCKED=1 node scripts/qa/task24/run.mjs green
```

The first invocation requires baseline exit 0 and targeted RED exit 1. The second
requires corrected Manual-QA exit 0. Server startup has a 180-second deadline;
QA children have 600 seconds and graceful teardown has 20 seconds. An enclosing
2700-second timeout bounds lock wait plus runtime. No sleeps or retry-to-pass loops.

The exact requested browser entry point, used by the supervisor, is:

`LPS_QA_URL=http://127.0.0.1:8903 node .omo/evidence/task-24k/qa.mjs baseline|red|green`

It requires the owned server already running under the lock. One headed Chromium
is launched and closed per phase. Linux short /proc/self/cwd paths resolve to the
owned temporary directory and avoid the 108-byte Unix-socket limit.

## Input and output schema

- tests/fixtures/task24/requirements.json: schemaVersion=1; independent named
  route/path/template/locale/state/mode requirements and required viewport dimensions.
  The count is the route x viewport product, never a success count or ceiling.
  A requirement may also name the record it must render (recordId) and the public state
  that record must be in (recordState); excludedStates declares, with its contract
  reason, each public state that has no addressable single page.
- inherited-matrix.json: preserved older identities for non-regression, not the source
  of new requirements. All inherited route/state/viewport cells must remain.
- inventory.mjs: expands requirements and rejects missing, duplicate, unexpected,
  wrong-path/template/locale/state/mode/viewport/dimension cells.
- controls.mjs: recovered exact DOM enumeration and component-equivalence contract.
  Native media, all captions, focus, keyboard, fullscreen, PiP transition/settled,
  and action states remain requirements. Invisible controls need an explicit reason.
- contract.mjs: validates a schemaVersion=1 plan with cells, sourceHash and fixtureHash.
  Its result is inventory-only and explicitly captureApproval=false.
- inspect.php: runtime export of published record IDs, parent, slug, type, title,
  WordPress permalink, declared/Polylang locales, reciprocal pairs, page key and state,
  plus the machine-consumed facts that decide the record's public state (lifecycle state,
  person status and consent approval, public-profile flag, event and application dates and
  status, and the names of the identifier fields that are set), merged from the Portuguese
  authority exactly as the public surfaces merge them.
  It exports no users, credentials, draft content, or private metadata.
  WordPress CPT permalinks differ from the theme's public routes. Actual queried
  post IDs and selected template headers bind the browser/HTTP proof, not a guessed
  slug transformation. The catalog includes published-but-archived/hidden test records;
  its count is not a count of publicly available pages or approved content.
- record-state.mjs: distinct public state of a record, mirroring the shipped contracts
  (TrustSurfacePolicy::event_state/opportunity_state, PublicRoutes::person_record/
  organization_record/is_addressable, the DiscoveryRoutes publication identifier block).
  Template and locale alone are not an equivalence: a record whose public state differs
  from the cell that would "represent" it is reported, not absorbed. A state the public
  surface never addresses (archived person, unapproved in-memoriam profile, organization
  without a public profile) must be declared in requirements.excludedStates with its
  contract reason; an undeclared one fails.
- crosscheck.mjs: fetches every declared route/state, checks actual template header,
  HTTP status, H1, language and redirects, and accounts for every public fixture record
  as an exact route, another record of an already required public state, or a declared
  unaddressable state. A requirement naming a record identity and state must render that
  record, in that state, with the same state the page prints into data-state.
- manifests/identity.json: exact frozen product file hashes, imported/fixed historical
  database hashes and byte identities for each media asset.
- baseline/red/green: fresh PNG, DOM, ARIA, URLs, public inventory and machine results.
  These are focused fixture proofs, not the exhaustive final screenshot set.

The current explicit inventory contains 85 route/state requirements at three widths
(255 cells), including the independently recovered English closed opportunity and the
eight cells that name a record identity and public state: the identifier-free
publication, the alumni profile and the in-memoriam profile in both locales, and the
Portuguese upcoming and past events. This is a derived current count, not a ceiling.
Further records of an already required public state are individually listed in
route-crosscheck.json, not counted as captures; a record in a state no cell renders is
a gap, not a duplicate.
The retained sample-page/default fixtures and inherited publication-state metadata
inconsistencies are not silently repaired or promoted to launch-content approval.

## Final capture handoff

Reuse the recovered capture driver and controls machinery with this matrix. Run only
on the joined, quality-verified frozen build. Source/control evidence must be new.
The final validator is scripts/qa/task24/validate-evidence.mjs, adapted from attempt-5
without dropping its source/ARIA/DOM/control/geometry/media/PiP/freshness/PNG checks.

Set LPS_TASK24_CAPTURE_ROOT to the NEW capture directory. Provide:

- manifests/source-before.json using the inherited files/newestSource/hashedAt schema.
- manifests/fixture-before.json with files=[{mode,file,sha256}] for immutable fixture
  state snapshots. Hash the ordered mode:file:digest\n records into coverage.fixtureHash.
- Each source.fixtureHash must equal its mode snapshot hash. Keep existing dbBefore
  and dbAfter receipts as well; these do not replace the immutable fixture identity.
- fixtures/media/assets with the preserved 24a/24b bytes for actual download checks.
- coverage.json with every required cell and DOM-enumerated control group/state.

Invocation:

LPS_TASK24_CAPTURE_ROOT=<new-owned-capture-dir> node scripts/qa/task24/validate-evidence.mjs <coverage.json> <validation.json>

Missing English detail, Governance, viewport or required control state must reject.
The validator is not a visual oracle. Full 375/768/1280 screenshots, all control
states, native compositor PiP endpoint proof, source freshness, diff coverage and
independent design/text reviews remain task 24m/24n work.

## Scope and trust

Normal Polylang assignment/association APIs repair fixture pairs. The fixture-only
migration corrects the reviewed exact orthographic map and replaces only the known
fake image blocks with a truthful text-only missing-image figure; the working PDF,
video and captions are not regenerated. Every WordPress install default whose copy is
still WordPress's own is removed, with a typed fixture identity check shared with
`tests/fixtures/wp/lps-wordpress-default-content.php`: "Hello world!" and the English
"Sample Page" are deleted, and the privacy-policy suggested-text draft stays an
unpublished draft. A record whose copy or title was edited, or one wired into the site
structure as front page, posts page, privacy page or parent, is refused and reported in
the removal ledger rather than silently deleted. Existing other optional content is
reported, not silently removed. Source identity is a frozen QA copy, not a claim
that other concurrently edited lanes have been integrated or verified.
