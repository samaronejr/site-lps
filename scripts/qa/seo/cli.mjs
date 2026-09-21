import { writeFile } from "node:fs/promises";
import { pathToFileURL } from "node:url";
import { crawl } from "../../lib/seo-crawler.mjs";
import { readSnapshot, writeSnapshot } from "../../lib/seo-snapshot.mjs";
import { validateSnapshot } from "../../lib/seo-validator.mjs";

/**
 * Runs the SEO and schema quality lanes.
 *
 * `--capture` records a live crawl as a snapshot; every other invocation
 * validates a snapshot directory offline, so the gate never depends on a
 * running server.
 */
export async function runSeoCli(argv, mode) {
  const option = (name, fallback) => {
    const index = argv.indexOf(name);
    return index === -1 ? fallback : argv[index + 1];
  };
  const list = (name) => {
    const value = option(name, "");
    return value === ""
      ? []
      : value
          .split(",")
          .map((entry) => entry.trim())
          .filter(Boolean);
  };

  const number = (name, fallback) => {
    const value = Number(option(name, ""));
    return Number.isFinite(value) && value > 0 ? value : fallback;
  };

  const baseUrl = option("--base-url", "");
  const snapshotDirectory = option("--snapshot", "tests/fixtures/seo/baseline");
  const reportPath = option("--report", "");

  if (argv.includes("--capture")) {
    if (baseUrl === "") {
      throw new Error("--capture requires --base-url");
    }
    const documents = await crawl(baseUrl, {
      extraPaths: list("--extra-paths"),
      timeoutMs: number("--timeout", 120000),
      concurrency: number("--concurrency", 3),
    });
    const keep = list("--only");
    const selected =
      keep.length === 0 ? documents : documents.filter((doc) => keep.includes(doc.path));
    const count = await writeSnapshot(snapshotDirectory, baseUrl.replace(/\/+$/, ""), selected);
    process.stdout.write(
      `${JSON.stringify({ lane: mode, action: "capture", snapshot: snapshotDirectory, documents: count })}\n`,
    );
    return;
  }

  const snapshot =
    baseUrl === ""
      ? await readSnapshot(snapshotDirectory)
      : {
          siteUrl: baseUrl.replace(/\/+$/, ""),
          documents: await crawl(baseUrl, {
            extraPaths: list("--extra-paths"),
            timeoutMs: number("--timeout", 120000),
            concurrency: number("--concurrency", 3),
          }),
        };

  const report = validateSnapshot(snapshot, { mode });
  const output = `${JSON.stringify(report, null, 2)}\n`;
  if (reportPath !== undefined && reportPath !== "") {
    await writeFile(reportPath, output);
  }
  process.stdout.write(output);
  if (report.status !== "passed") {
    process.exitCode = 1;
  }
}

// Direct invocation (`node scripts/qa/seo/cli.mjs --capture ...`) runs the seo
// lane; the schema lane always enters through `scripts/run-qa.mjs`.
if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href) {
	await runSeoCli(process.argv.slice(2), "seo");
}
