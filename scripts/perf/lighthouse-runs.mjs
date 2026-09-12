/**
 * Repeated Lighthouse captures with stored medians, waterfalls and vitals traces.
 *
 * Wraps the same engine the frontend perfection skill mandates (Lighthouse Node API driven
 * over a CDP endpoint of a real Chromium build, never the `lighthouse` CLI headless-shell),
 * and adds the artifact retention Todo 21 requires: per-run JSON, the median HTML report,
 * the network waterfall and the Web Vitals trace.
 *
 * Usage: node scripts/perf/lighthouse-runs.mjs <outDir> <runs> <label=url> [label=url ...]
 */

import { mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { launch } from "chrome-launcher";
import lighthouse from "lighthouse";

const CATEGORIES = ["performance", "accessibility", "best-practices", "seo"];

const presetConfig = (preset) => ({
  extends: "lighthouse:default",
  settings: {
    formFactor: preset === "desktop" ? "desktop" : "mobile",
    throttling:
      preset === "desktop"
        ? { rttMs: 40, throughputKbps: 10240, cpuSlowdownMultiplier: 1 }
        : undefined,
    screenEmulation:
      preset === "desktop"
        ? { mobile: false, width: 1350, height: 940, deviceScaleFactor: 1, disabled: false }
        : undefined,
    emulatedUserAgent: preset === "desktop" ? undefined : undefined,
    onlyCategories: CATEGORIES,
  },
});

const median = (values) => {
  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  return sorted.length % 2 === 0 ? (sorted[mid - 1] + sorted[mid]) / 2 : sorted[mid];
};

const metric = (lhr, id) => lhr.audits?.[id]?.numericValue ?? null;

const waterfall = (lhr) =>
  (lhr.audits?.["network-requests"]?.details?.items ?? []).map((item) => ({
    url: item.url,
    resourceType: item.resourceType,
    mimeType: item.mimeType,
    startTime: item.networkRequestTime ?? item.startTime,
    endTime: item.networkEndTime ?? item.endTime,
    transferSize: item.transferSize,
    resourceSize: item.resourceSize,
    statusCode: item.statusCode,
    priority: item.priority,
  }));

async function runOnce(url, preset, port) {
  const result = await lighthouse(
    url,
    { port, logLevel: "error", output: ["json", "html"] },
    presetConfig(preset),
  );
  return result;
}

async function main() {
  const [outDir, runsRaw, ...targets] = process.argv.slice(2);
  if (!outDir || !runsRaw || targets.length === 0) {
    console.error("usage: lighthouse-runs.mjs <outDir> <runs> <label=url> ...");
    process.exit(2);
  }
  const runs = Number.parseInt(runsRaw, 10);
  mkdirSync(outDir, { recursive: true });

  const summary = [];
  let failures = 0;

  for (const target of targets) {
    const sep = target.indexOf("=");
    const label = target.slice(0, sep);
    const url = target.slice(sep + 1);

    for (const preset of ["mobile", "desktop"]) {
      const scoreRuns = [];
      const reports = [];
      for (let i = 1; i <= runs; i += 1) {
        const chrome = await launch({
          chromePath: process.env.CHROME_PATH || "/usr/bin/chromium",
          chromeFlags: ["--headless=new", "--no-sandbox", "--disable-dev-shm-usage"],
        });
        try {
          const result = await runOnce(url, preset, chrome.port);
          const lhr = result.lhr;
          const scores = Object.fromEntries(
            CATEGORIES.map((c) => [c, Math.round((lhr.categories[c]?.score ?? 0) * 100)]),
          );
          const vitals = {
            LCP: metric(lhr, "largest-contentful-paint"),
            CLS: metric(lhr, "cumulative-layout-shift"),
            TBT: metric(lhr, "total-blocking-time"),
            INP_proxy_TBT: metric(lhr, "total-blocking-time"),
            FCP: metric(lhr, "first-contentful-paint"),
            SI: metric(lhr, "speed-index"),
            TTI: metric(lhr, "interactive"),
          };
          const run = { label, url, preset, run: i, scores, vitals };
          scoreRuns.push(run);
          reports.push({ run, result });
          writeFileSync(
            join(outDir, `${label}-${preset}-run${i}.json`),
            `${JSON.stringify(lhr, null, 2)}\n`,
          );
          console.log(
            `${label} ${preset} run ${i}: ${CATEGORIES.map((c) => `${c}=${scores[c]}`).join(" ")} LCP=${Math.round(vitals.LCP ?? 0)}ms CLS=${vitals.CLS} TBT=${Math.round(vitals.TBT ?? 0)}ms`,
          );
        } finally {
          await chrome.kill();
        }
      }

      const perfScores = scoreRuns.map((r) => r.scores.performance);
      const medianPerf = median(perfScores);
      let medianIndex = perfScores.indexOf(medianPerf);
      if (medianIndex < 0) {
        const sorted = [...perfScores].sort((a, b) => a - b);
        medianIndex = perfScores.indexOf(sorted[Math.floor(sorted.length / 2)]);
      }
      const medianEntry = reports[medianIndex];
      const medianLhr = medianEntry.result.lhr;

      writeFileSync(
        join(outDir, `${label}-${preset}-median.json`),
        `${JSON.stringify(medianLhr, null, 2)}\n`,
      );
      writeFileSync(
        join(outDir, `${label}-${preset}-median.html`),
        Array.isArray(medianEntry.result.report)
          ? medianEntry.result.report[1]
          : medianEntry.result.report,
      );
      writeFileSync(
        join(outDir, `${label}-${preset}-waterfall.json`),
        `${JSON.stringify(waterfall(medianLhr), null, 2)}\n`,
      );
      const trace = medianEntry.result.artifacts?.Trace ?? medianEntry.result.artifacts?.traces;
      if (trace) {
        writeFileSync(join(outDir, `${label}-${preset}-trace.json`), `${JSON.stringify(trace)}\n`);
      }
      writeFileSync(
        join(outDir, `${label}-${preset}-vitals.json`),
        `${JSON.stringify(
          {
            label,
            url,
            preset,
            runs: scoreRuns,
            medianScores: Object.fromEntries(
              CATEGORIES.map((c) => [c, median(scoreRuns.map((r) => r.scores[c]))]),
            ),
            medianVitals: {
              LCP: median(scoreRuns.map((r) => r.vitals.LCP ?? 0)),
              CLS: median(scoreRuns.map((r) => r.vitals.CLS ?? 0)),
              TBT: median(scoreRuns.map((r) => r.vitals.TBT ?? 0)),
            },
          },
          null,
          2,
        )}\n`,
      );

      const medianScores = Object.fromEntries(
        CATEGORIES.map((c) => [c, median(scoreRuns.map((r) => r.scores[c]))]),
      );
      const pass = CATEGORIES.every((c) => medianScores[c] === 100);
      if (!pass) failures += 1;
      summary.push({
        label,
        url,
        preset,
        medianScores,
        medianVitals: {
          LCP: median(scoreRuns.map((r) => r.vitals.LCP ?? 0)),
          CLS: median(scoreRuns.map((r) => r.vitals.CLS ?? 0)),
          TBT: median(scoreRuns.map((r) => r.vitals.TBT ?? 0)),
        },
        pass,
      });
      console.log(
        `MEDIAN ${label} ${preset}: ${CATEGORIES.map((c) => `${c}=${medianScores[c]}`).join(" ")} ${pass ? "PASS" : "FAIL"}`,
      );
    }
  }

  writeFileSync(join(outDir, "summary.json"), `${JSON.stringify(summary, null, 2)}\n`);
  console.log(`\nsummary written to ${join(outDir, "summary.json")}`);
  process.exit(failures === 0 ? 0 : 1);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
