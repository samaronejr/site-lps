import { mkdir, writeFile } from "node:fs/promises";
import AxeBuilder from "@axe-core/playwright";
import { chromium } from "playwright";

const baseUrl = process.env.SHOWCASE_URL ?? "http://127.0.0.1:4176/";
const evidenceRoot = ".omo/evidence/lps-feec-redesign/showcase";
const viewports = [
  { name: "mobile-375", width: 375, height: 812 },
  { name: "tablet-768", width: 768, height: 1024 },
  { name: "desktop-1280", width: 1280, height: 800 },
];
await Promise.all([
  mkdir(`${evidenceRoot}/browser`, { recursive: true }),
  mkdir(`${evidenceRoot}/a11y`, { recursive: true }),
  mkdir(`${evidenceRoot}/states`, { recursive: true }),
]);

const browser = await chromium.launch({ headless: true });
const actions = [];
const summary = [];
let failed = false;

async function captureAccessibility(context, page, name) {
  const session = await context.newCDPSession(page);
  const tree = await session.send("Accessibility.getFullAXTree");
  await writeFile(`${evidenceRoot}/a11y/${name}.json`, `${JSON.stringify(tree, null, 2)}\n`);
  await session.detach();
  return tree.nodes.length;
}

async function armTransition(page, selector) {
  await page.evaluate((targetSelector) => {
    const target = document.querySelector(targetSelector);
    window.__lpsTransitionDone = target
      ? new Promise((resolve) => target.addEventListener("transitionend", resolve, { once: true }))
      : Promise.resolve();
  }, selector);
}

async function awaitTransition(page) {
  await page.evaluate(() => window.__lpsTransitionDone);
}

