import { expect, test } from "@playwright/test";

const locales = [
  {
    id: "pt-br",
    research: "/pt-br/pesquisa/",
    projects: "/pt-br/projetos/",
    publications: "/pt-br/publicacoes/",
    projectSlug: "projeto-demonstracao-fixture",
    publicationSlug: "publicacao-demonstracao-fixture",
    sparseSlug: "registro-sem-identificadores-fixture",
    collectiveSlug: "colaboracao-3000-autores-fixture",
    personPath: "/pt-br/pessoas/",
    archivedLabel: "Integrante arquivado",
    unknownDate: "Data não informada",
    unknownDoi: "DOI não atribuído",
    unavailablePdf: "PDF indisponível",
    languageTag: "pt-BR",
  },
  {
    id: "en",
    research: "/en/research/",
    projects: "/en/projects/",
    publications: "/en/publications/",
    projectSlug: "demonstration-project-fixture",
    publicationSlug: "demonstration-publication-fixture",
    sparseSlug: "record-without-identifiers-fixture",
    collectiveSlug: "three-thousand-author-collaboration-fixture",
    personPath: "/en/people/",
    archivedLabel: "Archived member",
    unknownDate: "Date not provided",
    unknownDoi: "DOI not assigned",
    unavailablePdf: "PDF unavailable",
    languageTag: "en",
  },
];

for (const locale of locales) {
  test.describe(`discovery surfaces (${locale.id})`, () => {
    test("finds an area, opens a project, follows a person and a publication, downloads citations", async ({
      page,
      request,
    }) => {
      // Given: the research overview for this locale.
      const research = await page.goto(locale.research);
      expect(research?.status()).toBe(200);
      await expect(page.locator("section.lps-listing")).toBeVisible();
      await expect(page.locator("html")).toHaveAttribute("lang", locale.languageTag);
      await expect(page.locator("nav.lps-breadcrumbs")).toBeVisible();

      // When: the projects listing is opened and its first project followed.
      const projects = await page.goto(locale.projects);
      expect(projects?.status()).toBe(200);
      await page.locator(`a[href="${locale.projects}${locale.projectSlug}/"]`).first().click();
      await expect(page.locator("article.lps-project")).toBeVisible();

      // Then: the plain-language summary precedes the technical body.
      const project = await page.locator("article.lps-project").innerHTML();
      expect(project.indexOf("lps-summary")).toBeLessThan(project.indexOf("lps-body"));

      // And: an archived member is labelled and is not a live link.
      const archived = page.locator("ul.lps-members li", { hasText: locale.archivedLabel });
      await expect(archived).toBeVisible();
      await expect(archived.locator("a")).toHaveCount(0);

      // And: an active member can be followed to a person profile.
      const person = page.locator(`ul.lps-members a[href^="${locale.personPath}"]`).first();
      const personHref = await person.getAttribute("href");
      const personResponse = await page.goto(personHref);
      expect(personResponse?.status()).toBe(200);

      // And: the related publication can be followed from the project.
      await page.goto(`${locale.projects}${locale.projectSlug}/`);
      await page
        .locator(`ul.lps-publications a[href="${locale.publications}${locale.publicationSlug}/"]`)
        .first()
        .click();
      await expect(page.locator("article.lps-publication")).toBeVisible();
      await expect(page.locator("nav.lps-breadcrumbs")).toBeVisible();

      // And: both citation formats download with real payloads and content types.
      const bibtex = await request.get(
        `${locale.publications}${locale.publicationSlug}/?lps_citation=bibtex`,
      );
      expect(bibtex.status()).toBe(200);
      expect(bibtex.headers()["content-type"]).toBe("application/x-bibtex; charset=utf-8");
      expect(bibtex.headers()["content-disposition"]).toContain("attachment; filename=");
      const bibtexBody = await bibtex.text();
      expect(bibtexBody.startsWith("@article{")).toBe(true);
      expect(bibtexBody.split("\n").length).toBeGreaterThan(3);

      const csl = await request.get(
        `${locale.publications}${locale.publicationSlug}/?lps_citation=csl-json`,
      );
      expect(csl.status()).toBe(200);
      expect(csl.headers()["content-type"]).toBe(
        "application/vnd.citationstyles.csl+json; charset=utf-8",
      );
      const decoded = JSON.parse(await csl.text());
      expect(Array.isArray(decoded.author)).toBe(true);
    });

    test("keeps the 3000-author export complete", async ({ request }) => {
      // Given: the collective record. When: its BibTeX export is downloaded.
      const response = await request.get(
        `${locale.publications}${locale.collectiveSlug}/?lps_citation=bibtex`,
      );

      // Then: every author is present, in order, with no elision.
      expect(response.status()).toBe(200);
      const body = await response.text();
      expect(body.match(/Author \d{4}/g)?.length).toBe(3000);
      expect(body.indexOf("Author 0001")).toBeLessThan(body.indexOf("Author 3000"));
      expect(body).not.toContain("et al");
    });

    test("states absent DOI, unknown date, and unavailable PDF honestly", async ({ page }) => {
      // Given: a record without identifiers. When: its page is opened.
      const response = await page.goto(`${locale.publications}${locale.sparseSlug}/`);

      // Then: each missing value is named instead of invented.
      expect(response?.status()).toBe(200);
      const article = page.locator("article.lps-publication");
      await expect(article).toContainText(locale.unknownDate);
      await expect(article).toContainText(locale.unknownDoi);
      await expect(article).toContainText(locale.unavailablePdf);
      await expect(article.locator('a[href*="doi.org/"]')).toHaveCount(0);
      await expect(article.locator('a[href$=".pdf"]')).toHaveCount(0);
    });

    test("refuses an unsupported citation format", async ({ request }) => {
      // Given: a valid publication. When: an unsupported format is requested.
      const response = await request.get(
        `${locale.publications}${locale.publicationSlug}/?lps_citation=endnote`,
      );

      // Then: the download is refused rather than served as HTML.
      expect(response.status()).toBe(404);
      expect(response.headers()["content-type"]).toContain("text/plain");
    });

    test("serves every listing without client-side scripting", async ({ browser }) => {
      // Given: a browser context with JavaScript disabled.
      const context = await browser.newContext({ javaScriptEnabled: false });
      const page = await context.newPage();

      // When: each listing is requested.
      for (const path of [locale.research, locale.projects, locale.publications]) {
        const response = await page.goto(path);

        // Then: the server-rendered listing and its GET form are present.
        expect(response?.status()).toBe(200);
        await expect(page.locator("section.lps-listing form[method='get']")).toHaveCount(1);
      }
      await context.close();
    });
  });
}
