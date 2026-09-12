#!/usr/bin/env node
/**
 * Staging migration rehearsal driver.
 *
 * Subcommands:
 *   plan       Print the ordered rehearsal stages and their transcript paths.
 *   reconcile  Reconcile a corpus against a target state and write the workbook.
 *   run        Execute every stage against a staging alias, capturing transcripts.
 *
 * `run` mutates a staging environment, so it refuses to start unless both the
 * LPS_REHEARSAL_TARGET alias and --confirm-mutates-staging are supplied. `plan`
 * and `reconcile` are pure and safe to run at any time.
 */

import { spawnSync } from "node:child_process";
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import process from "node:process";
import {
  buildRehearsalStages,
  formatWorkbook,
  planMigration,
  reconcile,
} from "./rehearsal-core.mjs";

const CONFIRMATION_FLAG = "confirm-mutates-staging";
const TARGET_ENV = "LPS_REHEARSAL_TARGET";

/**
 * Parses `command --key=value --flag` arguments.
 *
 * @param {string[]} argv Raw arguments without the node executable.
 * @returns {{command: string, flags: Record<string, string|boolean>}}
 */
export function parseCliArgs(argv) {
  const flags = {};
  let command = "";
  for (const argument of argv) {
    if (!argument.startsWith("--")) {
      if (command === "") {
        command = argument;
      }
      continue;
    }
    const [key, ...rest] = argument.slice(2).split("=");
    flags[key] = rest.length === 0 ? true : rest.join("=");
  }
  return { command, flags };
}

/**
 * Decides whether stage execution against staging is permitted.
 *
 * @param {{env: Record<string, string|undefined>, flags: Record<string, string|boolean>}} input Execution context.
 * @returns {{allowed: boolean, reason: string}}
 */
export function assertExecutionAllowed(input) {
  const target = input?.env?.[TARGET_ENV] ?? "";
  const confirmed = input?.flags?.[CONFIRMATION_FLAG] === true;
  if (target !== "" && confirmed) {
    return { allowed: true, reason: "" };
  }
  return {
    allowed: false,
    reason: `Set ${TARGET_ENV} to the staging alias and pass --${CONFIRMATION_FLAG} before executing rehearsal stages.`,
  };
}

/**
 * Maps a reconciliation report to the rehearsal exit code.
 *
 * A rehearsal only passes when the corpus reconciles and nothing was
 * quarantined; quarantined rows are unresolved blockers, not warnings.
 *
 * @param {object} report Report returned by reconcile.
 * @returns {number} Process exit code.
 */
export function reconciliationExitCode(report) {
  if (report.stopped || !report.balanced || report.quarantined > 0) {
    return 1;
  }
  return 0;
}

function readJson(path) {
  return JSON.parse(readFileSync(path, "utf8"));
}

function writeArtifact(path, contents) {
  mkdirSync(dirname(path), { recursive: true });
  writeFileSync(path, contents, "utf8");
}

function commandPlan(flags) {
  const stages = buildRehearsalStages({
    package: String(flags.package ?? "dist/import-package.json"),
    exportPath: String(flags.export ?? "exports/rehearsal-export.json"),
  });
  for (const stage of stages) {
    process.stdout.write(`${stage.id}\t${stage.command.join(" ")}\t${stage.transcript}\n`);
  }
  return 0;
}

function commandReconcile(flags) {
  const corpusPath = String(flags.corpus ?? "");
  if (corpusPath === "") {
    process.stderr.write("reconcile requires --corpus=<path>\n");
    return 2;
  }
  const corpus = readJson(corpusPath);
  const target =
    flags.target === undefined
      ? (corpus.target ?? { records: {} })
      : readJson(String(flags.target));
  const plan = planMigration({ corpus, target });
  const report = reconcile(plan, corpus);
  const workbook = formatWorkbook(report, plan);
  if (flags.out !== undefined) {
    writeArtifact(String(flags.out), workbook);
  } else {
    process.stdout.write(workbook);
  }
  if (report.stopped) {
    process.stderr.write("Rehearsal stopped: the corpus has run-wide integrity failures.\n");
  } else if (report.quarantined > 0) {
    process.stderr.write(
      `Rehearsal incomplete: ${report.quarantined} record(s) quarantined with unresolved blockers.\n`,
    );
  }
  return reconciliationExitCode(report);
}

function commandRun(flags, env) {
  const permission = assertExecutionAllowed({ env, flags });
  if (!permission.allowed) {
    process.stderr.write(`${permission.reason}\n`);
    return 2;
  }
  const directory = String(flags.dir ?? "rehearsal");
  const stages = buildRehearsalStages({
    package: String(flags.package ?? "dist/import-package.json"),
    exportPath: String(flags.export ?? join(directory, "exports/rehearsal-export.json")),
  });
  for (const stage of stages) {
    const transcript = join(directory, stage.transcript);
    const started = new Date().toISOString();
    const result = spawnSync(stage.command[0], stage.command.slice(1), {
      encoding: "utf8",
      env,
    });
    const body = [
      `# ${stage.id} — ${stage.description}`,
      `# started: ${started}`,
      `# command: ${stage.command.join(" ")}`,
      `# exit: ${result.status}`,
      result.stdout ?? "",
      result.stderr ?? "",
      "",
    ].join("\n");
    writeArtifact(transcript, body);
    process.stdout.write(`${stage.id}\texit=${result.status}\t${transcript}\n`);
    if (stage.stopOnFailure && result.status !== 0) {
      process.stderr.write(
        `Stage ${stage.id} failed; the rehearsal stopped before later stages.\n`,
      );
      return 1;
    }
  }
  return 0;
}

function main(argv, env) {
  const { command, flags } = parseCliArgs(argv);
  switch (command) {
    case "plan":
      return commandPlan(flags);
    case "reconcile":
      return commandReconcile(flags);
    case "run":
      return commandRun(flags, env);
    default:
      process.stderr.write("usage: rehearsal.mjs <plan|reconcile|run> [--key=value]\n");
      return 2;
  }
}

if (process.argv[1]?.endsWith("rehearsal.mjs")) {
  process.exitCode = main(process.argv.slice(2), process.env);
}
