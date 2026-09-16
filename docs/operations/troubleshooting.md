# Troubleshooting

Symptom-first. Every remedy is a command that exists in this repository. If a remedy is not listed
here, do not improvise on a live environment — record the symptom and escalate to the role that owns
the surface.

## Prerequisites

- Dependencies installed from the lockfiles: `npm ci` and `tools/composer install`.
- For anything that renders: the local runtime started with `npm run env:start`.

## Environment

**`npm run env:start` fails or the site does not answer.**
The local runtime is `@wordpress/env` with WordPress Playground; the site is served at
`http://127.0.0.1:8888` and the test instance at port 8889. Stop it with `npm run env:stop`, then
start it again. If the database is inconsistent, `npm run env:clean` resets it — this destroys local
content, so never run it against anything you need.

**Node or npm version mismatch.**
`.nvmrc` and the `engines` block in `package.json` pin Node `24.20.0` and npm `11.19.0`. Use those
exact versions; a different major will produce lockfile churn that the gates reject.

**`php: command not found`.**
`php` is not required on `PATH`. Use the wrapper `tools/composer` for every PHP command
(`tools/composer install`, `tools/composer lint`, `tools/composer test`).

**`tools/composer analyse` runs out of memory.**
That was the PHP.wasm fallback runtime, whose allocator could not finish the level-max analysis at
512M, 1G or 1536M. Provision the native runtime once with `tools/setup-php-native`; `tools/composer`
then runs PHPStan through `tools/php` under the 1536M limit in `tools/php-native.ini`, and the full
analysis completes (measured 2026-09-13: 31 s wall clock, 727 MiB peak resident set). Do not raise the
memory limit to work around the wasm ceiling, and do not run the analysis repeatedly on a shared host.

## Gates

**`npm run lint` reports findings in files you did not touch.**
Biome runs over the whole repository. Confirm the finding is pre-existing by running the same command
on a clean checkout of the file, record it as pre-existing, and do not reformat unrelated files to
make the output quiet.

**`npm run test` fails in one suite.**
Run only that file: `npm run test -- tests/js/<suite>.test.mjs`. The suites are contract tests over
pure modules, so a failure is a real behavioural difference, not flakiness.

**`npm run test:e2e` cannot connect.**
Playwright needs a running site. Start the runtime first, then run
`npx playwright test --list` to confirm discovery before running the matrix.

**`node tests/docs/docs-checker.mjs` fails.**
Read the finding rule:

| Rule | Meaning | Fix |
| --- | --- | --- |
| `broken-relative-link` | A document links to a path that does not exist | Correct the link or restore the target |
| `missing-referenced-file` | A backticked repository path does not exist | Correct the path; do not remove the reference to silence it |
| `unknown-command` | A documented command is not in `package.json` scripts, `composer.json` scripts, the plugin WP-CLI surface, or a real node entry point | Document the command that exists now; the finding names the exact step |
| `missing-prerequisite` | A required prerequisite disappeared from a document's `## Prerequisites` section | Restore the prerequisite |
| `capability-claim-mismatch` | A role table claims an action that `SecurityPolicy` denies, or omits one it grants | Correct the document, or change the policy deliberately and re-run the PHP suites |
| `stale-documentation` | A source file described by a document changed | Re-read the source, correct the document, then update the hash in `docs/documentation-contract.json` |
| `expired-documentation` | A documented review is older than its maximum age | Re-review and record the new date |
| `undocumented-item` | A setting, collection, role, recurring task, update path or recovery action is in no document | Document it |
| `unverifiable-coverage-item` | A coverage item's declared source does not contain it | The item is fabricated or moved; fix the contract against the real source |

## Content and QA lanes

**`npm run qa:governance` fails.**
A collection lost its owner role, cadence, translation obligation, archive rule or correction path in
`tests/fixtures/governance/role-collection-matrix.json`. Restore the missing element; do not delete
the collection.

**`npm run qa:content` fails.**
The corpus in `content/corpus/` no longer reconciles with `content/inventory/`. Typical causes: a
placeholder, a missing source, an unsupported claim, a missing required locale pair, or a
rights-unknown public asset. The failing record ids are in the report; the affected records stay
draft.

**`npm run qa:inventory` fails.**
A duplicate normalized URL, a missing provenance field or a rights-unknown public asset entered
`content/inventory/`. Fix the row, keeping provenance for both sources.

**`npm run qa:links` fails.**
An internal link or identifier no longer resolves. Fix the link or add the redirect; then re-run
`wp lps redirects verify`.

**`npm run qa:ia` fails.**
A route, label, facet or vocabulary term violates the frozen architecture: a translated stable key, a
third route level, a duplicate locale destination, a free tag, or a term without an authoritative LPS
source. Correct the route fixture `tests/fixtures/ia/routes.json`.

**`npm run qa:a11y` fails.**
The finding names the route and selector. Repair the content or the media record; see
[the accessibility authoring checklist](../handbook/accessibility-authoring-checklist.md).

**`npm run qa:design-system` fails.**
An unapproved token, radius, shadow, font or motion value entered a template. Use a token from
`wp-content/themes/lps-theme/theme.json`; extend `DESIGN.md` first if the value is genuinely new.

## Editorial symptoms

**A record will not publish.**
A publish gate is unresolved: missing source, rights, privacy review, accessibility requirement,
translation review, or a required relationship such as a project lead
(`lps_project_lead_required`). The error names the field. The record stays draft — that is the design.

**An English page will not publish, or shows as stale.**
The authoritative Portuguese source changed after the last translation review
(`_lps_source_hash` differs from `_lps_reviewed_source_hash`). An independent reviewer must clear the
translation. See [translation and freshness](../handbook/translation-freshness.md).

**A user cannot see a collection.**
Collection assignment is missing on the account (`_lps_assigned_collections`); contributors,
translators and section editors only see assigned collections. An administrator assigns it.

**A publisher or administrator has no publish or settings capability.**
No Two-Factor provider is enabled for that account. Enrol MFA; the capability strip is intentional.

**An old URL 404s.**
Add or repair the redirect record and run `wp lps redirects verify`. A published slug is immutable;
slug changes must leave a one-hop redirect.

## Import symptoms

**Records are quarantined.**
Read the blocker string: `duplicate_source_id`, `redirect_loop`, `changed_checksum`,
`unavailable_asset`, `ambiguous_author`, `unresolved_author` or `invalid_locale`. Fix the source data
or the importer and re-run from `wp lps import plan`. Never hand-edit the target to clear a
quarantine — see [the publication and import guide](../handbook/publication-import-guide.md).

**A second `wp lps import apply` writes records.**
Idempotency is broken. Stop and compare the plan output of both runs; the disposition of every
unchanged record must be `unchanged`. This is an importer defect, not a data-entry problem.

## Operations symptoms

**`node scripts/backup/recovery.mjs plan` exits 1.**
Expected today: the encryption recipient key, the owner assignee and the retention approval are all
missing. These are launch blockers, not tool failures. See
[the release runbook index](release-runbook-index.md).

**`node scripts/cutover/dns-tls.mjs check` fails.**
Expected today: the current live-risk fixture reflects the recorded root timeout and expired `www`
certificate. Re-run only after the infrastructure owner reports a change, then re-capture
`npm run qa:infrastructure`.

**The site returns 503 after deployment.**
A required production configuration flag is absent; the must-use plugin refuses to serve a
half-configured site. Check the flag table in
[privacy and security operations](privacy-security-operations.md).
