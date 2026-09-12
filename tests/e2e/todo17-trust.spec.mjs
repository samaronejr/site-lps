import { expect, test } from "@playwright/test";

test.setTimeout(120_000);

const SHOT = "test-results/todo17";

/**
 * Opportunity, event, news, and institutional trust journeys.
 *
 * Every derived state is recomputed by the server from stored dates, so these
 * scenarios assert the rendered state rather than any fixture-side label.
 */

async function open(page, path) {
  const response = await page.goto(path, { waitUntil: "domcontentloaded" });
  return response;
}

test.describe("happy paths", () => {
  test("open opportunity completes the role-contact handoff in both locales", async ({ page }) => {
    for (const [locale, listing, tag, label] of [
      ["pt-br", "/pt-br/oportunidades/", "pt-BR", "Inscrições abertas"],
      ["en", "/en/opportunities/", "en", "Applications open"],
    ]) {
      await open(page, listing);
      await expect(page.locator("html")).toHaveAttribute("lang", tag);

      const openItem = page.locator('.lps-opportunity-listing li[data-state="open"]').first();
      await expect(openItem).toBeVisible();
      await openItem.locator("a").click();

      const article = page.locator("article.lps-opportunity");
      await expect(article).toHaveAttribute("data-state", "open");
      await expect(article).toContainText(label);

      const handoff = article.locator(".lps-handoff a");
      await expect(handoff).toHaveAttribute("href", /^mailto:[^@]+@lps\.ufrj\.br$/);

      await page.screenshot({
        path: `${SHOT}/happy-open-opportunity-${locale}.png`,
        fullPage: true,
      });
    }
  });

  test("upcoming event is reachable and shows its scheduled state", async ({ page }) => {
    await open(page, "/pt-br/eventos/");
    const upcoming = page.locator('.lps-event-listing li[data-state="upcoming"]').first();
    await expect(upcoming).toBeVisible();
    await upcoming.locator("a").click();

    const article = page.locator("article.lps-event");
    await expect(article).toHaveAttribute("data-state", "upcoming");
    await expect(article).toContainText("Programado");
    await page.screenshot({ path: `${SHOT}/happy-upcoming-event.png`, fullPage: true });
  });

  test("privacy and accessibility reporting routes are reachable in both locales", async ({
    page,
  }) => {
    for (const [path, tag, mail] of [
      ["/pt-br/privacidade/", "pt-BR", "privacidade@lps.ufrj.br"],
      ["/en/privacy/", "en", "privacidade@lps.ufrj.br"],
      ["/pt-br/acessibilidade/", "pt-BR", "acessibilidade@lps.ufrj.br"],
      ["/en/accessibility/", "en", "acessibilidade@lps.ufrj.br"],
    ]) {
      const response = await open(page, path);
      expect(response.status()).toBe(200);
      await expect(page.locator("html")).toHaveAttribute("lang", tag);

      const article = page.locator("article.lps-institutional");
      await expect(article).toBeVisible();
      await expect(article.locator(".lps-report-contact a")).toHaveAttribute(
        "href",
        `mailto:${mail}`,
      );
      await expect(article.locator(".lps-reviewed-at time")).toHaveCount(1);

      await page.screenshot({
        path: `${SHOT}/happy-${path.replaceAll("/", "-")}.png`,
        fullPage: true,
      });
    }
  });

  test("news listing is reachable and links to stable localized routes", async ({ page }) => {
    await open(page, "/pt-br/noticias/");
    const item = page.locator(".lps-news-listing li a").first();
    await expect(item).toBeVisible();
    await expect(item).toHaveAttribute("href", /^\/pt-br\/noticias\/[^/]+\/$/);
    await page.screenshot({ path: `${SHOT}/happy-news-listing.png`, fullPage: true });
  });
});

