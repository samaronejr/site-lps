import { createHash } from "node:crypto";
import { readdir, readFile } from "node:fs/promises";
import { join } from "node:path";

const AUTHORITATIVE_LOCALE = "pt-br";
const TARGET_LOCALE = "en";
const REQUIRED_LOCALES = [AUTHORITATIVE_LOCALE, TARGET_LOCALE];
const POST_TYPES = new Set(["page", "lps_person", "lps_opportunity"]);
const RECORD_TYPE_SLUGS = {
  page: "page",
  lps_person: "person",
  lps_opportunity: "opportunity",
};
const CADENCES = {
  "site-settings": "P90D",
  page: "P180D",
  person: "P90D",
  opportunity: "P30D",
  redirect: "P365D",
};
const TEACHING_COLLECTIONS = new Set([
  "course",
  "term",
  "offering",
  "unit",
  "resource",
  "teaching",
]);
// Hosts that may appear as provenance evidence but never as the permitted
// source of an active launch record: the retired legacy LPS site and the
// Internet Archive. Archive-only inventory rows keep them legitimately.
const LEGACY_SCRAPE_HOSTS = new Set([
  "web.archive.org",
  "archive.org",
  "lps.ufrj.br",
  "www.lps.ufrj.br",
]);
const FIXTURE_PROVENANCE = /(?:^|\/)(?:tests\/fixtures|test-results)\//;
const MEDIA_DECISIONS = new Set([
  "excluded-rights",
  "excluded-privacy",
  "excluded-duplicate",
  "staged",
  "linked",
]);
const PLACEHOLDER_PATTERNS = [
  /lorem ipsum/i,
  /\bTBD\b/,
  /\bTBA\b/,
  /\bTODO\b/,
  /\bFIXME\b/,
  /placeholder/i,
  /\bxxx+/i,
  /\?\?\?/,
];
const SETTINGS_KEYS = [
  "official_name",
  "acronym",
  "parent_ufrj",
  "parent_coppe",
  "founded_year",
  "address",
  "official_website",
  "timezone",
  "public_contact",
  "privacy_contact",
  "accessibility_contact",
  "orcid_organization",
  "logo_id",
  "title_pt_br",
  "title_en",
  "tagline_pt_br",
  "tagline_en",
  "footer_pt_br",
  "footer_en",
];

/**
 * Parses RFC4180 CSV text into objects keyed by the header row.
 *
 * @param {string} text CSV bytes decoded as UTF-8.
 * @returns {Array<Record<string, string>>} Data rows.
 */
export function parseCsv(text) {
  const rows = [];
  let row = [];
  let cell = "";
  let quoted = false;
  for (let index = 0; index < text.length; index += 1) {
    const char = text[index];
    if (quoted) {
      if (char !== '"') {
        cell += char;
      } else if (text[index + 1] === '"') {
        cell += '"';
        index += 1;
      } else {
        quoted = false;
      }
      continue;
    }
    if (char === '"') {
      quoted = true;
    } else if (char === ",") {
      row.push(cell);
      cell = "";
    } else if (char === "\n") {
      row.push(cell);
      rows.push(row);
      row = [];
      cell = "";
    } else if (char !== "\r") {
      cell += char;
    }
  }
  if (cell !== "" || row.length > 0) {
    row.push(cell);
    rows.push(row);
  }
  const [header, ...data] = rows;
  return data
    .filter((entry) => entry.length === header.length)
    .map((entry) => Object.fromEntries(header.map((key, position) => [key, entry[position]])));
}

/**
 * Builds the deterministic record identifier used by the PHP import boundary.
 *
 * @param {string} typeSlug Domain type slug.
 * @param {string} identity Stable source identity.
 * @returns {string} Record identifier.
 */
export function recordId(typeSlug, identity) {
  const hash = createHash("sha256").update(identity).digest("hex");
  const uuid = [
    hash.slice(0, 8),
    hash.slice(8, 12),
    `4${hash.slice(13, 16)}`,
    `8${hash.slice(17, 20)}`,
    hash.slice(20, 32),
  ].join("-");
  return `lps:${typeSlug}:${uuid}`;
}

/**
 * Reads the authored corpus, its inventory, and its source evidence.
 *
 * @param {{corpusDir?: string, inventoryDir?: string}} options Directories.
 * @returns {Promise<object>} Corpus bundle.
 */
