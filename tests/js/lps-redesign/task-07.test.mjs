import { cpSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { describe, expect, it } from "vitest";
import { auditStylesheet, contrastRatio, cssTokens } from "../../../scripts/lib/a11y.mjs";
import { loadContract } from "../../../scripts/lib/design-contract.mjs";
import { checkTheme } from "../../../wp-content/themes/lps-theme/scripts/check-theme.mjs";

const THEME = "wp-content/themes/lps-theme";
const CSS_PATH = `${THEME}/assets/css/theme.css`;
const CONTRACT_PATH = "docs/design/design-contract.json";
const FIXTURE = "tests/fixtures/lps-redesign/primitives.html";

const css = readFileSync(CSS_PATH, "utf8");
const theme = JSON.parse(readFileSync(`${THEME}/theme.json`, "utf8"));
const contract = loadContract(CONTRACT_PATH);
const tokens = cssTokens(css);
const codes = (report) => new Set(report.findings.map((finding) => finding.code));

/**
 * Copies the theme into a scratch root that mirrors the repository layout
 * (wp-content/themes/lps-theme beside docs/design/design-contract.json), so
 * the real checker — including the contract-sync gate — runs against the
 * mutated copy. Returns the checker's report.
 */
async function checkMutatedTheme(mutate) {
  const dir = mkdtempSync(path.join(tmpdir(), "lps-t7-theme-"));
  const root = path.join(dir, "wp-content/themes/lps-theme");
  try {
    cpSync(THEME, root, { recursive: true });
    cpSync(CONTRACT_PATH, path.join(dir, "docs/design/design-contract.json"), {
      recursive: false,
    });
    mutate(root);
    return await checkTheme(root);
  } finally {
    rmSync(dir, { force: true, recursive: true });
  }
}

describe("task-07: contract tokens are frozen identically in theme.json and theme.css", () => {
  it("every contract color token exists in :root with the contract value", () => {
    for (const token of contract.colors.tokens) {
      expect(tokens.get(token.token), token.token).toBe(token.value.toLowerCase());
    }
  });

  it("every contract family, scale step, spacing and motion token matches :root", () => {
    const squeeze = (value) => value.replace(/\s+/g, "");
    for (const family of contract.typography.families) {
      expect(squeeze(tokens.get(family.token) ?? ""), family.token).toBe(squeeze(family.stack));
    }
    for (const step of contract.typography.scale) {
      expect(squeeze(tokens.get(step.token) ?? ""), step.token).toBe(squeeze(step.size));
    }
    for (const space of contract.spacing.tokens) {
      expect(tokens.get(space.token), space.token).toBe(space.value);
    }
    for (const motion of contract.motion.tokens) {
      expect(squeeze(tokens.get(motion.token) ?? ""), motion.token).toBe(squeeze(motion.value));
    }
  });

  it("the theme.json palette is the contract palette — one token source, no second system", () => {
    const contractValues = new Map(
      contract.colors.tokens.map((t) => [t.token.replace("--color-", ""), t.value]),
    );
    // The dark-surface focus token is a CSS-only primitive, not a palette slug.
    contractValues.delete("focus-on-dark");
    const palette = Object.fromEntries(
      theme.settings.color.palette.map(({ slug, color }) => [slug, color]),
    );
    expect(Object.keys(palette).sort()).toEqual([...contractValues.keys()].sort());
    for (const [slug, value] of Object.entries(contractValues)) {
      expect(palette[slug], slug).toBe(value);
    }
    // No second token source may exist beside theme.json/theme.css.
    const pkg = JSON.parse(readFileSync("package.json", "utf8"));
    const deps = { ...pkg.dependencies, ...pkg.devDependencies };
    for (const name of Object.keys(deps)) {
      expect(name).not.toMatch(/tailwind|styled-components|@emotion|shadcn|sass$|less$/i);
    }
    expect(readFileSync(`${THEME}/theme.json`, "utf8")).not.toMatch(/tailwind|shadcn/i);
  });

  it("retired tokens and families are absent from the shipped theme", () => {
    for (const retired of [
      "--color-paper",
      "--color-ink",
      "--color-navy",
      "--color-signal",
      "--color-rule-strong",
      "--color-focus-offset",
      "--font-editorial",
      "--radius-square",
      "Source Serif",
      "source-serif",
    ]) {
      const pattern = new RegExp(`${retired.replace(/-/g, "\\-")}(?![\\w-])`);
      expect(pattern.test(css), `theme.css: ${retired}`).toBe(false);
      expect(pattern.test(JSON.stringify(theme)), `theme.json: ${retired}`).toBe(false);
    }
  });
});

describe("task-07: self-hosted IBM Plex delivery", () => {
  it("ships the six approved woff2 faces with swap and no remote font request", () => {
    const faces = [...css.matchAll(/@font-face\s*\{([^}]*)\}/g)].map((m) => m[1]);
    expect(faces).toHaveLength(6);
    for (const block of faces) {
      expect(block).toMatch(/font-display:\s*swap/);
      expect(block).toMatch(/url\("\.\.\/fonts\/ibm-plex-(sans|mono)-[a-z]+\.woff2"\)/);
    }
    expect(css).not.toMatch(/@import|url\(["']?https?:/i);
    expect(JSON.stringify(theme)).not.toMatch(/https?:\/\/[^"]*\.(?:woff2?|ttf|otf)/i);
  });

  it("keeps the OFL 1.1 license beside the vendored files and records the fallback", () => {
    const license = readFileSync(`${THEME}/assets/fonts/OFL.txt`, "utf8");
    expect(license).toContain("SIL OPEN FONT LICENSE Version 1.1");
    expect(license).toContain('Reserved Font Name "Plex"');
    // The recorded fallback: both stacks degrade to system families.
    expect(tokens.get("--font-interface")).toContain("system-ui");
    expect(tokens.get("--font-mono")).toContain("ui-monospace");
  });

  it("preloads only the two first-paint faces through the asset policy", () => {
    const policy = readFileSync(`${THEME}/includes/class-assetpolicy.php`, "utf8");
    expect(policy).toContain("ibm-plex-sans-regular.woff2");
    expect(policy).toContain("ibm-plex-sans-semibold.woff2");
    expect(policy).not.toContain("source-serif");
  });
});

describe("task-07: typography floor and editor alignment", () => {
  it("keeps body text at the 16px floor with the contracted line heights", () => {
    expect(tokens.get("--type-body")).toBe("1rem");
    expect(tokens.get("--type-reading")).toBe("1.125rem");
    // The body rhythm may be written literally or through its token; what the
    // floor cares about is the resolved ratio.
    const bodyBlock = /body\s*\{([^}]*)\}/.exec(css)?.[1] ?? "";
    const declared = /line-height:\s*([^;]+);/.exec(bodyBlock)?.[1].trim() ?? "";
    const resolved = declared.startsWith("var(")
      ? tokens.get(declared.slice(4, -1).trim())
      : declared;
    expect(Number(resolved)).toBe(1.6);
    // No font-size below the meta floor anywhere in the stylesheet.
    for (const match of css.matchAll(/font-size:\s*([\d.]+)(rem|px)/g)) {
      const px = match[2] === "rem" ? Number(match[1]) * 16 : Number(match[1]);
      expect(px, `font-size ${match[0]}`).toBeGreaterThanOrEqual(12);
    }
  });

  it("aligns editor and public typography on the same stylesheet and presets", () => {
    // The editor canvas consumes the identical stylesheet — no second editor
    // CSS may drift from the public tokens.
    const functions = readFileSync(`${THEME}/functions.php`, "utf8");
    expect(functions).toContain("add_theme_support( 'editor-styles' )");
    expect(functions).toContain("add_editor_style( 'assets/css/theme.css' )");
    // theme.json global styles bind the editor's own rendering to the same
    // presets the stylesheet consumes.
    expect(theme.styles.typography.fontFamily).toBe("var:preset|font-family|interface");
    expect(theme.styles.typography.fontSize).toBe("var:preset|font-size|body");
    expect(theme.styles.blocks["core/post-content"].typography.fontFamily).toBe(
      "var:preset|font-family|interface",
    );
    // The editor's font picker offers only the two approved roles.
    expect(theme.settings.typography.fontFamilies.map((f) => f.slug)).toEqual([
      "interface",
      "mono",
    ]);
  });
});

describe("task-07: contrast is verified on light and dark contexts", () => {
  it("every contract pair meets its floor against the frozen :root values", () => {
    for (const pair of contract.colors.pairs) {
      const ratio = contrastRatio(tokens.get(pair.foreground), tokens.get(pair.background));
      expect(ratio, pair.id).toBeGreaterThanOrEqual(pair.minRatio);
    }
  });

  it("link, focus and control boundaries verify on canvas, surface and anchor", () => {
    const pairs = [
      ["--color-action", "--color-canvas", 4.5],
      ["--color-action", "--color-surface", 4.5],
      ["--color-action-hover", "--color-canvas", 4.5],
      ["--color-action", "--color-canvas", 3.0], // focus outline (UI graphic)
      ["--color-focus-on-dark", "--color-anchor", 3.0],
      ["--color-boundary-strong", "--color-surface", 3.0],
      ["--color-boundary-strong", "--color-canvas", 3.0],
      ["--color-surface", "--color-anchor", 4.5],
      ["--color-surface", "--color-action", 4.5],
      ["--color-surface", "--color-action-hover", 4.5],
    ];
    for (const [fg, bg, min] of pairs) {
      expect(
        contrastRatio(tokens.get(fg), tokens.get(bg)),
        `${fg} on ${bg}`,
      ).toBeGreaterThanOrEqual(min);
    }
  });
});

describe("task-07: primitives, motion and print rules hold", () => {
  it("keeps the shared focus primitive on light and dark surfaces", () => {
    expect(css).toMatch(
      /:where\(a, button, input, select, textarea, summary, \[tabindex\], video\[controls\]\):focus-visible\s*\{\s*outline:\s*var\(--rule-focus\)/,
    );
    expect(css).toMatch(/outline-color:\s*var\(--color-focus-on-dark\)/);
    expect(tokens.get("--rule-focus")).toContain("var(--color-action)");
  });

  it("transitions only composited properties and honors reduced motion", () => {
    expect(css).not.toMatch(/transition:\s*all\b/i);
    // Composited properties only. `translate`, `rotate` and `scale` are the
    // independent transform properties: composited exactly like `transform`.
    const allowed = new Set([
      "color",
      "background-color",
      "border-color",
      "text-decoration-thickness",
      "transform",
      "translate",
      "rotate",
      "scale",
      "opacity",
      "filter",
    ]);
    for (const match of css.matchAll(/transition:\s*([^;]+);/g)) {
      for (const part of match[1].split(",")) {
        const prop = part.trim().split(/\s+/)[0];
        expect(allowed.has(prop), `transition property ${prop}`).toBe(true);
      }
    }
    expect(css).toMatch(/@media\s*\(prefers-reduced-motion:\s*reduce\)/);
    expect(css).not.toMatch(/@keyframes|animation:\s*[^;]*(?:infinite|alternate)/i);
    expect(css).not.toMatch(/scroll-behavior:\s*smooth/);
  });

  it("keeps the print primitives: controls stripped, content preserved, URLs retained", () => {
    const print = /@media\s*print\s*\{([\s\S]*)\}\s*$/u.exec(css);
    expect(print).not.toBeNull();
    expect(print[1]).toContain("display: none");
    expect(print[1]).toContain('a[href^="http"]::after');
    expect(print[1]).toContain("break-inside: avoid");
    expect(print[1]).not.toMatch(/\.lps-main-content[^{}]*\{[^{}]*display:\s*none/);
  });

  it("the isolated fixture consumes the production stylesheet and real classes", () => {
    const fixture = readFileSync(FIXTURE, "utf8");
    expect(fixture).toContain('href="../../../wp-content/themes/lps-theme/assets/css/theme.css"');
    for (const cls of [
      "lps-button-primary",
      "lps-alert-error",
      "lps-field-error",
      "lps-access-links",
      "lps-status-warning",
      "lps-wordmark",
      "lps-site-footer",
    ]) {
      expect(fixture).toContain(cls);
      expect(css).toContain(`.${cls}`);
    }
    // The fixture is a test aid only: it must never be referenced by the theme.
    const themeSources = ["functions.php", "includes/class-shell.php"].map((f) =>
      readFileSync(`${THEME}/${f}`, "utf8"),
    );
    for (const source of themeSources) expect(source).not.toContain("primitives.html");
  });
});

describe("task-07: the design-system lane passes on the shipped theme", () => {
  it("checkTheme reports zero findings", async () => {
    const report = await checkTheme();
    expect(report.findings).toEqual([]);
    expect(report.status).toBe("passed");
  });

  it("the stylesheet audit finds no blocking defect", () => {
    const findings = auditStylesheet(css, CSS_PATH);
    expect(findings.filter((f) => ["critical", "serious"].includes(f.impact))).toEqual([]);
  });
});

describe("task-07 failure path: injected defects are caught, never silently absorbed", () => {
  it("an unapproved raw color outside :root is a finding", async () => {
    const report = await checkMutatedTheme((root) => {
      const file = `${root}/assets/css/theme.css`;
      writeFileSync(file, `${readFileSync(file, "utf8")}\n.bad { color: #ff00ff; }\n`);
    });
    expect(report.status).toBe("failed");
    expect(codes(report).has("UNTOKENIZED_COLOR")).toBe(true);
  });

  it("a hidden focus outline is a finding", () => {
    // The DOM-level audit owns focus removal: a :focus rule that removes the
    // outline without an alternative indicator must be reported.
    const findings = auditStylesheet(
      `${css}\n.lps-button:focus { outline: none; }\n`,
      "injected.css",
    );
    expect(findings.map((f) => f.code)).toContain("lps_a11y_focus_outline_removed");
  });

  it("a reduced-motion violation is a finding", async () => {
    const report = await checkMutatedTheme((root) => {
      const file = `${root}/assets/css/theme.css`;
      const stripped = readFileSync(file, "utf8").replace(
        /@media \(prefers-reduced-motion: reduce\) \{[\s\S]*?\n\}\n\n@media print/,
        "@media print",
      );
      writeFileSync(file, stripped);
    });
    expect(report.status).toBe("failed");
    expect(codes(report).has("MISSING_REDUCED_MOTION")).toBe(true);
  });

  it("a disallowed radius or shadow is a finding", async () => {
    const report = await checkMutatedTheme((root) => {
      const file = `${root}/assets/css/theme.css`;
      writeFileSync(
        file,
        `${readFileSync(file, "utf8")}\n.bad { border-radius: 12px; box-shadow: 0 4px 8px #000; }\n`,
      );
    });
    expect(report.status).toBe("failed");
    expect(codes(report).has("ROUNDED_SURFACE")).toBe(true);
    expect(codes(report).has("SHADOW_SURFACE")).toBe(true);
  });

  it("a retired token reappearing is a finding", async () => {
    const report = await checkMutatedTheme((root) => {
      const file = `${root}/assets/css/theme.css`;
      writeFileSync(file, `${readFileSync(file, "utf8")}\n.bad { color: var(--color-navy); }\n`);
    });
    expect(report.status).toBe("failed");
    expect(codes(report).has("RETIRED_TOKEN")).toBe(true);
  });

  it("a contract-token drift in :root is a finding", async () => {
    const report = await checkMutatedTheme((root) => {
      const file = `${root}/assets/css/theme.css`;
      const declared = contract.colors.tokens.find((t) => t.token === "--color-action");
      const shipped = readFileSync(file, "utf8").match(/--color-action:\s*(#[0-9a-f]{6});/i)[1];
      // Perturb the last hex digit: one step of drift, whatever the palette is.
      const drifted = `${shipped.slice(0, -1)}${Number.parseInt(shipped.slice(-1), 16) ^ 0x1}`;
      expect(shipped.toUpperCase()).toBe(declared.value.toUpperCase());
      writeFileSync(file, readFileSync(file, "utf8").replace(shipped, drifted));
    });
    expect(report.status).toBe("failed");
    expect(codes(report).has("CONTRACT_VALUE_DRIFT")).toBe(true);
  });

  it("clipped text is caught by the DOM check, not by a snapshot", () => {
    // The DOM-level check for clipped essential text: an element that hides
    // overflow on a single-line nowrap box clips its label. The fixture's own
    // markup must be clean, and the same check must flag the injected defect.
    const clipped = (html) => {
      const offenders = [];
      for (const match of html.matchAll(
        /<(label|span|p|a|button|legend|h[1-6])\b[^>]*style="([^"]*)"[^>]*>([^<]{8,})</gi,
      )) {
        const style = match[2];
        if (/overflow\s*:\s*hidden/i.test(style) && /white-space\s*:\s*nowrap/i.test(style)) {
          offenders.push(match[3].trim());
        }
      }
      return offenders;
    };
    expect(clipped(readFileSync(FIXTURE, "utf8"))).toEqual([]);
    const injected = readFileSync(FIXTURE, "utf8").replace(
      '<span class="lps-field-hint" id="f-title-hint">',
      '<span class="lps-field-hint" id="f-title-hint" style="overflow:hidden;white-space:nowrap;max-width:4rem">',
    );
    expect(clipped(injected).length).toBeGreaterThan(0);
  });
});
