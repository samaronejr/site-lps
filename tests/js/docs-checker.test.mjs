import { describe, expect, it } from "vitest";
import { checkDocumentation } from "../docs/docs-checker.mjs";

const ROOT = new URL("../../", import.meta.url).pathname.replace(/\/$/, "");
const FIXTURE_NOW = "2026-09-06T00:00:00Z";

/**
 * Runs the checker against one purpose-built defect fixture.
 *
 * @param {string} name Fixture directory name under tests/fixtures/docs.
 * @returns {Promise<import("../docs/docs-checker.mjs").DocumentationReport>}
 */
function runFixture(name) {
  return checkDocumentation({
    root: ROOT,
    contractPath: `tests/fixtures/docs/${name}/contract.json`,
    now: FIXTURE_NOW,
  });
}

/**
 * Returns the findings of one rule.
 *
 * @param {import("../docs/docs-checker.mjs").DocumentationReport} report Report.
 * @param {string} rule Rule id.
 */
function findings(report, rule) {
  return report.findings.filter((finding) => finding.rule === rule);
}

describe("documentation checker fixtures (RED cases)", () => {
  it("passes a fixture whose links, references, commands, prerequisites and claims are real", async () => {
    const report = await runFixture("passing");
    expect(report.findings).toEqual([]);
    expect(report.status).toBe("passed");
  });

  it("fails on a broken relative link and names the exact link target", async () => {
    const report = await runFixture("broken-link");
    const hits = findings(report, "broken-relative-link");
    expect(report.status).toBe("failed");
    expect(hits).toHaveLength(1);
    expect(hits[0].target).toBe("docs/architecture/runtim.md");
    expect(hits[0].doc).toBe("tests/fixtures/docs/broken-link/guide.md");
    expect(hits[0].line).toBeGreaterThan(0);
  });

  it("fails on a referenced file that does not exist", async () => {
    const report = await runFixture("missing-reference");
    const hits = findings(report, "missing-referenced-file");
    expect(report.status).toBe("failed");
    expect(hits).toHaveLength(1);
    expect(hits[0].target).toBe("scripts/absent-helper.mjs");
  });

  it("fails on a documented command that no command surface provides", async () => {
    const report = await runFixture("unknown-command");
    const hits = findings(report, "unknown-command");
    expect(report.status).toBe("failed");
    expect(hits).toHaveLength(1);
    expect(hits[0].command).toBe("node scripts/absent-tool.mjs");
    expect(hits[0].surface).toBe("node");
  });
});

describe("documentation checker required failure scenarios", () => {
  it("names the removed prerequisite and the guide that lost it", async () => {
    const report = await runFixture("removed-prerequisite");
    const hits = findings(report, "missing-prerequisite");
    expect(report.status).toBe("failed");
    expect(hits).toHaveLength(1);
    expect(hits[0].token).toBe("npm run env:start");
    expect(hits[0].doc).toBe("tests/fixtures/docs/removed-prerequisite/guide.md");
    expect(hits[0].message).toContain("## Prerequisites");
  });

  it("names the exact stale step for a changed command", async () => {
    const report = await runFixture("changed-command");
    const hits = findings(report, "unknown-command");
    expect(report.status).toBe("failed");
    expect(hits).toHaveLength(1);
    expect(hits[0].command).toBe("npm run qa:accessibility");
    expect(hits[0].surface).toBe("npm-script");
    expect(hits[0].step).toBe("Step 3 - run `npm run qa:accessibility` and attach the report.");
  });

  it("names the denied capability claimed by a role guide", async () => {
    const report = await runFixture("denied-capability");
    const hits = findings(report, "capability-claim-mismatch");
    expect(report.status).toBe("failed");
    expect(hits).toHaveLength(1);
    expect(hits[0].role).toBe("contributor");
    expect(hits[0].claimed).toContain("publish");
    expect(hits[0].message).toContain("SecurityPolicy");
  });

  it("names the expired translation entry and the source that moved under it", async () => {
    const report = await runFixture("expired-translation");
    const stale = findings(report, "stale-documentation");
    const expired = findings(report, "expired-documentation");
    expect(report.status).toBe("failed");
    expect(stale).toHaveLength(1);
    expect(stale[0].id).toBe("translation-en-editorial-fields");
    expect(stale[0].changedSources).toEqual([
      "wp-content/plugins/lps-content-model/includes/class-translationpolicy.php",
    ]);
    expect(expired).toHaveLength(1);
    expect(expired[0].id).toBe("translation-en-editorial-fields");
    expect(expired[0].kind).toBe("translation");
  });
});

describe("documentation checker coverage assertion", () => {
  it("reports a coverage item whose token appears in no document", async () => {
    const report = await runFixture("uncovered-token");
    const hits = findings(report, "undocumented-item");
    expect(report.status).toBe("failed");
    expect(hits).toHaveLength(1);
    expect(hits[0].id).toBe("setting-accessibility-contact");
    expect(hits[0].category).toBe("production-setting");
  });

  it("rejects a coverage item that its declared source does not actually contain", async () => {
    const report = await runFixture("unverifiable-item");
    const hits = findings(report, "unverifiable-coverage-item");
    expect(report.status).toBe("failed");
    expect(hits).toHaveLength(1);
    expect(hits[0].id).toBe("invented-collection");
  });
});

describe("documentation checker against the real documentation set", () => {
  it("passes with zero findings", async () => {
    const report = await checkDocumentation({
      root: ROOT,
      contractPath: "docs/documentation-contract.json",
      now: FIXTURE_NOW,
    });
    expect(report.findings).toEqual([]);
    expect(report.status).toBe("passed");
  });

  it("covers every required category from the real sources", async () => {
    const report = await checkDocumentation({
      root: ROOT,
      contractPath: "docs/documentation-contract.json",
      now: FIXTURE_NOW,
    });
    const categories = new Set(report.coverage.items.map((item) => item.category));
    for (const required of [
      "production-setting",
      "collection",
      "role",
      "recurring-task",
      "update-path",
      "recovery-action",
    ]) {
      expect(categories.has(required)).toBe(true);
    }
    expect(report.coverage.total).toBe(report.coverage.documented);
    expect(report.coverage.items.length).toBeGreaterThanOrEqual(40);
  });

  it("checks every authored document and reports sibling-owned docs as advisories only", async () => {
    const report = await checkDocumentation({
      root: ROOT,
      contractPath: "docs/documentation-contract.json",
      now: FIXTURE_NOW,
    });
    expect(report.documents).toContain("README.md");
    expect(report.documents.length).toBeGreaterThanOrEqual(14);
    for (const advisory of report.advisories) {
      expect(report.documents).not.toContain(advisory.doc);
    }
  });
});
