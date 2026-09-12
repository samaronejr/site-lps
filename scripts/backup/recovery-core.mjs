/**
 * Deterministic backup, restore and rollback-rehearsal core.
 *
 * Every function here is pure: it operates on in-memory trees, artifacts and
 * configuration objects. Nothing touches a database, a live filesystem tree, a
 * network or a running site, so the whole backup -> restore -> hash-compare
 * cycle is provable from fixtures without an environment.
 *
 * Owners, retention and RPO/RTO values are read from configuration derived from
 * `docs/content/governance.md`. A missing owner or an unapproved objective is
 * reported as an explicit launch blocker and is never replaced by a default.
 */

import { createHash } from "node:crypto";

const COMPONENT_ORDER = ["database", "media", "config"];

const COMPONENT_SOURCE = {
  database: "records",
  media: "files",
  config: "config",
};

const BACKUP_STAGES = [
  {
    id: "backup-database",
    description: "Dump the database with structure, content and revision history.",
    command: ["wp", "db", "export", "{artifactDir}/database.sql"],
  },
  {
    id: "backup-media",
    description: "Archive the uploads tree with byte-for-byte fidelity.",
    command: ["tar", "-cf", "{artifactDir}/media.tar", "wp-content/uploads"],
  },
  {
    id: "backup-config",
    description: "Capture the versioned runtime configuration and option snapshot.",
    command: ["wp", "config", "list", "--format=json"],
  },
  {
    id: "encrypt-artifact",
    description: "Encrypt the combined artifact to the institutional recipient key.",
    command: ["age", "-r", "{recipientKeyId}", "-o", "{artifactDir}/backup.age"],
  },
  {
    id: "verify-artifact",
    description: "Recompute per-item hashes and the manifest digest from the artifact.",
    command: [
      "node",
      "scripts/backup/recovery.mjs",
      "restore-verify",
      "--backup={artifactDir}/manifest.json",
      "--tree={artifactDir}/source-tree.json",
    ],
  },
];

/**
 * Hashes a payload with the artifact-wide digest convention.
 *
 * @param {string} value Payload string.
 * @returns {string} Lowercase hex SHA-256.
 */
export function sha256(value) {
  return createHash("sha256").update(value, "utf8").digest("hex");
}

function componentDigest(items) {
  return sha256(
    Object.keys(items)
      .sort()
      .map((id) => `${id}:${items[id].sha256}`)
      .join("\n"),
  );
}

function manifestDigestOf(components) {
  return sha256(COMPONENT_ORDER.map((name) => `${name}:${components[name].digest}`).join("\n"));
}

/**
 * Builds the ordered backup plan with retention, owner and encryption blockers.
 *
 * @param {object} config Recovery configuration (governance-derived).
 * @returns {{ready: boolean, stages: object[], retention: object, owner: object, encryption: object, blockers: object[]}}
 */
export function buildBackupPlan(config) {
  const encryption = config?.encryption ?? {};
  const retentionConfig = config?.retention ?? {};
  const owners = config?.owners ?? {};
  const ownerRole = owners.backupOperatorRole ?? null;
  const ownerAssignee = ownerRole === null ? null : (owners.assignees?.[ownerRole] ?? null);

  const blockers = [];
  if (!encryption.recipientKeyId) {
    blockers.push({
      id: "backup-encryption-key-unassigned",
      detail: `No encryption recipient key is assigned; the ${encryption.keyCustodianRole ?? "key custodian"} role must provide one before a backup may be produced.`,
    });
  }
  if (ownerAssignee === null) {
    blockers.push({
      id: "backup-owner-unassigned",
      detail: `Backup operator role "${ownerRole ?? "unset"}" has no named assignee in the governance configuration.`,
    });
  }
  if (retentionConfig.status !== "approved") {
    blockers.push({
      id: "backup-retention-unapproved",
      detail: `Retention window is "${retentionConfig.status ?? "undefined"}"; the ${retentionConfig.approverRole ?? "approver"} role has not approved it.`,
    });
  }

  const retention = {
    dailySnapshots: retentionConfig.dailySnapshots ?? null,
    weeklySnapshots: retentionConfig.weeklySnapshots ?? null,
    monthlySnapshots: retentionConfig.monthlySnapshots ?? null,
    offsiteCopies: retentionConfig.offsiteCopies ?? null,
  };

  const stages = BACKUP_STAGES.map((stage) => ({
    id: stage.id,
    description: stage.description,
    command: stage.command.map((token) =>
      token
        .replace("{artifactDir}", config?.artifactDir ?? "{artifactDir}")
        .replace("{recipientKeyId}", encryption.recipientKeyId ?? "<unassigned-recipient-key>"),
    ),
    encrypted: true,
  }));

  return {
    ready: blockers.length === 0,
    stages,
    retention,
    owner: { role: ownerRole, assignee: ownerAssignee },
    encryption: {
      algorithm: encryption.algorithm ?? null,
      recipientKeyId: encryption.recipientKeyId ?? null,
      status: encryption.recipientKeyId ? "encrypted" : "unencrypted",
    },
    blockers,
  };
}

