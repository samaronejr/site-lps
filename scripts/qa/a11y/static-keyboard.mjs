/**
 * Agent-operated keyboard, focus, and reduced-motion checks over the statically
 * rendered surfaces. No port is required: the documents are opened from disk in a
 * real Chromium, and every interaction is a keyboard event.
 */

import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { pathToFileURL } from "node:url";
import { chromium } from "@playwright/test";

const MAX_TABS = 60;

function option(argv, name, fallback) {
  const index = argv.indexOf(`--${name}`);
  return index === -1 ? fallback : argv[index + 1];
}

/** Walks the focus order of one document and records focus quality at every stop. */
async function walkFocusOrder(page) {
  const stops = [];
  for (let step = 0; step < MAX_TABS; step += 1) {
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
          : `${element.tagName.toLowerCase()}${element.className ? `.${element.className.toString().trim().split(/\s+/)[0]}` : ""}`,
        name: (element.getAttribute("aria-label") ?? element.textContent ?? "").trim().slice(0, 60),
        outlineWidth: Number.parseFloat(style.outlineWidth) || 0,
        outlineStyle: style.outlineStyle,
        width: Math.round(rect.width),
        height: Math.round(rect.height),
        inViewport: rect.top >= 0 && rect.bottom <= window.innerHeight,
        obscured: !(
          centre === element ||
          element.contains(centre) ||
          element.contains(centre?.parentElement ?? null)
        ),
        documentOrder: [...document.querySelectorAll("*")].indexOf(element),
      };
    });
    if (stop === null) break;
    stops.push(stop);
  }
  return stops;
}

/** Runs the static keyboard and motion checks. */
export async function runStaticKeyboard({ sourceDir, outputDir }) {
  await mkdir(outputDir, { recursive: true });
  const manifest = JSON.parse(await readFile(path.join(sourceDir, "manifest.json"), "utf8"));
  const browser = await chromium.launch();
  const documents = [];
  const violations = [];
  try {
    for (const document of manifest.documents) {
      const url = pathToFileURL(path.resolve(sourceDir, document.file)).toString();
      const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
      const page = await context.newPage();
      await page.goto(url, { waitUntil: "load" });
      const stops = await walkFocusOrder(page);
      // A trap is the same element receiving focus repeatedly, identified by its
      // position in the document rather than by a shared generic selector.
      const trapped =
        stops.length >= 6 && new Set(stops.slice(-6).map((stop) => stop.documentOrder)).size === 1;
      const invisible = stops.filter(
        (stop) => stop.outlineWidth < 2 || stop.outlineStyle === "none",
      );
      const obscured = stops.filter((stop) => stop.obscured);
      const outOfOrder = stops.filter(
        (stop, index) => index > 0 && stop.documentOrder < stops[index - 1].documentOrder,
      );
      if (trapped)
        violations.push({
          document: document.id,
          code: "keyboard_trap",
          detail: stops.at(-1)?.selector,
        });
      for (const stop of invisible)
        violations.push({
          document: document.id,
          code: "focus_not_visible",
          detail: stop.selector,
        });
      for (const stop of obscured)
        violations.push({ document: document.id, code: "focus_obscured", detail: stop.selector });
      for (const stop of outOfOrder)
        violations.push({
          document: document.id,
          code: "focus_order_breaks_dom_order",
          detail: stop.selector,
        });
      documents.push({ id: document.id, stops: stops.length, focusOrder: stops });
      await context.close();
      process.stderr.write(`keyboard ${document.id}: ${stops.length} stops\n`);
    }

    const motionContext = await browser.newContext({
      reducedMotion: "reduce",
      viewport: { width: 1280, height: 900 },
    });
    const motionPage = await motionContext.newPage();
    await motionPage.goto(pathToFileURL(path.resolve(sourceDir, "shell-pt-br.html")).toString(), {
      waitUntil: "load",
    });
    const durations = await motionPage.evaluate(() =>
      [...document.querySelectorAll("a, button, summary")].map((element) => {
        const style = getComputedStyle(element);
        return {
          transition: Number.parseFloat(style.transitionDuration) || 0,
          animation: Number.parseFloat(style.animationDuration) || 0,
        };
      }),
    );
    const moving = durations.filter((entry) => entry.transition > 0.001 || entry.animation > 0.001);
    if (moving.length > 0)
      violations.push({
        document: "shell-pt-br",
        code: "motion_not_reduced",
        detail: `${moving.length} controls`,
      });
    await motionContext.close();

    const report = {
      schemaVersion: 1,
      ranAt: new Date().toISOString(),
      documents,
      reducedMotion: { controls: durations.length, stillAnimated: moving.length },
      violations,
    };
    await writeFile(
      path.join(outputDir, "static-keyboard-report.json"),
      `${JSON.stringify(report, null, 2)}\n`,
    );
    return report;
  } finally {
    await browser.close();
  }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const argv = process.argv.slice(2);
  const report = await runStaticKeyboard({
    sourceDir: option(argv, "source-dir", ".omo/evidence/task-23/static-surfaces"),
    outputDir: option(argv, "output-dir", ".omo/evidence/task-23/a11y"),
  });
  process.stdout.write(
    `${JSON.stringify(
      {
        documents: report.documents.length,
        focusStops: report.documents.reduce((total, document) => total + document.stops, 0),
        violations: report.violations,
        reducedMotion: report.reducedMotion,
      },
      null,
      2,
    )}\n`,
  );
  if (report.violations.length > 0) process.exitCode = 1;
}
