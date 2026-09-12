/**
 * Runs axe-core over every inventory route at a desktop width and at the 320 CSS px
 * reflow width, and records the accessibility tree snapshot used for manual review.
 */

import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";
import AxeBuilder from "@axe-core/playwright";
import { chromium } from "@playwright/test";
import { routes } from "../../lib/a11y-inventory.mjs";

const VIEWPORTS = [
  { id: "desktop", width: 1280, height: 900 },
  { id: "reflow-320", width: 320, height: 800 },
];

function option(argv, name, fallback) {
  const index = argv.indexOf(`--${name}`);
  return index === -1 ? fallback : argv[index + 1];
}

/** Runs axe over the inventory and returns the merged report. */
export async function runAxe({ baseUrl, outputDir }) {
  await mkdir(outputDir, { recursive: true });
  const browser = await chromium.launch();
  const pages = [];
  try {
    for (const route of routes()) {
      const url = new URL(route.path, baseUrl).toString();
      const violations = [];
      let horizontalOverflow = null;
      for (const viewport of VIEWPORTS) {
        const context = await browser.newContext({
          viewport: { width: viewport.width, height: viewport.height },
        });
        const page = await context.newPage();
        await page.goto(url, { waitUntil: "domcontentloaded" });
        const results = await new AxeBuilder({ page })
          .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa", "best-practice"])
          .analyze();
        for (const violation of results.violations) {
          violations.push({
            id: violation.id,
            impact: violation.impact,
            help: violation.help,
            description: violation.description,
            tags: violation.tags,
            viewport: viewport.id,
            nodes: violation.nodes.map((node) => ({ target: node.target, html: node.html })),
          });
        }
        if (viewport.id === "reflow-320") {
          horizontalOverflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
          );
        }
        await context.close();
      }
      pages.push({
        id: route.id,
        url,
        path: route.path,
        locale: route.locale,
        horizontalOverflow,
        violations,
      });
      process.stderr.write(`axe ${route.id}: ${violations.length} violation entries\n`);
    }
  } finally {
    await browser.close();
  }
  const report = {
    schemaVersion: 1,
    engine: "axe-core",
    baseUrl,
    ranAt: new Date().toISOString(),
    pages,
  };
  await writeFile(path.join(outputDir, "axe-report.json"), `${JSON.stringify(report, null, 2)}\n`);
  return report;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const argv = process.argv.slice(2);
  const report = await runAxe({
    baseUrl: option(argv, "base-url", process.env.LPS_BASE_URL ?? "http://127.0.0.1:8894"),
    outputDir: option(argv, "output-dir", ".omo/evidence/task-23/live"),
  });
  const blocking = report.pages.flatMap((page) =>
    page.violations.filter((violation) => ["serious", "critical"].includes(violation.impact)),
  );
  const overflow = report.pages.filter((page) => (page.horizontalOverflow ?? 0) > 1);
  process.stdout.write(
    `${JSON.stringify(
      {
        pages: report.pages.length,
        blockingViolations: blocking.length,
        reflowOverflowPages: overflow.map((page) => page.id),
      },
      null,
      2,
    )}\n`,
  );
  if (blocking.length > 0 || overflow.length > 0) process.exitCode = 1;
}
