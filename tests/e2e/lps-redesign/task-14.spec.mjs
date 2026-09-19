import { expect, test } from "@playwright/test";
import { mkdir } from "node:fs/promises";

/**
 * Task 14 — the unified LPS identity across every existing public section.
 *
 * The spec exercises the real HTTP boundary of the running site: every static
 * route answers in both locales, the canonical teaching fixture renders the
 * shared co-teaching history on both profiles, the course page groups
 * offerings by derived temporal status, publications keep DOI and author
 * order, cancelled/closed/absent states stay explicit, and a CMS update
 * propagates to the rendered surface. Screenshots of the representative
 * surfaces land in the task evidence directory.
 *
 * The pure record, policy, and route contracts are covered by the PHPUnit
 * suites; this spec proves the wired boundary.
 */

// The development runtime declares `localhost` as its origin (WP_SITEURL,
// canonicals, asset URLs) and its CSP refuses subresources fetched under a
// different loopback name, so the default base URL must be `localhost`, not
// `127.0.0.1`.
const BASE_URL = process.env.LPS_BASE_URL ?? "http://localhost:8897";
const SCREENSHOTS = process.env.LPS_SCREENSHOT_DIR ?? "test-results/task-14";

const AUTO_LOGIN_COOKIE = {
  name: "playground_auto_login_already_happened",
  value: "1",
};

/** Returns a context whose anonymous requests skip the auto-login handshake. */
async function anonymousContext(browser) {
  const context = await browser.newContext({ baseURL: BASE_URL });
  const probe = await context.newPage();
  await probe.goto("/", { waitUntil: "domcontentloaded" });
  const origin = new URL(probe.url());
  await context.addCookies([{ ...AUTO_LOGIN_COOKIE, domain: origin.hostname, path: "/" }]);
  await probe.close();
  return context;
}

/**
 * Signs in as the MFA-enrolled publisher through the real `wp-login.php`
 * boundary: the credential POST and the Two-Factor challenge POST go through
 * the request API so the renderer never touches the login flow (the page
 * crashes under memory pressure on this host when it does).
 */
async function loginAsPublisher(context) {
  await context.addCookies([{ ...AUTO_LOGIN_COOKIE, url: BASE_URL }]);

  const login = await context.request.post("/wp-login.php", {
    form: {
      log: "lps-t14-publisher",
      pwd: "lps-t14-publisher-pass",
      "wp-submit": "Log In",
      redirect_to: "/wp-admin/",
      testcookie: "1",
    },
    maxRedirects: 0,
  });
  const body = await login.text();
  expect(login.status()).toBe(200);
  expect(body).not.toContain("login_error");

  // The enrolled-MFA challenge renders hidden fields the second POST replays.
  const field = (name) => {
    const match = body.match(new RegExp(`name="${name}"[^>]*value="([^"]*)"`));
    return match ? match[1] : "";
  };
  const challenge = await context.request.post("/wp-login.php?action=validate_2fa", {
    form: {
      provider: field("provider"),
      "wp-auth-id": field("wp-auth-id"),
      "wp-auth-nonce": field("wp-auth-nonce"),
      redirect_to: field("redirect_to"),
      rememberme: "0",
    },
    maxRedirects: 0,
  });
  expect(challenge.status()).toBe(302);
  expect(challenge.headers()["location"]).toContain("/wp-admin/");
}

/** Reads the REST nonce bound to the signed-in session. */
async function restNonce(context) {
  for (let attempt = 0; attempt < 6; attempt += 1) {
    const response = await context.request.get("/wp-admin/admin-ajax.php?action=rest-nonce");
    if (response.ok()) {
      const nonce = (await response.text()).trim();
      if (nonce.length > 0 && nonce !== "0") {
        return nonce;
      }
    }
  }
  throw new Error("REST nonce endpoint never answered for the publisher session");
}

