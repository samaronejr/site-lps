# Performance measurement method

This document records how the LPS site's performance numbers are produced, what environment they
were captured in, and which claims they support. It exists so a reported number can never be
mistaken for something it is not.

## 1. Laboratory versus field data

All numbers this repository produces are **laboratory** results: they are captured on demand, on a
controlled host, against a seeded fixture, with a fixed browser and a fixed network profile. They
describe what the site does under that setup, not what real visitors experience.

**Field** data — real-user percentiles such as CrUX p75 LCP or INP — requires a production origin
with real traffic and a consented measurement pipeline. The site ships no tracking and no
real-user-monitoring runtime, so field percentiles are **unavailable by design** and are reported
as unavailable, never estimated.

INP in particular cannot be measured in a laboratory run: it is an interaction metric over a real
visit. The laboratory proxy is **Total Blocking Time (TBT)**, reported as `INP_proxy_TBT` in
`scripts/perf/lighthouse-runs.mjs`. A report that labels TBT as field INP is a defect.

## 2. Environment

Every measurement record must carry the environment it was captured in:

- Host: local workstation or named CI runner, CPU and memory class.
- Runtime: WordPress Playground (PHP-WASM, single-process SQLite) or the staging host. Playground
  numbers are development signals only — WASM PHP is several times slower than native FPM and must
  never be quoted as production TTFB.
- WordPress: core version, active plugins, active theme revision (git commit).
- Content: which fixture set is loaded (seed profiles, teaching graph, media assets).
- Browser: engine and version, viewport, device scale factor.
- Network: the applied throttling profile (or "none" for local loopback).

## 3. Measurement method

- **Payload budgets**: `scripts/lib/performance-budget.mjs` compresses every shipped theme asset
  and compares category totals against the frozen budgets in `BUDGETS`. Enforced by
  `npm run test -- tests/js/lps-redesign/task-22.test.mjs`.
- **Rendered-document audit**: `auditDocument()` in the same module checks the served HTML for the
  single eager/high-priority LCP image, lazy loading elsewhere, intrinsic dimensions, and the
  absence of third-party subresources.
- **Query growth**: the test-only `tests/fixtures/wp/lps-perf-probe.php` mu-plugin appends
  `<!-- lps-queries:N -->` to rendered pages in the dedicated Playground. The e2e suite reads the
  marker before and after growing a resource list and asserts the delta stays bounded. The probe
  is never mounted outside test environments.
- **Timed visibility**: release state is evaluated at read time from `_lps_release_state` /
  `_lps_release_at` against the request clock (`TeachingContracts::effective_release_state`). No
  cron event, scheduled task, or visit traffic participates, so a stopped scheduler cannot strand
  or leak a release. The e2e suite proves this by scheduling a resource in the future, observing
  the denial, then moving the release instant into the past and observing service — with no
  scheduler running in between.
- **Cache behavior**: `CachePolicy` emits bounded TTLs (HTML 300 s, query 60 s, not-found 60 s)
  with `stale-while-revalidate`; lifecycle meta writes purge the affected URLs through
  `purge_on_lifecycle_meta`. Repeat-request checks in the e2e suite verify identical cache
  decisions and post-change content.
- **Lighthouse**: `scripts/perf/lighthouse-audit.py` captures real-Chrome medians per
  representative template; `scripts/perf/lighthouse-runs.mjs` records LCP, CLS and
  `INP_proxy_TBT`. These are laboratory medians.

## 4. Reporting rules

- Every reported metric names its environment and measurement method.
- Laboratory results and field percentiles are recorded in separate fields; a missing field
  measurement is reported as `unavailable`, not interpolated.
- TBT may be reported only as the INP laboratory proxy (`INP_proxy_TBT`).
- Playground timings may be reported only as development signals, never as production latency.
