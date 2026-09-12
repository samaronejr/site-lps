#!/usr/bin/env node
/**
 * Documentation checker for the LPS maintainer handoff set.
 *
 * It fails on:
 *   - a broken relative markdown link,
 *   - a referenced repository file that does not exist,
 *   - a documented command that no real command surface provides
 *     (package.json scripts, composer scripts, the plugin WP-CLI registration, or a node entry point),
 *   - a removed prerequisite in a documented `## Prerequisites` section,
 *   - a role guide claiming a capability that SecurityPolicy denies,
 *   - documentation whose declared source moved (stale) or whose review window expired,
 *   - a required production setting, collection, role, recurring task, update path, or recovery
 *     action that appears in no document (and a coverage item its own source does not contain).
 *
 * Usage:
 *   node tests/docs/docs-checker.mjs [--contract=docs/documentation-contract.json]
 *                                    [--report=<path>] [--now=<ISO date>] [--quiet]
 * Exit codes: 0 clean, 1 findings, 2 usage/contract error.
 */

import { createHash } from "node:crypto";
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, normalize, relative, resolve } from "node:path";
import process from "node:process";

/**
 * @typedef {{rule: string, message: string, doc?: string, line?: number}} Finding
 * @typedef {{status: string, findings: Finding[], advisories: Finding[], documents: string[],
 *   coverage: {total: number, documented: number, items: object[]}}} DocumentationReport
 */

const REFERENCE_EXTENSIONS = new Set([
  "md",
  "mjs",
  "js",
  "json",
  "php",
  "css",
  "html",
  "yaml",
  "yml",
  "csv",
  "conf",
  "py",
  "txt",
  "woff2",
  "xml",
  "neon",
  "dist",
]);
const SKIPPED_ROOT_SEGMENTS = new Set([".omo", "dist", "node_modules", "vendor", "test-results"]);
const COMPOSER_BUILTINS = new Set(["validate", "install", "update", "dump-autoload", "audit"]);
const NPM_BUILTINS = new Set(["ci", "install", "audit"]);

/**
 * Reads a UTF-8 file.
 *
 * @param {string} path Absolute path.
 */
function read(path) {
  return readFileSync(path, "utf8");
}

/**
 * Splits a markdown document into lines with fence awareness.
 *
 * @param {string} text Document text.
 * @returns {{number: number, text: string, fenced: boolean}[]}
 */
function scanLines(text) {
  const lines = [];
  let fenced = false;
  text.split("\n").forEach((line, index) => {
    if (/^\s*```/.test(line)) {
      fenced = !fenced;
      lines.push({ number: index + 1, text: line, fenced: true });
      return;
    }
    lines.push({ number: index + 1, text: line, fenced });
  });
  return lines;
}

/**
 * Returns GitHub-style anchors for a markdown document.
 *
 * @param {string} text Document text.
 * @returns {Set<string>}
 */
function headingAnchors(text) {
  const anchors = new Set();
  for (const line of text.split("\n")) {
    const match = /^#{1,6}\s+(.*)$/.exec(line);
    if (!match) continue;
    anchors.add(
      match[1]
        .trim()
        .toLowerCase()
        .replace(/`/g, "")
        .replace(/[^\p{L}\p{N}\s-]/gu, "")
        .replace(/\s+/g, "-"),
    );
  }
  return anchors;
}

/**
 * Returns whether a backticked token looks like a repository path worth checking.
 *
 * @param {string} root  Repository root.
 * @param {string} token Candidate token.
 */
function isCheckableReference(root, token) {
  if (!token.includes("/") || /\s|[<>|]/.test(token)) return false;
  if (/^(?:https?:|mailto:|\/|~|\.)/.test(token)) return false;
  const [head] = token.split("/");
  if (SKIPPED_ROOT_SEGMENTS.has(head)) return false;
  if (!existsSync(join(root, head))) return false;
  const extension = token.split(".").pop()?.toLowerCase() ?? "";
  return token.includes("*") || REFERENCE_EXTENSIONS.has(extension);
}

/**
 * Resolves a possibly globbed repository reference.
 *
 * @param {string} root  Repository root.
 * @param {string} token Reference token.
 */
function referenceExists(root, token) {
  if (!token.includes("*")) return existsSync(join(root, token));
  const directory = dirname(token);
  const pattern = token.slice(directory.length + 1);
  const absolute = join(root, directory);
  if (!existsSync(absolute)) return false;
  const expression = new RegExp(
    `^${pattern
      .split("*")
      .map((part) => part.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"))
      .join(".*")}$`,
  );
  return readdirSync(absolute).some((entry) => expression.test(entry));
}

