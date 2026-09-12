/**
 * Deterministic migration rehearsal core.
 *
 * Pure planning, classification and reconciliation logic for the staging
 * rehearsal. Nothing in this module touches a network, a database, or a
 * running site, so every rule below is exercised by tests without a server.
 */

const REQUIRED_STAGES = [
  {
    id: "reset",
    description: "Restore the clean staging baseline before any import.",
    command: ["wp", "db", "reset", "--yes"],
  },
  {
    id: "import-dry-run",
    description: "Plan the import and report dispositions without writing.",
    command: ["wp", "lps", "import", "dry-run", "{package}"],
  },
  {
    id: "import-apply",
    description: "Apply the reviewed corpus to the clean baseline.",
    command: ["wp", "lps", "import", "{package}"],
  },
  {
    id: "reimport-apply",
    description: "Re-apply the identical corpus to prove zero additional writes.",
    command: ["wp", "lps", "import", "{package}"],
  },
  {
    id: "export",
    description: "Export the imported corpus for hash comparison.",
    command: ["wp", "lps", "export", "--output={exportPath}"],
  },
  {
    id: "crawl",
    description: "Crawl inventoried paths for 200 / one-hop 301 / 410 outcomes.",
    command: ["node", "scripts/run-qa.mjs", "links"],
  },
  {
    id: "reconcile",
    description: "Reconcile source, target, disposition counts and quarantine blockers.",
    command: ["node", "scripts/migration/rehearsal.mjs", "reconcile", "--corpus={package}"],
  },
];

const BCP47_INPUT = /^([a-z]{2,3})(?:-([a-z]{4}))?(?:-([a-z]{2}|\d{3}))?$/i;

/**
 * Builds the ordered rehearsal stage list with transcript destinations.
 *
 * @param {{package: string, exportPath?: string}} config Rehearsal configuration.
 * @returns {Array<{id: string, description: string, command: string[], transcript: string, stopOnFailure: boolean}>}
 */
export function buildRehearsalStages(config) {
  const packagePath = config?.package ?? "";
  const exportPath = config?.exportPath ?? "exports/rehearsal-export.json";
  return REQUIRED_STAGES.map((stage, index) => ({
    id: stage.id,
    description: stage.description,
    command: stage.command.map((token) =>
      token.replace("{package}", packagePath).replace("{exportPath}", exportPath),
    ),
    transcript: `transcripts/${String(index + 1).padStart(2, "0")}-${stage.id}.log`,
    stopOnFailure: true,
  }));
}

/**
 * Canonicalises a BCP47 language tag, or returns null when it is malformed.
 *
 * @param {unknown} tag Raw locale tag.
 * @returns {string|null} Canonical tag such as `pt-BR`.
 */
export function canonicalLocale(tag) {
  if (typeof tag !== "string") {
    return null;
  }
  const match = BCP47_INPUT.exec(tag.trim());
  if (match === null) {
    return null;
  }
  const [, language, script, region] = match;
  let canonical = language.toLowerCase();
  if (script !== undefined) {
    canonical += `-${script[0].toUpperCase()}${script.slice(1).toLowerCase()}`;
  }
  if (region !== undefined) {
    canonical += `-${/^\d+$/.test(region) ? region : region.toUpperCase()}`;
  }
  return canonical;
}

/**
 * Finds the first redirect cycle, including self-redirects.
 *
 * @param {Array<{from: string, to: string}>} redirects Redirect contract rows.
 * @returns {string[]|null} The cycle path, or null when the graph is acyclic.
 */
export function findRedirectCycle(redirects) {
  const edges = new Map();
  for (const redirect of redirects ?? []) {
    edges.set(redirect.from, redirect.to);
  }
  for (const start of edges.keys()) {
    const path = [];
    const seen = new Set();
    let node = start;
    while (edges.has(node)) {
      if (seen.has(node)) {
        return [...path.slice(path.indexOf(node)), node];
      }
      seen.add(node);
      path.push(node);
      node = edges.get(node);
    }
  }
  return null;
}

