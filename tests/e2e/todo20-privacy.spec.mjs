import { readFileSync } from "node:fs";
import { expect, test } from "@playwright/test";

const inventory = JSON.parse(
  readFileSync(
    new URL("../../wp-content/plugins/lps-content-model/privacy-inventory.json", import.meta.url),
    "utf8",
  ),
);

const PUBLIC_PAGES = [
  "/pt-br/",
  "/en/",
  "/pt-br/privacidade/",
  "/en/privacy/",
  "/pt-br/busca/?q=lps",
];

function isExternal(url, origin) {
  return !url.startsWith(origin) && !url.startsWith("data:") && !url.startsWith("blob:");
}

/**
 * `external` records requests the page attempted; `delivered` records the ones that
 * actually reached the network and returned a response. A CSP-blocked load still
 * raises a request event, so blocking is proven by an empty `delivered` list.
 */
function auditPage(page, origin) {
  const external = [];
  const delivered = [];
  const failures = [];
  page.on("request", (request) => {
    if (isExternal(request.url(), origin)) external.push(request.url());
  });
  page.on("response", (response) => {
    if (isExternal(response.url(), origin)) delivered.push(response.url());
  });
  page.on("pageerror", (error) => failures.push(String(error)));
  return { external, delivered, failures };
}

test.describe("public privacy and network boundary", () => {
  for (const path of PUBLIC_PAGES) {
    test(`no undeclared requests, cookies or storage on ${path}`, async ({ page, baseURL }) => {
      const origin = new URL(baseURL).origin;
      const audit = auditPage(page, origin);
      // Bounded DOM-state contract: wait for the document plus the visible
      // body instead of `networkidle`, which is timing-dependent and can
      // hide or miss slow external loads.
      await page.goto(path, { waitUntil: "domcontentloaded" });
      await expect(page.locator("body")).toBeVisible({ timeout: 15_000 });

      expect(audit.external, `external requests on ${path}`).toEqual(inventory.third_party_runtime);
      expect(audit.delivered, `external responses on ${path}`).toEqual(
        inventory.third_party_runtime,
      );
      expect(await page.context().cookies(), `cookies on ${path}`).toEqual(
        inventory.public_cookies,
      );

      const storage = await page.evaluate(() => ({
        local: Object.keys(window.localStorage),
        session: Object.keys(window.sessionStorage),
      }));
      expect(storage.local, `localStorage on ${path}`).toEqual(inventory.public_storage);
      expect(storage.session, `sessionStorage on ${path}`).toEqual(inventory.public_storage);
      expect(audit.failures, `page errors on ${path}`).toEqual([]);
    });
  }

  test("privacy notice is rendered from the deployed inventory", async ({ page }) => {
    await page.goto("/en/privacy/");
    const notice = page.locator("section.lps-privacy-inventory");
    await expect(notice).toBeVisible();
    const paragraphs = await notice.locator("p").allInnerTexts();
    expect(paragraphs).toEqual(inventory.notices.map((entry) => entry.en));

    await page.goto("/pt-br/privacidade/");
    const ptParagraphs = await page.locator("section.lps-privacy-inventory p").allInnerTexts();
    expect(ptParagraphs).toEqual(inventory.notices.map((entry) => entry.pt));
  });

  test("content security policy blocks an injected third-party tracker and inline handler", async ({
    page,
    baseURL,
  }) => {
    const origin = new URL(baseURL).origin;
    const audit = auditPage(page, origin);
    const violations = [];
    await page.addInitScript(() => {
      window.__lpsCspViolations = [];
      document.addEventListener("securitypolicyviolation", (event) => {
        window.__lpsCspViolations.push(event.violatedDirective);
      });
      window.__lpsTrackerFired = false;
    });
    await page.goto("/pt-br/", { waitUntil: "domcontentloaded" });
    await expect(page.locator("body")).toBeVisible({ timeout: 15_000 });

    await page.evaluate(() => {
      const script = document.createElement("script");
      script.src = "https://tracker.invalid/pixel.js";
      document.head.append(script);
      const inline = document.createElement("script");
      inline.textContent = "window.__lpsTrackerFired = true;";
      document.head.append(inline);
      const button = document.createElement("button");
      button.setAttribute("onclick", "window.__lpsTrackerFired = true");
      document.body.append(button);
      button.click();
    });
    // Bounded storage-signal contract: wait for the exact CSP-violation array
    // to become non-empty instead of polling an assertion. The 5s bound is
    // the same budget the previous `expect.poll` used, now expressed as an
    // explicit event wait.
    await page.waitForFunction(
      () => window.__lpsCspViolations && window.__lpsCspViolations.length > 0,
      undefined,
      { timeout: 5_000 },
    );
    violations.push(...(await page.evaluate(() => window.__lpsCspViolations)));

    expect(violations.some((directive) => directive.startsWith("script-src"))).toBe(true);
    expect(await page.evaluate(() => window.__lpsTrackerFired)).toBe(false);
    // A CSP-blocked script raises a request event in Chromium but not in
    // Firefox, so `external` holds the tracker at most; the boundary that
    // matters is that nothing external was delivered and nothing else was
    // attempted.
    expect(audit.external.filter((url) => url !== "https://tracker.invalid/pixel.js")).toEqual([]);
    expect(audit.delivered, "the blocked tracker must never reach the network").toEqual([]);
  });

  test("stored markup payloads are escaped rather than executed", async ({ page }) => {
    const executed = [];
    page.on("dialog", async (dialog) => {
      executed.push(dialog.message());
      await dialog.dismiss();
    });
    await page.goto(
      `/pt-br/busca/?q=${encodeURIComponent("<img src=x onerror=alert(1)><script>alert(2)</script>")}`,
      { waitUntil: "domcontentloaded" },
    );
    // Bounded DOM-state contract: the server-rendered search surface must
    // settle before asserting that no payload executed.
    await expect(page.locator("section.lps-search")).toBeVisible({ timeout: 15_000 });
    expect(executed).toEqual([]);
    expect(await page.locator("script:not([src])").filter({ hasText: "alert(2)" }).count()).toBe(0);
    expect(await page.locator("[onerror]").count()).toBe(0);
  });
});
