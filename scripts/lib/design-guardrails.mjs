import { createHash } from "node:crypto";

// Machine-enforced institutional-mark guardrails (DESIGN.md 2, 7 and 9).
//
// Until now these three clauses were enforced by human review only, so a
// gradient on a card, a second waveform ornament, or a brand-coloured link
// could ship while every automated gate reported success. This module is the
// shared rule engine both design-system checkers call.
//
// Rules, each rejected by name:
//   GRADIENT_ON_SURFACE     - any CSS gradient function, any SVG paint-server
//                             gradient outside the cleared mark artwork.
//   WAVEFORM_ORNAMENT       - any oscillating waveform path that is not the
//                             cleared institutional mark.
//   BRAND_COLOR_IN_UI       - either mark-only brand token reaching a UI
//                             declaration, directly or through an alias, a
//                             theme.json preset, or a palette entry.
//   BRAND_COLOR_IN_PALETTE  - either brand colour registered in any theme.json
//                             colour palette (2 puts them in settings.custom
//                             precisely so the editor never offers them).
//   MALFORMED_STYLESHEET    - unbalanced input, so a broken stylesheet can
//                             never read as a clean one.
//
// What stays legal, by construction:
//   - `settings.custom.colorBrandDeep` / `colorBrandAccent` in theme.json.
//   - `--color-brand-deep` / `--color-brand-accent` declared as custom
//     properties. Declaring a brand token is the permitted appearance; using
//     one in a paintable declaration is not.
//   - The cleared mark artwork itself, identified by the registered SHA-256
//     digests of its path `d` data and gradient elements rather than by
//     filename or viewBox, including its own `wave-gradient`.

/** DESIGN.md 9: the cleared mark's registered source geometry (pre-normalisation). */
export const MARK_VIEWBOX = "48 190 2052 301";
/** DESIGN.md 9: the full lockup is six outlined paths; variants may carry fewer. */
export const MARK_MINIMUM_PATHS = 6;
/** DESIGN.md 7/9: the mark's own gradient, and only inside the artwork. */
export const MARK_GRADIENT_ID = "wave-gradient";

/**
 * DESIGN.md 9 / Todo 32: the cleared mark's registered artwork, keyed by
 * SHA-256 of each path's `d` attribute and of each complete
 * `<linearGradient>` element, computed over the supplied source
 * `lps_logo_vector.svg` (sha256
 * f369f9e49c81e297d30fa8e240267667dddf8b89f0d26c494636f9429027a23b, recorded
 * in assets/img/mark/RIGHTS.md). Identity is the artwork itself, so a
 * normalised viewBox, a subset variant, or a renamed file still matches,
 * while a single altered path or gradient stop does not.
 */
const sha256 = (text) => createHash("sha256").update(text, "utf8").digest("hex");
export const MARK_PATH_SHA256 = new Set([
  "4ae0fcc8a4fc5beaadc89c52fcfa87cdcc4ec4e4075b3f94ff408c687b5c2250", // reference-line
  "df03d99bc5b9e1fb423d94d69fcbf7ffbd6ce0e5e24bb42dc951595112a3ed0d", // waveform
  "5cd21b6f9df0076586511a31be5c6125c3b256a533173cb74e901a37987f676a", // separator
  "1e9df5d6f156b69731504eeb5434acced6173bc5363c2351d7f44bf07f8a5e5e", // lps-lettering
  "85372f8521d543619421ce9742620a0db2f6a61c63e1396ec306ded05a127913", // laboratory-name
  "34936098746086283bdbdf7f356db9f434ba19958bab5add5f3048258f24d22a", // computational-intelligence
]);
export const MARK_GRADIENT_SHA256 = new Set([
  "532ff2aed9b4c04f837d7b2229f41dda2948a8007d46c87ca5cb38ef7448ce65", // wave-gradient
  "a04f16a0409824be81e0506e7e6cc39c83f90a78f4a343a490fb2d88b324ceb4", // baseline-gradient
  "d7bfd513682467bc74bd079f3eda2d514d60100e36ce4ac997111a6966261f57", // lps-gradient
]);

