import { spawn } from "node:child_process";
import { once } from "node:events";
import { createWriteStream } from "node:fs";
import { cp, mkdir, readFile, writeFile } from "node:fs/promises";
import net from "node:net";
import { backup, DatabaseSync } from "node:sqlite";
import { validatePlan } from "./contract.mjs";
import { crosscheck } from "./crosscheck.mjs";
import { matrix } from "./inventory.mjs";
import { assertProvisioned, prepare, root, sha } from "./prepare.mjs";

const phase = process.argv[2];
if (!["baseline-red", "green"].includes(phase)) throw Error("Use baseline-red or green");
if (process.env.LPS_TASK24_LOCKED !== "1")
  throw Error("Run through flock with LPS_TASK24_LOCKED=1");
await mkdir(`${root}/logs`, { recursive: true });
if (phase === "baseline-red") await prepare();
if (phase === "green") {
  // Reuses the earlier provisioning run, so the validated fixture state is re-checked
  // instead of assumed: stale, partial or edited runtimes fail here, not silently later.
  await assertProvisioned();
  await cp(
    "scripts/qa/task24/inspect.php",
    `${root}/runtime/wordpress/wp-content/mu-plugins/lps-zzz-task24k.php`,
  );
  await cp(
    "scripts/qa/task24/migrate.php",
    `${root}/runtime/wordpress/wp-content/mu-plugins/lps-zzz-task24k-migrate.php`,
  );
}
const probe = net.createServer();
probe.listen(8903, "127.0.0.1");
await once(probe, "listening");
await new Promise((resolve) => probe.close(resolve));
const args = [
  "node_modules/@wp-playground/cli/cli.js",
  "server",
  "--port=8903",
  "--workers=1",
  "--php=8.3",
  "--wordpress-install-mode=do-not-attempt-installing",
  "--mount-dir-before-install",
  `${root}/runtime/wordpress`,
  "/wordpress",
];
for (const name of ["lps-theme", "lps-content-model"])
  args.push(
    "--mount-dir",
    `${root}/runtime/${name}`,
    `/wordpress/wp-content/${name === "lps-theme" ? "themes/" : "plugins/"}${name}`,
  );
const child = spawn(process.execPath, args, {
  stdio: ["ignore", "pipe", "pipe"],
  env: { ...process.env, TMPDIR: `${root}/tmp` },
});
let activeQA;
process.once("SIGTERM", () => {
  activeQA?.kill("SIGTERM");
  child.kill("SIGTERM");
});
console.log(
  JSON.stringify({ resource: "wordpress", pid: child.pid, port: 8903, tmp: `${root}/tmp`, phase }),
);
await writeFile(
  `${root}/logs/${phase}-runtime.json`,
  JSON.stringify({ pid: child.pid, port: 8903, args, phase }, null, 2),
);
const exit = once(child, "close");
const log = createWriteStream(`${root}/logs/${phase}-server.log`);
const ready = new Promise((resolve, reject) => {
  const deadline = setTimeout(() => reject(Error("WordPress readiness deadline")), 180000);
  let text = "";
  for (const stream of [child.stdout, child.stderr])
    stream.on("data", (chunk) => {
      log.write(chunk);
      text = (text + chunk).slice(-4096);
      if (text.includes("Ready!")) {
        clearTimeout(deadline);
        resolve();
      }
    });
  child.once("error", (error) => {
    clearTimeout(deadline);
    reject(error);
  });
  child.once("close", (code) => {
    clearTimeout(deadline);
    reject(Error(`WordPress exited before ready: ${code}`));
  });
});
async function qa(stage) {
  const output = createWriteStream(`${root}/logs/${stage}.log`);
  const p = spawn(process.execPath, [`${root}/qa.mjs`, stage], {
    env: { ...process.env, LPS_QA_URL: "http://127.0.0.1:8903" },
    stdio: ["ignore", "pipe", "pipe"],
  });
  activeQA = p;
  for (const s of [p.stdout, p.stderr])
    s.on("data", (d) => {
      output.write(d);
      process.stdout.write(d);
    });
  const timer = setTimeout(() => p.kill("SIGTERM"), 600000);
  const [code, signal] = await once(p, "close");
  activeQA = undefined;
  clearTimeout(timer);
  output.end();
  await writeFile(`${root}/logs/${stage}-exit.json`, JSON.stringify({ code, signal }));
  return code;
}
let status = 0;
try {
  await ready;
  if (phase === "green") {
    const res = await fetch("http://127.0.0.1:8903/?task24k=apply", {
      signal: AbortSignal.timeout(90000),
    });
    const body = await res.text();
    const migrationFile = JSON.parse(body).alreadyApplied
      ? "migration-replay-start.json"
      : "migration.json";
    await writeFile(`${root}/manifests/${migrationFile}`, body);
    if (!res.ok || JSON.parse(body).error) throw Error(`Migration failed ${body.slice(0, 500)}`);
    status = await qa("green");
    const inventory = await crosscheck("http://127.0.0.1:8903");
    console.log(JSON.stringify({ crosscheck: inventory.pass, gaps: inventory.gaps }));
    if (!inventory.pass) status = 1;
    const replay = await fetch("http://127.0.0.1:8903/?task24k=apply", {
      signal: AbortSignal.timeout(45000),
    }).then((r) => r.json());
    await writeFile(`${root}/manifests/migration-replay.json`, JSON.stringify(replay, null, 2));
    if (replay.alreadyApplied !== true || replay.changes.length !== 0)
      throw Error("Migration replay made unexpected writes");
  } else {
    const base = await qa("baseline");
    if (base !== 0) throw Error(`Baseline characterization failed: ${base}`);
    const red = await qa("red");
    if (red !== 1) throw Error(`Expected targeted RED=1, got ${red}`);
  }
} finally {
  child.kill("SIGTERM");
  const timeout = setTimeout(() => child.kill("SIGKILL"), 20000);
  const [code, signal] = await exit;
  clearTimeout(timeout);
  log.end();
  await writeFile(
    `${root}/logs/${phase}-teardown.json`,
    JSON.stringify({
      pid: child.pid,
      code,
      signal,
      port: 8903,
      closedAt: new Date().toISOString(),
    }),
  );
  console.log(
    JSON.stringify({ resource: "wordpress", pid: child.pid, code, signal, cleanup: "exited" }),
  );
}
process.exitCode = status;
if (phase === "green") {
  const database = new DatabaseSync(`${root}/runtime/wordpress/wp-content/database/.ht.sqlite`, {
    readOnly: true,
  });
  await backup(database, `${root}/manifests/fixture-fixed.sqlite`);
  database.close();
  const source = JSON.parse(await readFile(`${root}/manifests/identity.json`));
  const fixtureHash = sha(await readFile(`${root}/manifests/fixture-fixed.sqlite`));
  const plan = { schemaVersion: 1, sourceHash: source.sourceHash, fixtureHash, cells: matrix() };
  await writeFile(`${root}/manifests/required-matrix.json`, JSON.stringify(plan, null, 2));
  await writeFile(
    `${root}/manifests/inventory-validation.json`,
    JSON.stringify(validatePlan(plan, { sourceHash: source.sourceHash, fixtureHash }), null, 2),
  );
}
