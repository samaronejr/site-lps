import { spawnSync } from "node:child_process";
import { mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import {
  assertLocalRehearsalAllowed,
  parseCliArgs,
  rehearsalExitCode,
} from "../../../scripts/deploy/local-rehearsal.mjs";

/**
 * Task 23 — staging deployment, media/database restore and rollback rehearsal.
 *
 * This is the dry-run/rehearsal contract test for the operations tooling the
 * staging rehearsal invokes: the backup/restore driver, the migration
 * rehearsal driver, the DNS/TLS cutover driver, the infrastructure preflight
 * lane and the new local rehearsal driver. Every assertion runs the real CLI
 * as a subprocess and pins its real exit code, so a regression in the safety
 * contract (mutating without authorization, passing a blocked plan, verifying
 * a corrupt artifact) turns the suite red.
 *
 * The hosted half of the rehearsal — an authorized staging target, DNS
 * control, the encryption recipient key and approved RPO/RTO — has no local
 * substitute. Those prerequisites are asserted here as *blocked*, which is
 * the honest outcome the plan requires: absent infrastructure produces
 * BLOCKED, not PASS.
 */

const NODE = process.execPath;
const RECOVERY = "scripts/backup/recovery.mjs";
const REHEARSAL = "scripts/migration/rehearsal.mjs";
const CUTOVER = "scripts/cutover/dns-tls.mjs";
const RUN_QA = "scripts/run-qa.mjs";
const LOCAL_REHEARSAL = "scripts/deploy/local-rehearsal.mjs";
const GOVERNANCE_OBJECTIVES = "docs/operations/recovery-objectives.json";

function cli(script, args, env = {}) {
  const result = spawnSync(NODE, [script, ...args], {
    encoding: "utf8",
    env: { ...process.env, ...env },
  });
  return {
    exit: result.status,
    stdout: result.stdout ?? "",
    stderr: result.stderr ?? "",
  };
}

const fixture = (name) => `tests/fixtures/recovery/${name}.json`;
const migrationFixture = (name) => `tests/fixtures/migration/${name}.json`;

describe("task-23: backup plan refuses to run while governance blockers stand", () => {
  it("plan exits 1 and names the encryption, owner and retention blockers", () => {
    const result = cli(RECOVERY, ["plan"]);

    expect(result.exit).toBe(1);
    expect(result.stdout).toContain("backup-encryption-key-unassigned");
    expect(result.stdout).toContain("backup-owner-unassigned");
    expect(result.stdout).toContain("backup-retention-unapproved");
    expect(result.stdout).toContain("ready\tfalse");
  });

  it("backup refuses to produce an artifact while blockers remain", () => {
    const result = cli(RECOVERY, [
      "backup",
      `--tree=${fixture("source-tree")}`,
      "--out=/tmp/lps-t23-should-not-exist.json",
    ]);

    expect(result.exit).toBe(1);
    expect(result.stderr).toContain("backup-encryption-key-unassigned");
  });

  it("run refuses to mutate any target without the alias and confirmation flag", () => {
    const bare = cli(RECOVERY, ["run"]);
    expect(bare.exit).toBe(2);
    expect(bare.stderr).toContain("LPS_RECOVERY_TARGET");

    const targetOnly = cli(RECOVERY, ["run"], { LPS_RECOVERY_TARGET: "@staging" });
    expect(targetOnly.exit).toBe(2);

    const flagOnly = cli(RECOVERY, ["run", "--confirm-mutates-target"]);
    expect(flagOnly.exit).toBe(2);
  });
});

describe("task-23: backup -> restore -> hash comparison contract", () => {
  it("restore-verify exits 0 for the healthy fixture artifact", () => {
    const result = cli(RECOVERY, [
      "restore-verify",
      `--backup=${fixture("healthy-backup")}`,
      `--tree=${fixture("source-tree")}`,
    ]);

    expect(result.exit).toBe(0);
    expect(result.stdout).toContain("verified\ttrue");
  });

  it("restore-verify exits 1 and names the corrupt record", () => {
    const result = cli(RECOVERY, [
      "restore-verify",
      `--backup=${fixture("corrupt-backup")}`,
      `--tree=${fixture("source-tree")}`,
    ]);

    expect(result.exit).toBe(1);
    expect(result.stdout).toContain("verified\tfalse");
    expect(result.stdout).toContain("lps-source-0002");
    expect(result.stdout).toContain("corrupt_payload");
  });

  it("restore-verify exits 1 and names the missing media path", () => {
    const result = cli(RECOVERY, [
      "restore-verify",
      `--backup=${fixture("missing-media")}`,
      `--tree=${fixture("source-tree")}`,
    ]);

    expect(result.exit).toBe(1);
    expect(result.stdout).toContain("uploads/2026/03/lps-seminar.jpg");
    expect(result.stdout).toContain("missing_payload");
  });

  it("restore-verify exits 1 when the restored tree drifts from the source schema", () => {
    const drifted = JSON.parse(readFileSync(fixture("source-tree"), "utf8"));
    delete drifted.records["lps-source-0003"];
    drifted.records["lps-source-9999"] = '{"slug":"unexpected","locale":"en"}';
    const dir = mkdtempSync(join(tmpdir(), "lps-t23-drift-"));
    const driftedPath = join(dir, "drifted-tree.json");
    writeFileSync(driftedPath, JSON.stringify(drifted));

    const result = cli(RECOVERY, [
      "restore-verify",
      `--backup=${fixture("healthy-backup")}`,
      `--tree=${driftedPath}`,
    ]);

    expect(result.exit).toBe(1);
    expect(result.stdout).toContain("verified\tfalse");
    expect(result.stdout).toContain("lps-source-0003");
    expect(result.stdout).toContain("lps-source-9999");
  });
});

describe("task-23: rollback rehearsal scoring stays blocked without approvals", () => {
  it("exits 1 with blocked objectives against the governance config", () => {
    const result = cli(RECOVERY, [
      "rollback-rehearsal",
      `--measured=${fixture("rollback-measurements")}`,
    ]);

    expect(result.exit).toBe(1);
    expect(result.stdout).toContain("Status: **blocked**");
    expect(result.stdout).toContain("rpo-rto-unapproved:application-rollback");
    expect(result.stdout).toContain("rpo-rto-unapproved:content-revision-rollback");
  });

  it("exits 0 only when approved objectives meet their measurements", () => {
    const result = cli(RECOVERY, [
      "rollback-rehearsal",
      `--config=${fixture("objectives-approved")}`,
      `--measured=${fixture("rollback-measurements")}`,
    ]);

    expect(result.exit).toBe(0);
    expect(result.stdout).toContain("Status: **pass**");
  });
});

describe("task-23: migration rehearsal dry-run contract", () => {
  it("plan prints the ordered stages without mutating anything", () => {
    const result = cli(REHEARSAL, ["plan", "--package=dist/import-package.json"]);

    expect(result.exit).toBe(0);
    expect(result.stdout).toContain("reset");
    expect(result.stdout).toContain("import-dry-run");
    expect(result.stdout).toContain("reimport-apply");
    expect(result.stdout).toContain("reconcile");
  });

  it("reconcile exits 0 on the baseline corpus", () => {
    const result = cli(REHEARSAL, ["reconcile", `--corpus=${migrationFixture("baseline")}`]);

    expect(result.exit).toBe(0);
  });

  it("reconcile exits 1 on a changed checksum and on an ambiguous author", () => {
    for (const name of ["changed-checksum", "ambiguous-author"]) {
      const result = cli(REHEARSAL, ["reconcile", `--corpus=${migrationFixture(name)}`]);
      expect(result.exit, `${name} must not reconcile clean`).toBe(1);
    }
  });

  it("run refuses staging mutation without the alias and confirmation flag", () => {
    const bare = cli(REHEARSAL, ["run"]);
    expect(bare.exit).toBe(2);
    expect(bare.stderr).toContain("LPS_REHEARSAL_TARGET");

    const targetOnly = cli(REHEARSAL, ["run"], { LPS_REHEARSAL_TARGET: "@staging" });
    expect(targetOnly.exit).toBe(2);
  });
});

describe("task-23: cutover and infrastructure preflight contracts", () => {
  it("cutover check exits 0 on the healthy plan and 1 on the live-risk plan", () => {
    const healthy = cli(CUTOVER, ["check", "--plan=tests/fixtures/cutover/healthy.json"]);
    expect(healthy.exit).toBe(0);

    const risk = cli(CUTOVER, ["check", "--plan=tests/fixtures/cutover/current-live-risk.json"]);
    expect(risk.exit).toBe(1);
  });

  it("cutover rollback refuses without the zone alias and confirmation flag", () => {
    const bare = cli(CUTOVER, ["rollback", "--plan=tests/fixtures/cutover/healthy.json"]);
    expect(bare.exit).toBe(2);
    expect(bare.stderr).toContain("LPS_CUTOVER_TARGET");
  });

  it("infrastructure lane passes the healthy fixture and fails the expired certificate", () => {
    const healthy = cli(RUN_QA, [
      "infrastructure",
      "--fixture",
      "tests/fixtures/infrastructure/healthy.json",
    ]);
    expect(healthy.exit).toBe(0);
    expect(JSON.parse(healthy.stdout).overallStatus).not.toBe("failed");

    const expired = cli(RUN_QA, [
      "infrastructure",
      "--fixture",
      "tests/fixtures/infrastructure/expired-certificate.json",
    ]);
    expect(expired.exit).toBe(1);
    expect(JSON.parse(expired.stdout).overallStatus).toBe("failed");
  });

  it("infrastructure lane refuses a live check without both hosts", () => {
    const result = cli(RUN_QA, ["infrastructure"]);

    expect(result.exit).not.toBe(0);
  });
});

describe("task-23: local rehearsal driver safety contract", () => {
  it("parses command and --key=value / --flag arguments", () => {
    expect(
      parseCliArgs(["run", "--env-dir=/tmp/x", "--port=8906", "--confirm-local-rehearsal"]),
    ).toEqual({
      command: "run",
      flags: {
        "env-dir": "/tmp/x",
        port: "8906",
        "confirm-local-rehearsal": true,
      },
    });
  });

  it("refuses to run without the confirmation flag", () => {
    const decision = assertLocalRehearsalAllowed({
      flags: {},
      envDir: join(tmpdir(), "lps-t23-env"),
      port: 8906,
    });

    expect(decision.allowed).toBe(false);
    expect(decision.reason).toContain("confirm-local-rehearsal");
  });

  it("refuses an env dir outside the system temp directory", () => {
    const decision = assertLocalRehearsalAllowed({
      flags: { "confirm-local-rehearsal": true },
      envDir: "/var/www/lps",
      port: 8906,
    });

    expect(decision.allowed).toBe(false);
    expect(decision.reason).toContain(tmpdir());
  });

  it("refuses a privileged or invalid port", () => {
    for (const port of [80, 0, -1, 70000, Number.NaN]) {
      const decision = assertLocalRehearsalAllowed({
        flags: { "confirm-local-rehearsal": true },
        envDir: join(tmpdir(), "lps-t23-env"),
        port,
      });
      expect(decision.allowed, `port ${port}`).toBe(false);
    }
  });

  it("accepts a confirmed local rehearsal inside the temp directory", () => {
    const decision = assertLocalRehearsalAllowed({
      flags: { "confirm-local-rehearsal": true },
      envDir: join(tmpdir(), "lps-t23-env"),
      port: 8906,
    });

    expect(decision).toEqual({ allowed: true, reason: "" });
  });

  it("maps only a passing report to exit code 0", () => {
    expect(rehearsalExitCode({ status: "pass" })).toBe(0);
    expect(rehearsalExitCode({ status: "fail" })).toBe(1);
    expect(rehearsalExitCode({ status: "blocked" })).toBe(1);
    expect(rehearsalExitCode(null)).toBe(1);
  });

  it("the CLI exits 2 when the confirmation flag is missing", () => {
    const result = cli(LOCAL_REHEARSAL, [
      "run",
      `--env-dir=${join(tmpdir(), "lps-t23-denied-env")}`,
      "--port=8906",
    ]);

    expect(result.exit).toBe(2);
    expect(result.stderr + result.stdout).toContain("confirm-local-rehearsal");
  });
});

describe("task-23: hosted prerequisites stay blocked, never faked", () => {
  it("the governance recovery objectives record no approvals", () => {
    const config = JSON.parse(readFileSync(GOVERNANCE_OBJECTIVES, "utf8"));

    expect(config.encryption.recipientKeyId).toBeNull();
    expect(config.retention.status).not.toBe("approved");
    for (const objective of config.objectives) {
      expect(objective.approval.status).not.toBe("approved");
      expect(objective.approval.approvedBy).toBeNull();
    }
    expect(config.owners.assignees.deployer).toBeNull();
    expect(config.owners.assignees.administrator).toBeNull();
    expect(config.owners.assignees.publisher).toBeNull();
  });
});
