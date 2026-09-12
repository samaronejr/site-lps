import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import {
  buildRehearsalStages,
  formatWorkbook,
  planMigration,
  reconcile,
  targetFromPlan,
} from "../../scripts/migration/rehearsal-core.mjs";

const fixture = (name) =>
  JSON.parse(
    readFileSync(
      fileURLToPath(new URL(`../fixtures/migration/${name}.json`, import.meta.url)),
      "utf8",
    ),
  );

const baseline = () => fixture("baseline");

describe("rehearsal stage scaffolding", () => {
  it("orders the full reset/import/reimport/export/crawl/reconcile flow", () => {
    const stages = buildRehearsalStages({ package: "dist/import-package.json" });

    expect(stages.map((stage) => stage.id)).toEqual([
      "reset",
      "import-dry-run",
      "import-apply",
      "reimport-apply",
      "export",
      "crawl",
      "reconcile",
    ]);
  });

  it("gives every stage a command, a transcript path, and a stop-on-failure contract", () => {
    const stages = buildRehearsalStages({ package: "dist/import-package.json" });

    for (const stage of stages) {
      expect(stage.command.length).toBeGreaterThan(0);
      expect(stage.transcript).toMatch(/^transcripts\/\d{2}-[a-z-]+\.log$/);
      expect(stage.stopOnFailure).toBe(true);
    }
    expect(new Set(stages.map((stage) => stage.transcript)).size).toBe(stages.length);
  });

  it("keeps the dry run ahead of the first apply", () => {
    const stages = buildRehearsalStages({ package: "dist/import-package.json" });
    const ids = stages.map((stage) => stage.id);

    expect(ids.indexOf("import-dry-run")).toBeLessThan(ids.indexOf("import-apply"));
    expect(stages.find((stage) => stage.id === "import-dry-run").command).toContain("dry-run");
    expect(stages.find((stage) => stage.id === "import-apply").command).not.toContain("dry-run");
  });
});

describe("happy-path planning and reconciliation", () => {
  it("creates every reviewed source record on a clean target", () => {
    const plan = planMigration({ corpus: baseline(), target: { records: {} } });

    expect(plan.status).toBe("ready");
    expect(plan.counts.create).toBe(baseline().records.length);
    expect(plan.counts.quarantine).toBe(0);
    expect(plan.writes).toHaveLength(baseline().records.length);
  });

  it("performs zero writes on the second apply of the same corpus", () => {
    const first = planMigration({ corpus: baseline(), target: { records: {} } });
    const second = planMigration({ corpus: baseline(), target: targetFromPlan(first, baseline()) });

    expect(second.status).toBe("ready");
    expect(second.writes).toEqual([]);
    expect(second.counts.unchanged).toBe(baseline().records.length);
    expect(second.counts.create + second.counts.update).toBe(0);
  });

  it("reconciles source, target, and disposition counts exactly", () => {
    const plan = planMigration({ corpus: baseline(), target: { records: {} } });
    const report = reconcile(plan, baseline());

    expect(report.balanced).toBe(true);
    expect(report.source).toBe(
      report.create + report.update + report.unchanged + report.quarantined,
    );
    expect(report.unresolved).toEqual([]);
  });

  it("groups reconciliation counts under canonical BCP47 locale tags", () => {
    const plan = planMigration({ corpus: baseline(), target: { records: {} } });
    const report = reconcile(plan, baseline());

    expect(Object.keys(report.locales).sort()).toEqual(["en", "pt-BR"]);
    for (const tag of Object.keys(report.locales)) {
      expect(tag).toMatch(/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|\d{3}))?$/);
    }
  });

  it("renders a workbook with one row per disposition", () => {
    const plan = planMigration({ corpus: baseline(), target: { records: {} } });
    const workbook = formatWorkbook(reconcile(plan, baseline()), plan);

    for (const record of baseline().records) {
      expect(workbook).toContain(record.sourceId);
    }
    expect(workbook).toContain("| source id |");
  });
});

