import { mkdirSync } from "node:fs";
import path from "node:path";
import { expect, test } from "@playwright/test";
import { fixtureNamespace } from "../../js/lps-redesign/fixtures.mjs";

const SHOTS = path.join(fixtureNamespace(), "task-02-e2e");
const FIXTURE = `file://${path.resolve("tests/fixtures/lps-redesign/logo-fixture.html")}`;

test.beforeAll(() => {
  mkdirSync(SHOTS, { recursive: true });
});

test.describe("task-02: development runtime serves the incumbent site", () => {
  test("the running WordPress surface answers and renders the LPS shell", async ({ request }) => {
    // An unreachable env must fail here explicitly, never pass with zero tests.
    const response = await request.get("/pt-br/");
    expect(response.status()).toBe(200);
    const html = await response.text();
    expect(html).toContain("wp-site-blocks");
    expect(html).toContain("Laboratório de Processamento de Sinais");
  });
});

test.describe("task-02: supplied logo renders at intended sizes in the isolated fixture", () => {
  test("desktop masthead renders the full artwork with its intrinsic aspect ratio", async ({
    page,
  }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto(FIXTURE);
    const logo = page.locator(".masthead-desktop .logo-full");
    await expect(logo).toBeVisible();

    const box = await logo.boundingBox();
    expect(box.width).toBeGreaterThanOrEqual(240);
    expect(box.width).toBeLessThanOrEqual(320);
    // Intrinsic artwork ratio 2052:301 ≈ 6.82; rendered box must preserve it.
    expect(box.width / box.height).toBeGreaterThan(6.4);
    expect(box.width / box.height).toBeLessThan(7.2);

    const natural = await logo.evaluate((img) => ({
      w: img.naturalWidth,
      h: img.naturalHeight,
      complete: img.complete,
    }));
    expect(natural.complete).toBe(true);
    expect(natural.w).toBeGreaterThan(0);

    // Surrounding label stays readable: >= 16px and contract text color.
    const label = page.locator(".masthead-desktop .site-name");
    const labelStyle = await label.evaluate((el) => {
      const cs = getComputedStyle(el);
      return { size: parseFloat(cs.fontSize), color: cs.color };
    });
    expect(labelStyle.size).toBeGreaterThanOrEqual(16);
    expect(labelStyle.color).toBe("rgb(18, 48, 74)"); // --color-anchor #12304A

    await page.screenshot({
      path: path.join(SHOTS, "fixture-desktop-1280.png"),
      fullPage: true,
    });
  });

  test("mobile masthead renders the compact variant and a labelled disclosure", async ({
    page,
  }) => {
    await page.setViewportSize({ width: 375, height: 720 });
    await page.goto(FIXTURE);
    const logo = page.locator(".masthead-mobile .logo-compact");
    await expect(logo).toBeVisible();

    const box = await logo.boundingBox();
    // Compact rule: never below 28px rendered height.
    expect(box.height).toBeGreaterThanOrEqual(28);
    // Compact artwork ratio 1322:275 ≈ 4.81.
    expect(box.width / box.height).toBeGreaterThan(4.5);
    expect(box.width / box.height).toBeLessThan(5.1);

    // The compact file, not the full lockup, is what mobile renders.
    const src = await logo.getAttribute("src");
    expect(src).toContain("lps_logo_compact.svg");

    // Labelled disclosure button with aria-expanded, >= 44px target.
    const disclosure = page.locator(".masthead-mobile .disclosure");
    await expect(disclosure).toHaveAttribute("aria-expanded", "false");
    const dBox = await disclosure.boundingBox();
    expect(dBox.height).toBeGreaterThanOrEqual(44);

    // No page-level horizontal overflow at 375px.
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);

    await page.screenshot({
      path: path.join(SHOTS, "fixture-mobile-375.png"),
      fullPage: true,
    });
  });

  test("anchor band uses the text wordmark — no artwork on dark surfaces", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto(FIXTURE);
    const band = page.locator(".band-anchor");
    await expect(band.locator(".wordmark")).toHaveText("LPS");
    // The logo's dark blues measure 1.59-2.25:1 on --color-anchor, so artwork
    // is banned here: the band must contain no <img> at all.
    await expect(band.locator("img")).toHaveCount(0);
  });

  test("fixture token values match the contract palette", async ({ page }) => {
    const contract = JSON.parse(
      (await import("node:fs")).readFileSync("docs/design/design-contract.json", "utf8"),
    );
    const tokens = new Map(contract.colors.tokens.map((t) => [t.token, t.value]));
    await page.goto(FIXTURE);
    const computed = await page.evaluate(() => {
      const cs = getComputedStyle(document.documentElement);
      return {
        "--color-canvas": cs.getPropertyValue("--color-canvas").trim().toUpperCase(),
        "--color-anchor": cs.getPropertyValue("--color-anchor").trim().toUpperCase(),
        "--color-action": cs.getPropertyValue("--color-action").trim().toUpperCase(),
        "--color-text": cs.getPropertyValue("--color-text").trim().toUpperCase(),
      };
    });
    // The fixture is the executable render of the contract: values must agree.
    for (const [token, value] of Object.entries(computed)) {
      expect(tokens.get(token)).toBe(value);
    }
  });

  test("focus-visible produces a visible outline on light and dark surfaces", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto(FIXTURE);
    const link = page.locator(".context a").first();
    await link.focus();
    const outline = await link.evaluate((el) => {
      const cs = getComputedStyle(el);
      return { width: cs.outlineWidth, style: cs.outlineStyle };
    });
    expect(outline.style).toBe("solid");
    expect(parseFloat(outline.width)).toBeGreaterThanOrEqual(3);

    const darkLink = page.locator(".band-anchor a").first();
    await darkLink.focus();
    const darkOutline = await darkLink.evaluate((el) => getComputedStyle(el).outlineColor);
    expect(darkOutline).toBe("rgb(255, 255, 255)"); // --color-focus-on-dark
  });
});
