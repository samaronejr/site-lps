import { readFileSync } from "node:fs";
import { describe, expect, test } from "vitest";
import {
  analysePathWaveform,
  auditMarkup,
  auditStylesheet,
  auditThemeJson,
  isClearedMark,
  scanDeclarations,
} from "../../scripts/lib/design-guardrails.mjs";

const codesOf = (findings) => findings.map(({ code }) => code);
const css = (source) => codesOf(auditStylesheet({ source, file: "fixture.css" }));
const markup = (source) => codesOf(auditMarkup({ source, file: "fixture.html" }));

/**
 * The cleared mark itself: the shipped Todo 32 full lockup, read from the
 * theme so the test exercises the real registered artwork digests (six
 * outlined paths, three registered gradients, normalised viewBox).
 */
const clearedMark = readFileSync(
  new URL("../../wp-content/themes/lps-theme/assets/img/mark/lps-mark-full.svg", import.meta.url),
  "utf8",
);

describe("brand tokens are mark-only (DESIGN.md 2, 9)", () => {
  test("declaring the brand tokens is legal", () => {
    expect(css(":root { --color-brand-deep: #094c92; --color-brand-accent: #00aff1; }")).toEqual(
      [],
    );
  });

  test("a brand token on a link, a focus ring, or a border is rejected", () => {
    expect(css("a { color: var(--color-brand-accent); }")).toEqual(["BRAND_COLOR_IN_UI"]);
    expect(css("a:focus-visible { outline: 3px solid var(--color-brand-accent); }")).toEqual([
      "BRAND_COLOR_IN_UI",
    ]);
    expect(css(".card { border-block-end: 1px solid var(--color-brand-deep); }")).toEqual([
      "BRAND_COLOR_IN_UI",
    ]);
  });

  test("renaming a brand token through an alias chain does not launder it", () => {
    const source = [
      ":root { --color-brand-accent: #00aff1; }",
      ":root { --tide: var(--color-brand-accent); --surf: var(--tide); }",
      ".band { background-color: var(--surf); }",
    ].join("\n");
    expect(css(source)).toEqual(["BRAND_COLOR_IN_UI"]);
  });

  test("the brand values are recognised as rgb() as well as hex", () => {
    expect(css(".band { color: rgb(0, 175, 241); }")).toContain("BRAND_COLOR_IN_UI");
    expect(css(".band { color: rgb(9 76 146); }")).toContain("BRAND_COLOR_IN_UI");
  });

  test("approved palette colours are untouched", () => {
    expect(css("a { color: var(--color-signal); } .card { border-color: #C9CDD1; }")).toEqual([]);
  });
});

describe("gradients are rejected on every surface (DESIGN.md 7)", () => {
  test("every gradient function is rejected, in any property", () => {
    for (const value of [
      "linear-gradient(180deg, var(--color-paper), var(--color-paper-muted))",
      "radial-gradient(circle, var(--color-paper), var(--color-ink))",
      "conic-gradient(from 0deg, var(--color-paper), var(--color-ink))",
      "repeating-linear-gradient(45deg, var(--color-paper) 0 4px, var(--color-ink) 4px 8px)",
    ]) {
      expect(css(`.card { background-image: ${value}; }`)).toEqual(["GRADIENT_ON_SURFACE"]);
    }
  });

  test("defining a gradient as a token is rejected too, not only using one", () => {
    expect(css(":root { --hero-fade: linear-gradient(#fff, #000); }")).toContain(
      "GRADIENT_ON_SURFACE",
    );
  });

  test("an SVG paint-server gradient outside the mark is rejected", () => {
    const source = '<svg viewBox="0 0 10 10"><linearGradient id="band"/><path d="M0 0h10"/></svg>';
    expect(markup(source)).toEqual(["GRADIENT_ON_SURFACE"]);
  });

  test("the mark's own wave-gradient inside the mark artwork is legal", () => {
    expect(isClearedMark(clearedMark)).toBe(true);
    expect(markup(clearedMark)).toEqual([]);
  });

  test("a foreign gradient id inside the mark artwork is still rejected", () => {
    const tampered = clearedMark.replace("wave-gradient", "hero-gradient");
    expect(isClearedMark(tampered)).toBe(false);
    expect(markup(tampered)).toContain("GRADIENT_ON_SURFACE");
  });

  test("a re-stopped gradient inside the mark artwork is still rejected", () => {
    // Same id, altered artwork: the digest test catches what an id test cannot.
    const tampered = clearedMark.replace('offset="0.01256"', 'offset="0.5"');
    expect(isClearedMark(tampered)).toBe(false);
    expect(markup(tampered)).toContain("GRADIENT_ON_SURFACE");
  });
});

