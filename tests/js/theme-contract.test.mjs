import { readFileSync } from "node:fs";
import { describe, expect, test } from "vitest";

const themeRoot = "wp-content/themes/lps-theme";
const read = (path) => readFileSync(`${themeRoot}/${path}`, "utf8");

describe("LPS block theme contract", () => {
  test("declares the binding palette, typography, spacing, and control-radius settings", () => {
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

    // Then: the unified canvas carries the full status set, washes included.
    // The dark-surface focus token is a CSS-only primitive, not a palette slug.
    expect(theme.settings.color.palette).toHaveLength(17);
    expect(theme.settings.color.palette.map(({ slug }) => slug)).toEqual([
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
    ]);
    const colors = Object.fromEntries(
      theme.settings.color.palette.map(({ slug, color }) => [slug, color]),
    );
    expect(colors).toMatchObject({
      canvas: "#F5F7FA",
      surface: "#FFFFFF",
      anchor: "#12304A",
      "anchor-deep": "#0C2237",
      action: "#165A96",
      "action-hover": "#0F4A7E",
      text: "#182B3A",
      "text-muted": "#526477",
      "rule-quiet": "#D7E0E8",
      "boundary-strong": "#74869A",
      success: "#216E4E",
      warning: "#7A4A00",
      error: "#A12622",
      "info-wash": "#DDECEF",
      "success-wash": "#E0ECE5",
      "warning-wash": "#F2E8D2",
      "error-wash": "#F2DEDA",
    });

    // Then: the sans-led interface role leads and the mono is scoped to
    // identifiers; the retired serif role ships no family and no font files.
    expect(theme.settings.typography.fontFamilies.map(({ slug }) => slug)).toEqual([
      "interface",
      "mono",
    ]);
    const families = Object.fromEntries(
      theme.settings.typography.fontFamilies.map(({ slug, ...rest }) => [slug, rest]),
    );
    expect(families.interface.fontFamily).toContain("IBM Plex Sans");
    expect(families.interface.fontFamily).toContain("system-ui");
    expect(families.interface.fontFace).toHaveLength(4);
    expect(families.mono.fontFamily).toContain("IBM Plex Mono");
    expect(families.mono.fontFamily).toContain("ui-monospace");
    expect(families.mono.fontFace).toHaveLength(2);
    for (const family of theme.settings.typography.fontFamilies) {
      for (const face of family.fontFace ?? []) {
        expect(face.fontDisplay).toBe("swap");
        for (const src of face.src) expect(src).toMatch(/^file:\.\/assets\/fonts\/.+\.woff2$/);
      }
    }

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
      background: "var:preset|color|canvas",
      text: "var:preset|color|text",
    });
    expect(styles.spacing.blockGap).toBe("var:preset|spacing|6");
    expect(styles.typography.fontFamily).toBe("var:preset|font-family|interface");
    expect(styles.typography.fontSize).toBe("var:preset|font-size|body");

    // Then: headings are sans-led with the contracted per-level weights.
    expect(styles.elements.heading.color.text).toBe("var:preset|color|text");
    expect(styles.elements.heading.typography.fontFamily).toBe("var:preset|font-family|interface");
    expect(styles.elements.h1.typography.fontFamily).toBe("var:preset|font-family|interface");
    expect(styles.elements.h1.typography.fontWeight).toBe("700");
    for (const level of ["h2", "h3", "h4"]) {
      expect(styles.elements[level].typography.fontFamily).toBe("var:preset|font-family|interface");
      expect(styles.elements[level].typography.fontWeight).toBe("650");
    }

    // Then: long-form reading stays on the interface family at the reading
    // size; the retired serif role is not reintroduced in any block style.
    expect(styles.blocks["core/post-content"].typography.fontFamily).toBe(
      "var:preset|font-family|interface",
    );
    expect(styles.blocks["core/post-content"].typography.fontSize).toBe(
      "var:preset|font-size|reading",
    );

    // Then: links and buttons keep the institutional action treatment.
    expect(styles.elements.link.color.text).toBe("var:preset|color|action");
    expect(styles.elements.button.border.radius).toBe("4px");
    expect(styles.elements.button.color).toEqual({
      background: "var:preset|color|action",
      text: "var:preset|color|surface",
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

    // Then: fonts are local woff2 with swap, and the theme has no remote
    // imports or copied logo asset.
    for (const face of [
      "ibm-plex-sans-regular.woff2",
      "ibm-plex-sans-medium.woff2",
      "ibm-plex-sans-semibold.woff2",
      "ibm-plex-sans-bold.woff2",
      "ibm-plex-mono-regular.woff2",
      "ibm-plex-mono-semibold.woff2",
    ]) {
      expect(css).toContain(`../fonts/${face}`);
    }
    expect(css).not.toMatch(/@import|url\(["']?https?:/i);
    expect(license).toContain("SIL OPEN FONT LICENSE Version 1.1");
    expect(license).toContain('Reserved Font Name "Plex"');
  });

  test("renders the native shell disclosure closed on mobile and always open on desktop", () => {
    // Given: the server-rendered global shell implementation and stylesheet.
    // When: the disclosure markup and its desktop override are inspected.
    const shell = read("includes/class-shell.php");
    const css = read("assets/css/theme.css");

    // Then: the disclosure ships closed so the mobile header stays compact, the
    // native summary toggles it without JavaScript, and the desktop rules keep
    // the panel visible under both the legacy and the ::details-content model.
    expect(shell).toContain('<details class="lps-shell-disclosure">');
    expect(shell).not.toContain('class="lps-shell-disclosure" open');
    expect(css).toContain(".lps-shell-disclosure:not([open]) > .lps-nav-panel");
    expect(css).toContain(".lps-shell-disclosure::details-content");
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
