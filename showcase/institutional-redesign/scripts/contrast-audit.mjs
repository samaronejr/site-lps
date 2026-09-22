/*
 * Contrast audit for the 2026 LPS design system.
 *
 * Computes WCAG 2.2 relative-luminance contrast for every pair the stylesheet
 * actually renders, and fails when a text pair drops below its requirement
 * (4.5:1 normal text, 3:1 large text and UI graphics).
 *
 * The palette is read from the stylesheet's own token block rather than
 * duplicated here: `var()` chains are resolved, so retuning a token re-runs the
 * whole table and a pair can never be verified against a value the sheet no
 * longer ships. Overlay tokens (rgba) are resolved too, composited over the
 * background they are declared for.
 *
 * Run: node showcase/institutional-redesign/scripts/contrast-audit.mjs
 */

import { readFileSync } from "node:fs";

const css = readFileSync(
  new URL("../../../wp-content/themes/lps-theme/assets/css/theme.css", import.meta.url),
  "utf8",
);

/** Tokens from the first :root block: the declarative layer, not an override. */
const rootAt = css.indexOf(":root");
if (rootAt < 0) throw new Error("theme.css declares no :root token block");
const tokenBlock = /:root\s*\{([\s\S]*?)\}/.exec(css.slice(rootAt));
const rawTokens = new Map();
for (const [, name, value] of tokenBlock[1].matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) {
  if (!rawTokens.has(name)) rawTokens.set(name, value.trim());
}

const rgb = (value) => {
  const clean = value.replace("#", "").trim();
  const full =
    clean.length === 3
      ? clean
          .split("")
          .map((c) => c + c)
          .join("")
      : clean;
  return [0, 2, 4].map((i) => Number.parseInt(full.slice(i, i + 2), 16));
};

const rgba = (value) => {
  const hexMatch = /^#[0-9a-f]{3,8}$/i.test(value.trim());
  if (hexMatch) return [...rgb(value), 1];
  const parts = value.match(/[\d.]+/g) ?? [];
  const [r, g, b, a = "1"] = parts;
  return [Number(r), Number(g), Number(b), Number(a)];
};

/**
 * Resolves a token name or literal to [r, g, b, a], following var() references.
 * Both spellings are accepted — `--color-text` and `var(--color-text)` — so a
 * pair can be written either way without changing what is measured.
 */
const resolve = (value, depth = 0) => {
  const text = value.trim();
  if (depth > 12) throw new Error(`var() cycle at ${value}`);
  const reference = /^(?:var\(\s*)?(--[\w-]+)\s*\)?$/.exec(text);
  if (reference) {
    const next = rawTokens.get(reference[1]);
    if (next === undefined) throw new Error(`unresolved token ${reference[1]}`);
    return resolve(next, depth + 1);
  }
  return rgba(text);
};

/** Composites a translucent foreground over an opaque background. */
const over = (top, bottom) => {
  const [r1, g1, b1, a] = top;
  const [r2, g2, b2] = bottom;
  return [
    Math.round(r1 * a + r2 * (1 - a)),
    Math.round(g1 * a + g2 * (1 - a)),
    Math.round(b1 * a + b2 * (1 - a)),
    1,
  ];
};

const luminance = ([r, g, b]) =>
  [r, g, b]
    .map((channel) => {
      const value = channel / 255;
      return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    })
    .reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);

const ratio = (foreground, background) => {
  const a = luminance(foreground);
  const b = luminance(background);
  const [light, dark] = a > b ? [a, b] : [b, a];
  return (light + 0.05) / (dark + 0.05);
};

/**
 * Every pair is [label, foreground, background, minimum], both sides expressed
 * as tokens. The list mirrors the stylesheet surfaces: canvas, shell, hero,
 * footer, notices, tables and the 3:1 UI-graphic floor.
 */
