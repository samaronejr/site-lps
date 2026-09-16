#!/usr/bin/env node
/**
 * Todo 29 — agent-run role acceptance simulations.
 *
 * Runs the seven role simulations from
 * docs/handbook/role-acceptance-simulations.md against the local
 * production-equivalent staging runtime (Todo 27). Each simulation starts
 * from a clean account and fixture provisioned by probes/provision.php, acts
 * through the real REST/capability surface inside `wp eval-file` probes, and
 * finishes with observable assertions: post states, audit-ledger rows, HTTP
 * status codes and CLI exit codes.
 *
 * Usage:
 *   node scripts/acceptance/simulate.mjs [--root=<staging-root>] [--out=<dir>]
 *     [--keep] [--only=<s1,s2,...>]
 *
 * Defaults: --root=$LPS_STAGING_ROOT or .omo/evidence/task-27/staging,
 * --out=.omo/evidence/task-29/acceptance.
 *
 * Exit codes: 0 every simulation passed and cleanup completed,
 * 1 a check failed, 2 usage/environment error.
 */

import { spawnSync } from "node:child_process";
import { request as httpsRequest } from "node:https";
import {
  copyFileSync,
  existsSync,
  mkdirSync,
  readFileSync,
  readlinkSync,
  rmSync,
  symlinkSync,
  unlinkSync,
  writeFileSync,
} from "node:fs";
import { chmodSync } from "node:fs";
import { randomBytes } from "node:crypto";
import path from "node:path";
import { fileURLToPath } from "node:url";

const REPO = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..");
const PROBES_SRC = `${REPO}/scripts/acceptance/probes`;
const STAGING = path.resolve(
  arg("root", process.env.LPS_STAGING_ROOT ?? `${REPO}/.omo/evidence/task-27/staging`),
);
const OUT = path.resolve(arg("out", `${REPO}/.omo/evidence/task-29/acceptance`));
const OPS = `${STAGING}/ops`;
const PROBES_DST = `${OPS}/probes`;
const SECRETS = `${OPS}/secrets`;
const HTTPS_PORT = Number(process.env.LPS_EDGE_HTTPS_PORT ?? 8443);
const KEEP = process.argv.includes("--keep");
const ONLY = (arg("only", "") || "").split(",").filter(Boolean);

const commands = [];
const results = { started: new Date().toISOString(), simulations: [], checks: [] };

function arg(name, fallback) {
  const prefix = `--${name}=`;
  const found = process.argv.find((a) => a.startsWith(prefix));
  return found ? found.slice(prefix.length) : fallback;
}

function record(command, exit, extra = {}) {
  commands.push({ command, exit, ...extra });
  return exit;
}

/** Runs one host command and records its real exit code. */
function run(command, args, options = {}) {
  const started = Date.now();
  const result = spawnSync(command, args, {
    encoding: "utf8",
    cwd: REPO,
    timeout: options.timeoutMs ?? 300_000,
    env: { ...process.env, LPS_STAGING_ROOT: STAGING, ...(options.env ?? {}) },
  });
  const exit = result.status === null ? `signal:${result.signal}` : result.status;
  record(`${command} ${args.join(" ")}`, exit, { ms: Date.now() - started });
  return { exit, stdout: result.stdout ?? "", stderr: result.stderr ?? "" };
}

/** Runs one WP-CLI command on the staging runtime. */
function wp(args, options = {}) {
  return run(process.execPath, ["scripts/deploy/staging.mjs", "wp", ...args], {
    timeoutMs: options.timeoutMs ?? 300_000,
  });
}

/** Runs one probe file and parses its T29JSON transcript line. */
function probe(file) {
  const result = wp(["eval-file", `/lps-probes/${file}`]);
  const line = (result.stdout.split("\n").find((l) => l.startsWith("T29JSON:")) ?? "").slice(8);
  let transcript = null;
  try {
    transcript = line ? JSON.parse(line) : null;
  } catch {
    transcript = null;
  }
  return { ...result, transcript };
}

/**
 * Fetches one URL through the TLS edge, pinned to the deployed certificate
 * (the same trust contract scripts/deploy/staging.mjs uses).
 *
 * The edge can drop a socket while the origin restarts during the deploy and
 * rollback steps; a transport error is retried a bounded number of times and
 * then returned as a failed response object, never thrown past the caller.
 */
