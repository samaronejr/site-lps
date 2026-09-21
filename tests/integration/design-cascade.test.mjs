import assert from "node:assert/strict";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { after, before, test } from "node:test";
import { chromium } from "playwright";
import { keyboardTo } from "./helpers/authored-media.mjs";

const base = process.env.LPS_BASE_URL ?? "http://127.0.0.1:8894";
const output = process.env.LPS_CASCADE_OUTPUT;
let browser;
before(async () => {
  browser = process.env.LPS_BROWSER_ENDPOINT_FILE
    ? await chromium.connect(
        JSON.parse(await readFile(process.env.LPS_BROWSER_ENDPOINT_FILE)).wsEndpoint,
      )
    : await chromium.launch({ executablePath: "/usr/bin/chromium", headless: true });
  if (output) await mkdir(output, { recursive: true });
});
after(async () => browser?.close());

for (const [locale, mediaRoute, aboutRoute] of [
  ["pt-br", "/pt-br/midia-acessivel/", "/pt-br/sobre/"],
  ["en", "/en/accessible-media/", "/en/about/"],
]) {
  for (const width of [375, 768, 1280]) {
    test(`Ordinary body paragraphs stay at least 16px: ${locale} ${width}`, async () => {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      try {
        await page.goto(new URL(aboutRoute, base).href);
        await page.evaluate(() => document.fonts.ready);
        const observed = await page.evaluate(() => ({
          url: location.href,
          h1: document.querySelector("h1").textContent,
          body: getComputedStyle(document.body).fontSize,
          bodyPreset: getComputedStyle(document.body).getPropertyValue(
            "--wp--preset--font-size--body",
          ),
          paragraphs: [...document.querySelectorAll("main article > p.lps-summary")].map((p) => ({
            text: p.textContent,
            size: getComputedStyle(p).fontSize,
          })),
          h1Size: getComputedStyle(document.querySelector("h1")).fontSize,
        }));
        if (output) {
          await writeFile(
            `${output}/body-${locale}-${width}.json`,
            JSON.stringify(observed, null, 2),
          );
          await page.screenshot({ path: `${output}/body-${locale}-${width}.png`, fullPage: true });
        }
        assert.equal(observed.url, new URL(aboutRoute, base).href);
        assert.ok(observed.paragraphs.length > 0, "Ordinary article paragraphs must be exercised");
        assert.ok(Number.parseFloat(observed.body) >= 16, `Body is ${observed.body}`);
        for (const p of observed.paragraphs)
          assert.ok(Number.parseFloat(p.size) >= 16, `Body paragraph is ${p.size}`);
      } finally {
        await page.close();
      }
    });

    test(`Native video keyboard focus has the action outline and offset: ${locale} ${width}`, async () => {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      try {
        await page.goto(new URL(mediaRoute, base).href);
        await page.evaluate(() => document.fonts.ready);
        await keyboardTo(page, "video[controls]");
        const video = page.locator("video");
        const observed = await video.evaluate((v) => {
          const s = getComputedStyle(v);
          // The expected focus color is read back from the --color-action token
          // instead of a hardcoded literal, so the pin follows the token layer.
          const probe = document.createElement("div");
          probe.style.color = "var(--color-action)";
          document.body.append(probe);
          const actionToken = getComputedStyle(probe).color;
          probe.remove();
          return {
            url: location.href,
            focused: document.activeElement === v,
            focusVisible: v.matches(":focus-visible"),
            controls: v.controls,
            tabindex: v.getAttribute("tabindex"),
            outline: s.outline,
            width: s.outlineWidth,
            style: s.outlineStyle,
            color: s.outlineColor,
            offset: s.outlineOffset,
            actionToken,
          };
        });
        if (output) {
          const cdp = await page.context().newCDPSession(page);
          await cdp.send("DOM.enable");
          await cdp.send("CSS.enable");
          const { root } = await cdp.send("DOM.getDocument");
          const { nodeId } = await cdp.send("DOM.querySelector", {
            nodeId: root.nodeId,
            selector: "video",
          });
          observed.matchedStyles = await cdp.send("CSS.getMatchedStylesForNode", { nodeId });
          await cdp.detach();
          await writeFile(
            `${output}/focus-${locale}-${width}.json`,
            JSON.stringify(observed, null, 2),
          );
          await page.screenshot({ path: `${output}/focus-${locale}-${width}.png` });
          await writeFile(
            `${output}/focus-${locale}-${width}.yaml`,
            await page.locator("body").ariaSnapshot(),
          );
        }
        assert.equal(observed.focused, true);
        assert.equal(observed.focusVisible, true);
        assert.equal(observed.controls, true);
        assert.equal(observed.tabindex, null);
        // The literal pin is retained as the token's frozen value, and the
        // outline must equal the live --color-action token (#165A96).
        assert.equal(
          observed.actionToken,
          "rgb(22, 90, 150)",
          `--color-action token drifted: ${observed.actionToken}`,
        );
        assert.deepEqual(
          [observed.width, observed.style, observed.color, observed.offset],
          ["3px", "solid", observed.actionToken, "3px"],
          `Actual native host outline: ${observed.outline}; offset ${observed.offset}`,
        );

        // The focus primitive is a shared selector list, so a second family
        // member — a footer link on the anchor band — must show the same
        // 3px/3px geometry with the dark-surface separation color
        // (--color-focus-on-dark).
        await keyboardTo(page, ".lps-site-footer a");
        const footerLink = page.locator(".lps-site-footer a").first();
        const darkObserved = await footerLink.evaluate((el) => {
          const s = getComputedStyle(el);
          const probe = document.createElement("div");
          probe.style.color = "var(--color-focus-on-dark)";
          document.body.append(probe);
          const offsetToken = getComputedStyle(probe).color;
          probe.remove();
          return {
            focused: document.activeElement === el,
            focusVisible: el.matches(":focus-visible"),
            width: s.outlineWidth,
            style: s.outlineStyle,
            color: s.outlineColor,
            offset: s.outlineOffset,
            offsetToken,
          };
        });
        assert.equal(darkObserved.focused, true);
        assert.equal(darkObserved.focusVisible, true);
        assert.equal(
          darkObserved.offsetToken,
          "rgb(255, 255, 255)",
          `--color-focus-on-dark token drifted: ${darkObserved.offsetToken}`,
        );
        assert.deepEqual(
          [darkObserved.width, darkObserved.style, darkObserved.color, darkObserved.offset],
          ["3px", "solid", darkObserved.offsetToken, "3px"],
          `Dark-band focus outline: ${darkObserved.color}; offset ${darkObserved.offset}`,
        );
        if (output) {
          await writeFile(
            `${output}/focus-dark-${locale}-${width}.json`,
            JSON.stringify(darkObserved, null, 2),
          );
        }
      } finally {
        await page.close();
      }
    });
  }
}
