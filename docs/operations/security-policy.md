# Security and privacy operations (task 20)

Policy date: 2026-09-06. Production remains blocked pending the institutional infrastructure,
privacy-contact, legal-basis and content-owner approvals in the plan. Local evidence is not an
assertion that production DNS, TLS, operators, database grants or retention have been provisioned.

## Mandatory release boundary

Deploy `dist/lps-content-model` to `wp-content/plugins/lps-content-model`, `dist/lps-theme` to
`wp-content/themes/lps-theme`, and `dist/mu-plugins/lps-security.php` to `wp-content/mu-plugins`.
The MU plugin loads before normal plugins and intersects both ordinary and network activation
lists with exactly LPS Content Model, Polylang 3.8.7 and Two-Factor 0.16.0. Unknown plugin files
must also fail the release inventory review; filtering activation is not a malware scanner.
No other MU plugins or drop-ins are approved in production. SQLite is local-test infrastructure
only. Keep the database, repository, development fixtures, logs, tests, lockfiles, credentials and
evidence archives outside the web root. Build excludes test trees and the existing seed debug file.
Deny direct web access to PHP includes, tests, hidden files and debug/log files at the edge.

WordPress 6.9.7 remains a maintained security branch (core API checked 2026-09-06); Two-Factor
0.16.0 does not yet declare WordPress 7.1 compatibility. Both approved vendor plugins remain the
current stable versions. PHP 8.3 receives security fixes; use the hosting distribution's current
patched package, not an unpatched upstream 8.3.6 binary. MariaDB 10.11 LTS is the production
recommendation. Exact Composer/npm versions and lockfiles are mandatory. The qs 6.16.0 override
repairs transitive denial-of-service advisories while preserving its compatible 6.x API.

## Authentication and authorization

Named individual accounts only. Publishers and both native/custom administrators require an
actually configured, enabled Two-Factor provider, not a nonempty metadata array. Missing providers
leave only read/profile access; upload, deletion, user management, publishing and settings remain
locked. TOTP is the recommended privileged provider; recovery codes remain offline and private.
WordPress/Two-Factor owns challenge verification and session issuance; never invent a parallel
login endpoint. Unused XML-RPC methods and application passwords are disabled. Public REST account
enumeration is denied. Public Person records are not WordPress login accounts.

Core REST cookie authentication requires the WordPress REST nonce. Custom admin handlers check
both their own action nonce and the target-object capability before typed sanitization. Output is
escaped in its final HTML/attribute/URL context. Keep normal typed WordPress errors; do not replace
errors with success, suppress scanner output or publish exception traces. Search storage uses
wpdb prepared identifiers/values; no request input is concatenated into SQL.

## Edge throttling and headers

Use the institution-managed Nginx edge, not an unapproved plugin or SaaS. The versioned
`security-edge.conf` fragment defines per-network-address throttling for all login requests,
including Two-Factor challenges, with five requests/minute and a burst of twenty; over-limit
requests return 429. Set a trusted real-IP allowlist at the infrastructure layer only if there
is another proxy. Never trust arbitrary X-Forwarded-For headers. Firewall the origin so clients
cannot bypass the limiter. The local verification uses actual Nginx; institutional approval and
production bypass testing remain deployment prerequisites.

Application CSP: default/object/base/frame-ancestor deny; scripts self plus per-response nonces
on WordPress-generated tags; no unsafe-eval, script event handlers, remote connections, fonts,
frames or media. Public frame-src is none; CMS frame-src is self for the block editor. Inline
styles are explicitly permitted because WordPress global/block styles require them. This does
not permit inline scripts. Nonces are never added to stored editor HTML. Keep full-page caches
from mixing headers and bodies or reusing nonces; authenticated pages are never shared-cacheable.

Every application response also has nosniff, DENY framing, no-referrer, disabled camera/microphone/
geolocation/payment/USB/topics and no cross-domain policy. HTTPS adds HSTS max-age=31536000;
do not add includeSubDomains/preload before all institution-owned subdomains are approved.
Apply equivalent headers to static assets and edge errors. WordPress re-sends its own weaker
frame/referrer headers on the credential and editor surfaces after `init`, so the MU plugin also
re-sends the policy on `login_init` and `admin_init` at the last priority; verify wp-login.php
carries the nonce CSP and DENY framing after any core update. The edge additionally returns 403
for the core metadata paths readme.html, license.txt and wp-admin/install|setup-config|upgrade.php,
and the response omits the WordPress generator version. No browser third-party runtime request,
public cookie, localStorage or sessionStorage is approved. Polylang's language cookie is disabled;
locale URLs are authoritative. Avatars, emoji remote assets, oEmbed discovery and DNS hints are off.

