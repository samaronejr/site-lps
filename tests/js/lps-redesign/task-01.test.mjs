import { access, readFile } from "node:fs/promises";
import path from "node:path";
import { afterAll, describe, expect, it } from "vitest";
import {
  attemptId,
  cleanupFixtures,
  fixtureNamespace,
  fixtureSlug,
  loadFixture,
  materializeFixture,
} from "./fixtures.mjs";

const ATTEMPT = attemptId();

afterAll(async () => {
  await cleanupFixtures();
});

describe("task-01 baseline: test discovery contract", () => {
  it("vitest discovery includes the lps-redesign namespace and fails on zero tests", async () => {
    // Given: the repository Vitest configuration.
    const config = await readFile("vitest.config.mjs", "utf8");

    // Then: the planned namespace is inside the include glob and a zero-test
    // run is a failure, never a silent pass.
    expect(config).toContain('include: ["tests/js/**/*.test.mjs"]');
    expect(config).toContain("passWithNoTests: false");
    expect("tests/js/lps-redesign/task-01.test.mjs").toMatch(/^tests\/js\/.*\.test\.mjs$/);
  });

  it("phpunit discovery covers the plugin suite and fails on an empty selection", async () => {
    const config = await readFile("phpunit.xml.dist", "utf8");

    expect(config).toContain("wp-content/plugins/lps-content-model/tests");
    expect(config).toContain('failOnEmptyTestSuite="true"');
  });

  it("playwright discovery covers the e2e lps-redesign namespace", async () => {
    const config = await readFile("playwright.config.mjs", "utf8");

    expect(config).toContain('testDir: "./tests/e2e"');
    await access(path.join("tests", "e2e", "lps-redesign", "task-01.spec.mjs"));
  });
});

describe("task-01 baseline: synthetic fixtures", () => {
  it("loads deterministic faculty and account fixtures that touch no real content", async () => {
    const faculty = await loadFixture("faculty");

    expect(faculty.synthetic).toBe(true);
    expect(faculty.people).toHaveLength(3);
    expect(faculty.accounts).toHaveLength(3);
    for (const record of [...faculty.people, ...faculty.accounts]) {
      expect(record.synthetic).toBe(true);
      expect(record.id).toMatch(/-synthetic-/);
    }
    // Accounts reference only fixture people; person records are not accounts.
    const peopleIds = new Set(faculty.people.map((person) => person.id));
    for (const account of faculty.accounts) {
      expect(peopleIds.has(account.person)).toBe(true);
    }
  });

  it("keeps calendar and offering inputs as data only, with task-8 persistence ownership", async () => {
    const calendar = await loadFixture("teaching-calendar");

    expect(calendar.persistence).toEqual({
      implementedBy: "task-08",
      assumedInBaseline: false,
    });
    const statuses = calendar.offerings.map((offering) => offering.temporalStatus);
    expect(statuses).toEqual(["current", "completed", "upcoming"]);
    // Two calendars share no term token; section keys stay per-offering.
    const tokens = calendar.terms.map((term) => term.token);
    expect(new Set(tokens).size).toBe(tokens.length);
  });

  it("materializes and cleans an attempt-namespaced fixture with stable IDs", async () => {
    // Given: the attempt namespace for this run.
    const first = await materializeFixture("faculty", "person-synthetic-ada");
    const second = await materializeFixture("faculty", "person-synthetic-ada");

    // Then: repeated materialization is deterministic and attempt-scoped.
    expect(first.file).toBe(second.file);
    expect(first.file).toContain(fixtureNamespace());
    expect(first.payload.attemptId).toBe(ATTEMPT);
    expect(fixtureSlug("person-synthetic-ada")).toBe(
      `lps-redesign-${ATTEMPT}-person-synthetic-ada`,
    );

    await cleanupFixtures();
    await expect(access(fixtureNamespace())).rejects.toThrow();
  });
});
