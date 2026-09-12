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
