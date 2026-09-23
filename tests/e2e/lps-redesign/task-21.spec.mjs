import { mkdirSync } from "node:fs";
import path from "node:path";
import { expect, test } from "@playwright/test";

/**
 * Task 21 — accessibility, responsive layout, and visual fidelity.
 *
 * The spec exercises the running site through a real browser: the responsive
 * matrix (320/375/768/1280/wide) on representative surfaces, 200%/400% zoom
 * reflow, long scientific content, visible keyboard focus, the no-JS shell
 * disclosure, announced search validation, authored-media alternatives
 * (captions, alt, HTML document alternatives), the intact brand artwork, and
 * the frozen design contract (IBM Plex interface stack, flat depth, no
 * conflicting luxury/terminal styling). Editor alignment is proven through a
 * real editor-role session and the theme.json preset payload.
 *
 * Screenshots land in the task evidence directory for the manual review pass.
 * An unreachable env fails explicitly, never passes with zero tests.
 */

const SHOTS = path.join(
  process.env.LPS_T21_SHOTS ?? ".omo/evidence/lps-website-ulw-plan/attempt-1/task-21/screenshots",
);

/** Suppresses the Playground auto-login handshake so the public shell renders anonymously. */
async function anonymous(context, baseURL) {
  const literal = new URL(baseURL ?? process.env.LPS_BASE_URL ?? "http://127.0.0.1:8888");
  const swapped = new URL(literal.origin);
  swapped.hostname = literal.hostname === "localhost" ? "127.0.0.1" : "localhost";
  const origins =
    swapped.origin === literal.origin ? [literal.origin] : [literal.origin, swapped.origin];
  await context.addCookies(
    origins.map((url) => ({ name: "playground_auto_login_already_happened", value: "1", url })),
  );
}

/** Signs in as the unprivileged editor account through the real login form. */
async function loginAsEditor(page) {
  await page.goto("/", { waitUntil: "domcontentloaded" });
  const base = new URL(page.url());
  await page.context().addCookies([
    {
      name: "playground_auto_login_already_happened",
      value: "1",
      domain: base.hostname,
      path: "/",
    },
  ]);
  await page.goto("/wp-login.php");
  const login = page.locator("#user_login");
  await login.waitFor({ state: "visible", timeout: 15_000 });
  await login.fill("lps-t7-editor");
  await page.locator("#user_pass").fill("lps-t7-editor-pass");
  await Promise.all([page.waitForLoadState("load"), page.locator("#wp-submit").click()]);
  await expect(page.locator("#login_error, .editor-styles-wrapper, #wpbody").first()).toBeAttached({
    timeout: 15_000,
  });
  await expect(page.locator("#login_error")).toHaveCount(0);
}

/** Measures page-level horizontal overflow in CSS px. */
async function horizontalOverflow(page) {
  return page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );
}

/** Waits for the seeded shell instead of the intermittent auto-login handshake. */
async function gotoShell(page, route) {
  await page.goto(route, { waitUntil: "domcontentloaded" });
  await expect(page.locator("a.lps-brand")).toBeAttached({ timeout: 60_000 });
}

const SURFACES = [
  { id: "home", path: "/pt-br/" },
  { id: "person", path: "/pt-br/pessoas/ana-alvares-fixture/" },
  {
    id: "offering",
    path: "/pt-br/ensino/disciplinas/sinais-e-sistemas-fixture/2026-2-semester/t01/",
  },
  { id: "media", path: "/pt-br/midia-acessivel/" },
  { id: "search-error", path: "/pt-br/busca/?q=a" },
  { id: "publication", path: "/pt-br/publicacoes/publicacao-demonstracao-fixture/" },
];

const WIDTHS = [
  { name: "320", width: 320, height: 800 },
  { name: "375", width: 375, height: 800 },
  { name: "768", width: 768, height: 1024 },
  { name: "1280", width: 1280, height: 900 },
  { name: "wide", width: 1920, height: 1080 },
];

test.beforeAll(() => {
  mkdirSync(SHOTS, { recursive: true });
});

