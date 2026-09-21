import { spawnSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { expect, test } from "@playwright/test";

/**
 * Task 23 — staging deployment, media/database restore and rollback rehearsal.
 *
 * The spec drives the real rehearsal end-to-end against a dedicated
 * WordPress Playground environment on port 8906 whose docroot (and SQLite
 * database) persists at /tmp/lps-t23-env/wordpress. `scripts/deploy/
 * local-rehearsal.mjs run` performs the whole cycle — build artifact deploy
 * via rsync, consistent SQLite backup plus uploads archive, real REST
 * mutations, restore with hash verification, and application rollback via a
 * real `git revert` on the release repository — and writes a JSON report with
 * the exit code of every step.
 *
 * The first test runs the rehearsal once (chromium project only; the
 * rehearsal is browser-agnostic). The remaining tests assert on the recorded
 * report and on the live HTTP surface of the post-rollback environment.
 *
 * This is a local rehearsal: it proves the deploy/backup/restore/rollback
 * contract on a disposable target. It does not claim a hosted staging
 * verification — the institution-managed staging host, DNS control,
 * encryption recipient key and approved RPO/RTO are recorded as blockers in
 * the report and in docs/operations/release-runbook-index.md.
 */

const ENV_DIR = process.env.LPS_T23_ENV_DIR ?? join(tmpdir(), "lps-t23-env");
const PORT = Number(process.env.LPS_T23_PORT ?? 8906);
const BASE_URL = `http://localhost:${PORT}`;
const REPORT_PATH = join(ENV_DIR, "rehearsal-report.json");
const DRIVER = "scripts/deploy/local-rehearsal.mjs";
const AUTO_LOGIN_COOKIE = {
  name: "playground_auto_login_already_happened",
  value: "1",
};

test.describe.configure({ mode: "serial" });

function readReport() {
  expect(existsSync(REPORT_PATH), `rehearsal report missing at ${REPORT_PATH}`).toBe(true);
  return JSON.parse(readFileSync(REPORT_PATH, "utf8"));
}

function step(report, id) {
  const entry = report.steps.find((candidate) => candidate.id === id);
  expect(entry, `report is missing step ${id}`).toBeDefined();
  return entry;
}

test("the local rehearsal runs end-to-end and reports pass", async () => {
  test.skip(
    test.info().project.name !== "chromium",
    "the rehearsal is browser-agnostic; run it once",
  );
  test.setTimeout(600_000);

  const build = spawnSync("npm", ["run", "build"], { encoding: "utf8" });
  expect(build.status, `npm run build failed:\n${build.stderr}`).toBe(0);

  const rehearsal = spawnSync(
    process.execPath,
    [DRIVER, "run", `--env-dir=${ENV_DIR}`, `--port=${PORT}`, "--confirm-local-rehearsal"],
    { encoding: "utf8", timeout: 540_000 },
  );
  if (rehearsal.status !== 0) {
    // Surface the report tail before failing so the step that broke is visible.
    const tail = existsSync(REPORT_PATH)
      ? readFileSync(REPORT_PATH, "utf8").slice(-4000)
      : "(no report written)";
    throw new Error(
      `rehearsal exited ${rehearsal.status}\nstderr: ${rehearsal.stderr}\nreport tail: ${tail}`,
    );
  }
  expect(rehearsal.status).toBe(0);

  const report = readReport();
  expect(report.status).toBe("pass");
});

test("every rehearsal step recorded a zero exit code", async () => {
  test.skip(
    test.info().project.name !== "chromium",
    "the rehearsal report is shared across projects",
  );
  const report = readReport();

  for (const id of [
    "preflight",
    "prepare",
    "release-r1",
    "deploy-r1",
    "serve",
    "login",
    "verify-r1",
    "backup",
    "deploy-r2",
    "mutate",
    "restore",
    "rollback",
  ]) {
    expect(step(report, id).exit, `step ${id}`).toBe(0);
  }
});

test("the backup captured database, files, config and offering relationships", async () => {
  test.skip(test.info().project.name !== "chromium", "report assertion runs once");
  const report = readReport();
  const backup = step(report, "backup");

  expect(backup.manifestDigest).toMatch(/^[0-9a-f]{64}$/);
  expect(backup.fileCount).toBeGreaterThan(0);

  const backupManifest = JSON.parse(
    readFileSync(join(ENV_DIR, "backups/b1/manifest.json"), "utf8"),
  );
  expect(backupManifest.database.sha256).toMatch(/^[0-9a-f]{64}$/);
  expect(backupManifest.files.sha256).toMatch(/^[0-9a-f]{64}$/);
  expect(Object.keys(backupManifest.files.entries)).toContain(
    "wp-content/uploads/lps-qa-media/qa-demo.webm",
  );
  expect(backupManifest.content.manifest.offerings.length).toBeGreaterThan(0);
  const seeded = backupManifest.content.manifest.offerings.find(
    (offering) => offering.slug === "sinais-e-sistemas-2026-2-t01",
  );
  expect(seeded, "seeded offering missing from backup manifest").toBeDefined();
  expect(seeded.team.length).toBeGreaterThan(0);
  expect(seeded.course_id).toBeGreaterThan(0);
});

test("restore reconstructed records and resource bytes", async () => {
  test.skip(test.info().project.name !== "chromium", "report assertion runs once");
  const report = readReport();
  const restore = step(report, "restore");

  expect(restore.sqliteIntegrity).toBe(true);
  expect(restore.contentMatches).toBe(true);
  expect(restore.fileRestored).toBe(true);
  expect(restore.markerAbsent).toBe(true);
});

test("rollback restored the prior served release without corrupting history", async () => {
  test.skip(test.info().project.name !== "chromium", "report assertion runs once");
  const report = readReport();
  const rollback = step(report, "rollback");

  expect(rollback.revertExit).toBe(0);
  expect(rollback.current).toBe("r1");
  expect(rollback.servedRelease).toBe("r1");
  expect(rollback.markerFileGone).toBe(true);
  expect(rollback.additiveTablesSurvived).toBe(true);
  // History is preserved: the revert is a new commit, not a rewrite.
  expect(rollback.history).toContain("release r1");
  expect(rollback.history).toContain("release r2");
  expect(rollback.history).toMatch(/revert/i);
});

test("the post-rollback environment serves the prior release over HTTP", async ({ browser }) => {
  test.skip(test.info().project.name !== "chromium", "live HTTP assertion runs once");
  const context = await browser.newContext({ baseURL: BASE_URL });
  await context.addCookies([{ ...AUTO_LOGIN_COOKIE, url: BASE_URL }]);

  const rest = await context.request.get("/wp-json/");
  expect(rest.status()).toBe(200);
  expect(rest.headers()["x-lps-rehearsal-release"] ?? null).toBeNull();

  const manifest = await context.request.get("/release-manifest.json");
  expect(manifest.status()).toBe(200);
  expect((await manifest.json()).releaseId).toBe("r1");

  const offerings = await context.request.get("/wp-json/wp/v2/offerings?per_page=100");
  expect(offerings.status()).toBe(200);
  const slugs = (await offerings.json()).map((offering) => offering.slug);
  expect(slugs).toContain("sinais-e-sistemas-2026-2-t01");

  const localeRoot = await context.request.get("/pt-br/");
  expect(localeRoot.status()).toBe(200);

  await context.close();
});

test.afterAll(async () => {
  // Leave the environment stopped but intact: the docroot, backup artifacts
  // and release repository under /tmp/lps-t23-env are the rehearsal evidence.
  spawnSync(process.execPath, [DRIVER, "stop", `--env-dir=${ENV_DIR}`], {
    encoding: "utf8",
  });
});
