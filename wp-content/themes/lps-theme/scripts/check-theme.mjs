import { access, readdir, readFile } from "node:fs/promises";
import { resolve } from "node:path";

const themeRoot = resolve(new URL("../", import.meta.url).pathname);

export async function checkTheme() {
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

  for (const match of css.matchAll(/#[0-9a-f]{3,8}\b|\brgba?\([^)]*\)|\bhsla?\([^)]*\)/gi)) {
    if (!insideRoot(match.index)) add("UNTOKENIZED_COLOR", match[0]);
  }
  for (const match of css.matchAll(/box-shadow\s*:\s*([^;]+);/gi)) {
    if (!/var\(--shadow-none\)/.test(match[1])) add("SHADOW_SURFACE", match[1].trim());
  }
  for (const match of css.matchAll(/border-radius\s*:\s*([^;]+);/gi)) {
    if (!/var\(--radius-square\)|^0$/i.test(match[1].trim()))
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
        await Promise.all(
          templateNames.map((name) => readFile(`${themeRoot}/templates/${name}`, "utf8")),
        )
      ).join("\n"),
    )
  ) {
    add("EXTERNAL_RUNTIME_ASSET", "remote template asset");
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
  for (const name of requiredTemplates.filter((name) => templateNames.includes(name))) {
    const markup = await readFile(`${themeRoot}/templates/${name}`, "utf8");
    if (!markup.includes('"lock":{"move":true,"remove":true}')) add("UNLOCKED_TEMPLATE", name);
  }
  // The required font files are the ones the stylesheet actually references, so
  // repackaging the family (for example subsetting to woff2) cannot silently drop
  // an asset and cannot fail the gate for a format the theme no longer ships.
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

  if (theme.settings.color.custom !== false) add("CUSTOM_COLOR_ENABLED", "theme.json");
  if (theme.settings.typography.customFontSize !== false) add("CUSTOM_TYPE_ENABLED", "theme.json");
  if (theme.settings.spacing.customSpacingSize !== false)
    add("CUSTOM_SPACING_ENABLED", "theme.json");

  // Required tokens of the light institutional system (DESIGN.md §3-§5, §8,
  // §10): the 18-slug palette roles, the three font roles, the grid and
  // geometry primitives, and the motion tokens. Absence fails the gate.
  const requiredTokens = [
    "--color-paper",
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
    "--font-interface",
    "--font-editorial",
    "--font-mono",
    "--grid-max",
    "--grid-gutter",
    "--motion-fast",
    "--motion-standard",
    "--ease-state",
    "--radius-square",
    "--shadow-none",
  ];
  for (const token of requiredTokens) {
    if (!css.includes(`${token}:`)) add("MISSING_TOKEN", token);
  }

  // Sans-led hierarchy: the h1-h4 block must resolve to the interface stack,
  // and no heading rule may reintroduce the serif outside reading containers.
  if (
    !/h1,\s*\n?\s*h2,\s*\n?\s*h3,\s*\n?\s*h4\s*\{[^}]*font-family:\s*var\(--font-interface\)/s.test(
      css,
    )
  ) {
    add("HEADING_STACK", "headings must resolve to --font-interface");
  }
  for (const match of css.matchAll(/([^{}]+)\{[^{}]*font-family:\s*var\(--font-editorial\)/g)) {
    const selector = match[1];
    if (/h[1-6]/.test(selector) && !selector.includes(".")) {
      add("HEADING_STACK", `serif headings outside reading containers: ${selector.trim()}`);
    }
  }

  // The institutional palette is the 18-slug set; every role must be present.
  const requiredSlugs = [
    "paper",
    "paper-raised",
    "paper-muted",
    "ink",
    "ink-soft",
    "navy",
    "navy-hover",
    "signal",
    "signal-hover",
    "rule",
    "rule-strong",
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
