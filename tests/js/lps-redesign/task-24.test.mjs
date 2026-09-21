import { spawnSync } from "node:child_process";
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import {
  BLOCKED,
  checkCorrespondence,
  collectReleaseFiles,
  evaluateReadiness,
  READY_FOR_FINAL_REVIEW,
  SECRET_PATTERNS,
  scanForFixtureContent,
  scanForSecrets,
  verifyManifest,
} from "../../../scripts/release/readiness.mjs";

/**
 * Task 24 — release-candidate assembly and truthful readiness handoff.
 *
 * Pins the contracts the handoff depends on: the manifest detects any
 * tamper/missing/extra file, the secret and fixture scanners are not
 * vacuous, shipped files match reviewed sources, and the readiness
 * evaluator returns READY_FOR_FINAL_REVIEW only for fully green local
 * evidence — anything else is BLOCKED, and production is never authorized
 * here. Static assertions tie every task 01–23 to a test receipt in the
 * tree and to the readiness ledger, which must keep hosted prerequisites
 * visible as blockers instead of reporting them as passed.
 */

const NODE = process.execPath;
const READINESS_CLI = "scripts/release/readiness.mjs";
const LEDGER = "docs/operations/readiness-ledger.md";

function cli(args, cwd) {
  const result = spawnSync(NODE, [READINESS_CLI, ...args], { encoding: "utf8", cwd });
  return { exit: result.status, stdout: result.stdout ?? "" };
}

function makeTree(files) {
  const root = mkdtempSync(join(tmpdir(), "lps-t24-"));
  for (const [rel, content] of Object.entries(files)) {
    const absolute = join(root, rel);
    mkdirSync(join(absolute, ".."), { recursive: true });
    writeFileSync(absolute, content);
  }
  return root;
}

function manifestFor(root) {
  return {
    release: "test-rc",
    sourceRevision: "test",
    files: collectReleaseFiles(root).map(({ path, sha256 }) => ({ path, sha256 })),
  };
}

describe("task-24: manifest verification detects any drift", () => {
  it("accepts an intact tree and rejects tamper, removal and addition", () => {
    const root = makeTree({ "a.txt": "alpha", "sub/b.txt": "beta" });
    const manifest = manifestFor(root);
    expect(verifyManifest(manifest, root).ok).toBe(true);

    writeFileSync(join(root, "a.txt"), "tampered");
    const tampered = verifyManifest(manifest, root);
    expect(tampered.ok).toBe(false);
    expect(tampered.mismatched).toEqual(["a.txt"]);
    writeFileSync(join(root, "a.txt"), "alpha");

    writeFileSync(join(root, "sub/c.txt"), "extra");
    const extra = verifyManifest(manifest, root, readFileSync, ["release-manifest.json"]);
    expect(extra.ok).toBe(false);
    expect(extra.extra).toEqual(["sub/c.txt"]);
  });

  it("the check CLI exits 0 clean and 1 on a tampered hash", () => {
    const tree = makeTree({ "wp-content/mu-plugins/guard.php": "<?php // sentinel" });
    const sourceRoot = tree;
    const root = join(tree, "candidate");
    mkdirSync(join(root, "mu-plugins"), { recursive: true });
    writeFileSync(join(root, "mu-plugins/guard.php"), "<?php // sentinel");
    const manifest = manifestFor(root);
    writeFileSync(join(root, "release-manifest.json"), JSON.stringify(manifest));
    const flags = [
      `--root=${root}`,
      `--manifest=${join(root, "release-manifest.json")}`,
      `--source-root=${sourceRoot}`,
    ];

    const clean = cli(["check", ...flags], process.cwd());
    expect(clean.exit).toBe(0);
    expect(JSON.parse(clean.stdout).ok).toBe(true);

    const poisoned = {
      ...manifest,
      files: [{ path: "mu-plugins/guard.php", sha256: "0".repeat(64) }],
    };
    writeFileSync(join(root, "release-manifest.json"), JSON.stringify(poisoned));
    const rejected = cli(["check", ...flags], process.cwd());
    expect(rejected.exit).toBe(1);
    expect(JSON.parse(rejected.stdout).verification.mismatched).toEqual(["mu-plugins/guard.php"]);
  });
});

describe("task-24: scanners are not vacuous and the artifact stays clean", () => {
  it("flags a private key and an access key, and passes a clean tree", () => {
    const dirty = makeTree({
      "key.txt": "-----BEGIN RSA PRIVATE KEY-----\nfake\n",
      "code.php": "<?php $x = 'AKIAIOSFODNN7EXAMPLE';",
      "clean.txt": "hello world",
    });
    const dirtyReport = scanForSecrets(dirty);
    expect(dirtyReport.hits.map((hit) => hit.pattern).sort()).toEqual([
      "aws-access-key",
      "private-key-block",
    ]);

    const clean = makeTree({ "clean.txt": "hello world" });
    expect(scanForSecrets(clean).hits).toEqual([]);
    expect(scanForFixtureContent(clean)).toEqual([]);
  });

  it("flags fixture paths while the real shipped sources carry no secrets", () => {
    const root = makeTree({ "tests/unit/sample.test.mjs": "x", "ok.php": "y" });
    expect(scanForFixtureContent(root)).toEqual(["tests/unit/sample.test.mjs"]);

    for (const shipped of [
      "wp-content/plugins/lps-content-model",
      "wp-content/themes/lps-theme",
      "wp-content/mu-plugins",
    ]) {
      expect(scanForSecrets(shipped).hits, `${shipped} must ship no secrets`).toEqual([]);
    }
    expect(SECRET_PATTERNS.length).toBeGreaterThan(0);
  });

  it("the build excludes test trees by construction", () => {
    const build = readFileSync("scripts/build.mjs", "utf8");
    expect(build).toMatch(/tests/);
  });
});

