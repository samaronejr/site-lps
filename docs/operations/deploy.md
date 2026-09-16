# Staging deployment runbook

Scope: provision, deploy, verify, and operate the production-equivalent staging
runtime for the LPS institutional website. This document describes the local
staging runtime built by `scripts/deploy/`; the institution-managed staging host
remains **owner-unconfirmed** (see `docs/operations/infrastructure-preflight.md`,
"staging" capability) and is not claimed here.

## Architecture

```
client ──TLS──> edge (node, :8443) ──HTTP──> origin (PHP.wasm WordPress, :8927)
        └─HTTP──> edge (:8080) ──301──> https://…:8443
```

- **Origin** — `@wp-playground/cli` serving the pinned WordPress + plugin +
  theme release over plain HTTP on port `8927`. The CLI has no bind-address
  flag, so it listens on all interfaces (`*:8927`); on a managed host the
  origin port must be firewalled to loopback so the edge is the only public
  surface. On this workstation it is reachable on the LAN.
- **Edge** — `scripts/deploy/edge.mjs`. Terminates TLS with a locally issued
  certificate, redirects plain HTTP to HTTPS, applies the security header set,
  rate-limits `wp-login.php`, denies static-sensitive paths, serves the
  full-page cache, and forwards misses to the origin.
- **Ops volume** — `<staging-root>/ops/` holds secrets, TLS material, logs,
  the page cache, the object cache, the purge queue, and run state. It lives
  outside the web root; nothing under `ops/` is served.

Ports are overridable: `LPS_EDGE_HTTPS_PORT` (8443), `LPS_EDGE_HTTP_PORT`
(8080), `LPS_ORIGIN_PORT` (8927), `LPS_SITE_URL`.

## Layout

```
<staging-root>/
├── releases/<id>/          # one provisioned release tree per deploy
│   ├── wordpress/          # docroot: WP + pinned plugin/theme + drop-ins
│   └── release.json        # manifest: tree hash, file count, artifact pins
├── current -> releases/<id>/wordpress
├── previous -> releases/<id>/wordpress
└── ops/
    ├── secrets/secrets.env # 0600; deploy halts when absent/unreadable
    ├── tls/                # edge-cert.pem / edge-key.pem (local CA)
    ├── logs/               # access.jsonl app.jsonl php-error.log
    │                       # php-error-early.log cron.log monitor.log
    │                       # monitor-checks.jsonl alerts.jsonl
    │                       # mail-outbox.jsonl purge-queue.jsonl
    ├── cache/pages/        # edge full-page cache (body + meta per key)
    ├── cache/object/       # WP object-cache drop-in backing store
    ├── run/                # pid files, maintenance flag, fault flag
    ├── probes/             # adversarial fixtures mounted at /lps-probes
    └── reports/            # import reports mounted at /lps-report
```

The staging root defaults to `.omo/evidence/task-27/staging` and is set with
`LPS_STAGING_ROOT` or `--root=`.

## Secrets

`ops/secrets/secrets.env` is the only secret store. Create it from
`scripts/deploy/secrets.env.template` or run `secrets-init` to generate one
with random values. Required keys:

| Key | Purpose |
| --- | --- |
| `LPS_PURGE_TOKEN` | Bearer token on `POST /lps-ops/purge` |
| `LPS_ALERT_SINK` | Absolute path (or webhook URL on a managed host) the monitor appends alert records to |
| `LPS_ADMIN_PASSWORD` | Optional; generated and written back when empty |

Rules: mode `0600`; never committed; never reused from production; the deploy
halts at `secrets-check` when the file is missing, unreadable, or a required
key is empty. Every receipt redacts secret values before it is written.

## Commands

All commands run from the repository root and take `--root=` or
`LPS_STAGING_ROOT`.

| Command | Effect |
| --- | --- |
| `secrets-init` | Create `secrets.env` with random values if absent |
| `secrets-check` | Validate the secrets file; exit 1 on any problem |
| `provision --release=<id>` | Build a release tree from `dist/` + pinned artifacts, run migrations + corpus import + boot-check under the production gate |
| `boot-check --release=<id>` | Re-run the production-gate probe against a provisioned release |
| `deploy --release=<id>` | secrets-check → provision (if needed) → boot-check → flip `current` → restart → flush cache → verify; auto-rollback on failure |
| `rollback --to=<id>` | Flip `current` back to a prior release and restart |
| `serve` / `stop` / `status` | Start/stop origin+edge; report health, pids, flags. `stop` kills the real port listener even when the pid file is stale; `serve` restarts the origin and proves the new listener serves the flipped release via `/wp-json/lps-ops/v1/health` |
| `wp <args>` | Run WP-CLI inside the current release (e.g. `wp eval "…"`) |
| `cron install` / `remove` / `status` / `run` | Manage the system crontab block; `run` is the per-minute wrapper |
| `monitor` | Run one monitor pass: health, public page, php-error scan, cron freshness; append alerts to `LPS_ALERT_SINK` |
| `maintenance on` / `off` | Toggle the maintenance flag; edge serves 503 + `Retry-After` |
| `fault on` / `off` | Inject an origin fault for adversarial probes |
| `verify` | Run the full verification battery against the live edge |

`deploy --package=<path>` overrides the import package (used by the
failed-import adversarial probe).

## Deploy flow

