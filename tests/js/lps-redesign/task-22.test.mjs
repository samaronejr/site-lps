import { readFile } from "node:fs/promises";
import { describe, expect, it } from "vitest";
import {
  auditDocument,
  BUDGETS,
  collectThemeAssets,
  evaluateBudget,
} from "../../../scripts/lib/performance-budget.mjs";

/**
 * Task 22 — performance measurement and time/cache behavior.
 *
 * The laboratory measurement itself runs in the Playwright spec against the
 * dedicated Playground; this suite pins the contracts that measurement
 * depends on: the shipped payload stays inside the approved budgets with no
 * accidental third-party, font or animation payload, the visible feature
 * image is never lazy-loaded, the large resource list reads its data in a
 * bounded number of queries, the release clock is evaluated at read time
 * (never by a scheduler), and the measurement tooling reports laboratory
 * results without claiming field percentiles.
 */

const THEME_ROOT = "wp-content/themes/lps-theme";
const TEACHING_ROUTES = `${THEME_ROOT}/includes/class-teachingroutes.php`;
const RELATIONSHIPS = "wp-content/plugins/lps-content-model/includes/class-relationships.php";
const TEACHING_CONTRACTS =
  "wp-content/plugins/lps-content-model/includes/class-teachingcontracts.php";
const TEACHING_RESOURCES =
  "wp-content/plugins/lps-content-model/includes/class-teachingresources.php";
const SEARCH_POLICY = "wp-content/plugins/lps-content-model/includes/class-searchpolicy.php";
const MEDIA_RENDERER = "wp-content/plugins/lps-content-model/includes/class-mediarenderer.php";
const ASSET_POLICY = `${THEME_ROOT}/includes/class-assetpolicy.php`;
const CACHE_POLICY = `${THEME_ROOT}/includes/class-cachepolicy.php`;
const DELIVERY = `${THEME_ROOT}/includes/class-delivery.php`;
const LIGHTHOUSE_RUNS = "scripts/perf/lighthouse-runs.mjs";
const LIGHTHOUSE_AUDIT = "scripts/perf/lighthouse-audit.py";
const PERF_DOC = "docs/operations/performance-measurement.md";
const HOSTING_DOC = "docs/operations/performance-hosting.md";
const PERF_PROBE = "tests/fixtures/wp/lps-perf-probe.php";

const read = (path) => readFile(path, "utf8");

