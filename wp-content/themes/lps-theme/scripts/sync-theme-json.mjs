#!/usr/bin/env node
/**
 * Keeps the block editor in step with the shipped stylesheet.
 *
 * `theme.json` is the editor's view of the design system; `assets/css/theme.css`
 * is the site's. They are two renderings of one set of values, so the palette,
 * the type scale, the spacing scale and the container width are generated here
 * from the contract (`docs/design/design-contract.json`) and from `:root` rather
 * than maintained by hand. Anything in `theme.json` the design system does not
 * own — font faces, block defaults, template parts — is left untouched.
 *
 * Usage:
 *   node wp-content/themes/lps-theme/scripts/sync-theme-json.mjs          # write
 *   node wp-content/themes/lps-theme/scripts/sync-theme-json.mjs --check  # verify only
 */

import { readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, "..", "..", "..", "..");
const themeJsonPath = resolve(repoRoot, "wp-content/themes/lps-theme/theme.json");
const cssPath = resolve(repoRoot, "wp-content/themes/lps-theme/assets/css/theme.css");
const contractPath = resolve(repoRoot, "docs/design/design-contract.json");

const theme = JSON.parse(readFileSync(themeJsonPath, "utf8"));
const contract = JSON.parse(readFileSync(contractPath, "utf8"));
const css = readFileSync(cssPath, "utf8");

/** Reads the `:root` token block: the stylesheet is the source of the values. */
function rootTokens(source) {
  const tokens = new Map();
  for (const range of source.matchAll(/(?:^|\})\s*:root\s*\{([^}]*)\}/gms)) {
    const body = range[1].replace(/\/\*[\s\S]*?\*\//g, " ");
    for (const match of body.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) {
      tokens.set(match[1], match[2].trim());
    }
  }
  return tokens;
}

const tokens = rootTokens(css);
const value = (token) => {
  const found = tokens.get(token);
  if (!found) throw new Error(`Token ${token} is not declared in :root.`);
  return found;
};

// --- Palette -------------------------------------------------------------------
// Exactly the contract palette, in contract order: one value per slug, and the
// slugs are the token names without their prefix. CSS-only primitives are not
// editor-facing and stay out of the palette.
const before = theme.settings.color.palette.map(({ slug }) => slug).join(",");
theme.settings.color.palette = contract.colors.tokens.map(({ slug, name, value: hex }) => ({
  slug,
  name,
  color: hex,
}));
const after = theme.settings.color.palette.map(({ slug }) => slug).join(",");
if (before !== after) {
  console.log(`palette: ${before.split(",").length} slugs -> ${after.split(",").length}`);
}

// --- Type scale ------------------------------------------------------------------
// The editor lists the scale from the smallest role up (metadata to display),
// which is the reading order the step names already imply — not the contract's
// display-first order, which is about how the scale is read as a specimen.
const EDITOR_TYPE_ORDER = [
  "meta",
  "small",
  "body",
  "reading",
  "lead",
  "h4",
  "h3",
  "h2",
  "h1",
  "display",
];
const byRole = new Map(contract.typography.scale.map((step) => [step.role, step]));
const missingRole = EDITOR_TYPE_ORDER.find((role) => !byRole.has(role));
if (missingRole) throw new Error(`Contract scale has no "${missingRole}" step.`);
theme.settings.typography.fontSizes = EDITOR_TYPE_ORDER.map((role) => {
  const step = byRole.get(role);
  const label = { h1: "Heading 1", meta: "Metadata", h4: "Heading 4" };
  return {
    slug: role,
    name: label[role] ?? role[0].toUpperCase() + role.slice(1),
    size: step.size,
    // Body copy is the one step that must not scale fluidly: the 16px floor.
    ...(role === "body" ? { fluid: false } : {}),
  };
});

// --- Spacing scale -----------------------------------------------------------------
theme.settings.spacing.spacingSizes = contract.spacing.tokens.map(({ token, value: size }) => ({
  slug: token.replace("--space-", ""),
  name: token.replace("--space-", ""),
  size,
}));

// --- Container ----------------------------------------------------------------------
theme.settings.layout.contentSize = value("--measure-reading");
theme.settings.layout.wideSize = contract.geometry.maxContentWidth.replace(/\s*\(.*\)$/, "");

// --- Styles ---------------------------------------------------------------------------
// Presets only: a raw colour or size here would be a second token source.
theme.styles.color = {
  background: "var:preset|color|canvas",
  text: "var:preset|color|text",
};
theme.styles.typography.fontFamily = "var:preset|font-family|interface";
theme.styles.typography.fontSize = "var:preset|font-size|body";
theme.styles.elements.heading.color = { text: "var:preset|color|text" };
theme.styles.elements.link.color = { text: "var:preset|color|action" };
// The control radius is a contract value, not a literal: the editor and the
// stylesheet must resolve to the same corner.
theme.styles.elements.button.border = {
  radius: contract.geometry.controlRadius.replace(/\s*\(.*\)$/, ""),
};
theme.styles.elements.button.color = {
  background: "var:preset|color|action",
  text: "var:preset|color|surface",
};

const serialized = `${JSON.stringify(theme, null, 2)}\n`;

if (process.argv.includes("--check")) {
  const current = readFileSync(themeJsonPath, "utf8");
  if (current !== serialized) {
    console.error("theme.json is out of date: run this script without --check.");
    process.exitCode = 1;
  } else {
    console.log("theme.json matches the contract.");
  }
} else {
  writeFileSync(themeJsonPath, serialized, "utf8");
  console.log(
    `theme.json synced — ${theme.settings.color.palette.length} palette slugs, ${theme.settings.typography.fontSizes.length} type steps, ${theme.settings.spacing.spacingSizes.length} spacing steps.`,
  );
}
