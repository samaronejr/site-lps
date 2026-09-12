import { cp, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import {
  IA_FIXTURE_PATH,
  runInformationArchitectureQa,
  TAXONOMY_DIRECTORY,
} from "../../scripts/lib/information-architecture.mjs";

async function copiedContract() {
  const directory = await mkdtemp(join(tmpdir(), "lps-ia-"));
  const taxonomyDirectory = join(directory, "taxonomies");
  const routeFixture = join(directory, "routes.json");
  await cp(TAXONOMY_DIRECTORY, taxonomyDirectory, { recursive: true });
  await cp(IA_FIXTURE_PATH, routeFixture);
  return { directory, routeFixture, taxonomyDirectory };
}

describe("bilingual information architecture", () => {
  it("generates complete bilingual sitemap artifacts from the frozen contract", async () => {
    const { directory, routeFixture, taxonomyDirectory } = await copiedContract();
    try {
      const report = await runInformationArchitectureQa({
        routeFixture,
        taxonomyDirectory,
        outputDirectory: join(directory, "generated"),
      });

      expect(report.status).toBe("passed");
      expect(report.issues).toEqual([]);
      expect(report.counts).toEqual({
        applicationDomains: 7,
        pages: 21,
        researchAreas: 4,
        routes: 42,
        searchFacets: 9,
      });
      await expect(
        readFile(join(directory, "generated", "sitemap-pt-br.md"), "utf8"),
      ).resolves.toContain("/pt-br/pesquisa/");
      await expect(
        readFile(join(directory, "generated", "routes-en.json"), "utf8"),
      ).resolves.toContain('"/en/research/"');
    } finally {
      await rm(directory, { force: true, recursive: true });
    }
  });

  it("rejects translated keys, deep or duplicate routes, unsupported domains, and orphan pages", async () => {
    const { directory, routeFixture, taxonomyDirectory } = await copiedContract();
    try {
      const vocabularyPath = join(taxonomyDirectory, "controlled-vocabularies.yaml");
      const vocabulary = await readFile(vocabularyPath, "utf8");
      await writeFile(
        vocabularyPath,
        vocabulary
          .replace("key: signal-processing", "key: processamento-de-sinais")
          .replace("key: data-quality", "key: quantum-finance"),
      );

      const routes = JSON.parse(await readFile(routeFixture, "utf8"));
      routes.pages.find((page) => page.key === "about").routes.en = "/en/research/";
      routes.pages.find((page) => page.key === "project-detail").routes["pt-br"] =
        "/pt-br/pesquisa/areas/detalhe/";
      routes.pages.find((page) => page.key === "event-detail").parent = "absent-page";
      await writeFile(routeFixture, `${JSON.stringify(routes, null, 2)}\n`);

      const report = await runInformationArchitectureQa({
        routeFixture,
        taxonomyDirectory,
        outputDirectory: join(directory, "generated"),
      });

      expect(report.status).toBe("failed");
      expect(report.issues.map((entry) => entry.code)).toEqual(
        expect.arrayContaining([
          "STABLE_KEY_TRANSLATED",
          "UNSUPPORTED_DOMAIN",
          "ROUTE_DEPTH_EXCEEDED",
          "DUPLICATE_DESTINATION",
          "ORPHAN_PAGE",
        ]),
      );
    } finally {
      await rm(directory, { force: true, recursive: true });
    }
  });
});
