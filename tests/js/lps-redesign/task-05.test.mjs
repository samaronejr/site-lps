import { readFile } from "node:fs/promises";
import { afterAll, describe, expect, it } from "vitest";
import { cleanupFixtures, loadFixture } from "./fixtures.mjs";

/**
 * Task 5 — unified native authoring, provenance, and teaching-language
 * publication.
 *
 * The canonical implementation lives in PHP
 * (`includes/class-publicationpolicy.php`, `class-publicationrecords.php`).
 * This suite validates the shared synthetic fixture against a documented
 * mirror of the origin and visibility rules, and asserts the PHP contract
 * surface that renderers, search, feeds, and previews all consume. The mirror
 * below is asserted on fixed vectors only; it is not the shipped logic.
 */

const PUBLICATION_POLICY =
  "wp-content/plugins/lps-content-model/includes/class-publicationpolicy.php";
const PUBLICATION_RECORDS =
  "wp-content/plugins/lps-content-model/includes/class-publicationrecords.php";
const TRANSLATION_POLICY =
  "wp-content/plugins/lps-content-model/includes/class-translationpolicy.php";
const TEACHING_CONTRACTS =
  "wp-content/plugins/lps-content-model/includes/class-teachingcontracts.php";
const IMPORT_REPOSITORY =
  "wp-content/plugins/lps-content-model/includes/class-importrepository.php";
const SEARCH_INDEX =
  "wp-content/plugins/lps-content-model/includes/class-searchindex.php";
const HOMEPAGE = "wp-content/themes/lps-theme/includes/class-homepage.php";
const SEO_ROUTES = "wp-content/themes/lps-theme/includes/class-seoroutes.php";
const BOOTSTRAP = "wp-content/plugins/lps-content-model/lps-content-model.php";

const PROVENANCE_KEYS = [
  "_lps_import_source_id",
  "_lps_import_source_url",
  "_lps_import_captured_at",
  "_lps_import_checksum",
  "_lps_import_rights",
  "_lps_import_review_state",
  "_lps_import_fingerprint",
  "_lps_import_reviewed_fields",
  "_lps_crossref_fields",
  "_lps_crossref_cache_key",
  "_lps_crossref_cached_at",
];

const ORIGIN = { NATIVE: "native", IMPORTED: "imported", AMBIGUOUS: "ambiguous" };

function text(value) {
  return typeof value === "string" ? value.trim() : "";
}

function hasImportProvenance(record) {
  return PROVENANCE_KEYS.some((key) => text(record[key]) !== "");
}

/** Mirrors PublicationPolicy::resolve_origin on documented vectors. */
function resolveOrigin(record) {
  if (hasImportProvenance(record)) return ORIGIN.IMPORTED;
  const claim = text(record._lps_origin);
  return claim === ORIGIN.NATIVE || claim === ORIGIN.IMPORTED
    ? claim
    : ORIGIN.AMBIGUOUS;
}

/** Mirrors PublicationPolicy::origin_write_error on documented vectors. */
function originWriteError(stored, candidate, record) {
  const next = text(candidate);
  if (next !== "" && next !== ORIGIN.NATIVE && next !== ORIGIN.IMPORTED) {
    return "lps_origin_invalid";
  }
  if (next === "" || next === text(stored)) return null;
  if (next === ORIGIN.NATIVE && hasImportProvenance(record)) {
    return "lps_origin_conflicts_provenance";
  }
  if (next === ORIGIN.IMPORTED && !hasImportProvenance(record)) {
    return "lps_origin_imported_requires_provenance";
  }
  return null;
}

/** Mirrors PublicationPolicy::reconciliation_class on documented vectors. */
function reconciliationClass(record) {
  const origin = resolveOrigin(record);
  if (origin === ORIGIN.AMBIGUOUS) return "ambiguous";
  if (origin === ORIGIN.IMPORTED && text(record._lps_origin) === ORIGIN.NATIVE) {
    return "conflict";
  }
  if (
    origin === ORIGIN.IMPORTED &&
    text(record._lps_import_review_state) !== "reviewed"
  ) {
    return "unreviewed-import";
  }
  return "none";
}

afterAll(async () => {
  await cleanupFixtures();
});