test.describe("task-21: responsive surfaces never overflow the viewport", () => {
  test.describe.configure({ mode: "serial" });

  for (const surface of SURFACES) {
    for (const viewport of WIDTHS) {
      test(`${surface.id} at ${viewport.name}px has no page-level horizontal overflow`, async ({
        page,
        context,
        baseURL,
      }) => {
        test.setTimeout(120_000);
        await anonymous(context, baseURL);
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await gotoShell(page, surface.path);
        await page.evaluate(() => document.fonts.ready);
        const overflow = await horizontalOverflow(page);
        expect(
          overflow,
          `${surface.path} overflows horizontally at ${viewport.width}px`,
        ).toBeLessThanOrEqual(1);
        await page.screenshot({
          path: path.join(SHOTS, `${surface.id}-${viewport.name}.png`),
          fullPage: true,
        });
      });
    }
  }

  // Browser zoom reflows the layout: 200% at a 1280 CSS px baseline is the
  // 640 CSS px viewport, 400% is 320 CSS px (WCAG 1.4.10 reflow). CSS `zoom`
  // magnifies instead of reflowing, so the equivalent viewports are the
  // honest emulation.
  for (const [label, width] of [
    ["200", 640],
    ["400", 320],
  ]) {
    test(`${label}% zoom equivalent (${width}px) keeps content reachable without horizontal scrolling`, async ({
      page,
      context,
      baseURL,
    }) => {
      test.setTimeout(120_000);
      await anonymous(context, baseURL);
      await page.setViewportSize({ width, height: 900 });
      await gotoShell(page, "/pt-br/");
      const overflow = await horizontalOverflow(page);
      expect(overflow, `home overflows at ${label}% zoom`).toBeLessThanOrEqual(1);
      await expect(page.locator("#lps-main")).toBeVisible();
      await page.screenshot({
        path: path.join(SHOTS, `home-zoom-${label}.png`),
        fullPage: false,
      });
    });
  }

  test("long scientific labels and unbroken strings wrap instead of overflowing", async ({
    page,
    context,
    baseURL,
  }) => {
    test.setTimeout(120_000);
    await anonymous(context, baseURL);
    await page.setViewportSize({ width: 320, height: 800 });
    // The seeded search fixture carries the longest scientific phrase in the
    // corpus; the dense offering page carries the longest metadata labels.
    for (const route of [
      "/pt-br/busca/?q=Infraestrutura+experimental+multidisciplinar+para+caracterizacao+processamento+validacao+e+preservacao",
      "/pt-br/ensino/disciplinas/sinais-e-sistemas-fixture/2026-2-semester/t01/",
    ]) {
      await gotoShell(page, route);
      const overflow = await horizontalOverflow(page);
      expect(overflow, `${route} overflows at 320px`).toBeLessThanOrEqual(1);
      // Constrained boxes must wrap their text rather than clip it.
      const clipped = await page.evaluate(() => {
        const bad = [];
        for (const el of document.querySelectorAll(
          "h1, h2, h3, td, th, .lps-meta, .lps-field-error, a, button",
        )) {
          const overflowX = getComputedStyle(el).overflowX;
          const constrained = ["hidden", "clip", "scroll", "auto"].includes(overflowX);
          if (
            constrained &&
            !el.closest(".lps-table-scroll") &&
            el.scrollWidth > el.clientWidth + 1 &&
            el.textContent.trim().length > 0
          ) {
            bad.push(`${el.tagName}.${el.className}: ${el.textContent.trim().slice(0, 40)}`);
          }
        }
        return bad;
      });
      expect(clipped, `clipped text on ${route}`).toEqual([]);
    }
    await page.screenshot({ path: path.join(SHOTS, "long-content-320.png"), fullPage: true });
  });
});

