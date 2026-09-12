#!/usr/bin/env node
/**
 * Backup, restore and rollback rehearsal driver.
 *
 * Subcommands:
 *   plan               Print the encrypted backup stages, retention and owner blockers.
 *   backup             Produce a backup artifact from a source-tree fixture (pure).
 *   restore-verify     Restore an artifact into a clean tree and compare hashes (pure).
 *   rollback-rehearsal Score application and content rollback against approved RPO/RTO (pure).
 *   run                Execute the backup stages against a real target environment.
 *
 * `run` is the only mutating subcommand and refuses to start unless both the
 * LPS_RECOVERY_TARGET alias and --confirm-mutates-target are supplied. Every
 * other subcommand is pure and safe to run at any time.
 */

import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname } from "node:path";
import process from "node:process";
import {
  buildBackupPlan,
  createBackup,
  formatRollbackReport,
  rehearseRollback,
  verifyRestore,
} from "./recovery-core.mjs";

const CONFIRMATION_FLAG = "confirm-mutates-target";
const TARGET_ENV = "LPS_RECOVERY_TARGET";
const DEFAULT_CONFIG = "docs/operations/recovery-objectives.json";

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
 * Decides whether mutating recovery stages may run against a target environment.
 *
 * @param {{env: Record<string, string|undefined>, flags: Record<string, string|boolean>}} input Execution context.
 * @returns {{allowed: boolean, reason: string}}
 */
export function assertRecoveryExecutionAllowed(input) {
  const target = input?.env?.[TARGET_ENV] ?? "";
  const confirmed = input?.flags?.[CONFIRMATION_FLAG] === true;
  if (target !== "" && confirmed) {
    return { allowed: true, reason: "" };
  }
  return {
    allowed: false,
    reason: `Set ${TARGET_ENV} to the target environment alias and pass --${CONFIRMATION_FLAG} before executing recovery stages.`,
  };
}

/**
 * Maps a restore verification report to its exit code.
 *
 * @param {object} report Report returned by verifyRestore.
 * @returns {number} Process exit code.
 */
export function restoreExitCode(report) {
  return report.verified ? 0 : 1;
}

/**
 * Maps a rollback rehearsal report to its exit code.
 *
 * A blocked rehearsal is a launch blocker, not a pass.
 *
 * @param {object} report Report returned by rehearseRollback.
 * @returns {number} Process exit code.
 */
export function rollbackExitCode(report) {
  return report.status === "pass" ? 0 : 1;
}

function readJson(path) {
  return JSON.parse(readFileSync(path, "utf8"));
}

function writeArtifact(path, contents) {
  mkdirSync(dirname(path), { recursive: true });
  writeFileSync(path, contents, "utf8");
}

function emitOrWrite(flags, contents) {
  if (flags.out === undefined) {
    process.stdout.write(contents);
    return;
  }
  writeArtifact(String(flags.out), contents);
}

function commandPlan(flags) {
  const config = readJson(String(flags.config ?? DEFAULT_CONFIG));
  const plan = buildBackupPlan(config);
  const lines = [
    `owner\t${plan.owner.role}\t${plan.owner.assignee ?? "<unassigned>"}`,
    `encryption\t${plan.encryption.algorithm ?? "<unset>"}\t${plan.encryption.status}`,
    `retention\tdaily=${plan.retention.dailySnapshots}\tweekly=${plan.retention.weeklySnapshots}\tmonthly=${plan.retention.monthlySnapshots}\toffsite=${plan.retention.offsiteCopies}`,
    ...plan.stages.map((stage) => `stage\t${stage.id}\t${stage.command.join(" ")}`),
    ...plan.blockers.map((blocker) => `blocker\t${blocker.id}\t${blocker.detail}`),
    `ready\t${plan.ready}`,
  ];
  emitOrWrite(flags, `${lines.join("\n")}\n`);
  if (!plan.ready) {
    process.stderr.write(
      `Backup plan is not executable: ${plan.blockers.length} unresolved blocker(s).\n`,
    );
    return 1;
  }
  return 0;
}