test.describe("failure and adversarial states", () => {
  test("closed opportunity stays stable and is never presented as open", async ({ page }) => {
    const response = await open(page, "/pt-br/oportunidades/estagio-encerrado/");
    expect(response.status()).toBe(200);

    const article = page.locator("article.lps-opportunity");
    await expect(article).toHaveAttribute("data-state", "closed");
    await expect(article).toContainText("Inscrições encerradas");
    await expect(article).not.toContainText("Inscrições abertas");
    await expect(article.locator(".lps-handoff")).toHaveCount(0);

    await page.screenshot({ path: `${SHOT}/failure-closed-opportunity.png`, fullPage: true });
  });

  test("long-closed opportunity stays reachable but becomes noindex", async ({ page }) => {
    const response = await open(page, "/pt-br/oportunidades/bolsa-arquivada/");
    expect(response.status()).toBe(200);

    await expect(page.locator("article.lps-opportunity")).toHaveAttribute("data-noindex", "true");
    await expect(page.locator('head meta[name="robots"]')).toHaveCount(1);
    await expect(page.locator('head meta[name="robots"]')).toHaveAttribute("content", /noindex/);

    await page.screenshot({ path: `${SHOT}/failure-aged-opportunity-noindex.png`, fullPage: true });
  });

  test("cancelled event stays stable with an explicit status", async ({ page }) => {
    const response = await open(page, "/pt-br/eventos/workshop-cancelado/");
    expect(response.status()).toBe(200);

    const article = page.locator("article.lps-event");
    await expect(article).toHaveAttribute("data-state", "cancelled");
    await expect(article).toContainText("Cancelado");
    await expect(article.locator(".lps-event-notice")).toBeVisible();

    await page.screenshot({ path: `${SHOT}/failure-cancelled-event.png`, fullPage: true });
  });

  test("opportunity without a public contact never reaches the listing", async ({ page }) => {
    await open(page, "/pt-br/oportunidades/");
    await expect(page.locator(".lps-opportunity-listing")).toBeVisible();
    await expect(page.getByText("Oportunidade sem contato publico")).toHaveCount(0);

    const response = await open(page, "/pt-br/oportunidades/oportunidade-sem-contato/");
    expect(response.status()).toBe(404);

    await page.screenshot({ path: `${SHOT}/failure-absent-contact.png`, fullPage: true });
  });

  test("opportunity missing its required English variant does not publish", async ({ page }) => {
    await open(page, "/pt-br/oportunidades/");
    await expect(page.getByText("Oportunidade apenas em portugues")).toHaveCount(0);

    const response = await open(page, "/pt-br/oportunidades/oportunidade-so-portugues/");
    expect(response.status()).toBe(404);

    await page.screenshot({ path: `${SHOT}/failure-missing-english.png`, fullPage: true });
  });

  test("unsourced and stale institutional claims never render", async ({ page }) => {
    await open(page, "/pt-br/sobre/");

    const article = page.locator("article.lps-institutional");
    await expect(article).toBeVisible();
    await expect(article).not.toContainText("ADVERSARIAL PRESTIGE CLAIM WITHOUT SOURCE");
    await expect(article).not.toContainText("ADVERSARIAL STALE CLAIM");
    await expect(article).toContainText("convenios ativos com agencias de fomento");

    await page.screenshot({
      path: `${SHOT}/failure-unsourced-and-stale-claims.png`,
      fullPage: true,
    });
  });

  test("institutional pages never collect personal data", async ({ page }) => {
    for (const path of [
      "/pt-br/sobre/",
      "/pt-br/sobre/historia/",
      "/pt-br/sobre/governanca/",
      "/pt-br/colabore/",
      "/pt-br/contato/",
      "/pt-br/privacidade/",
      "/pt-br/acessibilidade/",
    ]) {
      await open(page, path);
      const article = page.locator("article.lps-institutional");
      await expect(article).toBeVisible();
      await expect(article.locator("form")).toHaveCount(0);
      await expect(article.locator("input")).toHaveCount(0);
    }
  });
});
