import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdir, writeFile } from "node:fs/promises";
import { test } from "node:test";
import { chromium } from "playwright";
import { keyboardTo } from "./helpers/authored-media.mjs";

const base = process.env.LPS_QA_URL ?? "http://127.0.0.1:8902";
const out = ".omo/evidence/task-24j/pagination";
const baselineCss = execFileSync("git", [
  "show",
  "73dac5ccff03d5da15f31637a94fb3a8f508c6c2:wp-content/themes/lps-theme/assets/css/theme.css",
]);

test("Search pagination remains a keyboard-usable wrapping row", async () => {
  await mkdir(out, { recursive: true });
  const server = await chromium.launchServer({ executablePath: "/usr/bin/chromium" });
  console.log(JSON.stringify({ ownedBrowser: server.process().pid, tmp: process.env.TMPDIR }));
  const browser = await chromium.connect(server.wsEndpoint());
  try {
    for (const [width, height] of [
      [375, 812],
      [768, 1024],
      [1280, 800],
    ]) {
      const observed = [];
      for (const stage of ["baseline", "current"]) {
        const context = await browser.newContext({ viewport: { width, height } });
        try {
          await context.addCookies([
            { name: "playground_auto_login_already_happened", value: "1", url: base },
          ]);
          if (stage === "baseline") {
            await context.route("**/assets/css/theme.css?*", (route) =>
              route.fulfill({ contentType: "text/css", body: baselineCss }),
            );
          }
          const page = await context.newPage();
          await page.goto(new URL("/pt-br/busca/?q=boletim", base).href);
          await page.evaluate(() => document.fonts.ready);
          const pagination = page.locator(".lps-search-pagination ul");
          const geometry = await pagination.evaluate((el) => {
            const s = getComputedStyle(el);
            return {
              display: s.display,
              wrap: s.flexWrap,
              gap: s.gap,
              controls: [...el.querySelectorAll("a,li > span")].map((control) => {
                const r = control.getBoundingClientRect();
                return { x: r.x, y: r.y, width: r.width, height: r.height };
              }),
            };
          });
          assert.equal(geometry.display, "flex");
          assert.equal(geometry.wrap, "wrap");
          assert.ok(geometry.controls.length > 1, "Exercise real multipage results");
          assert.ok(geometry.controls.every((r) => r.width >= 44 && r.height >= 44));
          await page.screenshot({ path: `${out}/${stage}-${width}.png`, fullPage: true });
          const next = pagination.locator("a").first();
          const destination = await next.getAttribute("href");
          const navigation = page.waitForURL(new URL(destination, base).href, { timeout: 15000 });
          await keyboardTo(page, ".lps-search-pagination a");
          await page.keyboard.press("Enter");
          await navigation;
          assert.equal(new URL(page.url()).pathname, "/pt-br/busca/page/2/");
          assert.equal(
            await page.locator('.lps-search-pagination [aria-current="page"]').innerText(),
            "2",
          );
          observed.push(geometry);
          await writeFile(`${out}/${stage}-${width}.json`, JSON.stringify(geometry, null, 2));
        } finally {
          await context.close();
        }
      }
      assert.deepEqual(observed[1], observed[0], "Search pagination geometry changed");
    }
  } finally {
    await browser.close();
    await server.close();
    console.log(JSON.stringify({ closedBrowser: server.process().pid }));
  }
});
