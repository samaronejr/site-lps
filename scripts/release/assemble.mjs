#!/usr/bin/env node
/**
 * Task 24 — release-candidate assembly.
 *
 * Rebuilds dist/ from the reviewed source, then writes
 * dist/release-manifest.json: a per-file SHA-256 manifest plus provenance
 * (source revision, working-tree status, schema version, content-package
 * digest, performance-budget verdict). Every shipped file is verified
 * byte-identical to its source counterpart and the tree is scanned for
 * secrets and fixture content before the manifest is written, so a
 * violation aborts with exit 1 and no manifest — never a silent pass.
 *
 * Usage: node scripts/release/assemble.mjs [--root=dist]
 */

import { spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import process from "node:process";
import {
  checkCorrespondence,
  collectReleaseFiles,
  scanForFixtureContent,
  scanForSecrets,
} from "./readiness.mjs";

function option(name, fallback) {
  const inline = process.argv.find((arg) => arg.startsWith(`${name}=`));
  if (inline !== undefined) return inline.slice(name.length + 1);
  const index = process.argv.indexOf(name);
  return index === -1 ? fallback : process.argv[index + 1];
}

function git(args) {
  const result = spawnSync("git", args, { encoding: "utf8" });
  return result.status === 0 ? result.stdout.trim() : "unknown";
}

function schemaVersion() {
  const exporter = "wp-content/plugins/lps-content-model/includes/class-exporter.php";
  if (!existsSync(exporter)) return "unknown";
  const match = readFileSync(exporter, "utf8").match(/'schema_version'\s*=>\s*'([^']+)'/);
  return match ? match[1] : "unknown";
}

function fileDigest(path) {
  return createHash("sha256").update(readFileSync(path)).digest("hex");
}

const root = option("--root", "dist");

const build = spawnSync("npm", ["run", "build"], { encoding: "utf8" });
if (build.status !== 0) {
  process.stderr.write(`release assembly refused: npm run build exited ${build.status}\n`);
  process.stderr.write(build.stderr.slice(-2000));
  process.exit(1);
}

let budget = { pass: false, source: "missing" };
const budgetPath = join(root, "performance-budget.json");
if (existsSync(budgetPath)) {
  try {
    const report = JSON.parse(readFileSync(budgetPath, "utf8"));
    budget = { pass: report.pass === true, source: "dist/performance-budget.json" };
  } catch {
    budget = { pass: false, source: "unparseable" };
  }
}

const corpusPath = "content/import/launch-corpus.json";
const manifest = {
  release: `rc-${new Date().toISOString().slice(0, 10)}-${git(["rev-parse", "--short", "HEAD"])}`,
  created: new Date().toISOString(),
  sourceRevision: git(["rev-parse", "HEAD"]),
  sourceStatus: git(["status", "--porcelain"]),
  schemaVersion: schemaVersion(),
  contentPackage: existsSync(corpusPath)
    ? { path: corpusPath, sha256: fileDigest(corpusPath) }
    : null,
  budget,
  files: collectReleaseFiles(root),
};

if (manifest.contentPackage === null) {
  process.stderr.write("release assembly refused: content/import/launch-corpus.json is missing\n");
  process.exit(1);
}
if (!budget.pass) {
  process.stderr.write("release assembly refused: performance budget did not pass\n");
  process.exit(1);
}

const secrets = scanForSecrets(root);
if (secrets.hits.length > 0) {
  process.stderr.write(
    `release assembly refused: secrets detected\n${JSON.stringify(secrets.hits, null, 2)}\n`,
  );
  process.exit(1);
}
const fixtures = scanForFixtureContent(root);
if (fixtures.length > 0) {
  process.stderr.write(
    `release assembly refused: fixture content in artifact\n${JSON.stringify(fixtures, null, 2)}\n`,
  );
  process.exit(1);
}
const correspondence = checkCorrespondence(root, process.cwd());
if (correspondence.length > 0) {
  process.stderr.write(
    `release assembly refused: source/artifact mismatch\n${JSON.stringify(correspondence, null, 2)}\n`,
  );
  process.exit(1);
}

manifest.counts = {
  files: manifest.files.length,
  bytes: manifest.files.reduce((total, entry) => total + entry.bytes, 0),
};

writeFileSync(join(root, "release-manifest.json"), `${JSON.stringify(manifest, null, 2)}\n`);
process.stdout.write(
  `${JSON.stringify({ ok: true, release: manifest.release, sourceRevision: manifest.sourceRevision, ...manifest.counts }, null, 2)}\n`,
);
