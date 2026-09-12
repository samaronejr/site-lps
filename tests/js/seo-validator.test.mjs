import { describe, expect, it } from "vitest";
import { readSnapshot } from "../../scripts/lib/seo-snapshot.mjs";
import { validateSnapshot } from "../../scripts/lib/seo-validator.mjs";

const FIXTURES = "tests/fixtures/seo";

async function validate(name, mode = "seo") {
  return validateSnapshot(await readSnapshot(`${FIXTURES}/${name}`), { mode });
}

function codes(report) {
  return report.errors.map((error) => error.code);
}

describe("seo snapshot validator", () => {
  it("accepts a snapshot that satisfies every indexation contract", async () => {
    const report = await validate("baseline");
    expect(report.errors).toEqual([]);
    expect(report.status).toBe("passed");
    expect(report.counts.pages).toBeGreaterThan(0);
    expect(report.counts.xmlDocuments).toBeGreaterThan(0);
  });

  it("accepts the baseline in the schema lane with zero errors", async () => {
    const report = await validate("baseline", "schema");
    expect(report.errors).toEqual([]);
    expect(report.lane).toBe("schema");
  });

  it("reports the page that publishes no canonical link", async () => {
    const report = await validate("missing-canonical");
    expect(report.errors).toEqual([
      {
        code: "lps_seo_missing_canonical",
        path: "/pt-br/publicacoes/artigo-a/",
        detail: "no canonical link",
      },
    ]);
  });

  it("reports the page whose locale counterpart does not link back", async () => {
    const report = await validate("absent-reciprocal-locale");
    expect(codes(report)).toEqual(["lps_seo_hreflang_not_reciprocal"]);
    expect(report.errors[0].path).toBe("/pt-br/publicacoes/artigo-a/");
  });

  it("reports a legacy address that redirects more than once", async () => {
    const report = await validate("redirect-chain");
    expect(codes(report)).toEqual(["lps_seo_redirect_chain"]);
    expect(report.errors[0].path).toBe("/lps/velho.html");
  });

  it("reports a scholarship published as a job posting", async () => {
    const report = await validate("scholarship-as-jobposting", "schema");
    expect(codes(report)).toEqual(["lps_schema_scholarship_as_jobposting"]);
    expect(report.errors[0].path).toBe("/pt-br/oportunidades/bolsa-doutorado/");
  });

  it("reports a DOI claimed by a second address in the same locale", async () => {
    const report = await validate("duplicate-doi", "schema");
    expect(codes(report)).toEqual(["lps_schema_duplicate_doi"]);
    expect(report.errors[0].path).toBe("/pt-br/publicacoes/artigo-b/");
  });

  it("reports a non-indexable address listed in a locale sitemap", async () => {
    const report = await validate("draft-in-sitemap");
    expect(codes(report)).toEqual(["lps_seo_noindex_in_sitemap"]);
    expect(report.errors[0].path).toBe("/pt-br/noticias/rascunho/");
  });

  it("reports a sitemap entry the site does not serve", async () => {
    const report = await validate("sitemap-entry-not-served");
    expect(codes(report)).toEqual(["lps_seo_sitemap_entry_not_served"]);
    expect(report.errors[0].path).toBe("/pt-br/infraestrutura/");
    expect(report.errors[0].detail).toContain("404");
  });

  it("keeps the schema lane free of pure indexation findings", async () => {
    const report = await validate("missing-canonical", "schema");
    expect(report.errors).toEqual([]);
  });
});
