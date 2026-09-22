import { readFile } from "node:fs/promises";
import { afterAll, describe, expect, it } from "vitest";
import { cleanupFixtures, loadFixture } from "./fixtures.mjs";

/**
 * Task 3 — teaching entities, uniqueness, and history contracts.
 *
 * The canonical implementations live in PHP
 * (`includes/class-teachingcontracts.php`, `class-teachingmigrations.php`).
 * This suite validates the shared synthetic fixtures against the documented
 * normalization and identity rules and asserts the PHP contract surface that
 * later tasks depend on. The tiny normalization mirror below is asserted
 * against the PHP contract's documented behavior on fixed vectors only.
 */

const TEACHING_CONTRACTS =
  "wp-content/plugins/lps-content-model/includes/class-teachingcontracts.php";
const TEACHING_MIGRATIONS =
  "wp-content/plugins/lps-content-model/includes/class-teachingmigrations.php";
const POLICY = "wp-content/plugins/lps-content-model/includes/class-policy.php";
const BOOTSTRAP = "wp-content/plugins/lps-content-model/lps-content-model.php";

const FOLD = {
  á: "a",
  à: "a",
  â: "a",
  ã: "a",
  ä: "a",
  å: "a",
  é: "e",
  è: "e",
  ê: "e",
  ë: "e",
  í: "i",
  ì: "i",
  î: "i",
  ï: "i",
  ó: "o",
  ò: "o",
  ô: "o",
  õ: "o",
  ö: "o",
  ú: "u",
  ù: "u",
  û: "u",
  ü: "u",
  ç: "c",
  ñ: "n",
  ý: "y",
  ÿ: "y",
  ß: "ss",
  Á: "A",
  À: "A",
  Â: "A",
  Ã: "A",
  Ä: "A",
  Å: "A",
  É: "E",
  È: "E",
  Ê: "E",
  Ë: "E",
  Í: "I",
  Ì: "I",
  Î: "I",
  Ï: "I",
  Ó: "O",
  Ò: "O",
  Ô: "O",
  Õ: "O",
  Ö: "O",
  Ú: "U",
  Ù: "U",
  Û: "U",
  Ü: "U",
  Ç: "C",
  Ñ: "N",
  Ý: "Y",
};

function fold(value) {
  return [...value].map((char) => FOLD[char] ?? char).join("");
}

/** Mirrors TeachingContracts::normalize_key on documented vectors. */
function normalizeKey(value) {
  if (typeof value !== "string") return "";
  const folded = fold(value).toLowerCase();
  return folded
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/-{2,}/g, "-")
    .replace(/^-+|-+$/g, "");
}

const normalizeSectionKey = normalizeKey;
const normalizeTermCode = normalizeKey;
const normalizeCalendarKey = normalizeKey;

function termToken(calendarKey, termCode) {
  const calendar = normalizeCalendarKey(calendarKey);
  const code = normalizeTermCode(termCode);
  return calendar === "" || code === "" ? "" : `${code}-${calendar}`;
}

function offeringIdentity(course, term, section) {
  return `${course}|${term}|${normalizeSectionKey(section)}`;
}

afterAll(async () => {
  await cleanupFixtures();
});

describe("task-03: normalization mirror parity on fixed vectors", () => {
  it("folds, lowercases, and hyphenates section keys deterministically", () => {
    expect(normalizeSectionKey(" Turma  A ")).toBe("turma-a");
    expect(normalizeSectionKey("TURMA-A")).toBe("turma-a");
    expect(normalizeSectionKey("turma a")).toBe("turma-a");
    expect(normalizeSectionKey("Seção 1")).toBe("secao-1");
    expect(normalizeSectionKey("   ")).toBe("");
    expect(normalizeSectionKey(42)).toBe("");
  });

  it("builds calendar-qualified term tokens", () => {
    expect(termToken("semester", "2026.1")).toBe("2026-1-semester");
    expect(termToken("trimester", "2026.1")).toBe("2026-1-trimester");
    expect(termToken("", "2026.1")).toBe("");
  });
});