export async function readCorpus(options = {}) {
  const corpusDir = options.corpusDir ?? "content/corpus";
  const inventoryDir = options.inventoryDir ?? "content/inventory";
  const importDir = options.importDir ?? "content/import";
  const manifest = JSON.parse(await readFile(join(corpusDir, "manifest.json"), "utf8"));
  const siteSettings = JSON.parse(await readFile(join(corpusDir, "site-settings.json"), "utf8"));
  const records = await readJsonDirectory(join(corpusDir, "records"));
  const archive = await readJsonDirectory(join(corpusDir, "archive"));
  const inventory = parseCsv(await readFile(join(inventoryDir, "records.csv"), "utf8"));
  const assets = parseCsv(await readFile(join(inventoryDir, "assets.csv"), "utf8"));
  const evidence = {};
  for (const name of await readdir(join(corpusDir, "evidence"))) {
    if (name.endsWith(".txt")) {
      evidence[join(corpusDir, "evidence", name)] = await readFile(
        join(corpusDir, "evidence", name),
        "utf8",
      );
    }
  }
  let importPackage = null;
  try {
    importPackage = JSON.parse(await readFile(join(importDir, "launch-corpus.json"), "utf8"));
  } catch {
    importPackage = null;
  }
  return {
    manifest,
    siteSettings,
    records,
    archive,
    inventory,
    assets,
    evidence,
    importPackage,
    corpusDir,
    inventoryDir,
    importDir,
  };
}

async function readJsonDirectory(directory) {
  const names = (await readdir(directory)).filter((name) => name.endsWith(".json")).sort();
  const entries = [];
  for (const name of names) {
    entries.push(JSON.parse(await readFile(join(directory, name), "utf8")));
  }
  return entries;
}

function issue(code, path, detail) {
  return { code, path, detail };
}

function text(value) {
  return typeof value === "string" ? value : "";
}

function hostOf(value) {
  try {
    return new URL(text(value)).hostname.toLowerCase();
  } catch {
    return "";
  }
}

/**
 * Returns whether a corpus record is eligible for the launch import package.
 *
 * Synthetic records and records whose permitted source is a legacy/archive
 * host are provenance evidence, never launch content.
 *
 * @param {object} record Corpus record.
 * @returns {boolean} Eligibility.
 */
export function recordImportable(record) {
  const sourceUrl = record?.governance?.provenance?.sourceUrl;
  return record?.synthetic !== true && !LEGACY_SCRAPE_HOSTS.has(hostOf(sourceUrl));
}

function normalizeDigits(value) {
  return value.normalize("NFC").replace(/[.\u00a0\s]/g, "");
}

function normalizeSpaces(value) {
  return value
    .normalize("NFC")
    .replace(/[\s\u00a0]+/g, " ")
    .trim();
}

function localeText(variant) {
  return [text(variant?.title), text(variant?.excerpt), ...(variant?.content ?? [])].join("\n");
}

function isIsoDate(value) {
  return (
    typeof value === "string" &&
    /^\d{4}-\d{2}-\d{2}$/.test(value) &&
    !Number.isNaN(Date.parse(value))
  );
}

/**
 * Validates the authored corpus against inventory, governance, and translation policy.
 *
 * @param {object} corpus Corpus bundle from {@link readCorpus}.
 * @returns {object} Deterministic lane report.
 */
