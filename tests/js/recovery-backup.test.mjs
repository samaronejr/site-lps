import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import {
  buildBackupPlan,
  createBackup,
  evaluateRecoveryObjectives,
  rehearseRollback,
  restoreBackup,
  verifyRestore,
} from "../../scripts/backup/recovery-core.mjs";

const readJson = (relative) =>
  JSON.parse(readFileSync(fileURLToPath(new URL(relative, import.meta.url)), "utf8"));

const fixture = (name) => readJson(`../fixtures/recovery/${name}.json`);
const governanceObjectives = () => readJson("../../docs/operations/recovery-objectives.json");
const tree = () => fixture("source-tree");

describe("backup plan safety and provenance", () => {
  it("covers database, media, and config in a fixed order", () => {
    const plan = buildBackupPlan(fixture("objectives-approved"));

    expect(plan.stages.map((stage) => stage.id)).toEqual([
      "backup-database",
      "backup-media",
      "backup-config",
      "encrypt-artifact",
      "verify-artifact",
    ]);
    for (const stage of plan.stages) {
      expect(stage.command.length).toBeGreaterThan(0);
    }
  });

  it("carries the documented retention window and the governance owner role", () => {
    const plan = buildBackupPlan(fixture("objectives-approved"));

    expect(plan.retention).toEqual({
      dailySnapshots: 14,
      weeklySnapshots: 8,
      monthlySnapshots: 12,
      offsiteCopies: 1,
    });
    expect(plan.owner.role).toBe("deployer");
    expect(plan.owner.assignee).toBe("fixture-deployer");
  });

  it("reports the unassigned governance owner and missing key as blockers, never as defaults", () => {
    const plan = buildBackupPlan(governanceObjectives());

    expect(plan.owner.assignee).toBeNull();
    const ids = plan.blockers.map((blocker) => blocker.id);
    expect(ids).toContain("backup-encryption-key-unassigned");
    expect(ids).toContain("backup-owner-unassigned");
    expect(plan.ready).toBe(false);
  });

  it("refuses to describe an unencrypted artifact", () => {
    const config = fixture("objectives-approved");
    config.encryption.recipientKeyId = null;

    const plan = buildBackupPlan(config);

    expect(plan.ready).toBe(false);
    expect(plan.blockers.map((blocker) => blocker.id)).toContain(
      "backup-encryption-key-unassigned",
    );
  });
});

describe("backup -> restore -> hash comparison cycle", () => {
  it("produces per-item hashes and a manifest digest for every component", () => {
    const artifact = createBackup(tree(), fixture("objectives-approved"));

    expect(Object.keys(artifact.components).sort()).toEqual(["config", "database", "media"]);
    expect(Object.keys(artifact.components.database.items)).toEqual(Object.keys(tree().records));
    for (const item of Object.values(artifact.components.media.items)) {
      expect(item.sha256).toMatch(/^[0-9a-f]{64}$/);
    }
    expect(artifact.manifestDigest).toMatch(/^[0-9a-f]{64}$/);
    expect(artifact.encryption.status).toBe("encrypted");
  });

  it("is deterministic for the same tree", () => {
    const first = createBackup(tree(), fixture("objectives-approved"));
    const second = createBackup(tree(), fixture("objectives-approved"));

    expect(second.manifestDigest).toBe(first.manifestDigest);
  });

  it("restores into a clean environment and reproduces the source tree exactly", () => {
    const artifact = createBackup(tree(), fixture("objectives-approved"));
    const restored = restoreBackup(artifact);

    expect(restored.findings).toEqual([]);
    expect(restored.tree.records).toEqual(tree().records);
    expect(restored.tree.files).toEqual(tree().files);
    expect(restored.tree.config).toEqual(tree().config);
  });

  it("verifies record, file, and config hashes against the source tree", () => {
    const artifact = createBackup(tree(), fixture("objectives-approved"));
    const report = verifyRestore(tree(), artifact);

    expect(report.verified).toBe(true);
    expect(report.findings).toEqual([]);
    expect(report.counts).toEqual({ records: 3, files: 2, config: 3 });
  });

  it("matches the committed healthy backup fixture digest", () => {
    const artifact = createBackup(tree(), fixture("objectives-approved"));

    expect(artifact.manifestDigest).toBe(fixture("healthy-backup").manifestDigest);
  });

  it("names the exact drifted record when the restored tree differs", () => {
    const artifact = createBackup(tree(), fixture("objectives-approved"));
    const drifted = tree();
    drifted.records["lps-source-0002"] = '{"slug":"about","locale":"en","title":"Edited"}';

    const report = verifyRestore(drifted, artifact);

    expect(report.verified).toBe(false);
    expect(report.findings.map((finding) => finding.item)).toContain("lps-source-0002");
    expect(report.findings[0].class).toBe("hash_mismatch");
  });
});

