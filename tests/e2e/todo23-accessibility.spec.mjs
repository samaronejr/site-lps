/**
 * Todo 23 - agent-operated keyboard, focus, reflow, and reduced-motion journeys.
 *
 * Every interaction below is performed with the keyboard only. Nothing is clicked,
 * nothing is scrolled programmatically, and every wait is bound to an observable
 * navigation or DOM state rather than to elapsed time.
 */

import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";
import { expect, test } from "@playwright/test";

const OUTPUT_DIR = process.env.LPS_A11Y_OUTPUT ?? ".omo/evidence/task-23/live";
const MAX_TABS = 80;

const LOCALES = {
  "pt-br": {
    home: "/pt-br/",
    search: "/pt-br/busca/",
    searchTerm: "sinais",
    people: "/pt-br/pessoas/",
    opportunity: "/pt-br/oportunidades/bolsa-doutorado-sinais/",
    publication: "/pt-br/publicacoes/publicacao-demonstracao-fixture/",
    accessibility: "/pt-br/acessibilidade/",
    otherLocaleCode: "en",
  },
  en: {
    home: "/en/",
    search: "/en/search/",
    searchTerm: "signal",
    people: "/en/people/",
    opportunity: "/en/opportunities/doctoral-scholarship-signal-processing/",
    publication: "/en/publications/demonstration-publication-fixture/",
    accessibility: "/en/accessibility/",
    otherLocaleCode: "pt",
  },
};

const results = [];

function record(id, locale, status, details) {
  results.push({ id, locale, status, ...details });
}

/** Describes the currently focused element. */
async function focused(page) {
  return page.evaluate(() => {
    const element = document.activeElement;
    if (!element || element === document.body) return null;
    const style = getComputedStyle(element);
    const rect = element.getBoundingClientRect();
    const centre = document.elementFromPoint(
      rect.left + rect.width / 2,
      rect.top + rect.height / 2,
    );
    return {
      tag: element.tagName.toLowerCase(),
      id: element.id,
      className: element.className?.toString?.() ?? "",
      name: element.getAttribute("aria-label") ?? element.textContent?.trim().slice(0, 80) ?? "",
      href: element.getAttribute("href"),
      type: element.getAttribute("type"),
      outlineWidth: Number.parseFloat(style.outlineWidth) || 0,
      outlineStyle: style.outlineStyle,
      rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
      inViewport:
        rect.top >= 0 &&
        rect.left >= 0 &&
        rect.bottom <= window.innerHeight &&
        rect.right <= window.innerWidth,
      topmost: centre === element || element.contains(centre),
      documentOrder: [...document.querySelectorAll("*")].indexOf(element),
      selector: element.id
        ? `#${element.id}`
        : `${element.tagName.toLowerCase()}${element.className ? `.${element.className.toString().split(" ")[0]}` : ""}`,
    };
  });
}

/** Tabs forward until `match` accepts the focused element; returns the whole focus order. */
async function tabUntil(page, match, { max = MAX_TABS } = {}) {
  const order = [];
  for (let step = 0; step < max; step += 1) {
    await page.keyboard.press("Tab");
    const current = await focused(page);
    if (!current) continue;
    order.push(current);
    if (match(current)) return { found: current, order };
  }
  return { found: null, order };
}

/** Asserts that keyboard focus is visible, on screen, and not covered by another element. */
function assertFocusQuality(entry) {
  expect(entry.outlineWidth, `focus indicator on ${entry.selector}`).toBeGreaterThanOrEqual(2);
  expect(entry.outlineStyle, `focus outline style on ${entry.selector}`).not.toBe("none");
  expect(entry.inViewport, `focus on ${entry.selector} is inside the viewport`).toBe(true);
  expect(entry.topmost, `focus on ${entry.selector} is not obscured`).toBe(true);
}

/** Asserts that an interactive target meets the 24 CSS px minimum target size. */
function assertTargetSize(entry) {
  const smallest = Math.min(entry.rect.width, entry.rect.height);
  expect(smallest, `target size of ${entry.selector}`).toBeGreaterThanOrEqual(24);
}