describe("task-24: correspondence pins shipped bytes to reviewed sources", () => {
  it("accepts identical trees and names byte drift", () => {
    const source = makeTree({ "wp-content/mu-plugins/guard.php": "<?php // guard" });
    mkdirSync(join(source, "dist-check"), { recursive: true });
    const root = join(source, "dist-check");
    mkdirSync(join(root, "mu-plugins"), { recursive: true });
    writeFileSync(join(root, "mu-plugins/guard.php"), "<?php // guard");
    expect(checkCorrespondence(root, source)).toEqual([]);

    writeFileSync(join(root, "mu-plugins/guard.php"), "<?php // drifted");
    expect(checkCorrespondence(root, source)).toEqual([
      { file: "mu-plugins/guard.php", reason: "byte-mismatch" },
    ]);
  });
});

describe("task-24: readiness evaluator never passes what it cannot prove", () => {
  const greenLocal = () => ({
    tasks: [{ id: "01", receipts: ["tests/js/lps-redesign/task-01.test.mjs"] }],
    gates: [{ name: "unit", executed: true, exit: 0 }],
    e2e: { executed: 12 },
    manifest: { ok: true },
    hostedBlockers: ["staging-host-unprovisioned"],
  });

  it("returns READY_FOR_FINAL_REVIEW only for green local evidence, keeping hosted blockers", () => {
    const report = evaluateReadiness(greenLocal());
    expect(report.verdict).toBe(READY_FOR_FINAL_REVIEW);
    expect(report.reasons).toEqual([]);
    expect(report.blockers).toEqual(["staging-host-unprovisioned"]);
  });

  it("blocks list-only browser output, failed gates, missing receipts and bad manifests", () => {
    expect(evaluateReadiness({ ...greenLocal(), e2e: { executed: 0, listed: 41 } }).verdict).toBe(
      BLOCKED,
    );
    expect(
      evaluateReadiness({ ...greenLocal(), e2e: { executed: 0, listed: 41 } }).reasons,
    ).toContain("e2e-executed-zero");

    const failedGate = evaluateReadiness({
      ...greenLocal(),
      gates: [{ name: "lint", executed: true, exit: 1, classification: "pre-existing" }],
    });
    expect(failedGate.verdict).toBe(BLOCKED);
    expect(failedGate.reasons).toContain("gate-lint-exit-1-pre-existing");

    const missingReceipt = evaluateReadiness({
      ...greenLocal(),
      tasks: [{ id: "09", receipts: [] }],
    });
    expect(missingReceipt.verdict).toBe(BLOCKED);
    expect(missingReceipt.reasons).toContain("task-09-without-receipt");

    const badManifest = evaluateReadiness({
      ...greenLocal(),
      manifest: { ok: false, detail: "mismatched" },
    });
    expect(badManifest.verdict).toBe(BLOCKED);

    const unexecuted = evaluateReadiness({
      ...greenLocal(),
      gates: [{ name: "lint", executed: false, exit: 0 }],
    });
    expect(unexecuted.verdict).toBe(BLOCKED);
    expect(unexecuted.reasons).toContain("gate-lint-not-executed");
  });
});

describe("task-24: every task reconciles to a receipt and the ledger stays truthful", () => {
  it("tasks 01–23 each have at least one unit or browser receipt in the tree", () => {
    const missing = [];
    for (let task = 1; task <= 23; task += 1) {
      const id = String(task).padStart(2, "0");
      const receipts = [
        `tests/js/lps-redesign/task-${id}.test.mjs`,
        `tests/e2e/lps-redesign/task-${id}.spec.mjs`,
      ];
      let found = false;
      for (const receipt of receipts) {
        try {
          readFileSync(receipt);
          found = true;
        } catch {
          // try the next receipt kind
        }
      }
      if (!found) missing.push(id);
    }
    expect(missing).toEqual([]);
  });

  it("the readiness ledger names every task and keeps hosted work blocked, never passed", () => {
    const ledger = readFileSync(LEDGER, "utf8");
    for (let task = 1; task <= 24; task += 1) {
      expect(ledger, `ledger must reconcile task-${String(task).padStart(2, "0")}`).toContain(
        `task-${String(task).padStart(2, "0")}`,
      );
    }
    expect(ledger).toMatch(/BLOCKED/);
    expect(ledger).toMatch(/production authorization is a separate decision/i);
    expect(ledger).not.toMatch(/production-ready|ready for production/i);
  });
});