function commandBackup(flags) {
  const treePath = String(flags.tree ?? "");
  if (treePath === "") {
    process.stderr.write("backup requires --tree=<path>\n");
    return 2;
  }
  const config = readJson(String(flags.config ?? DEFAULT_CONFIG));
  const plan = buildBackupPlan(config);
  if (!plan.ready) {
    for (const blocker of plan.blockers) {
      process.stderr.write(`blocker\t${blocker.id}\t${blocker.detail}\n`);
    }
    process.stderr.write("Refusing to produce a backup artifact with unresolved blockers.\n");
    return 1;
  }
  const artifact = createBackup(readJson(treePath), config);
  emitOrWrite(flags, `${JSON.stringify(artifact, null, 2)}\n`);
  return 0;
}

function commandRestoreVerify(flags) {
  const backupPath = String(flags.backup ?? "");
  const treePath = String(flags.tree ?? "");
  if (backupPath === "" || treePath === "") {
    process.stderr.write("restore-verify requires --backup=<path> and --tree=<path>\n");
    return 2;
  }
  const report = verifyRestore(readJson(treePath), readJson(backupPath));
  const lines = [
    `verified\t${report.verified}`,
    `counts\trecords=${report.counts.records}\tfiles=${report.counts.files}\tconfig=${report.counts.config}`,
    ...report.findings.map(
      (finding) =>
        `finding\t${finding.component}\t${finding.item}\t${finding.class}\t${finding.detail}`,
    ),
  ];
  emitOrWrite(flags, `${lines.join("\n")}\n`);
  if (!report.verified) {
    process.stderr.write(
      `Restore verification failed: ${report.findings.length} finding(s); the backup is not restorable.\n`,
    );
  }
  return restoreExitCode(report);
}

function commandRollbackRehearsal(flags) {
  const config = readJson(String(flags.config ?? DEFAULT_CONFIG));
  const measured = flags.measured === undefined ? [] : readJson(String(flags.measured));
  const report = rehearseRollback({ config, measured });
  emitOrWrite(flags, formatRollbackReport(report));
  if (report.status !== "pass") {
    process.stderr.write(`Rollback rehearsal ${report.status}.\n`);
  }
  return rollbackExitCode(report);
}

function commandRun(flags, env) {
  const permission = assertRecoveryExecutionAllowed({ env, flags });
  if (!permission.allowed) {
    process.stderr.write(`${permission.reason}\n`);
    return 2;
  }
  const config = readJson(String(flags.config ?? DEFAULT_CONFIG));
  const plan = buildBackupPlan(config);
  if (!plan.ready) {
    for (const blocker of plan.blockers) {
      process.stderr.write(`blocker\t${blocker.id}\t${blocker.detail}\n`);
    }
    process.stderr.write("Refusing to execute backup stages with unresolved blockers.\n");
    return 1;
  }
  process.stderr.write(
    "Backup execution against a live target is out of scope for this static tranche; run the printed stages under the cutover runbook with the named owner present.\n",
  );
  for (const stage of plan.stages) {
    process.stdout.write(`stage\t${stage.id}\t${stage.command.join(" ")}\n`);
  }
  return 0;
}

function main(argv, env) {
  const { command, flags } = parseCliArgs(argv);
  switch (command) {
    case "plan":
      return commandPlan(flags);
    case "backup":
      return commandBackup(flags);
    case "restore-verify":
      return commandRestoreVerify(flags);
    case "rollback-rehearsal":
      return commandRollbackRehearsal(flags);
    case "run":
      return commandRun(flags, env);
    default:
      process.stderr.write(
        "usage: recovery.mjs <plan|backup|restore-verify|rollback-rehearsal|run> [--key=value]\n",
      );
      return 2;
  }
}

if (process.argv[1]?.endsWith("recovery.mjs")) {
  process.exitCode = main(process.argv.slice(2), process.env);
}