export function validateCorpus(corpus) {
  const errors = [];
  const inventoryById = new Map(corpus.inventory.map((row) => [row.id, row]));
  const migrateRows = corpus.inventory.filter((row) => row.disposition === "migrate");
  const archiveRows = corpus.inventory.filter((row) => row.freshness === "historical-archive-only");
  const linkRows = corpus.inventory.filter((row) => row.disposition === "link");

  const recordIds = new Set();
  for (const record of corpus.records) {
    const path = `records.${record.inventoryRecord}`;
    const row = inventoryById.get(record.inventoryRecord);
    if (!row) {
      errors.push(issue("lps_corpus_unknown_inventory_record", path, record.inventoryRecord));
      continue;
    }
    if (row.disposition !== "migrate") {
      errors.push(issue("lps_corpus_disposition_mismatch", path, row.disposition));
    }
    if (recordIds.has(record.inventoryRecord)) {
      errors.push(issue("lps_corpus_duplicate_record", path, record.inventoryRecord));
    }
    recordIds.add(record.inventoryRecord);
    validateRecord(record, row, corpus, errors, path);
  }
  for (const row of migrateRows) {
    if (!recordIds.has(row.id)) {
      errors.push(issue("lps_corpus_record_missing", `inventory.${row.id}`, "no authored record"));
    }
  }

  const archived = new Set();
  for (const entry of corpus.archive) {
    const path = `archive.${entry.inventoryRecord}`;
    const row = inventoryById.get(entry.inventoryRecord);
    if (!row) {
      errors.push(issue("lps_corpus_unknown_inventory_record", path, entry.inventoryRecord));
      continue;
    }
    archived.add(entry.inventoryRecord);
    validateArchive(entry, row, errors, path);
  }
  for (const row of archiveRows) {
    if (!archived.has(row.id)) {
      errors.push(issue("lps_corpus_archive_missing", `inventory.${row.id}`, "row not archived"));
    }
  }
  if (recordIds.size > 0) {
    for (const row of archiveRows) {
      if (recordIds.has(row.id)) {
        errors.push(
          issue(
            "lps_corpus_archived_row_published",
            `records.${row.id}`,
            "archive-only row authored as active record",
          ),
        );
      }
    }
  }

  const declaredLinks = new Set(
    (corpus.manifest.linkOnlyRows ?? []).map((entry) => entry.inventoryRecord),
  );
  for (const row of linkRows) {
    if (!declaredLinks.has(row.id)) {
      errors.push(
        issue("lps_corpus_link_row_undeclared", `inventory.${row.id}`, "link row not declared"),
      );
    }
    if (recordIds.has(row.id)) {
      errors.push(
        issue("lps_corpus_link_row_migrated", `records.${row.id}`, "link-only row copied"),
      );
    }
  }

  validateRedirectGraph(corpus.archive, errors);
  validateSiteSettings(corpus, errors);
  validateManifest(corpus, errors);
  validateImportPackageDrift(corpus, errors);

  const blockers = collectBlockers(corpus);
  const counts = {
    inventoryRows: corpus.inventory.length,
    inventoryMigrate: migrateRows.length,
    inventoryArchiveOnly: archiveRows.length,
    inventoryLinkOnly: linkRows.length,
    corpusRecords: corpus.records.length,
    localeVariants: corpus.records.length * REQUIRED_LOCALES.length,
    archivedRecords: corpus.archive.length,
    redirects: corpus.archive.length,
    relationships: corpus.records.reduce(
      (total, record) => total + (record.governance?.relationships?.length ?? 0),
      0,
    ),
    claims: corpus.records.reduce((total, record) => total + (record.claims?.length ?? 0), 0),
    migratedAssets: 0,
  };
  return {
    lane: "content",
    status: errors.length === 0 ? "passed" : "failed",
    schemaVersion: 1,
    counts,
    reconciliation: {
      source: {
        inventoryMigrateRows: migrateRows.length,
        inventoryArchiveOnlyRows: archiveRows.length,
      },
      target: {
        importRecords: counts.localeVariants,
        redirectRecords: counts.redirects,
        totalTargetRecords: counts.localeVariants + counts.redirects,
        relationships: counts.relationships,
      },
      balanced:
        migrateRows.length === corpus.records.length &&
        archiveRows.length === corpus.archive.length,
    },
    localePairs: {
      required: REQUIRED_LOCALES,
      complete: corpus.records.every((record) =>
        REQUIRED_LOCALES.every((locale) => localeText(record.locales?.[locale]).trim() !== ""),
      ),
      englishReviewed: corpus.records.filter(
        (record) => record.locales?.en?.translation?.reviewedBy !== null,
      ).length,
    },
    errorCount: errors.length,
    errors,
    launchBlockers: blockers,
  };
}

