import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { after, before, test } from "node:test";
import { chromium } from "playwright";
import { armMediaEvent, keyboardTo } from "./helpers/authored-media.mjs";

const base = process.env.LPS_BASE_URL ?? "http://127.0.0.1:8894";
const output = process.env.LPS_MEDIA_OUTPUT;
const assets = "tests/fixtures/media/assets";
let browser;
const receipts = [];
before(async () => {
  browser = process.env.LPS_BROWSER_ENDPOINT_FILE
    ? await chromium.connect(
        JSON.parse(await readFile(process.env.LPS_BROWSER_ENDPOINT_FILE)).wsEndpoint,
      )
    : await chromium.launch({ executablePath: "/usr/bin/chromium", headless: true });
  if (output) await mkdir(output, { recursive: true });
});
after(async () => {
  await browser?.close();
  if (output)
    await writeFile(path.join(output, "receipts.json"), JSON.stringify(receipts, null, 2));
});

for (const [locale, route] of [
  ["pt-br", "/pt-br/midia-acessivel/"],
  ["en", "/en/accessible-media/"],
]) {
  for (const width of [375, 768, 1280]) {
    test(`PDF delivery and matching HTML: ${locale} ${width}`, async () => {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      try {
        const response = await page.goto(new URL(route, base).href);
        assert.equal(response.status(), 200);
        const link = page.locator('main a[href$=".pdf"]');
        const url = new URL(await link.getAttribute("href"), page.url()).href;
        const pdf = await page.request.get(url);
        const bytes = await pdf.body();
        const receipt = {
          kind: "pdf",
          locale,
          width,
          requested: url,
          actual: pdf.url(),
          status: pdf.status(),
          headers: pdf.headers(),
          bytes: bytes.length,
        };
        receipts.push(receipt);
        assert.equal(
          pdf.url(),
          url,
          `PDF redirected to ${pdf.url()} (${pdf.headers()["content-type"]})`,
        );
        assert.equal(pdf.status(), 200);
        assert.match(pdf.headers()["content-type"], /^application\/pdf\b/u);
        assert.equal(bytes.subarray(0, 5).toString(), "%PDF-");
        assert.equal(Number((await link.textContent()).match(/(\d+) bytes/u)?.[1]), bytes.length);
        assert.deepEqual(bytes, await readFile(`${assets}/document-${locale}.pdf`));
        const text = execFileSync("pdftotext", ["-", "-"], { input: bytes }).toString();
        const alternative = await link.getAttribute("data-html-alternative");
        const target = new URL(alternative, page.url());
        assert.equal(target.pathname, route);
        assert.ok(target.hash);
        const section = page.locator(target.hash);
        const htmlText = (await section.innerText()).replace(/\s+/gu, " ").trim();
        assert.equal(text.replace(/\s+/gu, " ").trim(), htmlText, "PDF and contextual HTML differ");
        await keyboardTo(page, 'main a[href$=".pdf"]');
        const downloaded = page.waitForEvent("download", { timeout: 10000 });
        await page.keyboard.press("Enter");
        const download = await downloaded;
        assert.equal(await download.failure(), null);
        const downloadedChunks = [];
        for await (const chunk of await download.createReadStream()) downloadedChunks.push(chunk);
        assert.deepEqual(Buffer.concat(downloadedChunks), bytes);
        receipt.download = download.suggestedFilename();
        assert.equal(page.url(), new URL(route, base).href);
        await page.keyboard.press("Tab");
        assert.equal(await page.locator(":focus").getAttribute("href"), alternative);
        await page.keyboard.press("Enter");
        assert.equal(page.url(), target.href);
        assert.equal(
          await page.locator("html").getAttribute("lang"),
          locale === "en" ? "en-US" : "pt-BR",
        );
        if (output)
          await page.screenshot({
            path: `${output}/document-${locale}-${width}.png`,
            fullPage: true,
            caret: "initial",
          });
      } finally {
        await page.close();
      }
    });

    test(`Playable video, synchronized captions and keyboard: ${locale} ${width}`, async () => {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      try {
        await page.goto(new URL(route, base).href);
        const video = page.locator("main video");
        const source = await video.evaluate(
          (v) => v.getAttribute("src") || v.querySelector("source")?.getAttribute("src"),
        );
        receipts.push({ kind: "video-source", locale, width, source: source ?? null });
        assert.ok(source, "Rendered video has neither src nor source");
        assert.equal(new URL(source, page.url()).origin, new URL(base).origin);
        const media = await page.request.get(new URL(source, page.url()).href);
        assert.equal(media.status(), 200);
        assert.match(media.headers()["content-type"], /^video\/webm\b/u);
        assert.deepEqual(await media.body(), await readFile(`${assets}/qa-demo.webm`));
        assert.equal(await video.getAttribute("controls"), "");
        assert.equal(await video.getAttribute("autoplay"), null);
        await armMediaEvent(page, "loadedmetadata");
        await video.evaluate((v) => v.load());
        await page.evaluate(() => window.mediaSignal);
        const metadata = await video.evaluate((v) => ({
          duration: v.duration,
          width: v.videoWidth,
          height: v.videoHeight,
          currentSrc: v.currentSrc,
          error: v.error,
        }));
        assert.ok(metadata.duration >= 11.9 && metadata.duration <= 12.1);
        assert.equal(metadata.width, 640);
        assert.equal(metadata.height, 360);
        assert.equal(metadata.error, null);
        await keyboardTo(page, "video");
        await armMediaEvent(page, "playing");
        await page.keyboard.press("Space");
        await page.evaluate(() => window.mediaSignal);
        await armMediaEvent(page, "pause");
        await page.keyboard.press("Space");
        await page.evaluate(() => window.mediaSignal);
        assert.equal(await video.evaluate((v) => v.paused), true);
        const track = video.locator("track");
        const vtt = await page.request.get(
          new URL(await track.getAttribute("src"), page.url()).href,
        );
        assert.equal(vtt.status(), 200);
        // Playground's static MIME map uses octet-stream for VTT. Record it;
        // real track decoding/synchronization below is the accessibility contract.
        receipts.push({ kind: "caption-response", locale, width, headers: vtt.headers() });
        assert.deepEqual(await vtt.body(), await readFile(`${assets}/captions-${locale}.vtt`));
        await track.evaluate(
          (t) =>
            new Promise((resolve, reject) => {
              if (t.readyState === 2) return resolve();
              const controller = new AbortController();
              const timer = setTimeout(() => {
                controller.abort();
                reject(new Error("caption load deadline"));
              }, 10000);
              for (const event of ["load", "error"])
                t.addEventListener(
                  event,
                  () => {
                    clearTimeout(timer);
                    controller.abort();
                    if (event === "error") reject(new Error("caption load error"));
                    else resolve();
                  },
                  { signal: controller.signal },
                );
              t.track.mode = "showing";
            }),
        );
        const cues = await track.evaluate((t) =>
          Array.from(t.track.cues, (c) => ({ start: c.startTime, end: c.endTime, text: c.text })),
        );
        assert.deepEqual(
          cues.map((c) => [c.start, c.end]),
          [
            [0, 4],
            [4, 8],
            [8, 12],
          ],
        );
        assert.equal(await track.getAttribute("srclang"), locale === "en" ? "en" : "pt-BR");
        const transcriptId = await video.getAttribute("aria-describedby");
        const transcript = await page.locator(`#${transcriptId}`).innerText();
        for (const cue of cues)
          assert.ok(transcript.includes(cue.text), "Caption text missing from transcript");
        const beforeSeek = await video.evaluate((v) => v.currentTime);
        await armMediaEvent(page, "seeked");
        await page.keyboard.press("ArrowRight");
        await page.evaluate(() => window.mediaSignal);
        const afterSeek = await video.evaluate((v) => v.currentTime);
        assert.ok(
          afterSeek > beforeSeek,
          `Keyboard seek did not advance: ${beforeSeek} -> ${afterSeek}`,
        );
        // Native keyboard step size is browser-defined (Chromium uses 1% here).
        // Independently seek across a cue boundary and observe the actual track event.
        await armMediaEvent(page, "seeked");
        await video.evaluate(
          (v) =>
            new Promise((resolve, reject) => {
              const controller = new AbortController();
              const timer = setTimeout(() => {
                controller.abort();
                reject(new Error("cuechange deadline"));
              }, 10000);
              v.textTracks[0].addEventListener(
                "cuechange",
                () => {
                  clearTimeout(timer);
                  controller.abort();
                  resolve();
                },
                { signal: controller.signal },
              );
              v.currentTime = 6;
            }),
        );
        await page.evaluate(() => window.mediaSignal);
        assert.deepEqual(
          await track.evaluate((t) => Array.from(t.track.activeCues, (c) => c.text)),
          [cues[1].text],
        );
        if (output)
          await video.screenshot({
            path: `${output}/caption-${locale}-${width}.png`,
            caret: "initial",
          });
        // Exercise text-track disabling/enabling on the real media track, not a mock.
        await track.evaluate((t) => {
          t.track.mode = "disabled";
        });
        assert.equal(await track.evaluate((t) => t.track.activeCues), null);
        await track.evaluate((t) => {
          t.track.mode = "showing";
        });
        await page.keyboard.press("Tab");
        await page.keyboard.press("Shift+Tab");
        assert.equal(await video.evaluate((v) => document.activeElement === v), true);
        assert.ok(
          await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
        );
        if (output) {
          await writeFile(`${output}/media-${locale}-${width}.html`, await page.content());
          await writeFile(
            `${output}/media-${locale}-${width}.yaml`,
            await page.locator("body").ariaSnapshot(),
          );
          await page.screenshot({
            path: `${output}/media-${locale}-${width}.png`,
            fullPage: true,
            caret: "initial",
          });
        }
        receipts.push({
          kind: "playback",
          locale,
          width,
          metadata,
          cues,
          keyboard: ["Tab", "Space:play", "Space:pause", "ArrowRight:seek", "Tab", "Shift+Tab"],
        });
      } finally {
        await page.close();
      }
    });
  }
}
