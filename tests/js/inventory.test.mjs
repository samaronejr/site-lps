import { cp, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, expect, test } from "vitest";
import { validateInventory } from "../../scripts/lib/inventory-validator.mjs";

async function withFixture(mutator) {
  const directory = await mkdtemp(join(tmpdir(), "lps-inventory-test-"));
  try {
    await cp("tests/fixtures/inventory/valid", directory, { recursive: true });
    await mutator(directory);
    return await validateInventory(directory);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
}

describe("inventory validation", () => {
  test("accepts the deterministic valid fixture", async () => {
    const report = await validateInventory("tests/fixtures/inventory/valid");
    expect(report.status).toBe("passed");
    expect(report.coverage.unclassified).toBe(0);
  });

  test("reports duplicate URL, missing provenance, and unknown public asset rights together", async () => {
    const report = await withFixture(async (directory) => {
      const urlsPath = join(directory, "urls.csv");
      const urls = await readFile(urlsPath, "utf8");
      await writeFile(
        urlsPath,
        `${urls}url-duplicate,HTTPS://EXAMPLE.ORG:443/legacy/#fragment,https://example.org/legacy,https://example.org/,2026-08-30T00:00:00Z,live-http,LPS web maintainers,pt-BR,current,public-record,sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb,301,2026-08-30,duplicate normalized URL fixture\n`,
      );

      const recordsPath = join(directory, "records.csv");
      const records = await readFile(recordsPath, "utf8");
      await writeFile(recordsPath, records.replace(",live-http,", ",,"));

      const assetsPath = join(directory, "assets.csv");
      const assets = await readFile(assetsPath, "utf8");
      await writeFile(assetsPath, assets.replace(",public-record-no-copy,", ",rights-unknown,"));
    });

    expect(report.errors).toEqual(
      expect.arrayContaining([
        expect.stringContaining("duplicate normalized URL"),
        expect.stringContaining("missing provenance"),
        expect.stringContaining("rights-unknown public asset"),
      ]),
    );
  });
});
