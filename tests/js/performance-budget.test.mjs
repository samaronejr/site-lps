import { describe, expect, test } from "vitest";
import {
  auditDocument,
  BUDGETS,
  classifyAsset,
  collectThemeAssets,
  evaluateBudget,
} from "../../scripts/lib/performance-budget.mjs";

const KIB = 1024;

const asset = (path, transferBytes, extra = {}) => ({ path, transferBytes, ...extra });

describe("Todo 21 compressed initial budgets", () => {
  test("declares exactly the approved plan budgets", () => {
    // Given: the plan's compressed initial budget contract.
    // Then: the checker encodes it verbatim, so no run can silently relax a limit.
    expect(BUDGETS).toEqual({
      js: 120 * KIB,
      css: 50 * KIB,
      fonts: 100 * KIB,
      hero: 200 * KIB,
      total: 750 * KIB,
    });
  });

  test("classifies shipped assets by budget category", () => {
    expect(classifyAsset("assets/css/theme.css")).toBe("css");
    expect(classifyAsset("assets/js/nav.js")).toBe("js");
    expect(classifyAsset("assets/js/nav.mjs")).toBe("js");
    expect(classifyAsset("assets/fonts/source-serif-4-regular.woff2")).toBe("fonts");
    expect(classifyAsset("assets/fonts/source-serif-4-regular.ttf")).toBe("fonts");
    expect(classifyAsset("uploads/hero.avif")).toBe("image");
    expect(classifyAsset("readme.txt")).toBe("other");
  });

  test("the shipped theme meets every compressed budget", () => {
    // Given: the real theme payload a first-time anonymous visitor downloads.
    const assets = collectThemeAssets("wp-content/themes/lps-theme");

    // When: the plan budgets are applied to the compressed transfer sizes.
    const report = evaluateBudget(assets);

    // Then: no category is over budget.
    expect(report.violations).toEqual([]);
    expect(report.pass).toBe(true);
    expect(report.totals.css).toBeGreaterThan(0);
    expect(report.totals.fonts).toBeGreaterThan(0);
    expect(report.totals.fonts).toBeLessThanOrEqual(BUDGETS.fonts);
    expect(report.totals.js).toBeLessThanOrEqual(BUDGETS.js);
    expect(report.totals.total).toBeLessThanOrEqual(BUDGETS.total);
  });

  test("an oversized hero image fails the hero budget", () => {
    // Given: a hero that exceeds 200 KiB compressed.
    const assets = [
      asset("assets/css/theme.css", 12 * KIB),
      asset("uploads/hero.avif", 260 * KIB, { role: "hero" }),
    ];

    // When: the budget is evaluated.
    const report = evaluateBudget(assets);

    // Then: the hero category is reported as the violation, with the offending asset named.
    expect(report.pass).toBe(false);
    const hero = report.violations.find((violation) => violation.category === "hero");
    expect(hero).toBeDefined();
    expect(hero.actual).toBe(260 * KIB);
    expect(hero.limit).toBe(BUDGETS.hero);
    expect(hero.assets).toEqual(["uploads/hero.avif"]);
  });

  test("an unbounded font payload fails the font budget", () => {
    // Given: unsubset fonts shipped as full TTF faces.
    const assets = [
      asset("assets/fonts/source-serif-4-regular.ttf", 190 * KIB),
      asset("assets/fonts/source-serif-4-semibold.ttf", 190 * KIB),
    ];

    // When/Then: the font budget rejects the payload.
    const report = evaluateBudget(assets);
    expect(report.pass).toBe(false);
    const fonts = report.violations.find((violation) => violation.category === "fonts");
    expect(fonts.actual).toBe(380 * KIB);
    expect(fonts.limit).toBe(BUDGETS.fonts);
    expect(fonts.assets).toHaveLength(2);
  });

  test("a JavaScript payload over 120 KiB and a total over 750 KiB both fail", () => {
    const assets = [
      asset("assets/js/bundle.js", 130 * KIB),
      asset("uploads/gallery.avif", 700 * KIB),
    ];
    const report = evaluateBudget(assets);
    const categories = report.violations.map((violation) => violation.category);
    expect(categories).toContain("js");
    expect(categories).toContain("total");
  });
});

describe("Todo 21 document rendering rules", () => {
  const goodDocument = `<!doctype html><html lang="pt-BR"><head>
    <link rel="preload" as="font" type="font/woff2" href="/f.woff2" crossorigin>
    <link rel="stylesheet" href="/theme.css">
    </head><body>
    <img src="/hero.avif" width="1600" height="900" alt="Laboratorio" fetchpriority="high" decoding="async">
    <img src="/b.avif" width="800" height="600" alt="Detalhe" loading="lazy" decoding="async">
    <script src="/nav.js" defer></script>
    </body></html>`;

  test("a compliant document raises no finding", () => {
    const report = auditDocument(goodDocument);
    expect(report.findings).toEqual([]);
    expect(report.pass).toBe(true);
  });

  test("a render-blocking script in the head is rejected", () => {
    // Given: a classic synchronous script in <head>.
    const html = goodDocument.replace(
      '<link rel="stylesheet"',
      '<script src="/blocking.js"></script><link rel="stylesheet"',
    );

    // When/Then: the render-blocking rule fires and names the resource.
    const report = auditDocument(html);
    expect(report.pass).toBe(false);
    const finding = report.findings.find((item) => item.rule === "render-blocking-script");
    expect(finding).toBeDefined();
    expect(finding.detail).toContain("/blocking.js");
  });

  test("an image without intrinsic dimensions is rejected", () => {
    // Given: an image that cannot reserve layout space.
    const html = goodDocument.replace(' width="800" height="600"', "");

    // When/Then: the missing-dimensions rule fires.
    const report = auditDocument(html);
    expect(report.pass).toBe(false);
    const finding = report.findings.find((item) => item.rule === "missing-dimensions");
    expect(finding).toBeDefined();
    expect(finding.detail).toContain("/b.avif");
  });

  test("more than one prioritized image is rejected", () => {
    // Given: two images claiming LCP priority.
    const html = goodDocument.replace(
      '<img src="/b.avif" width="800" height="600" alt="Detalhe" loading="lazy"',
      '<img src="/b.avif" width="800" height="600" alt="Detalhe" fetchpriority="high"',
    );

    // When/Then: only the true LCP image may be prioritized.
    const report = auditDocument(html);
    expect(report.pass).toBe(false);
    expect(report.findings.map((item) => item.rule)).toContain("multiple-priority-images");
  });

  test("a below-fold image that is neither lazy nor prioritized is rejected", () => {
    const html = goodDocument.replace(' loading="lazy"', "");
    const report = auditDocument(html);
    expect(report.findings.map((item) => item.rule)).toContain("eager-below-fold-media");
  });
});
