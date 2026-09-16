# Plugin and update policy

Pinned versions are the executable truth in `package.json`, `package-lock.json`, `composer.json`,
`composer.lock` and `.nvmrc`. The rationale for each top-level choice is
[the supported runtime note](../architecture/runtime.md). This document is the procedure for changing
any of them.

## Prerequisites

- A clean checkout with `npm ci` and `tools/composer install` completing from the committed lockfiles.
- A staging environment to test the update before production (currently **PENDING**: the
  institution-managed staging host is not provisioned).
- The release build reproducible with `npm run build`.

## Production plugin allowlist

Exactly three plugins may be active in production:

| Plugin | Pinned version | Role |
| --- | --- | --- |
| `lps-content-model` | plugin header version `0.1.0`, content contract version `1.1.0` | Content model, roles, translation state, search index, import/export, redirects. |
| `polylang` | `3.8.7` | Locale association and routing only. |
| `two-factor` | `0.16.0` | MFA for publisher and administrator accounts. |

`wp-content/mu-plugins/lps-security.php` intersects both the ordinary and network activation lists
with exactly that set, and no other must-use plugin or drop-in is approved. Filtering activation is
not a malware scanner: an unknown plugin file must also fail the release inventory review. Adding a
plugin requires a plan amendment, an allowlist change, a privacy re-review and a full gate run.

No page builder, no paid field plugin, no page-cache plugin (caching is a host or CDN
responsibility), no analytics or form plugin.

## Pinned runtime

| Component | Pinned | Notes |
| --- | --- | --- |
| WordPress | `6.9.7` | Newest maintained release tested by every allowlisted plugin. WordPress 7.1 waits until Two-Factor declares compatibility. |
| PHP | `8.3` | Use the hosting distribution's current patched 8.3 package, not an unpatched upstream binary. |
| Database | MariaDB `10.11` LTS or MySQL 8.0+ | SQLite is local-test infrastructure only. |
| Node.js | `24.20.0` | LTS through 2028-04-30. |
| npm | `11.19.0` | Bundled with the pinned Node release. |
| `qs` override | `6.16.0` | Repairs transitive denial-of-service advisories while keeping the compatible 6.x API. |

## Update cadence

| Severity | Deadline |
| --- | --- |
| Exploited or critical | Triage and isolate immediately, `patch within 24 hours`. |
| High | `high within 72 hours`. |
| Everything else supported | Apply other supported updates monthly. |
| Advisory review | Review vendor advisories weekly. |

Composer and npm audit run in every release. No blanket scanner exclusion and no forced major upgrade.

## Procedure for any version change

1. Re-check the authoritative sources: the WordPress core version-check API, the plugin info API for
   each allowlisted plugin, the PHP support matrix and the Node release schedule.
2. Update the exact manifest versions and regenerate **both** lockfiles together.
3. If the WordPress or PHP baseline moves, update `docs/architecture/runtime.md` in the same change.
4. Install from clean dependency state: `npm ci` and `tools/composer install`.
5. Run the gates in this order and keep every log:
   - `npm run lint`
   - `npm run test`
   - `npm run build`
   - `tools/composer lint`
   - `tools/composer test`
   - `npm run qa:security`
   - `npm run qa:a11y`
   - `npm run test:e2e`
6. Test on staging with a clean install plus the capability, nonce, upload, XSS and REST tests, a
   browser MFA/network/storage audit, a passive scan, and a restore and rollback rehearsal.
7. Store the before/after lockfiles, checksums, the rollback artifact and the raw results under the
   evidence policy in [evidence retention](evidence-retention.md).
8. Verify the documentation still matches: `node tests/docs/docs-checker.mjs`. A renamed command or a
   moved source file is reported as the exact stale step.

## Rollback

Every update keeps its predecessor artifact. Application rollback is the `application-rollback`
objective in `docs/operations/recovery-objectives.json`; it is currently unapproved, which is a launch
blocker. Do not schedule a production update before that objective has an owner-approved RPO/RTO. The
procedure itself is in [the release runbook index](release-runbook-index.md).

## Known pre-existing conditions

- `tools/composer analyse` (PHPStan) requires the native PHP 8.3 runtime from `tools/setup-php-native`.
  The recorded 512M exhaustion belonged to the PHP.wasm fallback, which could not finish the level-max
  analysis at any limit; on the native runtime the full analysis completes inside the 1536M limit in
  `tools/php-native.ini` (measured 2026-09-13: 31 s wall clock, 727 MiB peak resident set). Do not
  raise memory limits as a workaround, and do not run the analysis repeatedly on a shared host.
- The staging host, backup encryption key, retention approval and RPO/RTO approvals are external
  preconditions and remain open. A dependency update can be prepared and tested locally, but its
  production application is blocked with them.