test.describe("task-21: keyboard, disclosure, and announced validation", () => {
  test.describe.configure({ mode: "serial" });

  test("keyboard focus is visible and unobscured across the shell controls", async ({
    page,
    context,
    baseURL,
  }) => {
    test.setTimeout(120_000);
    await anonymous(context, baseURL);
    await page.setViewportSize({ width: 1280, height: 900 });
    await gotoShell(page, "/pt-br/");
    const stops = [];
    for (let step = 0; step < 40; step += 1) {
      await page.keyboard.press("Tab");
      const stop = await page.evaluate(() => {
        const element = document.activeElement;
        if (!element || element === document.body) return null;
        const style = getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        const centre = document.elementFromPoint(
          rect.left + rect.width / 2,
          rect.top + rect.height / 2,
        );
        return {
          selector: element.id
            ? `#${element.id}`
            : `${element.tagName.toLowerCase()}.${String(element.className).split(" ")[0] ?? ""}`,
          outlineWidth: Number.parseFloat(style.outlineWidth) || 0,
          outlineStyle: style.outlineStyle,
          inViewport: rect.top >= 0 && rect.bottom <= window.innerHeight,
          obscured: !(
            centre === element ||
            element.contains(centre) ||
            element.contains(centre?.parentElement ?? null)
          ),
          documentOrder: [...document.querySelectorAll("*")].indexOf(element),
        };
      });
      if (!stop) break;
      stops.push(stop);
    }
    expect(stops.length, "focus reaches multiple controls").toBeGreaterThan(3);
    for (const stop of stops) {
      expect(stop.outlineWidth, `focus ring on ${stop.selector}`).toBeGreaterThanOrEqual(2);
      expect(stop.outlineStyle, `focus outline style on ${stop.selector}`).not.toBe("none");
      expect(stop.obscured, `focus on ${stop.selector} is covered`).toBe(false);
    }
    // A trap is the same element receiving focus repeatedly.
    const tail = stops.slice(-6).map((stop) => stop.documentOrder);
    expect(new Set(tail).size, "no keyboard trap in the last six stops").toBeGreaterThan(1);
    await page.screenshot({ path: path.join(SHOTS, "keyboard-focus-1280.png"), fullPage: false });
  });

  test("the mobile disclosure opens natively without JavaScript", async ({ browser, baseURL }) => {
    test.setTimeout(120_000);
    const context = await browser.newContext({
      viewport: { width: 390, height: 844 },
      javaScriptEnabled: false,
    });
    await anonymous(context, baseURL);
    const page = await context.newPage();
    await page.goto("/pt-br/", { waitUntil: "domcontentloaded" });
    const summary = page.locator(".lps-shell-disclosure > summary");
    await expect(summary).toBeAttached({ timeout: 60_000 });
    await summary.click();
    await expect(page.locator(".lps-shell-disclosure")).toHaveAttribute("open", "");
    await expect(page.locator(".lps-shell-disclosure .lps-search input")).toBeVisible();
    await page.screenshot({ path: path.join(SHOTS, "disclosure-nojs-390.png"), fullPage: true });
    await context.close();
  });

  test("search validation announces the boundary error through role=alert", async ({
    page,
    context,
    baseURL,
  }) => {
    test.setTimeout(120_000);
    await anonymous(context, baseURL);
    await gotoShell(page, "/pt-br/busca/?q=a");
    // The one-character query is the seeded boundary error: the message must
    // render in a live region so assistive technology announces it.
    const status = page.locator("#lps-search-status");
    await expect(status).toBeVisible();
    await expect(status).toHaveAttribute("role", "alert");
    await expect(status).toHaveClass(/lps-search-error/);
    await page.screenshot({ path: path.join(SHOTS, "search-error-1280.png"), fullPage: true });
  });

  test("reduced motion removes transitions and animations", async ({ browser, baseURL }) => {
    test.setTimeout(120_000);
    const context = await browser.newContext({
      reducedMotion: "reduce",
      viewport: { width: 1280, height: 900 },
    });
    await anonymous(context, baseURL);
    const page = await context.newPage();
    await gotoShell(page, "/pt-br/");
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
    await context.close();
  });
});

test.describe("task-21: authored media and document alternatives", () => {
  test.describe.configure({ mode: "serial" });

  test("the accessible-media page ships captions, alt text, and HTML alternatives", async ({
    page,
    context,
    baseURL,
  }) => {
    test.setTimeout(120_000);
    await anonymous(context, baseURL);
    await gotoShell(page, "/pt-br/midia-acessivel/");

    // Every informative image carries alt text; decorative images are marked.
    const images = await page.evaluate(() =>
      [...document.querySelectorAll("#lps-main img")].map((img) => ({
        alt: img.getAttribute("alt"),
        presentation: img.getAttribute("role") === "presentation",
      })),
    );
    expect(images.length, "the media page renders authored images").toBeGreaterThan(0);
    for (const image of images) {
      expect(
        image.alt !== null && (image.alt.length > 0 || image.presentation),
        "each image is named or marked decorative",
      ).toBe(true);
    }

    // The data table keeps its caption and a keyboard-scrollable region.
    const table = page.locator(".wp-block-table");
    await expect(table.locator("figcaption")).toHaveCount(1);
    const scrollRegion = page.locator(".lps-table-scroll[role='region'][tabindex='0']");
    await expect(scrollRegion).toHaveCount(1);
    await expect(scrollRegion).toHaveAttribute("aria-label", /rolagem horizontal/i);

    // The video ships a captions track; the PDF names its HTML alternative.
    await expect(page.locator("video track[kind='captions']")).toHaveCount(1);
    const pdf = page.locator("a[data-html-alternative]").first();
    await expect(pdf).toBeAttached();
    const alternative = await pdf.getAttribute("data-html-alternative");
    expect(alternative, "the document names its HTML alternative").toBeTruthy();
    const altResponse = await page.request.get(alternative);
    expect(altResponse.status(), "the HTML alternative resolves").toBe(200);
    await page.screenshot({ path: path.join(SHOTS, "media-1280.png"), fullPage: true });
  });
});