describe("failure fixtures refuse restore and name the offending item", () => {
  it("detects a corrupt backup payload by hash and names the record", () => {
    const restored = restoreBackup(fixture("corrupt-backup"));

    expect(restored.findings.length).toBeGreaterThan(0);
    const finding = restored.findings.find((entry) => entry.item === "lps-source-0002");
    expect(finding).toBeDefined();
    expect(finding.class).toBe("corrupt_payload");
    expect(finding.component).toBe("database");
    expect(restored.restorable).toBe(false);
  });

  it("detects missing media and names the exact file path", () => {
    const restored = restoreBackup(fixture("missing-media"));

    const finding = restored.findings.find(
      (entry) => entry.item === "uploads/2026/03/lps-seminar.jpg",
    );
    expect(finding).toBeDefined();
    expect(finding.class).toBe("missing_payload");
    expect(finding.component).toBe("media");
    expect(restored.restorable).toBe(false);
  });

  it("never reports an unrestorable backup as verified", () => {
    for (const name of ["corrupt-backup", "missing-media"]) {
      const report = verifyRestore(tree(), fixture(name));

      expect(report.verified).toBe(false);
      expect(report.findings.length).toBeGreaterThan(0);
    }
  });
});

describe("RPO/RTO objectives are read, never guessed", () => {
  it("emits a launch blocker for each unapproved objective in the governance config", () => {
    const evaluation = evaluateRecoveryObjectives(governanceObjectives());

    expect(evaluation.approved).toBe(false);
    expect(evaluation.launchBlockers.map((blocker) => blocker.id)).toEqual([
      "rpo-rto-unapproved:application-rollback",
      "rpo-rto-unapproved:content-revision-rollback",
    ]);
    for (const objective of evaluation.objectives) {
      expect(objective.rpoMinutes).toBeNull();
      expect(objective.rtoMinutes).toBeNull();
    }
  });

  it("accepts approved objectives verbatim from configuration", () => {
    const evaluation = evaluateRecoveryObjectives(fixture("objectives-approved"));

    expect(evaluation.approved).toBe(true);
    expect(evaluation.launchBlockers).toEqual([]);
    const application = evaluation.objectives.find(
      (objective) => objective.id === "application-rollback",
    );
    expect(application.rpoMinutes).toBe(60);
    expect(application.rtoMinutes).toBe(30);
    expect(application.approvedBy).toBe("fixture-administrator");
  });

  it("treats a numeric objective without an approval receipt as unapproved", () => {
    const config = fixture("objectives-approved");
    config.objectives[0].approval = {
      status: "unapproved",
      approverRole: "administrator",
      approvedBy: null,
      approvedAt: null,
    };

    const evaluation = evaluateRecoveryObjectives(config);

    expect(evaluation.approved).toBe(false);
    expect(evaluation.launchBlockers.map((blocker) => blocker.id)).toContain(
      "rpo-rto-unapproved:application-rollback",
    );
  });
});

