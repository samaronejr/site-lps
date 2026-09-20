#!/usr/bin/env node
/**
 * Release-candidate readiness helpers for task 24.
 *
 * Pure, dependency-free functions plus a small `check` CLI:
 *
 *   node scripts/release/readiness.mjs check --root=dist --manifest=dist/release-manifest.json
 *
 * `check` recomputes every digest in the manifest, scans the tree for
 * secrets and fixture content, and verifies each shipped file is
 * byte-identical to its reviewed source counterpart. Exit 0 means the
 * candidate on disk matches the manifest; exit 1 names the violation.
 *
 * `evaluateReadiness` never authorizes production. It returns
 * READY_FOR_FINAL_REVIEW only when every local gate executed green, the
 * manifest verifies, the browser suite really executed tests, and every
 * task links to a receipt; anything else is BLOCKED with named reasons.
 * Hosted prerequisites stay visible as blockers in both outcomes.
 */

import { createHash } from "node:crypto";
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative, resolve, sep } from "node:path";
import process from "node:process";

export const READY_FOR_FINAL_REVIEW = "READY_FOR_FINAL_REVIEW";
export const BLOCKED = "BLOCKED";

/** Files the build/assembly pipeline generates; excluded from correspondence. */
export const GENERATED_DIST_FILES = new Set([
  "manifest.json",
  "performance-budget.json",
  "release-manifest.json",
]);

/** Source subtrees excluded from the shipped artifact by scripts/build.mjs. */
const SOURCE_EXCLUDE_PATTERN = /(?:\/tests(?:\/|$)|\/seed-debug\.txt$)/;

/** dist prefix -> reviewed source prefix for the correspondence check. */
export const CORRESPONDENCE_MAP = {
  "lps-content-model": "wp-content/plugins/lps-content-model",
  "lps-theme": "wp-content/themes/lps-theme",
  "mu-plugins": "wp-content/mu-plugins",
};

export const SECRET_PATTERNS = [
  { id: "private-key-block", pattern: "-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----" },
  { id: "aws-access-key", pattern: "AKIA[0-9A-Z]{16}" },
  { id: "credential-env-token", pattern: "LPS_TASK10_PASSWORD" },
  {
    id: "hardcoded-secret-assignment",
    pattern: "(?:password|passwd|api[_-]?key|client[_-]?secret)\\s*[:=]\\s*['\"][^'\"]{4,}['\"]",
  },
];

const SECRET_FILENAMES = [/\.pem$/, /\.key$/, /(?:^|\/)\.env$/];

/** Relative-path markers that must never appear inside the shipped artifact. */
export const FIXTURE_PATH_MARKERS = [
  "/tests/",
  "/fixtures/",
  ".spec.mjs",
  ".test.mjs",
  "seed-debug.txt",
];

export function sha256Hex(bytes) {
  return createHash("sha256").update(bytes).digest("hex");
}

function walkFiles(root) {
  const found = [];
  const visit = (directory) => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const absolute = join(directory, entry.name);
      if (entry.isDirectory()) visit(absolute);
      else if (entry.isFile()) found.push(absolute);
    }
  };
  visit(root);
  return found.sort();
}

/**
 * Collects every file under root with POSIX-style relative paths, sizes and digests.
 */
export function collectReleaseFiles(root, readFile = readFileSync) {
  return walkFiles(root).map((absolute) => {
    const bytes = readFile(absolute);
    return {
      path: relative(root, absolute).split(sep).join("/"),
      bytes: bytes.length,
      sha256: sha256Hex(bytes),
    };
  });
}

/**
 * Recomputes every manifest digest against the tree on disk.
 * Extra files not listed in the manifest are reported; generated files are
 * expected to be listed (assemble writes them) so they are checked too.
 */
