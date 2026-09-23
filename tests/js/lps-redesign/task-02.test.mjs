import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";
import {
  contrastRatio,
  loadContract,
  validateContract,
} from "../../../scripts/lib/design-contract.mjs";

const CONTRACT_PATH = "docs/design/design-contract.json";
const SOURCE_LOGO = "assets/brand/lps_logo_vector.svg";
const COMPACT_LOGO = "assets/brand/lps_logo_compact.svg";
const SOURCE_SHA256 = "f369f9e49c81e297d30fa8e240267667dddf8b89f0d26c494636f9429027a23b";

const read = (path) => readFileSync(path, "utf8");
const clone = (value) => JSON.parse(JSON.stringify(value));
const codes = (report) => new Set(report.findings.map((finding) => finding.code));

function viewBoxAspect(svg) {
  const [, , width, height] = svg
    .match(/<svg[^>]*viewBox="([^"]+)"/)[1]
    .split(/\s+/)
    .map(Number);
  return width / height;
}

describe("task-02 contract: supplied artwork is intact and the compact variant is a pure subset", () => {
  it("preserves the supplied logo bytes exactly as recorded in the plan", () => {
    const bytes = readFileSync(SOURCE_LOGO);
    expect(createHash("sha256").update(bytes).digest("hex")).toBe(SOURCE_SHA256);
    expect(bytes.length).toBe(53019);
  });

  it("derives the compact variant by path selection only — no new or altered geometry", () => {
    const source = read(SOURCE_LOGO);
    const compact = read(COMPACT_LOGO);
    const sourcePaths = new Map(
      [...source.matchAll(/<path\s+id="([^"]+)"\s+d="([^"]+)"/g)].map((m) => [m[1], m[2]]),
    );
    const compactPaths = [...compact.matchAll(/<path\s+id="([^"]+)"\s+d="([^"]+)"/g)].map((m) => [
      m[1],
      m[2],
    ]);

    // Every compact path exists in the source with byte-identical geometry.
    expect(compactPaths.length).toBeGreaterThan(0);
    for (const [id, d] of compactPaths) {
      expect(sourcePaths.get(id), `path ${id} must come from the source`).toBe(d);
    }
    // The documented mapping: waveform + reference line + separator + LPS lettering.
    expect(compactPaths.map(([id]) => id).sort()).toEqual([
      "lps-lettering",
      "reference-line",
      "separator",
      "waveform",
    ]);
    // The long descriptive lettering is dropped, not redrawn.
    expect(compact).not.toContain("laboratory-name");
    expect(compact).not.toContain("computational-intelligence");
  });

  it("keeps the declared aspect ratios inside the artwork viewBoxes", () => {
    const contract = loadContract(CONTRACT_PATH);
    const full = viewBoxAspect(read(SOURCE_LOGO));
    const compact = viewBoxAspect(read(COMPACT_LOGO));
    expect(Math.abs(full - contract.logo.source.aspectRatio)).toBeLessThan(0.01);
    const compactVariant = contract.logo.variants.find((v) => v.id === "compact");
    expect(Math.abs(compact - compactVariant.aspectRatio)).toBeLessThan(0.01);
  });
});