function validateRecord(record, row, corpus, errors, path) {
  const governance = record.governance ?? {};
  if (!POST_TYPES.has(record.postType)) {
    errors.push(issue("lps_corpus_post_type_invalid", `${path}.postType`, record.postType));
  }
  if (text(governance.ownerRole) === "") {
    errors.push(issue("lps_corpus_owner_missing", `${path}.governance.ownerRole`, "empty"));
  }
  if (CADENCES[record.collection] !== governance.reviewCadence) {
    errors.push(
      issue(
        "lps_corpus_review_cadence_mismatch",
        `${path}.governance.reviewCadence`,
        governance.reviewCadence,
      ),
    );
  }
  if (!isIsoDate(governance.lastReviewedAt) || !isIsoDate(governance.nextReviewDate)) {
    errors.push(
      issue("lps_corpus_review_date_invalid", `${path}.governance`, "review dates required"),
    );
  } else if (Date.parse(governance.nextReviewDate) <= Date.parse(governance.lastReviewedAt)) {
    errors.push(
      issue(
        "lps_corpus_review_date_invalid",
        `${path}.governance.nextReviewDate`,
        "not after last review",
      ),
    );
  }
  if (text(governance.reviewState) === "") {
    errors.push(
      issue("lps_corpus_review_state_missing", `${path}.governance.reviewState`, "empty"),
    );
  }
  if (governance.editorialState !== "draft" && governance.editorialState !== "published") {
    errors.push(
      issue(
        "lps_corpus_editorial_state_invalid",
        `${path}.governance.editorialState`,
        governance.editorialState,
      ),
    );
  }
  if (
    governance.editorialState === "published" &&
    (governance.publishGate?.blockers ?? []).length > 0
  ) {
    errors.push(
      issue(
        "lps_corpus_published_with_blockers",
        `${path}.governance.publishGate`,
        "unresolved blockers",
      ),
    );
  }
  const rights = governance.rights ?? {};
  if (rights.state !== row.rights_privacy_state) {
    errors.push(
      issue("lps_corpus_rights_mismatch", `${path}.governance.rights.state`, rights.state),
    );
  }
  if (text(rights.basis) === "") {
    errors.push(
      issue("lps_corpus_rights_basis_missing", `${path}.governance.rights.basis`, "empty"),
    );
  }
  if ((rights.assetsIncluded ?? []).length > 0) {
    errors.push(
      issue(
        "lps_corpus_rights_unknown_asset",
        `${path}.governance.rights.assetsIncluded`,
        "no asset is rights-cleared",
      ),
    );
  }
  if (rights.personalDataPublished !== false) {
    errors.push(
      issue(
        "lps_corpus_personal_data_published",
        `${path}.governance.rights`,
        "no legal basis documented",
      ),
    );
  }

  const provenance = governance.provenance ?? {};
  if (provenance.sourceUrl !== row.source_url) {
    errors.push(
      issue(
        "lps_corpus_source_url_mismatch",
        `${path}.governance.provenance.sourceUrl`,
        provenance.sourceUrl,
      ),
    );
  }
  if (provenance.inventoryChecksum !== row.checksum) {
    errors.push(
      issue(
        "lps_corpus_checksum_mismatch",
        `${path}.governance.provenance.inventoryChecksum`,
        provenance.inventoryChecksum,
      ),
    );
  }
  if (provenance.inventoryCapturedAt !== row.captured_at) {
    errors.push(
      issue(
        "lps_corpus_captured_at_mismatch",
        `${path}.governance.provenance.inventoryCapturedAt`,
        provenance.inventoryCapturedAt,
      ),
    );
  }
  const evidenceFile = provenance.reverification?.evidenceFile;
  const evidence = corpus.evidence[evidenceFile];
  if (typeof evidence !== "string" || evidence.trim() === "") {
    errors.push(
      issue(
        "lps_corpus_evidence_missing",
        `${path}.governance.provenance.reverification`,
        evidenceFile,
      ),
    );
  }

  if (
    record.synthetic === true ||
    FIXTURE_PROVENANCE.test(text(provenance.sourceUrl)) ||
    FIXTURE_PROVENANCE.test(text(evidenceFile))
  ) {
    errors.push(
      issue(
        "lps_corpus_synthetic_record",
        path,
        "development fixture data must not enter the launch corpus",
      ),
    );
  }
  if (
    LEGACY_SCRAPE_HOSTS.has(hostOf(provenance.sourceUrl)) &&
    row.freshness !== "historical-archive-only"
  ) {
    errors.push(
      issue(
        "lps_corpus_legacy_scrape_source",
        `${path}.governance.provenance.sourceUrl`,
        provenance.sourceUrl,
      ),
    );
  }
  if (TEACHING_COLLECTIONS.has(record.collection) && text(provenance.catalogSourceUrl) === "") {
    errors.push(
      issue(
        "lps_corpus_catalog_source_missing",
        `${path}.governance.provenance.catalogSourceUrl`,
        "course-code/calendar facts require an authoritative catalog source",
      ),
    );
  }

  for (const relation of governance.relationships ?? []) {
    if (relation.type !== "related_record") {
      errors.push(
        issue("lps_corpus_relationship_type_invalid", `${path}.relationships`, relation.type),
      );
    }
    if (!["related", "featured", "context"].includes(relation.role)) {
      errors.push(
        issue("lps_corpus_relationship_role_invalid", `${path}.relationships`, relation.role),
      );
    }
    const target = corpus.records.find((entry) => entry.inventoryRecord === relation.target);
    if (!target) {
      errors.push(
        issue("lps_corpus_relationship_target_missing", `${path}.relationships`, relation.target),
      );
    }
  }

  validateLocales(record, corpus, errors, path);
  validateClaims(record, corpus, errors, path);
}

