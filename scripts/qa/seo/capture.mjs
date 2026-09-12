/**
 * Resumable crawl capture.
 *
 * The local WordPress Playground runtime answers a rendered page in seconds, so
 * a full bilingual crawl is captured across several bounded runs. Each document
 * is written as soon as it arrives and skipped on the next run, which makes the
 * capture restartable without ever refetching or losing a page.
 */

import { mkdir, readdir, readFile, writeFile } from "node:fs/promises";
import { join, resolve } from "node:path";

const MACHINE_PATHS = [
  "/robots.txt",
  "/sitemap.xml",
  "/sitemap-pt-br.xml",
  "/sitemap-en.xml",
  "/pt-br/noticias/feed/",
  "/en/news/feed/",
  "/pt-br/eventos/feed/",
  "/en/events/feed/",
];

export async function capture({
  baseUrl,
  directory,
  extraPaths = [],
  budgetMs = 480000,
  concurrency = 3,
  timeoutMs = 120000,
}) {
  const origin = baseUrl.replace(/\/+$/, "");
  const raw = resolve(directory);
  await mkdir(raw, { recursive: true });
  const deadline = Date.now() + budgetMs;
  const cookie = await warmUp(origin, timeoutMs);
  const done = new Set(
    (await readdir(raw))
      .filter((name) => name.endsWith(".json"))
      .map((name) => name.replace(/\.json$/, "")),
  );

  const store = async (document) => {
    if (isSelfRedirect(document) || document.status >= 500) {
      return;
    }
    await writeFile(join(raw, `${key(document.path)}.json`), `${JSON.stringify(document)}\n`);
    done.add(key(document.path));
  };

  const pending = async (paths) => paths.filter((path) => !done.has(key(path)));

  const run = async (paths) => {
    const queue = await pending(paths);
    for (let index = 0; index < queue.length; index += concurrency) {
      if (Date.now() > deadline) {
        return false;
      }
      const batch = queue.slice(index, index + concurrency);
      const documents = await Promise.all(
        batch.map((path) => fetchDocument(origin, path, cookie, timeoutMs)),
      );
      for (const document of documents) {
        await store(document);
      }
    }
    return true;
  };

  await run(MACHINE_PATHS);

  const listed = [];
  for (const path of ["/sitemap-pt-br.xml", "/sitemap-en.xml"]) {
    const stored = await readStored(raw, path);
    if (stored !== null) {
      listed.push(...locations(stored.body, origin));
    }
  }

  const complete = await run([...new Set([...listed, ...extraPaths])]);
  const remaining = (await pending([...MACHINE_PATHS, ...listed, ...extraPaths])).length;
  return { captured: done.size, remaining, complete: complete && remaining === 0 };
}

export async function collect(directory) {
  const raw = resolve(directory);
  const names = (await readdir(raw)).filter((name) => name.endsWith(".json")).sort();
  const documents = [];
  for (const name of names) {
    documents.push(JSON.parse(await readFile(join(raw, name), "utf8")));
  }
  return documents.sort((left, right) => left.path.localeCompare(right.path));
}

async function readStored(raw, path) {
  try {
    return JSON.parse(await readFile(join(raw, `${key(path)}.json`), "utf8"));
  } catch {
    return null;
  }
}

async function warmUp(origin, timeoutMs) {
  const response = await fetch(`${origin}/pt-br/`, {
    redirect: "manual",
    signal: AbortSignal.timeout(timeoutMs),
  });
  const raw = response.headers.getSetCookie?.() ?? [];
  await response.arrayBuffer();
  return raw.map((value) => value.split(";")[0]).join("; ");
}

async function fetchDocument(origin, path, cookie, timeoutMs, attempt = 0) {
  let response;
  let body;
  try {
    response = await fetch(`${origin}${path}`, {
      redirect: "manual",
      headers: cookie === "" ? {} : { cookie },
      signal: AbortSignal.timeout(timeoutMs),
    });
    body = await response.text();
  } catch (error) {
    if (attempt >= 4) {
      throw error;
    }
    await new Promise((resolve) => setTimeout(resolve, 2000 * (attempt + 1)));
    return fetchDocument(origin, path, cookie, timeoutMs, attempt + 1);
  }
  if (response.status >= 500 && attempt < 2) {
    return fetchDocument(origin, path, cookie, timeoutMs, attempt + 1);
  }
  return {
    path,
    status: response.status,
    contentType: (response.headers.get("content-type") ?? "").split(";")[0].trim(),
    location: response.headers.get("location") ?? "",
    body,
  };
}

function locations(xml, origin) {
  const found = [];
  for (const match of xml.matchAll(/<loc>([^<]+)<\/loc>/g)) {
    const url = match[1].trim();
    if (url.startsWith(origin)) {
      found.push(url.slice(origin.length) || "/");
    }
  }
  return found;
}

/**
 * Reports whether a response is the runtime's own auto-login bounce.
 *
 * A `5xx` is discarded by the caller for the same reason: the local runtime
 * answers `WordPress is not ready yet` while it boots, which is a fact about
 * the runtime rather than a document the site publishes.
 *
 * Playground answers the first request of a session with a 302 back to the same
 * address. Recording that bounce would publish a snapshot claiming the site
 * temporarily redirects every page, so it is never stored.
 */
function isSelfRedirect(document) {
  if (document.status !== 302) {
    return false;
  }
  const target = document.location.replace(/^https?:\/\/[^/]+/, "");
  return target === document.path;
}

function key(path) {
  const cleaned = path.replaceAll(/[^a-z0-9]+/gi, "-").replace(/^-+|-+$/g, "");
  return cleaned === "" ? "root" : cleaned.toLowerCase();
}