test.describe("Todo 23 keyboard journeys", () => {
  test.use({ viewport: { width: 1280, height: 900 } });

  for (const [locale, config] of Object.entries(LOCALES)) {
    test(`search journey by keyboard (${locale})`, async ({ page }) => {
      await page.goto(config.home, { waitUntil: "domcontentloaded" });
      const skip = await tabUntil(page, (entry) => entry.className.includes("lps-skip-link"), {
        max: 3,
      });
      expect(skip.found, "the first tab stop is the skip link").not.toBeNull();
      assertFocusQuality(skip.found);
      const field = await tabUntil(page, (entry) => entry.id === "lps-search-input");
      expect(field.found, "the header search field is reachable by keyboard").not.toBeNull();
      assertFocusQuality(field.found);
      assertTargetSize(field.found);
      await page.keyboard.type(config.searchTerm);
      await Promise.all([
        page.waitForNavigation({ waitUntil: "domcontentloaded" }),
        page.keyboard.press("Enter"),
      ]);
      const status = page.locator('[role="status"], [role="alert"]').first();
      await expect(status).toBeVisible();
      record("search", locale, "passed", {
        url: page.url(),
        selector: "#lps-search-input",
        announced: (await status.textContent())?.trim(),
        focusOrder: field.order.length,
      });
    });

    test(`filter journey by keyboard (${locale})`, async ({ page }) => {
      await page.goto(`${config.search}?q=${config.searchTerm}`, { waitUntil: "domcontentloaded" });
      const field = await tabUntil(page, (entry) => entry.id === "lps-search-q");
      expect(field.found, "the search field is reachable").not.toBeNull();
      assertFocusQuality(field.found);
      const select = await tabUntil(page, (entry) => entry.id === "lps-search-record");
      expect(select.found, "the record-type filter is reachable").not.toBeNull();
      assertFocusQuality(select.found);
      assertTargetSize(select.found);
      await page.keyboard.press("ArrowDown");
      const submit = await tabUntil(
        page,
        (entry) => entry.tag === "button" && entry.type === "submit",
      );
      expect(submit.found, "the filter submit button is reachable").not.toBeNull();
      assertFocusQuality(submit.found);
      assertTargetSize(submit.found);
      await Promise.all([
        page.waitForNavigation({ waitUntil: "domcontentloaded" }),
        page.keyboard.press("Enter"),
      ]);
      const status = page.locator("#lps-search-status");
      await expect(status).toBeVisible();
      await expect(status).toHaveAttribute("role", /status|alert/u);
      record("filter", locale, "passed", {
        url: page.url(),
        selector: "#lps-search-record",
        announced: (await status.textContent())?.trim(),
      });
    });

    test(`language switch by keyboard (${locale})`, async ({ page }) => {
      await page.goto(config.home, { waitUntil: "domcontentloaded" });
      const target = await tabUntil(
        page,
        (entry) =>
          entry.tag === "a" &&
          (entry.href ?? "").startsWith(`/${config.otherLocaleCode === "en" ? "en" : "pt-br"}/`),
      );
      expect(target.found, "the other locale is reachable by keyboard").not.toBeNull();
      assertFocusQuality(target.found);
      assertTargetSize(target.found);
      await Promise.all([
        page.waitForNavigation({ waitUntil: "domcontentloaded" }),
        page.keyboard.press("Enter"),
      ]);
      const lang = await page.getAttribute("html", "lang");
      expect(lang?.toLowerCase()).toContain(config.otherLocaleCode);
      record("language-switch", locale, "passed", {
        url: page.url(),
        selector: ".lps-locale a",
        lang,
      });
    });

    test(`opportunity contact by keyboard (${locale})`, async ({ page }) => {
      await page.goto(config.opportunity, { waitUntil: "domcontentloaded" });
      const contact = await tabUntil(page, (entry) => (entry.href ?? "").startsWith("mailto:"));
      expect(contact.found, "the opportunity contact is reachable by keyboard").not.toBeNull();
      assertFocusQuality(contact.found);
      assertTargetSize(contact.found);
      expect(contact.found.name.length, "the contact link has an accessible name").toBeGreaterThan(
        0,
      );
      record("opportunity-contact", locale, "passed", {
        url: page.url(),
        selector: contact.found.selector,
        name: contact.found.name,
      });
    });

    test(`citation download by keyboard (${locale})`, async ({ page }) => {
      await page.goto(config.publication, { waitUntil: "domcontentloaded" });
      const citation = await tabUntil(page, (entry) =>
        (entry.href ?? "").includes("lps_citation=bibtex"),
      );
      expect(citation.found, "the BibTeX download is reachable by keyboard").not.toBeNull();
      assertFocusQuality(citation.found);
      assertTargetSize(citation.found);
      expect(citation.found.name.toLowerCase()).toContain("bibtex");
      const response = await page.request.get(new URL(citation.found.href, page.url()).toString());
      expect(response.status()).toBe(200);
      const body = await response.text();
      expect(body).toContain("@article{");
      record("citation-download", locale, "passed", {
        url: page.url(),
        selector: citation.found.selector,
        name: citation.found.name,
        bytes: body.length,
      });
    });

    test(`barrier reporting by keyboard (${locale})`, async ({ page }) => {
      await page.goto(config.accessibility, { waitUntil: "domcontentloaded" });
      const report = await tabUntil(page, (entry) => (entry.href ?? "").startsWith("mailto:"));
      expect(
        report.found,
        "the accessibility barrier contact is reachable by keyboard",
      ).not.toBeNull();
      assertFocusQuality(report.found);
      assertTargetSize(report.found);
      record("barrier-reporting", locale, "passed", {
        url: page.url(),
        selector: report.found.selector,
        name: report.found.name,
      });
    });

    test(`no keyboard trap across the whole page (${locale})`, async ({ page }) => {
      await page.goto(config.search, { waitUntil: "domcontentloaded" });
      const seen = [];
      for (let step = 0; step < MAX_TABS; step += 1) {
        await page.keyboard.press("Tab");
        const entry = await focused(page);
        if (!entry) break;
        // A trap is the same element receiving focus repeatedly, identified by its
        // position in the document: consecutive links share a generic selector.
        seen.push(entry.documentOrder);
        const window = seen.slice(-6);
        if (window.length === 6 && new Set(window).size === 1) {
          throw new Error(`Keyboard focus is trapped on ${entry.selector}`);
        }
      }
      const unique = new Set(seen);
      expect(unique.size, "focus visits multiple controls").toBeGreaterThan(3);
      record("keyboard-trap-sweep", locale, "passed", {
        url: page.url(),
        stops: seen.length,
        unique: unique.size,
      });
    });

    test(`reflow at 320 CSS px without horizontal scrolling (${locale})`, async ({ page }) => {
      await page.setViewportSize({ width: 320, height: 800 });
      for (const route of [config.home, config.search, config.people, config.publication]) {
        await page.goto(route, { waitUntil: "domcontentloaded" });
        const overflow = await page.evaluate(
          () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );
        expect(overflow, `horizontal overflow on ${route}`).toBeLessThanOrEqual(1);
      }
      record("reflow-320", locale, "passed", { url: page.url() });
    });

    test(`text spacing and 400% zoom remain readable (${locale})`, async ({ page }) => {
      await page.setViewportSize({ width: 320, height: 800 });
      await page.goto(config.home, { waitUntil: "domcontentloaded" });
      await page.addStyleTag({
        content:
          "* { line-height: 1.5 !important; letter-spacing: 0.12em !important; word-spacing: 0.16em !important; } p { margin-bottom: 2em !important; }",
      });
      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      );
      expect(overflow, "text-spacing overrides cause no horizontal scrolling").toBeLessThanOrEqual(
        1,
      );
      record("text-spacing-zoom", locale, "passed", { url: page.url(), overflow });
    });
  }

  test("reduced motion removes transitions and animations", async ({ browser }) => {
    const context = await browser.newContext({
      reducedMotion: "reduce",
      viewport: { width: 1280, height: 900 },
    });
    const page = await context.newPage();
    await page.goto("/pt-br/", { waitUntil: "domcontentloaded" });
    const durations = await page.evaluate(() =>
      [...document.querySelectorAll("a, button, summary, .lps-skip-link")].map((element) => {
        const style = getComputedStyle(element);
        return {
          transition: Number.parseFloat(style.transitionDuration) || 0,
          animation: Number.parseFloat(style.animationDuration) || 0,
        };
      }),
    );
    for (const duration of durations) {
      expect(duration.transition).toBeLessThanOrEqual(0.001);
      expect(duration.animation).toBeLessThanOrEqual(0.001);
    }
    record("reduced-motion", "pt-br", "passed", { url: page.url(), controls: durations.length });
    await context.close();
  });

  test.afterAll(async () => {
    await mkdir(OUTPUT_DIR, { recursive: true });
    await writeFile(
      path.join(OUTPUT_DIR, "keyboard-journeys.json"),
      `${JSON.stringify({ schemaVersion: 1, ranAt: new Date().toISOString(), journeys: results }, null, 2)}\n`,
    );
  });
});