/** DESIGN.md 2: the two mark-only brand values. */
export const BRAND_COLORS = ["#094c92", "#00aff1"];
const BRAND_RGB = [
  [9, 76, 146],
  [0, 175, 241],
];

const BRAND_TOKEN_NAME = /^--(?:wp--custom--)?color-brand-(?:deep|accent)$/i;
const CSS_GRADIENT = /\b(?:repeating-)?(?:linear|radial|conic)-gradient\s*\(/i;
// A complete gradient element: self-closing tag, or open tag through its
// matching close tag so the stops are part of the hashed artwork.
const SVG_GRADIENT_ELEMENT = /<(linear|radial)Gradient\b[^>]*?(?:\/>|>[\s\S]*?<\/\1Gradient\s*>)/gi;
const VAR_REFERENCE = /var\(\s*(--[A-Za-z0-9_-]+)/g;

/**
 * Number of adjacent curve segments that must flip side-of-chord before a path
 * counts as a waveform. Three flips is two full oscillations, which no icon,
 * circle, rounded rectangle, or single scallop in this codebase produces.
 */
export const WAVEFORM_ALTERNATION_THRESHOLD = 3;

const PATH_ARGUMENT_COUNT = { M: 2, L: 2, H: 1, V: 1, C: 6, S: 4, Q: 4, T: 2, A: 7, Z: 0 };

function finding(code, message, extra) {
  return { code, message, ...extra };
}

/** Blank out comments while preserving byte offsets, so line numbers stay true. */
function blankComments(css) {
  return css.replace(/\/\*[\s\S]*?\*\//g, (match) => match.replace(/[^\n]/g, " "));
}

function lineOf(source, index) {
  let line = 1;
  for (let cursor = 0; cursor < index && cursor < source.length; cursor += 1) {
    if (source[cursor] === "\n") line += 1;
  }
  return line;
}

function selectorOf(scopes) {
  const named = scopes.filter((scope) => scope !== "" && !scope.startsWith("@"));
  return named.at(-1) ?? scopes.at(-1) ?? "";
}

/**
 * Minimal structural CSS scanner. It yields every declaration with its
 * enclosing selector, and reports whether the sheet was balanced.
 *
 * It deliberately does not use "is this byte inside a :root block?" positional
 * logic: an unterminated block can make such a test swallow a later violation,
 * which is exactly the smuggling path M2/M3 demonstrate.
 */
export function scanDeclarations(source) {
  const css = blankComments(source);
  const declarations = [];
  const scopes = [];
  let buffer = "";
  let declarationStart = 0;
  let quote = null;
  let parens = 0;
  let opened = 0;
  let closed = 0;

  const flush = () => {
    const text = buffer.trim();
    buffer = "";
    if (text === "" || scopes.length === 0) return;
    const colon = text.indexOf(":");
    if (colon === -1) return;
    const property = text.slice(0, colon).trim();
    if (property === "" || property.includes("{") || property.includes("}")) return;
    declarations.push({
      property,
      value: text.slice(colon + 1).trim(),
      selector: selectorOf(scopes),
      scope: scopes.join(" "),
      index: declarationStart,
      line: lineOf(css, declarationStart),
      isCustomProperty: property.startsWith("--"),
    });
  };

  const append = (char, index) => {
    if (buffer.trim() === "" && !/\s/.test(char)) declarationStart = index;
    buffer += char;
  };

  for (let index = 0; index < css.length; index += 1) {
    const char = css[index];
    if (quote !== null) {
      append(char, index);
      if (char === quote && css[index - 1] !== "\\") quote = null;
      continue;
    }
    if (char === '"' || char === "'") {
      quote = char;
      append(char, index);
      continue;
    }
    if (char === "(") parens += 1;
    if (char === ")") parens = Math.max(0, parens - 1);
    if (parens > 0 || (char !== "{" && char !== "}" && char !== ";")) {
      append(char, index);
      continue;
    }
    if (char === "{") {
      opened += 1;
      scopes.push(buffer.trim().replace(/\s+/g, " "));
      buffer = "";
      continue;
    }
    if (char === "}") {
      closed += 1;
      flush();
      scopes.pop();
      continue;
    }
    flush();
  }
  flush();

  return {
    declarations,
    balanced: opened === closed && quote === null && parens === 0,
    opened,
    closed,
  };
}

function containsBrandLiteral(value) {
  if (/#(?:094c92|00aff1)\b/i.test(value)) return true;
  for (const match of value.matchAll(/\brgba?\(([^)]*)\)/gi)) {
    const parts = match[1]
      .split(/[\s,/]+/)
      .filter((part) => part !== "")
      .slice(0, 3)
      .map(Number);
    if (parts.length !== 3 || parts.some(Number.isNaN)) continue;
    if (BRAND_RGB.some((rgb) => rgb.every((channel, position) => channel === parts[position]))) {
      return true;
    }
  }
  return false;
}

function variableNames(value) {
  return [...value.matchAll(VAR_REFERENCE)].map((match) => match[1]);
}

/**
 * Every custom property that carries a brand colour, directly or through a
 * chain of aliases. Renaming a brand token does not launder it.
 */
function brandAliases(declarations) {
  const custom = new Map();
  for (const declaration of declarations) {
    if (declaration.isCustomProperty) custom.set(declaration.property, declaration.value);
  }
  const brand = new Set();
  for (const [name, value] of custom) {
    if (BRAND_TOKEN_NAME.test(name) || containsBrandLiteral(value)) brand.add(name);
  }
  let changed = true;
  while (changed) {
    changed = false;
    for (const [name, value] of custom) {
      if (brand.has(name)) continue;
      if (variableNames(value).some((reference) => brand.has(reference))) {
        brand.add(name);
        changed = true;
      }
    }
  }
  return brand;
}

function brandReferenceIn(value, aliases) {
  if (containsBrandLiteral(value)) return "brand colour literal";
  for (const name of variableNames(value)) {
    if (BRAND_TOKEN_NAME.test(name)) return `brand token ${name}`;
    if (aliases.has(name)) return `alias ${name} of a brand token`;
  }
  return null;
}

/* ------------------------------------------------------------------ SVG --- */

function attribute(tag, name) {
  // The \\b keeps `d` from matching the d in `id="…"` and `id` from matching
  // the id in `gradientUnits`/`aria-labelledby`.
  return new RegExp(`\\b${name}\\s*=\\s*["']([^"']*)["']`, "i").exec(tag)?.[1] ?? null;
}

/**
 * DESIGN.md 9 identity test: the SVG is the cleared mark when every path `d`
 * and every gradient element it contains is registered artwork. The viewBox
 * is deliberately not part of the test - Todo 32 normalises the non-zero
 * source origin, so identity is the artwork itself, not the canvas. A single
 * foreign path or gradient makes the whole element an impostor.
 */
export function isClearedMark(svgText) {
  const pathData = [...svgText.matchAll(/<path\b[^>]*>/gi)].map((match) =>
    attribute(match[0], "d"),
  );
  if (pathData.length === 0 || pathData.some((d) => d === null)) return false;
  if (!pathData.every((d) => MARK_PATH_SHA256.has(sha256(d)))) return false;
  for (const gradient of svgText.matchAll(SVG_GRADIENT_ELEMENT)) {
    if (!MARK_GRADIENT_SHA256.has(sha256(gradient[0]))) return false;
  }
  return true;
}

function tokenizePath(data) {
  const commands = [];
  const tokens = data.match(/[MmLlHhVvCcSsQqTtAaZz]|-?(?:\d*\.\d+|\d+)(?:[eE][-+]?\d+)?/g) ?? [];
  let letter = null;
  let cursor = 0;
  while (cursor < tokens.length) {
    if (/^[A-Za-z]$/.test(tokens[cursor])) {
      letter = tokens[cursor];
      cursor += 1;
    }
    if (letter === null) return commands;
    const key = letter.toUpperCase();
    const arity = PATH_ARGUMENT_COUNT[key] ?? 0;
    if (arity === 0) {
      commands.push({ key, relative: false, args: [] });
      letter = null;
      continue;
    }
    const args = tokens.slice(cursor, cursor + arity).map(Number);
    if (args.length < arity || args.some(Number.isNaN)) return commands;
    cursor += arity;
    commands.push({ key, relative: letter === letter.toLowerCase(), args });
    if (key === "M") letter = letter === "m" ? "l" : "L";
  }
  return commands;
}

function sign(delta) {
  if (delta > 0) return 1;
  if (delta < 0) return -1;
  return 0;
}

/**
 * Geometric waveform test. Each curve segment is scored by which side of its
 * own endpoint chord its control points fall on; a waveform is a run of
 * segments that keeps flipping sides. This reads the shape, not the name, so
 * a neutrally named, monochrome, gradient-free band is still caught.
 */
export function analysePathWaveform(data) {
  const commands = tokenizePath(data);
  let x = 0;
  let y = 0;
  let startX = 0;
  let startY = 0;
  let previousControl = null;
  let previousKey = null;
  const signs = [];

  for (const { key, relative, args } of commands) {
    const dx = relative ? x : 0;
    const dy = relative ? y : 0;
    let control = null;
    let endX = x;
    let endY = y;

    if (key === "M") {
      endX = args[0] + dx;
      endY = args[1] + dy;
      startX = endX;
      startY = endY;
    } else if (key === "L" || key === "T") {
      endX = args[0] + dx;
      endY = args[1] + dy;
    } else if (key === "H") {
      endX = args[0] + dx;
    } else if (key === "V") {
      endY = args[0] + dy;
    } else if (key === "C" || key === "S" || key === "Q") {
      endX = args[args.length - 2] + dx;
      endY = args[args.length - 1] + dy;
    } else if (key === "A") {
      endX = args[5] + dx;
      endY = args[6] + dy;
    } else if (key === "Z") {
      endX = startX;
      endY = startY;
    }

    if (key === "C") {
      control = { x: (args[0] + args[2]) / 2 + dx, y: (args[1] + args[3]) / 2 + dy };
      previousControl = { x: args[2] + dx, y: args[3] + dy };
    } else if (key === "Q") {
      control = { x: args[0] + dx, y: args[1] + dy };
      previousControl = { x: control.x, y: control.y };
    } else if (key === "S") {
      const reflected =
        previousKey === "C" || previousKey === "S"
          ? { x: 2 * x - previousControl.x, y: 2 * y - previousControl.y }
          : { x, y };
      control = { x: (reflected.x + args[0] + dx) / 2, y: (reflected.y + args[1] + dy) / 2 };
      previousControl = { x: args[0] + dx, y: args[1] + dy };
    } else if (key === "T") {
      control =
        previousKey === "Q" || previousKey === "T"
          ? { x: 2 * x - previousControl.x, y: 2 * y - previousControl.y }
          : { x, y };
      previousControl = { x: control.x, y: control.y };
    } else {
      previousControl = null;
    }

    if (control !== null) {
      signs.push(sign(control.y - (y + endY) / 2));
    } else if (key === "A") {
      signs.push(args[4] === 1 ? 1 : -1);
    }

    x = endX;
    y = endY;
    previousKey = key;
  }

  const meaningful = signs.filter((value) => value !== 0);
  let alternations = 0;
  for (let index = 1; index < meaningful.length; index += 1) {
    if (meaningful[index] !== meaningful[index - 1]) alternations += 1;
  }
  return {
    curveSegments: meaningful.length,
    alternations,
    waveform: alternations >= WAVEFORM_ALTERNATION_THRESHOLD,
  };
}

function svgElements(source) {
  return [...source.matchAll(/<svg\b[^>]*>[\s\S]*?<\/svg\s*>/gi)].map((match) => ({
    text: match[0],
    index: match.index,
  }));
}

function describeSvg(svgText, fallback) {
  const open = /<svg\b[^>]*>/i.exec(svgText)?.[0] ?? "";
  const id = attribute(open, "id");
  const className = attribute(open, "class");
  if (id !== null) return `svg#${id}`;
  if (className !== null) return `svg.${className.trim().split(/\s+/).join(".")}`;
  return fallback;
}

/**
 * Audit one SVG-bearing source (a template, the showcase page, or a standalone
 * `.svg` asset) for non-mark waveforms and for gradients borrowed from the mark.
 */
export function auditSvgSource({ source, file }) {
  const findings = [];
  for (const [ordinal, element] of svgElements(source).entries()) {
    const cleared = isClearedMark(element.text);
    const label = describeSvg(element.text, `svg[${ordinal}]`);
    const line = lineOf(source, element.index);

    for (const gradient of element.text.matchAll(SVG_GRADIENT_ELEMENT)) {
      const id = attribute(gradient[0], "id") ?? "";
      // The mark's own gradients are exempt only inside artwork that passed
      // the identity test, and only when the element is byte-identical to the
      // registered source - a renamed or re-stopped gradient is not the mark's.
      if (cleared && MARK_GRADIENT_SHA256.has(sha256(gradient[0]))) continue;
      findings.push(
        finding(
          "GRADIENT_ON_SURFACE",
          `${file}: ${label} declares <${gradient[1]}Gradient id="${id}">. DESIGN.md 7 permits gradients only inside the cleared mark artwork, and only the mark's own registered gradient elements.`,
          { file, selector: label, detail: `${gradient[1]}Gradient#${id}`, line },
        ),
      );
    }

    // Outside the artwork a brand colour on a presentation attribute is the same
    // violation as a brand colour in a stylesheet; inside it, it is the mark.
    // Inside a cleared mark the check still applies to non-artwork elements
    // (a foreign <rect>/<circle> painted with a brand colour is not lettering).
    const paintSource = cleared
      ? element.text.replace(/<(?:path|stop)\b[^>]*>/gi, "")
      : element.text;
    for (const paint of paintSource.matchAll(
      /\b(fill|stroke|stop-color|flood-color|lighting-color)\s*=\s*["']([^"']*)["']/gi,
    )) {
      if (!containsBrandLiteral(paint[2])) continue;
      findings.push(
        finding(
          "BRAND_COLOR_IN_UI",
          `${file}: ${label} paints ${paint[1]}="${paint[2]}" with a mark-only brand colour. DESIGN.md 2 and 9 reserve the brand tokens for the cleared mark artwork alone.`,
          { file, selector: label, property: paint[1], detail: paint[2], line },
        ),
      );
    }

    if (cleared) continue;

    for (const path of element.text.matchAll(/\bd\s*=\s*["']([^"']+)["']/gi)) {
      const analysis = analysePathWaveform(path[1]);
      if (!analysis.waveform) continue;
      findings.push(
        finding(
          "WAVEFORM_ORNAMENT",
          `${file}: ${label} draws an oscillating waveform path (${analysis.alternations} chord-side flips across ${analysis.curveSegments} curve segments) and is not the cleared mark (registered artwork digests). DESIGN.md 7 and 9 permit no waveform but the mark.`,
          { file, selector: label, detail: path[1].slice(0, 120), line },
        ),
      );
    }
  }
  return findings;
}

function decodeDataUriSvg(value) {
  const sources = [];
  for (const match of value.matchAll(/url\(\s*(["']?)(data:image\/svg\+xml[^"')]+)\1\s*\)/gi)) {
    const payload = match[2];
    try {
      sources.push(
        payload.includes(";base64,")
          ? Buffer.from(payload.split(";base64,")[1], "base64").toString("utf8")
          : decodeURIComponent(payload.replace(/^data:image\/svg\+xml[^,]*,/i, "")),
      );
    } catch {
      sources.push(payload);
    }
  }
  return sources;
}

/** Local `.svg` assets referenced from a stylesheet or a markup file. */
export function collectLocalSvgReferences(source) {
  const references = new Set();
  for (const match of source.matchAll(/url\(\s*["']?([^"')]+\.svg)(?:[?#][^"')]*)?["']?\s*\)/gi)) {
    references.add(match[1]);
  }
  for (const match of source.matchAll(
    /(?:src|href|data|xlink:href)\s*=\s*["']([^"']+\.svg)(?:[?#][^"']*)?["']/gi,
  )) {
    references.add(match[1]);
  }
  return [...references].filter((reference) => !/^(?:https?:)?\/\//i.test(reference));
}

/* ------------------------------------------------------------------ CSS --- */

export function auditStylesheet({ source, file, lineOffset = 0, selectorOverride = null }) {
  const findings = [];
  const { declarations, balanced, opened, closed } = scanDeclarations(source);

  if (!balanced) {
    findings.push(
      finding(
        "MALFORMED_STYLESHEET",
        `${file}: stylesheet does not parse cleanly (${opened} '{' vs ${closed} '}'). A malformed sheet is rejected rather than scanned as if it were clean.`,
        { file, selector: "(file)", detail: `open=${opened} close=${closed}` },
      ),
    );
  }

  const aliases = brandAliases(declarations);

  for (const declaration of declarations) {
    const line = declaration.line + lineOffset;
    const selector = selectorOverride ?? declaration.selector;
    const where = `${selector} { ${declaration.property} }`;

    if (CSS_GRADIENT.test(declaration.value)) {
      findings.push(
        finding(
          "GRADIENT_ON_SURFACE",
          `${file}:${line} ${where} uses a CSS gradient. DESIGN.md 7 permits no gradient on any surface; the mark's ${MARK_GRADIENT_ID} lives inside the artwork and is not a CSS gradient.`,
          { file, selector, property: declaration.property, detail: declaration.value, line },
        ),
      );
    }

    if (declaration.isCustomProperty) continue;

    const brand = brandReferenceIn(declaration.value, aliases);
    if (brand !== null) {
      findings.push(
        finding(
          "BRAND_COLOR_IN_UI",
          `${file}:${line} ${where} resolves to a mark-only brand colour (${brand}). DESIGN.md 2 and 9 forbid brand tokens on text, links, controls, focus rings, rules, borders, icons, backgrounds, and surfaces; only the mark artwork carries them.`,
          { file, selector, property: declaration.property, detail: declaration.value, line },
        ),
      );
    }
  }

  for (const svg of decodeDataUriSvg(source)) {
    findings.push(...auditSvgSource({ source: svg, file: `${file} (inline data: svg)` }));
  }

  return findings;
}

/* ---------------------------------------------------------------- markup --- */

export function auditMarkup({ source, file }) {
  const findings = auditSvgSource({ source, file });
  // Inline style attributes are stylesheets with a shorter reach, not an escape
  // hatch: a gradient or a brand colour is the same violation there.
  for (const match of source.matchAll(/\bstyle\s*=\s*(["'])([^"']*)\1/gi)) {
    const line = lineOf(source, match.index);
    findings.push(
      ...auditStylesheet({
        source: `inline { ${match[2]} }`,
        file,
        lineOffset: line - 1,
        selectorOverride: "style attribute",
      }).filter(({ code }) => code !== "MALFORMED_STYLESHEET"),
    );
  }
  return findings;
}

/* ------------------------------------------------------------- theme.json --- */

function* walkStrings(node, path) {
  if (typeof node === "string") {
    yield [path, node];
    return;
  }
  if (Array.isArray(node)) {
    for (const [index, item] of node.entries()) yield* walkStrings(item, `${path}[${index}]`);
    return;
  }
  if (node !== null && typeof node === "object") {
    for (const [key, value] of Object.entries(node)) {
      yield* walkStrings(value, path === "" ? key : `${path}.${key}`);
    }
  }
}

function* walkPalettes(node, path) {
  if (Array.isArray(node)) {
    for (const [index, item] of node.entries()) yield* walkPalettes(item, `${path}[${index}]`);
    return;
  }
  if (node !== null && typeof node === "object") {
    for (const [key, value] of Object.entries(node)) {
      const next = path === "" ? key : `${path}.${key}`;
      if (key === "palette" && Array.isArray(value)) yield [next, value];
      yield* walkPalettes(value, next);
    }
  }
}

function customPresetName(key) {
  return `--wp--custom--${key.replace(/([a-z0-9])([A-Z])/g, "$1-$2").toLowerCase()}`;
}

export function auditThemeJson({ theme, file }) {
  const findings = [];
  const brandSlugs = new Map();

  for (const [path, palette] of walkPalettes(theme, "")) {
    for (const [index, entry] of palette.entries()) {
      if (entry === null || typeof entry !== "object") continue;
      if (typeof entry.color !== "string" || !containsBrandLiteral(entry.color)) continue;
      brandSlugs.set(String(entry.slug), entry.color);
      findings.push(
        finding(
          "BRAND_COLOR_IN_PALETTE",
          `${file}: ${path}[${index}] registers mark-only brand colour ${entry.color} as palette slug "${entry.slug}". DESIGN.md 2 keeps the brand tokens in settings.custom precisely so no palette, and therefore no editor colour picker, ever offers them.`,
          { file, selector: `${path}[${index}]`, detail: entry.color },
        ),
      );
    }
  }

  const brandCustomKeys = new Set();
  for (const [path, value] of walkStrings(theme.settings?.custom ?? {}, "")) {
    if (containsBrandLiteral(value)) brandCustomKeys.add(customPresetName(path.split(".").at(-1)));
  }

  for (const [path, value] of walkStrings(theme, "")) {
    if (CSS_GRADIENT.test(value)) {
      findings.push(
        finding(
          "GRADIENT_ON_SURFACE",
          `${file}: ${path} declares a CSS gradient. DESIGN.md 7 permits no gradient on any surface, in CSS or in theme.json.`,
          { file, selector: path, detail: value },
        ),
      );
    }

    if (!path.startsWith("styles")) continue;

    const presetSlug = /^var:preset\|color\|(.+)$/.exec(value)?.[1];
    const customReference = /^var:custom\|(.+)$/.exec(value)?.[1];
    let reason = null;
    if (containsBrandLiteral(value)) reason = "brand colour literal";
    else if (presetSlug !== undefined && brandSlugs.has(presetSlug)) {
      reason = `palette preset "${presetSlug}" (${brandSlugs.get(presetSlug)})`;
    } else if (
      customReference !== undefined &&
      brandCustomKeys.has(customPresetName(customReference.split("|").at(-1)))
    ) {
      reason = `settings.custom reference ${customReference}`;
    } else {
      const referenced = variableNames(value).find(
        (name) => BRAND_TOKEN_NAME.test(name) || brandCustomKeys.has(name),
      );
      if (referenced !== undefined) reason = `brand token ${referenced}`;
    }

    if (reason !== null) {
      findings.push(
        finding(
          "BRAND_COLOR_IN_UI",
          `${file}: ${path} resolves to a mark-only brand colour (${reason}). DESIGN.md 2 and 9 forbid brand tokens anywhere in the rendered UI, including indirectly through settings.color.palette and styles.elements.*.`,
          { file, selector: path, detail: value },
        ),
      );
    }
  }

  return findings;
}