export function verifyManifest(manifest, root, readFile = readFileSync, exclude = []) {
  const excluded = new Set(exclude);
  const listed = new Map((manifest.files ?? []).map((entry) => [entry.path, entry.sha256]));
  const onDisk = new Map(
    collectReleaseFiles(root, readFile).map((entry) => [entry.path, entry.sha256]),
  );
  const missing = [];
  const mismatched = [];
  for (const [path, digest] of listed) {
    if (!onDisk.has(path)) missing.push(path);
    else if (onDisk.get(path) !== digest) mismatched.push(path);
  }
  const extra = [...onDisk.keys()].filter((path) => !listed.has(path) && !excluded.has(path));
  return {
    ok: missing.length === 0 && mismatched.length === 0 && extra.length === 0,
    missing,
    mismatched,
    extra,
  };
}

/**
 * Scans text files for secret patterns and rejects secret filenames outright.
 * Files larger than 2 MiB are skipped (media blobs) and reported as skipped.
 */
export function scanForSecrets(root, readFile = readFileSync) {
  const hits = [];
  const skipped = [];
  for (const absolute of walkFiles(root)) {
    const rel = relative(root, absolute).split(sep).join("/");
    if (SECRET_FILENAMES.some((pattern) => pattern.test(rel))) {
      hits.push({ file: rel, pattern: "secret-filename" });
      continue;
    }
    const size = statSync(absolute).size;
    if (size > 2 * 1024 * 1024) {
      skipped.push(rel);
      continue;
    }
    let text;
    try {
      text = readFile(absolute, "utf8");
    } catch {
      continue;
    }
    if (typeof text !== "string" || text.includes("\u0000")) continue;
    for (const { id, pattern } of SECRET_PATTERNS) {
      if (new RegExp(pattern, "i").test(text)) hits.push({ file: rel, pattern: id });
    }
  }
  return { hits, skipped };
}

/** Reports any shipped path carrying a test/fixture marker. */
export function scanForFixtureContent(root) {
  return walkFiles(root)
    .map((absolute) => relative(root, absolute).split(sep).join("/"))
    .filter((rel) => FIXTURE_PATH_MARKERS.some((marker) => rel.includes(marker)));
}

/**
 * Every shipped file (except generated ones) must be byte-identical to its
 * reviewed source counterpart. Returns mismatches with a reason per file:
 * missing-source, byte-mismatch, or unmapped-prefix.
 */
export function checkCorrespondence(root, sourceRoot, readFile = readFileSync) {
  const mismatches = [];
  for (const absolute of walkFiles(root)) {
    const rel = relative(root, absolute).split(sep).join("/");
    if (GENERATED_DIST_FILES.has(rel)) continue;
    const prefix = rel.split("/")[0];
    const sourcePrefix = CORRESPONDENCE_MAP[prefix];
    if (!sourcePrefix) {
      mismatches.push({ file: rel, reason: "unmapped-prefix" });
      continue;
    }
    const sourcePath = join(sourceRoot, sourcePrefix, rel.slice(prefix.length + 1));
    if (SOURCE_EXCLUDE_PATTERN.test(`/${sourcePath}`)) {
      mismatches.push({ file: rel, reason: "shipped-excluded-source" });
      continue;
    }
    if (!existsSync(sourcePath)) {
      mismatches.push({ file: rel, reason: "missing-source" });
      continue;
    }
    const shipped = readFile(absolute);
    const source = readFile(sourcePath);
    if (!shipped.equals(source)) mismatches.push({ file: rel, reason: "byte-mismatch" });
  }
  return mismatches;
}

/**
 * Reconciles tasks, gates, browser execution and the manifest into a verdict.
 *
 * input.tasks: [{id, receipts: [non-empty strings], blocked?: string}]
 * input.gates: [{name, executed: bool, exit: number, classification?: "pre-existing"|"introduced"}]
 * input.e2e: {executed: number, listed?: number}
 * input.manifest: {ok: bool, detail?: string}
 * input.hostedBlockers: [non-empty strings]
 *
 * READY_FOR_FINAL_REVIEW requires everything local to have executed green;
 * hostedBlockers stay attached as production blockers. Anything else yields
 * BLOCKED. There is no production-authorized outcome by design.
 */
