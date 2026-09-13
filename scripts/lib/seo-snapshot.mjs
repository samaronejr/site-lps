import { mkdir, readdir, readFile, rm, writeFile } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";

const MANIFEST = "manifest.json";

/**
 * Reads a snapshot directory into the document list consumed by the validators.
 *
 * A snapshot may extend a baseline snapshot: documents sharing a path replace
 * the baseline document, and `remove` drops one entirely. That keeps a failure
 * fixture down to the single defect it is meant to prove.
 */
export async function readSnapshot(directory) {
  const root = resolve(directory);
  const manifest = JSON.parse(await readFile(join(root, MANIFEST), "utf8"));
  let documents = [];
  let siteUrl = manifest.siteUrl ?? "";

  if (typeof manifest.extends === "string" && manifest.extends !== "") {
    const base = await readSnapshot(resolve(root, manifest.extends));
    documents = base.documents;
    siteUrl = manifest.siteUrl ?? base.siteUrl;
  }

  const index = new Map(documents.map((document) => [document.path, document]));
  for (const entry of manifest.documents ?? []) {
    if (entry.remove === true) {
      index.delete(entry.path);
      continue;
    }
    index.set(entry.path, {
      path: entry.path,
      status: entry.status ?? 200,
      contentType: entry.contentType ?? "text/html",
      location: entry.location ?? "",
      body:
        entry.file === undefined
          ? (entry.body ?? "")
          : await readFile(join(root, entry.file), "utf8"),
    });
  }

  return { siteUrl, documents: [...index.values()] };
}

/** Writes a snapshot directory from a crawl result. */
export async function writeSnapshot(directory, siteUrl, documents) {
  const root = resolve(directory);
  await rm(root, { recursive: true, force: true });
  await mkdir(root, { recursive: true });

  const entries = [];
  for (const [position, document] of documents.entries()) {
    const extension = document.contentType.includes("xml")
      ? "xml"
      : document.contentType.includes("html")
        ? "html"
        : "txt";
    const file = `documents/${String(position).padStart(3, "0")}-${slug(document.path)}.${extension}`;
    await mkdir(dirname(join(root, file)), { recursive: true });
    await writeFile(join(root, file), document.body);
    entries.push({
      path: document.path,
      status: document.status,
      contentType: document.contentType,
      location: document.location ?? "",
      file,
    });
  }

  await writeFile(
    join(root, MANIFEST),
    `${JSON.stringify({ siteUrl, documents: entries }, null, 2)}\n`,
  );
  return entries.length;
}

/** Lists every snapshot directory directly under a root. */
export async function listSnapshots(root) {
  const entries = await readdir(resolve(root), { withFileTypes: true });
  return entries
    .filter((entry) => entry.isDirectory())
    .map((entry) => entry.name)
    .sort();
}

function slug(path) {
  const cleaned = path.replaceAll(/[^a-z0-9]+/gi, "-").replace(/^-+|-+$/g, "");
  return cleaned === "" ? "root" : cleaned.slice(0, 60).toLowerCase();
}
