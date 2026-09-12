import { readFile } from "node:fs/promises";
import { resolve } from "node:path";

const DISPOSITIONS = new Set(["migrate", "link", "301", "410", "private-excluded"]);
const RIGHTS = new Set([
  "public-record",
  "public-record-no-copy",
  "externally-maintained",
  "private-excluded",
  "rights-cleared",
]);
const CHECKSUM = /^sha256:[a-f0-9]{64}$/;
const TIMESTAMP = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

export function parseCsv(input) {
  const rows = [];
  let row = [];
  let field = "";
  let quoted = false;
  for (let index = 0; index < input.length; index += 1) {
    const character = input[index];
    if (quoted) {
      if (character === '"' && input[index + 1] === '"') {
        field += '"';
        index += 1;
      } else if (character === '"') {
        quoted = false;
      } else {
        field += character;
      }
    } else if (character === '"') {
      quoted = true;
    } else if (character === ",") {
      row.push(field);
      field = "";
    } else if (character === "\n") {
      row.push(field.replace(/\r$/, ""));
      if (row.some((value) => value !== "")) rows.push(row);
      row = [];
      field = "";
    } else {
      field += character;
    }
  }
  if (quoted) throw new Error("unterminated quoted CSV field");
  if (field || row.length > 0) {
    row.push(field.replace(/\r$/, ""));
    rows.push(row);
  }
  const [headers, ...values] = rows;
  if (!headers) throw new Error("empty CSV");
  return values.map((valuesRow, index) => {
    if (valuesRow.length !== headers.length) {
      throw new Error(
        `CSV row ${index + 2} has ${valuesRow.length} fields; expected ${headers.length}`,
      );
    }
    return Object.fromEntries(headers.map((header, column) => [header, valuesRow[column]]));
  });
}

export function normalizeUrl(value) {
  const url = new URL(value);
  url.hash = "";
  url.hostname = url.hostname.toLowerCase();
  if (
    (url.protocol === "https:" && url.port === "443") ||
    (url.protocol === "http:" && url.port === "80")
  ) {
    url.port = "";
  }
  const sorted = [...url.searchParams.entries()].sort(
    ([leftKey, leftValue], [rightKey, rightValue]) => {
      const left = `${leftKey}\0${leftValue}`;
      const right = `${rightKey}\0${rightValue}`;
      return left < right ? -1 : left > right ? 1 : 0;
    },
  );
  url.search = "";
  for (const [key, value] of sorted) url.searchParams.append(key, value);
  if (url.pathname !== "/") url.pathname = url.pathname.replace(/\/+$/, "");
  return url.toString();
}

function increment(target, key) {
  target[key] = (target[key] ?? 0) + 1;
}

function validateCommon(rows, file, errors) {
  const required = [
    "id",
    "source_url",
    "provenance",
    "owner_candidate",
    "locale",
    "freshness",
    "rights_privacy_state",
    "checksum",
    "disposition",
  ];
  for (const [index, row] of rows.entries()) {
    const label = `${file}:${index + 2}`;
    for (const field of required) {
      if (!row[field]?.trim()) errors.push(`${label} missing ${field}`);
    }
    if (!DISPOSITIONS.has(row.disposition))
      errors.push(`${label} invalid disposition '${row.disposition}'`);
    if (!RIGHTS.has(row.rights_privacy_state)) {
      errors.push(`${label} invalid rights/privacy state '${row.rights_privacy_state}'`);
    }
    if (!CHECKSUM.test(row.checksum)) errors.push(`${label} invalid sha256 checksum`);
    try {
      new URL(row.source_url);
    } catch {
      errors.push(`${label} invalid source URL`);
    }
  }
}

export async function validateInventory(directory = "content/inventory") {
  const root = resolve(directory);
  const errors = [];
  const [records, urls, assets, sources] = await Promise.all([
    readFile(resolve(root, "records.csv"), "utf8").then(parseCsv),
    readFile(resolve(root, "urls.csv"), "utf8").then(parseCsv),
    readFile(resolve(root, "assets.csv"), "utf8").then(parseCsv),
    readFile(resolve(root, "sources.md"), "utf8"),
  ]);

  validateCommon(records, "records.csv", errors);
  validateCommon(urls, "urls.csv", errors);
  validateCommon(assets, "assets.csv", errors);

  const identifiers = new Set();
  for (const [file, rows] of [
    ["records.csv", records],
    ["urls.csv", urls],
    ["assets.csv", assets],
  ]) {
    for (const [index, row] of rows.entries()) {
      if (identifiers.has(row.id))
        errors.push(`${file}:${index + 2} duplicate inventory id '${row.id}'`);
      identifiers.add(row.id);
      const timestamp = row.captured_at ?? row.discovered_at;
      if (!TIMESTAMP.test(timestamp ?? "") || Number.isNaN(Date.parse(timestamp))) {
        errors.push(`${file}:${index + 2} invalid capture timestamp`);
      }
    }
  }

  const normalized = new Map();
  for (const [index, row] of urls.entries()) {
    let calculated;
    try {
      calculated = normalizeUrl(row.url);
    } catch {
      errors.push(`urls.csv:${index + 2} invalid discovered URL`);
      continue;
    }
    if (row.normalized_url !== calculated) {
      errors.push(`urls.csv:${index + 2} normalized_url does not match '${calculated}'`);
    }
    if (normalized.has(calculated)) {
      errors.push(
        `urls.csv:${index + 2} duplicate normalized URL '${calculated}' (first at row ${normalized.get(calculated)})`,
      );
    } else {
      normalized.set(calculated, index + 2);
    }
    if (row.provenance.startsWith("archive-")) {
      const date = row.discovered_at?.slice(0, 10);
      if (row.evidence_date !== date) {
        errors.push(
          `urls.csv:${index + 2} archive evidence date must label capture date '${date}'`,
        );
      }
    }
  }

  for (const [index, asset] of assets.entries()) {
    if (asset.rights_privacy_state === "rights-unknown") {
      errors.push(`assets.csv:${index + 2} rights-unknown public asset must be private-excluded`);
    }
  }

  if (!/^## Unverified leads$/m.test(sources)) {
    errors.push("sources.md missing separate '## Unverified leads' section");
  }

  const dimensions = { source: {}, disposition: {}, locale: {}, rights: {} };
  for (const row of [...records, ...urls, ...assets]) {
    increment(dimensions.source, row.provenance);
    increment(dimensions.disposition, row.disposition);
    increment(dimensions.locale, row.locale);
    increment(dimensions.rights, row.rights_privacy_state);
  }
  const unclassified = urls.filter((row) => !DISPOSITIONS.has(row.disposition)).length;
  const duplicateAssets = Object.entries(
    assets.reduce((checksums, asset) => {
      increment(checksums, asset.checksum);
      return checksums;
    }, {}),
  )
    .filter(([, count]) => count > 1)
    .map(([checksum, count]) => ({ checksum, count }));

  return {
    lane: "inventory",
    status: errors.length === 0 ? "passed" : "failed",
    errors,
    counts: { records: records.length, urls: urls.length, assets: assets.length },
    coverage: { discovered: urls.length, classified: urls.length - unclassified, unclassified },
    dimensions,
    duplicateAssets,
  };
}