export function evaluateReadiness(input) {
  const reasons = [];
  const blockers = [...(input.hostedBlockers ?? [])];

  for (const task of input.tasks ?? []) {
    if (!task.receipts || task.receipts.length === 0) {
      reasons.push(`task-${task.id}-without-receipt`);
    }
  }

  let executedGateCount = 0;
  for (const gate of input.gates ?? []) {
    if (!gate.executed) {
      reasons.push(`gate-${gate.name}-not-executed`);
      continue;
    }
    executedGateCount += 1;
    if (gate.exit !== 0) {
      const scope = gate.classification === "introduced" ? "introduced" : "pre-existing";
      reasons.push(`gate-${gate.name}-exit-${gate.exit}-${scope}`);
      blockers.push(`verification-debt:${gate.name} (exit ${gate.exit}, ${scope})`);
    }
  }
  if (executedGateCount === 0) reasons.push("no-gate-executed");

  const executed = input.e2e?.executed ?? 0;
  if (executed === 0) {
    reasons.push("e2e-executed-zero");
    if (input.e2e?.listed)
      blockers.push(`browser-suite-listed-only (${input.e2e.listed} listed, 0 executed)`);
  }

  if (!input.manifest?.ok)
    reasons.push(`release-manifest-invalid:${input.manifest?.detail ?? "unverified"}`);

  if (reasons.length > 0) return { verdict: BLOCKED, reasons, blockers };
  return { verdict: READY_FOR_FINAL_REVIEW, reasons, blockers };
}

function option(name, fallback) {
  const inline = process.argv.find((arg) => arg.startsWith(`${name}=`));
  if (inline !== undefined) return inline.slice(name.length + 1);
  const index = process.argv.indexOf(name);
  return index === -1 ? fallback : process.argv[index + 1];
}

function runCheck() {
  const root = option("--root", "dist");
  const sourceRoot = option("--source-root", process.cwd());
  const manifestPath = option("--manifest", join(root, "release-manifest.json"));
  if (!existsSync(manifestPath)) {
    process.stdout.write(
      `${JSON.stringify({ ok: false, error: `manifest-missing:${manifestPath}` }, null, 2)}\n`,
    );
    process.exitCode = 1;
    return;
  }
  let manifest;
  try {
    manifest = JSON.parse(readFileSync(manifestPath, "utf8"));
  } catch (error) {
    process.stdout.write(
      `${JSON.stringify({ ok: false, error: `manifest-unparseable:${String(error)}` }, null, 2)}\n`,
    );
    process.exitCode = 1;
    return;
  }
  const manifestRel = relative(resolve(root), resolve(manifestPath)).split(sep).join("/");
  const verification = verifyManifest(
    manifest,
    root,
    readFileSync,
    manifestRel.startsWith("..") ? [] : [manifestRel],
  );
  const secrets = scanForSecrets(root);
  const fixtures = scanForFixtureContent(root);
  const correspondence = checkCorrespondence(root, sourceRoot);
  const report = {
    ok:
      verification.ok &&
      secrets.hits.length === 0 &&
      fixtures.length === 0 &&
      correspondence.length === 0,
    manifest: manifest.release ?? null,
    sourceRevision: manifest.sourceRevision ?? null,
    fileCount: manifest.files?.length ?? 0,
    verification,
    secrets,
    fixturePaths: fixtures,
    correspondence,
  };
  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
  if (!report.ok) process.exitCode = 1;
}

const subcommand = process.argv[2];
if (subcommand === "check") runCheck();
else if (subcommand !== undefined) {
  process.stderr.write(`unknown subcommand: ${subcommand} (expected: check)\n`);
  process.exitCode = 2;
}
