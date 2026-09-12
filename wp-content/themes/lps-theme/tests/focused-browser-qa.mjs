import { mkdir, writeFile } from "node:fs/promises";
import { chromium } from "playwright";

const baseURL = "http://127.0.0.1:8888";
const root = ".omo/evidence/task-13/browser";
await mkdir(`${root}/screenshots`, { recursive: true });
await mkdir(`${root}/accessibility`, { recursive: true });
await mkdir(`${root}/html`, { recursive: true });

const browser = await chromium.launch({ executablePath: "/usr/bin/chromium", headless: true });
const network = [];
const consoleMessages = [];
const actions = [];

try {
  const noJsContext = await browser.newContext({
    viewport: { width: 375, height: 812 },
    javaScriptEnabled: false,
  });
  const page = await noJsContext.newPage();
  page.on("request", (request) => network.push(request.url()));
  page.on("console", (message) =>
    consoleMessages.push({ type: message.type(), text: message.text() }),
  );
  const response = await page.goto(baseURL, { waitUntil: "load", timeout: 15_000 });
  const summary = page.locator(".lps-shell-disclosure > summary");
  await summary.click();
  actions.push({
    control: "native menu disclosure",
    method: "mouse",
    passed: await page
      .locator(".lps-shell-disclosure")
      .evaluate((node) => node.hasAttribute("open")),
  });
  await summary.focus();
  await summary.press("Enter");
  await summary.press("Enter");
  actions.push({
    control: "native menu disclosure",
    method: "keyboard",
    passed: await page
      .locator(".lps-shell-disclosure")
      .evaluate((node) => node.hasAttribute("open")),
  });

  const skip = page.locator(".lps-skip-link");
  await skip.focus();
  await skip.press("Enter");
  actions.push({
    control: "skip link",
    method: "keyboard",
    passed: page.url().endsWith("#lps-main"),
  });

  const controls = page.locator(
    ".lps-primary-nav a, .lps-locale a, .lps-button-primary, .lps-site-footer a, .lps-search input, .lps-search button",
  );
  const focusStates = [];
  for (let index = 0; index < (await controls.count()); index += 1) {
    const control = controls.nth(index);
    await control.focus();
    focusStates.push(
      await control.evaluate((node) => ({
        name:
          node.textContent?.trim() || node.getAttribute("aria-label") || node.getAttribute("name"),
        outlineStyle: getComputedStyle(node).outlineStyle,
        outlineWidth: getComputedStyle(node).outlineWidth,
      })),
    );
  }
  actions.push({
    control: "all global controls",
    method: "keyboard focus",
    passed: focusStates.every(
      ({ outlineStyle, outlineWidth }) =>
        outlineStyle !== "none" && Number.parseFloat(outlineWidth) >= 3,
    ),
    focusStates,
  });

  await page.locator("#lps-search-input").fill("busca sem javascript");
  await Promise.all([
    page.waitForNavigation({ waitUntil: "load", timeout: 15_000 }),
    page.locator(".lps-search button").click(),
  ]);
  actions.push({
    control: "search",
    method: "mouse/no-JS",
    passed: page.url().includes("s=busca+sem+javascript"),
    finalURL: page.url(),
  });

  const metrics = await page.evaluate(() => ({
    documentWidth: document.documentElement.scrollWidth,
    viewportWidth: document.documentElement.clientWidth,
    bodyWidth: document.body.scrollWidth,
    lang: document.documentElement.lang,
    landmarks: [...document.querySelectorAll("header,nav,main,footer,[role=search]")].map((node) =>
      node.tagName.toLowerCase(),
    ),
  }));
  await page.screenshot({ path: `${root}/screenshots/pt-no-js.png`, fullPage: true });
  await writeFile(`${root}/accessibility/pt-no-js.yaml`, await page.locator("body").ariaSnapshot());
  await writeFile(`${root}/html/pt-no-js.html`, await page.content());
  await noJsContext.close();

  const printContext = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const printPage = await printContext.newPage();
  const printResponse = await printPage.goto(baseURL, { waitUntil: "load", timeout: 15_000 });
  await printPage.emulateMedia({ media: "print" });
  await printPage.pdf({ path: `${root}/print-shell.pdf`, format: "A4", printBackground: true });
  await printPage.screenshot({ path: `${root}/screenshots/pt-print.png`, fullPage: true });
  const printVisibility = await printPage.evaluate(() => ({
    disclosure: getComputedStyle(document.querySelector(".lps-shell-disclosure")).display,
    footer: getComputedStyle(document.querySelector(".lps-site-footer")).display,
    main: getComputedStyle(document.querySelector("#lps-main")).display,
  }));
  await printContext.close();

  const origin = new URL(baseURL).origin;
  const report = {
    generatedAt: new Date().toISOString(),
    noJs: {
      status: response?.status(),
      metrics,
      accessibility: {
        axeSkipped: true,
        reason: "JavaScript-disabled behavior context; DOM/AX/HTML/keyboard proof used.",
      },
    },
    print: { status: printResponse?.status(), visibility: printVisibility },
    actions,
    externalRequests: network.filter((url) => new URL(url).origin !== origin),
    consoleErrors: consoleMessages.filter(({ type }) => type === "error"),
  };
  await writeFile(`${root}/focused-report.json`, `${JSON.stringify(report, null, 2)}\n`);
  await writeFile(`${root}/action-log.json`, `${JSON.stringify(actions, null, 2)}\n`);
  await writeFile(`${root}/focused-network.json`, `${JSON.stringify(network, null, 2)}\n`);
  await writeFile(`${root}/focused-console.json`, `${JSON.stringify(consoleMessages, null, 2)}\n`);

  const failed =
    metrics.documentWidth > metrics.viewportWidth ||
    metrics.bodyWidth > metrics.viewportWidth ||
    report.externalRequests.length > 0 ||
    report.consoleErrors.length > 0 ||
    actions.some(({ passed }) => !passed) ||
    printVisibility.main === "none" ||
    printVisibility.disclosure !== "none" ||
    printVisibility.footer !== "none";
  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
  if (failed) process.exitCode = 1;
} finally {
  await browser.close();
}