describe("waveform ornament is rejected by geometry, not by name (DESIGN.md 7, 9)", () => {
  test("an oscillating path is a waveform", () => {
    const analysis = analysePathWaveform("M0 60 Q150 0 300 60 T600 60 T900 60 T1200 60 V120 H0 Z");
    expect(analysis.waveform).toBe(true);
    expect(analysis.alternations).toBeGreaterThanOrEqual(3);
  });

  test("a neutrally named, monochrome, gradient-free wave band is still caught", () => {
    const source =
      '<svg class="section-band" aria-hidden="true" viewBox="0 0 1200 120">' +
      '<path d="M0 60 Q150 0 300 60 T600 60 T900 60 T1200 60 V120 H0 Z" fill="currentColor"/></svg>';
    expect(markup(source)).toEqual(["WAVEFORM_ORNAMENT"]);
  });

  test("icons, polylines, circles and rounded corners are not waveforms", () => {
    for (const data of [
      "M8 16 16 8M10 8h6v6M14 4h6v6M20 14v6H4V4h6",
      "M72 290 200 242 328 180 456 130 584 92 712 72",
      "m5 12 4 4L19 6",
      "M10 0 C15.5 0 20 4.5 20 10 C20 15.5 15.5 20 10 20 C4.5 20 0 15.5 0 10 C0 4.5 4.5 0 10 0Z",
      "M4 0h12a4 4 0 0 1 4 4v12a4 4 0 0 1-4 4H4a4 4 0 0 1-4-4V4a4 4 0 0 1 4-4z",
    ]) {
      expect(analysePathWaveform(data).waveform, data).toBe(false);
    }
  });

  test("the cleared mark's own wave is exempt, an altered copy that is not the mark is not", () => {
    expect(markup(clearedMark)).toEqual([]);
    // One changed coordinate in the waveform path is enough to break the
    // registered artwork digest; the viewBox alone no longer grants identity.
    const impostor = clearedMark.replace("M303.51,466.46", "M303.52,466.46");
    expect(isClearedMark(impostor)).toBe(false);
    expect(markup(impostor)).toContain("WAVEFORM_ORNAMENT");
  });
});

describe("markup is not an escape hatch from the stylesheet rules", () => {
  test("an inline style attribute is audited like a stylesheet", () => {
    expect(markup('<div style="color: var(--color-brand-accent)"></div>')).toEqual([
      "BRAND_COLOR_IN_UI",
    ]);
    expect(markup('<div style="background-image: linear-gradient(#fff, #000)"></div>')).toEqual([
      "GRADIENT_ON_SURFACE",
    ]);
  });

  test("a brand colour on an SVG presentation attribute is rejected outside the mark", () => {
    expect(markup('<svg viewBox="0 0 10 10"><path d="M0 0h10" fill="#00aff1"/></svg>')).toEqual([
      "BRAND_COLOR_IN_UI",
    ]);
  });

  test("the same attribute inside the cleared mark artwork is legal", () => {
    expect(markup(clearedMark)).toEqual([]);
    expect(clearedMark).toContain('fill="#094c92"');
  });

  test("a brand colour on a non-artwork element inside the mark is still rejected", () => {
    const tampered = clearedMark.replace(
      "</svg>",
      '<rect width="10" height="10" fill="#00aff1"/></svg>',
    );
    expect(markup(tampered)).toContain("BRAND_COLOR_IN_UI");
  });

  test("a data: URI SVG smuggled through CSS is still audited", () => {
    const svg = '<svg viewBox="0 0 4 4"><linearGradient id="x"/><path d="M0 0h4"/></svg>';
    const source = `.band { background-image: url("data:image/svg+xml,${encodeURIComponent(svg)}"); }`;
    expect(css(source)).toContain("GRADIENT_ON_SURFACE");
  });
});

