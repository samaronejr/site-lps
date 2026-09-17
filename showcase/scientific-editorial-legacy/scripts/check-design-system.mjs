import { readFile } from "node:fs/promises";
import { resolve } from "node:path";

const root = resolve(new URL("../", import.meta.url).pathname);
const cssArgument = process.argv.slice(2).find((argument) => argument.endsWith(".css"));
const htmlArgument = process.argv.slice(2).find((argument) => argument.endsWith(".html"));
const cssPath = resolve(cssArgument ?? `${root}/styles.css`);
const htmlPath = resolve(htmlArgument ?? `${root}/index.html`);
const [css, html] = await Promise.all([readFile(cssPath, "utf8"), readFile(htmlPath, "utf8")]);
const findings = [];
const add = (code, message, detail = undefined) => findings.push({ code, message, detail });

const rootRanges = [];
for (const match of css.matchAll(/(?:^|\})\s*:root\s*\{([^}]*)\}/gms)) {
  rootRanges.push([match.index, match.index + match[0].length]);
}
const insideRoot = (index) => rootRanges.some(([start, end]) => index >= start && index <= end);

for (const match of css.matchAll(/#[0-9a-f]{3,8}\b|\brgba?\([^)]*\)|\bhsla?\([^)]*\)/gi)) {
  if (!insideRoot(match.index)) {
    add("UNTOKENIZED_COLOR", "Raw color appears outside a :root token declaration.", match[0]);
  }
}

for (const match of css.matchAll(/box-shadow\s*:\s*([^;]+);/gi)) {
  if (!/var\(--shadow-none\)/.test(match[1])) {
    add(
      "SHADOW_SURFACE",
      "Box shadows are forbidden by the borders-only depth contract.",
      match[1].trim(),
    );
  }
}

for (const match of css.matchAll(/border-radius\s*:\s*([^;]+);/gi)) {
  if (!/var\(--radius-square\)|^0(?:[a-z%]+)?$/i.test(match[1].trim())) {
    add(
      "ROUNDED_SURFACE",
      "Rectangular showcase surfaces must resolve to the square-radius token.",
      match[1].trim(),
    );
  }
}

if (/transition\s*:\s*all\b/i.test(css)) {
  add(
    "TRANSITION_ALL",
    "Transitions must name composited or state properties; transition: all is forbidden.",
  );
}

const hasMotion = /\b(?:transition(?:-[a-z-]+)?|animation(?:-[a-z-]+)?)\s*:/i.test(css);
if (hasMotion && !/@media\s*\(prefers-reduced-motion:\s*reduce\)/i.test(css)) {
  add(
    "MISSING_REDUCED_MOTION",
    "Motion declarations require an explicit prefers-reduced-motion path.",
  );
}

for (const selector of ["a", "button", "input", "select", "textarea", "summary"]) {
  const focusPattern = new RegExp(
    `(?:^|[,\\s])${selector.replace("[", "\\[")}:focus-visible\\b`,
    "m",
  );
  if (!focusPattern.test(css)) {
    add("MISSING_FOCUS_STATE", `Missing explicit :focus-visible treatment for ${selector}.`);
  }
}

for (const match of css.matchAll(
  /(?:^|[;{])\s*(color|background(?:-color)?|border(?:-[a-z]+-color)?|fill|stroke)\s*:\s*([^;}{]+)[;}]/gim,
)) {
  if (insideRoot(match.index)) continue;
  const value = match[2].trim();
  if (!/^(?:var\(|currentColor$|inherit$|transparent$|none$|0$)/i.test(value)) {
    add(
      "UNTOKENIZED_COLOR_PROPERTY",
      `Color-bearing property ${match[1]} must resolve to a token.`,
      value,
    );
  }
}

for (const match of css.matchAll(/font-size\s*:\s*([^;]+);/gi)) {
  if (!/var\(--type-|clamp\(/.test(match[1])) {
    add(
      "UNTOKENIZED_TYPE",
      "Font sizes must resolve to the documented type scale.",
      match[1].trim(),
    );
  }
}

const requiredTokens = [
  "--color-paper",
  "--color-ink",
  "--color-navy",
  "--color-signal",
  "--color-rule",
  "--font-editorial",
  "--font-interface",
  "--font-mono",
  "--grid-max",
  "--grid-gutter",
  "--motion-fast",
  "--radius-square",
  "--shadow-none",
];
for (const token of requiredTokens) {
  if (!css.includes(`${token}:`)) add("MISSING_TOKEN", `Required design token ${token} is absent.`);
}

const requiredMarkup = [
  ["TYPOGRAPHY", 'id="tipografia"'],
  ["LINK", "<a "],
  ["BUTTON", "<button"],
  ["NAVIGATION", "<nav"],
  ["EDITORIAL_CARD", 'class="record'],
  ["FIGURE", "<figure"],
  ["FILTER", "<fieldset"],
  ["TABLE", "<table"],
  ["FORM", "<form"],
  ["ALERT", 'class="alert'],
  ["MEDIA", 'class="media-block'],
  ["PORTUGUESE_STRESS", 'lang="pt-BR"'],
  ["ENGLISH_STRESS", 'lang="en"'],
  ["CJK_STRESS", 'lang="zh-Hans"'],
];
for (const [code, needle] of requiredMarkup) {
  if (!html.includes(needle)) add("MISSING_PRIMITIVE", `Showcase is missing ${code}.`);
}

if (
  /<img\b[^>]*src=["']https?:/i.test(html) ||
  /(?:ufrj|coppe|wired)[^"']*\.(?:svg|png|jpe?g|webp)/i.test(html)
) {
  add(
    "UNAPPROVED_ASSET",
    "Showcase must not copy or hotlink institutional/reference brand assets.",
  );
}
if (/\p{Extended_Pictographic}/u.test(html)) {
  add("EMOJI_ICON", "Emoji are forbidden as icons or visible structural content.");
}
if (!html.includes('name="viewport"'))
  add("MISSING_VIEWPORT", "Responsive viewport metadata is required.");
if (!html.includes('class="skip-link"')) add("MISSING_SKIP_LINK", "A skip link is required.");

const result = {
  lane: "design-system",
  status: findings.length === 0 ? "passed" : "failed",
  checked: { css: cssPath, html: htmlPath },
  findingCount: findings.length,
  findings,
};
process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
if (findings.length > 0) process.exitCode = 1;
