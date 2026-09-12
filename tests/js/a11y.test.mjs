import { readdir, readFile } from "node:fs/promises";
import { describe, expect, it } from "vitest";
import {
  auditCorpus,
  auditDocument,
  auditStylesheet,
  auditTemplateSource,
  CONTRAST_PAIRS,
  contrastRatio,
  cssTokens,
} from "../../scripts/lib/a11y.mjs";
import { routes, templateCoverage } from "../../scripts/lib/a11y-inventory.mjs";

const FAILURES = "tests/fixtures/a11y/failures";
const THEME_CSS = "wp-content/themes/lps-theme/assets/css/theme.css";

async function auditFixture(file, overrides = {}) {
  const html = await readFile(`${FAILURES}/${file}`, "utf8");
  return auditDocument({
    id: file.replace(/\.html$/u, ""),
    path: `/pt-br/fixture/${file}`,
    locale: "pt-br",
    template: "fixture",
    state: "fixture",
    classes: [],
    html,
    ...overrides,
  });
}

function codes(report) {
  return report.findings.map((finding) => finding.code);
}

describe("route and state inventory", () => {
  it("claims every public block template", async () => {
    const coverage = await templateCoverage();
    expect(coverage.uncovered).toEqual([]);
    expect(coverage.unknown).toEqual([]);
    expect(coverage.templates.length).toBeGreaterThan(20);
  });

  it("covers both locales for every locale-paired surface", () => {
    const inventory = routes();
    expect(inventory.filter((route) => route.locale === "pt-br").length).toBeGreaterThan(0);
    expect(inventory.filter((route) => route.locale === "en").length).toBeGreaterThan(0);
    expect(new Set(inventory.map((route) => route.id)).size).toBe(inventory.length);
  });
});

describe("block template sources", () => {
  it("rejects a template that loses the skip target", () => {
    const findings = auditTemplateSource(
      "broken.html",
      '<!-- wp:lps-theme/header /--><main class="lps-main-content"></main><!-- wp:lps-theme/footer /-->',
    );
    expect(findings.map((finding) => finding.code)).toContain(
      "lps_a11y_template_skip_target_missing",
    );
    expect(findings[0].route).toContain("templates/broken.html");
  });

  it("accepts every template the theme ships", async () => {
    const dir = "wp-content/themes/lps-theme/templates";
    const templates = (await readdir(dir)).filter((file) => file.endsWith(".html"));
    for (const template of templates) {
      const source = await readFile(`${dir}/${template}`, "utf8");
      expect(auditTemplateSource(template, source), template).toEqual([]);
    }
  });
});