async function fetchEdge(pathname, attempts = 4) {
  let last = { status: 0, location: "", body: "", error: "no attempt" };
  for (let attempt = 0; attempt < attempts; attempt++) {
    last = await new Promise((resolve) => {
      const req = httpsRequest(
        {
          host: "127.0.0.1",
          port: HTTPS_PORT,
          path: pathname,
          method: "GET",
          ca: readFileSync(`${OPS}/tls/edge-cert.pem`),
          timeout: 30_000,
          rejectUnauthorized: true,
        },
        (res) => {
          const chunks = [];
          res.on("data", (chunk) => chunks.push(chunk));
          res.on("end", () =>
            resolve({
              status: res.statusCode,
              location: res.headers.location ?? "",
              body: Buffer.concat(chunks).toString("utf8"),
            }),
          );
          // A mid-response socket drop surfaces on res, not req; without this
          // listener the error is an uncaught exception that kills the run.
          res.on("error", (error) => resolve({ status: 0, location: "", body: "", error: String(error) }));
        },
      );
      req.on("timeout", () => req.destroy(new Error("timeout")));
      req.on("error", (error) => resolve({ status: 0, location: "", body: "", error: String(error) }));
      req.end();
    });
    if (last.status > 0) return last;
    if (attempt + 1 < attempts) await new Promise((r) => setTimeout(r, 500));
  }
  return last;
}

function check(name, pass, observed) {
  results.checks.push({ name, pass: !!pass, observed });
  return !!pass;
}

function failHard(why) {
  console.error(`simulate: ${why}`);
  finish(2);
}

function finish(code) {
  results.finished = new Date().toISOString();
  results.exit = code;
  mkdirSync(OUT, { recursive: true });
  writeFileSync(`${OUT}/results.json`, `${JSON.stringify(results, null, 2)}\n`);
  writeFileSync(
    `${OUT}/commands.jsonl`,
    commands.map((c) => JSON.stringify(c)).join("\n") + "\n",
  );
  process.exit(code);
}

// A transport-level surprise must still leave a results file behind; an
// uncaught exception that bypasses finish() would erase the evidence trail.
process.on("uncaughtException", (error) => {
  results.checks.push({ name: "runner:uncaught", pass: false, observed: String(error) });
  try {
    finish(1);
  } catch {
    process.exit(1);
  }
});

// --- environment gate --------------------------------------------------------

if (!existsSync(`${STAGING}/current`)) {
  failHard(`staging root ${STAGING} has no current release; run the Todo 27 deploy first`);
}
for (const dir of [PROBES_DST, `${OUT}`]) mkdirSync(dir, { recursive: true });
for (const file of [
  "lib.php",
  "provision.php",
  "s1-contributor.php",
  "s2-translator.php",
  "s3-section-editor.php",
  "s4-publisher.php",
  "s5-administrator.php",
  "s6-privacy-auditor.php",
  "s7-deployer.php",
  "cleanup.php",
]) {
  copyFileSync(`${PROBES_SRC}/${file}`, `${PROBES_DST}/${file}`);
}

// Per-run disposable secrets, staged mode-0600 inside the ops secrets volume
// and deleted during cleanup. They never enter transcripts or evidence.
mkdirSync(SECRETS, { recursive: true });
const totpSecret = randomBytes(20)
  .toString("base64")
  .replace(/[^A-Z2-7]/gi, "")
  .toUpperCase()
  .padEnd(32, "A")
  .slice(0, 32);
const password = `t29-${randomBytes(12).toString("hex")}`;
writeFileSync(`${SECRETS}/t29.env`, `T29_PASSWORD=${password}\nT29_TOTP_SECRET=${totpSecret}\n`, {
  mode: 0o600,
});
chmodSync(`${SECRETS}/t29.env`, 0o600);

const health = await fetchEdge("/lps-ops/health").catch((error) => ({ error: String(error) }));
if (!check("env:staging-health", health.status === 200, health)) {
  finish(2);
}

// --- provision: clean accounts and fixtures ----------------------------------

