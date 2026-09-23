// Local-only, redistributable QA assets. Requires Chromium and a VP8-capable ffmpeg.
import { execFileSync } from "node:child_process";
import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { chromium } from "playwright";

const dir = fileURLToPath(new URL("./assets/", import.meta.url));
const content = JSON.parse(await readFile(`${dir}content.json`, "utf8"));
const ffmpeg = process.env.FFMPEG;
if (!ffmpeg) throw new Error("Set FFMPEG to the local VP8 encoder executable");
const escapeHtml = (text) =>
  text.replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;");
const browser = await chromium.launch({ executablePath: "/usr/bin/chromium", headless: true });
try {
  const page = await browser.newPage({
    viewport: { width: 640, height: 360 },
    deviceScaleFactor: 1,
  });
  const sans = await readFile(
    new URL(
      "../../../wp-content/themes/lps-theme/assets/fonts/ibm-plex-sans-regular.woff2",
      import.meta.url,
    ),
  );
  for (const [locale, data] of Object.entries(content)) {
    await page.setContent(`<!doctype html><html lang="${locale}"><head><title>${escapeHtml(data.title)}</title><style>
      @font-face { font-family: "IBM Plex Sans"; src: url(data:font/woff2;base64,${sans.toString("base64")}); }
      @page { size: A4; margin: 20mm; }
      body { color: #182B3A; font: 12pt/1.6 "IBM Plex Sans", system-ui, sans-serif; }
      h1 { color: #12304A; font-size: 24pt; line-height: 1.15; break-after: avoid; }
      p { orphans: 3; widows: 3; }
    </style></head><body><h1>${escapeHtml(data.title)}</h1>${data.paragraphs.map((p) => `<p>${escapeHtml(p)}</p>`).join("")}</body></html>`);
    await page.evaluate(() => document.fonts.ready);
    await page.pdf({
      path: `${dir}document-${locale}.pdf`,
      preferCSSPageSize: true,
      tagged: true,
      outline: true,
    });
    const cues = data.cues.map((text, index) => {
      const start = String(index * 4).padStart(2, "0");
      const end = String((index + 1) * 4).padStart(2, "0");
      return `00:00:${start}.000 --> 00:00:${end}.000\n${text}`;
    });
    await writeFile(`${dir}captions-${locale}.vtt`, `WEBVTT\n\n${cues.join("\n\n")}\n`);
  }
  const frames = [];
  for (const number of [1, 2, 3]) {
    // Bilingual, original numbered test cards, not institutional footage.
    await page.setContent(
      `<html><body style="margin:0"><canvas width="640" height="360"></canvas></body></html>`,
    );
    await page.evaluate((n) => {
      const ctx = document.querySelector("canvas").getContext("2d");
      ctx.fillStyle = "#FFFFFF";
      ctx.fillRect(0, 0, 640, 360);
      ctx.fillStyle = "#12304A";
      ctx.font = "32px system-ui";
      ctx.textAlign = "center";
      ctx.fillText("QA fixture / Teste de QA", 320, 64);
      ctx.font = "96px system-ui";
      ctx.fillText(String(n), 320, 200);
      ctx.font = "24px system-ui";
      ctx.fillText("No audio / Sem áudio", 320, 260);
    }, number);
    if (number === 1) await page.screenshot({ path: `${dir}qa-poster.png` });
    const frame = await page.screenshot({ type: "jpeg", quality: 95 });
    for (let index = 0; index < 40; index += 1) frames.push(frame);
  }
  execFileSync(
    ffmpeg,
    [
      "-hide_banner",
      "-y",
      "-f",
      "image2pipe",
      "-framerate",
      "10",
      "-c:v",
      "mjpeg",
      "-i",
      "pipe:0",
      "-an",
      "-c:v",
      "libvpx",
      "-threads",
      "2",
      "-b:v",
      "200k",
      "-g",
      "10",
      `${dir}qa-demo.webm`,
    ],
    { input: Buffer.concat(frames), stdio: ["pipe", "inherit", "inherit"] },
  );
} finally {
  await browser.close();
}
