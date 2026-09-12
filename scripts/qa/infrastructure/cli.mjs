import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { evaluateReport, inspectInfrastructure } from "./checker.mjs";
import { renderInfrastructureReport } from "./report.mjs";

function parseArguments(args) {
  const options = {
    host: null,
    alternate: null,
    fixture: null,
    timeoutMs: 8_000,
    artifactsDir: ".omo/evidence/task-2/certificates",
    report: null,
  };
  for (let index = 0; index < args.length; index += 1) {
    const key = args[index];
    const value = args[index + 1];
    if (key === "--host") options.host = value;
    else if (key === "--alternate") options.alternate = value;
    else if (key === "--fixture") options.fixture = value;
    else if (key === "--timeout-ms") options.timeoutMs = Number(value);
    else if (key === "--artifacts-dir") options.artifactsDir = value;
    else if (key === "--report") options.report = value;
    else throw new Error(`Unknown argument: ${key}`);
    index += 1;
  }
  if (options.fixture) return options;
  if (!options.host || !options.alternate) {
    throw new Error("--host and --alternate are required for live checks");
  }
  if (
    !Number.isInteger(options.timeoutMs) ||
    options.timeoutMs < 100 ||
    options.timeoutMs > 30_000
  ) {
    throw new Error("--timeout-ms must be an integer from 100 through 30000");
  }
  options.report ??= "docs/operations/infrastructure-preflight.md";
  return options;
}

async function writeArtifacts(directory, report, certificates) {
  await mkdir(directory, { recursive: true });
  for (const host of report.hosts) {
    const certificate = certificates[host.hostname];
    if (certificate)
      await writeFile(path.join(directory, `${host.hostname}.pem`), certificate, "utf8");
    await writeFile(
      path.join(directory, `${host.hostname}.json`),
      `${JSON.stringify({ checkedAt: report.checkedAt, hostname: host.hostname, tls: host.tls }, null, 2)}\n`,
      "utf8",
    );
  }
}

export async function runInfrastructureCli(args) {
  const options = parseArguments(args);
  let report;
  if (options.fixture) {
    const fixture = JSON.parse(await readFile(options.fixture, "utf8"));
    report = evaluateReport(fixture);
  } else {
    const result = await inspectInfrastructure(options);
    report = result.report;
    await writeArtifacts(options.artifactsDir, report, result.certificates);
  }
  if (options.report) {
    await mkdir(path.dirname(options.report), { recursive: true });
    await writeFile(options.report, renderInfrastructureReport(report), "utf8");
  }
  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
  process.exitCode = report.overallStatus === "failed" ? 1 : 0;
}
