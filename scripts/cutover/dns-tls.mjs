#!/usr/bin/env node
/**
 * DNS/TLS cutover readiness driver.
 *
 * Subcommands:
 *   check     Validate a documented cutover plan and print/write the readiness report.
 *   apply     Execute the planned DNS/TLS changes against an approved zone.
 *   rollback  Restore the pre-cutover DNS/TLS state recorded in the plan.
 *
 * `check` is pure: it performs no DNS query, TLS handshake or HTTP request.
 * `apply` mutates external infrastructure and therefore refuses to start unless
 * both the LPS_CUTOVER_TARGET alias and --confirm-mutates-dns are supplied, and
 * it additionally refuses whenever the plan is not ready.
 */

import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname } from "node:path";
import process from "node:process";
import { formatReadinessReport, validateCutoverReadiness } from "./dns-tls-core.mjs";

/** Subcommands exposed by this driver. */
export const CUTOVER_COMMANDS = ["check", "apply", "rollback"];

const CONFIRMATION_FLAG = "confirm-mutates-dns";
const TARGET_ENV = "LPS_CUTOVER_TARGET";

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
 * Decides whether DNS/TLS mutation is permitted.
 *
 * @param {{env: Record<string, string|undefined>, flags: Record<string, string|boolean>}} input Execution context.
 * @returns {{allowed: boolean, reason: string}}
 */
export function assertCutoverExecutionAllowed(input) {
  const target = input?.env?.[TARGET_ENV] ?? "";
  const confirmed = input?.flags?.[CONFIRMATION_FLAG] === true;
  if (target !== "" && confirmed) {
    return { allowed: true, reason: "" };
  }
  return {
    allowed: false,
    reason: `Set ${TARGET_ENV} to the approved zone alias and pass --${CONFIRMATION_FLAG} before executing cutover stages.`,
  };
}

/**
 * Maps a readiness report to its exit code.
 *
 * @param {object} report Report returned by validateCutoverReadiness.
 * @returns {number} Process exit code.
 */
export function readinessExitCode(report) {
  return report.ready ? 0 : 1;
}

function readJson(path) {
  return JSON.parse(readFileSync(path, "utf8"));
}

function commandCheck(flags) {
  const planPath = String(flags.plan ?? "");
  if (planPath === "") {
    process.stderr.write("check requires --plan=<path>\n");
    return 2;
  }
  const report = validateCutoverReadiness(readJson(planPath));
  const rendered = formatReadinessReport(report);
  if (flags.out === undefined) {
    process.stdout.write(rendered);
  } else {
    mkdirSync(dirname(String(flags.out)), { recursive: true });
    writeFileSync(String(flags.out), rendered, "utf8");
  }
  for (const finding of report.findings) {
    if (finding.status === "fail") {
      process.stderr.write(`fail\t${finding.check}\t${finding.item}\t${finding.detail}\n`);
    }
  }
  return readinessExitCode(report);
}

function commandRollback(flags, env) {
  const permission = assertCutoverExecutionAllowed({ env, flags });
  if (!permission.allowed) {
    process.stderr.write(`${permission.reason}\n`);
    return 2;
  }
  const planPath = String(flags.plan ?? "");
  if (planPath === "") {
    process.stderr.write("rollback requires --plan=<path>\n");
    return 2;
  }
  const plan = readJson(planPath);
  for (const threshold of plan.rollback?.thresholds ?? []) {
    process.stdout.write(`threshold\t${threshold.id}\t${threshold.condition}\n`);
  }
  for (const record of plan.dns?.records ?? []) {
    process.stdout.write(
      `restore-record\t${record.host}\t${record.type}\t${record.value}\tttl=${record.ttlSeconds}\n`,
    );
  }
  process.stderr.write(
    "Restoring DNS/TLS state is an execution-time action that requires the named approver and an approved zone; this repository performs plan validation only.\n",
  );
  return 2;
}

function commandApply(flags, env) {
  const permission = assertCutoverExecutionAllowed({ env, flags });
  if (!permission.allowed) {
    process.stderr.write(`${permission.reason}\n`);
    return 2;
  }
  const planPath = String(flags.plan ?? "");
  if (planPath === "") {
    process.stderr.write("apply requires --plan=<path>\n");
    return 2;
  }
  const report = validateCutoverReadiness(readJson(planPath));
  if (!report.ready) {
    for (const finding of report.findings.filter((entry) => entry.status === "fail")) {
      process.stderr.write(`fail\t${finding.check}\t${finding.item}\t${finding.detail}\n`);
    }
    process.stderr.write("Refusing to apply a cutover plan that is not ready.\n");
    return 1;
  }
  process.stderr.write(
    "Applying DNS/TLS changes is an execution-time action that requires the named approver and an approved zone; this repository performs plan validation only.\n",
  );
  return 2;
}

function main(argv, env) {
  const { command, flags } = parseCliArgs(argv);
  switch (command) {
    case "check":
      return commandCheck(flags);
    case "apply":
      return commandApply(flags, env);
    case "rollback":
      return commandRollback(flags, env);
    default:
      process.stderr.write(`usage: dns-tls.mjs <${CUTOVER_COMMANDS.join("|")}> [--key=value]\n`);
      return 2;
  }
}

if (process.argv[1]?.endsWith("dns-tls.mjs")) {
  process.exitCode = main(process.argv.slice(2), process.env);
}