function classifyRecord(record, target, options) {
  const locale = canonicalLocale(record.locale);
  if (locale === null) {
    return {
      sourceId: record.sourceId,
      locale: null,
      action: "quarantine",
      class: "invalid_locale",
      blocker: `Locale "${String(record.locale)}" is not a well-formed BCP47 tag.`,
    };
  }

  const missingAsset = (record.assets ?? []).find((asset) => asset.available !== true);
  if (missingAsset !== undefined) {
    return {
      sourceId: record.sourceId,
      locale,
      action: "quarantine",
      class: "unavailable_asset",
      blocker: `Asset ${missingAsset.id} is unavailable at the source.`,
    };
  }

  const ambiguousAuthor = (record.authors ?? []).find(
    (author) => (author.matches ?? []).length !== 1,
  );
  if (ambiguousAuthor !== undefined) {
    const matches = ambiguousAuthor.matches ?? [];
    return {
      sourceId: record.sourceId,
      locale,
      action: "quarantine",
      class: matches.length === 0 ? "unresolved_author" : "ambiguous_author",
      blocker: `Author "${ambiguousAuthor.name}" resolves to ${matches.length} people (${matches.join(", ") || "none"}).`,
    };
  }

  const existing = target?.records?.[record.sourceId];
  if (existing === undefined) {
    return {
      sourceId: record.sourceId,
      locale,
      action: "create",
      class: null,
      blocker: "",
    };
  }
  if (existing.importedChecksum === record.checksum) {
    return {
      sourceId: record.sourceId,
      locale,
      action: "unchanged",
      class: null,
      blocker: "",
    };
  }
  if (options?.acceptSourceChanges === true) {
    return {
      sourceId: record.sourceId,
      locale,
      action: "update",
      class: null,
      blocker: "",
    };
  }
  return {
    sourceId: record.sourceId,
    locale,
    action: "quarantine",
    class: "changed_checksum",
    blocker: `Source checksum ${record.checksum} differs from the reviewed checksum ${existing.importedChecksum}; an explicit disposition with provenance is required.`,
  };
}

function emptyCounts() {
  return { create: 0, update: 0, unchanged: 0, quarantine: 0, blocked: 0 };
}

/**
 * Plans one import apply against a known target state.
 *
 * Run-wide integrity failures stop the run before any write is planned, so a
 * defective corpus can never produce a partial overwrite.
 *
 * @param {{corpus: object, target?: object, options?: object}} input Planning input.
 * @returns {{status: string, stopReasons: object[], dispositions: object[], writes: string[], counts: object}}
 */
export function planMigration(input) {
  const corpus = input?.corpus ?? { records: [] };
  const target = input?.target ?? { records: {} };
  const options = input?.options ?? {};
  const records = corpus.records ?? [];

  const stopReasons = [];
  const occurrences = new Map();
  for (const record of records) {
    occurrences.set(record.sourceId, (occurrences.get(record.sourceId) ?? 0) + 1);
  }
  for (const [sourceId, count] of occurrences) {
    if (count > 1) {
      stopReasons.push({
        class: "duplicate_source_id",
        sourceId,
        detail: `Source id ${sourceId} appears ${count} times in the corpus; identity is ambiguous.`,
      });
    }
  }

  const cycle = findRedirectCycle(corpus.redirects ?? []);
  if (cycle !== null) {
    stopReasons.push({
      class: "redirect_loop",
      sourceId: null,
      detail: `Redirect cycle detected: ${cycle.join(" -> ")}.`,
    });
  }

  const dispositions = records.map((record) => classifyRecord(record, target, options));
  const counts = emptyCounts();

  if (stopReasons.length > 0) {
    const summary = stopReasons.map((reason) => reason.detail).join(" ");
    const blocked = dispositions.map((row) => ({
      ...row,
      action: "blocked",
      class: stopReasons[0].class,
      blocker: `Run stopped before any write. ${summary}`,
    }));
    counts.blocked = blocked.length;
    return { status: "stopped", stopReasons, dispositions: blocked, writes: [], counts };
  }

  for (const row of dispositions) {
    counts[row.action] += 1;
  }
  const writes = dispositions
    .filter((row) => row.action === "create" || row.action === "update")
    .map((row) => row.sourceId);

  return { status: "ready", stopReasons, dispositions, writes, counts };
}

