import { expect, test } from "@playwright/test";

test.setTimeout(120_000);

const SHOT = "test-results/todo16";

/**
 * People, organization, and infrastructure public journeys.
 *
 * Every assertion reads the server-rendered document: the surfaces are built
 * without client JavaScript, so what the browser shows is exactly what an
 * anonymous visitor or a crawler receives.
 */

const PRIVATE_STRINGS = [
  "ana.privada@example.org",
  "privado.oculto@example.org",
  "Rua Privada 10",
  "nao.revisado@example.org",
];

async function open(page, path) {
  const response = await page.goto(path, { waitUntil: "domcontentloaded" });
  expect(response, `no response for ${path}`).not.toBeNull();
  return response;
}

test.describe("happy paths", () => {
  test("people listing keeps every cohort visible in both locales", async ({ page }) => {
    for (const [locale, path, tag, labels] of [
      [
        "pt-br",
        "/pt-br/pessoas/",
        "pt-BR",
        [
          "Estudante",
          "Professor",
          "Equipe técnica",
          "Colaboração externa",
          "Egresso",
          "In memoriam",
        ],
      ],
      [
        "en",
        "/en/people/",
        "en",
        [
          "Student",
          "Professor",
          "Technical staff",
          "External collaborator",
          "Alumni",
          "In memoriam",
        ],
      ],
    ]) {
      const response = await open(page, path);
      expect(response.status(), `${path} must be served`).toBe(200);
      await expect(page.locator("html")).toHaveAttribute("lang", tag);

      const items = page.locator("section.lps-people li");
      await expect(items.first()).toBeVisible();

      const listing = await page.locator("section.lps-people ul").innerText();
      for (const label of labels) {
        expect(listing, `${locale} listing shows the ${label} cohort`).toContain(label);
      }

      await page.screenshot({ path: `${SHOT}/happy-people-listing-${locale}.png`, fullPage: true });
    }
  });

  test("role, status, and area facets narrow the listing without losing links", async ({
    page,
  }) => {
    await open(page, "/pt-br/pessoas/");
    const total = await page.locator("section.lps-people li").count();
    expect(total).toBeGreaterThan(2);

    await open(page, "/pt-br/pessoas/?role%5B%5D=student");
    const students = page.locator("section.lps-people li");
    await expect(students.first()).toBeVisible();
    expect(await students.count()).toBeLessThan(total);
    for (const row of await students.all()) {
      await expect(row.locator(".lps-role")).toContainText("Estudante");
    }

    await open(page, "/pt-br/pessoas/?status%5B%5D=alumni");
    const alumni = page.locator("section.lps-people li");
    await expect(alumni.first()).toBeVisible();
    for (const row of await alumni.all()) {
      await expect(row.locator(".lps-status")).toHaveText("Egresso");
    }

    await open(page, "/pt-br/pessoas/?area%5B%5D=signal-processing");
    await expect(page.locator("section.lps-people li").first()).toBeVisible();
    await page.screenshot({ path: `${SHOT}/happy-people-facets.png`, fullPage: true });
  });

  test("a profile opens from the listing and exposes only approved contacts", async ({ page }) => {
    await open(page, "/pt-br/pessoas/");
    await page
      .getByRole("link", { name: /Ana Álvares/ })
      .first()
      .click();

    const profile = page.locator("article.lps-person");
    await expect(profile).toBeVisible();
    await expect(profile).toContainText("Ana Álvares");
    await expect(profile.locator(".lps-role")).toHaveCount(2);
    await expect(profile.locator('a[href^="mailto:"]')).toHaveAttribute(
      "href",
      "mailto:ana.publica@example.org",
    );
    await expect(profile.locator('a[href^="https://orcid.org/"]')).toBeVisible();
    await expect(profile.locator("img")).toHaveAttribute(
      "src",
      "/wp-content/uploads/ana-fixture.jpg",
    );

    expect(page.url()).toContain("/pt-br/pessoas/ana-alvares-fixture/");
    await page.screenshot({ path: `${SHOT}/happy-person-profile.png`, fullPage: true });
  });

  test("a departed member keeps working historical links", async ({ page }) => {
    await open(page, "/pt-br/pessoas/pessoa-egressa-fixture/");
    const profile = page.locator("article.lps-person");
    await expect(profile).toContainText("2023-12-31");

    const history = profile.locator("ul.lps-history a").first();
    await expect(history).toBeVisible();
    const target = await history.getAttribute("href");
    const response = await open(page, target);
    expect(response.status(), `${target} still resolves after departure`).toBe(200);
    await page.screenshot({ path: `${SHOT}/happy-history-link.png`, fullPage: true });
  });

  test("infrastructure links capability evidence and contacts in both locales", async ({
    page,
  }) => {
    for (const [locale, path, claim, reviewed, contact] of [
      [
        "pt-br",
        "/pt-br/infraestrutura/",
        "Suporta experimentos reprodutíveis",
        "Fonte revisada em 2026-08-20",
        "/pt-br/pessoas/equipe-tecnica-fixture/",
      ],
      [
        "en",
        "/en/infrastructure/",
        "Supports reproducible signal-processing experiments",
        "Source reviewed 2026-08-20",
        "/en/people/fixture-technical-staff/",
      ],
    ]) {
      const response = await open(page, path);
      expect(response.status(), `${path} must be served`).toBe(200);

      const infra = page.locator("section.lps-infra");
      await expect(infra).toBeVisible();
      await expect(infra).toContainText(claim);
      await expect(infra).toContainText(reviewed);
      await expect(infra.locator(`a[href="${contact}"]`)).toBeVisible();

      await page.screenshot({ path: `${SHOT}/happy-infrastructure-${locale}.png`, fullPage: true });

      const contactResponse = await open(page, contact);
      expect(contactResponse.status(), `${contact} is a reachable contact route`).toBe(200);
      await expect(page.locator("article.lps-person")).toBeVisible();
    }
  });

  test("a public partner profile renders while its logo rights stay unproven", async ({ page }) => {
    const response = await open(page, "/pt-br/organizacoes/");
    expect(response.status()).toBe(200);

    const org = page.locator("article.lps-org");
    await expect(org.first()).toContainText("Parceiro Público de fixture");
    await expect(org.first().locator('a[href="https://parceiro.example/"]')).toBeVisible();
    expect(await page.content()).not.toContain("parceiro-fixture.svg");
    await page.screenshot({ path: `${SHOT}/happy-organizations.png`, fullPage: true });
  });
});