test.describe("task-14: the identity across every existing public section", () => {
  // The development runtime is single-process PHP; multi-request tests need
  // more than the default 30s on this host.
  test.beforeEach(() => {
    test.setTimeout(90_000);
  });

  test("every static route answers in both locales", async ({ browser }) => {
    const context = await anonymousContext(browser);
    const page = await context.newPage();
    // The static route set mirrors tests/fixtures/ia/routes.json (parameterized
    // families excluded) plus the search and institutional sub-pages.
    const routes = [
      "/pt-br/", "/pt-br/sobre/", "/pt-br/sobre/historia/",
      "/pt-br/pesquisa/", "/pt-br/projetos/", "/pt-br/pessoas/", "/pt-br/publicacoes/",
      "/pt-br/infraestrutura/", "/pt-br/oportunidades/", "/pt-br/noticias/",
      "/pt-br/eventos/", "/pt-br/colabore/", "/pt-br/contato/", "/pt-br/privacidade/",
      "/pt-br/acessibilidade/", "/pt-br/ensino/", "/pt-br/busca/",
      "/en/", "/en/about/", "/en/about/history/",
      "/en/research/", "/en/projects/", "/en/people/", "/en/publications/",
      "/en/infrastructure/", "/en/opportunities/", "/en/news/",
      "/en/events/", "/en/collaborate/", "/en/contact/", "/en/privacy/",
      "/en/accessibility/", "/en/teaching/", "/en/search/",
    ];
    // Route status checks go through the request API in parallel batches;
    // sequential page loads would exceed the test timeout on this host.
    const failures = [];
    for (let index = 0; index < routes.length; index += 6) {
      const batch = routes.slice(index, index + 6);
      const responses = await Promise.all(
        batch.map((route) => context.request.get(route)),
      );
      responses.forEach((response, offset) => {
        if (response.status() >= 400) {
          failures.push(`${batch[offset]} -> ${response.status()}`);
        }
      });
    }
    expect(failures, `routes failing: ${failures.join(", ")}`).toEqual([]);
    await context.close();
  });

  test("the faculty profile renders the canonical teaching history", async ({ browser }) => {
    const context = await anonymousContext(browser);
    const page = await context.newPage();
    await page.goto("/pt-br/pessoas/docente-sinais-fixture/", { waitUntil: "domcontentloaded" });

    const teaching = page.locator(".lps-person-teaching");
    await expect(teaching).toBeVisible();
    await expect(teaching.locator("h2")).toHaveText("Disciplinas e materiais");

    // The current offering and the completed offering land in their own
    // temporal groups, newest first inside each group.
    const current = teaching.locator('.lps-teaching-group[data-temporal="current"]');
    await expect(current.locator("h3")).toHaveText("Em andamento");
    const currentLink = current.locator(".lps-teaching-history a");
    await expect(currentLink).toHaveText("Sinais e Sistemas — Turma T01 (2026.2)");
    await expect(currentLink).toHaveAttribute(
      "href",
      "/pt-br/ensino/disciplinas/sinais-e-sistemas-fixture/2026-2-semester/t01/",
    );
    await expect(current.locator(".lps-meta")).toContainText("Docente responsável");

    const completed = teaching.locator('.lps-teaching-group[data-temporal="completed"]');
    await expect(completed.locator("h3")).toHaveText("Ofertas anteriores");
    await expect(completed.locator(".lps-teaching-history a")).toHaveText(
      "Sinais e Sistemas — Turma T01 (2025.2)",
    );

    // The absent portrait is announced, never a broken image.
    await expect(page.locator(".lps-person-no-photo")).toHaveText("Foto não publicada");
    await expect(page.locator(".lps-person-photo")).toHaveCount(0);
    await context.close();
  });

  test("the co-teacher profile renders the same offering with its own role", async ({ browser }) => {
    const context = await anonymousContext(browser);
    const page = await context.newPage();
    await page.goto("/pt-br/pessoas/codocente-sinais-fixture/", { waitUntil: "domcontentloaded" });

    const current = page.locator('.lps-teaching-group[data-temporal="current"]');
    await expect(current.locator(".lps-teaching-history a")).toHaveText(
      "Sinais e Sistemas — Turma T01 (2026.2)",
    );
    await expect(current.locator(".lps-meta")).toContainText("Codocente");
    await context.close();
  });

  test("the course page groups offerings by derived temporal status", async ({ browser }) => {
    const context = await anonymousContext(browser);
    const page = await context.newPage();
    await page.goto("/pt-br/ensino/disciplinas/sinais-e-sistemas-fixture/", {
      waitUntil: "domcontentloaded",
    });

    await expect(page.locator("h1")).toHaveText("Sinais e Sistemas (fixture de QA)");
    const current = page.locator('.lps-offering-group[data-temporal="current"]');
    await expect(current.locator("h3")).toHaveText("Em andamento");
    await expect(current.locator(".lps-temporal-status")).toHaveText("Em andamento");
    const completed = page.locator('.lps-offering-group[data-temporal="completed"]');
    await expect(completed.locator("h3")).toHaveText("Ofertas anteriores");
    await expect(completed.locator(".lps-temporal-status")).toHaveText("Concluída");
    await context.close();
  });

  test("the offering page renders the shared team with localized roles", async ({ browser }) => {
    const context = await anonymousContext(browser);
    const page = await context.newPage();
    await page.goto("/pt-br/ensino/disciplinas/sinais-e-sistemas-fixture/2026-2-semester/t01/", {
      waitUntil: "domcontentloaded",
    });

    await expect(page.locator("h1")).toHaveText("Sinais e Sistemas — Turma T01 (2026.2)");
    const team = page.locator(".lps-teaching-team li");
    await expect(team).toHaveCount(2);
    await expect(team.nth(0)).toContainText("Docente de Sinais (fixture de QA)");
    await expect(team.nth(0).locator(".lps-team-role")).toHaveText("Docente responsável");
    await expect(team.nth(1)).toContainText("Codocente de Sinais (fixture de QA)");
    await expect(team.nth(1).locator(".lps-team-role")).toHaveText("Codocente");
    await expect(page.locator(".lps-units li")).toHaveCount(2);
    await context.close();
  });

  test("the publication keeps its DOI and the stored author order", async ({ browser }) => {
    const context = await anonymousContext(browser);
    const page = await context.newPage();
    await page.goto("/pt-br/publicacoes/publicacao-demonstracao-fixture/", {
      waitUntil: "domcontentloaded",
    });

    await expect(page.locator("h1")).toHaveText("Publicação de demonstração (fixture de QA)");
    await expect(page.locator(".lps-doi")).toContainText("10.5555/lps.qa.fixture");
    const authors = page.locator(".lps-authors li");
    await expect(authors).toHaveCount(2);
    await expect(authors.nth(0)).toContainText("Pessoa Ativa (fixture de QA)");
    await expect(authors.nth(1)).toContainText("External Fixture Author");
    await context.close();
  });

  test("cancelled events and closed opportunities announce their state", async ({ browser }) => {
    const context = await anonymousContext(browser);
    const page = await context.newPage();

    await page.goto("/pt-br/eventos/", { waitUntil: "domcontentloaded" });
    const cancelled = page.locator('.lps-event-listing li[data-state="cancelled"]');
    await expect(cancelled).toHaveCount(1);
    await expect(cancelled.locator(".lps-event-state")).toHaveText("Cancelado");

    await page.goto("/pt-br/eventos/workshop-cancelado/", { waitUntil: "domcontentloaded" });
    await expect(page.locator(".lps-event-notice")).toContainText("não será realizado");

    await page.goto("/pt-br/oportunidades/", { waitUntil: "domcontentloaded" });
    const closed = page.locator('.lps-opportunity-listing li[data-state="closed"]').first();
    await expect(closed.locator(".lps-opportunity-state")).toHaveText("Inscrições encerradas");
    await context.close();
  });

  test("the people directory groups cohorts and keeps an explicit empty state", async ({ browser }) => {
    const context = await anonymousContext(browser);
    const page = await context.newPage();

    await page.goto("/pt-br/pessoas/", { waitUntil: "domcontentloaded" });
    const cohorts = page.locator(".lps-people-cohort");
    await expect(cohorts.first()).toBeVisible();
    await expect(page.locator('.lps-people-cohort[data-cohort="active"]')).toHaveCount(1);

    // A filter combination with no members renders the explicit empty state
    // in the status line instead of a blank listing.
    await page.goto("/pt-br/pessoas/?role=nonexistent-role", { waitUntil: "domcontentloaded" });
    await expect(page.locator("#lps-people-status")).toContainText("Nenhuma pessoa corresponde");

    // A departed member keeps the historical record as its own stratum.
    await page.goto("/pt-br/pessoas/pessoa-egressa-fixture/", { waitUntil: "domcontentloaded" });
    await expect(page.locator(".lps-person-record h2")).toHaveText("Registro histórico");
    await context.close();
  });

  test("a single news record renders as a dated reading page", async ({ browser }) => {
    const context = await anonymousContext(browser);
    const page = await context.newPage();
    await page.goto("/pt-br/noticias/noticia-teste/", { waitUntil: "domcontentloaded" });
    await expect(page.locator("article.lps-news h1")).toHaveText("Notícia de teste");
    await expect(page.locator(".lps-body")).toContainText("Destino apenas para teste.");
    await context.close();
  });

  test("a CMS update propagates to the rendered surface", async ({ browser }) => {
    const context = await browser.newContext({ baseURL: BASE_URL });
    await loginAsPublisher(context);
    const nonce = await restNonce(context);

    // Resolve the fixture news record, update its summary through the real
    // REST boundary, and confirm the public surface reflects the write.
    const list = await context.request.get("/wp-json/wp/v2/news?slug=noticia-teste", {
      headers: { "X-WP-Nonce": nonce },
    });
    expect(list.status()).toBe(200);
    const [record] = await list.json();
    expect(record).toBeTruthy();

    const summary = `Resumo atualizado pela suite e2e ${Date.now().toString(36)}`;
    const update = await context.request.post(`/wp-json/wp/v2/news/${record.id}`, {
      data: { excerpt: summary },
      headers: { "X-WP-Nonce": nonce },
    });
    expect(update.status()).toBe(200);

    const anonymous = await anonymousContext(browser);
    const publicPage = await anonymous.newPage();
    await publicPage.goto("/pt-br/noticias/noticia-teste/", { waitUntil: "domcontentloaded" });
    await expect(publicPage.locator(".lps-summary")).toHaveText(summary);
    await anonymous.close();
    await context.close();
  });

  test("screenshots capture the redesigned surfaces", async ({ browser }) => {
    test.setTimeout(120_000);
    await mkdir(SCREENSHOTS, { recursive: true });
    const context = await anonymousContext(browser);
    const page = await context.newPage();
    const shots = [
      ["/pt-br/pessoas/docente-sinais-fixture/", "person-profile-pt-br.png"],
      ["/pt-br/pessoas/", "people-directory-pt-br.png"],
      ["/pt-br/ensino/disciplinas/sinais-e-sistemas-fixture/", "course-pt-br.png"],
      ["/pt-br/ensino/disciplinas/sinais-e-sistemas-fixture/2026-2-semester/t01/", "offering-pt-br.png"],
      ["/pt-br/publicacoes/publicacao-demonstracao-fixture/", "publication-pt-br.png"],
      ["/pt-br/eventos/", "events-pt-br.png"],
      ["/pt-br/oportunidades/", "opportunities-pt-br.png"],
      ["/pt-br/noticias/noticia-teste/", "news-single-pt-br.png"],
      ["/pt-br/sobre/", "institutional-pt-br.png"],
      ["/en/people/signals-faculty-fixture/", "person-profile-en.png"],
    ];
    for (const [route, name] of shots) {
      const response = await page.goto(route, { waitUntil: "domcontentloaded" });
      expect(response?.status(), `${route} screenshot`).toBeLessThan(400);
      await page.screenshot({ path: `${SCREENSHOTS}/${name}`, fullPage: true });
    }
    await context.close();
  });
});