const pairs = [
  // Canvas and surfaces
  ["text on canvas", "--color-text", "--color-canvas", 4.5],
  ["text on surface", "--color-text", "--color-surface", 4.5],
  ["text on surface-alt", "--color-text", "--color-surface-alt", 4.5],
  ["muted text on surface", "--color-text-muted", "--color-surface", 4.5],
  ["muted text on canvas", "--color-text-muted", "--color-canvas", 4.5],
  ["muted text on surface-alt", "--color-text-muted", "--color-surface-alt", 4.5],
  ["soft text (meta, labels) on surface", "--color-text-soft", "--color-surface", 4.5],
  ["soft text on surface-alt", "--color-text-soft", "--color-surface-alt", 4.5],

  // Links and actions
  ["link on surface", "--color-action", "--color-surface", 4.5],
  ["link on canvas", "--color-action", "--color-canvas", 4.5],
  ["link hover on surface", "--color-action-hover", "--color-surface", 4.5],
  ["kicker on canvas", "--color-action", "--color-canvas", 4.5],
  ["white on action button", "--color-paper-000", "--color-action", 4.5],
  ["white on action hover", "--color-paper-000", "--color-action-hover", 4.5],
  ["anchor on ghost button", "--color-anchor", "--color-surface", 4.5],
  ["ghost hover text on anchor", "--color-surface", "--color-anchor", 4.5],
  ["tag text on surface-alt", "--color-text-muted", "--color-surface-alt", 4.5],
  ["accent tag text on cyan wash", "--color-accent-ink", "--color-accent-wash", 4.5],
  ["skip link text on accent", "--color-navy-950", "--color-accent", 4.5],

  // Shell
  ["utility text on anchor", "--color-on-dark", "--color-anchor", 4.5],
  ["utility meta on anchor", "--color-on-dark-meta", "--color-anchor", 4.5],
  ["utility hover white on anchor", "--color-surface", "--color-anchor", 4.5],
  ["session link text on anchor", "--color-surface", "--color-anchor", 4.5],
  ["session link active on accent", "--color-navy-950", "--color-accent", 4.5],
  ["session link hover on surface", "--color-navy-900", "--color-surface", 4.5],
  ["nav link on surface", "--color-anchor", "--color-surface", 4.5],
  ["nav hover on blue wash", "--color-action-hover", "--color-wash-action-subtle", 4.5],
  ["search label on surface", "--color-text-muted", "--color-surface", 4.5],
  ["locale current on anchor", "--color-surface", "--color-anchor", 4.5],
  ["locale current on white plate", "--color-navy-900", "--color-surface", 4.5],

  // Hero and the dark bands
  ["hero kicker cyan on navy-900", "--color-accent", "--color-navy-900", 4.5],
  ["hero lead on navy-900", "--color-on-dark-lead", "--color-navy-900", 4.5],
  ["hero title white on navy-900", "--color-surface", "--color-navy-900", 4.5],
  ["hero support text on navy-900", "--color-on-dark", "--color-navy-900", 4.5],
  ["hero ghost button text on navy-900", "--color-surface", "--color-navy-900", 4.5],
  ["cta band lead on navy-950", "--color-on-dark-lead", "--color-navy-950", 4.5],
  ["cta band white on navy-950", "--color-surface", "--color-navy-950", 4.5],

  // Footer
  ["footer body on navy-950", "--color-on-dark-body", "--color-navy-950", 4.5],
  ["footer link on navy-950", "--color-on-dark", "--color-navy-950", 4.5],
  ["footer address on navy-950", "--color-on-dark-address", "--color-navy-950", 4.5],
  ["footer meta on navy-950", "--color-on-dark-meta", "--color-navy-950", 4.5],
  ["footer heading white on navy-950", "--color-surface", "--color-navy-950", 4.5],

  // Notices
  ["alert text on info wash", "--color-anchor", "--color-info-wash", 4.5],
  ["warning ink on warning wash", "--color-warning-ink", "--color-warning-wash", 4.5],
  ["error ink on error wash", "--color-error-ink", "--color-error-wash", 4.5],
  ["warning status on warning wash", "--color-warning", "--color-warning-wash", 4.5],
  ["error status on error wash", "--color-error", "--color-error-wash", 4.5],
  ["success status on success wash", "--color-success", "--color-success-wash", 4.5],
  ["material note text on surface-alt", "--color-text-muted", "--color-surface-alt", 4.5],

  // Records, tables, people
  ["table head on surface", "--color-text-soft", "--color-surface", 4.5],
  ["course code on surface", "--color-anchor", "--color-surface", 4.5],
  ["event date on anchor plate", "--color-surface", "--color-anchor", 4.5],
  ["monogram initials on anchor", "--color-surface", "--color-anchor", 4.5],
  ["card body muted on surface", "--color-text-muted", "--color-surface", 4.5],
  ["timeline year on canvas", "--color-anchor", "--color-canvas", 4.5],
  ["partner name on surface", "--color-text-muted", "--color-surface", 4.5],
  ["memo monogram on slate-600", "--color-surface", "--color-slate-600", 4.5],

  // UI graphics (3:1) and the deliberately quiet hairline
  ["focus ring on surface", "--color-action", "--color-surface", 3],
  ["focus ring on canvas", "--color-action", "--color-canvas", 3],
  ["focus ring on anchor", "--color-focus-on-dark", "--color-anchor", 3],
  ["input border on surface", "--color-boundary-strong", "--color-surface", 3],
  ["kicker index bar on surface", "--color-cyan-500", "--color-surface", 3],
  ["nav current bar on surface", "--color-cyan-500", "--color-surface", 3],
  ["rule hairline on surface (non-essential)", "--color-rule-quiet", "--color-surface", 1],
];

let failures = 0;
const rows = pairs.map(([name, foreground, background, min]) => {
  const fg = resolve(foreground);
  const bg = resolve(background);
  const composited = fg[3] < 1 ? over(fg, bg) : fg;
  const value = ratio(composited, bg);
  const pass = value >= min;
  if (!pass) failures += 1;
  return { name, fg: foreground, bg: background, min, value: value.toFixed(2), pass };
});

const width = Math.max(...rows.map((row) => row.name.length));
for (const row of rows) {
  const flag = row.pass ? "PASS" : "FAIL";
  console.log(
    `${flag}  ${row.name.padEnd(width)}  ${String(row.value).padStart(6)}:1  (min ${row.min})  ${row.fg} on ${row.bg}`,
  );
}
console.log(`\n${rows.length - failures}/${rows.length} pairs meet their target.`);
if (failures > 0) {
  console.error(`${failures} contrast pair(s) below target.`);
  process.exitCode = 1;
}