test.describe("failure and privacy paths", () => {
  test("private fields never reach any public surface", async ({ page }) => {
    for (const path of [
      "/pt-br/pessoas/",
      "/en/people/",
      "/pt-br/pessoas/ana-alvares-fixture/",
      "/pt-br/pessoas/ana-alvares-2-fixture/",
      "/pt-br/organizacoes/",
      "/pt-br/infraestrutura/",
    ]) {
      await open(page, path);
      const html = await page.content();
      for (const secret of PRIVATE_STRINGS) {
        expect(html, `${secret} must never appear on ${path}`).not.toContain(secret);
      }
    }
  });

  test("an unreviewed record publishes neither photo nor email", async ({ page }) => {
    await open(page, "/pt-br/pessoas/ana-alvares-2-fixture/");
    const profile = page.locator("article.lps-person");
    await expect(profile).toContainText("Foto não publicada");
    await expect(profile.locator("img")).toHaveCount(0);
    await expect(profile.locator('a[href^="mailto:"]')).toHaveCount(0);
    expect(await page.content()).not.toContain("untrusted.example");
    await page.screenshot({ path: `${SHOT}/failure-unreviewed-profile.png`, fullPage: true });
  });

  test("a record awaiting consent stays withheld", async ({ page }) => {
    await open(page, "/pt-br/pessoas/");
    const listing = await page.content();
    expect(listing).not.toContain("Pessoa Sem Consentimento");
    expect(listing).not.toContain("Pessoa Arquivada de fixture");

    await open(page, "/pt-br/pessoas/pessoa-sem-consentimento-fixture/");
    await expect(page.locator("article.lps-person")).toHaveCount(0);
    await page.screenshot({ path: `${SHOT}/failure-withheld-profile.png`, fullPage: true });
  });

  test("names colliding only by diacritics stay distinguishable", async ({ page }) => {
    await open(page, "/pt-br/pessoas/");
    const qualified = page.locator("section.lps-people li:has(.lps-disambiguation)");
    expect(await qualified.count()).toBe(2);

    const hrefs = await page
      .locator("section.lps-people li a")
      .evaluateAll((nodes) => nodes.map((node) => node.getAttribute("href")));
    expect(hrefs).toContain("/pt-br/pessoas/ana-alvares-fixture/");
    expect(hrefs).toContain("/pt-br/pessoas/ana-alvares-2-fixture/");
    await page.screenshot({ path: `${SHOT}/failure-duplicate-names.png`, fullPage: true });
  });

  test("an external collaborator is never presented as staff", async ({ page }) => {
    await open(page, "/pt-br/pessoas/colaborador-externo-fixture/");
    const profile = page.locator("article.lps-person");
    await expect(profile.locator(".lps-external")).toContainText("não integra a equipe do LPS");

    await open(page, "/en/people/fixture-external-collaborator/");
    await expect(page.locator("article.lps-person .lps-external")).toContainText("not LPS staff");
    await page.screenshot({ path: `${SHOT}/failure-external-label.png`, fullPage: true });
  });

  test("a hidden partner leaks neither profile nor logo", async ({ page }) => {
    await open(page, "/pt-br/organizacoes/");
    let html = await page.content();
    expect(html).not.toContain("Parceiro Oculto");
    expect(html).not.toContain("oculto-fixture.svg");
    expect(html).not.toContain("https://oculto.example/");

    await open(page, "/pt-br/organizacoes/parceiro-oculto-fixture/");
    html = await page.content();
    expect(html).not.toContain("Parceiro Oculto");
    expect(html).not.toContain("oculto-fixture.svg");
    await page.screenshot({ path: `${SHOT}/failure-hidden-partner.png`, fullPage: true });
  });

  test("an unsourced capability claim is never published", async ({ page }) => {
    await open(page, "/pt-br/infraestrutura/");
    expect(await page.content()).not.toContain("O laboratório mais rápido do Brasil");

    await open(page, "/en/infrastructure/");
    expect(await page.content()).not.toContain("The fastest laboratory in Brazil");
    await page.screenshot({ path: `${SHOT}/failure-unsourced-claim.png`, fullPage: true });
  });

  test("an unknown facet value returns an empty accessible listing", async ({ page }) => {
    const response = await open(page, "/pt-br/pessoas/?role%5B%5D=does-not-exist");
    expect(response.status()).toBe(200);
    await expect(page.locator("section.lps-people form")).toBeVisible();
    await expect(page.locator("section.lps-people li")).toHaveCount(0);
    await page.screenshot({ path: `${SHOT}/failure-unknown-facet.png`, fullPage: true });
  });
});
