import { access, readdir, readFile } from "node:fs/promises";
import { dirname, relative, resolve } from "node:path";
import {
  auditMarkup,
  auditStylesheet,
  auditSvgSource,
  auditThemeJson,
  collectLocalSvgReferences,
} from "../../../../scripts/lib/design-guardrails.mjs";

const themeRoot = resolve(new URL("../", import.meta.url).pathname);

/**
 * Tokens retired by the unified-identity contract (DESIGN.md supersede table).
 * Their reappearance in the stylesheet or theme.json is a finding, not a
 * silent alias: the replacement world does not keep the old names alive.
 */
const RETIRED_TOKENS = [
  "--color-paper",
  "--color-paper-raised",
  "--color-paper-muted",
  "--color-ink",
  "--color-ink-soft",
  "--color-navy",
  "--color-navy-hover",
  "--color-signal",
  "--color-signal-hover",
  "--color-rule",
  "--color-rule-strong",
  "--color-focus-offset",
  "--font-editorial",
  "--radius-square",
];

const RETIRED_FAMILIES = ["Source Serif 4", "source-serif"];

/**
 * Audits one theme directory. `root` defaults to the shipped theme; tests may
 * point it at a copied, mutated fixture root. Contract-sync checks run only
 * when docs/design/design-contract.json exists beside the theme's repo root,
 * so an isolated fixture copy is never failed for a contract it cannot see.
 */
