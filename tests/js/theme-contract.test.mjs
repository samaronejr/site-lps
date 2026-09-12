import { readFileSync } from "node:fs";
import { describe, expect, test } from "vitest";

const themeRoot = "wp-content/themes/lps-theme";
const read = (path) => readFileSync(`${themeRoot}/${path}`, "utf8");

describe("LPS block theme contract", () => {
  test("declares the binding palette, typography, spacing, and square surface settings", () => {
    // Given: the production block theme configuration.
    // When: theme.json is parsed.
    const theme = JSON.parse(read("theme.json"));

    // Then: editor choices resolve to the approved machine tokens.
    expect(theme.version).toBe(3);
    expect(theme.settings.color.custom).toBe(false);
    expect(theme.settings.spacing.customSpacingSize).toBe(false);
    expect(theme.settings.typography.customFontSize).toBe(false);
    expect(theme.settings.color.palette).toHaveLength(16);
    expect(theme.settings.typography.fontFamilies.map(({ slug }) => slug)).toEqual([
      "editorial",
      "interface",
      "mono",
    ]);
    expect(theme.settings.typography.fontSizes.map(({ slug }) => slug)).toEqual([
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
    ]);
    expect(theme.settings.spacing.spacingSizes.map(({ slug }) => slug)).toEqual([
      "1",
      "2",
      "3",
      "4",
      "5",
      "6",
      "8",
      "10",
      "12",
      "16",
      "20",
      "24",
    ]);
  });

  test("ships local licensed fonts and contains no external runtime asset URL", () => {
    // Given: the theme stylesheet and bundled font license.
    // When: their runtime references are inspected.
    const css = read("assets/css/theme.css");
    const license = read("assets/fonts/OFL.txt");

    // Then: fonts are local and the theme has no remote imports or copied logo asset.
    expect(css).toContain("../fonts/source-serif-4-regular.woff2");
    expect(css).toContain("../fonts/source-serif-4-semibold.woff2");
    expect(css).not.toMatch(/@import|url\(["']?https?:/i);
    expect(license).toContain("SIL OPEN FONT LICENSE Version 1.1");
  });

  test("renders the native shell disclosure open so desktop and no-JS controls stay available", () => {
    // Given: the server-rendered global shell implementation.
    // When: its disclosure markup is inspected before any client behavior runs.
    const shell = read("includes/class-shell.php");

    // Then: native descendants remain exposed in every browser and without JavaScript.
    expect(shell).toContain('<details class="lps-shell-disclosure" open>');
  });

  test("locks the global structure while preserving editable post content", () => {
    // Given: every global template and editor pattern.
    // When: their block markup is inspected.
    const templates = [
      "index.html",
      "page.html",
      "single.html",
      "archive.html",
      "search.html",
      "404.html",
    ];
    const patterns = ["page-shell.php", "editorial-section.php", "empty-state.php"];

    // Then: shell blocks cannot be moved or removed and content remains represented by core blocks.
    for (const template of templates) {
      const markup = read(`templates/${template}`);
      expect(markup).toContain('"lock":{"move":true,"remove":true}');
      expect(markup).toContain("lps-theme/header");
      expect(markup).toContain("lps-theme/footer");
    }
    for (const pattern of patterns) expect(read(`patterns/${pattern}`)).toContain("Block Types:");
    expect(read("templates/page.html")).toContain("wp:post-content");
    expect(read("templates/search.html")).toContain("wp:query-no-results");
  });
});