describe("task-02 contract: token specification validates clean (happy path)", () => {
  it("passes the contract validator with zero findings", () => {
    const report = validateContract(loadContract(CONTRACT_PATH));
    expect(report.findings).toEqual([]);
    expect(report.status).toBe("passed");
  });

  it("gives every planned token a role and meets its declared contrast pair expectations", () => {
    const contract = loadContract(CONTRACT_PATH);
    // Pairs may reference a palette token or a declared CSS primitive (the on-dark
    // reading tones, notice inks, focus-on-dark): both are part of the token set.
    const all = [...contract.colors.tokens, ...(contract.colors.primitives ?? [])];
    const tokens = new Map(all.map((t) => [t.token, t]));
    for (const token of all) {
      expect(token.role, token.token).toBeTruthy();
      expect(token.usage, token.token).toBeTruthy();
    }
    for (const pair of contract.colors.pairs) {
      const ratio = contrastRatio(
        tokens.get(pair.foreground).value,
        tokens.get(pair.background).value,
      );
      expect(ratio, pair.id).toBeGreaterThanOrEqual(pair.minRatio);
      expect(Math.abs(ratio - pair.expectedRatio), pair.id).toBeLessThanOrEqual(0.1);
    }
  });

  it("specifies the four surfaces with one identity and different task density", () => {
    const contract = loadContract(CONTRACT_PATH);
    const surfaces = Object.fromEntries(contract.surfaces.map((s) => [s.id, s]));
    expect(Object.keys(surfaces).sort()).toEqual([
      "editor-dashboard",
      "homepage",
      "offering",
      "professor",
    ]);
    const densities = new Set(contract.surfaces.map((s) => s.taskDensity));
    expect(densities.size).toBe(4);
    for (const surface of contract.surfaces) {
      for (const state of ["empty", "error", "long-content"]) {
        expect(surface.states[state], `${surface.id}.${state}`).toBeTruthy();
      }
    }
  });

  it("records the full-color-header amendment and the mobile logo rule", () => {
    const contract = loadContract(CONTRACT_PATH);
    const rules = contract.logo.rules.join(" ");
    expect(rules).toMatch(/amendment/i);
    expect(rules).toMatch(/UFRJ\/COPPE marks remain text-only/);
    expect(rules).toMatch(/Mobile masthead uses the compact variant/);
    expect(contract.logo.source.sha256).toBe(SOURCE_SHA256);
  });
});

describe("task-02 contract: malformed inputs are rejected (failure path)", () => {
  it("rejects a deliberately low-contrast control pair", () => {
    const contract = clone(loadContract(CONTRACT_PATH));
    contract.colors.pairs.push({
      id: "injected-low-contrast",
      foreground: "--color-rule-quiet",
      background: "--color-surface",
      minRatio: 4.5,
      context: "injected control pair",
    });
    const report = validateContract(contract);
    expect(report.status).toBe("failed");
    // quiet-rule is decorative-only: rejected as DECORATIVE_TOKEN_IN_PAIR.
    // A non-decorative weak pair is rejected as LOW_CONTRAST_PAIR.
    expect(
      codes(report).has("DECORATIVE_TOKEN_IN_PAIR") || codes(report).has("LOW_CONTRAST_PAIR"),
    ).toBe(true);

    const contract2 = clone(loadContract(CONTRACT_PATH));
    contract2.colors.pairs.push({
      id: "injected-low-contrast-2",
      foreground: "--color-boundary-strong",
      background: "--color-surface",
      minRatio: 4.5,
      context: "boundary as text",
    });
    const report2 = validateContract(contract2);
    expect(report2.status).toBe("failed");
    expect(codes(report2).has("LOW_CONTRAST_PAIR")).toBe(true);
  });

  it("rejects a contract with the mobile logo rule removed", () => {
    const contract = clone(loadContract(CONTRACT_PATH));
    contract.logo.variants = contract.logo.variants.filter((v) => v.id !== "compact");
    contract.logo.rules = contract.logo.rules.filter((r) => !/mobile|compact/i.test(r));
    const report = validateContract(contract);
    expect(report.status).toBe("failed");
    expect(codes(report).has("MISSING_MOBILE_LOGO_RULE")).toBe(true);
  });

  it("rejects a contract that permits a disallowed decorative effect", () => {
    const contract = clone(loadContract(CONTRACT_PATH));
    contract.applications.find((a) => a.id === "social-preview").spec +=
      " with a linear-gradient(135deg, #165A96, #00b9f2) backdrop";
    const report = validateContract(contract);
    expect(report.status).toBe("failed");
    expect(codes(report).has("DISALLOWED_EFFECT")).toBe(true);

    const contract2 = clone(loadContract(CONTRACT_PATH));
    contract2.bans.effects = contract2.bans.effects.filter((e) => !/linear-gradient/.test(e));
    const report2 = validateContract(contract2);
    expect(report2.status).toBe("failed");
    expect(codes(report2).has("MISSING_BAN")).toBe(true);
  });

  it("rejects compact-variant geometry that drifts from the source paths", () => {
    const contract = clone(loadContract(CONTRACT_PATH));
    contract.logo.variants
      .find((v) => v.id === "compact")
      .derivation.keptPaths.push("invented-path");
    const report = validateContract(contract);
    expect(report.status).toBe("failed");
    expect(codes(report).has("COMPACT_PATH_DRIFT")).toBe(true);
  });
});