/**
 * Builds the target state produced by applying a plan, for re-apply checks.
 *
 * @param {object} plan Plan returned by planMigration.
 * @param {object} corpus Corpus the plan was built from.
 * @returns {{records: Record<string, {importedChecksum: string, slug: string, locale: string|null}>}}
 */
export function targetFromPlan(plan, corpus) {
  const applied = new Set(plan.writes);
  const records = {};
  for (const record of corpus.records ?? []) {
    if (!applied.has(record.sourceId)) {
      continue;
    }
    records[record.sourceId] = {
      importedChecksum: record.checksum,
      slug: record.slug,
      locale: canonicalLocale(record.locale),
    };
  }
  return { records };
}

/**
 * Reconciles source, target and disposition counts for the workbook.
 *
 * @param {object} plan Plan returned by planMigration.
 * @param {object} corpus Corpus the plan was built from.
 * @returns {object} Reconciliation report.
 */
export function reconcile(plan, corpus) {
  const source = (corpus.records ?? []).length;
  const stopped = plan.status === "stopped";
  const create = plan.counts.create;
  const update = plan.counts.update;
  const unchanged = plan.counts.unchanged;
  const quarantined = plan.counts.quarantine;

  const locales = {};
  for (const row of plan.dispositions) {
    if (row.locale === null) {
      continue;
    }
    locales[row.locale] = (locales[row.locale] ?? 0) + 1;
  }

  const unresolved = plan.dispositions
    .filter((row) => row.action === "quarantine")
    .map((row) => ({ sourceId: row.sourceId, class: row.class, blocker: row.blocker }));

  const balanced =
    !stopped &&
    source === create + update + unchanged + quarantined &&
    unresolved.every((row) => row.blocker.length > 0 && row.class.length > 0);

  return {
    corpusId: corpus.corpusId ?? "",
    stopped,
    stopReasons: plan.stopReasons,
    source,
    target: create + update + unchanged,
    create,
    update,
    unchanged,
    quarantined,
    blocked: plan.counts.blocked,
    locales,
    unresolved,
    balanced,
  };
}

/**
 * Renders the reconciliation workbook as Markdown.
 *
 * @param {object} report Report returned by reconcile.
 * @param {object} plan Plan returned by planMigration.
 * @returns {string} Workbook document.
 */
export function formatWorkbook(report, plan) {
  const lines = [
    `# Migration reconciliation workbook — ${report.corpusId}`,
    "",
    "## Counts",
    "",
    "| metric | value |",
    "| --- | --- |",
    `| source records | ${report.source} |`,
    `| target records | ${report.target} |`,
    `| create | ${report.create} |`,
    `| update | ${report.update} |`,
    `| unchanged | ${report.unchanged} |`,
    `| quarantined | ${report.quarantined} |`,
    `| blocked by stop | ${report.blocked} |`,
    `| reconciled | ${report.balanced ? "yes" : "no"} |`,
    "",
    "## Locales (BCP47)",
    "",
    "| tag | records |",
    "| --- | --- |",
    ...Object.keys(report.locales)
      .sort()
      .map((tag) => `| ${tag} | ${report.locales[tag]} |`),
    "",
    "## Dispositions",
    "",
    "| source id | locale | action | class | blocker |",
    "| --- | --- | --- | --- | --- |",
    ...plan.dispositions.map(
      (row) =>
        `| ${row.sourceId} | ${row.locale ?? "-"} | ${row.action} | ${row.class ?? "-"} | ${row.blocker || "-"} |`,
    ),
    "",
    "## Stop reasons",
    "",
    ...(report.stopReasons.length === 0
      ? ["None."]
      : report.stopReasons.map((reason) => `- \`${reason.class}\`: ${reason.detail}`)),
    "",
  ];
  return `${lines.join("\n")}\n`;
}
