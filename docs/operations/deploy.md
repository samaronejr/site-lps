# Deploy runbook (artifact-based, local rehearsal verified)

This runbook covers deploying a versioned release to a target docroot and
rolling it back. The local rehearsal contract is implemented and verified;
the hosted staging/production steps remain **PENDING** on the
institution-managed host (see the
[release runbook index](release-runbook-index.md)).

## Components

| path | purpose |
| --- | --- |
| `scripts/build.mjs` | Produces `dist/lps-content-model`, `dist/lps-theme`, `dist/mu-plugins` and `dist/manifest.json`. |
| `scripts/deploy/local-rehearsal.mjs` | Local rehearsal driver: `run` executes the full deploy/backup/restore/rollback cycle against a disposable WordPress Playground target; `stop` halts it. |
| `tests/js/lps-redesign/task-23.test.mjs` | Dry-run contract test for every operations tool the rehearsal invokes. |
| `tests/e2e/lps-redesign/task-23.spec.mjs` | Executes the rehearsal end-to-end and asserts on the report and live HTTP surface. |

## Release shape

A release is a directory laid out as docroot-relative paths:

```
release/
  wp-content/plugins/lps-content-model/   # from dist/lps-content-model
  wp-content/themes/lps-theme/            # from dist/lps-theme
  wp-content/mu-plugins/                  # from dist/mu-plugins
  release-manifest.json                   # releaseId, source revision, build time
```

Releases are versioned in a git repository (`<env>/releases/` in the local
rehearsal; an artifact store on a real host). `CURRENT` names the release that
is deployed. Rollback is `git revert` of the release commit followed by a
redeploy — history is never rewritten and no destructive downgrade runs.

## Deploy contract

1. Read the previous deploy's file manifest (`.lps-deployed-files` beside the
   docroot). Remove deployed files the new release no longer ships.
2. `rsync -a <release>/ <docroot>/` — never `rsync --delete` at docroot level:
   the docroot also holds WordPress core, uploads and the database, which are
   not release artifacts.
3. Write the new deployed-file manifest.
4. Verify over HTTP: `GET /release-manifest.json` reports the deployed
   `releaseId`; `GET /wp-json/` and the locale root return 200.

## Local rehearsal

```bash
npm run build
node scripts/deploy/local-rehearsal.mjs run \
  --env-dir=/tmp/lps-t23-env \
  --port=8906 \
  --confirm-local-rehearsal
```

The driver refuses to run without `--confirm-local-rehearsal`, requires
`--env-dir` inside the system temp directory, and has no code path that
accepts a remote host. It provisions a persistent docroot (WordPress core,
platform plugins, fixture seeds and media), deploys release `r1`, takes a
consistent backup (SQLite `VACUUM INTO` snapshot, uploads tar archive,
per-file SHA-256 manifest, content manifest including offering
relationships), deploys release `r2`, mutates content through the real REST
boundary, restores the backup and verifies record/byte integrity, then rolls
back to `r1` via `git revert` and verifies the prior served version and that
the additive plugin schema survived.

The report lands at `<env-dir>/rehearsal-report.json` with the real exit code
of every step; the process exit code is 0 only when every step passed.

## Hosted staging (PENDING)

The same contract applies to the authorized staging target once it exists:
the release directory is rsynced to the staging docroot, the backup is the
host's database dump plus asset archive, and rollback redeploys the previous
release. Executing any of that requires the institution-managed host,
deployment access and the named owner approvals recorded in
[recovery-objectives.json](recovery-objectives.json) — none of which exist
yet, so hosted deploy/restore stays **BLOCKED**, not assumed.
