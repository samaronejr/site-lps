#!/usr/bin/env node
/**
 * LPS design-contract validator (lps-website-ulw-plan task-02).
 *
 * Validates docs/design/design-contract.json against the supplied logo
 * artwork and the contract's own rules. Every check is deterministic:
 * contrast ratios are recomputed from token values (WCAG 2.2 relative
 * luminance), logo hashes are recomputed from bytes, and the compact
 * variant is verified as a strict path subset of the source SVG.
 *
 * Usage:
 *   node scripts/lib/design-contract.mjs [contract-path] [--json]
 *   import { validateContract } from "./design-contract.mjs";
 *
 * Exit codes: 0 clean, 1 findings, 2 usage/IO error.
 */

import { createHash } from "node:crypto";
import { existsSync, readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");
const DEFAULT_CONTRACT = "docs/design/design-contract.json";

const REQUIRED_SURFACES = ["homepage", "professor", "offering", "editor-dashboard"];
const REQUIRED_SURFACE_STATES = ["empty", "error", "long-content"];
const REQUIRED_APPLICATIONS = [
  "desktop-branding",
  "mobile-branding",
  "news-cover",
  "course-document-cover",
  "social-preview",
  "print",
];
const REQUIRED_PAIRS = [
  "body-on-surface",
  "link-on-surface",
  "nav-label-on-anchor",
  "control-boundary-on-surface",
  "focus-outline-on-anchor",
];
const REQUIRED_SEMANTIC_ROLES = ["success", "warning", "error"];
const REQUIRED_NAV = [
  "O LPS",
  "Pesquisa",
  "Pessoas",
  "Ensino",
  "Notícias e eventos",
  "Oportunidades",
];
const REQUIRED_BANS = [
  "linear-gradient",
  "box-shadow",
  "backdrop-filter",
  "pill radius",
  "transition: all",
  "scroll-hijack",
  "marquee",
  "autoplay media",
];
const EXPECTED_DIALS = { designVariance: 3, motionIntensity: 2, visualDensity: 5 };
const SOURCE_LOGO_SHA256 = "f369f9e49c81e297d30fa8e240267667dddf8b89f0d26c494636f9429027a23b";

/** Patterns that may never appear inside a token value or spec string. */
const DISALLOWED_EFFECT_PATTERNS = [
  /(?:linear|radial|conic)-gradient\s*\(/i,
  /(?:box|text)-shadow\s*:/i,
  /backdrop-(?:filter|blur)/i,
  /filter\s*:\s*blur/i,
  /border-radius\s*:\s*(?:[5-9]|\d{2,})px/i,
  /border-radius\s*:\s*999/i,
  /transition\s*:\s*all\b/i,
  /marquee/i,
  /parallax/i,
];

function srgbToLinear(channel) {
  return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
}

export function relativeLuminance(hex) {
  const value = hex.replace("#", "");
  const [r, g, b] = [0, 2, 4].map((i) => srgbToLinear(parseInt(value.slice(i, i + 2), 16) / 255));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrastRatio(foreground, background) {
  const [hi, lo] = [relativeLuminance(foreground), relativeLuminance(background)].sort(
    (a, b) => b - a,
  );
  return (hi + 0.05) / (lo + 0.05);
}

function sha256(path) {
  return createHash("sha256").update(readFileSync(path)).digest("hex");
}

function svgViewBox(svg) {
  const match = svg.match(/<svg[^>]*viewBox="([^"]+)"/);
  return match ? match[1] : null;
}

function svgPathData(svg) {
  const map = new Map();
  for (const match of svg.matchAll(/<path\s+id="([^"]+)"\s+d="([^"]+)"/g)) {
    map.set(match[1], match[2]);
  }
  return map;
}

function collectStrings(value, out = []) {
  if (typeof value === "string") out.push(value);
  else if (Array.isArray(value)) for (const item of value) collectStrings(item, out);
  else if (value && typeof value === "object")
    for (const item of Object.values(value)) collectStrings(item, out);
  return out;
}

/**
 * Validates a parsed contract object. Returns { status, findings } where each
 * finding is { code, message }.
 */
export function validateContract(contract, { root = ROOT } = {}) {
  const findings = [];
  const add = (code, message) => findings.push({ code, message });

  if (contract.schemaVersion !== 1) {
    add("CONTRACT_SCHEMA", "schemaVersion must equal 1.");
    return { status: "failed", findings };
  }

  // --- Dials ---------------------------------------------------------------
  for (const [dial, expected] of Object.entries(EXPECTED_DIALS)) {
    if (contract.dials?.[dial] !== expected) {
      add("INVALID_DIAL", `${dial} must equal ${expected}; got ${contract.dials?.[dial]}.`);
    }
  }

  // --- Color tokens ----------------------------------------------------------
  const tokens = new Map();
  for (const token of contract.colors?.tokens ?? []) {
    if (!token.token || !token.role || !token.usage) {
      add("MISSING_TOKEN_ROLE", `Color token ${token.token ?? "(unnamed)"} lacks role or usage.`);
    }
    if (!/^#[0-9a-fA-F]{6}$/.test(token.value ?? "")) {
      add("INVALID_TOKEN_VALUE", `${token.token} value "${token.value}" is not a #rrggbb color.`);
    }
    tokens.set(token.token, token);
  }
  for (const role of REQUIRED_SEMANTIC_ROLES) {
    if (![...tokens.values()].some((t) => t.role === role)) {
      add("MISSING_SEMANTIC_TOKEN", `Semantic token with role "${role}" is required.`);
    }
  }
  if (![...tokens.values()].some((t) => t.role === "focus-on-dark")) {
    add("MISSING_FOCUS_TOKEN", "A focus-on-dark token is required for anchor-band focus.");
  }

  // --- Contrast pairs --------------------------------------------------------
  const pairIds = new Set();
  for (const pair of contract.colors?.pairs ?? []) {
    pairIds.add(pair.id);
    const fg = tokens.get(pair.foreground);
    const bg = tokens.get(pair.background);
    if (!fg || !bg) {
      add("UNKNOWN_PAIR_TOKEN", `Pair ${pair.id} references an unknown token.`);
      continue;
    }
    if (fg.decorativeOnly || bg.decorativeOnly) {
      add(
        "DECORATIVE_TOKEN_IN_PAIR",
        `Pair ${pair.id} uses decorative-only token ${fg.decorativeOnly ? pair.foreground : pair.background}.`,
      );
      continue;
    }
    const ratio = contrastRatio(fg.value, bg.value);
    if (ratio < pair.minRatio) {
      add(
        "LOW_CONTRAST_PAIR",
        `Pair ${pair.id} (${pair.foreground} on ${pair.background}) measures ${ratio.toFixed(2)}:1, below ${pair.minRatio}:1 for ${pair.context}.`,
      );
    }
    if (typeof pair.expectedRatio === "number" && Math.abs(ratio - pair.expectedRatio) > 0.1) {
      add(
        "EXPECTED_RATIO_MISMATCH",
        `Pair ${pair.id} declares ${pair.expectedRatio}:1 but measures ${ratio.toFixed(2)}:1.`,
      );
    }
  }
  for (const id of REQUIRED_PAIRS) {
    if (!pairIds.has(id)) add("MISSING_CONTRAST_PAIR", `Required contrast pair "${id}" is absent.`);
  }

  // --- Typography --------------------------------------------------------------
  const families = contract.typography?.families ?? [];
  if (!families.some((f) => /body|reading/i.test(f.owns ?? ""))) {
    add("TYPOGRAPHY_ROLE_MISSING", "No font family owns body/reading text.");
  }
  const mono = families.find((f) => /mono/i.test(f.token ?? ""));
  if (mono && !/course codes|identifiers|code/i.test(mono.owns ?? "")) {
    add(
      "TYPOGRAPHY_ROLE_MISSING",
      "The mono family must be restricted to course codes, identifiers, and code.",
    );
  }
  const body = (contract.typography?.scale ?? []).find((s) => s.token === "--type-body");
  if (!body || !/^1(?:\.[01])?rem$|^1[678]px$/.test(body.size ?? "")) {
    add("BODY_FLOOR_VIOLATION", "--type-body must be 16-18px (1rem-1.125rem).");
  }

  // --- Geometry -----------------------------------------------------------------
  if (contract.geometry?.controlRadius !== "4px") {
    add("RADIUS_VIOLATION", "Control radius must be exactly 4px.");
  }

  // --- Logo -----------------------------------------------------------------------
  const logo = contract.logo ?? {};
  const sourcePath = resolve(root, logo.source?.path ?? "");
  if (!existsSync(sourcePath)) {
    add("LOGO_SOURCE_MISSING", `Logo source ${logo.source?.path} does not exist.`);
  } else {
    if (sha256(sourcePath) !== (logo.source?.sha256 ?? SOURCE_LOGO_SHA256)) {
      add("LOGO_SOURCE_MISMATCH", "Logo source bytes differ from the recorded SHA-256.");
    }
    if (Buffer.byteLength(readFileSync(sourcePath)) !== logo.source?.bytes) {
      add("LOGO_SOURCE_MISMATCH", "Logo source byte length differs from the recorded size.");
    }
    const svg = readFileSync(sourcePath, "utf8");
    if (svgViewBox(svg) !== logo.source?.viewBox) {
      add("LOGO_VIEWBOX_MISMATCH", "Logo source viewBox differs from the declared value.");
    }
  }

  const compact = (logo.variants ?? []).find((v) => v.id === "compact");
  if (!compact) {
    add("MISSING_MOBILE_LOGO_RULE", "No compact logo variant is declared for mobile.");
  } else {
    const compactPath = resolve(root, compact.path ?? "");
    if (!existsSync(compactPath)) {
      add("LOGO_VARIANT_MISSING", `Compact variant ${compact.path} does not exist.`);
    } else if (existsSync(sourcePath)) {
      const sourceSvg = readFileSync(sourcePath, "utf8");
      const compactSvg = readFileSync(compactPath, "utf8");
      const sourcePaths = svgPathData(sourceSvg);
      const compactPaths = svgPathData(compactSvg);
      for (const [id, d] of compactPaths) {
        if (!sourcePaths.has(id)) {
          add(
            "COMPACT_NEW_GEOMETRY",
            `Compact variant introduces path "${id}" absent from source.`,
          );
        } else if (sourcePaths.get(id) !== d) {
          add("COMPACT_PATH_DRIFT", `Compact variant path "${id}" differs from the source bytes.`);
        }
      }
      for (const id of compact.derivation?.keptPaths ?? []) {
        if (!compactPaths.has(id)) {
          add("COMPACT_PATH_DRIFT", `Declared kept path "${id}" is absent from the compact file.`);
        }
      }
      if (svgViewBox(compactSvg) !== compact.viewBox) {
        add("LOGO_VIEWBOX_MISMATCH", "Compact variant viewBox differs from the declared value.");
      }
    }
    if (typeof compact.minHeightPx !== "number" || compact.minHeightPx <= 0) {
      add("MISSING_MOBILE_LOGO_RULE", "Compact variant lacks a minimum rendered height rule.");
    }
  }
  const logoRules = (logo.rules ?? []).join(" ").toLowerCase();
  if (!/mobile/.test(logoRules) || !/compact/.test(logoRules)) {
    add("MISSING_MOBILE_LOGO_RULE", "Logo rules do not state the mobile compact-variant rule.");
  }
  if (!/amendment/.test(logoRules) || !/ufrj|coppe/.test(logoRules)) {
    add(
      "MISSING_LOGO_AMENDMENT",
      "The full-color-header amendment (LPS artwork only; UFRJ/COPPE stay text-only) is not recorded.",
    );
  }

  // --- Navigation ------------------------------------------------------------------
  for (const label of REQUIRED_NAV) {
    if (!(contract.navigation?.primary ?? []).includes(label)) {
      add("MISSING_NAV_ITEM", `Primary navigation lacks "${label}".`);
    }
  }

  // --- Surfaces ----------------------------------------------------------------------
  const surfaceIds = new Set((contract.surfaces ?? []).map((s) => s.id));
  for (const id of REQUIRED_SURFACES) {
    if (!surfaceIds.has(id)) add("MISSING_SURFACE", `Required surface "${id}" is not specified.`);
  }
  for (const surface of contract.surfaces ?? []) {
    for (const state of REQUIRED_SURFACE_STATES) {
      if (!surface.states?.[state]) {
        add("MISSING_SURFACE_STATE", `Surface "${surface.id}" lacks the "${state}" state.`);
      }
    }
    if (!surface.mode || !surface.taskDensity) {
      add("MISSING_SURFACE_STATE", `Surface "${surface.id}" lacks mode or task density.`);
    }
  }

  // --- Applications --------------------------------------------------------------------
  const applicationIds = new Set((contract.applications ?? []).map((a) => a.id));
  for (const id of REQUIRED_APPLICATIONS) {
    if (!applicationIds.has(id))
      add("MISSING_APPLICATION", `Application "${id}" is not specified.`);
  }

  // --- Bans and disallowed effects --------------------------------------------------------
  const bans = (contract.bans?.effects ?? []).join("\n").toLowerCase();
  for (const ban of REQUIRED_BANS) {
    if (!bans.includes(ban.toLowerCase())) {
      add("MISSING_BAN", `The ban list does not cover "${ban}".`);
    }
  }
  // Any token value or spec text that smuggles a banned effect back in is rejected.
  const scannable = [
    ...(contract.colors?.tokens ?? []).map((t) => t.value),
    ...(contract.typography?.families ?? []).map((f) => f.stack),
    ...(contract.surfaces ?? []).flatMap((s) => collectStrings(s.states)),
    ...(contract.applications ?? []).map((a) => a.spec),
  ].filter(Boolean);
  for (const text of scannable) {
    for (const pattern of DISALLOWED_EFFECT_PATTERNS) {
      if (pattern.test(text)) {
        add("DISALLOWED_EFFECT", `"${text.slice(0, 80)}" contains a banned decorative effect.`);
      }
    }
  }

  return { status: findings.length === 0 ? "passed" : "failed", findings };
}

export function loadContract(path = DEFAULT_CONTRACT, { root = ROOT } = {}) {
  const file = resolve(root, path);
  return JSON.parse(readFileSync(file, "utf8"));
}

const invokedAsScript =
  process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (invokedAsScript) {
  const args = process.argv.slice(2);
  const contractPath = args.find((a) => a.endsWith(".json")) ?? DEFAULT_CONTRACT;
  try {
    const contract = loadContract(contractPath);
    const report = validateContract(contract);
    process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
    process.exitCode = report.status === "passed" ? 0 : 1;
  } catch (error) {
    process.stderr.write(`${JSON.stringify({ status: "error", message: String(error) })}\n`);
    process.exitCode = 2;
  }
}