1. `secrets-check` — halt before any mutation when secrets are absent.
2. `provision` — assemble `releases/<id>/` from `dist/` (theme/plugin build
   output) plus pinned WordPress, `wp-config.php`, `object-cache.php`, and the
   `lps-ops` mu-plugin. Record `release.json` with the tree hash.
3. `boot-check` — boot the release under the production gate
   (`WP_ENVIRONMENT_TYPE=production`, `WP_DEBUG=false`,
   `DISALLOW_FILE_EDIT/MODS=true`, `LPS_EDGE_SECURITY_VERIFIED=true`), run
   `lps migrate`, apply + verify the launch corpus, set the admin password.
4. Flip `current`, restart origin + edge, flush the page cache, leave
   maintenance mode.
5. `verify` — the battery below. Any failure auto-rolls back to `previous`.

Releases built from the same clean `dist/` are byte-identical (same tree
hash), so a deploy is reproducible and drift-free.

## Verification battery

`verify` checks, in order: TLS health endpoint; security headers; page-cache
HIT on second fetch; query-cache behaviour; unapproved-query bypass;
private-path bypass; purge API (denied without token, allowed with); purge
queue (publish-driven); static denials (`.env`, `xmlrpc.php`,
`wp-json/wp/v2/users`, uploaded `.php`, `readme.html`); login throttle (429
within the burst); redirect graph (410/301/200/sitemap/robots); cron
freshness; secrets-scan of every receipt. Exit 0 only when all pass.

## Cache

- **Page cache** — edge, file-backed under `ops/cache/pages/`. Honors the
  origin `Cache-Control` (`s-maxage` for public HTML, shorter for queries and
  404s, `private,no-store` for authenticated or unapproved-query responses).
  Stale-while-revalidate serves one stale entry while revalidating.
- **Object cache** — `object-cache.php` drop-in, file-backed under
  `ops/cache/object/`. Persistent across requests within a release.
- **Purge** — `POST /lps-ops/purge` with `Authorization: Bearer
  $LPS_PURGE_TOKEN` and `{"all":true}` or `{"paths":[…]}`. Publish transitions
  also enqueue purge targets through `ops/logs/purge-queue.jsonl`, which the
  edge watches. A deploy flushes the whole cache.

## Cron

`cron install` writes a `# LPS-STAGING-CRON-BEGIN/END` block into the user
crontab with two per-minute entries: `staging.mjs cron run` (executes
`wp cron event run --due-now` inside the current release) and
`staging.mjs monitor`. The `lps-ops` mu-plugin registers an
`lps_ops_heartbeat` event on a one-minute schedule so scheduled work is
observable in `cron.log` and via the `lps_ops_heartbeat` option. The monitor
alerts when `cron.log` is stale beyond `LPS_CRON_MAX_AGE_SEC` (default 180s).

## Logs and monitoring

| Log | Content |
| --- | --- |
| `access.jsonl` | edge access log, one JSON record per request |
| `app.jsonl` | application events (purge enqueues, heartbeat, mail) |
| `php-error.log` | PHP errors after `plugins_loaded` (ops `error_log`) |
| `php-error-early.log` | early-boot PHP errors (`WP_DEBUG_LOG` path) |
| `cron.log` | per-minute `wp cron event run` receipts |
| `monitor-checks.jsonl` | one record per monitor pass |
| `alerts.jsonl` | alert records appended by the monitor |
| `mail-outbox.jsonl` | captured `wp_mail` (PHP.wasm has no MTA) |
| `purge-queue.jsonl` | publish-driven purge targets |

The monitor exits 0 when all checks pass, 1 when any check fails (alerts
written), and 2 when the alert sink itself is unwritable — a broken alert
route is never silent.

## Maintenance mode

`maintenance on` touches `ops/run/maintenance`; the edge answers every request
with `503` + `Retry-After` + the maintenance page until `maintenance off`.
Deploys enter maintenance for the flip/restart window only and leave it before
verification.

## Adversarial probes (all executed)

| Probe | Expected | Observed |
| --- | --- | --- |
| Missing `secrets.env` | deploy halts at `secrets-check`, prior release stays live | exit 1, `current` unchanged, health 200 |
| Corrupt migration file | deploy halts at `boot-check`, prior release stays live | exit 1, `current` unchanged, health 200 |
| Duplicate record id in import package | deploy halts at `boot-check` (`import-apply` exit 1) | exit 1, `current` unchanged, health 200 |
| Stale + corrupt page-cache entries | deploy flushes; stale body never served; corrupt meta treated as miss | deploy exit 0, no stale body, no crash |
| Broken cron (stale `cron.log`) | monitor alerts `cron-freshness` | exit 1, alert appended |
| Unwritable `LPS_ALERT_SINK` | monitor halts loudly | exit 2 |

## Known gaps (not staging defects)

- The launch corpus does not contain every fixture route the a11y inventory
  expects (`/pt-br/busca/`, `/pt-br/contato/`, fixture people/projects/etc.),
  so `qa:a11y` reports `lps_a11y_route_status_unexpected` 404s and the
  fixture-bound keyboard journeys are absent. These are content gaps in the
  corpus, not runtime faults; axe and pa11y report zero violations on every
  captured staging page.
- `docs-checker` flags `data-dictionary.md` and `design-system-guide.md` as
  stale against uncommitted source changes that predate this task.
- `biome check` reports pre-existing format errors in `scripts/qa/task24/`,
  `scripts/todo10-selector-preflight.mjs`, and `tests/e2e/todo1{0,20}-*.mjs`.