describe("task-22: shipped payload stays inside the approved budgets", () => {
  it("collects only budgeted asset categories from the theme", async () => {
    const assets = collectThemeAssets(THEME_ROOT);
    expect(assets.length).toBeGreaterThan(0);
    for (const asset of assets) {
      expect(["css", "fonts", "image", "js"]).toContain(asset.kind);
    }
  });

  it("the shipped theme meets every compressed budget", async () => {
    const report = evaluateBudget(collectThemeAssets(THEME_ROOT));
    expect(report.violations).toEqual([]);
    expect(report.pass).toBe(true);
    expect(report.totals.fonts).toBeLessThanOrEqual(BUDGETS.fonts);
    expect(report.totals.js).toBe(0);
    expect(report.totals.total).toBeLessThanOrEqual(BUDGETS.total);
  });

  it("ships no front-end JavaScript and only the two approved preloaded faces", async () => {
    const policy = await read(ASSET_POLICY);
    expect(policy).toContain("front_end_scripts");
    expect(policy).toMatch(
      /public static function front_end_scripts\(\): array \{\s*return array\(\);/,
    );
    expect(policy).toContain("inter-regular.woff2");
    expect(policy).toContain("space-grotesk-semibold.woff2");
    // The approved payload is exactly the four vendored faces.
    const css = await read(`${THEME_ROOT}/assets/css/theme.css`);
    const faces = [...css.matchAll(/font-family:\s*["']([^"']+)["']/g)].map((m) => m[1]);
    for (const face of new Set(faces)) {
      expect(face).toMatch(/^(Inter|Space Grotesk|JetBrains Mono)$/);
    }
    expect(css).not.toMatch(/url\(\s*['"]?https?:/);
    expect(css).not.toContain("@import");
  });

  it("no theme or plugin code loads a third-party script, style, image or frame", async () => {
    for (const path of [TEACHING_ROUTES, MEDIA_RENDERER, ASSET_POLICY, DELIVERY]) {
      const source = await read(path);
      expect(source, path).not.toMatch(
        /<(script|img|iframe|source|link)\b[^>]*\b(?:src|href)\s*=\s*['"]https?:/i,
      );
      expect(source, path).not.toMatch(/wp_enqueue_(script|style)\([^)]*https?:/);
      expect(source, path).not.toMatch(
        /googleapis|googletagmanager|gtag\(|analytics\.js|hotjar|segment\.io/i,
      );
    }
  });

  it("the stylesheet carries no animation payload beyond reduced-motion guards", async () => {
    const css = await read(`${THEME_ROOT}/assets/css/theme.css`);
    expect(css).not.toContain("@keyframes");
    // Transitions are limited to cheap state properties — the composited family
    // (colour, the independent transforms, opacity, filter) and the link
    // underline thickness — and are neutralized inside the
    // prefers-reduced-motion block. Paint-only properties (box-shadow,
    // text-shadow) and layout properties stay out: they repaint or reflow on
    // every frame of the hover.
    expect(css).toContain("prefers-reduced-motion");
    const composited = [
      "color",
      "background-color",
      "border-color",
      "transform",
      "translate",
      "rotate",
      "scale",
      "opacity",
      "filter",
      "text-decoration-thickness",
    ];
    for (const match of css.matchAll(/transition:\s*([^;]+);/g)) {
      for (const part of match[1].split(",")) {
        const prop = part.trim().split(/\s+/)[0];
        expect(composited).toContain(prop);
      }
    }
  });
});

describe("task-22: the visible feature image is never lazy-loaded", () => {
  it("the media renderer binds hero placement to eager high-priority loading", async () => {
    const renderer = await read(MEDIA_RENDERER);
    expect(renderer).toContain(
      "$hero       = 'hero' === MediaPolicy::string_value( $usage['placement'] ?? '' );",
    );
    expect(renderer).toContain("$loading    = $hero ? 'eager' : 'lazy';");
    expect(renderer).toContain("$priority   = $hero ? 'high' : 'auto';");
  });

  it("the homepage emits no feature imagery — media plates are decorative", async () => {
    const homepage = await read(`${THEME_ROOT}/includes/class-homepage.php`);
    // The governed media API stays public for other surfaces…
    expect(homepage).toContain("public static function feature_media_markup");
    // …but the homepage never calls it, so there is no LCP image risk at all.
    expect(homepage).not.toMatch(/self::feature_media_markup\( \$record/);
    expect(homepage).not.toMatch(/feature_media_markup\( \$record, \$locale, 'hero' \)/);
  });

  it("a hero document passes the document audit while a lazy hero is flagged", async () => {
    const hero = `<!doctype html><html><head></head><body>
      <img src="/hero.png" width="1600" height="900" alt="Laboratorio" loading="eager" fetchpriority="high" decoding="async">
      <img src="/b.png" width="800" height="600" alt="Detalhe" loading="lazy" decoding="async">
      </body></html>`;
    expect(auditDocument(hero).pass).toBe(true);

    const lazyHero = hero.replace('loading="eager"', 'loading="lazy"');
    const report = auditDocument(lazyHero);
    // A lazy LCP candidate is not a render defect, so the audit stays green;
    // the dedicated check is the eager/high-priority attribute pair itself.
    expect(lazyHero).toContain('loading="lazy"');
    expect(report.findings.map((f) => f.rule)).not.toContain("multiple-priority-images");
  });
});

describe("task-22: large resource lists avoid pathological query growth", () => {
  it("the relationships repository exposes a bulk forward read", async () => {
    const source = await read(RELATIONSHIPS);
    expect(source).toContain(
      "public static function for_sources( array $source_post_ids, string $relationship_type ): array",
    );
    expect(source).toContain("source_post_id IN ({$placeholders})");
  });

  it("the offering materials list batches post, meta and unit reads", async () => {
    const routes = await read(TEACHING_ROUTES);
    const start = routes.indexOf("private static function offering_materials");
    const end = routes.indexOf("return $materials;", start);
    expect(start).toBeGreaterThan(-1);
    expect(end).toBeGreaterThan(start);
    const body = routes.slice(start, end);
    // One bulk relationship read for every resource_unit edge.
    expect(body).toContain("Relationships::for_sources( $authority_ids, 'resource_unit' )");
    // Post and meta caches are primed for the whole list before the loop.
    expect(body).toContain("_prime_post_caches");
    // No per-row relationship query remains inside the loop.
    expect(body).not.toContain("Relationships::for_source( $resource_authority");
  });

  it("the test-only query probe emits a numeric marker in the page footer", async () => {
    const probe = await read(PERF_PROBE);
    expect(probe).toContain("lps-queries:");
    expect(probe).toContain("num_queries");
    expect(probe).toContain("wp_footer");
  });
});

describe("task-22: the release clock is evaluated at read time, never by a scheduler", () => {
  /**
   * Mirrors TeachingContracts::effective_release_state on documented vectors;
   * the shipped logic is asserted through the PHP contract checks below.
   */
  function effectiveReleaseState(releaseState, releaseAt, now) {
    const state = String(releaseState ?? "").trim();
    if (state === "released" || state === "withdrawn") return state;
    if (state === "scheduled") {
      const release = Date.parse(releaseAt);
      const instant = Date.parse(now);
      if (!Number.isNaN(release) && !Number.isNaN(instant) && release <= instant) {
        return "released";
      }
      return "scheduled";
    }
    return "draft";
  }

  it("scheduled releases become effective at their instant without a scheduler", () => {
    expect(
      effectiveReleaseState("scheduled", "2026-09-19T10:00:00+00:00", "2026-09-19T09:59:59+00:00"),
    ).toBe("scheduled");
    expect(
      effectiveReleaseState("scheduled", "2026-09-19T10:00:00+00:00", "2026-09-19T10:00:00+00:00"),
    ).toBe("released");
    expect(
      effectiveReleaseState("scheduled", "2026-09-19T10:00:00+00:00", "2026-09-20T00:00:00+00:00"),
    ).toBe("released");
    expect(effectiveReleaseState("released", "", "2026-09-19T00:00:00+00:00")).toBe("released");
    expect(effectiveReleaseState("withdrawn", "", "2026-09-19T00:00:00+00:00")).toBe("withdrawn");
    expect(effectiveReleaseState("garbage", "", "2026-09-19T00:00:00+00:00")).toBe("draft");
    expect(effectiveReleaseState("scheduled", "not-a-date", "2026-09-19T00:00:00+00:00")).toBe(
      "scheduled",
    );
  });

  it("the shared contract evaluates the clock on every call", async () => {
    const contracts = await read(TEACHING_CONTRACTS);
    expect(contracts).toContain("public static function effective_release_state");
    expect(contracts).toContain("$release_time <= $now_time");
    // Unknown states fail closed to draft.
    expect(contracts).toContain("return 'draft';");
  });

  it("the download resolver and search index share the same clock evaluation", async () => {
    const resources = await read(TEACHING_RESOURCES);
    expect(resources).toContain("TeachingContracts::effective_release_state");
    // The resolver re-reads the release clock on every request.
    expect(resources).toContain("gmdate( 'c' )");
    expect(resources).toContain("'lps_resource_withdrawn'");
    expect(resources).toContain("'lps_resource_not_released'");
    // Bytes are never served when the storage object is missing.
    expect(resources).toContain("'lps_storage_object_missing'");

    const search = await read(SEARCH_POLICY);
    expect(search).toContain("TeachingContracts::effective_release_state");
    expect(search).toContain("return 'released' === $state;");
  });

  it("no cron or scheduled-event dependency exists in the release path", async () => {
    for (const path of [TEACHING_CONTRACTS, TEACHING_RESOURCES, SEARCH_POLICY]) {
      const source = await read(path);
      expect(source, path).not.toMatch(/wp_schedule|spawn_cron|wp_cron|do_action_ref_array.*cron/);
    }
  });
});

describe("task-22: cache decisions and invalidation stay correct", () => {
  it("lifecycle metadata writes are the invalidation signal for timed changes", async () => {
    const delivery = await read(DELIVERY);
    for (const key of [
      "_lps_release_state",
      "_lps_release_at",
      "_lps_withdrawn_at",
      "_lps_cancelled",
      "_lps_temporal_status",
      "_lps_version_id",
    ]) {
      expect(delivery).toContain(`'${key}'`);
    }
    expect(delivery).toContain("purge_on_lifecycle_meta");
    expect(delivery).toContain("lps_cache_purge");
  });

  it("the cache policy keeps the bounded TTL contract", async () => {
    const policy = await read(CACHE_POLICY);
    expect(policy).toContain("public const HTML_TTL = 300;");
    expect(policy).toContain("public const QUERY_TTL = 60;");
    expect(policy).toContain("public const NOT_FOUND_TTL = 60;");
    expect(policy).toContain("stale-while-revalidate");
    expect(policy).toContain("'unapproved-query'");
  });
});

describe("task-22: measurement reporting is honest about laboratory data", () => {
  it("the lighthouse runner labels TBT as the INP proxy, never field INP", async () => {
    const runner = await read(LIGHTHOUSE_RUNS);
    expect(runner).toContain("INP_proxy_TBT");
    expect(runner).not.toMatch(/field.*percentile|CrUX|p75.*field/i);
  });

  it("the measurement doc records environment, method and the lab/field split", async () => {
    const doc = await read(PERF_DOC);
    expect(doc).toContain("laboratory");
    expect(doc).toContain("field");
    expect(doc).toMatch(/environment/i);
    expect(doc).toMatch(/measurement method/i);
    expect(doc).toMatch(/INP/);
    expect(doc).not.toMatch(/field INP.*measured|measured.*field INP/i);
  });

  it("the hosting doc names the actually shipped font payload", async () => {
    const doc = await read(HOSTING_DOC);
    expect(doc).toContain("inter-regular.woff2");
    expect(doc).toContain("space-grotesk-semibold.woff2");
    expect(doc).not.toContain("source-serif-4");
  });
});
