# Supported runtime

The development baseline is pinned rather than floated. Lockfiles are the executable dependency
source of truth; this document records why the top-level runtime choices were made.

| Component | Supported version | Basis checked 2026-08-30 |
| --- | --- | --- |
| WordPress | 6.9.7 | Core Version Check API lists 6.9.7 as an autoupdate release; it is the newest maintained release tested by every pinned production plugin. |
| PHP | 8.3.x (Composer platform 8.3.6) | WordPress supports PHP 7.4+, and PHP 8.3 is maintained in the Ubuntu 24.04 development-host matrix. |
| MariaDB / MySQL | MariaDB 10.11+ or MySQL 8.0+ | Current WordPress requirements page. Playground uses SQLite for local development only. |
| Node.js | 24.20.0 LTS | Node's release schedule marks v24 Krypton as LTS through 2028-04-30. |
| npm | 11.19.0 | Bundled with the pinned Node.js release. |
| Polylang | 3.8.7 | Plugin API: requires WordPress 6.5/PHP 7.4; tested through WordPress 7.1. |
| Two-Factor | 0.16.0 | Plugin API: requires WordPress 6.8/PHP 7.2; tested through WordPress 6.9.7. |

WordPress 6.9.7 is intentional: WordPress 7.1 is current, but Two-Factor 0.16.0 does not yet claim
7.1 compatibility. Upgrade only after the complete allowlist publishes compatible stable releases
and the integration suite passes.

## Local runtime

`npm run env:start` launches `@wordpress/env` 11.14.0 with WordPress Playground. It provides an
actual HTTP WordPress surface without Docker at `http://127.0.0.1:8888`; the test instance uses port
8889. `npm run env:stop` performs deterministic cleanup. Docker remains an upstream `wp-env` option,
but is not required by this repository.

## Upgrade policy

Update exact manifest versions and regenerate both lockfiles together. Re-check the core API,
plugin compatibility fields, PHP support matrix, and Node schedule, then run every Composer, npm,
E2E, and QA gate from clean dependency installs.

Authoritative sources:

- https://api.wordpress.org/core/version-check/1.7/
- https://wordpress.org/about/requirements/
- https://api.wordpress.org/plugins/info/1.2/
- https://github.com/nodejs/Release/blob/main/schedule.json
- https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/

