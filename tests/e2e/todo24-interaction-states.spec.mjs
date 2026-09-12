import { expect, test } from "@playwright/test";

/**
 * Todo 24 - rendered interaction states.
 *
 * DESIGN.md requires every navigation control to expose rest, hover, focus,
 * pressed and current states. A control that renders identically on hover gives
 * no affordance, so these journeys assert a real computed change in the browser
 * rather than the presence of a CSS rule.
 */

const LOCALES = [
  { locale: "pt-br", home: "/pt-br/" },
  { locale: "en", home: "/en/" },
];

/** Suppresses the Playground auto-login so the anonymous public shell renders. */
async function anonymous(context, baseURL) {
  await context.addCookies([
    { name: "playground_auto_login_already_happened", value: "1", url: new URL(baseURL).origin },
  ]);
}

function stateOf(locator) {
  return locator.evaluate((element) => {
    const style = getComputedStyle(element);
    return {
      color: style.color,
      background: style.backgroundColor,
      textDecorationLine: style.textDecorationLine,
      textDecorationThickness: style.textDecorationThickness,
      borderBlockEndWidth: style.borderBlockEndWidth,
      outlineWidth: style.outlineWidth,
      outlineStyle: style.outlineStyle,
      outlineOffset: style.outlineOffset,
    };
  });
}

function changed(rest, hover) {
  return (
    rest.color !== hover.color ||
    rest.background !== hover.background ||
    rest.textDecorationLine !== hover.textDecorationLine ||
    rest.textDecorationThickness !== hover.textDecorationThickness ||
    rest.borderBlockEndWidth !== hover.borderBlockEndWidth
  );
}

for (const { locale, home } of LOCALES) {
  test.describe(`Todo 24 interaction states (${locale})`, () => {
    test.use({ viewport: { width: 1280, height: 800 } });

    test(`primary navigation shows a hover state (${locale})`, async ({
      page,
      context,
      baseURL,
    }) => {
      await anonymous(context, baseURL);
      await page.goto(home, { waitUntil: "domcontentloaded" });

      const link = page.locator(".lps-primary-nav a").first();
      await expect(link).toBeVisible();
      await page.mouse.move(0, 0);
      const rest = await stateOf(link);
      await link.hover();
      const hover = await stateOf(link);

      expect(changed(rest, hover), `primary navigation hover state (${locale})`).toBe(true);
    });

    test(`locale switch shows a hover state (${locale})`, async ({ page, context, baseURL }) => {
      await anonymous(context, baseURL);
      await page.goto(home, { waitUntil: "domcontentloaded" });

      const link = page.locator('.lps-locale a:not([aria-current="page"])').first();
      await expect(link).toBeVisible();
      await page.mouse.move(0, 0);
      const rest = await stateOf(link);
      await link.hover();
      const hover = await stateOf(link);

      expect(changed(rest, hover), `locale control hover state (${locale})`).toBe(true);
    });

    test(`navigation keeps a visible focus ring (${locale})`, async ({
      page,
      context,
      baseURL,
    }) => {
      await anonymous(context, baseURL);
      await page.goto(home, { waitUntil: "domcontentloaded" });

      const link = page.locator(".lps-primary-nav a").first();
      await link.focus();
      await page.keyboard.press("Tab");
      await page.keyboard.press("Shift+Tab");
      const focused = await stateOf(link);

      expect(focused.outlineStyle).not.toBe("none");
      expect(Number.parseFloat(focused.outlineWidth)).toBeGreaterThanOrEqual(3);
      expect(Number.parseFloat(focused.outlineOffset)).toBeGreaterThanOrEqual(3);
    });
  });
}

/**
 * Listing rows place their metadata beside the record link. Without explicit
 * spacing the two inline elements render as one run of text ("seminarPast"), so
 * these journeys assert a real rendered gap rather than the presence of a rule.
 */
const LISTING_ROWS = [
  {
    id: "events-pt",
    path: "/pt-br/eventos/",
    row: "ul.lps-event-listing li",
    meta: ".lps-event-state",
  },
  {
    id: "events-en",
    path: "/en/events/",
    row: "ul.lps-event-listing li",
    meta: ".lps-event-state",
  },
  {
    id: "opportunities-pt",
    path: "/pt-br/oportunidades/",
    row: "ul.lps-opportunity-listing li",
    meta: ".lps-opportunity-state",
  },
  { id: "news-pt", path: "/pt-br/noticias/", row: "ul.lps-news-listing li", meta: "time" },
  { id: "people-pt", path: "/pt-br/pessoas/", row: "section.lps-people ul li", meta: ".lps-role" },
];

for (const listing of LISTING_ROWS) {
  test(`listing metadata is visually separated from the record link (${listing.id})`, async ({
    page,
    context,
    baseURL,
  }) => {
    await anonymous(context, baseURL);
    await page.goto(listing.path, { waitUntil: "domcontentloaded" });

    const row = page.locator(listing.row).first();
    await expect(row).toBeVisible();
    const link = row.locator("a").first();
    const meta = row.locator(listing.meta).first();
    await expect(meta).toBeVisible();

    const [linkBox, metaBox] = await Promise.all([link.boundingBox(), meta.boundingBox()]);
    expect(linkBox, "record link box").not.toBeNull();
    expect(metaBox, "metadata box").not.toBeNull();

    const sameLine = Math.abs(linkBox.y - metaBox.y) < linkBox.height;
    const gap = sameLine
      ? metaBox.x - (linkBox.x + linkBox.width)
      : metaBox.y - (linkBox.y + linkBox.height);
    expect(gap, `${listing.id} gap between record link and metadata`).toBeGreaterThanOrEqual(8);
  });
}

test("related record rows separate the record name from its status", async ({
  page,
  context,
  baseURL,
}) => {
  await anonymous(context, baseURL);
  await page.goto("/pt-br/projetos/projeto-demonstracao-fixture/", {
    waitUntil: "domcontentloaded",
  });

  const row = page.locator("ul.lps-members li", { has: page.locator(".lps-status") }).first();
  await expect(row).toBeVisible();
  const name = row.locator("span").first();
  const status = row.locator(".lps-status");
  const [nameBox, statusBox] = await Promise.all([name.boundingBox(), status.boundingBox()]);

  const sameLine = Math.abs(nameBox.y - statusBox.y) < nameBox.height;
  const gap = sameLine
    ? statusBox.x - (nameBox.x + nameBox.width)
    : statusBox.y - (nameBox.y + nameBox.height);
  expect(gap, "gap between member name and status").toBeGreaterThanOrEqual(8);
});
