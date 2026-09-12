import { readFile } from "node:fs/promises";

export const GOVERNANCE_FIXTURE_PATH = "tests/fixtures/governance/role-collection-matrix.json";

const REQUIRED_ROLES = [
  "contributor",
  "translator",
  "section-editor",
  "publisher",
  "administrator",
  "privacy-auditor",
  "deployer",
];
const SOURCE_PRIORITIES = new Set([
  "authoritative-identifier-registry",
  "migration-inventory-record",
  "official-funder-or-partner-record",
  "official-lps-record",
  "official-organization-record",
  "official-organizer-record",
  "official-publisher-record",
  "official-ufrj-coppe-record",
  "rights-holder-record",
  "verified-primary-source",
]);
const TRANSLATION_OBLIGATIONS = new Set([
  "required",
  "optional",
  "not-localized",
  "localized-per-use",
]);
const ISO_DURATION = /^P(?:\d+D|\d+M|\d+Y)$/;

export async function readGovernanceFixture(path = GOVERNANCE_FIXTURE_PATH) {
  return JSON.parse(await readFile(path, "utf8"));
}

function issue(code, path, message) {
  return { code, path, message };
}

export function validateGovernanceFixture(fixture) {
  const issues = [];
  const roles = new Set(fixture.roles);

  if (fixture.schemaVersion !== 1) {
    issues.push(issue("SCHEMA_VERSION_INVALID", "schemaVersion", "schemaVersion must equal 1."));
  }
  for (const role of REQUIRED_ROLES) {
    if (!roles.has(role)) {
      issues.push(issue("REQUIRED_ROLE_MISSING", "roles", `Required role is missing: ${role}.`));
    }
  }

  const staffing = fixture.staffing ?? {};
  if (staffing.minimumAccounts?.publisher < 2) {
    issues.push(
      issue(
        "PUBLISHER_MINIMUM_INVALID",
        "staffing.minimumAccounts.publisher",
        "At least two publishers are required.",
      ),
    );
  }
  if (staffing.minimumAccounts?.administrator < 1) {
    issues.push(
      issue(
        "ADMINISTRATOR_MINIMUM_INVALID",
        "staffing.minimumAccounts.administrator",
        "At least one administrator is required.",
      ),
    );
  }
  for (const role of REQUIRED_ROLES.filter(
    (role) => role !== "publisher" && role !== "administrator",
  )) {
    if (staffing.minimumAccounts?.[role] < 1) {
      issues.push(
        issue(
          "ROLE_MINIMUM_INVALID",
          `staffing.minimumAccounts.${role}`,
          `At least one ${role} is required.`,
        ),
      );
    }
  }
  for (const role of ["publisher", "administrator"]) {
    if (!staffing.mfaRoles?.includes(role)) {
      issues.push(issue("MFA_ROLE_MISSING", "staffing.mfaRoles", `MFA is required for ${role}.`));
    }
  }
  if (staffing.sharedAccountsForbidden !== true) {
    issues.push(
      issue(
        "SHARED_ACCOUNTS_NOT_FORBIDDEN",
        "staffing.sharedAccountsForbidden",
        "Shared accounts must be forbidden.",
      ),
    );
  }
  if (staffing.contributorSelfPublishingForbidden !== true) {
    issues.push(
      issue(
        "CONTRIBUTOR_SELF_PUBLISHING_NOT_FORBIDDEN",
        "staffing.contributorSelfPublishingForbidden",
        "Contributor self-publishing must be forbidden.",
      ),
    );
  }
  if (staffing.independentTranslationReviewRequired !== true) {
    issues.push(
      issue(
        "INDEPENDENT_TRANSLATION_REVIEW_NOT_REQUIRED",
        "staffing.independentTranslationReviewRequired",
        "Independent translation review must be required.",
      ),
    );
  }

  const blockerIds = new Set(
    (fixture.launchBlockers ?? [])
      .filter((blocker) => blocker.status === "unresolved")
      .map((blocker) => blocker.id),
  );
  for (const blockerId of [
    "named-role-and-collection-assignments",
    "privacy-legal-bases",
    "public-correction-and-takedown-contact",
    "accessibility-contact",
  ]) {
    if (!blockerIds.has(blockerId)) {
      issues.push(
        issue(
          "UNRESOLVED_LAUNCH_BLOCKER_MISSING",
          "launchBlockers",
          `Required unresolved launch blocker is missing: ${blockerId}.`,
        ),
      );
    }
  }

  const seenCollections = new Set();
  for (const [index, collection] of (fixture.collections ?? []).entries()) {
    const path = `collections[${index}]`;
    if (!collection.id || seenCollections.has(collection.id)) {
      issues.push(
        issue("COLLECTION_ID_INVALID", `${path}.id`, "Collection id must be unique and non-empty."),
      );
    }
    seenCollections.add(collection.id);
    if (!roles.has(collection.ownerRole)) {
      issues.push(
        issue(
          "OWNER_ROLE_MISSING",
          `${path}.ownerRole`,
          "Collection owner role must be a declared role.",
        ),
      );
    }
    if (
      !Array.isArray(collection.sourcePriority) ||
      collection.sourcePriority.length === 0 ||
      collection.sourcePriority.some((source) => !SOURCE_PRIORITIES.has(source))
    ) {
      issues.push(
        issue(
          "SOURCE_PRIORITY_MISSING",
          `${path}.sourcePriority`,
          "Collection must declare ordered recognized source priorities.",
        ),
      );
    }
    if (
      typeof collection.reviewCadence !== "string" ||
      !ISO_DURATION.test(collection.reviewCadence)
    ) {
      issues.push(
        issue(
          "REVIEW_CADENCE_MISSING",
          `${path}.reviewCadence`,
          "Collection review cadence must be an ISO-8601 period.",
        ),
      );
    }
    if (typeof collection.expiryRule !== "string" || collection.expiryRule.length === 0) {
      issues.push(
        issue(
          "EXPIRY_RULE_MISSING",
          `${path}.expiryRule`,
          "Collection must declare an expiry rule.",
        ),
      );
    }
    const translation = collection.translation ?? {};
    if (!TRANSLATION_OBLIGATIONS.has(translation.obligation)) {
      issues.push(
        issue(
          "TRANSLATION_OBLIGATION_MISSING",
          `${path}.translation.obligation`,
          "Collection must declare a translation obligation.",
        ),
      );
    }
    if (translation.obligation === "not-localized") {
      if (translation.reviewerRole !== "not-applicable" || translation.reviewRequired !== false) {
        issues.push(
          issue(
            "TRANSLATION_REVIEWER_INVALID",
            `${path}.translation`,
            "Non-localized collections must use the explicit not-applicable review state.",
          ),
        );
      }
    } else if (!roles.has(translation.reviewerRole) || translation.reviewRequired !== true) {
      issues.push(
        issue(
          "TRANSLATION_REVIEWER_MISSING",
          `${path}.translation`,
          "Localized collections require a declared reviewer role and review gate.",
        ),
      );
    }
    if (typeof collection.archiveRule !== "string" || collection.archiveRule.length === 0) {
      issues.push(
        issue(
          "ARCHIVE_RULE_MISSING",
          `${path}.archiveRule`,
          "Collection must declare an archive rule.",
        ),
      );
    }
    if (
      typeof collection.correctionPath?.correction !== "string" ||
      collection.correctionPath.correction.length === 0
    ) {
      issues.push(
        issue(
          "CORRECTION_ROUTE_MISSING",
          `${path}.correctionPath.correction`,
          "Collection must declare a correction route.",
        ),
      );
    }
    if (
      typeof collection.correctionPath?.takedown !== "string" ||
      collection.correctionPath.takedown.length === 0
    ) {
      issues.push(
        issue(
          "TAKEDOWN_ROUTE_MISSING",
          `${path}.correctionPath.takedown`,
          "Collection must declare a takedown route.",
        ),
      );
    }
  }
  if (!Array.isArray(fixture.collections) || fixture.collections.length === 0) {
    issues.push(
      issue("COLLECTIONS_MISSING", "collections", "At least one content collection is required."),
    );
  }

  const matrix = [...(fixture.collections ?? [])]
    .sort((left, right) => left.id.localeCompare(right.id))
    .map((collection) => ({
      collection: collection.id,
      ownerRole: collection.ownerRole,
      sourcePriority: collection.sourcePriority,
      reviewCadence: collection.reviewCadence,
      expiryRule: collection.expiryRule,
      translationObligation: collection.translation?.obligation,
      translationReviewerRole: collection.translation?.reviewerRole,
      archiveRule: collection.archiveRule,
      correctionPath: collection.correctionPath?.correction,
      takedownPath: collection.correctionPath?.takedown,
    }));

  return { issues, matrix };
}

export async function runGovernanceQa(
  path = process.env.GOVERNANCE_FIXTURE ?? GOVERNANCE_FIXTURE_PATH,
) {
  const fixture = await readGovernanceFixture(path);
  const { issues, matrix } = validateGovernanceFixture(fixture);
  return {
    lane: "governance",
    status: issues.length === 0 ? "passed" : "failed",
    schemaVersion: fixture.schemaVersion,
    fixture: path,
    roleCollectionMatrix: matrix,
    launchReadiness: fixture.launchBlockers?.some((blocker) => blocker.status === "unresolved")
      ? "blocked"
      : "ready",
    launchBlockers: fixture.launchBlockers ?? [],
    issues,
  };
}