describe("task-03: teaching-calendar fixture conforms to the contracts", () => {
  it("keeps term tokens canonical, ordered, and unique across calendars", async () => {
    const calendar = await loadFixture("teaching-calendar");
    const calendarKeys = new Set(calendar.calendars.map((entry) => entry.key));

    for (const term of calendar.terms) {
      expect(calendarKeys.has(term.calendarKey)).toBe(true);
      expect(term.token).toBe(termToken(term.calendarKey, term.periodLabel));
      expect(term.startsOn <= term.endsOn).toBe(true);
    }
    const tokens = calendar.terms.map((term) => term.token);
    expect(new Set(tokens).size).toBe(tokens.length);
    const periodTypes = new Set(calendar.calendars.map((entry) => entry.periodType));
    expect(periodTypes.has("semester")).toBe(true);
    expect(periodTypes.has("trimester")).toBe(true);
  });

  it("keeps offering identities unique and exercises co-teaching and completed status", async () => {
    const calendar = await loadFixture("teaching-calendar");
    const termIds = new Set(calendar.terms.map((term) => term.id));
    const courseIds = new Set(calendar.courses.map((course) => course.id));
    const identities = new Set();
    const statuses = new Set();
    let coTaught = 0;

    for (const offering of calendar.offerings) {
      expect(termIds.has(offering.term)).toBe(true);
      expect(courseIds.has(offering.course)).toBe(true);
      expect(normalizeSectionKey(offering.section)).not.toBe("");
      const identity = offeringIdentity(offering.course, offering.term, offering.section);
      expect(identities.has(identity)).toBe(false);
      identities.add(identity);
      statuses.add(offering.temporalStatus);
      expect(offering.teachingTeam.length).toBeGreaterThanOrEqual(1);
      if (offering.teachingTeam.length > 1) coTaught += 1;
    }
    expect(statuses).toEqual(new Set(["current", "completed", "upcoming"]));
    expect(coTaught).toBeGreaterThanOrEqual(1);
  });

  it("keeps units and resources inside exactly one offering", async () => {
    const calendar = await loadFixture("teaching-calendar");
    const offeringIds = new Set(calendar.offerings.map((offering) => offering.id));
    const unitOffering = new Map(calendar.units.map((unit) => [unit.id, unit.offering]));

    for (const unit of calendar.units) {
      expect(offeringIds.has(unit.offering)).toBe(true);
      expect(normalizeKey(unit.anchor)).toBe(unit.anchor);
      expect(unit.position).toBeGreaterThanOrEqual(1);
    }
    for (const resource of calendar.resources) {
      expect(offeringIds.has(resource.offering)).toBe(true);
      if (resource.unit !== null) {
        expect(unitOffering.get(resource.unit)).toBe(resource.offering);
      }
    }
  });

  it("declares the failure cases the PHP contract must reject", async () => {
    const calendar = await loadFixture("teaching-calendar");
    const cases = new Map(calendar.invalidCases.map((entry) => [entry.id, entry]));

    expect(cases.get("invalid-date-order").expectError).toBe("lps_invalid_term_date_order");
    expect(cases.get("invalid-date-order").input.startsOn).toBe("2026-07-10");
    expect(cases.get("invalid-date-order").input.endsOn).toBe("2026-03-02");

    expect(cases.get("cross-offering-unit").expectError).toBe("lps_cross_offering_unit_reference");

    const collision = cases.get("conflicting-section-keys");
    expect(collision.expectError).toBe("lps_offering_identity_conflict");
    expect(normalizeSectionKey(collision.input.first)).toBe(
      normalizeSectionKey(collision.input.second),
    );
  });
});

