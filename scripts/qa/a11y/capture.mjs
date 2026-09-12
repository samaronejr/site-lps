/**
 * Captures the server-rendered document of every inventory route.
 *
 * The site renders without JavaScript, so an HTTP capture is the faithful document
 * the browser, axe, and pa11y also receive. Each capture records its status and a
 * checksum, so the gate can prove the audited bytes are the captured bytes.
 */

import { createHash } from "node:crypto";
import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";
import { routes } from "../../lib/a11y-inventory.mjs";

function option(argv, name, fallback) {
  const index = argv.indexOf(`--${name}`);
  return index === -1 ? fallback : argv[index + 1];
}

/** Captures every route into `outputDir` and writes `manifest.json`. */
export async function captureRoutes({ baseUrl, outputDir }) {
  await mkdir(outputDir, { recursive: true });
  const pages = [];
  for (const route of routes()) {
    const url = new URL(route.path, baseUrl).toString();
    const response = await fetch(url, {
      redirect: "follow",
      headers: { "Accept-Language": route.locale },
    });
    const html = await response.text();
    const file = `${route.id}.html`;
    await writeFile(path.join(outputDir, file), html);
    pages.push({
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
    });
    process.stderr.write(`${response.status} ${url}\n`);
  }
  const manifest = { schemaVersion: 1, baseUrl, capturedAt: new Date().toISOString(), pages };
  await writeFile(path.join(outputDir, "manifest.json"), `${JSON.stringify(manifest, null, 2)}\n`);
  return manifest;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const argv = process.argv.slice(2);
  const manifest = await captureRoutes({
    baseUrl: option(argv, "base-url", process.env.LPS_BASE_URL ?? "http://127.0.0.1:8894"),
    outputDir: option(argv, "output-dir", ".omo/evidence/task-23/live/pages"),
  });
  const failed = manifest.pages.filter((page) => page.status >= 500);
  process.stdout.write(
    `${JSON.stringify({ captured: manifest.pages.length, serverErrors: failed.length }, null, 2)}\n`,
  );
  if (failed.length > 0) process.exitCode = 1;
}