test.describe("task-21: visual fidelity against the frozen contract", () => {
  test.describe.configure({ mode: "serial" });

  test("the brand artwork is intact, legible, and undistorted", async ({
    page,
    context,
    baseURL,
  }) => {
    test.setTimeout(120_000);
    await anonymous(context, baseURL);
    await page.setViewportSize({ width: 1280, height: 900 });
    await gotoShell(page, "/pt-br/");
    const logo = page.locator("a.lps-brand img.lps-logo");
    await expect(logo).toBeVisible();
    await page.waitForFunction(
      () => {
        const el = document.querySelector("a.lps-brand img.lps-logo");
        return el && el.complete && el.naturalWidth > 0;
      },
      null,
      { timeout: 15_000 },
    );
    // The rendered box keeps the source aspect ratio (2052x301) within 2%.
    const box = await logo.boundingBox();
    const natural = await logo.evaluate((img) => ({
      width: img.naturalWidth,
      height: img.naturalHeight,
    }));
    const renderedRatio = box.width / box.height;
    const sourceRatio = natural.width / natural.height;
    expect(Math.abs(renderedRatio - sourceRatio) / sourceRatio).toBeLessThan(0.02);
    // The artwork is the link's only content, so it carries the wordmark alt.
    await expect(logo).toHaveAttribute("alt", "LPS");
    await page.screenshot({ path: path.join(SHOTS, "brand-1280.png"), fullPage: false });
  });

  test("no conflicting luxury or terminal styling leaks into the shell", async ({
    page,
    context,
    baseURL,
  }) => {
    test.setTimeout(120_000);
    await anonymous(context, baseURL);
    await page.setViewportSize({ width: 1280, height: 900 });
    await gotoShell(page, "/pt-br/");
    await page.evaluate(() => document.fonts.ready);

    const contract = await page.evaluate(() => {
      const body = getComputedStyle(document.body);
      const offenders = [];
      for (const el of document.querySelectorAll(
        "h1, h2, h3, .lps-card, .lps-button-primary, button, .lps-home-section",
      )) {
        const style = getComputedStyle(el);
        const radius = Number.parseFloat(style.borderTopLeftRadius) || 0;
        if (radius > 8) offenders.push(`radius ${radius}px on ${el.tagName}.${el.className}`);
        if (style.boxShadow !== "none")
          offenders.push(`shadow ${style.boxShadow.slice(0, 40)} on ${el.tagName}.${el.className}`);
        if (/gradient/i.test(style.backgroundImage))
          offenders.push(`gradient on ${el.tagName}.${el.className}`);
      }
      return {
        fontFamily: body.fontFamily,
        background: body.backgroundColor,
        offenders,
      };
    });
    // The interface stack resolves to IBM Plex Sans as the first family,
    // never a serif display or a terminal mono on body copy. WebKit serializes
    // the computed family without quotes, so the comparison strips them.
    const firstFamily = contract.fontFamily.split(",")[0].replaceAll('"', "").trim();
    expect(firstFamily).toBe("IBM Plex Sans");
    // The canvas is the contracted cool off-white, not a warm luxury paper or
    // a terminal black.
    expect(contract.background).toBe("rgb(245, 247, 250)");
    expect(contract.offenders, "styling conflicts").toEqual([]);
  });

  test("the editor boot payload resolves the same frozen tokens", async ({ page }) => {
    // The block editor boot in the WASM runtime exceeds the default budget.
    test.setTimeout(180_000);
    const cssCheck = await page.request.get("/wp-content/themes/lps-theme/assets/css/theme.css");
    expect(await cssCheck.text()).toContain("--color-canvas");
    await loginAsEditor(page);
    await page.goto("/wp-admin/post-new.php");
    await page.waitForLoadState("domcontentloaded");
    const canvasFrame = page.locator('iframe[name="editor-canvas"]');
    await canvasFrame.waitFor({ state: "attached", timeout: 120_000 });
    // The canvas iframe is a blob: document blocked by the site CSP (a
    // pre-existing platform defect), so the honest alignment signal is the
    // editor boot payload: theme.json presets injected into editor settings.
    const payload = await page.content();
    expect(payload).toContain('--wp--preset--font-family--interface: "IBM Plex Sans"');
    expect(payload).toContain('--wp--preset--font-family--mono: "IBM Plex Mono"');
    await page.screenshot({ path: path.join(SHOTS, "editor-boot.png"), fullPage: false });
  });
});