describe("task-03: migration-plan fixture conforms to the contracts", () => {
  it("declares versioned additive plans and copy-forward cases", async () => {
    const fixture = await loadFixture("teaching-migration-plan");

    expect(fixture.schema.additiveOnly).toBe(true);
    expect(fixture.schema.dryRunMutates).toBe(false);
    expect(fixture.schema.tables).toEqual(["lps_term_registry", "lps_offering_registry"]);

    const fresh = fixture.plans.find((plan) => plan.id === "plan-fresh-install");
    expect(fresh.expectOk).toBe(true);
    expect(fresh.expectSteps).toEqual(["create_term_registry", "create_offering_registry"]);
    for (const plan of fixture.plans.filter((entry) => !entry.expectOk)) {
      expect(plan.expectErrors.length).toBeGreaterThanOrEqual(1);
    }

    const valid = fixture.copyForward.find((entry) => entry.id === "copy-valid");
    expect(valid.expectError).toBeNull();
    expect(valid.plan.creates_draft).toBe(true);
    expect(valid.plan.publishes).toBe(false);
    expect(valid.plan.team_reviewed).toBe(true);
    for (const reset of [
      "announcements",
      "deadlines",
      "release_times",
      "active_notices",
      "unreleased_resources",
    ]) {
      expect(valid.plan.resets).toContain(reset);
    }
    for (const versionId of valid.plan.selected_version_ids) {
      expect(valid.plan.cleared_version_ids).toContain(versionId);
    }
    const expectedErrors = new Set(
      fixture.copyForward
        .filter((entry) => entry.expectError !== null)
        .map((entry) => entry.expectError),
    );
    for (const code of [
      "lps_copy_forward_identity_collision",
      "lps_copy_forward_operation_id_required",
      "lps_copy_forward_team_review_required",
      "lps_copy_forward_reset_required",
      "lps_copy_forward_unreleased_version",
      "lps_copy_forward_publish_forbidden",
    ]) {
      expect(expectedErrors.has(code)).toBe(true);
    }
  });
});

describe("task-03: PHP contract surface", () => {
  it("names the five register_post_type calls explicitly", async () => {
    const source = await readFile(TEACHING_CONTRACTS, "utf8");
    for (const postType of ["lps_course", "lps_term", "lps_offering", "lps_unit", "lps_resource"]) {
      expect(source).toContain(`register_post_type( '${postType}'`);
    }
  });

  it("declares indexed uniqueness registries with unique keys", async () => {
    const source = await readFile(TEACHING_MIGRATIONS, "utf8");
    expect(source).toContain("lps_term_registry");
    expect(source).toContain("lps_offering_registry");
    expect(source).toContain("UNIQUE KEY identity_hash");
    expect(source).toContain("UNIQUE KEY term_token");
    expect(source).toContain("section_key");
    expect(source).toContain("calendar_key");
    expect(source).toContain("term_code");
  });

  it("keeps the dry-run free of storage access", async () => {
    const source = await readFile(TEACHING_MIGRATIONS, "utf8");
    const match = source.match(/public static function dry_run\([^)]*\): array \{([\s\S]*?)\n\t\}/);
    expect(match).not.toBeNull();
    const body = match[1];
    expect(body).not.toContain("$wpdb");
    expect(body).not.toContain("get_option");
    expect(body).not.toContain("update_option");
    expect(body).not.toContain("dbDelta");
    expect(body).toContain("'mutated'");
    expect(body).toContain("'preserves_existing_records'");
  });

  it("keeps the existing record identifier format and wires the bootstrap", async () => {
    const policy = await readFile(POLICY, "utf8");
    for (const prefix of ["course", "term", "offering", "unit", "resource"]) {
      expect(policy).toContain(prefix);
    }
    expect(policy).toMatch(
      /lps:\(page\|person\|organization\|research-area\|project\|publication\|news\|opportunity\|event\|redirect\|course\|term\|offering\|unit\|resource\)/,
    );

    const bootstrap = await readFile(BOOTSTRAP, "utf8");
    expect(bootstrap).toContain("class-teachingcontracts.php");
    expect(bootstrap).toContain("class-teachingmigrations.php");
  });
});