describe("failure fixtures stop or quarantine without partial overwrite", () => {
  it("stops the whole run on a duplicate source id", () => {
    const plan = planMigration({ corpus: fixture("duplicate-source-id"), target: { records: {} } });

    expect(plan.status).toBe("stopped");
    expect(plan.stopReasons.map((reason) => reason.class)).toContain("duplicate_source_id");
    expect(plan.writes).toEqual([]);
  });

  it("stops the whole run on a redirect loop and names the cycle", () => {
    const plan = planMigration({ corpus: fixture("redirect-loop"), target: { records: {} } });

    expect(plan.status).toBe("stopped");
    const loop = plan.stopReasons.find((reason) => reason.class === "redirect_loop");
    expect(loop).toBeDefined();
    expect(loop.detail).toMatch(/\/pt\/laboratorio/);
    expect(plan.writes).toEqual([]);
  });

  it("quarantines a changed checksum instead of overwriting the reviewed target", () => {
    const corpus = fixture("changed-checksum");
    const plan = planMigration({ corpus, target: corpus.target });

    expect(plan.status).toBe("ready");
    const quarantined = plan.dispositions.filter((row) => row.action === "quarantine");
    expect(quarantined.map((row) => row.class)).toContain("changed_checksum");
    expect(plan.writes).not.toContain("lps-source-0001");
  });

  it("quarantines a record whose asset is unavailable", () => {
    const plan = planMigration({ corpus: fixture("unavailable-asset"), target: { records: {} } });

    const row = plan.dispositions.find((entry) => entry.sourceId === "lps-source-0002");
    expect(row.action).toBe("quarantine");
    expect(row.class).toBe("unavailable_asset");
    expect(plan.writes).not.toContain("lps-source-0002");
  });

  it("quarantines an ambiguous author instead of guessing", () => {
    const plan = planMigration({ corpus: fixture("ambiguous-author"), target: { records: {} } });

    const row = plan.dispositions.find((entry) => entry.sourceId === "lps-source-0003");
    expect(row.action).toBe("quarantine");
    expect(row.class).toBe("ambiguous_author");
    expect(row.blocker).not.toBe("");
    expect(plan.writes).not.toContain("lps-source-0003");
  });

  it("quarantines a record whose locale is not a BCP47 tag", () => {
    const corpus = baseline();
    corpus.records[0].locale = "portugues";

    const plan = planMigration({ corpus, target: { records: {} } });

    const row = plan.dispositions.find((entry) => entry.sourceId === corpus.records[0].sourceId);
    expect(row.action).toBe("quarantine");
    expect(row.class).toBe("invalid_locale");
  });

  it("keeps healthy siblings importable while a defective record is quarantined", () => {
    const plan = planMigration({ corpus: fixture("unavailable-asset"), target: { records: {} } });

    expect(plan.status).toBe("ready");
    expect(plan.writes.length).toBeGreaterThan(0);
    expect(plan.counts.quarantine).toBe(1);
  });

  it("gives every quarantined row an explicit unresolved blocker", () => {
    for (const name of ["unavailable-asset", "ambiguous-author"]) {
      const corpus = fixture(name);
      const plan = planMigration({ corpus, target: corpus.target ?? { records: {} } });
      const report = reconcile(plan, corpus);

      for (const row of report.unresolved) {
        expect(row.blocker.length).toBeGreaterThan(0);
        expect(row.class.length).toBeGreaterThan(0);
      }
      expect(report.unresolved.length).toBe(plan.counts.quarantine);
    }
  });

  it("never reports a stopped run as reconciled", () => {
    const plan = planMigration({ corpus: fixture("duplicate-source-id"), target: { records: {} } });
    const report = reconcile(plan, fixture("duplicate-source-id"));

    expect(report.balanced).toBe(false);
    expect(report.stopped).toBe(true);
  });
});

describe("rehearsal runner safety", () => {
  it("refuses to execute stages without an explicit staging target and confirmation", async () => {
    const { assertExecutionAllowed } = await import("../../scripts/migration/rehearsal.mjs");

    expect(assertExecutionAllowed({ env: {}, flags: {} })).toEqual({
      allowed: false,
      reason:
        "Set LPS_REHEARSAL_TARGET to the staging alias and pass --confirm-mutates-staging before executing rehearsal stages.",
    });
    expect(
      assertExecutionAllowed({ env: { LPS_REHEARSAL_TARGET: "@staging" }, flags: {} }).allowed,
    ).toBe(false);
    expect(
      assertExecutionAllowed({ env: {}, flags: { "confirm-mutates-staging": true } }).allowed,
    ).toBe(false);
  });

  it("allows execution once the target and confirmation are both present", async () => {
    const { assertExecutionAllowed } = await import("../../scripts/migration/rehearsal.mjs");

    expect(
      assertExecutionAllowed({
        env: { LPS_REHEARSAL_TARGET: "@staging" },
        flags: { "confirm-mutates-staging": true },
      }),
    ).toEqual({ allowed: true, reason: "" });
  });

  it("parses long-form flags into a disposition-safe record", async () => {
    const { parseCliArgs } = await import("../../scripts/migration/rehearsal.mjs");

    expect(
      parseCliArgs(["reconcile", "--corpus=a.json", "--out=b.md", "--confirm-mutates-staging"]),
    ).toEqual({
      command: "reconcile",
      flags: { corpus: "a.json", out: "b.md", "confirm-mutates-staging": true },
    });
  });
});

describe("rehearsal exit-code contract", () => {
  it("passes only when the corpus reconciles with zero quarantines", async () => {
    const { reconciliationExitCode } = await import("../../scripts/migration/rehearsal.mjs");
    const plan = planMigration({ corpus: baseline(), target: { records: {} } });

    expect(reconciliationExitCode(reconcile(plan, baseline()))).toBe(0);
  });

  it("fails the rehearsal while any record is quarantined", async () => {
    const { reconciliationExitCode } = await import("../../scripts/migration/rehearsal.mjs");
    const corpus = fixture("unavailable-asset");
    const plan = planMigration({ corpus, target: { records: {} } });

    expect(reconciliationExitCode(reconcile(plan, corpus))).toBe(1);
  });

  it("fails the rehearsal when the run stopped", async () => {
    const { reconciliationExitCode } = await import("../../scripts/migration/rehearsal.mjs");
    const corpus = fixture("redirect-loop");
    const plan = planMigration({ corpus, target: { records: {} } });

    expect(reconciliationExitCode(reconcile(plan, corpus))).toBe(1);
  });
});
