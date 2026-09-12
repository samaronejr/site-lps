import { describe, expect, it } from "vitest";
import {
  buildImportPackage,
  readCorpus,
  validateCorpus,
} from "../../scripts/lib/content-corpus.mjs";

const corpus = await readCorpus({ corpusDir: "content/corpus", inventoryDir: "content/inventory" });

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

describe("launch corpus publish gate", () => {
  it("accepts the authored corpus and reconciles source and target counts", () => {
    const report = validateCorpus(corpus);
    expect(report.status).toBe("passed");
    expect(report.errors).toEqual([]);
    expect(report.counts.inventoryMigrate).toBe(12);
    expect(report.counts.corpusRecords).toBe(12);
    expect(report.counts.localeVariants).toBe(24);
    expect(report.counts.inventoryArchiveOnly).toBe(2);
    expect(report.reconciliation.balanced).toBe(true);
    expect(report.reconciliation.target.totalTargetRecords).toBe(26);
    expect(report.localePairs.complete).toBe(true);
  });

  it("reports unresolved launch blockers instead of guessing approvals", () => {
    const report = validateCorpus(corpus);
    const blockers = report.launchBlockers.map((entry) => entry.id);
    expect(blockers).toContain("named-role-and-collection-assignments");
    expect(blockers).toContain("translation-review-pending");
    expect(report.localePairs.englishReviewed).toBe(0);
  });

  it("builds a deterministic import package with unique record identifiers", () => {
    const first = buildImportPackage(corpus);
    const second = buildImportPackage(corpus);
    expect(JSON.stringify(first)).toBe(JSON.stringify(second));
    expect(first.records).toHaveLength(24);
    expect(first.relationships).toHaveLength(16);
    expect(first.redirects).toHaveLength(2);
    expect(first.media).toHaveLength(0);
    expect(new Set(first.records.map((entry) => entry.record_id)).size).toBe(24);
    expect(new Set(first.records.map((entry) => entry.source_id)).size).toBe(24);
    expect(first.records.every((entry) => entry.state === "draft")).toBe(true);
  });

  it("preserves the inventory checksum of every migrated record", () => {
    const built = buildImportPackage(corpus);
    for (const entry of built.records) {
      const id = entry.source_id.replace(/#en$/, "");
      const row = corpus.inventory.find((candidate) => candidate.id === id);
      expect(entry.checksum).toBe(row.checksum);
      expect(entry.source_url).toBe(row.source_url);
      expect(entry.captured_at).toBe(row.captured_at);
    }
  });
});

describe("publish gate failure paths", () => {
  it("rejects a record that publishes personal data without a legal basis", () => {
    const report = mutate((copy) => {
      record(copy, "record-010").governance.rights.personalDataPublished = true;
    });
    expect(codes(report)).toContain("lps_corpus_personal_data_published");
  });

  it("rejects a record whose source or evidence is missing", () => {
    const report = mutate((copy) => {
      const entry = record(copy, "record-002");
      entry.governance.provenance.sourceUrl = "";
      entry.governance.provenance.reverification.evidenceFile =
        "content/corpus/evidence/absent.txt";
    });
    expect(codes(report)).toContain("lps_corpus_source_url_mismatch");
    expect(codes(report)).toContain("lps_corpus_evidence_missing");
    expect(codes(report)).toContain("lps_corpus_claim_evidence_missing");
  });

  it("rejects an unsupported statistic that no source evidence backs", () => {
    const report = mutate((copy) => {
      record(copy, "record-001").locales["pt-br"].content.push(
        "O laboratório publica 4821 artigos por ano.",
      );
    });
    expect(codes(report)).toContain("lps_corpus_unsupported_statistic");
  });

  it("rejects a missing English variant of a required page", () => {
    const report = mutate((copy) => {
      delete record(copy, "record-002").locales.en;
    });
    expect(codes(report)).toContain("lps_corpus_locale_pair_incomplete");
    expect(validateCorpus(structuredClone(corpus)).localePairs.complete).toBe(true);
  });

  it("rejects an English variant that merely duplicates the Portuguese source", () => {
    const report = mutate((copy) => {
      const entry = record(copy, "record-005");
      entry.locales.en.title = entry.locales["pt-br"].title;
      entry.locales.en.excerpt = entry.locales["pt-br"].excerpt;
      entry.locales.en.content = [...entry.locales["pt-br"].content];
    });
    expect(codes(report)).toContain("lps_corpus_untranslated_variant");
  });

  it("rejects an undeclared machine translation", () => {
    const report = mutate((copy) => {
      const translation = record(copy, "record-004").locales.en.translation;
      translation.machineTranslated = true;
      translation.method = "";
    });
    expect(codes(report)).toContain("lps_corpus_machine_translation_undeclared");
    expect(codes(report)).toContain("lps_corpus_translation_method_undeclared");
  });

  it("rejects essential content that exists only as a PDF", () => {
    const report = mutate((copy) => {
      record(copy, "record-003").locales["pt-br"].content.push(
        "O regulamento está disponível apenas em https://lps.ufrj.br/regulamento.pdf.",
      );
    });
    expect(codes(report)).toContain("lps_corpus_pdf_only_essential_content");
  });

  it("rejects a rights-unknown media asset", () => {
    const report = mutate((copy) => {
      record(copy, "record-004").governance.rights.assetsIncluded = ["asset-001"];
    });
    expect(codes(report)).toContain("lps_corpus_rights_unknown_asset");
  });

  it("rejects a duplicated record and a tampered checksum", () => {
    const duplicate = mutate((copy) => {
      copy.records.push(structuredClone(record(copy, "record-001")));
    });
    expect(codes(duplicate)).toContain("lps_corpus_duplicate_record");
    const tampered = mutate((copy) => {
      record(copy, "record-001").governance.provenance.inventoryChecksum = "sha256:0";
    });
    expect(codes(tampered)).toContain("lps_corpus_checksum_mismatch");
  });

  it("rejects an archive-only legacy page republished as active content", () => {
    const report = mutate((copy) => {
      const clone = structuredClone(record(copy, "record-005"));
      clone.inventoryRecord = "record-015";
      copy.records.push(clone);
    });
    expect(codes(report)).toContain("lps_corpus_archived_row_published");
  });

  it("rejects a hard-deleted archive entry and a redirect chain", () => {
    const deleted = mutate((copy) => {
      copy.archive[0].archive.hardDeleted = true;
    });
    expect(codes(deleted)).toContain("lps_corpus_archive_hard_deleted");
    const chained = mutate((copy) => {
      copy.archive[0].archive.successorDecision = {
        kind: "redirect-301",
        source: "/publications/publications.html",
        target: "/projetos/research.html",
        status: 301,
        reason: "chain",
        provenance: "http://www.lps.ufrj.br/publications/publications.html",
      };
    });
    expect(codes(chained)).toContain("lps_corpus_redirect_chain");
  });

  it("rejects publication while publish-gate blockers remain unresolved", () => {
    const report = mutate((copy) => {
      record(copy, "record-001").governance.editorialState = "published";
    });
    expect(codes(report)).toContain("lps_corpus_published_with_blockers");
  });

  it("rejects a link-only inventory row copied into the corpus", () => {
    const report = mutate((copy) => {
      const clone = structuredClone(record(copy, "record-001"));
      clone.inventoryRecord = "record-012";
      copy.records.push(clone);
    });
    expect(codes(report)).toContain("lps_corpus_link_row_migrated");
  });

  it("rejects a stale review date and a missing owner role", () => {
    const report = mutate((copy) => {
      const entry = record(copy, "record-006");
      entry.governance.ownerRole = "";
      entry.governance.nextReviewDate = "2026-08-01";
    });
    expect(codes(report)).toContain("lps_corpus_owner_missing");
    expect(codes(report)).toContain("lps_corpus_review_date_invalid");
  });

  it("rejects a site setting that is null without a recorded reason", () => {
    const report = mutate((copy) => {
      copy.siteSettings.nullFieldReasons.privacy_contact = "";
    });
    expect(codes(report)).toContain("lps_corpus_null_field_unexplained");
  });
});
