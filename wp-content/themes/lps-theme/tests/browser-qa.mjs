import { createHash } from "node:crypto";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import AxeBuilder from "@axe-core/playwright";
import { chromium } from "playwright";

const baseURL = process.env.LPS_BASE_URL ?? "http://localhost:8888";
const evidenceRoot = ".omo/evidence/task-13/browser";
const directories = ["screenshots", "accessibility", "html"];
for (const directory of directories)
  await mkdir(`${evidenceRoot}/${directory}`, { recursive: true });

const browser = await chromium.launch({ executablePath: "/usr/bin/chromium", headless: true });
const actions = [];
const captures = [];
const network = [];
const consoleMessages = [];
const origin = new URL(baseURL).origin;

async function anonymousContext(options = {}) {
  const context = await browser.newContext(options);
  await context.addCookies([
    { name: "playground_auto_login_already_happened", value: "1", url: `${origin}/` },
    { name: "playground_auto_login_already_logged_out", value: "1", url: `${origin}/` },
  ]);
  return context;
}

function observe(page, id) {
  page.on("console", (message) =>
    consoleMessages.push({ state: id, type: message.type(), text: message.text() }),
  );
  page.on("request", (request) =>
    network.push({
      state: id,
      method: request.method(),
      resourceType: request.resourceType(),
      url: request.url(),
    }),
  );
}

async function ready(page) {
  await page.waitForLoadState("load");
  await page.evaluate(() => document.fonts.ready);
}

async function pageMetrics(page) {
  return page.evaluate(() => {
    const visible = (selector) => {
      const node = document.querySelector(selector);
      return Boolean(
        node?.getClientRects().length && getComputedStyle(node).visibility !== "hidden",
      );
    };
    const landmarkNames = [
      ...document.querySelectorAll("header,nav,main,footer,[role=search]"),
    ].map((node) => ({ tag: node.tagName.toLowerCase(), label: node.getAttribute("aria-label") }));
    return {
      documentWidth: document.documentElement.scrollWidth,
      viewportWidth: document.documentElement.clientWidth,
      bodyWidth: document.body.scrollWidth,
      lang: document.documentElement.lang,
      title: document.title,
      bodyClasses: document.body.className.split(/\s+/).filter(Boolean),
      headings: [...document.querySelectorAll("h1,h2,h3,h4")].map((heading) => ({
        level: heading.tagName,
        text: heading.textContent?.trim(),
      })),
      landmarkNames,
      h1Count: document.querySelectorAll("h1").length,
      detailsOpen: document.querySelector(".lps-shell-disclosure")?.hasAttribute("open") ?? false,
      visibleControls: {
        skip: visible(".lps-skip-link"),
        primaryNavigation: visible(".lps-primary-nav a"),
        search: visible(".lps-search input") && visible(".lps-search button"),
        locale: visible(".lps-locale a, .lps-locale span"),
        callToAction: visible(".lps-button-primary"),
        footer: visible(".lps-site-footer"),
      },
      toolbar:
        Boolean(document.querySelector("#wpadminbar")) ||
        document.body.classList.contains("admin-bar"),
      proprietaryAssets: [...document.images]
        .map((image) => image.currentSrc || image.src)
        .filter((url) => /gravatar|ufrj|coppe|logo/i.test(url)),
      externalRuntimeAssets: [
        ...document.images,
        ...document.scripts,
        ...document.querySelectorAll("link[rel=stylesheet]"),
      ]
        .map((node) => node.currentSrc || node.src || node.href)
        .filter((url) => url && new URL(url, location.href).origin !== location.origin),
    };
  });
}

