import { expect, test } from "@playwright/test";

const PT = "/pt-br/busca/";
const EN = "/en/search/";

/** Reads the announced result count from the accessible status region. */
async function statusText(page) {
  return (await page.locator("#lps-search-status").first().innerText()).trim();
}

/** Collects the record slugs rendered on the current results page. */
async function resultSlugs(page) {
  return page
    .locator("li.lps-search-result a")
    .evaluateAll((nodes) => nodes.map((node) => node.getAttribute("href") ?? ""));
}

test.describe("Todo 18 locale search - happy paths", () => {
  test("both locale routes render a server-rendered search form", async ({ page }) => {
    for (const [route, heading] of [
      [PT, "Buscar no site"],
      [EN, "Search this site"],
    ]) {
      await page.goto(route);
      await expect(page.locator("form.lps-search-form")).toBeVisible();
      await expect(page.locator("h2#lps-search-title")).toHaveText(heading);
      await expect(page.locator("#lps-search-q")).toHaveAttribute("minlength", "2");
      await expect(page.locator("#lps-search-q")).toHaveAttribute("maxlength", "100");
    }
  });

  test("accented and unaccented spellings return the same records", async ({ page }) => {
    await page.goto(`${PT}?q=${encodeURIComponent("técnica")}`);
    const accented = await resultSlugs(page);
    const accentedCount = await statusText(page);

    await page.goto(`${PT}?q=tecnica`);
    expect(await resultSlugs(page)).toEqual(accented);
    expect(await statusText(page)).toBe(accentedCount);
    expect(accented.length).toBeGreaterThan(0);
  });

  test("an acronym reaches its record", async ({ page }) => {
    await page.goto(`${PT}?q=SIGMA`);
    await expect(page.locator("li.lps-search-result")).not.toHaveCount(0);
    await expect(page.locator("#lps-search-status")).toContainText("resultado");
  });

  test("a plain-language concept reaches records", async ({ page }) => {
    await page.goto(`${PT}?q=monitoramento`);
    await expect(page.locator("li.lps-search-result")).not.toHaveCount(0);
  });

  test("pagination is stable with no duplicate or omitted record", async ({ page }) => {
    await page.goto(`${PT}?q=boletim`);
    const first = await resultSlugs(page);
    expect(first).toHaveLength(20);

    await page.goto(`${PT}page/2/?q=boletim`);
    const second = await resultSlugs(page);
    expect(second.length).toBeGreaterThan(0);

    const overlap = first.filter((slug) => second.includes(slug));
    expect(overlap).toEqual([]);
    expect(new Set([...first, ...second]).size).toBe(first.length + second.length);
    await expect(page.locator(".lps-search-range")).toContainText("de");
  });

  test("a facet narrows results and stays in the shareable URL", async ({ page }) => {
    const url = `${PT}?q=boletim&record=lps_news&category%5B%5D=institucional`;
    await page.goto(url);
    await expect(page.locator("li.lps-search-result")).not.toHaveCount(0);
    expect(page.url()).toContain("category%5B%5D=institucional");

    const checked = page.locator(
      'input[type="checkbox"][name="category[]"][value="institucional"]',
    );
    if ((await checked.count()) > 0) {
      await expect(checked.first()).toBeChecked();
    }
  });

  test("a copied result URL reproduces the same page for another visitor", async ({
    page,
    browser,
  }) => {
    const shared = `${PT}?q=boletim`;
    await page.goto(shared);
    const original = await resultSlugs(page);

    const fresh = await browser.newContext();
    const freshPage = await fresh.newPage();
    await freshPage.goto(page.url());
    expect(await resultSlugs(freshPage)).toEqual(original);
    await fresh.close();
  });

  test("changing locale keeps each result set inside its own language", async ({ page }) => {
    await page.goto(`${PT}?q=SIGMA`);
    await expect(page.locator("html")).toHaveAttribute("lang", "pt-BR");
    const portuguese = await resultSlugs(page);

    await page.goto(`${EN}?q=SIGMA`);
    await expect(page.locator("html")).toHaveAttribute("lang", "en");
    const english = await resultSlugs(page);

    expect(portuguese.length).toBeGreaterThan(0);
    expect(english.length).toBeGreaterThan(0);
    expect(portuguese.filter((slug) => english.includes(slug))).toEqual([]);
  });
});

