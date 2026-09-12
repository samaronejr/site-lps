/**
 * Resilient live capture for the accessibility gate.
 *
 * The PHP-in-WebAssembly runtime used for local verification serves a page in tens
 * of seconds and can wedge under sequential load, so this runner captures one route
 * at a time, resumes from an existing manifest, and restarts the server it owns
 * when the port stops answering. It never signals a process it did not start.
 */

import { execFile } from "node:child_process";
import { createHash } from "node:crypto";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { promisify } from "node:util";
import { routes } from "../../lib/a11y-inventory.mjs";

const run = promisify(execFile);

function option(argv, name, fallback) {
  const index = argv.indexOf(`--${name}`);
  return index === -1 ? fallback : argv[index + 1];
}

async function readManifest(file) {
  try {
    return JSON.parse(await readFile(file, "utf8"));
  } catch {
    return null;
  }
}

/**
 * Re-applies the harness rewrite flush.
 *
 * The local Playground database registers seed rewrite rules progressively, and a
 * fresh worker boots with the stale set, so pretty permalinks for records seeded
 * later resolve to 404 until the rules are rebuilt. This is a property of the
 * local runtime, not of the product.
 */
async function flushRewrites(baseUrl) {
  try {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 240_000);
    await fetch(new URL("/pt-br/?lps_a11y_flush=task23", baseUrl), { signal: controller.signal });
    clearTimeout(timer);
  } catch {
    // A failed flush is retried on the next restart.
  }
}

async function isHealthy(baseUrl, timeoutMs) {
  try {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    const response = await fetch(new URL("/pt-br/", baseUrl), { signal: controller.signal });
    clearTimeout(timer);
    return response.status === 200;
  } catch {
    return false;
  }
}

async function restartServer(serveScript, portsLog, baseUrl, bootTimeoutMs) {
  await appendLog(
    portsLog,
    `${new Date().toISOString()} server unresponsive; restarting the task-owned server`,
  );
  await run("bash", [serveScript]);
  const deadline = Date.now() + bootTimeoutMs;
  while (Date.now() < deadline) {
    if (await isHealthy(baseUrl, 120_000)) {
      await appendLog(portsLog, `${new Date().toISOString()} server healthy again`);
      await flushRewrites(baseUrl);
      return true;
    }
    await new Promise((resolve) => setTimeout(resolve, 5000));
  }
  return false;
}

async function appendLog(file, line) {
  const previous = await readFile(file, "utf8").catch(() => "");
  await writeFile(file, `${previous}${line}\n`);
}

/** Captures every route, resuming and restarting as needed. */
export async function captureLive({
  baseUrl,
  outputDir,
  serveScript,
  portsLog,
  attempts,
  requestTimeoutMs,
}) {
  await mkdir(outputDir, { recursive: true });
  const manifestFile = path.join(outputDir, "manifest.json");
  const existing = await readManifest(manifestFile);
  const captured = new Map((existing?.pages ?? []).map((page) => [page.id, page]));
  await flushRewrites(baseUrl);

  for (const route of routes()) {
    if (captured.has(route.id)) continue;
    const url = new URL(route.path, baseUrl).toString();
    let lastError = "";
    for (let attempt = 1; attempt <= attempts; attempt += 1) {
      try {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), requestTimeoutMs);
        const response = await fetch(url, { redirect: "follow", signal: controller.signal });
        const html = await response.text();
        clearTimeout(timer);
        const file = `${route.id}.html`;
        await writeFile(path.join(outputDir, file), html);
        captured.set(route.id, {
          id: route.id,
          url,
          path: route.path,
          locale: route.locale,
          template: route.template,
          state: route.state,
          status: response.status,
          file,
          bytes: Buffer.byteLength(html),
          sha256: createHash("sha256").update(html).digest("hex"),
          capturedAt: new Date().toISOString(),
          attempts: attempt,
        });
        process.stderr.write(`${response.status} ${route.id} (attempt ${attempt})\n`);
        break;
      } catch (error) {
        lastError = String(error?.cause?.code ?? error?.name ?? error);
        process.stderr.write(`retry ${route.id}: ${lastError}\n`);
        if (!(await isHealthy(baseUrl, 120_000))) {
          await restartServer(serveScript, portsLog, baseUrl, 600_000);
        }
      }
    }
    if (!captured.has(route.id)) {
      process.stderr.write(`FAILED ${route.id}: ${lastError}\n`);
    }
    await writeFile(
      manifestFile,
      `${JSON.stringify(
        {
          schemaVersion: 1,
          baseUrl,
          capturedAt: new Date().toISOString(),
          pages: routes()
            .map((entry) => captured.get(entry.id))
            .filter(Boolean),
        },
        null,
        2,
      )}\n`,
    );
  }
  return (await readManifest(manifestFile)) ?? { pages: [] };
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const argv = process.argv.slice(2);
  const manifest = await captureLive({
    baseUrl: option(argv, "base-url", process.env.LPS_BASE_URL ?? "http://127.0.0.1:8894"),
    outputDir: option(argv, "output-dir", ".omo/evidence/task-23/live/pages"),
    serveScript: option(argv, "serve-script", ".omo/evidence/task-23/runtime/serve.sh"),
    portsLog: option(argv, "ports-log", ".omo/evidence/task-23/live/ports.log"),
    attempts: Number(option(argv, "attempts", "4")),
    requestTimeoutMs: Number(option(argv, "request-timeout", "300000")),
  });
  const missing = routes().filter((route) => !manifest.pages.some((page) => page.id === route.id));
  process.stdout.write(
    `${JSON.stringify({ captured: manifest.pages.length, missing: missing.map((route) => route.id) }, null, 2)}\n`,
  );
  if (missing.length > 0) process.exitCode = 1;
}