const provisioned = probe("provision.php");
if (!provisioned.transcript) {
  console.error(provisioned.stdout.slice(-2000), provisioned.stderr.slice(-2000));
  failHard("provision probe produced no transcript");
}
writeFileSync(`${OUT}/provision.json`, `${JSON.stringify(provisioned.transcript, null, 2)}\n`);
if (!check("provision:passed", provisioned.transcript.status === "passed", provisioned.transcript.failed)) {
  finish(1);
}
const fixture = provisioned.transcript.fixtures;

// --- S7 deployer: deploy and roll back ---------------------------------------
// Runs before the editorial simulations so the deploy's import-verify gate sees
// the clean corpus.

if (ONLY.length === 0 || ONLY.includes("s7")) {
  const s7 = { id: "s7-deployer", steps: [] };
  const boundary = probe("s7-deployer.php");
  s7.steps.push({ step: "credential-boundary", exit: boundary.exit, transcript: boundary.transcript });
  check("s7:credential-boundary", boundary.transcript?.status === "passed", boundary.transcript?.failed);

  const before = {
    current: readlinkSync(`${STAGING}/current`),
    previous: existsSync(`${STAGING}/previous`) ? readlinkSync(`${STAGING}/previous`) : null,
  };
  s7.before = before;

  const build = run("npm", ["run", "build"], { timeoutMs: 300_000 });
  s7.steps.push({ step: "npm run build", exit: build.exit, tail: build.stdout.slice(-400) });
  check("s7:build", build.exit === 0, build.exit);

  const release = `t29-${Date.now().toString(36)}`;
  const deploy = run(
    process.execPath,
    ["scripts/deploy/staging.mjs", "deploy", `--release=${release}`],
    { timeoutMs: 600_000 },
  );
  s7.steps.push({ step: `deploy ${release}`, exit: deploy.exit, tail: deploy.stdout.slice(-400) });
  check("s7:deploy", deploy.exit === 0, deploy.exit);

  // The edge can lag the flip by a beat; poll until it reports the release.
  let healthAfter = { status: 0 };
  let releaseId = {};
  for (let i = 0; i < 30; i++) {
    healthAfter = await fetchEdge("/lps-ops/health").catch((e) => ({ error: String(e) }));
    if (healthAfter.status === 200) {
      try {
        releaseId = JSON.parse(healthAfter.body ?? "{}");
      } catch {}
      if (releaseId.release === release) break;
    }
    await new Promise((r) => setTimeout(r, 1000));
  }
  check(
    "s7:health-after-deploy",
    healthAfter.status === 200 && releaseId.release === release,
    { status: healthAfter.status, release: releaseId.release },
  );
  const drift = wp(["lps", "import", "verify", "--input=/lps-import/launch-corpus.json", "--inventory=/lps-inventory"]);
  s7.steps.push({ step: "import verify (no content drift)", exit: drift.exit });
  check("s7:no-content-drift", drift.exit === 0, drift.exit);

  const priorRelease = path.basename(path.dirname(before.current));
  const rolled = run(
    process.execPath,
    ["scripts/deploy/staging.mjs", "rollback", `--to=${priorRelease}`],
    { timeoutMs: 600_000 },
  );
  s7.steps.push({ step: `rollback to ${priorRelease}`, exit: rolled.exit, tail: rolled.stdout.slice(-400) });
  check("s7:rollback", rolled.exit === 0, rolled.exit);
  let healthRolled = { status: 0 };
  for (let i = 0; i < 30; i++) {
    healthRolled = await fetchEdge("/lps-ops/health").catch((e) => ({ error: String(e) }));
    if (healthRolled.status === 200) break;
    await new Promise((r) => setTimeout(r, 1000));
  }
  check(
    "s7:prior-release-restored",
    healthRolled.status === 200 && readlinkSync(`${STAGING}/current`) === before.current,
    { status: healthRolled.status, current: readlinkSync(`${STAGING}/current`) },
  );

  // Restore the previous-release pointer and remove the probe release.
  try {
    unlinkSync(`${STAGING}/previous`);
  } catch {}
  if (before.previous) symlinkSync(before.previous, `${STAGING}/previous`);
  rmSync(`${STAGING}/releases/${release}`, { recursive: true, force: true });
  s7.releaseRemoved = !existsSync(`${STAGING}/releases/${release}`);
  check("s7:release-removed", s7.releaseRemoved, s7.releaseRemoved);

  results.simulations.push(s7);
}

