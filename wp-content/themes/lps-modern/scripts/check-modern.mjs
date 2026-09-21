import { createHash } from "node:crypto";
import { access, readdir, readFile } from "node:fs/promises";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

const themeRoot = resolve(new URL("../", import.meta.url).pathname);

const WAVEFORM_SHA256 = "df03d99bc5b9e1fb423d94d69fcbf7ffbd6ce0e5e24bb42dc951595112a3ed0d";
const REFERENCE_SHA256 = "4ae0fcc8a4fc5beaadc89c52fcfa87cdcc4ec4e4075b3f94ff408c687b5c2250";

const sha256 = (text) => createHash("sha256").update(text, "utf8").digest("hex");

export async function checkModern() {
  const [css, themeSource, templateNames] = await Promise.all([
    readFile(`${themeRoot}/assets/css/theme.css`, "utf8"),
    readFile(`${themeRoot}/theme.json`, "utf8"),
    readdir(`${themeRoot}/templates`),
  ]);
  const theme = JSON.parse(themeSource);
  const findings = [];
  const add = (code, detail) => findings.push({ code, detail });

  const rootRanges = [...css.matchAll(/(?:^|\})\s*:root\s*\{([^}]*)\}/gms)].map((match) => [
    match.index,
    match.index + match[0].length,
  ]);
  const insideRoot = (index) => rootRanges.some(([start, end]) => index >= start && index <= end);

  for (const match of css.matchAll(/#[0-9a-f]{3,8}\b|\brgba?\(|[\s(:]rgb\(/gi)) {
    if (!insideRoot(match.index)) add("UNTOKENIZED_COLOR", match[0]);
  }
  for (const match of css.matchAll(/(?:repeating-)?(?:linear|radial|conic)-gradient\s*\(/gi)) {
    if (!insideRoot(match.index)) add("UNTOKENIZED_GRADIENT", match[0]);
  }
  for (const match of css.matchAll(/box-shadow\s*:\s*([^;]+);/gi)) {
    if (!/var\(--shadow-(?:sm|md|lg)\)|^none$/i.test(match[1].trim()))
      add("UNTOKENIZED_SHADOW", match[1].trim());
  }
  for (const match of css.matchAll(/(?<!outline-)border-radius\s*:\s*([^;]+);/gi)) {
    if (!/var\(--radius-(?:sm|md|lg|pill)\)|^0$/i.test(match[1].trim()))
      add("UNTOKENIZED_RADIUS", match[1].trim());
  }
  if (/transition\s*:\s*all\b/i.test(css)) add("TRANSITION_ALL", "transition: all");
  if (
    /\b(?:transition|animation)(?:-[a-z-]+)?\s*:/i.test(css) &&
    !/@media\s*\(prefers-reduced-motion:\s*reduce\)/i.test(css)
  ) {
    add("MISSING_REDUCED_MOTION", "motion without reduced-motion override");
  }
  const whereCovered = new Set(
    [...css.matchAll(/:where\(([^)]*)\)\s*:focus-visible/g)].flatMap((match) =>
      match[1].split(",").map((part) => part.trim()),
    ),
  );
  for (const selector of ["a", "button", "input", "select", "textarea", "summary"]) {
    const direct = new RegExp(`(?:^|[,\\s(])${selector}:focus-visible\\b`, "m").test(css);
    if (!direct && !whereCovered.has(selector)) {
      add("MISSING_FOCUS_STATE", selector);
    }
  }
  if (/@import|url\(["']?https?:/i.test(css)) add("EXTERNAL_RUNTIME_ASSET", "remote CSS asset");

  const templateMarkup = (
    await Promise.all(
      templateNames.map((name) => readFile(`${themeRoot}/templates/${name}`, "utf8")),
    )
  ).join("\n");
  if (/<(?:img|script|link)\b[^>]+https?:/i.test(templateMarkup)) {
    add("EXTERNAL_RUNTIME_ASSET", "remote template asset");
  }

  for (const name of [
    "index.html",
    "page.html",
    "single.html",
    "archive.html",
    "search.html",
    "404.html",
    "front-page.html",
  ]) {
    if (!templateNames.includes(name)) add("MISSING_TEMPLATE", name);
    else {
      const markup = await readFile(`${themeRoot}/templates/${name}`, "utf8");
      if (!markup.includes('"lock":{"move":true,"remove":true}')) add("UNLOCKED_TEMPLATE", name);
    }
  }

  const referencedFonts = [...css.matchAll(/url\(["']?\.\.\/fonts\/([^"')]+)["']?\)/g)].map(
    (match) => `assets/fonts/${match[1]}`,
  );
  for (const path of [...new Set([...referencedFonts, "assets/fonts/OFL.txt"])]) {
    try {
      await access(`${themeRoot}/${path}`);
    } catch {
      add("MISSING_FONT_ASSET", path);
    }
  }

  const palette = theme.settings?.color?.palette ?? [];
  const slugs = new Set(palette.map((entry) => entry.slug));
  for (const slug of [
    "paper",
    "paper-soft",
    "ink",
    "ink-soft",
    "brand-ink",
    "brand",
    "accent",
    "accent-soft",
    "rule",
  ]) {
    if (!slugs.has(slug)) add("MISSING_PALETTE_SLUG", slug);
  }
  for (const key of [
    "radiusSm",
    "radiusMd",
    "radiusLg",
    "radiusPill",
    "shadowSm",
    "shadowMd",
    "shadowLg",
  ]) {
    if (typeof theme.settings?.custom?.[key] !== "string") add("MISSING_CUSTOM_TOKEN", key);
  }

  for (const file of [
    "lps-logo-horizontal.svg",
    "lps-logo-compact.svg",
    "lps-waveform-symbol.svg",
  ]) {
    try {
      const svg = await readFile(`${themeRoot}/assets/img/logo/${file}`, "utf8");
      const paths = [...svg.matchAll(/<path\b[^>]*\bd="([^"]*)"/gi)].map((m) => m[1]);
      const digests = new Set(paths.map(sha256));
      if (!digests.has(WAVEFORM_SHA256) || !digests.has(REFERENCE_SHA256)) {
        add("LOGO_GEOMETRY_DRIFT", file);
      }
    } catch {
      add("MISSING_LOGO_ASSET", file);
    }
  }

  return {
    status: findings.length === 0 ? "passed" : "failed",
    findingCount: findings.length,
    findings,
  };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const report = await checkModern();
  process.stdout.write(`${JSON.stringify({ lane: "modern", ...report }, null, 2)}\n`);
  if (report.status !== "passed") process.exitCode = 1;
}