function validateLocales(record, corpus, errors, path) {
  for (const locale of REQUIRED_LOCALES) {
    const variant = record.locales?.[locale];
    if (!variant) {
      errors.push(
        issue("lps_corpus_locale_pair_incomplete", `${path}.locales.${locale}`, "variant missing"),
      );
      continue;
    }
    for (const field of ["title", "slug", "excerpt"]) {
      if (text(variant[field]).trim() === "") {
        errors.push(issue("lps_corpus_field_empty", `${path}.locales.${locale}.${field}`, "empty"));
      }
    }
    const paragraphs = variant.content ?? [];
    if (
      !Array.isArray(paragraphs) ||
      paragraphs.length === 0 ||
      paragraphs.some((entry) => text(entry).trim() === "")
    ) {
      errors.push(
        issue("lps_corpus_field_empty", `${path}.locales.${locale}.content`, "empty paragraph"),
      );
    }
    const body = localeText(variant);
    for (const pattern of PLACEHOLDER_PATTERNS) {
      if (pattern.test(body)) {
        errors.push(
          issue("lps_corpus_placeholder_content", `${path}.locales.${locale}`, String(pattern)),
        );
      }
    }
    if (/https?:\/\/\S+\.pdf\b/i.test(body) && text(record.accessibleAlternative).trim() === "") {
      errors.push(
        issue(
          "lps_corpus_pdf_only_essential_content",
          `${path}.locales.${locale}`,
          "an essential PDF needs a sourced accessible HTML equivalent",
        ),
      );
    }
    validateNumericClaims(record, corpus, errors, `${path}.locales.${locale}`, body);
  }

  const source = record.locales?.[AUTHORITATIVE_LOCALE];
  const target = record.locales?.[TARGET_LOCALE];
  if (source && target) {
    if (localeText(source).trim() === localeText(target).trim()) {
      errors.push(
        issue(
          "lps_corpus_untranslated_variant",
          `${path}.locales.en`,
          "identical to Portuguese source",
        ),
      );
    }
    const translation = target.translation ?? {};
    if (translation.authoritativeLocale !== AUTHORITATIVE_LOCALE) {
      errors.push(
        issue(
          "lps_corpus_translation_authority_missing",
          `${path}.locales.en.translation`,
          translation.authoritativeLocale,
        ),
      );
    }
    if (text(translation.method) === "") {
      errors.push(
        issue(
          "lps_corpus_translation_method_undeclared",
          `${path}.locales.en.translation.method`,
          "empty",
        ),
      );
    }
    if (translation.machineTranslated !== false) {
      errors.push(
        issue(
          "lps_corpus_machine_translation_undeclared",
          `${path}.locales.en.translation`,
          "machine translation is not accepted",
        ),
      );
    }
    if (text(translation.reviewerRole) === "" || text(translation.reviewState) === "") {
      errors.push(
        issue(
          "lps_corpus_translation_review_missing",
          `${path}.locales.en.translation`,
          "reviewer role and state required",
        ),
      );
    }
    if (translation.reviewedBy !== null && record.governance?.editorialState !== "published") {
      errors.push(
        issue(
          "lps_corpus_translation_review_inconsistent",
          `${path}.locales.en.translation.reviewedBy`,
          String(translation.reviewedBy),
        ),
      );
    }
  }
}

function validateNumericClaims(record, corpus, errors, path, body) {
  const evidenceFile = record.governance?.provenance?.reverification?.evidenceFile;
  const evidence = normalizeDigits(text(corpus.evidence[evidenceFile]));
  for (const token of body.match(/\d[\d.]*\d|\d{2,}/g) ?? []) {
    const normalized = normalizeDigits(token);
    if (normalized.length < 2) {
      continue;
    }
    if (!evidence.includes(normalized)) {
      errors.push(issue("lps_corpus_unsupported_statistic", path, token));
    }
  }
}