describe("failure fixtures identify the exact route and selector", () => {
  it("reports a missing alt attribute", async () => {
    const report = await auditFixture("missing-alt.html");
    const finding = report.findings.find((item) => item.code === "lps_a11y_image_alt_missing");
    expect(finding).toBeDefined();
    expect(finding.selector).toContain("img.lps-person-photo");
    expect(finding.route).toContain("missing-alt.html");
    expect(finding.impact).toBe("critical");
  });

  it("reports a label that points at no control", async () => {
    const report = await auditFixture("broken-label.html");
    expect(codes(report)).toContain("lps_a11y_label_target_missing");
    expect(codes(report)).toContain("lps_a11y_field_label_missing");
    const finding = report.findings.find((item) => item.code === "lps_a11y_field_label_missing");
    expect(finding.selector).toContain("input.lps-search-input");
  });

  it("reports a keyboard trap", async () => {
    const report = await auditFixture("keyboard-trap.html");
    expect(codes(report)).toContain("lps_a11y_inline_key_handler");
    expect(codes(report)).toContain("lps_a11y_positive_tabindex");
    const finding = report.findings.find((item) => item.code === "lps_a11y_inline_key_handler");
    expect(finding.selector).toContain("button.lps-filter-trap");
  });

  it("reports malformed language tags and undeclared language changes", async () => {
    const report = await auditFixture("wrong-language.html");
    const invalid = report.findings.filter((item) => item.code === "lps_a11y_language_tag_invalid");
    expect(invalid.length).toBeGreaterThanOrEqual(2);
    const parts = report.findings.find(
      (item) => item.code === "lps_a11y_language_of_parts_missing",
    );
    expect(parts).toBeDefined();
    expect(parts.selector).toContain("p.lps-lead");
  });

  it("does not mistake an address for an undeclared language change", () => {
    const report = auditDocument({
      id: "address",
      path: "/en/accessibility/",
      locale: "en",
      template: "page.html",
      state: "populated",
      classes: [],
      html: '<!doctype html><html lang="en-US"><head><title>Accessibility</title></head><body><a class="lps-skip-link" href="#lps-main">Skip</a><main id="lps-main"><h1>Accessibility</h1><p><a href="mailto:acessibilidade@lps.ufrj.br">acessibilidade@lps.ufrj.br</a></p></main></body></html>',
    });
    expect(report.findings.map((finding) => finding.code)).not.toContain(
      "lps_a11y_language_of_parts_missing",
    );
  });

  it("reports essential content published only as PDF", async () => {
    const report = await auditFixture("pdf-only.html");
    const finding = report.findings.find(
      (item) => item.code === "lps_a11y_pdf_only_essential_content",
    );
    expect(finding).toBeDefined();
    expect(finding.selector).toContain("a.lps-essential-document");
  });

  it("reports insufficient contrast from the stylesheet tokens", async () => {
    const findings = auditStylesheet(
      await readFile(`${FAILURES}/low-contrast.css`, "utf8"),
      "low-contrast.css",
    );
    const contrast = findings.filter((finding) => finding.code === "lps_a11y_contrast_text");
    expect(contrast.length).toBeGreaterThanOrEqual(2);
    expect(contrast.some((finding) => finding.selector.includes("--color-ink-soft"))).toBe(true);
  });

  it("reports focus indicators removed by a focus rule", async () => {
    const findings = auditStylesheet(
      await readFile(`${FAILURES}/obscured-focus.css`, "utf8"),
      "obscured-focus.css",
    );
    const finding = findings.find((item) => item.code === "lps_a11y_focus_outline_removed");
    expect(finding).toBeDefined();
    expect(finding.selector).toContain(".lps-primary-nav a:focus");
  });

  it("reports authored corpus media and PDF-only defects", async () => {
    const findings = await auditCorpus(`${FAILURES}/corpus`);
    expect(findings.map((finding) => finding.code)).toEqual(
      expect.arrayContaining([
        "lps_a11y_corpus_media_alt_missing",
        "lps_a11y_corpus_transcript_missing",
        "lps_a11y_corpus_pdf_only_essential_content",
      ]),
    );
    const alt = findings.find((finding) => finding.code === "lps_a11y_corpus_media_alt_missing");
    expect(alt.selector).toBe("locales.pt-br.media[0]");
    expect(alt.page).toBe("record-901");
  });
});

describe("repaired surfaces pass the same rules", () => {
  it("finds no blocking defect in the reference document", async () => {
    const html = await readFile("tests/fixtures/a11y/passing/baseline.html", "utf8");
    const report = auditDocument({
      id: "baseline",
      path: "/pt-br/midia-acessivel/",
      locale: "pt-br",
      template: "page.html",
      state: "authored-media",
      classes: [
        "images",
        "figures",
        "tables",
        "downloads",
        "transcripts",
        "language-of-parts",
        "status",
      ],
      html,
    });
    expect(report.findings).toEqual([]);
  });

  it("finds no blocking defect in the shipped stylesheet", async () => {
    const findings = auditStylesheet(await readFile(THEME_CSS, "utf8"), THEME_CSS);
    expect(findings.filter((finding) => ["critical", "serious"].includes(finding.impact))).toEqual(
      [],
    );
  });

  it("finds no accessibility defect in the authored launch corpus", async () => {
    const findings = await auditCorpus("content/corpus");
    expect(findings).toEqual([]);
  });
});

describe("contrast mathematics", () => {
  it("matches the WCAG reference ratios", () => {
    expect(contrastRatio("#000000", "#ffffff")).toBeCloseTo(21, 5);
    expect(contrastRatio("#ffffff", "#ffffff")).toBeCloseTo(1, 5);
  });

  it("evaluates every declared pair against the shipped tokens", async () => {
    const tokens = cssTokens(await readFile(THEME_CSS, "utf8"));
    for (const pair of CONTRAST_PAIRS) {
      const ratio = contrastRatio(tokens.get(pair.foreground), tokens.get(pair.background));
      const required = pair.kind === "text" ? 4.5 : 3;
      expect(
        ratio,
        `${pair.id} (${pair.foreground} on ${pair.background}) is ${ratio.toFixed(2)}:1`,
      ).toBeGreaterThanOrEqual(required);
    }
  });
});
