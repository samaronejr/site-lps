#!/usr/bin/env node
/**
 * Reference and incumbent screenshot capture for lps-website-ulw-plan task-02.
 *
 * Captures desktop and mobile screenshots of:
 *   - the requested institutional references (FEEC, PEE) — observation only,
 *     no assets are copied;
 *   - the incumbent LPS site served by the development runtime;
 *   - the isolated logo fixture rendering the supplied artwork.
 *
 * Uses playwright-core with the system Chromium (/usr/bin/chromium,
 * --no-sandbox). Every capture writes a PNG plus a JSON metadata sidecar
 * carrying the capture date, viewport, URL, and outcome.
 *
 * Usage:
 *   node scripts/lps-redesign/capture-references.mjs --out=<dir> [--base=<url>]
 * Exit codes: 0 all captures succeeded, 1 one or more failed (failures are
 * recorded in the manifest, never hidden).
 */

import { mkdirSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";
import { chromium } from "playwright-core";

const args = process.argv.slice(2);
const outDir = resolve(
  args.find((a) => a.startsWith("--out="))?.slice(6) ?? "test-results/lps-redesign/captures",
);
const base = args.find((a) => a.startsWith("--base="))?.slice(7) ?? "http://127.0.0.1:8888";
const fixture = `file://${resolve("tests/fixtures/lps-redesign/logo-fixture.html")}`;

const VIEWPORTS = {
  desktop: { width: 1280, height: 800 },
  mobile: { width: 375, height: 720 },
};

const TARGETS = [
  { id: "reference-feec-home", url: "https://www.fee.unicamp.br/", kind: "reference" },
  { id: "reference-pee-home", url: "https://www.pee.ufrj.br/", kind: "reference" },
  { id: "incumbent-lps-home", url: `${base}/pt-br/`, kind: "incumbent" },
  { id: "incumbent-lps-pesquisa", url: `${base}/pt-br/pesquisa/`, kind: "incumbent" },
  { id: "incumbent-lps-pessoas", url: `${base}/pt-br/pessoas/`, kind: "incumbent" },
  { id: "fixture-logo", url: fixture, kind: "fixture" },
];

mkdirSync(outDir, { recursive: true });
const manifest = { capturedAt: new Date().toISOString(), captures: [] };

const browser = await chromium.launch({
  executablePath: "/usr/bin/chromium",
  args: ["--no-sandbox"],
});

let failures = 0;
for (const target of TARGETS) {
  for (const [viewportName, viewport] of Object.entries(VIEWPORTS)) {
    const name = `${target.id}-${viewportName}`;
    const record = {
      id: name,
      url: target.url,
      kind: target.kind,
      viewport,
      capturedAt: new Date().toISOString(),
    };
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    try {
      const response = await page.goto(target.url, {
        waitUntil: "domcontentloaded",
        timeout: 45_000,
      });
      await page.waitForTimeout(1500); // settle fonts/layout, not a timing assertion
      record.httpStatus = response?.status() ?? null;
      record.title = await page.title();
      await page.screenshot({ path: `${outDir}/${name}.png`, fullPage: false });
      record.file = `${name}.png`;
      record.status = "captured";
    } catch (error) {
      record.status = "failed";
      record.error = String(error).slice(0, 300);
      failures += 1;
    } finally {
      await context.close();
    }
    manifest.captures.push(record);
    writeFileSync(`${outDir}/${name}.json`, `${JSON.stringify(record, null, 2)}\n`);
    process.stdout.write(`${record.status === "captured" ? "OK " : "FAIL"} ${name}\n`);
  }
}

await browser.close();
writeFileSync(`${outDir}/manifest.json`, `${JSON.stringify(manifest, null, 2)}\n`);
process.stdout.write(`failures: ${failures}\n`);
process.exitCode = failures > 0 ? 1 : 0;