try {
  for (const viewport of viewports) {
    const context = await browser.newContext({
      viewport: { width: viewport.width, height: viewport.height },
      reducedMotion: "no-preference",
    });
    const page = await context.newPage();
    const consoleErrors = [];
    const externalRequests = [];
    page.on("console", (message) => {
      if (message.type() === "error") consoleErrors.push(message.text());
    });
    page.on("request", (request) => {
      const url = new URL(request.url());
      if (url.origin !== new URL(baseUrl).origin) externalRequests.push(request.url());
    });

    const response = await page.goto(baseUrl, { waitUntil: "networkidle" });
    actions.push({
      action: "goto",
      viewport: viewport.name,
      status: response?.status(),
      url: page.url(),
    });
    await page.locator("body").evaluate((body) => body.getBoundingClientRect());

    const metrics = await page.evaluate(() => {
      const root = document.documentElement;
      const clipped = [...document.querySelectorAll("body *")]
        .filter((element) => {
          if (element.closest(".table-scroll")) return false;
          const rect = element.getBoundingClientRect();
          const style = getComputedStyle(element);
          return style.position !== "fixed" && (rect.left < -1 || rect.right > innerWidth + 1);
        })
        .slice(0, 20)
        .map((element) => ({
          tag: element.tagName,
          className: element.className,
          text: element.textContent?.trim().slice(0, 80),
        }));
      return {
        clientWidth: root.clientWidth,
        scrollWidth: root.scrollWidth,
        pageOverflow: root.scrollWidth - root.clientWidth,
        clipped,
        sourceSerifLoaded: document.fonts.check('16px "Source Serif 4"'),
        cjkBlocks: document.querySelectorAll('[lang="zh-Hans"], [lang="ja"], [lang="ko"]').length,
      };
    });

    const axe = await new AxeBuilder({ page }).analyze();
    const axNodeCount = await captureAccessibility(context, page, viewport.name);
    await page.screenshot({ path: `${evidenceRoot}/browser/${viewport.name}.png`, fullPage: true });
    actions.push({
      action: "capture",
      viewport: viewport.name,
      screenshot: `${evidenceRoot}/browser/${viewport.name}.png`,
      accessibilityTree: `${evidenceRoot}/a11y/${viewport.name}.json`,
    });

    const record = {
      viewport,
      status: response?.status(),
      metrics,
      axeViolations: axe.violations.map(({ id, impact, help, nodes }) => ({
        id,
        impact,
        help,
        nodeCount: nodes.length,
      })),
      axNodeCount,
      consoleErrors,
      externalRequests,
    };
    summary.push(record);
    if (
      response?.status() !== 200 ||
      metrics.pageOverflow !== 0 ||
      metrics.clipped.length ||
      !metrics.sourceSerifLoaded ||
      axe.violations.length ||
      consoleErrors.length ||
      externalRequests.length
    )
      failed = true;

    if (viewport.width === 1280) {
      const button = page.locator("button.button-primary").first();
      await page.screenshot({ path: `${evidenceRoot}/states/rest-1280.png`, fullPage: false });
      await armTransition(page, "button.button-primary");
      await button.hover();
      await awaitTransition(page);
      const hover = await button.evaluate((element) => {
        const style = getComputedStyle(element);
        return {
          background: style.backgroundColor,
          color: style.color,
          transform: style.transform,
        };
      });
      await page.screenshot({ path: `${evidenceRoot}/states/hover-1280.png`, fullPage: false });
      await button.focus();
      const focus = await button.evaluate((element) => {
        const style = getComputedStyle(element);
        return { outline: style.outline, outlineOffset: style.outlineOffset };
      });
      await page.screenshot({ path: `${evidenceRoot}/states/focus-1280.png`, fullPage: false });
      const box = await button.boundingBox();
      if (!box) throw new Error("Primary button has no bounding box");
      await armTransition(page, "button.button-primary");
      await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
      await page.mouse.down();
      await awaitTransition(page);
      const pressed = await button.evaluate((element) => ({
        transform: getComputedStyle(element).transform,
      }));
      await page.screenshot({ path: `${evidenceRoot}/states/pressed-1280.png`, fullPage: false });
      await page.mouse.up();
      await writeFile(
        `${evidenceRoot}/states/interactive-computed.json`,
        `${JSON.stringify({ hover, focus, pressed }, null, 2)}\n`,
      );
      actions.push({
        action: "states",
        viewport: viewport.name,
        states: ["rest", "hover", "focus", "pressed"],
      });
    }
    await context.close();
  }

  const reducedContext = await browser.newContext({
    viewport: { width: 1280, height: 800 },
    reducedMotion: "reduce",
  });
  const reducedPage = await reducedContext.newPage();
  await reducedPage.goto(baseUrl, { waitUntil: "networkidle" });
  const reduced = await reducedPage
    .locator("button.button-primary")
    .first()
    .evaluate((element) => {
      element.dispatchEvent(new MouseEvent("mousedown", { bubbles: true }));
      const style = getComputedStyle(element);
      return {
        mediaMatches: matchMedia("(prefers-reduced-motion: reduce)").matches,
        transitionDuration: style.transitionDuration,
        transform: style.transform,
        animationDuration: style.animationDuration,
      };
    });
  await reducedPage.screenshot({
    path: `${evidenceRoot}/states/reduced-motion-1280.png`,
    fullPage: false,
  });
  await writeFile(
    `${evidenceRoot}/states/reduced-motion-computed.json`,
    `${JSON.stringify(reduced, null, 2)}\n`,
  );
  actions.push({ action: "reduced-motion", viewport: "desktop-1280", ...reduced });
  if (!reduced.mediaMatches || reduced.transform !== "none") failed = true;
  await reducedContext.close();
} finally {
  await browser.close();
  actions.push({ action: "browser-close" });
  await writeFile(
    `${evidenceRoot}/browser/browser-actions.json`,
    `${JSON.stringify(actions, null, 2)}\n`,
  );
  await writeFile(
    `${evidenceRoot}/browser/verification.json`,
    `${JSON.stringify({ status: failed ? "failed" : "passed", viewports: summary }, null, 2)}\n`,
  );
}

if (failed) {
  process.stderr.write("Showcase browser verification failed; inspect verification.json.\n");
  process.exitCode = 1;
} else {
  process.stdout.write(
    `Showcase browser verification passed at ${viewports.map(({ width }) => width).join(", ")}px.\n`,
  );
}
