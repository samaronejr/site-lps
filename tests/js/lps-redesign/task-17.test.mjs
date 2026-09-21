import { describe, expect, it } from "vitest";
import {
  buildImportPackage,
  readCorpus,
  recordImportable,
  validateCorpus,
} from "../../../scripts/lib/content-corpus.mjs";
import { planMigration, reconcile } from "../../../scripts/migration/rehearsal-core.mjs";

/**
 * Task 17 — reviewed launch corpus and media selections.
 *
 * The corpus validator is the admission gate for the launch corpus: every
 * record needs a traceable permitted source and accountable review, media
 * selections need documented decisions, launch blockers need owner roles and
 * required inputs, and the checked-in import package must not drift from the
 * authored corpus. The rehearsal planner quarantines inadmissible rows with
 * named classes instead of silently accepting them. The live
 * dry-run/apply/re-apply/export rehearsal against a real WordPress database
 * runs through `scripts/migration/launch-corpus-rehearsal.php` in the
 * dedicated Playground environment.
 */

const corpus = await readCorpus({
  corpusDir: "content/corpus",
  inventoryDir: "content/inventory",
});

function mutate(change) {
  const copy = structuredClone(corpus);
  change(copy);
  return validateCorpus(copy);
}

function codes(report) {
  return report.errors.map((error) => error.code);
}

function record(copy, id) {
  return copy.records.find((entry) => entry.inventoryRecord === id);
}

describe("task-17: launch corpus admission", () => {
  it("accepts the reviewed corpus with media selections and owned blockers", () => {
    const report = validateCorpus(corpus);
    expect(report.status).toBe("passed");
    expect(report.errors).toEqual([]);
    expect(report.counts.corpusRecords).toBe(12);
    expect(report.counts.migratedAssets).toBe(0);
  });

  it("records every inventoried asset with an explicit selection decision", () => {
    const selections = corpus.manifest.mediaPolicy.selections;
    expect(selections).toHaveLength(corpus.assets.length);
    for (const asset of corpus.assets) {
      const selection = selections.find((entry) => entry.asset === asset.id);
      expect(selection, asset.id).toBeDefined();
      expect(selection.decision).toBe("excluded-rights");
      expect(selection.reason.length).toBeGreaterThan(0);
    }
  });

  it("records missing real content as owned prerequisites, not silent gaps", () => {
    const blockers = corpus.manifest.launchBlockers;
    for (const blocker of blockers) {
      expect(blocker.ownerRole, blocker.id).toBeTruthy();
      expect(blocker.requiredInput, blocker.id).toBeTruthy();
    }
    const ids = blockers.map((entry) => entry.id);
    expect(ids).toContain("course-catalog-and-calendar-source-not-supplied");
    expect(ids).toContain("media-rights-and-credit-undocumented");
    const report = validateCorpus(corpus);
    const reported = report.launchBlockers.map((entry) => entry.id);
    expect(reported).toContain("course-catalog-and-calendar-source-not-supplied");
  });

  it("keeps the checked-in import package identical to the authored corpus", () => {
    expect(corpus.importPackage).not.toBeNull();
    const built = buildImportPackage(corpus);
    expect(JSON.stringify(corpus.importPackage)).toBe(JSON.stringify(built));
    expect(codes(validateCorpus(corpus))).not.toContain("lps_corpus_import_package_stale");
  });
});

