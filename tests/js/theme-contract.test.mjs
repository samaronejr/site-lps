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
    expect(theme.settings.appearanceTools).toBe(false);
    expect(theme.settings.useRootPaddingAwareAlignments).toBe(true);
    expect(theme.settings.layout).toEqual({ contentSize: "68ch", wideSize: "80rem" });
    expect(theme.settings.color.custom).toBe(false);
    expect(theme.settings.color.customDuotone).toBe(false);
    expect(theme.settings.color.customGradient).toBe(false);
    expect(theme.settings.color.defaultDuotone).toBe(false);
    expect(theme.settings.color.defaultGradients).toBe(false);
    expect(theme.settings.color.defaultPalette).toBe(false);
    expect(theme.settings.spacing.customSpacingSize).toBe(false);
    expect(theme.settings.spacing.defaultSpacingSizes).toBe(false);
    expect(theme.settings.spacing.spacingScale).toBeUndefined();
    expect(theme.settings.typography.customFontSize).toBe(false);
    expect(theme.settings.typography.defaultFontSizes).toBe(false);
    expect(theme.settings.typography.fluid).toBe(true);
    expect(theme.settings.border).toEqual({
      color: false,
      radius: false,
      style: false,
      width: false,
    });
    expect(theme.settings.shadow).toEqual({ defaultPresets: false, presets: [] });
    expect(theme.settings.blocks["core/button"]).toEqual({
      border: { radius: false },
      color: { custom: false },
    });
    expect(theme.settings.blocks["core/group"]).toEqual({
      color: { custom: false },
      spacing: { padding: true, margin: true },
    });
    expect(theme.settings.blocks["core/image"]).toEqual({ border: { radius: false } });

    // Then: the light institutional canvas carries the full status set, washes included.
    expect(theme.settings.color.palette).toHaveLength(18);
    expect(theme.settings.color.palette.map(({ slug }) => slug)).toEqual([
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
    ]);
    const colors = Object.fromEntries(
      theme.settings.color.palette.map(({ slug, color }) => [slug, color]),
    );
    expect(colors).toMatchObject({
      paper: "#FFFFFF",
      "paper-raised": "#FFFFFF",
      "paper-muted": "#EFF1F4",
      ink: "#141A1F",
      "ink-soft": "#46515A",
      navy: "#003B5C",
      "navy-hover": "#002B44",
      signal: "#007A87",
      "signal-hover": "#005F69",
      rule: "#C9CDD1",
      "rule-strong": "#6F7A82",
      success: "#216E4E",
      warning: "#7A4A00",
      error: "#A12622",
      "info-wash": "#DDECEF",
      "success-wash": "#E0ECE5",
      "warning-wash": "#F2E8D2",
      "error-wash": "#F2DEDA",
    });

    // Then: the sans-led interface role leads, the reading serif stays self-hosted OFL.
    expect(theme.settings.typography.fontFamilies.map(({ slug }) => slug)).toEqual([
      "interface",
      "editorial",
      "mono",
    ]);
    const families = Object.fromEntries(
      theme.settings.typography.fontFamilies.map(({ slug, ...rest }) => [slug, rest]),
    );
    expect(families.interface.fontFamily).toContain("system-ui");
    expect(families.editorial.fontFamily).toContain("Source Serif 4");
    expect(families.editorial.fontFace).toHaveLength(2);
    expect(families.mono.fontFamily).toContain("ui-monospace");

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

  test("binds global and block styles to presets with the sans-led role remap", () => {
    // Given: the production block theme configuration.
    // When: theme.json global styles are parsed.
    const theme = JSON.parse(read("theme.json"));
    const styles = theme.styles;

    // Then: canvas, rhythm, and body copy resolve to presets, never literals.
    expect(styles.color).toEqual({
      background: "var:preset|color|paper",
      text: "var:preset|color|ink",
    });
    expect(styles.spacing.blockGap).toBe("var:preset|spacing|6");
    expect(styles.typography.fontFamily).toBe("var:preset|font-family|interface");
    expect(styles.typography.fontSize).toBe("var:preset|font-size|body");

    // Then: headings are sans-led with the contracted per-level weights.
    expect(styles.elements.heading.color.text).toBe("var:preset|color|ink");
    expect(styles.elements.heading.typography.fontFamily).toBe("var:preset|font-family|interface");
    for (const level of ["h1", "h2"]) {
      expect(styles.elements[level].typography.fontFamily).toBe("var:preset|font-family|interface");
      expect(styles.elements[level].typography.fontWeight).toBe("700");
    }
    for (const level of ["h3", "h4"]) {
      expect(styles.elements[level].typography.fontFamily).toBe("var:preset|font-family|interface");
      expect(styles.elements[level].typography.fontWeight).toBe("650");
    }

    // Then: the reading serif is scoped to the post-content container only.
    expect(styles.blocks["core/post-content"].typography.fontFamily).toBe(
      "var:preset|font-family|editorial",
    );
    expect(styles.blocks["core/post-content"].elements.heading.typography.fontFamily).toBe(
      "var:preset|font-family|editorial",
    );

    // Then: links and buttons keep the institutional signal/navy treatment.
    expect(styles.elements.link.color.text).toBe("var:preset|color|signal-hover");
    expect(styles.elements.button.border.radius).toBe("0");
    expect(styles.elements.button.color).toEqual({
      background: "var:preset|color|navy",
      text: "var:preset|color|paper-raised",
    });
    expect(styles.elements.button.typography.fontFamily).toBe("var:preset|font-family|interface");
    expect(styles.elements.button.typography.fontSize).toBe("var:preset|font-size|small");

    // Then: no style value bypasses the preset system with a raw color or CSS var.
    const serialized = JSON.stringify(styles);
    expect(serialized).not.toMatch(/#[0-9a-f]{3,8}\b|\brgba?\(|\bhsla?\(|var\(--/i);
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