function validateClaims(record, corpus, errors, path) {
  const claims = record.claims ?? [];
  if (claims.length === 0) {
    errors.push(issue("lps_corpus_claim_evidence_missing", `${path}.claims`, "no sourced claim"));
  }
  const evidenceFile = record.governance?.provenance?.reverification?.evidenceFile;
  const evidence = normalizeSpaces(text(corpus.evidence[evidenceFile]));
  claims.forEach((claim, index) => {
    const quote = normalizeSpaces(text(claim.evidenceQuote));
    if (quote === "" || !evidence.includes(quote)) {
      errors.push(
        issue("lps_corpus_claim_evidence_missing", `${path}.claims.${index}`, quote.slice(0, 60)),
      );
    }
    if (claim.sourceUrl !== record.governance?.provenance?.sourceUrl) {
      errors.push(
        issue("lps_corpus_claim_source_mismatch", `${path}.claims.${index}`, claim.sourceUrl),
      );
    }
  });
}

function validateArchive(entry, row, errors, path) {
  if (entry.governance?.editorialState !== "archived") {
    errors.push(
      issue(
        "lps_corpus_archive_state_invalid",
        `${path}.governance.editorialState`,
        entry.governance?.editorialState,
      ),
    );
  }
  if (entry.archive?.hardDeleted !== false) {
    errors.push(
      issue(
        "lps_corpus_archive_hard_deleted",
        `${path}.archive.hardDeleted`,
        "referenced records must not be deleted",
      ),
    );
  }
  if (text(entry.archive?.reason) === "") {
    errors.push(issue("lps_corpus_archive_reason_missing", `${path}.archive.reason`, "empty"));
  }
  if (entry.governance?.provenance?.inventoryChecksum !== row.checksum) {
    errors.push(
      issue(
        "lps_corpus_checksum_mismatch",
        `${path}.governance.provenance.inventoryChecksum`,
        entry.governance?.provenance?.inventoryChecksum,
      ),
    );
  }
  const decision = entry.archive?.successorDecision ?? {};
  if (!decision.source?.startsWith("/")) {
    errors.push(
      issue(
        "lps_corpus_redirect_source_invalid",
        `${path}.archive.successorDecision.source`,
        decision.source,
      ),
    );
  }
  if (decision.kind === "gone-410") {
    if (decision.target !== null || decision.status !== 410) {
      errors.push(
        issue(
          "lps_corpus_redirect_gone_invalid",
          `${path}.archive.successorDecision`,
          "410 must have no target",
        ),
      );
    }
  } else if (decision.kind === "redirect-301") {
    if (decision.status !== 301 || !text(decision.target).startsWith("/")) {
      errors.push(
        issue(
          "lps_corpus_redirect_target_invalid",
          `${path}.archive.successorDecision`,
          decision.target,
        ),
      );
    }
  } else {
    errors.push(
      issue(
        "lps_corpus_redirect_decision_invalid",
        `${path}.archive.successorDecision.kind`,
        decision.kind,
      ),
    );
  }
  if (text(decision.reason) === "") {
    errors.push(
      issue(
        "lps_corpus_redirect_reason_missing",
        `${path}.archive.successorDecision.reason`,
        "empty",
      ),
    );
  }
}

function validateRedirectGraph(archive, errors) {
  const sources = new Map();
  for (const entry of archive) {
    const decision = entry.archive?.successorDecision ?? {};
    const source = text(decision.source);
    if (sources.has(source)) {
      errors.push(
        issue("lps_corpus_redirect_duplicate_source", `archive.${entry.inventoryRecord}`, source),
      );
    }
    sources.set(source, text(decision.target));
  }
  for (const [source, target] of sources) {
    if (target !== "" && sources.has(target)) {
      errors.push(issue("lps_corpus_redirect_chain", `archive.${source}`, target));
    }
  }
}

function validateSiteSettings(corpus, errors) {
  const settings = corpus.siteSettings?.settings ?? {};
  for (const key of SETTINGS_KEYS) {
    if (!(key in settings)) {
      errors.push(
        issue("lps_corpus_site_setting_missing", `site-settings.settings.${key}`, "absent"),
      );
      continue;
    }
    if (settings[key] === null && text(corpus.siteSettings?.nullFieldReasons?.[key]) === "") {
      errors.push(
        issue(
          "lps_corpus_null_field_unexplained",
          `site-settings.nullFieldReasons.${key}`,
          "reason required",
        ),
      );
    }
  }
  for (const [index, claim] of (corpus.siteSettings?.claims ?? []).entries()) {
    const evidence = normalizeSpaces(text(corpus.evidence[claim.evidenceFile]));
    if (!evidence.includes(normalizeSpaces(text(claim.evidenceQuote)))) {
      errors.push(
        issue(
          "lps_corpus_claim_evidence_missing",
          `site-settings.claims.${index}`,
          text(claim.evidenceQuote).slice(0, 60),
        ),
      );
    }
  }
}

