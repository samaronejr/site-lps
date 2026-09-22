import { writeFile } from "node:fs/promises";
import { runLinksQa } from "./checker.mjs";

/**
 * Runs the internal route/link integrity lane.
 *
 * `--base-url` (or `LPS_BASE_URL`) names the running site; `--route-fixture`
 * overrides the IA route fixture the static route set is read from;
 * `--report` writes the JSON report to disk. The lane exits non-zero when the
 * environment is unreachable or any route/link check fails — it never passes
 * silently.
 */
export async function runLinksCli(argv) {
  const option = (name, fallback) => {
    const index = argv.indexOf(name);
    return index === -1 ? fallback : argv[index + 1];
  };
  const number = (name, fallback) => {
    const value = Number(option(name, ""));
    return Number.isFinite(value) && value > 0 ? value : fallback;
  };

  const baseUrl = option("--base-url", process.env.LPS_BASE_URL ?? "http://localhost:8888");
  const reportPath = option("--report", "");

  const report = await runLinksQa({
    baseUrl,
    routeFixture: option("--route-fixture", "tests/fixtures/ia/routes.json"),
    timeoutMs: number("--timeout", 120000),
    concurrency: number("--concurrency", 4),
    maxLinks: number("--max-links", 600),
  });

  const output = `${JSON.stringify(report, null, 2)}\n`;
  if (reportPath !== "") {
    await writeFile(reportPath, output);
  }
  process.stdout.write(output);
  if (report.status !== "passed") {
    process.exitCode = 1;
  }
}
