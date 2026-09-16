import assert from "node:assert/strict";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { chromium } from "playwright";
import { enumerate } from "./controls.mjs";
import { requirements } from "./inventory.mjs";
import { root, sha } from "./prepare.mjs";

const stage = process.argv[2];
assert.ok(["baseline", "red", "green"].includes(stage));
const base = process.env.LPS_QA_URL;
assert.equal(base, "http://127.0.0.1:8903", "Only owned disposable origin permitted");
await mkdir(`${root}/${stage}`, { recursive: true });
await mkdir(`${root}/tmp`, { recursive: true });
const request = await fetch(`${base}/?task24k=inventory`, { signal: AbortSignal.timeout(45000) });
assert.equal(request.status, 200);
const inventory = await request.json();
await writeFile(`${root}/${stage}/public-inventory.json`, JSON.stringify(inventory, null, 2));
const source = JSON.parse(await readFile(`${root}/manifests/identity.json`));
const wanted =
  stage === "baseline"
    ? ["media-pt", "media-en"]
    : [
        "media-pt",
        "media-en",
        "about-pt",
        "about-en",
        "contact-pt",
        "accessibility-pt",
        "history-pt",
        "history-en",
        "governance-pt",
        "governance-en",
        "research-area-en",
        "organization-en",
        "news-single-en",
        "event-single-en",
        "opportunity-closed-en",
      ];
// Linux AF_UNIX paths are bounded to 108 bytes; this resolves inside the owned directory.
process.env.TMPDIR = `/proc/self/cwd/${path.relative(process.cwd(), root)}/tmp`;
const server = await chromium.launchServer({
  headless: false,
  executablePath: "/usr/bin/chromium",
  env: {
    ...process.env,
    XDG_CONFIG_HOME: `${root}/tmp/config`,
    XDG_CACHE_HOME: `${root}/tmp/cache`,
  },
  args: ["--no-sandbox", "--disable-dev-shm-usage"],
  timeout: 45000,
});
const resource = { pid: server.process().pid, argv: server.process().spawnargs, phase: stage };
console.log(JSON.stringify({ resource: "browser", ...resource }));
await writeFile(`${root}/logs/${stage}-browser.json`, JSON.stringify(resource, null, 2));
const browser = await chromium.connect(server.wsEndpoint());
let cancelled = false;
process.once("SIGTERM", () => {
  cancelled = true;
  void browser.close();
});
const receipts = [];