/**
 * Validates the manifest media selections and blocker accountability.
 *
 * Every inventoried asset needs a recorded selection decision; staged media
 * needs reviewed alt text (or an explicit decorative decision) and a credit
 * line. Every launch blocker names its accountable owner role and the missing
 * prerequisite input, so absent real content stays a tracked owner task.
 *
 * @param {object} corpus Corpus bundle.
 * @param {Array<object>} errors Issue sink.
 */
function validateManifest(corpus, errors) {
  const selections = corpus.manifest.mediaPolicy?.selections ?? [];
  const seen = new Set();
  for (const [index, selection] of selections.entries()) {
    const path = `manifest.mediaPolicy.selections.${index}`;
    const assetId = text(selection.asset);
    if (assetId === "" || seen.has(assetId)) {
      errors.push(issue("lps_corpus_media_selection_invalid", path, assetId));
    }
    seen.add(assetId);
    if (!MEDIA_DECISIONS.has(selection.decision)) {
      errors.push(
        issue("lps_corpus_media_selection_invalid", `${path}.decision`, selection.decision),
      );
    }
    if (text(selection.reason) === "") {
      errors.push(issue("lps_corpus_media_selection_reason_missing", `${path}.reason`, "empty"));
    }
    if (selection.decision === "staged") {
      if (text(selection.altText) === "" && selection.decorative !== true) {
        errors.push(
          issue(
            "lps_corpus_media_alt_missing",
            `${path}.altText`,
            "staged media needs reviewed alt text or an explicit decorative decision",
          ),
        );
      }
      if (text(selection.credit) === "") {
        errors.push(
          issue(
            "lps_corpus_media_credit_missing",
            `${path}.credit`,
            "staged media needs a documented credit line",
          ),
        );
      }
    }
  }
  for (const asset of corpus.assets ?? []) {
    if (!seen.has(asset.id)) {
      errors.push(
        issue(
          "lps_corpus_media_selection_missing",
          `inventory.${asset.id}`,
          "asset row has no recorded selection decision",
        ),
      );
    }
  }
  for (const [index, blocker] of (corpus.manifest.launchBlockers ?? []).entries()) {
    const path = `manifest.launchBlockers.${index}`;
    if (text(blocker.ownerRole) === "") {
      errors.push(
        issue(
          "lps_corpus_blocker_owner_missing",
          `${path}.ownerRole`,
          "every blocker names an accountable owner role",
        ),
      );
    }
    if (text(blocker.requiredInput) === "") {
      errors.push(
        issue(
          "lps_corpus_blocker_input_missing",
          `${path}.requiredInput`,
          "every blocker records the missing owner prerequisite",
        ),
      );
    }
  }
}

/**
 * Detects drift between the authored corpus and the checked-in import package.
 *
 * `content/import/launch-corpus.json` is a generated artifact; a byte-level
 * difference means it was edited by hand or built from another corpus.
 *
 * @param {object} corpus Corpus bundle.
 * @param {Array<object>} errors Issue sink.
 */
function validateImportPackageDrift(corpus, errors) {
  // A corpus that already fails validation needs no drift verdict; the
  // package builder legitimately refuses malformed records.
  if (corpus.importPackage === null || errors.length > 0) {
    return;
  }
  const expected = JSON.stringify(buildImportPackage(corpus));
  if (JSON.stringify(corpus.importPackage) !== expected) {
    errors.push(
      issue(
        "lps_corpus_import_package_stale",
        "content/import/launch-corpus.json",
        "regenerate with node scripts/build-import-package.mjs",
      ),
    );
  }
}