describe("task-05: origin mirror parity on fixed vectors", () => {
  it("resolves provenance over stored claims", async () => {
    const fixture = await loadFixture("publication-origin");
    for (const entry of fixture.originCases) {
      expect(resolveOrigin(entry.record), entry.id).toBe(entry.expectOrigin);
    }
  });

  it("never lets an unreviewed import be marked native", async () => {
    const fixture = await loadFixture("publication-origin");
    for (const entry of fixture.originWriteCases) {
      expect(
        originWriteError(entry.stored, entry.candidate, entry.record),
        entry.id,
      ).toBe(entry.expectError);
    }
  });

  it("classifies reconciliation rows without granting trust", async () => {
    const fixture = await loadFixture("publication-origin");
    for (const entry of fixture.reconciliationCases) {
      expect(reconciliationClass(entry.record), entry.id).toBe(
        entry.expectAction,
      );
      expect(resolveOrigin(entry.record), entry.id).toBe(entry.expectOrigin);
    }
  });
});

describe("task-05: visibility fixture is internally consistent", () => {
  it("keeps every case on a declared surface with a resolved origin", async () => {
    const fixture = await loadFixture("publication-origin");
    const surfaces = new Set(["public", "feature"]);
    for (const entry of fixture.visibilityCases) {
      expect(surfaces.has(entry.surface), entry.id).toBe(true);
      expect(resolveOrigin(entry.record), entry.id).toBe(entry.expectOrigin);
      for (const field of Object.keys(entry.expectErrors ?? {})) {
        expect(typeof field).toBe("string");
      }
    }
  });

  it("keeps a native record free of import provenance", async () => {
    const fixture = await loadFixture("publication-origin");
    for (const entry of fixture.visibilityCases) {
      if (entry.expectOrigin !== ORIGIN.NATIVE) continue;
      expect(hasImportProvenance(entry.record), entry.id).toBe(false);
    }
  });
});

describe("task-05: staleness fixture is field-specific", () => {
  it("only changes declared fields between base and changed records", async () => {
    const fixture = await loadFixture("publication-origin");
    for (const entry of fixture.stalenessCases) {
      const changed = { ...entry.base, ...entry.change };
      const changedKeys = Object.keys(changed).filter(
        (key) => changed[key] !== entry.base[key],
      );
      expect(changedKeys.sort(), entry.id).toEqual(
        Object.keys(entry.change).sort(),
      );
    }
  });
});

describe("task-05: PHP contract surface", () => {
  it("declares the origin constants and the two surfaces", async () => {
    const source = await readFile(PUBLICATION_POLICY, "utf8");
    for (const token of [
      "ORIGIN_NATIVE",
      "ORIGIN_IMPORTED",
      "ORIGIN_AMBIGUOUS",
      "SURFACE_PUBLIC",
      "SURFACE_FEATURE",
      "visibility_decision",
      "preview_decision",
      "reconciliation_class",
      "origin_write_error",
    ]) {
      expect(source).toContain(token);
    }
  });

  it("keeps the provenance key list complete", async () => {
    const source = await readFile(PUBLICATION_POLICY, "utf8");
    for (const key of PROVENANCE_KEYS) {
      expect(source).toContain(`'${key}'`);
    }
  });

  it("routes every public surface through the one decision", async () => {
    const homepage = await readFile(HOMEPAGE, "utf8");
    expect(homepage).toContain("PublicationPolicy::visibility_decision");
    expect(homepage).toContain("SURFACE_FEATURE");

    const search = await readFile(SEARCH_INDEX, "utf8");
    expect(search).toContain("PublicationPolicy::visibility_decision");
    expect(search).toContain("SURFACE_PUBLIC");

    const seo = await readFile(SEO_ROUTES, "utf8");
    expect(seo).toContain("PublicationPolicy::visibility_decision");
    expect(seo).toContain("PublicationPolicy::preview_decision");
  });

  it("stamps imported origin at the import boundary", async () => {
    const source = await readFile(IMPORT_REPOSITORY, "utf8");
    expect(source).toContain("$meta['_lps_origin']");
    expect(source).toContain("'imported'");
  });

  it("keeps teaching staleness field-specific", async () => {
    const policy = await readFile(TRANSLATION_POLICY, "utf8");
    expect(policy).toContain("TeachingContracts::localized_meta_keys");

    const teaching = await readFile(TEACHING_CONTRACTS, "utf8");
    expect(teaching).toContain("localized_meta_keys");
    expect(teaching).toContain("'_lps_origin'");
  });

  it("wires the new classes into the plugin bootstrap", async () => {
    const bootstrap = await readFile(BOOTSTRAP, "utf8");
    expect(bootstrap).toContain("class-publicationpolicy.php");
    expect(bootstrap).toContain("class-publicationrecords.php");

    const records = await readFile(PUBLICATION_RECORDS, "utf8");
    expect(records).toContain("publication_record");
    expect(records).toContain("origin_reconciliation");
  });
});