async function capture(state) {
  const context = await anonymousContext({
    viewport: { width: state.width, height: state.height },
    javaScriptEnabled: state.javaScriptEnabled ?? true,
    reducedMotion: state.reducedMotion,
  });
  const page = await context.newPage();
  observe(page, state.id);
  const response = await page.goto(`${baseURL}${state.path}`, { waitUntil: "domcontentloaded" });
  const responseHTML = await response?.text();
  await ready(page);
  if (state.zoom) await page.evaluate(() => (document.documentElement.style.zoom = "2"));

  // The disclosure ships closed so the mobile header stays compact; opening it
  // is a native toggle that needs no JavaScript, so the no-JS capture performs
  // it before measuring the reachable controls.
  if (state.openDisclosure) {
    await page.locator(".lps-shell-disclosure > summary").click();
  }
  const metrics = await pageMetrics(page);
  const accessibility = await page.locator("body").ariaSnapshot();
  const axe = state.javaScriptEnabled === false ? null : await new AxeBuilder({ page }).analyze();
  const serious =
    axe?.violations.filter(({ impact }) => impact === "serious" || impact === "critical") ?? [];
  const screenshotPath = `${evidenceRoot}/screenshots/${state.id}.png`;
  await page.screenshot({ path: screenshotPath, fullPage: true });
  await writeFile(`${evidenceRoot}/accessibility/${state.id}.yaml`, accessibility);
  await writeFile(`${evidenceRoot}/html/${state.id}.html`, await page.content());
  if (state.javaScriptEnabled === false && responseHTML) {
    await writeFile(`${evidenceRoot}/html/${state.id}-response.html`, responseHTML);
  }

  const result = {
    ...state,
    status: response?.status(),
    finalURL: page.url(),
    metrics,
    accessibilityProof: {
      navigation: /navigation/i.test(accessibility),
      search: /search|buscar/i.test(accessibility),
      footer: /contentinfo|institutional|institucionais/i.test(accessibility),
    },
    axe: axe
      ? { violations: axe.violations.length, seriousCritical: serious.length, serious }
      : {
          skipped: true,
          reason: "JavaScript-disabled behavior context; DOM/AX/HTML/keyboard proof used.",
        },
    screenshotPath,
  };
  captures.push(result);
  await page.close();
  await context.close();
}

const standardStates = [
  { locale: "pt-br", path: "/pt-br/", expectedLang: "pt-BR" },
  { locale: "en", path: "/en/", expectedLang: "en" },
];
for (const state of standardStates) {
  for (const viewport of [
    { name: "mobile", width: 375, height: 812 },
    { name: "tablet", width: 768, height: 1024 },
    { name: "desktop", width: 1280, height: 800 },
  ]) {
    await capture({
      id: `${state.locale}-${viewport.name}`,
      locale: state.locale,
      path: state.path,
      viewport: `${viewport.width}x${viewport.height}`,
      width: viewport.width,
      height: viewport.height,
      expectedLang: state.expectedLang,
      mode: "standard",
    });
  }
}

for (const state of [
  {
    id: "pt-empty-search",
    locale: "pt-br",
    path: "/pt-br/?s=resultado-cientifico-inexistente",
    viewport: "375x812",
    width: 375,
    height: 812,
    mode: "empty-search",
  },
  {
    id: "en-empty-search",
    locale: "en",
    path: "/en/?s=nonexistent-scientific-output",
    viewport: "768x1024",
    width: 768,
    height: 1024,
    mode: "empty-search",
  },
  {
    id: "pt-missing-translation",
    locale: "pt-br",
    path: "/pt-br/?p=1",
    viewport: "1280x800",
    width: 1280,
    height: 800,
    mode: "missing-translation",
  },
  {
    id: "pt-zoom-long-label",
    locale: "pt-br",
    path: "/pt-br/?s=Infraestrutura+experimental+multidisciplinar+para+caracterizacao+processamento+validacao+e+preservacao",
    viewport: "640x900@200%",
    width: 640,
    height: 900,
    zoom: true,
    mode: "zoom-long-label",
  },
  {
    id: "pt-no-js",
    locale: "pt-br",
    path: "/pt-br/",
    viewport: "375x812",
    width: 375,
    height: 812,
    javaScriptEnabled: false,
    openDisclosure: true,
    mode: "no-js",
  },
  {
    id: "pt-reduced-motion",
    locale: "pt-br",
    path: "/pt-br/",
    viewport: "375x812",
    width: 375,
    height: 812,
    reducedMotion: "reduce",
    mode: "reduced-motion",
  },
  {
    id: "en-404",
    locale: "en",
    path: "/en/not-found-shell/",
    viewport: "1280x800",
    width: 1280,
    height: 800,
    mode: "404",
  },
])
  await capture(state);

const actionContext = await anonymousContext({
  viewport: { width: 375, height: 812 },
  javaScriptEnabled: false,
});
const actionPage = await actionContext.newPage();
observe(actionPage, "global-actions");
await actionPage.goto(`${baseURL}/pt-br/`, { waitUntil: "domcontentloaded" });
await ready(actionPage);
const summary = actionPage.locator(".lps-shell-disclosure > summary");
// The disclosure ships closed so the mobile header stays compact; one
// activation must open it natively, without JavaScript.
await summary.click();
actions.push({
  control: "native menu disclosure",
  method: "mouse/no-JS",
  passed: await actionPage
    .locator(".lps-shell-disclosure")
    .evaluate((node) => node.hasAttribute("open")),
});
await summary.click();
await summary.focus();
await summary.press("Enter");
actions.push({
  control: "native menu disclosure",
  method: "keyboard/no-JS",
  passed: await actionPage
    .locator(".lps-shell-disclosure")
    .evaluate((node) => node.hasAttribute("open")),
});