describe("application and content rollback rehearsal", () => {
  const measured = [
    { objectiveId: "application-rollback", dataLossMinutes: 12, recoveryMinutes: 18 },
    { objectiveId: "content-revision-rollback", dataLossMinutes: 3, recoveryMinutes: 6 },
  ];

  it("passes each rehearsal that meets its approved RPO and RTO", () => {
    const report = rehearseRollback({ config: fixture("objectives-approved"), measured });

    expect(report.status).toBe("pass");
    expect(report.results.map((result) => result.objectiveId)).toEqual([
      "application-rollback",
      "content-revision-rollback",
    ]);
    for (const result of report.results) {
      expect(result.status).toBe("pass");
    }
  });

  it("fails the rehearsal that exceeds its approved RTO and names the objective", () => {
    const slow = [
      { objectiveId: "application-rollback", dataLossMinutes: 12, recoveryMinutes: 95 },
      { objectiveId: "content-revision-rollback", dataLossMinutes: 3, recoveryMinutes: 6 },
    ];

    const report = rehearseRollback({ config: fixture("objectives-approved"), measured: slow });

    expect(report.status).toBe("fail");
    const failed = report.results.find((result) => result.status === "fail");
    expect(failed.objectiveId).toBe("application-rollback");
    expect(failed.breaches).toContain("rto");
  });

  it("blocks rather than passes when the objective has no owner approval", () => {
    const report = rehearseRollback({ config: governanceObjectives(), measured });

    expect(report.status).toBe("blocked");
    expect(report.launchBlockers.map((blocker) => blocker.id)).toContain(
      "rpo-rto-unapproved:application-rollback",
    );
    for (const result of report.results) {
      expect(result.status).toBe("blocked");
    }
  });

  it("blocks an objective that was never rehearsed instead of assuming it passed", () => {
    const report = rehearseRollback({
      config: fixture("objectives-approved"),
      measured: [measured[0]],
    });

    expect(report.status).toBe("blocked");
    const content = report.results.find(
      (result) => result.objectiveId === "content-revision-rollback",
    );
    expect(content.status).toBe("blocked");
    expect(content.detail).toContain("no rehearsal measurement");
  });
});

describe("recovery CLI safety and exit codes", () => {
  it("refuses to mutate a target without both the env var and the confirmation flag", async () => {
    const { assertRecoveryExecutionAllowed } = await import("../../scripts/backup/recovery.mjs");

    expect(assertRecoveryExecutionAllowed({ env: {}, flags: {} })).toEqual({
      allowed: false,
      reason:
        "Set LPS_RECOVERY_TARGET to the target environment alias and pass --confirm-mutates-target before executing recovery stages.",
    });
    expect(
      assertRecoveryExecutionAllowed({ env: { LPS_RECOVERY_TARGET: "@staging" }, flags: {} })
        .allowed,
    ).toBe(false);
    expect(
      assertRecoveryExecutionAllowed({ env: {}, flags: { "confirm-mutates-target": true } })
        .allowed,
    ).toBe(false);
  });

  it("allows execution once the target and confirmation are both present", async () => {
    const { assertRecoveryExecutionAllowed } = await import("../../scripts/backup/recovery.mjs");

    expect(
      assertRecoveryExecutionAllowed({
        env: { LPS_RECOVERY_TARGET: "@staging" },
        flags: { "confirm-mutates-target": true },
      }),
    ).toEqual({ allowed: true, reason: "" });
  });

  it("exits zero only for a verified restore", async () => {
    const { restoreExitCode } = await import("../../scripts/backup/recovery.mjs");
    const artifact = createBackup(tree(), fixture("objectives-approved"));

    expect(restoreExitCode(verifyRestore(tree(), artifact))).toBe(0);
    expect(restoreExitCode(verifyRestore(tree(), fixture("corrupt-backup")))).toBe(1);
    expect(restoreExitCode(verifyRestore(tree(), fixture("missing-media")))).toBe(1);
  });

  it("exits non-zero for a blocked or failed rollback rehearsal", async () => {
    const { rollbackExitCode } = await import("../../scripts/backup/recovery.mjs");

    expect(
      rollbackExitCode(
        rehearseRollback({
          config: fixture("objectives-approved"),
          measured: [
            { objectiveId: "application-rollback", dataLossMinutes: 12, recoveryMinutes: 18 },
            { objectiveId: "content-revision-rollback", dataLossMinutes: 3, recoveryMinutes: 6 },
          ],
        }),
      ),
    ).toBe(0);
    expect(
      rollbackExitCode(
        rehearseRollback({
          config: governanceObjectives(),
          measured: [],
        }),
      ),
    ).toBe(1);
  });
});
