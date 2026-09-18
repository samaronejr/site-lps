# Privacy and security operations

Operator-facing summary of how this site is run safely. The binding technical policy is
[the security and privacy policy](security-policy.md) together with the versioned edge fragments
`docs/operations/security-edge.conf`, `docs/operations/security-server.conf` and
`docs/operations/security-static-headers.conf`. This document tells an operator what to do and where
the boundary is; it does not restate the header-by-header configuration.

## Prerequisites

- Institution-managed hosting with an Nginx edge, cron under the runtime identity, and a secret store.
- `wp-config.php` provisioned outside the web root, mode 0640, readable only by the PHP service group.
- A database credential limited to SELECT, INSERT, UPDATE, DELETE on the one application database.
- The release artifacts from `npm run build` in `dist/`, including
  `dist/mu-plugins/lps-security.php`.

## Required production configuration flags

The must-use plugin returns HTTP 503 while any of these is absent, so the site cannot serve traffic
in a half-configured state:

| Flag | Required value |
| --- | --- |
| `WP_ENVIRONMENT_TYPE` | `production` |
| `WP_DEBUG` | `false` |
| `WP_DEBUG_DISPLAY` | `false` |
| `DISALLOW_FILE_EDIT` | `true` |
| `DISALLOW_FILE_MODS` | `true` |
| `LPS_EDGE_SECURITY_VERIFIED` | `true`, and only after the operator's approval is recorded and TLS, origin isolation, 429 throttling, headers, upload isolation, secrets/grants and the release allowlist have actually been tested |
| `LPS_TEACHING_STORAGE_ROOT` | Absolute path outside the web root, writable by the PHP service, provisioned by the host |
| `LPS_TEACHING_PUBLIC_ROOT` | Absolute path of the public web root, used to prove the storage root is outside it |
| `LPS_TEACHING_MAX_BYTES` | Optional per-file cap override in bytes; default 52428800 (50 MiB), hard ceiling 209715200 (200 MiB); technical administration only, never faculty-adjustable |
| `LPS_TEACHING_SCANNER_APPROVED` | `true`, and only after the institution approves the deployed scanner; the test-only adapter never satisfies this |

Never define `LPS_EDGE_SECURITY_VERIFIED` or `LPS_TEACHING_SCANNER_APPROVED` merely to make a
smoke test pass. Teaching-file publication and release fail closed while the storage root, its
non-public placement, or the scanner configuration is absent.

## Accounts and authentication

- Named individual accounts only; role-named or shared accounts are rejected at the account boundary.
- `publisher` and `administrator` need an actually enabled Two-Factor provider; TOTP is the
  recommended privileged provider and recovery codes stay offline and private.
- Missing MFA leaves read and profile access only — upload, deletion, user management, publishing and
  settings stay locked.
- XML-RPC and application passwords are disabled; public REST account enumeration is denied.
- A public Person record is never a login account.
- Remove account access on departure and run the dormant-account report (`dormant-report`).

## Data the site collects

Nothing, for anonymous visitors: no analytics, no forms, no chatbot, no social embed, no consent
banner, no public cookie, no `localStorage` or `sessionStorage`, and no third-party runtime request.
Polylang's language cookie is disabled because locale URLs are authoritative. Privileged login uses
only WordPress authentication, test and preference cookies.

The feature/vendor/data inventory that generates the public privacy notice in both locales is
`wp-content/plugins/lps-content-model/privacy-inventory.json`. Re-review it — and obtain a plan
amendment — before any future analytics, form, embed, vendor or data-processing change.

## Personal data requests

1. A takedown, privacy, rights or public-contact request goes to the publisher and the privacy
   auditor (see [editorial workflow](../content/editorial-workflow.md)).
2. They may restrict or unpublish the public item while evidence is reviewed.
3. An administrator performs only the necessary technical access or configuration action.
4. Provenance is never silently altered and a referenced record is never hard-deleted.
5. Legal bases, rights and consent require documentary evidence. This repository documents none, and
   `privacy-legal-bases` is an open launch blocker.

## Logs

- The Nginx log format uses `$uri`, never `$request` or `$request_uri`, so public search query
  strings — which are user-supplied personal data — never enter access logs.
- No bodies, cookies, authorization headers, MFA secrets or SQL payloads are logged.
- Routine access and security logs expire within `30 days`; an authorized incident hold is documented
  with owner, purpose and expiry.
- Debug logs stay private and outside the web root.

## Uploads

Allowed types are JPEG, PNG, WebP, AVIF, MP4, WebM, MP3, Ogg, PDF and WebVTT within the media policy
limits. Executable and double extensions, MIME/extension mismatches, bad image geometry and disguised
PDF/VTT payloads are rejected. Uploads are served non-executable with nosniff; PDF and VTT downloads
get attachment disposition and a sandbox CSP at the edge. No SVG, HTML or script upload is allowed.
Private documents never belong in public uploads.

Faculty teaching downloads never enter public uploads at all: `class-teachingstorage.php` quarantines
them outside the web root under opaque keys, inspects content (PDF actions, UTF-8 text, image
geometry, OOXML package safety, `.ipynb` schema — never executed), and releases only after a
`clean` verdict from the configured scanner. Pending, error and infected verdicts never clear.
Allowed teaching types: PDF, UTF-8 TXT/CSV, DOCX/PPTX/XLSX with package validation, PNG/JPEG/WebP,
and `.ipynb` delivered only as downloads. Macro-enabled Office, SVG, HTML, scripts, executables,
archives and arbitrary JSON are denied. Downloads are `attachment` + nosniff + no-store through
the authorized endpoint; records carry no public URL.

## Routine security cadence

| Interval | Task |
| --- | --- |
| Weekly | Review vendor advisories weekly and record the outcome. |
| Every release | Run Composer and npm audit; store before/after locks and checksums. |
| Immediately | Triage and isolate an exploited or critical vulnerability; `patch within 24 hours`. |
| Within 72 hours | Patch `high within 72 hours`. |
| Monthly | Apply other supported updates monthly on staging first. |
| Every 30 days of TLS stability | Re-evaluate HSTS eligibility before extending it. |

Details and the test matrix for updates are in [the plugin and update policy](plugin-update-policy.md).

## Verification commands

| Command | Proves |
| --- | --- |
| `npm run qa:security` | Passive scan of the served surface. |
| `npm run qa:infrastructure` | DNS, TLS, HTTP status and the capability checklist. |
| `tools/composer test` | Capability, nonce, sanitization, escaping, upload and REST-exposure contracts. |
| `npm run test` | JavaScript-side contracts, including the security and recovery fixtures. |
| `npx playwright test tests/e2e/todo20-privacy.spec.mjs` | Network, cookie and storage audit in a real browser. |

`tools/composer analyse` (PHPStan) is a known pre-existing failure: it exhausts memory at 512M on the
untouched importer. Do not raise the limit as a workaround; the condition is recorded, not hidden.

## Incidents

Isolate the affected surface first, then patch. Record the timeline, the affected records, the
operator actions and the evidence paths. If compatibility blocks a critical patch, isolate the
component rather than keeping an exposed unsupported version. Never suppress scanner output or
publish an exception trace.