const globalControls = actionPage.locator(
  ".lps-masthead .lps-wordmark, .lps-primary-nav a, .lps-search input, .lps-search button, .lps-locale a, .lps-button-primary, .lps-site-footer a",
);
const keyboardFocus = [];
for (let index = 0; index < (await globalControls.count()); index += 1) {
  const control = globalControls.nth(index);
  await control.focus();
  keyboardFocus.push(
    await control.evaluate((node) => ({
      name: node.textContent?.trim() || node.getAttribute("name"),
      href: node.getAttribute("href"),
      outlineStyle: getComputedStyle(node).outlineStyle,
      outlineWidth: getComputedStyle(node).outlineWidth,
      visible: Boolean(node.getClientRects().length),
    })),
  );
}
actions.push({
  control: "all global links/tools",
  method: "keyboard/no-JS",
  passed: keyboardFocus.every(
    ({ outlineStyle, outlineWidth, visible }) =>
      visible && outlineStyle !== "none" && Number.parseFloat(outlineWidth) >= 3,
  ),
  states: keyboardFocus,
});
const skip = actionPage.locator(".lps-skip-link");
await skip.focus();
await actionPage.screenshot({
  path: `${evidenceRoot}/screenshots/pt-keyboard-focus.png`,
  fullPage: true,
});
await skip.press("Enter");
actions.push({
  control: "skip link",
  method: "keyboard/no-JS",
  passed: actionPage.url().endsWith("#lps-main"),
});
await actionPage.close();
await actionContext.close();

for (const method of ["mouse", "keyboard"]) {
  const context = await anonymousContext({
    viewport: { width: 375, height: 812 },
    javaScriptEnabled: false,
  });
  const page = await context.newPage();
  observe(page, `search-${method}`);
  await page.goto(`${baseURL}/pt-br/`, { waitUntil: "domcontentloaded" });
  const input = page.locator("#lps-search-input");
  await input.fill(`controle global ${method} sem resultado`);
  const navigation = page.waitForURL(/\?s=controle\+global\+(?:mouse|keyboard)\+sem\+resultado$/);
  if (method === "mouse")
    await Promise.all([navigation, page.locator(".lps-search button").click()]);
  else await Promise.all([navigation, input.press("Enter")]);
  actions.push({
    control: "search",
    method: `${method}/no-JS`,
    passed: true,
    finalURL: page.url(),
  });
  await page.close();
  await context.close();
}

const hoverContext = await anonymousContext({ viewport: { width: 1280, height: 800 } });
const hoverPage = await hoverContext.newPage();
observe(hoverPage, "mouse-hover");
await hoverPage.goto(`${baseURL}/pt-br/`, { waitUntil: "domcontentloaded" });
await ready(hoverPage);
const hoverTarget = hoverPage.locator(".lps-primary-nav a").first();
await hoverTarget.hover();
await hoverPage.screenshot({
  path: `${evidenceRoot}/screenshots/pt-mouse-hover.png`,
  fullPage: true,
});
const expectedHoverURL = new URL(await hoverTarget.getAttribute("href"), baseURL);
await Promise.all([
  hoverPage.waitForURL((url) => url.pathname === expectedHoverURL.pathname),
  hoverTarget.click(),
]);
actions.push({
  control: "primary navigation",
  method: "mouse",
  passed: hoverPage.url() === expectedHoverURL.href,
  finalURL: hoverPage.url(),
});
await hoverPage.close();
await hoverContext.close();

const printContext = await anonymousContext({ viewport: { width: 1280, height: 800 } });
const printPage = await printContext.newPage();
observe(printPage, "pt-print");
const printResponse = await printPage.goto(`${baseURL}/pt-br/`, { waitUntil: "domcontentloaded" });
await ready(printPage);
await printPage.emulateMedia({ media: "print" });
await printPage.pdf({
  path: `${evidenceRoot}/print-shell.pdf`,
  format: "A4",
  printBackground: true,
});
await printPage.screenshot({ path: `${evidenceRoot}/screenshots/pt-print.png`, fullPage: true });
await writeFile(
  `${evidenceRoot}/accessibility/pt-print.yaml`,
  await printPage.locator("body").ariaSnapshot(),
);
await writeFile(`${evidenceRoot}/html/pt-print.html`, await printPage.content());
const printVisibility = await printPage.evaluate(() => ({
  disclosure: getComputedStyle(document.querySelector(".lps-shell-disclosure")).display,
  breadcrumbs: document.querySelector(".lps-breadcrumbs")
    ? getComputedStyle(document.querySelector(".lps-breadcrumbs")).display
    : "absent",
  footer: getComputedStyle(document.querySelector(".lps-site-footer")).display,
  main: getComputedStyle(document.querySelector("#lps-main")).display,
  toolbar: Boolean(document.querySelector("#wpadminbar")),
}));
captures.push({
  id: "pt-print",
  locale: "pt-br",
  mode: "print",
  path: "/pt-br/",
  viewport: "1280x800/A4",
  width: 1280,
  height: 800,
  status: printResponse?.status(),
  finalURL: printPage.url(),
  metrics: await pageMetrics(printPage),
  printVisibility,
  axe: {
    skipped: true,
    reason: "Dedicated Chromium print-media/PDF proof; screen twin is axe-tested.",
  },
  screenshotPath: `${evidenceRoot}/screenshots/pt-print.png`,
  pdfPath: `${evidenceRoot}/print-shell.pdf`,
});
await printPage.close();
await printContext.close();
await browser.close();