// 24j-B2: fullPage capture exposes offscreen fixed elements (e.g. the
// unfocused .lps-skip-link at translateY(-200%)) as floating chips, because the
// compositor positions viewport-fixed boxes inside the expanded capture
// viewport. Conceal fixed elements the user cannot see for the duration of the
// capture only; scroll, focus, and full-page coverage are preserved, and the
// product DOM/CSS is untouched. Sticky elements are left alone: they render at
// their document position in a full-page capture, so hiding them would delete
// real content. Zero-area fixed boxes are left alone to avoid hiding visible
// overflowing children of collapsed wrappers.
async function concealOffscreenFixed(page) {
  return page.evaluate(() => {
    const active = document.activeElement;
    const nodes = [];
    for (const el of document.querySelectorAll("body *")) {
      if (el === active || (active && active !== document.body && el.contains(active))) continue;
      if (el.matches(":focus,:focus-visible")) continue;
      let style;
      try {
        style = getComputedStyle(el);
      } catch {
        continue;
      }
      if (style.position !== "fixed") continue;
      const rect = el.getBoundingClientRect();
      if (rect.width === 0 || rect.height === 0) continue;
      const offscreen =
        rect.bottom <= 0 ||
        rect.top >= window.innerHeight ||
        rect.right <= 0 ||
        rect.left >= window.innerWidth;
      if (!offscreen) continue;
      nodes.push(el);
      el.setAttribute("data-lps-capture-concealed", el.style.getPropertyValue("visibility"));
      el.style.setProperty("visibility", "hidden", "important");
    }
    window.__lpsCaptureNodes = nodes;
    return nodes.length;
  });
}
async function revealOffscreenFixed(page) {
  await page.evaluate(() => {
    for (const el of window.__lpsCaptureNodes ?? []) {
      if (!el.isConnected) continue;
      const prior = el.getAttribute("data-lps-capture-concealed");
      el.removeAttribute("data-lps-capture-concealed");
      if (prior) el.style.setProperty("visibility", prior);
      else el.style.removeProperty("visibility");
    }
    window.__lpsCaptureNodes = undefined;
  });
}
async function fullPageScreenshot(page, file) {
  await concealOffscreenFixed(page);
  try {
    await page.screenshot({ path: file, fullPage: true });
  } finally {
    await revealOffscreenFixed(page);
  }
}
async function mediaProof(page, locale) {
  const link = page.locator("main a[download]").first();
  const href = await link.getAttribute("href");
  const response = await page.request.get(href);
  assert.equal(response.status(), 200);
  const bytes = await response.body();
  const expected = await readFile(
    `${root}/runtime/wordpress/wp-content/uploads/lps-qa-media/document-${locale}.pdf`,
  );
  assert.equal(bytes.subarray(0, 5).toString(), "%PDF-");
  assert.equal(sha(bytes), sha(expected));
  const downloading = page.waitForEvent("download", { timeout: 15000 });
  await link.click();
  const download = await downloading;
  assert.equal(await download.failure(), null);
  const file = `${root}/${stage}/download-${locale}.pdf`;
  await download.saveAs(file);
  assert.equal(sha(await readFile(file)), sha(expected));
  const content = JSON.parse(
    await readFile(`${root}/runtime/wordpress/wp-content/uploads/lps-qa-media/content.json`),
  );
  assert.deepEqual(
    await page.locator("#lps-qa-document p").allTextContents(),
    content[locale].paragraphs,
  );
  const media = await page.locator("video").evaluate(async (video) => {
    const event = (node, name, trigger) =>
      new Promise((resolve, reject) => {
        const controller = new AbortController();
        const timer = setTimeout(() => {
          controller.abort();
          reject(Error(`Media deadline ${name}`));
        }, 15000);
        node.addEventListener(
          name,
          () => {
            clearTimeout(timer);
            controller.abort();
            resolve();
          },
          { once: true, signal: controller.signal },
        );
        video.addEventListener(
          "error",
          () => {
            clearTimeout(timer);
            controller.abort();
            reject(Error(`Video error ${video.error?.code}`));
          },
          { once: true, signal: controller.signal },
        );
        Promise.resolve(trigger()).catch((error) => {
          clearTimeout(timer);
          controller.abort();
          reject(error);
        });
      });
    const track = video.querySelector("track");
    video.textTracks[0].mode = "showing";
    const loaded = track.readyState === 2 ? Promise.resolve() : event(track, "load", () => {});
    await event(video, "loadedmetadata", () => video.load());
    await loaded;
    const metadata = {
      duration: video.duration,
      width: video.videoWidth,
      height: video.videoHeight,
      error: video.error,
    };
    await event(video, "playing", () => video.play());
    const playing = !video.paused;
    await event(video, "pause", () => video.pause());
    await event(video, "seeked", () => {
      video.currentTime = 6;
    });
    const cue = {
      active: [...video.textTracks[0].activeCues].map((c) => c.text),
      all: [...video.textTracks[0].cues].map((c) => ({
        start: c.startTime,
        end: c.endTime,
        text: c.text,
      })),
      language: track.srclang,
      mode: video.textTracks[0].mode,
    };
    return {
      metadata,
      playing,
      paused: video.paused,
      time: video.currentTime,
      cue,
      src: video.currentSrc,
    };
  });
  assert.deepEqual(media.metadata, { duration: 12, width: 640, height: 360, error: null });
  assert.equal(media.playing, true);
  assert.equal(media.paused, true);
  assert.equal(media.time, 6);
  assert.deepEqual(
    media.cue.all.map((c) => [c.start, c.end]),
    [
      [0, 4],
      [4, 8],
      [8, 12],
    ],
  );
  assert.deepEqual(media.cue.active, [content[locale].cues[1]]);
  assert.equal(media.cue.language, locale === "en" ? "en" : "pt-BR");
  return { pdf: { href, bytes: bytes.length, sha256: sha(bytes), download: true }, video: media };
}
try {
  for (const route of requirements.routes.filter((r) => wanted.includes(r.id)))
    for (const width of [375, 1280]) {
      if (cancelled) break;
      const id = `${route.id}__${width}`;
      const row = {
        id,
        path: route.path,
        locale: route.locale,
        width,
        sourceHash: source.sourceHash,
      };
      const context = await browser.newContext({
        viewport: { width, height: width === 375 ? 812 : 800 },
        locale: route.locale === "en" ? "en-US" : "pt-BR",
        serviceWorkers: "block",
      });
      await context.addCookies([
        { name: "playground_auto_login_already_happened", value: "1", url: base },
      ]);
      const page = await context.newPage();
      page.setDefaultTimeout(15000);
      page.setDefaultNavigationTimeout(45000);
      try {
        const response = await page.goto(base + route.path, { waitUntil: "load" });
        await page.evaluate(() => document.fonts.ready);
        row.status = response.status();
        row.template = response.headers()["x-task24k-template"];
        row.recordId = Number(response.headers()["x-task24k-post"]);
        row.dom = await page.evaluate(() => ({
          url: location.href,
          lang: document.documentElement.lang,
          h1: document.querySelector("h1")?.textContent.trim(),
          main: document.querySelector("main")?.innerText,
          images: [...document.querySelectorAll("main figure img")].map((i) => ({
            src: i.currentSrc,
            alt: i.alt,
          })),
          figures: [...document.querySelectorAll("main figure")].map((f) => ({
            text: f.innerText,
            html: f.outerHTML,
          })),
          codepoints: [...new Set(document.querySelector("main")?.innerText)]
            .filter((c) => c.codePointAt(0) > 127)
            .map((c) => ({
              char: c,
              codepoint: `U+${c.codePointAt(0).toString(16).toUpperCase()}`,
            })),
          bodyFont: getComputedStyle(document.body).fontSize,
          recordState: document.querySelector("main article[data-state]")?.dataset.state,
        }));
        row.controls = await enumerate(page, {
          ...route,
          viewport: width === 375 ? "mobile-375" : "desktop-1280",
        });
        row.png = `${stage}/${id}.png`;
        row.html = `${stage}/${id}.html`;
        row.url = page.url();
        row.at = new Date().toISOString();
        await fullPageScreenshot(page, `${root}/${row.png}`);
        await writeFile(`${root}/${row.html}`, await page.content());
        await writeFile(`${root}/${stage}/${id}.yaml`, await page.locator("body").ariaSnapshot());
        assert.equal(row.status, 200);
        assert.equal(new Intl.Locale(row.dom.lang).language, route.locale === "en" ? "en" : "pt");
        if (route.locale === "pt-br") assert.equal(row.dom.lang, "pt-BR");
        const post = inventory.posts.find((p) => p.id === row.recordId);
        assert.ok(post, `Public database identity missing for ${row.url}`);
        assert.equal(row.dom.h1, post.title);
        row.post = post;
        if (route.state === "closed") assert.equal(row.dom.recordState, "closed");
        if (route.id.startsWith("media-")) row.media = await mediaProof(page, route.locale);
        if (stage !== "baseline") {
          if (route.id.startsWith("media-")) {
            assert.equal(
              row.dom.images.length,
              0,
              "B1: authored figures must have no substitute image",
            );
            assert.ok(row.dom.figures.some((f) => /indisponível|unavailable/.test(f.text)));
          }
          if (route.id.startsWith("about-")) {
            assert.ok(row.dom.main.includes("Universitária"), "B2: proper location codepoints");
            if (route.locale === "pt-br")
              assert.ok(
                row.dom.main.includes("laboratório mantém convênios ativos com agências"),
                "B2: Portuguese codepoints",
              );
          }
          if (route.id === "contact-pt")
            assert.ok(
              row.dom.main.includes("públicos") && row.dom.main.includes("Coordenação"),
              "B2: contact codepoints",
            );
          if (route.id === "accessibility-pt")
            assert.ok(row.dom.main.includes("Declaração"), "B2: accessibility codepoints");
          if (route.id === "history-pt")
            assert.ok(
              row.dom.main.includes("História") &&
                row.dom.main.includes("Trajetória do laboratório"),
              "B2: history codepoints",
            );
          if (route.id === "governance-pt")
            assert.ok(
              row.dom.main.includes("Governança") &&
                row.dom.main.includes("Coordenação acadêmica") &&
                row.dom.main.includes("Elétrica"),
              "B2: governance codepoints",
            );
          if (route.id.startsWith("history-") || route.id.startsWith("governance-")) {
            assert.equal(post.locale, route.locale);
            assert.equal(post.declaredLocale, route.locale);
            assert.ok(
              post.translations["pt-br"] && post.translations.en,
              "B5: normal Polylang pair missing",
            );
          }
        }
        row.pass = true;
      } catch (error) {
        row.pass = false;
        row.error = error.stack;
      } finally {
        await context.close();
        receipts.push(row);
        await writeFile(
          `${root}/${stage}/results.json`,
          JSON.stringify(
            { stage, pass: receipts.every((r) => r.pass), sourceHash: source.sourceHash, receipts },
            null,
            2,
          ),
        );
      }
      console.log(
        JSON.stringify({
          id,
          pass: row.pass,
          status: row.status,
          error: row.error?.split("\n")[0],
        }),
      );
    }
} finally {
  await browser.close();
  await server.close();
  await writeFile(
    `${root}/logs/${stage}-browser-teardown.json`,
    JSON.stringify({
      pid: resource.pid,
      closedAt: new Date().toISOString(),
      exitCode: server.process().exitCode,
      signalCode: server.process().signalCode,
    }),
  );
}
process.exitCode = !cancelled && receipts.every((r) => r.pass) ? 0 : 1;
