/**
 * Runs axe-core and pa11y over statically rendered surface documents.
 *
 * This scan needs no server: the documents are produced by the real theme
 * rendering seams, so contrast, target size, focus, and reflow are measured in a
 * real browser against the shipped stylesheet.
 */

import { copyFile, mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { pathToFileURL } from "node:url";
import AxeBuilder from "@axe-core/playwright";
import { chromium } from "@playwright/test";
import pa11y from "pa11y";

const VIEWPORTS = [
  { id: "desktop", width: 1280, height: 900 },
  { id: "reflow-320", width: 320, height: 800 },
];

function option(argv, name, fallback) {
  const index = argv.indexOf(`--${name}`);
  return index === -1 ? fallback : argv[index + 1];
}

/** Scans every rendered document and writes both engine reports. */
export async function scanStaticSurfaces({ sourceDir, outputDir }) {
  await mkdir(outputDir, { recursive: true });
  await copyFile(
    "wp-content/themes/lps-theme/assets/css/theme.css",
    path.join(sourceDir, "theme.css"),
  );
  const manifest = JSON.parse(await readFile(path.join(sourceDir, "manifest.json"), "utf8"));

  const browser = await chromium.launch();
  const axePages = [];
  const measurements = [];
  try {
    for (const document of manifest.documents) {
      const url = pathToFileURL(path.resolve(sourceDir, document.file)).toString();
      const violations = [];
      let overflow = null;
      let smallTargets = [];
      for (const viewport of VIEWPORTS) {
        const context = await browser.newContext({
          viewport: { width: viewport.width, height: viewport.height },
        });
        const page = await context.newPage();
        await page.goto(url, { waitUntil: "load" });
        const results = await new AxeBuilder({ page })
          .withTags(["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"])
          .analyze();
        for (const violation of results.violations) {
          violations.push({
            id: violation.id,
            impact: violation.impact,
            help: violation.help,
            tags: violation.tags,
            viewport: viewport.id,
            nodes: violation.nodes.map((node) => ({ target: node.target, html: node.html })),
          });
        }
        if (viewport.id === "reflow-320") {
          overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
          );
          smallTargets = await page.evaluate(() =>
            [...document.querySelectorAll("a, button, input, select, summary, [tabindex]")]
              .map((element) => {
                const rect = element.getBoundingClientRect();
                const style = getComputedStyle(element);
                const parent = element.parentElement;
                // SC 2.5.8 exempts a target that is inline inside a sentence, where
                // the line box of the surrounding text constrains its size.
                const inlineInSentence =
                  style.display === "inline" &&
                  Boolean(parent) &&
                  parent.textContent.trim().length > element.textContent.trim().length;
                return {
                  selector: element.id
                    ? `#${element.id}`
                    : `${element.tagName.toLowerCase()}${element.className ? `.${element.className.toString().split(" ")[0]}` : ""}`,
                  width: Math.round(rect.width),
                  height: Math.round(rect.height),
                  display: style.display,
                  exempt: inlineInSentence ? "inline-in-sentence" : null,
                };
              })
              .filter((entry) => entry.width > 0 && (entry.width < 24 || entry.height < 24)),
          );
        }
        await context.close();
      }
      axePages.push({ id: document.id, url, violations, horizontalOverflow: overflow });
      measurements.push({ id: document.id, horizontalOverflow: overflow, smallTargets });
      process.stderr.write(
        `axe ${document.id}: ${violations.length} violations, overflow ${overflow}px\n`,
      );
    }
  } finally {
    await browser.close();
  }

  const executablePath = chromium.executablePath();
  const pa11yPages = [];
  for (const document of manifest.documents) {
    const url = pathToFileURL(path.resolve(sourceDir, document.file)).toString();
    const result = await pa11y(url, {
      standard: "WCAG2AA",
      includeWarnings: true,
      runners: ["htmlcs"],
      chromeLaunchConfig: { executablePath, args: ["--no-sandbox"] },
      timeout: 60000,
      viewport: { width: 1280, height: 900 },
    });
    pa11yPages.push({
      id: document.id,
      url,
      issues: result.issues.map((issue) => ({
        code: issue.code,
        type: issue.type,
        message: issue.message,
        selector: issue.selector,
      })),
    });
    process.stderr.write(
      `pa11y ${document.id}: ${result.issues.filter((i) => i.type === "error").length} errors\n`,
    );
  }

  await writeFile(
    path.join(outputDir, "axe-static-report.json"),
    `${JSON.stringify({ schemaVersion: 1, engine: "axe-core", scope: "static-surfaces", ranAt: new Date().toISOString(), pages: axePages }, null, 2)}\n`,
  );
  await writeFile(
    path.join(outputDir, "pa11y-static-report.json"),
    `${JSON.stringify({ schemaVersion: 1, engine: "pa11y/HTML_CodeSniffer", standard: "WCAG2AA", scope: "static-surfaces", ranAt: new Date().toISOString(), pages: pa11yPages }, null, 2)}\n`,
  );
  await writeFile(
    path.join(outputDir, "static-measurements.json"),
    `${JSON.stringify({ schemaVersion: 1, viewport: "320x800", ranAt: new Date().toISOString(), measurements }, null, 2)}\n`,
  );
  return { axePages, pa11yPages, measurements };
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const argv = process.argv.slice(2);
  const result = await scanStaticSurfaces({
    sourceDir: option(argv, "source-dir", ".omo/evidence/task-23/static-surfaces"),
    outputDir: option(argv, "output-dir", ".omo/evidence/task-23/a11y"),
  });
  const blocking = result.axePages.flatMap((page) =>
    page.violations.filter((violation) => ["serious", "critical"].includes(violation.impact)),
  );
  const errors = result.pa11yPages.flatMap((page) =>
    page.issues.filter((issue) => issue.type === "error"),
  );
  const overflow = result.measurements.filter((entry) => (entry.horizontalOverflow ?? 0) > 1);
  const small = result.measurements.flatMap((entry) =>
    entry.smallTargets
      .filter((target) => target.exempt === null)
      .map((target) => ({ page: entry.id, ...target })),
  );
  process.stdout.write(
    `${JSON.stringify(
      {
        documents: result.axePages.length,
        axeBlocking: blocking.length,
        pa11yErrors: errors.length,
        reflowOverflow: overflow.map((entry) => entry.id),
        undersizedTargets: small.length,
        undersized: small,
      },
      null,
      2,
    )}\n`,
  );
  if (blocking.length > 0 || errors.length > 0 || overflow.length > 0 || small.length > 0)
    process.exitCode = 1;
}