const externalRequests = network.filter(({ url }) => {
  const parsed = new URL(url);
  return (parsed.protocol === "http:" || parsed.protocol === "https:") && parsed.origin !== origin;
});
const errorConsole = consoleMessages.filter(
  ({ state, type, text }) =>
    type === "error" &&
    !(
      ["en-404", "mouse-hover"].includes(state) &&
      text.includes("server responded with a status of 404")
    ),
);
const jsEnabledCaptures = captures.filter(({ mode }) => mode !== "no-js" && mode !== "print");
const noJs = captures.find(({ mode }) => mode === "no-js");
const emptyEnglish = captures.find(({ id }) => id === "en-empty-search");
const report = {
  generatedAt: new Date().toISOString(),
  browser: "System Chromium via Playwright executablePath=/usr/bin/chromium",
  captures,
  actions,
  outcomes: {
    captureCount: captures.length,
    screenshotCount: captures.length + 2,
    seriousCriticalAxe: jsEnabledCaptures.reduce(
      (sum, capture) => sum + (capture.axe.seriousCritical ?? 0),
      0,
    ),
    overflowFailures: captures
      .filter(
        ({ metrics }) =>
          metrics.documentWidth > metrics.viewportWidth ||
          metrics.bodyWidth > metrics.viewportWidth,
      )
      .map(({ id }) => id),
    languageFailures: captures
      .filter(({ expectedLang, metrics }) => expectedLang && metrics.lang !== expectedLang)
      .map(({ id }) => id),
    headingFailures: captures
      .filter(({ mode, metrics }) => mode === "404" && metrics.h1Count !== 1)
      .map(({ id }) => id),
    externalRequests,
    consoleErrors: errorConsole,
    toolbarFailures: captures.filter(({ metrics }) => metrics.toolbar).map(({ id }) => id),
    proprietaryAssetFailures: captures
      .filter(({ metrics }) => metrics.proprietaryAssets.length > 0)
      .map(({ id }) => id),
    actionFailures: actions.filter(({ passed }) => !passed),
    desktopGlobalControlsVisible: captures
      .filter(({ viewport }) => viewport === "1280x800")
      .every(({ metrics }) => Object.values(metrics.visibleControls).every(Boolean)),
    serverRenderedNoJs:
      Boolean(noJs?.metrics.detailsOpen) &&
      Boolean(noJs?.accessibilityProof.navigation) &&
      Boolean(noJs?.accessibilityProof.search) &&
      Boolean(noJs?.accessibilityProof.footer),
    englishEmptySearch:
      emptyEnglish?.status === 200 &&
      emptyEnglish?.metrics.lang === "en" &&
      emptyEnglish?.metrics.headings.some(({ text }) => text === "Nothing matched this view"),
    printProof:
      printVisibility.main !== "none" &&
      printVisibility.disclosure === "none" &&
      printVisibility.footer === "none" &&
      !printVisibility.toolbar,
  },
};
await writeFile(`${evidenceRoot}/report.json`, `${JSON.stringify(report, null, 2)}\n`);
await writeFile(`${evidenceRoot}/action-log.json`, `${JSON.stringify(actions, null, 2)}\n`);
await writeFile(`${evidenceRoot}/network.json`, `${JSON.stringify(network, null, 2)}\n`);
await writeFile(`${evidenceRoot}/console.json`, `${JSON.stringify(consoleMessages, null, 2)}\n`);
const reportBytes = await readFile(`${evidenceRoot}/report.json`);
await writeFile(
  `${evidenceRoot}/report.sha256`,
  `${createHash("sha256").update(reportBytes).digest("hex")}  report.json\n`,
);

const failed = Object.entries(report.outcomes).filter(([key, value]) => {
  if (Array.isArray(value)) return value.length > 0;
  if (typeof value === "boolean") return !value;
  if (key === "seriousCriticalAxe") return value > 0;
  return false;
});
process.stdout.write(`${JSON.stringify(report.outcomes, null, 2)}\n`);
if (failed.length > 0) process.exitCode = 1;