/**
 * Parses the WP-CLI surface actually registered by the content-model plugin.
 *
 * @param {string} root      Repository root.
 * @param {object} surfaces  Contract command surfaces.
 * @returns {{registered: Set<string>, invokable: Set<string>}}
 */
function parseCliSurface(root, surfaces) {
  const registered = new Set();
  const invokable = new Set();
  const registrationPath = join(root, surfaces.cliRegistration);
  const registration = read(registrationPath);
  const classDirectory = join(root, surfaces.cliClassDirectory);
  for (const match of registration.matchAll(/add_command\(\s*'([^']+)'\s*,\s*([^)]+?)\s*\)\s*;/g)) {
    const name = match[1];
    const target = match[2];
    registered.add(name);
    const classMatch = /([A-Za-z_]+)::class/.exec(target);
    if (!classMatch) {
      invokable.add(name);
      continue;
    }
    const file = join(classDirectory, `class-${classMatch[1].toLowerCase()}.php`);
    if (!existsSync(file)) continue;
    const source = read(file);
    for (const method of source.matchAll(/public function ([a-z_0-9]+)\s*\(/g)) {
      const methodName = method[1];
      if (methodName === "__invoke") {
        invokable.add(name);
        continue;
      }
      const subcommand = methodName.replace(/_/g, "-");
      registered.add(`${name} ${subcommand}`);
      invokable.add(`${name} ${subcommand}`);
    }
  }
  return { registered, invokable };
}

/**
 * Parses the role-to-action policy from SecurityPolicy.
 *
 * @param {string} root       Repository root.
 * @param {string} policyPath Contract policy source path.
 * @returns {Map<string, string[]>}
 */
function parsePolicyActions(root, policyPath) {
  const source = read(join(root, policyPath));
  const block = /private const ACTIONS = array\(([\s\S]*?)\n\t\);/.exec(source);
  if (!block) throw new Error(`SecurityPolicy ACTIONS map not found in ${policyPath}`);
  const actions = new Map();
  for (const row of block[1].matchAll(/'([a-z-]+)'\s*=>\s*array\(([^)]*)\)/g)) {
    actions.set(
      row[1],
      [...row[2].matchAll(/'([a-z-]+)'/g)].map((entry) => entry[1]),
    );
  }
  return actions;
}

/**
 * Normalizes a documented shell line into argument tokens.
 *
 * @param {string} line Raw line.
 * @returns {string[]}
 */
function commandTokens(line) {
  let text = line.trim().replace(/\s*\\$/, "");
  text = text.replace(/^\$\s+/, "");
  const tokens = text.split(/\s+/).filter(Boolean);
  while (tokens.length > 0 && (/^[A-Z_][A-Z0-9_]*=/.test(tokens[0]) || tokens[0] === "export")) {
    tokens.shift();
  }
  return tokens;
}

/**
 * Validates one documented command against the real surfaces.
 *
 * @param {string[]} tokens  Command tokens.
 * @param {object}   context Checker context.
 * @returns {{surface: string, ok: boolean, detail?: string} | null}
 */
function validateCommand(tokens, context) {
  const [head, ...rest] = tokens;
  const isPlaceholder = (token) => token === undefined || /[<>]/.test(token);
  if (head === "npm") {
    if (rest[0] === "run") {
      const script = rest[1];
      if (isPlaceholder(script)) return null;
      return {
        surface: "npm-script",
        ok: Object.hasOwn(context.npmScripts, script),
        detail: `package.json scripts has no "${script}"`,
      };
    }
    if (rest[0] === "test") {
      return { surface: "npm-script", ok: Object.hasOwn(context.npmScripts, "test") };
    }
    if (NPM_BUILTINS.has(rest[0])) return { surface: "npm-builtin", ok: true };
    return null;
  }
  if (head === "npx") {
    const target = rest.find((token) => /\.(?:mjs|js|spec\.mjs)$/.test(token));
    if (!target || isPlaceholder(target)) return { surface: "npx", ok: true };
    return {
      surface: "npx",
      ok: existsSync(join(context.root, target)),
      detail: `${target} does not exist`,
    };
  }
  if (head === "node") {
    const target = rest[0];
    if (isPlaceholder(target)) return null;
    return {
      surface: "node",
      ok: existsSync(join(context.root, target)),
      detail: `${target} does not exist`,
    };
  }
  if (head === "tools/composer" || head === "composer") {
    const script = rest[0];
    if (isPlaceholder(script)) return null;
    if (head === "tools/composer" && !existsSync(join(context.root, "tools/composer"))) {
      return { surface: "composer", ok: false, detail: "tools/composer does not exist" };
    }
    const known = Object.hasOwn(context.composerScripts, script) || COMPOSER_BUILTINS.has(script);
    return { surface: "composer", ok: known, detail: `composer.json has no "${script}" script` };
  }
  if (head === "wp") {
    if (rest[0] !== "lps") return null;
    const words = [];
    for (const token of rest) {
      if (token.startsWith("-") || /[<>]/.test(token)) break;
      words.push(token);
    }
    for (let length = words.length; length >= 2; length -= 1) {
      const candidate = words.slice(0, length).join(" ");
      if (context.cli.invokable.has(candidate)) return { surface: "wp-cli", ok: true };
    }
    const registeredOnly = words
      .map((_, index) => words.slice(0, index + 1).join(" "))
      .filter((candidate) => context.cli.registered.has(candidate));
    const detail =
      registeredOnly.length > 0
        ? `"wp ${registeredOnly.at(-1)}" is registered but has no directly invokable command; use one of its subcommands`
        : `no plugin WP-CLI command matches "wp ${words.join(" ")}"`;
    return { surface: "wp-cli", ok: false, detail };
  }
  return null;
}

