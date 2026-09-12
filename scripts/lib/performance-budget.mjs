import { readdirSync, readFileSync } from "node:fs";
import { join, relative } from "node:path";
import { brotliCompressSync, constants } from "node:zlib";

const KIB = 1024;

/**
 * Plan Todo 21 compressed initial budgets. These are the contract; a run may never relax them.
 */
export const BUDGETS = Object.freeze({
  js: 120 * KIB,
  css: 50 * KIB,
  fonts: 100 * KIB,
  hero: 200 * KIB,
  total: 750 * KIB,
});

const CATEGORY_BY_EXTENSION = new Map([
  [".js", "js"],
  [".mjs", "js"],
  [".cjs", "js"],
  [".css", "css"],
  [".woff2", "fonts"],
  [".woff", "fonts"],
  [".ttf", "fonts"],
  [".otf", "fonts"],
  [".avif", "image"],
  [".webp", "image"],
  [".jpg", "image"],
  [".jpeg", "image"],
  [".png", "image"],
  [".svg", "image"],
]);

/** Assets that are already compressed on the wire and must not be measured through brotli. */
const PRECOMPRESSED = new Set(["fonts", "image"]);

/**
 * Maps a shipped path to its budget category.
 *
 * @param {string} path
 * @returns {"js"|"css"|"fonts"|"image"|"other"}
 */
export function classifyAsset(path) {
  const lower = path.toLowerCase();
  const dot = lower.lastIndexOf(".");
  if (dot < 0) return "other";
  return CATEGORY_BY_EXTENSION.get(lower.slice(dot)) ?? "other";
}

/**
 * Measures the bytes a browser actually transfers for one file.
 *
 * Text assets are measured through brotli (every host in `docs/operations/performance-hosting.md`
 * is required to serve brotli); fonts and images are already compressed containers.
 *
 * @param {string} filePath
 * @param {string} category
 * @returns {number}
 */
export function transferBytes(filePath, category = classifyAsset(filePath)) {
  const raw = readFileSync(filePath);
  if (PRECOMPRESSED.has(category)) return raw.byteLength;
  return brotliCompressSync(raw, {
    params: { [constants.BROTLI_PARAM_QUALITY]: 11 },
  }).byteLength;
}

const walk = (dir, out = []) => {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else if (entry.isFile()) out.push(full);
  }
  return out;
};

/**
 * Collects every asset the theme ships to an anonymous first-time visitor.
 *
 * @param {string} themeRoot
 * @returns {Array<{path: string, transferBytes: number, kind: string}>}
 */
export function collectThemeAssets(themeRoot) {
  const assetsRoot = join(themeRoot, "assets");
  return walk(assetsRoot)
    .map((file) => {
      const kind = classifyAsset(file);
      return { path: relative(themeRoot, file), kind, transferBytes: transferBytes(file, kind) };
    })
    .filter((asset) => asset.kind !== "other")
    .sort((a, b) => a.path.localeCompare(b.path));
}

/**
 * Applies the plan budgets to a measured payload.
 *
 * @param {Array<{path: string, transferBytes: number, kind?: string, role?: string}>} assets
 * @param {typeof BUDGETS} budgets
 */
export function evaluateBudget(assets, budgets = BUDGETS) {
  const buckets = { js: [], css: [], fonts: [], hero: [], total: [] };
  for (const asset of assets) {
    const kind = asset.kind ?? classifyAsset(asset.path);
    if (kind in buckets) buckets[kind].push(asset);
    if (asset.role === "hero") buckets.hero.push(asset);
    buckets.total.push(asset);
  }

  const sum = (list) => list.reduce((bytes, asset) => bytes + asset.transferBytes, 0);
  const totals = Object.fromEntries(Object.entries(buckets).map(([key, list]) => [key, sum(list)]));

  const violations = [];
  for (const [category, limit] of Object.entries(budgets)) {
    if (totals[category] > limit) {
      violations.push({
        category,
        actual: totals[category],
        limit,
        assets: buckets[category].map((asset) => asset.path),
      });
    }
  }

  return { pass: violations.length === 0, totals, violations, assets };
}

const attribute = (tag, name) => {
  const match = tag.match(
    new RegExp(`\\s${name}(?:\\s*=\\s*("([^"]*)"|'([^']*)'|([^\\s>]+)))?`, "i"),
  );
  if (!match) return null;
  return match[2] ?? match[3] ?? match[4] ?? "";
};

/**
 * Audits a rendered document for the Todo 21 rendering rules.
 *
 * @param {string} html
 */
export function auditDocument(html) {
  const findings = [];
  const headMatch = html.match(/<head[^>]*>([\s\S]*?)<\/head>/i);
  const head = headMatch ? headMatch[1] : "";

  for (const tag of head.match(/<script\b[^>]*>/gi) ?? []) {
    const src = attribute(tag, "src");
    const isModule = (attribute(tag, "type") ?? "").toLowerCase() === "module";
    const deferred =
      attribute(tag, "defer") !== null || attribute(tag, "async") !== null || isModule;
    if (src !== null && !deferred) {
      findings.push({
        rule: "render-blocking-script",
        detail: `<script src="${src}"> blocks the first paint`,
      });
    }
  }

  const images = html.match(/<img\b[^>]*>/gi) ?? [];
  let prioritized = 0;
  images.forEach((tag, index) => {
    const src = attribute(tag, "src") ?? "(inline)";
    const width = attribute(tag, "width");
    const height = attribute(tag, "height");
    if (width === null || height === null || width === "" || height === "") {
      findings.push({ rule: "missing-dimensions", detail: `${src} has no intrinsic width/height` });
    }
    const priority = (attribute(tag, "fetchpriority") ?? "").toLowerCase() === "high";
    if (priority) prioritized += 1;
    const lazy = (attribute(tag, "loading") ?? "").toLowerCase() === "lazy";
    if (index > 0 && !lazy && !priority) {
      findings.push({
        rule: "eager-below-fold-media",
        detail: `${src} is neither lazy-loaded nor the LCP image`,
      });
    }
  });
  if (prioritized > 1) {
    findings.push({
      rule: "multiple-priority-images",
      detail: `${prioritized} images claim fetchpriority="high"; only the true LCP image may.`,
    });
  }

  for (const tag of html.match(/<iframe\b[^>]*>/gi) ?? []) {
    if ((attribute(tag, "loading") ?? "").toLowerCase() !== "lazy") {
      findings.push({
        rule: "eager-below-fold-media",
        detail: `${attribute(tag, "src") ?? "iframe"} is not lazy-loaded`,
      });
    }
  }

  return { pass: findings.length === 0, findings };
}

/**
 * Formats a report for evidence logs.
 *
 * @param {ReturnType<typeof evaluateBudget>} report
 */
export function formatBudgetReport(report) {
  const line = (category) =>
    `${category.padEnd(6)} ${String(report.totals[category]).padStart(8)} B  limit ${String(BUDGETS[category]).padStart(8)} B  ${
      report.totals[category] <= BUDGETS[category] ? "PASS" : "FAIL"
    }`;
  return ["js", "css", "fonts", "hero", "total"].map(line).join("\n");
}