function collectBlockers(corpus) {
  const blockers = new Map();
  for (const blocker of corpus.manifest.launchBlockers ?? []) {
    blockers.set(blocker.id, { id: blocker.id, status: blocker.status, records: [] });
  }
  const attach = (id, reference) => {
    const existing = blockers.get(id) ?? { id, status: "unresolved", records: [] };
    existing.records.push(reference);
    blockers.set(id, existing);
  };
  for (const record of corpus.records) {
    for (const id of record.governance?.publishGate?.blockers ?? []) {
      attach(id, record.inventoryRecord);
    }
  }
  for (const id of corpus.siteSettings?.governance?.publishGate?.blockers ?? []) {
    attach(id, "site-settings");
  }
  return [...blockers.values()].sort((left, right) => left.id.localeCompare(right.id));
}

/**
 * Builds the deterministic WP-CLI import package from the authored corpus.
 *
 * @param {object} corpus Corpus bundle from {@link readCorpus}.
 * @returns {object} Import package in schema version 1.0.
 */
export function buildImportPackage(corpus) {
  const inventoryById = new Map(corpus.inventory.map((row) => [row.id, row]));
  const records = [];
  const identities = new Map();
  const refused = corpus.records.filter((record) => !recordImportable(record));
  if (refused.length > 0) {
    throw new Error(
      `lps_corpus_package_refused: ${refused
        .map((record) => record.inventoryRecord)
        .join(", ")} is not launch-corpus eligible`,
    );
  }
  for (const record of [...corpus.records].sort((left, right) =>
    left.inventoryRecord.localeCompare(right.inventoryRecord),
  )) {
    const row = inventoryById.get(record.inventoryRecord);
    for (const locale of REQUIRED_LOCALES) {
      const variant = record.locales[locale];
      const identity = `lps-corpus:${record.inventoryRecord}:${locale}`;
      const id = recordId(RECORD_TYPE_SLUGS[record.postType], identity);
      identities.set(`${record.inventoryRecord}:${locale}`, id);
      records.push({
        source_id:
          locale === AUTHORITATIVE_LOCALE ? record.inventoryRecord : `${record.inventoryRecord}#en`,
        type: record.postType,
        record_id: id,
        title: variant.title,
        slug: variant.slug,
        locale,
        state: record.governance.editorialState,
        source_url: row.source_url,
        captured_at: row.captured_at,
        checksum: row.checksum,
        rights: row.rights_privacy_state,
        review_state: record.governance.reviewState,
        reviewed_fields: [],
        excerpt: variant.excerpt,
        content: variant.content
          .map((paragraph) => `<!-- wp:paragraph -->\n<p>${paragraph}</p>\n<!-- /wp:paragraph -->`)
          .join("\n\n"),
        meta: {
          ...record.meta,
          _lps_review_date: record.governance.nextReviewDate,
          _lps_claim_verified: false,
          _lps_claim_source_url: row.source_url,
          _lps_claim_reviewed_at: record.governance.lastReviewedAt,
        },
      });
    }
  }
  const relationships = [];
  for (const record of corpus.records) {
    for (const relation of record.governance.relationships ?? []) {
      relationships.push({
        source: identities.get(`${record.inventoryRecord}:${AUTHORITATIVE_LOCALE}`),
        type: relation.type,
        target: identities.get(`${relation.target}:${AUTHORITATIVE_LOCALE}`),
        role: relation.role,
        order: relation.order,
      });
    }
  }
  const redirects = [...corpus.archive]
    .sort((left, right) => left.inventoryRecord.localeCompare(right.inventoryRecord))
    .map((entry) => {
      const decision = entry.archive.successorDecision;
      const gone = decision.kind === "gone-410";
      return {
        source: decision.source,
        target: gone ? "" : decision.target,
        gone,
        status: decision.status,
        reason: decision.reason,
        provenance: decision.provenance,
        captured_at: entry.governance.provenance.inventoryCapturedAt,
      };
    });
  return {
    schema_version: "1.0",
    records,
    relationships,
    authorships: [],
    media: [],
    redirects,
  };
}

/**
 * Returns the reciprocal Portuguese/English pairs for translation association.
 *
 * @param {object} corpus Corpus bundle.
 * @returns {Array<{record: string, "pt-br": string, en: string}>} Locale pairs.
 */
export function localePairs(corpus) {
  return [...corpus.records]
    .sort((left, right) => left.inventoryRecord.localeCompare(right.inventoryRecord))
    .map((record) => ({
      record: record.inventoryRecord,
      "pt-br": recordId(
        RECORD_TYPE_SLUGS[record.postType],
        `lps-corpus:${record.inventoryRecord}:pt-br`,
      ),
      en: recordId(RECORD_TYPE_SLUGS[record.postType], `lps-corpus:${record.inventoryRecord}:en`),
    }));
}
