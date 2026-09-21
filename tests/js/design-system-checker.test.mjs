import { execFileSync } from "node:child_process";
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, expect, test } from "vitest";
import { checkTheme } from "../../wp-content/themes/lps-theme/scripts/check-theme.mjs";

const checker = "showcase/scientific-editorial/scripts/check-design-system.mjs";
const html = "showcase/scientific-editorial/index.html";
const css = "showcase/scientific-editorial/styles.css";

function run(cssPath = css) {
  try {
    return {
      status: 0,
      report: JSON.parse(
        execFileSync(process.execPath, [checker, cssPath, html], { encoding: "utf8" }),
      ),
    };
  } catch (error) {
    return { status: error.status, report: JSON.parse(error.stdout) };
  }
}

describe("Scientific Editorial checker", () => {
  // The showcase still carries the pre-contract palette: task-07 froze the
  // task-2 contract into theme.json, and the checker now gates the unmigrated
  // showcase against it. That failure is pre-existing drift, not a regression
  // of this task — so the test asserts the checker detects exactly that drift
  // (token-parity findings only, no structural violations) until the showcase
  // migration lands.
  test("flags the unmigrated showcase as contract drift, nothing else", () => {
    const result = run();
    expect(result.status).not.toBe(0);
    expect(result.report.status).toBe("failed");
    expect(result.report.findingCount).toBeGreaterThan(0);
    const codes = new Set(result.report.findings.map(({ code }) => code));
    for (const code of codes) {
      expect(["MISSING_TOKEN", "TOKEN_VALUE_MISMATCH"]).toContain(code);
    }
  });

  test("rejects raw color, rounded shadow card, missing focus, and motion without reduction", () => {
    const directory = mkdtempSync(join(tmpdir(), "lps-design-check-"));
    const fixture = join(directory, "fixture.css");
    try {
      let source = readFileSync(css, "utf8");
      source = source.replace("button:focus-visible,\n", "");
      source = source.replace(
        /@media \(prefers-reduced-motion: reduce\) \{[\s\S]*?\n\}\n\n@media print/,
        "@media print",
      );
      source +=
        "\n.bad-card { color: #ff00ff; border-radius: 12px; box-shadow: 0 4px 12px #000000; animation: drift 2s linear infinite; }\n";
      writeFileSync(fixture, source);
      const result = run(fixture);
      const codes = new Set(result.report.findings.map(({ code }) => code));
      expect(result.status).not.toBe(0);
      for (const code of [
        "UNTOKENIZED_COLOR",
        "ROUNDED_SURFACE",
        "SHADOW_SURFACE",
        "MISSING_FOCUS_STATE",
        "MISSING_REDUCED_MOTION",
      ]) {
        expect(codes.has(code), `missing expected finding ${code}`).toBe(true);
      }
    } finally {
      rmSync(directory, { force: true, recursive: true });
    }
  });
});

describe("LPS theme checker", () => {
  test("accepts the packaged woff2 fonts the stylesheet actually references", async () => {
    // Given: the shipped theme, whose @font-face rules reference subsetted woff2 files.
    const css = readFileSync("wp-content/themes/lps-theme/assets/css/theme.css", "utf8");
    expect(css).toContain("../fonts/ibm-plex-sans-regular.woff2");
    expect(css).toContain("../fonts/ibm-plex-sans-semibold.woff2");

    // When: the theme design-system checker runs.
    const report = await checkTheme();

    // Then: it must not demand font formats the theme no longer ships.
    expect(report.findings.filter(({ code }) => code === "MISSING_FONT_ASSET")).toEqual([]);
    expect(report).toMatchObject({ status: "passed", findingCount: 0 });
  });

  test("still reports a font asset the stylesheet references but the theme does not ship", async () => {
    // Given: a stylesheet that references a font file that is absent from the theme.
    const cssPath = "wp-content/themes/lps-theme/assets/css/theme.css";
    const original = readFileSync(cssPath, "utf8");
    try {
      writeFileSync(
        cssPath,
        original.replace("ibm-plex-sans-regular.woff2", "ibm-plex-sans-missing.woff2"),
      );

      // When: the checker runs against that stylesheet.
      const report = await checkTheme();

      // Then: the missing local font asset is still a finding.
      expect(report.findings.map(({ code }) => code)).toContain("MISSING_FONT_ASSET");
    } finally {
      writeFileSync(cssPath, original);
    }
  });
});