describe("task-17: admission failure paths", () => {
  it("rejects a synthetic fixture record in the launch corpus", () => {
    const report = mutate((copy) => {
      record(copy, "record-001").synthetic = true;
    });
    expect(codes(report)).toContain("lps_corpus_synthetic_record");
  });

  it("rejects a record whose provenance points at a fixture path", () => {
    const report = mutate((copy) => {
      record(copy, "record-001").governance.provenance.reverification.evidenceFile =
        "tests/fixtures/lps-redesign/people.json";
    });
    expect(codes(report)).toContain("lps_corpus_synthetic_record");
  });

  it("rejects a legacy-scraped source URL on an active record", () => {
    const report = mutate((copy) => {
      const entry = record(copy, "record-001");
      entry.governance.provenance.sourceUrl =
        "https://web.archive.org/web/2002/http://www.lps.ufrj.br/";
    });
    expect(codes(report)).toContain("lps_corpus_legacy_scrape_source");
    expect(codes(report)).toContain("lps_corpus_source_url_mismatch");
  });

  it("rejects a teaching record without an authoritative catalog source", () => {
    const report = mutate((copy) => {
      const entry = record(copy, "record-001");
      entry.collection = "course";
      entry.postType = "lps_course";
    });
    expect(codes(report)).toContain("lps_corpus_catalog_source_missing");
  });

  it("rejects an inventoried asset with no selection decision", () => {
    const report = mutate((copy) => {
      copy.manifest.mediaPolicy.selections = copy.manifest.mediaPolicy.selections.filter(
        (entry) => entry.asset !== "asset-003",
      );
    });
    expect(codes(report)).toContain("lps_corpus_media_selection_missing");
  });

  it("rejects a staged medium without reviewed alt text or credit", () => {
    const report = mutate((copy) => {
      copy.manifest.mediaPolicy.selections.push({
        asset: "asset-001",
        decision: "staged",
        reason: "Rights cleared by owner letter.",
      });
    });
    expect(codes(report)).toContain("lps_corpus_media_alt_missing");
    expect(codes(report)).toContain("lps_corpus_media_credit_missing");
  });

  it("rejects a launch blocker with no accountable owner or required input", () => {
    const report = mutate((copy) => {
      copy.manifest.launchBlockers.push({ id: "mystery-gap", status: "unresolved" });
    });
    expect(codes(report)).toContain("lps_corpus_blocker_owner_missing");
    expect(codes(report)).toContain("lps_corpus_blocker_input_missing");
  });

  it("rejects a hand-edited import package that drifts from the corpus", () => {
    const report = mutate((copy) => {
      copy.importPackage.records[0].title = "Hand-edited title";
    });
    expect(codes(report)).toContain("lps_corpus_import_package_stale");
  });

  it("refuses to build a package from an inadmissible record", () => {
    const copy = structuredClone(corpus);
    record(copy, "record-001").synthetic = true;
    expect(recordImportable(record(copy, "record-001"))).toBe(false);
    expect(() => buildImportPackage(copy)).toThrow(/lps_corpus_package_refused/);
  });
});

describe("task-17: rehearsal quarantine classes", () => {
  const base = {
    corpusId: "task-17-failure-package",
    records: [
      {
        sourceId: "ok-1",
        locale: "pt-BR",
        checksum: "sha256:ok",
        slug: "ok",
        sourceUrl: "https://sites.google.com/lps.ufrj.br/lps/in%C3%ADcio",
      },
    ],
    redirects: [],
  };

  it("quarantines a synthetic record with a named class", () => {
    const bad = {
      ...base,
      records: [...base.records, { ...base.records[0], sourceId: "syn-1", synthetic: true }],
    };
    const plan = planMigration({ corpus: bad, target: { records: {} } });
    const row = plan.dispositions.find((entry) => entry.sourceId === "syn-1");
    expect(row.action).toBe("quarantine");
    expect(row.class).toBe("synthetic_record");
    expect(row.blocker.length).toBeGreaterThan(0);
    const report = reconcile(plan, bad);
    expect(report.quarantined).toBe(1);
    expect(report.unresolved[0].class).toBe("synthetic_record");
  });

  it("quarantines a legacy-scrape source with a named class", () => {
    const plan = planMigration({
      corpus: {
        ...base,
        records: [
          ...base.records,
          {
            ...base.records[0],
            sourceId: "scrape-1",
            sourceUrl: "https://web.archive.org/web/2002/http://www.lps.ufrj.br/",
          },
        ],
      },
      target: { records: {} },
    });
    const row = plan.dispositions.find((entry) => entry.sourceId === "scrape-1");
    expect(row.action).toBe("quarantine");
    expect(row.class).toBe("legacy_scrape_source");
  });

  it("quarantines a teaching record without a catalog source", () => {
    const plan = planMigration({
      corpus: {
        ...base,
        records: [
          ...base.records,
          { ...base.records[0], sourceId: "course-1", type: "lps_course" },
        ],
      },
      target: { records: {} },
    });
    const row = plan.dispositions.find((entry) => entry.sourceId === "course-1");
    expect(row.action).toBe("quarantine");
    expect(row.class).toBe("catalog_source_missing");
  });
});