export async function checkTheme(root = themeRoot) {
  const [css, themeSource, templateNames, partNames, patternNames] = await Promise.all([
    readFile(`${root}/assets/css/theme.css`, "utf8"),
    readFile(`${root}/theme.json`, "utf8"),
    readdir(`${root}/templates`),
    readdir(`${root}/parts`),
    readdir(`${root}/patterns`),
  ]);
  const theme = JSON.parse(themeSource);
  const findings = [];
  const add = (code, detail) => findings.push({ code, detail });
  const rootRanges = [...css.matchAll(/(?:^|\})\s*:root\s*\{([^}]*)\}/gms)].map((match) => [
    match.index,
    match.index + match[0].length,
  ]);
  const insideRoot = (index) => rootRanges.some(([start, end]) => index >= start && index <= end);

  for (const match of css.matchAll(/#[0-9a-f]{3,8}\b|\brgba?\([^)]*\)|\bhsla?\([^)]*\)/gi)) {
    if (!insideRoot(match.index)) add("UNTOKENIZED_COLOR", match[0]);
  }
  for (const match of css.matchAll(/(?:box|text)-shadow\s*:\s*([^;]+);/gi)) {
    if (!/var\(--shadow-none\)/.test(match[1])) add("SHADOW_SURFACE", match[1].trim());
  }
  // The contract permits exactly two radii: the 4px control radius and the
  // rectangular image radius. A literal or any other token is a finding.
  for (const match of css.matchAll(/border-radius\s*:\s*([^;]+);/gi)) {
    if (!/var\(--radius-(?:control|image)\)|^0$/i.test(match[1].trim()))
      add("ROUNDED_SURFACE", match[1].trim());
  }
  if (/transition\s*:\s*all\b/i.test(css)) add("TRANSITION_ALL", "transition: all");
  if (
    /\b(?:transition|animation)(?:-[a-z-]+)?\s*:/i.test(css) &&
    !/@media\s*\(prefers-reduced-motion:\s*reduce\)/i.test(css)
  ) {
    add("MISSING_REDUCED_MOTION", "motion without reduced-motion override");
  }
  if (/@import|url\(["']?https?:/i.test(css)) add("EXTERNAL_RUNTIME_ASSET", "remote CSS asset");
  if (
    /<(?:img|script|link)\b[^>]+https?:/i.test(
      (
        await Promise.all(templateNames.map((name) => readFile(`${root}/templates/${name}`, "utf8")))
      ).join("\n"),
    )
  ) {
    add("EXTERNAL_RUNTIME_ASSET", "remote template asset");
  }

  // Self-hosted font delivery: every @font-face must swap and must reference a
  // packaged woff2 — never a remote URL or an unpackaged format.
  for (const match of css.matchAll(/@font-face\s*\{([^}]*)\}/gi)) {
    const block = match[1];
    if (!/font-display\s*:\s*swap/i.test(block)) {
      add("FONT_DISPLAY_MISSING", block.trim().slice(0, 80));
    }
    for (const src of block.matchAll(/src\s*:\s*([^;]+);/gi)) {
      if (/https?:/i.test(src[1]) || !/url\(\s*["']?\.\.\/fonts\//i.test(src[1])) {
        add("REMOTE_FONT_SOURCE", src[1].trim());
      }
    }
  }

  const requiredTemplates = [
    "index.html",
    "page.html",
    "single.html",
    "archive.html",
    "search.html",
    "404.html",
  ];
  for (const name of requiredTemplates) {
    if (!templateNames.includes(name)) add("MISSING_TEMPLATE", name);
  }
  // Every shipped template, part and pattern must carry the structural lock
  // on every block; the required list above only proves presence, so the lock
  // audit runs over the full directories rather than the six required names,
  // and a single locked block cannot mask an unlocked sibling. The same pass
  // applies the color, shadow and radius bans to markup, which the CSS scan
  // cannot see: fragment targets are stripped first so href="#main" and
  // url(#gradient) are not mistaken for hex literals, and the block-JSON
  // "radius" attribute is held to the same zero-or-4px rule as the
  // border-radius property.
  const markupFiles = [
    ...templateNames.map((name) => `templates/${name}`),
    ...partNames.map((name) => `parts/${name}`),
    ...patternNames.map((name) => `patterns/${name}`),
  ];
  for (const relative of markupFiles) {
    const markup = await readFile(`${root}/${relative}`, "utf8");
    const blocks = markup.match(/<!--\s*wp:(?!\/)[\s\S]*?-->/g) ?? [];
    if (blocks.length === 0) add("UNLOCKED_TEMPLATE", `${relative}: no locked blocks`);
    for (const block of blocks) {
      if (!block.includes('"lock":{"move":true,"remove":true}')) {
        const name = /wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)/.exec(block)?.[1] ?? "block";
        add("UNLOCKED_TEMPLATE", `${relative}: ${name}`);
      }
    }
    const colorScan = markup.replace(/(?:href|src)="#[^"]*"|url\(#[^)]*\)/g, "");
    for (const match of colorScan.matchAll(
      /#[0-9a-f]{3,8}\b|\brgba?\([^)]*\)|\bhsla?\([^)]*\)/gi,
    )) {
      add("UNTOKENIZED_COLOR", `${relative}: ${match[0]}`);
    }
    for (const match of markup.matchAll(/(?:box|text)-shadow\s*:\s*([^;"<]+)/gi)) {
      if (!/var\(--shadow-none\)/.test(match[1])) {
        add("SHADOW_SURFACE", `${relative}: ${match[1].trim()}`);
      }
    }
    for (const match of markup.matchAll(/border-radius\s*:\s*([^;"<]+)/gi)) {
      if (!/var\(--radius-(?:control|image)\)|^0(?:px)?$/i.test(match[1].trim())) {
        add("ROUNDED_SURFACE", `${relative}: ${match[1].trim()}`);
      }
    }
    // Block-JSON radius accepts only the contract's 4px control radius or an
    // explicit zero; anything else is a rounded surface.
    for (const match of markup.matchAll(/"radius"\s*:\s*"([^"]*)"/g)) {
      if (!/^(|0|0px|4px|0\.25rem)$/.test(match[1])) {
        add("ROUNDED_SURFACE", `${relative}: "radius":"${match[1]}"`);
      }
    }
  }
  // The required font files are the ones the stylesheet actually references, so
  // repackaging the family (for example subsetting to woff2) cannot silently drop
  // an asset and cannot fail the gate for a format the theme no longer ships.
  const referencedFonts = [...css.matchAll(/url\(["']?\.\.\/fonts\/([^"')]+)["']?\)/g)].map(
    (match) => `assets/fonts/${match[1]}`,
  );
  for (const path of [...new Set([...referencedFonts, "assets/fonts/OFL.txt"])]) {
    try {
      await access(`${root}/${path}`);
    } catch {
      add("MISSING_FONT_ASSET", path);
    }
  }

  if (theme.settings.color.custom !== false) add("CUSTOM_COLOR_ENABLED", "theme.json");
  if (theme.settings.typography.customFontSize !== false) add("CUSTOM_TYPE_ENABLED", "theme.json");
  if (theme.settings.spacing.customSpacingSize !== false)
    add("CUSTOM_SPACING_ENABLED", "theme.json");

  // Required tokens of the unified identity (DESIGN.md §3-§5, §10-§11): the
  // 17-slug palette roles plus the dark-surface focus token, the two font
  // roles, the grid and geometry primitives, the rule primitives, and the
  // motion tokens. Absence fails the gate.
  const requiredTokens = [
    "--color-canvas",
    "--color-surface",
    "--color-anchor",
    "--color-anchor-deep",
    "--color-action",
    "--color-action-hover",
    "--color-text",
    "--color-text-muted",
    "--color-rule-quiet",
    "--color-boundary-strong",
    "--color-focus-on-dark",
    "--font-interface",
    "--font-mono",
    "--grid-max",
    "--grid-gutter",
    "--motion-fast",
    "--motion-standard",
    "--ease-state",
    "--radius-control",
    "--radius-image",
    "--rule-hairline",
    "--rule-boundary",
    "--rule-anchor",
    "--rule-focus",
    "--shadow-none",
  ];
  for (const token of requiredTokens) {
    if (!css.includes(`${token}:`)) add("MISSING_TOKEN", token);
  }

  // Retired tokens and families must not be redeclared or referenced. The
  // boundary class keeps --color-rule-quiet from tripping the --color-rule ban.
  for (const token of RETIRED_TOKENS) {
    const pattern = new RegExp(`${token.replace(/-/g, "\\-")}(?![\\w-])`, "g");
    if (pattern.test(css)) add("RETIRED_TOKEN", `theme.css: ${token}`);
    if (pattern.test(themeSource)) add("RETIRED_TOKEN", `theme.json: ${token}`);
  }
  for (const family of RETIRED_FAMILIES) {
    if (css.includes(family)) add("RETIRED_TOKEN", `theme.css: ${family}`);
    if (themeSource.includes(family)) add("RETIRED_TOKEN", `theme.json: ${family}`);
  }

  // Sans-led hierarchy: the h1-h4 block must resolve to the interface stack,
  // and no heading rule may reintroduce a non-interface family.
  if (
    !/h1,\s*\n?\s*h2,\s*\n?\s*h3,\s*\n?\s*h4\s*\{[^}]*font-family:\s*var\(--font-interface\)/s.test(
      css,
    )
  ) {
    add("HEADING_STACK", "headings must resolve to --font-interface");
  }
  for (const match of css.matchAll(/([^{}]+)\{[^{}]*font-family:\s*([^;}]+)/g)) {
    const selector = match[1];
    const family = match[2].trim();
    if (
      /h[1-6]/.test(selector) &&
      !selector.includes(".") &&
      !/var\(--font-interface\)/.test(family)
    ) {
      add("HEADING_STACK", `non-interface headings: ${selector.trim()}`);
    }
  }

  // The unified palette is the 17-slug set; every role must be present.
  const requiredSlugs = [
    "canvas",
    "surface",
    "anchor",
    "anchor-deep",
    "action",
    "action-hover",
    "text",
    "text-muted",
    "rule-quiet",
    "boundary-strong",
    "success",
    "warning",
    "error",
    "info-wash",
    "success-wash",
    "warning-wash",
    "error-wash",
  ];
  const paletteSlugs = new Set(theme.settings.color.palette.map(({ slug }) => slug));
  for (const slug of requiredSlugs) {
    if (!paletteSlugs.has(slug)) add("MISSING_PALETTE_SLUG", slug);
  }

  // theme.json styles consume presets only; a raw color there would compete
  // with the palette as a second token source.
  for (const match of JSON.stringify(theme.styles).matchAll(
    /#[0-9a-f]{3,8}\b|\brgba?\(|\bhsla?\(/gi,
  )) {
    add("UNTOKENIZED_COLOR", `theme.json styles: ${match[0]}`);
  }

  // Contract sync: when the machine-readable contract is present, every
  // contract color token must exist in :root with the contract value, every
  // family stack must match, and every scale size must match. This is the
  // check that makes theme/contract drift a hard failure rather than a doc
  // observation.
  try {
    const contract = JSON.parse(
      await readFile(resolve(root, "../../../docs/design/design-contract.json"), "utf8"),
    );
    const cssTokens = new Map();
    for (const range of rootRanges) {
      const body = css.slice(range[0], range[1]);
      for (const match of body.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) {
        cssTokens.set(match[1], match[2].trim());
      }
    }
    for (const token of contract.colors?.tokens ?? []) {
      const actual = cssTokens.get(token.token);
      if (actual === undefined) {
        add("CONTRACT_TOKEN_MISSING", token.token);
      } else if (actual.toLowerCase() !== token.value.toLowerCase()) {
        add("CONTRACT_VALUE_DRIFT", `${token.token}: contract ${token.value}, css ${actual}`);
      }
    }
    for (const family of contract.typography?.families ?? []) {
      const actual = cssTokens.get(family.token);
      if (actual === undefined) {
        add("CONTRACT_TOKEN_MISSING", family.token);
      } else if (actual.replace(/\s+/g, " ") !== family.stack.replace(/\s+/g, " ")) {
        add("CONTRACT_VALUE_DRIFT", `${family.token}: contract stack differs`);
      }
    }
    for (const step of contract.typography?.scale ?? []) {
      const actual = cssTokens.get(step.token);
      if (actual === undefined) {
        add("CONTRACT_TOKEN_MISSING", step.token);
      } else if (actual.replace(/\s+/g, "") !== step.size.replace(/\s+/g, "")) {
        add("CONTRACT_VALUE_DRIFT", `${step.token}: contract ${step.size}, css ${actual}`);
      }
    }
    for (const space of contract.spacing?.tokens ?? []) {
      const actual = cssTokens.get(space.token);
      if (actual === undefined) {
        add("CONTRACT_TOKEN_MISSING", space.token);
      } else if (actual !== space.value) {
        add("CONTRACT_VALUE_DRIFT", `${space.token}: contract ${space.value}, css ${actual}`);
      }
    }
    for (const motion of contract.motion?.tokens ?? []) {
      const actual = cssTokens.get(motion.token);
      if (actual === undefined) {
        add("CONTRACT_TOKEN_MISSING", motion.token);
      } else if (actual.replace(/\s+/g, "") !== motion.value.replace(/\s+/g, "")) {
        add("CONTRACT_VALUE_DRIFT", `${motion.token}: contract ${motion.value}, css ${actual}`);
      }
    }
  } catch {
    // No readable contract beside this root: an isolated fixture copy. The
    // sync gate applies to the real repository only.
  }

  // DESIGN.md 2/7/9 institutional-mark guardrails, applied to every surface the
  // theme actually ships: the stylesheet, theme.json, the block templates, and
  // every SVG asset either of them can reach.
  findings.push(...auditStylesheet({ source: css, file: "assets/css/theme.css" }));
  findings.push(...auditThemeJson({ theme, file: "theme.json" }));

  const scannedAssets = new Set();
  const auditAsset = async (fromFile, reference) => {
    const path = resolve(themeRoot, dirname(fromFile), reference);
    const key = relative(themeRoot, path);
    if (key.startsWith("..") || scannedAssets.has(key)) return;
    scannedAssets.add(key);
    try {
      findings.push(...auditSvgSource({ source: await readFile(path, "utf8"), file: key }));
    } catch {
      // An asset the theme does not ship is the missing-asset rules' business.
      // It cannot hide a waveform that is not on disk.
    }
  };

  for (const reference of collectLocalSvgReferences(css)) {
    await auditAsset("assets/css/theme.css", reference);
  }
  for (const name of templateNames) {
    const file = `templates/${name}`;
    const markup = await readFile(`${themeRoot}/${file}`, "utf8");
    findings.push(...auditMarkup({ source: markup, file }));
    for (const reference of collectLocalSvgReferences(markup)) await auditAsset(file, reference);
  }
  for (const entry of await readdir(themeRoot, { recursive: true })) {
    if (!entry.endsWith(".svg") || scannedAssets.has(entry)) continue;
    scannedAssets.add(entry);
    const source = await readFile(`${themeRoot}/${entry}`, "utf8");
    findings.push(...auditSvgSource({ source, file: entry }));
  }

  return {
    lane: "theme-design-system",
    status: findings.length === 0 ? "passed" : "failed",
    counts: {
      colors: theme.settings.color.palette.length,
      fontFamilies: theme.settings.typography.fontFamilies.length,
      fontSizes: theme.settings.typography.fontSizes.length,
      spacing: theme.settings.spacing.spacingSizes.length,
      templates: templateNames.length,
    },
    findingCount: findings.length,
    findings,
  };
}

if (process.argv[1] === new URL(import.meta.url).pathname) {
  const report = await checkTheme();
  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
  if (report.status !== "passed") process.exitCode = 1;
}
