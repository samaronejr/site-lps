#!/usr/bin/env node
/**
 * Structural audit of the built preview.
 *
 * There is no browser runtime in this workspace, so this is the substitute for a
 * visual pass plus the automated part of an accessibility review: it parses each
 * generated page and asserts the shell, landmark, heading, alternative-text, language
 * and no-script guarantees that the theme contract requires. It is deliberately
 * independent from the page builders — it reads the built HTML from disk.
 *
 * Usage: node showcase/institutional-redesign/scripts/audit-pages.mjs
 */

import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, "..", "public");

const walk = (dir) =>
  readdirSync(dir).flatMap((entry) => {
    const full = join(dir, entry);
    return statSync(full).isDirectory() ? walk(full) : [full];
  });

const files = walk(root)
  .filter((file) => file.endsWith(".html"))
  .sort();

const headings = (html) =>
  [...html.matchAll(/<h([1-6])\b[^>]*>/g)].map((match) => Number(match[1]));
const ids = (html) => [...html.matchAll(/\sid="([^"]+)"/g)].map((match) => match[1]);
const internalTargets = (html) =>
  [...html.matchAll(/href="(#[^"]+)"/g)].map((match) => match[1].slice(1));

const checks = [
  ["one h1 per page", (html) => headings(html).filter((level) => level === 1).length === 1],
  [
    "heading order has no skipped level",
    (html) => {
      const levels = headings(html);
      return levels.every((level, index) => index === 0 || level <= levels[index - 1] + 1);
    },
  ],
  ["document language declared", (html) => /<html lang="(pt-BR|en)"/.test(html)],
  ["skip link present", (html) => html.includes("lps-skip-link")],
  ["main landmark present", (html) => html.includes('id="lps-main"')],
  [
    "header and footer shell present",
    (html) => html.includes("lps-site-header") && html.includes("lps-site-footer"),
  ],
  ["no client script", (html) => !/<script\b/i.test(html)],
  // An unresolved localized value renders as one of these; a page that ships one
  // is a content-shape bug, not a copy decision.
  [
    "no unresolved value in the output",
    (html) => !/(?:\[object Object\]|>undefined<|undefined ·|undefined<\/)/.test(html),
  ],
  // Anchors may point anywhere: they are navigable links, not runtime requests.
  // Only resource-loading attributes are constrained, and they must be same-origin.
  [
    "no remote runtime request",
    (html) => {
      const remote = [
        ...html.matchAll(/\s(?:src|srcset)="(https?:\/\/[^"]+)"/g),
        ...html.matchAll(/<link[^>]+href="(https?:\/\/[^"]+)"/g),
        ...html.matchAll(/@import[^;]*?(https?:\/\/[^;]+);/g),
        ...html.matchAll(/url\(\s*["']?(https?:\/\/[^"')]+)/g),
      ];
      return remote.length === 0;
    },
  ],
  ["title present", (html) => /<title>[^<]{8,}<\/title>/.test(html)],
  ["meta description present", (html) => /<meta name="description" content="[^"]{40,}"/.test(html)],
  ["canonical link present", (html) => /<link rel="canonical"/.test(html)],
  ["locale alternates present", (html) => (html.match(/hreflang="(pt-BR|en)"/g) ?? []).length >= 2],
  [
    "every image has alt",
    (html) => [...html.matchAll(/<img\b[^>]*>/g)].every((match) => /\balt="/.test(match[0])),
  ],
  [
    "every form control has a label",
    (html) =>
      [...html.matchAll(/<(input|select|textarea)\b[^>]*>/g)].every((match) => {
        const control = match[0];
        if (/type="(hidden|submit|button)"/.test(control)) return true;
        const id = control.match(/\sid="([^"]+)"/)?.[1];
        if (id && html.includes(`for="${id}"`)) return true;
        return (
          /aria-label=/.test(control) ||
          /<label[^>]*>[\s\S]{0,200}?<\/(label|fieldset)>/.test(html.slice(match.index ?? 0))
        );
      }),
  ],
  ["buttons are real buttons or links", (html) => !/<div[^>]*role="button"/.test(html)],
  [
    "table captions present",
    (html) =>
      (html.match(/<table\b/g) ?? []).length === (html.match(/<caption>|<caption\b/g) ?? []).length,
  ],
  [
    "panel disclosures use details/summary",
    (html) =>
      !html.includes("lps-shell-disclosure") ||
      /<details class="lps-shell-disclosure">\s*<summary>/.test(html),
  ],
];

const inPageAnchors = [
  [
    "every in-page anchor resolves",
    (html) => {
      const available = new Set(ids(html));
      return internalTargets(html).every((target) => available.has(target));
    },
  ],
];

let findings = 0;
const perCheckFailures = new Map();

for (const file of files) {
  const html = readFileSync(file, "utf8");
  const failures = [];

  for (const [name, test] of [...checks, ...inPageAnchors]) {
    if (!test(html)) {
      failures.push(name);
      perCheckFailures.set(name, (perCheckFailures.get(name) ?? 0) + 1);
    }
  }

  // Duplicate IDs break anchor navigation and label associations.
  const seen = new Set();
  const duplicates = ids(html).filter((id) => {
    if (seen.has(id)) return true;
    seen.add(id);
    return false;
  });
  if (duplicates.length > 0) failures.push(`duplicate ids: ${[...new Set(duplicates)].join(", ")}`);

  if (failures.length > 0) {
    findings += failures.length;
    console.log(`FAIL  ${relative(root, file)}`);
    for (const failure of failures) console.log(`      · ${failure}`);
  }
}

const passed = files.length * (checks.length + inPageAnchors.length) - findings;
console.log(`\n${files.length} pages · ${passed} assertions passed · ${findings} findings`);
if (perCheckFailures.size > 0) {
  console.log("Failing checks:");
  for (const [name, count] of perCheckFailures) console.log(`  ${count}×  ${name}`);
  process.exitCode = 1;
}