/**
 * Extracts the documented step text a command belongs to.
 *
 * @param {string} lineText Raw line text.
 */
function stepText(lineText) {
  const trimmed = lineText.trim().replace(/^[-*]\s+/, "");
  return trimmed === "" ? undefined : trimmed;
}

/**
 * Runs the link, reference and command rules over one document.
 *
 * @param {string} doc     Repository-relative document path.
 * @param {object} context Checker context.
 * @returns {Finding[]}
 */
function checkDocument(doc, context) {
  const absolute = join(context.root, doc);
  if (!existsSync(absolute)) {
    return [{ rule: "missing-document", doc, message: `Documented file ${doc} does not exist.` }];
  }
  const text = read(absolute);
  const findings = [];
  for (const line of scanLines(text)) {
    if (!line.fenced) {
      for (const link of line.text.matchAll(/\[[^\]]*\]\(([^)\s]+)\)/g)) {
        const raw = link[1];
        if (/^(?:https?:|mailto:|#)/.test(raw)) continue;
        const [pathPart, anchor] = raw.split("#");
        const target = normalize(join(dirname(doc), pathPart));
        if (!existsSync(join(context.root, target))) {
          findings.push({
            rule: "broken-relative-link",
            doc,
            line: line.number,
            target,
            message: `${doc}:${line.number} links to ${target}, which does not exist.`,
          });
          continue;
        }
        if (anchor && target.endsWith(".md")) {
          const anchors = headingAnchors(read(join(context.root, target)));
          if (!anchors.has(anchor)) {
            findings.push({
              rule: "broken-relative-link",
              doc,
              line: line.number,
              target: `${target}#${anchor}`,
              message: `${doc}:${line.number} links to anchor #${anchor}, which ${target} does not define.`,
            });
          }
        }
      }
    }
    const inline = line.fenced
      ? []
      : [...line.text.matchAll(/`([^`]+)`/g)].map((match) => match[1]);
    for (const token of inline) {
      if (isCheckableReference(context.root, token) && !referenceExists(context.root, token)) {
        findings.push({
          rule: "missing-referenced-file",
          doc,
          line: line.number,
          target: token,
          message: `${doc}:${line.number} references ${token}, which does not exist.`,
        });
      }
    }
    const candidates = line.fenced && !/^\s*```/.test(line.text) ? [line.text] : inline;
    for (const candidate of candidates) {
      if (/^\s*#/.test(candidate)) continue;
      const tokens = commandTokens(candidate);
      if (tokens.length === 0) continue;
      const result = validateCommand(tokens, context);
      if (!result || result.ok) continue;
      findings.push({
        rule: "unknown-command",
        doc,
        line: line.number,
        command: tokens.join(" "),
        surface: result.surface,
        step: stepText(line.text),
        message: `${doc}:${line.number} documents "${tokens.join(" ")}" but ${result.detail}.`,
      });
    }
  }
  return findings;
}

/**
 * Returns the body of the `## Prerequisites` section, or an empty string.
 *
 * @param {string} text Document text.
 */
function prerequisiteSection(text) {
  const lines = text.split("\n");
  const start = lines.findIndex((line) => /^##\s+Prerequisites\s*$/i.test(line));
  if (start === -1) return "";
  const rest = lines.slice(start + 1);
  const end = rest.findIndex((line) => /^##\s/.test(line));
  return (end === -1 ? rest : rest.slice(0, end)).join("\n");
}

/**
 * Checks the `## Prerequisites` section of a document.
 *
 * @param {object} entry   Contract prerequisite entry.
 * @param {object} context Checker context.
 * @returns {Finding[]}
 */
function checkPrerequisites(entry, context) {
  const absolute = join(context.root, entry.doc);
  if (!existsSync(absolute)) {
    return [
      {
        rule: "missing-prerequisite",
        doc: entry.doc,
        message: `${entry.doc} does not exist, so its ## Prerequisites section cannot be verified.`,
      },
    ];
  }
  const text = read(absolute);
  const body = prerequisiteSection(text);
  return entry.tokens
    .filter((token) => !body.includes(token))
    .map((token) => ({
      rule: "missing-prerequisite",
      doc: entry.doc,
      token,
      message: `${entry.doc} lost the required prerequisite "${token}" from its ## Prerequisites section.`,
    }));
}

/**
 * Cross-checks documented role capability tables against SecurityPolicy.
 *
 * @param {string} doc     Document path.
 * @param {object} context Checker context.
 * @returns {Finding[]}
 */
function checkCapabilityClaims(doc, context) {
  const absolute = join(context.root, doc);
  if (!existsSync(absolute)) return [];
  const lines = read(absolute).split("\n");
  const findings = [];
  let column = -1;
  lines.forEach((line, index) => {
    if (!line.trim().startsWith("|")) {
      column = -1;
      return;
    }
    const cells = line
      .split("|")
      .slice(1, -1)
      .map((cell) => cell.trim());
    if (cells.some((cell) => cell === "Allowed actions (policy)")) {
      column = cells.indexOf("Allowed actions (policy)");
      return;
    }
    if (column === -1 || /^-+$/.test(cells[0]?.replace(/[\s:]/g, "") ?? "")) return;
    const role = cells[0]?.replace(/[`*]/g, "").trim() ?? "";
    if (!context.policyActions.has(role)) return;
    const claimed = [...(cells[column] ?? "").matchAll(/`([a-z-]+)`/g)].map((match) => match[1]);
    const allowed = context.policyActions.get(role) ?? [];
    const extra = claimed.filter((action) => !allowed.includes(action));
    const missing = allowed.filter((action) => !claimed.includes(action));
    if (extra.length === 0 && missing.length === 0) return;
    findings.push({
      rule: "capability-claim-mismatch",
      doc,
      line: index + 1,
      role,
      claimed,
      allowed,
      message:
        `${doc}:${index + 1} claims actions [${claimed.join(", ")}] for role "${role}" but SecurityPolicy allows ` +
        `[${allowed.join(", ")}]${extra.length > 0 ? `; denied: ${extra.join(", ")}` : ""}` +
        `${missing.length > 0 ? `; undocumented: ${missing.join(", ")}` : ""}.`,
    });
  });
  return findings;
}

/**
 * Verifies documentation freshness against the sources it describes.
 *
 * @param {object} entry   Freshness entry.
 * @param {object} context Checker context.
 * @returns {Finding[]}
 */
function checkFreshness(entry, context) {
  const findings = [];
  const changed = [];
  for (const source of entry.sources) {
    const absolute = join(context.root, source.path);
    if (!existsSync(absolute)) {
      changed.push(source.path);
      continue;
    }
    const digest = createHash("sha256").update(readFileSync(absolute)).digest("hex");
    if (digest !== source.sha256) changed.push(source.path);
  }
  if (changed.length > 0) {
    findings.push({
      rule: "stale-documentation",
      id: entry.id,
      kind: entry.kind,
      doc: entry.doc,
      changedSources: changed,
      message:
        `${entry.doc} step "${entry.id}" was reviewed against ${changed.join(", ")} on ${entry.reviewedAt}; ` +
        "that source changed, so the documented step is stale and must be re-reviewed.",
    });
  }
  const reviewed = Date.parse(`${entry.reviewedAt}T00:00:00Z`);
  const ageDays = Math.floor((Date.parse(context.now) - reviewed) / 86400000);
  if (Number.isFinite(entry.maxAgeDays) && ageDays > entry.maxAgeDays) {
    findings.push({
      rule: "expired-documentation",
      id: entry.id,
      kind: entry.kind,
      doc: entry.doc,
      ageDays,
      maxAgeDays: entry.maxAgeDays,
      message:
        `${entry.doc} step "${entry.id}" expired: reviewed ${ageDays} days ago on ${entry.reviewedAt}, ` +
        `maximum ${entry.maxAgeDays} days.`,
    });
  }
  return findings;
}

/**
 * Runs the whole documentation contract.
 *
 * @param {{root: string, contractPath: string, now?: string}} options Checker options.
 * @returns {Promise<DocumentationReport>}
 */
export async function checkDocumentation(options) {
  const root = resolve(options.root);
  const contractPath = options.contractPath;
  const contract = JSON.parse(read(join(root, contractPath)));
  const context = {
    root,
    now: options.now ?? new Date().toISOString(),
    npmScripts: JSON.parse(read(join(root, contract.commandSurfaces.packageJson))).scripts ?? {},
    composerScripts:
      JSON.parse(read(join(root, contract.commandSurfaces.composerJson))).scripts ?? {},
    cli: parseCliSurface(root, contract.commandSurfaces),
    policyActions: parsePolicyActions(root, contract.policySource),
  };

  const findings = [];
  const documents = contract.documentSet;
  for (const doc of documents) {
    findings.push(...checkDocument(doc, context));
    findings.push(...checkCapabilityClaims(doc, context));
  }
  for (const entry of contract.prerequisites ?? []) {
    findings.push(...checkPrerequisites(entry, context));
  }
  for (const entry of contract.freshness ?? []) {
    findings.push(...checkFreshness(entry, context));
  }

  const corpus = documents
    .filter((doc) => existsSync(join(root, doc)))
    .map((doc) => ({ doc, text: read(join(root, doc)) }));
  const coverageItems = [];
  for (const item of contract.coverage ?? []) {
    const sourcePath = join(root, item.source.file);
    const sourceOk = existsSync(sourcePath) && read(sourcePath).includes(item.source.mustContain);
    if (!sourceOk) {
      findings.push({
        rule: "unverifiable-coverage-item",
        id: item.id,
        category: item.category,
        message:
          `Coverage item "${item.id}" declares source ${item.source.file} containing ` +
          `${item.source.mustContain}, which is not present. The item is unverifiable and must not be documented as fact.`,
      });
      coverageItems.push({ ...item, documented: false, verified: false, documentedIn: [] });
      continue;
    }
    const scope = item.requiredIn
      ? corpus.filter((entry) => entry.doc === item.requiredIn)
      : corpus;
    const documentedIn = scope
      .filter((entry) => entry.text.includes(item.token))
      .map((entry) => entry.doc);
    if (documentedIn.length === 0) {
      findings.push({
        rule: "undocumented-item",
        id: item.id,
        category: item.category,
        token: item.token,
        message:
          `${item.category} "${item.token}" (${item.id}, defined in ${item.source.file}) appears in no ` +
          `document of the handoff set${item.requiredIn ? ` (required in ${item.requiredIn})` : ""}.`,
      });
    }
    coverageItems.push({
      ...item,
      documented: documentedIn.length > 0,
      verified: true,
      documentedIn,
    });
  }

  const advisories = [];
  for (const doc of contract.referenceDocuments ?? []) {
    advisories.push(...checkDocument(doc, context));
  }

  return {
    status: findings.length === 0 ? "passed" : "failed",
    schemaVersion: 1,
    now: context.now,
    documents,
    findings,
    advisories,
    coverage: {
      total: coverageItems.length,
      documented: coverageItems.filter((item) => item.documented).length,
      items: coverageItems,
    },
  };
}

/**
 * CLI entry point.
 *
 * @param {string[]} argv Raw arguments.
 */
async function main(argv) {
  const flags = {};
  for (const argument of argv) {
    const match = /^--([^=]+)(?:=(.*))?$/.exec(argument);
    if (match) flags[match[1]] = match[2] ?? true;
  }
  const report = await checkDocumentation({
    root: process.cwd(),
    contractPath:
      typeof flags.contract === "string" ? flags.contract : "docs/documentation-contract.json",
    now: typeof flags.now === "string" ? flags.now : undefined,
  });
  const output = `${JSON.stringify(report, null, 2)}\n`;
  if (typeof flags.report === "string") {
    mkdirSync(dirname(resolve(flags.report)), { recursive: true });
    writeFileSync(flags.report, output);
  }
  if (!flags.quiet) process.stdout.write(output);
  process.exitCode = report.status === "passed" ? 0 : 1;
}

const invokedDirectly =
  process.argv[1] && relative(process.cwd(), process.argv[1]).endsWith("docs-checker.mjs");
if (invokedDirectly) {
  await main(process.argv.slice(2));
}
