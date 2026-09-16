/**
 * Runs pa11y (HTML_CodeSniffer, WCAG2AA) over every inventory route.
 *
 * pa11y drives the Chromium binary Playwright already installs, so no second
 * browser download is required and the audited engine is the real pa11y runner.
 */

import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";
import { chromium } from "@playwright/test";
import pa11y from "pa11y";
import { routes } from "../../lib/a11y-inventory.mjs";

function option(argv, name, fallback) {
  const index = argv.indexOf(`--${name}`);
  return index === -1 ? fallback : argv[index + 1];
}

/** Runs pa11y over the inventory and returns the merged report. */
export async function runPa11y({ baseUrl, outputDir }) {
  await mkdir(outputDir, { recursive: true });
  const executablePath = chromium.executablePath();
  const pages = [];
  for (const route of routes()) {
    const url = new URL(route.path, baseUrl).toString();
    const result = await pa11y(url, {
      standard: "WCAG2AA",
      includeNotices: false,
      includeWarnings: true,
      runners: ["htmlcs"],
      chromeLaunchConfig: {
        executablePath,
        // Staging terminates TLS with a locally issued certificate.
        args: ["--no-sandbox", "--ignore-certificate-errors"],
      },
      timeout: 60000,
      viewport: { width: 1280, height: 900 },
    });
    pages.push({
      id: route.id,
      url,
      path: route.path,
      locale: route.locale,
      issues: result.issues.map((issue) => ({
        code: issue.code,
        type: issue.type,
        message: issue.message,
        selector: issue.selector,
        context: issue.context,
      })),
    });
    const errors = result.issues.filter((issue) => issue.type === "error").length;
    process.stderr.write(`pa11y ${route.id}: ${errors} errors\n`);
  }
  const report = {
    schemaVersion: 1,
    engine: "pa11y/HTML_CodeSniffer",
    standard: "WCAG2AA",
    baseUrl,
    ranAt: new Date().toISOString(),
    pages,
  };
  await writeFile(
    path.join(outputDir, "pa11y-report.json"),
    `${JSON.stringify(report, null, 2)}\n`,
  );
  return report;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const argv = process.argv.slice(2);
  const report = await runPa11y({
    baseUrl: option(argv, "base-url", process.env.LPS_BASE_URL ?? "http://127.0.0.1:8894"),
    outputDir: option(argv, "output-dir", ".omo/evidence/task-23/live"),
  });
  const errors = report.pages.flatMap((page) =>
    page.issues.filter((issue) => issue.type === "error"),
  );
  process.stdout.write(
    `${JSON.stringify({ pages: report.pages.length, errors: errors.length }, null, 2)}\n`,
  );
  if (errors.length > 0) process.exitCode = 1;
}