/**
 * Creates a deterministic backup artifact from an in-memory source tree.
 *
 * @param {{treeId?: string, records: object, files: object, config: object}} tree Source tree.
 * @param {object} config Recovery configuration.
 * @returns {object} Backup artifact with per-item hashes and a manifest digest.
 */
export function createBackup(tree, config) {
  const encryption = config?.encryption ?? {};
  const owners = config?.owners ?? {};
  const ownerRole = owners.backupOperatorRole ?? null;

  const components = {};
  for (const name of COMPONENT_ORDER) {
    const source = tree?.[COMPONENT_SOURCE[name]] ?? {};
    const items = {};
    for (const id of Object.keys(source)) {
      items[id] = { sha256: sha256(String(source[id])), payload: source[id] };
    }
    components[name] = { items, digest: componentDigest(items) };
  }

  return {
    backupId: `lps-backup-${tree?.treeId ?? "unknown"}`,
    treeId: tree?.treeId ?? null,
    encryption: {
      algorithm: encryption.algorithm ?? null,
      recipientKeyId: encryption.recipientKeyId ?? null,
      status: encryption.recipientKeyId ? "encrypted" : "unencrypted",
    },
    retention: {
      dailySnapshots: config?.retention?.dailySnapshots ?? null,
      weeklySnapshots: config?.retention?.weeklySnapshots ?? null,
      monthlySnapshots: config?.retention?.monthlySnapshots ?? null,
      offsiteCopies: config?.retention?.offsiteCopies ?? null,
    },
    owner: {
      role: ownerRole,
      assignee: ownerRole === null ? null : (owners.assignees?.[ownerRole] ?? null),
    },
    components,
    manifestDigest: manifestDigestOf(components),
  };
}

/**
 * Restores an artifact into a clean in-memory environment, verifying hashes.
 *
 * A payload that is absent or whose hash does not match its manifest entry is
 * reported with its exact component and item identifier; the artifact is then
 * not restorable.
 *
 * @param {object} artifact Backup artifact.
 * @returns {{restorable: boolean, tree: object, findings: object[]}}
 */
export function restoreBackup(artifact) {
  const findings = [];
  const tree = { treeId: artifact?.treeId ?? null, records: {}, files: {}, config: {} };

  for (const name of COMPONENT_ORDER) {
    const component = artifact?.components?.[name];
    if (component === undefined) {
      findings.push({
        component: name,
        item: name,
        class: "missing_component",
        detail: `Artifact has no ${name} component.`,
      });
      continue;
    }
    const items = component.items ?? {};
    for (const id of Object.keys(items)) {
      const entry = items[id];
      if (entry.payload === null || entry.payload === undefined) {
        findings.push({
          component: name,
          item: id,
          class: "missing_payload",
          detail: `${name} item ${id} is listed in the manifest but has no payload in the artifact.`,
        });
        continue;
      }
      const actual = sha256(String(entry.payload));
      if (actual !== entry.sha256) {
        findings.push({
          component: name,
          item: id,
          class: "corrupt_payload",
          detail: `${name} item ${id} hashes to ${actual} but the manifest declares ${entry.sha256}.`,
        });
        continue;
      }
      tree[COMPONENT_SOURCE[name]][id] = entry.payload;
    }

    const recomputed = componentDigest(items);
    if (component.digest !== undefined && recomputed !== component.digest) {
      findings.push({
        component: name,
        item: `${name}.digest`,
        class: "digest_mismatch",
        detail: `${name} component digest ${recomputed} does not match the declared ${component.digest}.`,
      });
    }
  }

  return { restorable: findings.length === 0, tree, findings };
}

/**
 * Verifies a restored artifact against the source tree it must reproduce.
 *
 * @param {object} sourceTree Tree the backup was taken from.
 * @param {object} artifact Backup artifact.
 * @returns {{verified: boolean, findings: object[], counts: {records: number, files: number, config: number}}}
 */
export function verifyRestore(sourceTree, artifact) {
  const restored = restoreBackup(artifact);
  const findings = [...restored.findings];

  for (const name of COMPONENT_ORDER) {
    const key = COMPONENT_SOURCE[name];
    const source = sourceTree?.[key] ?? {};
    const target = restored.tree[key];
    for (const id of Object.keys(source)) {
      if (!(id in target)) {
        if (!findings.some((finding) => finding.item === id)) {
          findings.push({
            component: name,
            item: id,
            class: "missing_item",
            detail: `${name} item ${id} exists in the source tree but not in the restored tree.`,
          });
        }
        continue;
      }
      const expected = sha256(String(source[id]));
      const actual = sha256(String(target[id]));
      if (expected !== actual) {
        findings.push({
          component: name,
          item: id,
          class: "hash_mismatch",
          detail: `${name} item ${id} restored with hash ${actual}, expected ${expected}.`,
        });
      }
    }
    for (const id of Object.keys(target)) {
      if (!(id in source)) {
        findings.push({
          component: name,
          item: id,
          class: "unexpected_item",
          detail: `${name} item ${id} exists in the restored tree but not in the source tree.`,
        });
      }
    }
  }

  return {
    verified: findings.length === 0,
    findings,
    counts: {
      records: Object.keys(restored.tree.records).length,
      files: Object.keys(restored.tree.files).length,
      config: Object.keys(restored.tree.config).length,
    },
  };
}