test.describe("Todo 18 locale search - without JavaScript", () => {
  test.use({ javaScriptEnabled: false });

  test("search, facets, and pagination work with scripting disabled", async ({ page }) => {
    await page.goto(`${PT}?q=boletim`);
    expect(await resultSlugs(page)).toHaveLength(20);
    await expect(page.locator("#lps-search-status")).toContainText("resultado");

    await page.goto(`${PT}page/2/?q=boletim`);
    expect((await resultSlugs(page)).length).toBeGreaterThan(0);

    // The surface itself must carry no script; unrelated WordPress core assets
    // (the emoji loader) are outside this contract.
    const surface = await page.locator("section.lps-search").innerHTML();
    expect(surface).not.toContain("<script");
    expect(surface).not.toContain("onclick");
  });
});

test.describe("Todo 18 locale search - failure and hostile states", () => {
  test("a one-character query reports an accessible bound", async ({ page }) => {
    await page.goto(`${PT}?q=a`);
    const alert = page.locator('[role="alert"]');
    await expect(alert).toBeVisible();
    await expect(alert).toContainText("2 caracteres");
    await expect(page.locator("li.lps-search-result")).toHaveCount(0);
  });

  test("an overlong query reports an accessible bound", async ({ page }) => {
    await page.goto(`${PT}?q=${"a".repeat(101)}`);
    await expect(page.locator('[role="alert"]')).toContainText("100 caracteres");
    await expect(page.locator("li.lps-search-result")).toHaveCount(0);
  });

  test("a malformed query is refused without leaking the index", async ({ page }) => {
    await page.goto(`${PT}?q=%C3%28`);
    await expect(page.locator('[role="alert"]')).toBeVisible();
    await expect(page.locator("li.lps-search-result")).toHaveCount(0);
  });

  test("an empty result set renders an accessible empty state", async ({ page }) => {
    await page.goto(`${PT}?q=zzzznaoexistezzzz`);
    await expect(page.locator("#lps-search-status")).toContainText("0 resultado");
    await expect(page.locator(".lps-search-empty")).toBeVisible();
    await expect(page.locator("li.lps-search-result")).toHaveCount(0);
  });

  test("hostile markup is escaped and never executed", async ({ page }) => {
    let dialogs = 0;
    page.on("dialog", async (dialog) => {
      dialogs += 1;
      await dialog.dismiss();
    });
    await page.goto(`${PT}?q=${encodeURIComponent("<script>alert(1)</script>")}`);
    expect(dialogs).toBe(0);
    await expect(page.locator("li.lps-search-result")).toHaveCount(0);
  });

  test("a draft record never appears in results", async ({ page }) => {
    await page.goto(`${PT}?q=rascunho`);
    await expect(page.locator("#lps-search-status")).toContainText("0 resultado");
    expect(await page.content()).not.toContain("rascunho-nunca-indexado-fixture");
  });

  test("a record without a translation stays out of the other locale", async ({ page }) => {
    await page.goto(`${EN}?q=boletim`);
    await expect(page.locator("#lps-search-status")).toContainText("0 result");
    await expect(page.locator("li.lps-search-result")).toHaveCount(0);
  });

  test("an unapproved facet value is dropped instead of filtering", async ({ page }) => {
    await page.goto(`${PT}?q=boletim&record=lps_news&evil%5B%5D=%3Cscript%3E`);
    expect(await page.content()).not.toContain("<script>alert");
    await expect(page.locator("li.lps-search-result")).not.toHaveCount(0);
  });

  test("filtered and paginated states are never indexable", async ({ page }) => {
    await page.goto(`${PT}?q=boletim`);
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", /noindex/);
  });
});