describe("theme.json is enforced as well as CSS (DESIGN.md 2)", () => {
  const base = {
    settings: {
      custom: { colorBrandDeep: "#094c92", colorBrandAccent: "#00aff1" },
      color: { palette: [{ slug: "signal", name: "Signal", color: "#007A87" }] },
    },
    styles: { elements: { link: { color: { text: "var:preset|color|signal" } } } },
  };
  const clone = () => structuredClone(base);
  const audit = (theme) => codesOf(auditThemeJson({ theme, file: "theme.json" }));

  test("brand tokens under settings.custom are legal", () => {
    expect(audit(clone())).toEqual([]);
  });

  test("a brand colour in any palette is rejected", () => {
    const theme = clone();
    theme.settings.color.palette.push({ slug: "brand-accent", name: "Brand", color: "#00aff1" });
    expect(audit(theme)).toContain("BRAND_COLOR_IN_PALETTE");
  });

  test("the indirect path - palette preset reached from styles.elements - is rejected", () => {
    const theme = clone();
    theme.settings.color.palette.push({ slug: "brand-accent", name: "Brand", color: "#00aff1" });
    theme.styles.elements.link.color.text = "var:preset|color|brand-accent";
    const codes = audit(theme);
    expect(codes).toContain("BRAND_COLOR_IN_PALETTE");
    expect(codes).toContain("BRAND_COLOR_IN_UI");
  });

  test("a styles value reaching settings.custom by var() is rejected", () => {
    const theme = clone();
    theme.styles.elements.link.color.text = "var(--wp--custom--color-brand-deep)";
    expect(audit(theme)).toEqual(["BRAND_COLOR_IN_UI"]);
  });

  test("a literal brand hex in styles is rejected", () => {
    const theme = clone();
    theme.styles.elements.link.color.text = "#00aff1";
    expect(audit(theme)).toEqual(["BRAND_COLOR_IN_UI"]);
  });

  test("a gradient anywhere in theme.json is rejected", () => {
    const theme = clone();
    theme.styles.color = { background: "linear-gradient(180deg, #fff, #000)" };
    expect(audit(theme)).toContain("GRADIENT_ON_SURFACE");
  });
});

describe("malformed input is rejected, never read as clean", () => {
  test("unbalanced braces are a finding", () => {
    expect(css(".truncated { color: var(--color-ink);")).toContain("MALFORMED_STYLESHEET");
  });

  test("a violation smuggled inside an unterminated :root block is still caught", () => {
    const source = [
      ":root {",
      "  --color-brand-accent: #00aff1;",
      "  .smuggled { color: var(--color-brand-accent); }",
      "}",
    ].join("\n");
    expect(css(source)).toContain("BRAND_COLOR_IN_UI");
  });

  test("the declaration scanner reports its own balance", () => {
    expect(scanDeclarations("a { color: red; }").balanced).toBe(true);
    expect(scanDeclarations("a { color: red;").balanced).toBe(false);
  });

  test("comments and quoted braces do not shift declarations or line numbers", () => {
    const source = [
      "/* } fake close */",
      'a::after { content: "}"; color: var(--color-ink); }',
    ].join("\n");
    const { declarations, balanced } = scanDeclarations(source);
    expect(balanced).toBe(true);
    expect(declarations.map(({ property, line }) => `${property}@${line}`)).toEqual([
      "content@2",
      "color@2",
    ]);
  });
});
