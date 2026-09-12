import { mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import {
  GOVERNANCE_FIXTURE_PATH,
  readGovernanceFixture,
  runGovernanceQa,
  validateGovernanceFixture,
} from "../../scripts/lib/governance.mjs";

describe("governance role and collection matrix", () => {
  it("supplies every required machine-consumed collection control", async () => {
    const fixture = await readGovernanceFixture();
    const { issues, matrix } = validateGovernanceFixture(fixture);

    expect(issues).toEqual([]);
    expect(matrix).toHaveLength(fixture.collections.length);
    for (const row of matrix) {
      expect(row.ownerRole).toBeTruthy();
      expect(row.sourcePriority.length).toBeGreaterThan(0);
      expect(row.reviewCadence).toBeTruthy();
      expect(row.translationObligation).toBeTruthy();
      expect(row.archiveRule).toBeTruthy();
      expect(row.correctionPath).toBeTruthy();
      expect(row.takedownPath).toBeTruthy();
    }
  });

  it("reports a missing owner, translation reviewer, and takedown route from a temporary fixture", async () => {
    const fixture = JSON.parse(await readFile(GOVERNANCE_FIXTURE_PATH, "utf8"));
    delete fixture.collections.find((collection) => collection.id === "project").ownerRole;
    delete fixture.collections.find((collection) => collection.id === "publication").translation
      .reviewerRole;
    delete fixture.collections.find((collection) => collection.id === "media-asset").correctionPath
      .takedown;

    const directory = await mkdtemp(join(tmpdir(), "lps-governance-"));
    const path = join(directory, "missing-controls.json");
    try {
      await writeFile(path, `${JSON.stringify(fixture)}\n`);
      const report = await runGovernanceQa(path);

      expect(report.status).toBe("failed");
      expect(report.issues.map((entry) => entry.code)).toEqual(
        expect.arrayContaining([
          "OWNER_ROLE_MISSING",
          "TRANSLATION_REVIEWER_MISSING",
          "TAKEDOWN_ROUTE_MISSING",
        ]),
      );
    } finally {
      await rm(directory, { force: true, recursive: true });
    }
  });
});
