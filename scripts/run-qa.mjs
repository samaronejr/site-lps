import { access, writeFile } from "node:fs/promises";
import { readCorpus, validateCorpus } from "./lib/content-corpus.mjs";
import { readFoundationFixture } from "./lib/foundation-fixture.mjs";
import { runGovernanceQa } from "./lib/governance.mjs";
import { runInformationArchitectureQa } from "./lib/information-architecture.mjs";
import { validateInventory } from "./lib/inventory-validator.mjs";

function option(name, fallback) {
  const index = process.argv.indexOf(name);
  return index === -1 ? fallback : process.argv[index + 1];
}

const lane = process.argv[2];
if (lane === "workspace") {
  const fixture = await readFoundationFixture("tests/fixtures/foundation.json");
  await Promise.all([access(fixture.plugin.entry), access(fixture.theme.entry)]);
  process.stdout.write(`${JSON.stringify({ lane, status: "passed", schemaVersion: 1 })}\n`);
} else if (lane === "inventory") {
  const report = await validateInventory(option("--inventory-dir", "content/inventory"));
  const output = `${JSON.stringify(report, null, 2)}\n`;
  const reportPath = option("--report");
  if (reportPath) await writeFile(reportPath, output);
  process.stdout.write(output);
  if (report.status !== "passed") process.exitCode = 1;
} else if (lane === "content") {
  const corpus = await readCorpus({
    corpusDir: option("--corpus-dir", "content/corpus"),
    inventoryDir: option("--inventory-dir", "content/inventory"),
  });
  const report = validateCorpus(corpus);
  const output = `${JSON.stringify(report, null, 2)}\n`;
  const reportPath = option("--report");
  if (reportPath) await writeFile(reportPath, output);
  process.stdout.write(output);
  if (report.status !== "passed") process.exitCode = 1;
} else if (lane === "governance") {
  const report = await runGovernanceQa();
  const output = `${JSON.stringify(report, null, 2)}\n`;
  process.stdout.write(output);
  if (report.status !== "passed") process.exitCode = 1;
} else if (lane === "ia") {
  const report = await runInformationArchitectureQa({
    routeFixture: option("--route-fixture", process.env.IA_ROUTE_FIXTURE),
    taxonomyDirectory: option("--taxonomy-dir", process.env.IA_TAXONOMY_DIRECTORY),
    outputDirectory: option("--output-dir", process.env.IA_OUTPUT_DIRECTORY),
  });
  const output = `${JSON.stringify(report, null, 2)}\n`;
  const reportPath = option("--report");
  if (reportPath) await writeFile(reportPath, output);
  process.stdout.write(output);
  if (report.status !== "passed") process.exitCode = 1;
} else if (lane === "seo" || lane === "schema") {
  const { runSeoCli } = await import("./qa/seo/cli.mjs");
  await runSeoCli(process.argv.slice(3), lane);
} else if (lane === "a11y") {
  const { runA11yCli } = await import("./qa/a11y/cli.mjs");
  await runA11yCli(process.argv.slice(3));
} else if (lane === "security") {
  const { runSecurityCli } = await import("./qa/security/cli.mjs");
  await runSecurityCli(process.argv.slice(3));
} else if (lane === "infrastructure") {
  const { runInfrastructureCli } = await import("./qa/infrastructure/cli.mjs");
  await runInfrastructureCli(process.argv.slice(3));
} else if (lane === "visual") {
  // Real visual verification lane: drives the Playwright browser-qa suite
  // against the running wp-env playground (LPS_BASE_URL, default :8888).
  // browser-qa.mjs exits 0 only when every capture/assertion passes; its exit
  // code propagates so a failing lane is red, never silently green. When the
  // env is unreachable the lane reports that honestly instead of passing.
  const { execFileSync } = await import("node:child_process");
  const baseURL = process.env.LPS_BASE_URL ?? "http://localhost:8888";
  try {
    execFileSync("curl", ["-fsS", "-o", "/dev/null", "--max-time", "8", "-c", "/tmp/lps-qa-visual-cookies.txt", "-b", "/tmp/lps-qa-visual-cookies.txt", "-L", `${baseURL}/wp-json/`], { stdio: "pipe" });
  } catch {
    process.stderr.write(
      `${JSON.stringify({ lane, status: "env-unreachable", baseURL, hint: "npm run env:start first" })}\n`,
    );
    process.exitCode = 1;
  }
  if (process.exitCode !== 1) {
    try {
      execFileSync(
        process.execPath,
        ["wp-content/themes/lps-theme/tests/browser-qa.mjs"],
        { stdio: "inherit", env: { ...process.env, LPS_BASE_URL: baseURL } },
      );
      process.stdout.write(`${JSON.stringify({ lane, status: "passed", baseURL })}\n`);
    } catch (error) {
      process.stdout.write(
        `${JSON.stringify({ lane, status: "failed", baseURL, exitCode: error.status ?? 1 })}\n`,
      );
      process.exitCode = error.status ?? 1;
    }
  }
} else if (lane === "design-system") {
  const { execFileSync } = await import("node:child_process");
  const { checkTheme } = await import("../wp-content/themes/lps-theme/scripts/check-theme.mjs");
  const showcase = JSON.parse(
    execFileSync(
      process.execPath,
      ["showcase/scientific-editorial/scripts/check-design-system.mjs"],
      { encoding: "utf8" },
    ),
  );
  const theme = await checkTheme();
  const report = {
    lane,
    status: showcase.status === "passed" && theme.status === "passed" ? "passed" : "failed",
    findingCount: showcase.findingCount + theme.findingCount,
    reports: { showcase, theme },
  };
  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
  if (report.status !== "passed") process.exitCode = 1;
} else {
  process.stderr.write(
    `${JSON.stringify({ lane, status: "not-implemented", owner: "future-plan-lane" })}\n`,
  );
  process.exitCode = 2;
}