## Uploads

Allow only JPEG, PNG, WebP, AVIF, MP4, WebM, MP3, Ogg, PDF and WebVTT within MediaPolicy limits.
Reject executable/double extensions, MIME/extension mismatch, zero/oversize files, bad image
geometry, disguised PDF/VTT and active PDF action/script/attachment names, including hex-escaped
names. These signature checks are not a complete PDF parser or antivirus. PDFs additionally need
rights/privacy/accessibility review and an HTML equivalent before public use. Serve uploads
non-executable with nosniff; PDF/VTT downloads receive attachment disposition and a sandbox CSP at
the edge, containing active content even if hidden in compressed PDF objects. No SVG/HTML/script
uploads. Sideloads pass through the same boundary. Private documents never belong in public uploads.

Faculty teaching downloads are a separate lane from controlled brand/media assets.
`wp-content/plugins/lps-content-model/includes/class-teachingstorage.php` stores them
outside the public root under opaque `lps-file-*` keys: files land in `quarantine/`,
pass extension/MIME/content inspection (PDF action names, UTF-8 text, image geometry,
OOXML package structure with macro/ActiveX/OLE/embedded-executable denial, `.ipynb`
schema validation with active-output denial — notebooks are never executed), then the
configured scanner. Only a `clean` verdict moves a record to `cleared/`; `pending`,
`error`, adapter exceptions and invalid verdicts return to quarantine and `infected`
fails. Records never carry a public URL; the authorized download endpoint re-checks
state on every request and serves `attachment` disposition with nosniff and no-store.
The default cap is 50 MiB, adjustable only by technical administrators within the
200 MiB ceiling. Missing storage root, a root inside the public tree, a missing
scanner or an unapproved scanner in production all fail closed — the bundled
`lps-test-only-scanner` adapter is test-only and can never satisfy production.

## Secrets and least privilege

Provision wp-config.php outside the web root from an institution-controlled secret store, mode
0640 readable only by the PHP service group. Require independent high-entropy WordPress salts and
a database credential bound to the one application database and origin host. Runtime SQL grants:
SELECT, INSERT, UPDATE, DELETE only; no FILE, GRANT, SUPER, global privileges or DDL. A separate,
short-lived deploy credential performs reviewed versioned migrations and is removed afterward.
The runtime must have read-only core/plugin/theme/MU files and write access only to uploads and
an explicitly private temporary/cache directory. It cannot install/update/edit code from wp-admin.
Run cron under the runtime identity; updates/imports that need external metadata are reviewed CLI
maintenance, not web requests. Deployer gets no editorial account or reusable publishing secret.

Before enabling production set WP_ENVIRONMENT_TYPE=production, WP_DEBUG=false,
WP_DEBUG_DISPLAY=false, DISALLOW_FILE_EDIT=true and DISALLOW_FILE_MODS=true. Set
LPS_EDGE_SECURITY_VERIFIED=true only after recording the operator's approval and testing TLS,
origin isolation, 429 throttling, headers, upload isolation, secrets/grants and release allowlist.
The MU plugin returns 503 while these required configuration flags are absent. Never define the
verification flag merely to make a smoke test pass. Debug logs remain private with no credentials,
MFA secrets, cookies, authorization headers, SQL payloads or request bodies.

## Inventory, retention, updates and incidents

The shipped `privacy-inventory.json` is the feature/vendor/data inventory and the source of both
public notice locales. Host/operator and privacy-contact identities are not fabricated. The
institution must approve the legal bases, contact and corpus-specific retention before launch.
Anonymous pages use no storage; privileged login uses only WordPress authentication/test/preference
cookies. Public search query strings are user-supplied data and must not enter access logs. The
Nginx log format uses $uri, not $request or $request_uri; no bodies, cookies or query strings.
Routine access/security logs expire within 30 days; authorized incident holds are documented with
owner, purpose and expiry. Account access is removed on departure; revisions/audits and backups
follow the institution-approved records schedule and restricted restore access.

Review vendor advisories weekly and run Composer/npm audit in every release. Triage exploited or
critical vulnerabilities immediately, isolate affected surfaces immediately, patch within 24 hours;
high within 72 hours; other supported updates monthly. Test pinned updates on staging: clean installs,
capability/nonce/upload/XSS/REST tests, PHPStan at 512M, lint, build, browser MFA/network/storage audit,
passive scan, restore and rollback. No blanket scanner exclusions or forced major upgrades. Store
before/after locks, checksums, rollback artifact and raw results. If compatibility blocks a critical
patch, isolate rather than retaining an exposed unsupported component. Re-review inventory/notice
and obtain a plan amendment before any future analytics, form, embed, vendor or data-processing change.