// --- editorial simulations ----------------------------------------------------

const probes = [
  ["s1", "s1-contributor.php"],
  ["s2", "s2-translator.php"],
  ["s3", "s3-section-editor.php"],
  ["s4", "s4-publisher.php"],
  ["s5", "s5-administrator.php"],
  ["s6", "s6-privacy-auditor.php"],
];

for (const [id, file] of probes) {
  if (ONLY.length > 0 && !ONLY.includes(id)) continue;
  const result = probe(file);
  const sim = { id, file, exit: result.exit, transcript: result.transcript };
  if (!result.transcript) {
    sim.error = `no T29JSON transcript: ${result.stdout.slice(-500)} ${result.stderr.slice(-500)}`;
  }
  writeFileSync(`${OUT}/${id}.json`, `${JSON.stringify(sim, null, 2)}\n`);
  check(`${id}:passed`, result.transcript?.status === "passed", result.transcript?.failed ?? sim.error);
  results.simulations.push(sim);
}

// --- HTTP assertions for the publisher surface --------------------------------

const s4 = results.simulations.find((s) => s.id === "s4");
if (s4?.transcript?.status === "passed") {
  const urls = s4.transcript.urls ?? {};
  const pathOf = (u) => new URL(u).pathname;
  const news = await fetchEdge(pathOf(urls.news_new ?? urls.news));
  check("s4:http-news-200", news.status === 200, news.status);
  const pt = await fetchEdge(pathOf(urls.pt_area));
  check("s4:http-pt-200", pt.status === 200, pt.status);
  const en = await fetchEdge(pathOf(urls.en_area));
  check("s4:http-en-200", en.status === 200, en.status);
  const old = await fetchEdge(urls.old_path);
  check(
    "s4:http-one-hop-redirect",
    old.status === 301 && old.location.endsWith(urls.new_path),
    { status: old.status, location: old.location },
  );
  const verifyRedirects = wp(["lps", "redirects", "verify"]);
  check("s4:redirects-verify", verifyRedirects.exit === 0, verifyRedirects.exit);
}

// --- S5 import commands --------------------------------------------------------

const s5 = results.simulations.find((s) => s.id === "s5");
if (s5?.transcript?.status === "passed") {
  const dry = wp(["lps", "import", "dry-run", "--input=/lps-import/launch-corpus.json"]);
  let guard = null;
  try {
    guard = JSON.parse(dry.stdout).mutation_guard;
  } catch {}
  check(
    "s5:dry-run-mutation-guard",
    dry.exit === 0 && guard?.unchanged === true,
    { exit: dry.exit, guard },
  );
  const apply = wp(["lps", "import", "apply", "--input=/lps-import/launch-corpus.json"], {
    timeoutMs: 300_000,
  });
  let writes = null;
  try {
    writes = JSON.parse(apply.stdout).writes;
  } catch {}
  check("s5:reapply-zero-writes", apply.exit === 0 && writes === 0, { exit: apply.exit, writes });
}

// --- cleanup -------------------------------------------------------------------

if (!KEEP) {
  const cleaned = probe("cleanup.php");
  writeFileSync(`${OUT}/cleanup.json`, `${JSON.stringify(cleaned.transcript, null, 2)}\n`);
  check("cleanup:passed", cleaned.transcript?.status === "passed", cleaned.transcript?.failed);
  rmSync(`${SECRETS}/t29.env`, { force: true });
  for (const file of [
    "lib.php",
    "provision.php",
    "s1-contributor.php",
    "s2-translator.php",
    "s3-section-editor.php",
    "s4-publisher.php",
    "s5-administrator.php",
    "s6-privacy-auditor.php",
    "s7-deployer.php",
    "cleanup.php",
  ]) {
    rmSync(`${PROBES_DST}/${file}`, { force: true });
  }
}

const failed = results.checks.filter((c) => !c.pass);
console.log(
  JSON.stringify(
    {
      status: failed.length === 0 ? "passed" : "failed",
      checks: results.checks.length,
      failed: failed.map((c) => c.name),
      out: OUT,
    },
    null,
    2,
  ),
);
finish(failed.length === 0 ? 0 : 1);