/**
 * Reads RPO/RTO objectives from configuration without inventing values.
 *
 * @param {object} config Recovery configuration.
 * @returns {{approved: boolean, objectives: object[], launchBlockers: object[]}}
 */
export function evaluateRecoveryObjectives(config) {
  const objectives = [];
  const launchBlockers = [];

  for (const entry of config?.objectives ?? []) {
    const approval = entry.approval ?? {};
    const hasNumbers = typeof entry.rpoMinutes === "number" && typeof entry.rtoMinutes === "number";
    const approved =
      approval.status === "approved" &&
      typeof approval.approvedBy === "string" &&
      approval.approvedBy.length > 0 &&
      hasNumbers;

    if (!approved) {
      launchBlockers.push({
        id: `rpo-rto-unapproved:${entry.id}`,
        detail:
          `No owner-approved RPO/RTO exists for ${entry.id}; the ${approval.approverRole ?? "approver"} role must record one. ${entry.evidence ?? ""}`.trim(),
      });
    }

    objectives.push({
      id: entry.id,
      scope: entry.scope ?? null,
      approved,
      rpoMinutes: approved ? entry.rpoMinutes : null,
      rtoMinutes: approved ? entry.rtoMinutes : null,
      approverRole: approval.approverRole ?? null,
      approvedBy: approval.approvedBy ?? null,
    });
  }

  return { approved: launchBlockers.length === 0, objectives, launchBlockers };
}

/**
 * Rehearses application and content-revision rollback against approved objectives.
 *
 * An objective without an approval receipt, or without a rehearsal measurement,
 * is reported as `blocked`; it never passes by omission.
 *
 * @param {{config: object, measured: Array<{objectiveId: string, dataLossMinutes: number, recoveryMinutes: number}>}} input Rehearsal input.
 * @returns {{status: string, results: object[], launchBlockers: object[]}}
 */
export function rehearseRollback(input) {
  const evaluation = evaluateRecoveryObjectives(input?.config);
  const measurements = new Map((input?.measured ?? []).map((entry) => [entry.objectiveId, entry]));

  const results = evaluation.objectives.map((objective) => {
    const measurement = measurements.get(objective.id);
    if (!objective.approved) {
      return {
        objectiveId: objective.id,
        status: "blocked",
        breaches: [],
        detail: `Objective ${objective.id} has no owner-approved RPO/RTO; the rehearsal cannot be scored.`,
      };
    }
    if (measurement === undefined) {
      return {
        objectiveId: objective.id,
        status: "blocked",
        breaches: [],
        detail: `Objective ${objective.id} has no rehearsal measurement.`,
      };
    }
    const breaches = [];
    if (measurement.dataLossMinutes > objective.rpoMinutes) {
      breaches.push("rpo");
    }
    if (measurement.recoveryMinutes > objective.rtoMinutes) {
      breaches.push("rto");
    }
    return {
      objectiveId: objective.id,
      status: breaches.length === 0 ? "pass" : "fail",
      breaches,
      detail:
        breaches.length === 0
          ? `Rollback met RPO ${objective.rpoMinutes}min and RTO ${objective.rtoMinutes}min.`
          : `Rollback breached ${breaches.join(" and ")}: measured RPO ${measurement.dataLossMinutes}min / RTO ${measurement.recoveryMinutes}min against approved ${objective.rpoMinutes}min / ${objective.rtoMinutes}min.`,
    };
  });

  let status = "pass";
  if (results.some((result) => result.status === "fail")) {
    status = "fail";
  } else if (results.length === 0 || results.some((result) => result.status === "blocked")) {
    status = "blocked";
  }

  return { status, results, launchBlockers: evaluation.launchBlockers };
}

/**
 * Renders the recovery rehearsal report as Markdown.
 *
 * @param {object} report Report returned by rehearseRollback.
 * @returns {string} Markdown document.
 */
export function formatRollbackReport(report) {
  const lines = [
    "# Rollback rehearsal",
    "",
    `Status: **${report.status}**`,
    "",
    "| objective | status | breaches | detail |",
    "| --- | --- | --- | --- |",
    ...report.results.map(
      (result) =>
        `| ${result.objectiveId} | ${result.status} | ${result.breaches.join(", ") || "-"} | ${result.detail} |`,
    ),
    "",
    "## Launch blockers",
    "",
    ...(report.launchBlockers.length === 0
      ? ["None."]
      : report.launchBlockers.map((blocker) => `- \`${blocker.id}\`: ${blocker.detail}`)),
    "",
  ];
  return `${lines.join("\n")}\n`;
}
