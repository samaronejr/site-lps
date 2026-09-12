import { mkdir, writeFile } from "node:fs/promises";
import { dirname } from "node:path";
import {
  buildImportPackage,
  localePairs,
  readCorpus,
  validateCorpus,
} from "./lib/content-corpus.mjs";

function option(name, fallback) {
  const index = process.argv.indexOf(name);
  return index === -1 ? fallback : process.argv[index + 1];
}

const corpus = await readCorpus({
  corpusDir: option("--corpus-dir", "content/corpus"),
  inventoryDir: option("--inventory-dir", "content/inventory"),
});
const report = validateCorpus(corpus);
if (report.status !== "passed") {
  process.stderr.write(
    `${JSON.stringify({ status: report.status, errors: report.errors }, null, 2)}\n`,
  );
  process.exit(1);
}

const packagePath = option("--output", "content/import/launch-corpus.json");
const pairsPath = option("--pairs", "content/import/locale-pairs.json");
const packageValue = buildImportPackage(corpus);
const pairs = localePairs(corpus);
await mkdir(dirname(packagePath), { recursive: true });
await mkdir(option("--assets-dir", "content/import/assets"), { recursive: true });
await writeFile(packagePath, `${JSON.stringify(packageValue, null, 2)}\n`);
await writeFile(pairsPath, `${JSON.stringify({ schemaVersion: 1, pairs }, null, 2)}\n`);

process.stdout.write(
  `${JSON.stringify(
    {
      status: "built",
      package: packagePath,
      pairs: pairsPath,
      counts: {
        records: packageValue.records.length,
        relationships: packageValue.relationships.length,
        redirects: packageValue.redirects.length,
        media: packageValue.media.length,
        localePairs: pairs.length,
      },
    },
    null,
    2,
  )}\n`,
);
