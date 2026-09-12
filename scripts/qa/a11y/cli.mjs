/**
 * `npm run qa:a11y` lane.
 *
 * The lane refuses to pass on inference: every route in the inventory must have a
 * fresh captured document, an axe run, a pa11y run, and a keyboard-journey result.
 */

import { createHash } from "node:crypto";
import { readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import {
  auditCorpus,
  auditDocument,
  auditStylesheet,
  auditTemplateSource,
  summarize,
} from "../../lib/a11y.mjs";
import { KEYBOARD_JOURNEYS, routes, templateCoverage } from "../../lib/a11y-inventory.mjs";

const DEFAULTS = {
  snapshotDir: ".omo/evidence/task-23/live/pages",
  css: "wp-content/themes/lps-theme/assets/css/theme.css",
  corpusDir: "content/corpus",
  axe: ".omo/evidence/task-23/live/axe-report.json",
  pa11y: ".omo/evidence/task-23/live/pa11y-report.json",
  keyboard: ".omo/evidence/task-23/live/keyboard-journeys.json",
  templateDir: "wp-content/themes/lps-theme/templates",
};

function option(argv, name, fallback) {
  const index = argv.indexOf(`--${name}`);
  return index === -1 ? fallback : argv[index + 1];
}

async function readJson(file) {
  try {
    return JSON.parse(await readFile(file, "utf8"));
  } catch (error) {
    if (error.code === "ENOENT") return null;
    throw error;
  }
}

/** Runs the complete accessibility lane. */
export async function runA11yQa(argv = []) {
  const settings = {
    snapshotDir: option(argv, "snapshot-dir", DEFAULTS.snapshotDir),
    css: option(argv, "css", DEFAULTS.css),
    corpusDir: option(argv, "corpus-dir", DEFAULTS.corpusDir),
    axe: option(argv, "axe", DEFAULTS.axe),
    pa11y: option(argv, "pa11y", DEFAULTS.pa11y),
    keyboard: option(argv, "keyboard", DEFAULTS.keyboard),
    templateDir: option(argv, "template-dir", DEFAULTS.templateDir),
  };
  const findings = [];
  const inventory = routes();

  const coverage = await templateCoverage(settings.templateDir);
  for (const template of coverage.uncovered) {
    findings.push({
      code: "lps_a11y_template_not_covered",
      impact: "critical",
      criterion: "Todo 23 route/state inventory",
      route: `${settings.templateDir}/${template}`,
      page: template,
      selector: template,
      message: "A public block template is not claimed by any inventory route.",
    });
  }
  for (const template of coverage.unknown) {
    findings.push({
      code: "lps_a11y_inventory_template_unknown",
      impact: "serious",
      criterion: "Todo 23 route/state inventory",
      route: `${settings.templateDir}/${template}`,
      page: template,
      selector: template,
      message: "The inventory claims a template the theme does not ship.",
    });
  }

  for (const template of coverage.templates) {
    const source = await readFile(path.join(settings.templateDir, template), "utf8");
    findings.push(...auditTemplateSource(template, source));
  }

  const manifest = await readJson(path.join(settings.snapshotDir, "manifest.json"));
  const captured = new Map();
  if (manifest) {
    for (const entry of manifest.pages ?? []) captured.set(entry.id, entry);
  }

  const documents = [];
  for (const route of inventory) {
    const entry = captured.get(route.id);
    if (!entry) {
      findings.push({
        code: "lps_a11y_route_capture_missing",
        impact: "critical",
        criterion: "Todo 23 acceptance: fresh results for the complete route inventory",
        route: route.path,
        page: route.id,
        selector: route.template,
        message: "No fresh captured document exists for this route/state.",
      });
      continue;
    }
    const expectedStatus = route.expectStatus ?? 200;
    if (entry.status !== expectedStatus) {
      findings.push({
        code: "lps_a11y_route_status_unexpected",
        impact: "critical",
        criterion: "Todo 23 route/state inventory",
        route: route.path,
        page: route.id,
        selector: route.template,
        message: `Captured HTTP ${entry.status}, expected ${expectedStatus}.`,
      });
      continue;
    }
    const html = await readFile(path.join(settings.snapshotDir, entry.file), "utf8");
    const digest = createHash("sha256").update(html).digest("hex");
    if (entry.sha256 && entry.sha256 !== digest) {
      findings.push({
        code: "lps_a11y_snapshot_tampered",
        impact: "critical",
        criterion: "Evidence integrity",
        route: route.path,
        page: route.id,
        selector: entry.file,
        message: "The captured document does not match the checksum recorded at capture time.",
      });
      continue;
    }
    const report = auditDocument({ ...route, html });
    documents.push(report);
    findings.push(...report.findings);
  }

  findings.push(...auditStylesheet(await readFile(settings.css, "utf8"), settings.css));
  findings.push(...(await auditCorpus(settings.corpusDir)));

  const axe = await readJson(settings.axe);
  if (!axe) {
    findings.push({
      code: "lps_a11y_axe_report_missing",
      impact: "critical",
      criterion: "Todo 23 acceptance: fresh axe results",
      route: settings.axe,
      page: "axe",
      selector: "axe-core",
      message: "No axe report is attached to this run.",
    });
  } else {
    const scanned = new Set((axe.pages ?? []).map((page) => page.id));
    for (const route of inventory) {
      if (!scanned.has(route.id)) {
        findings.push({
          code: "lps_a11y_axe_coverage_missing",
          impact: "critical",
          criterion: "Todo 23 acceptance: complete route inventory has fresh axe results",
          route: route.path,
          page: route.id,
          selector: route.template,
          message: "The axe run does not cover this route/state.",
        });
      }
    }
    for (const page of axe.pages ?? []) {
      for (const violation of page.violations ?? []) {
        if (!["serious", "critical"].includes(violation.impact)) continue;
        findings.push({
          code: `axe_${violation.id}`,
          impact: violation.impact,
          criterion:
            (violation.tags ?? []).filter((tag) => tag.startsWith("wcag")).join(",") || "axe-core",
          route: page.url,
          page: page.id,
          selector: (violation.nodes ?? [])[0]?.target?.join(" ") ?? "unknown",
          message: violation.help ?? violation.description ?? violation.id,
        });
      }
    }
  }

  const pa11y = await readJson(settings.pa11y);
  if (!pa11y) {
    findings.push({
      code: "lps_a11y_pa11y_report_missing",
      impact: "critical",
      criterion: "Todo 23 acceptance: fresh pa11y results",
      route: settings.pa11y,
      page: "pa11y",
      selector: "pa11y",
      message: "No pa11y report is attached to this run.",
    });
  } else {
    const scanned = new Set((pa11y.pages ?? []).map((page) => page.id));
    for (const route of inventory) {
      if (!scanned.has(route.id)) {
        findings.push({
          code: "lps_a11y_pa11y_coverage_missing",
          impact: "critical",
          criterion: "Todo 23 acceptance: complete route inventory has fresh pa11y results",
          route: route.path,
          page: route.id,
          selector: route.template,
          message: "The pa11y run does not cover this route/state.",
        });
      }
    }
    for (const page of pa11y.pages ?? []) {
      for (const issue of page.issues ?? []) {
        if (issue.type !== "error") continue;
        findings.push({
          code: `pa11y_${issue.code}`,
          impact: "serious",
          criterion: issue.code,
          route: page.url,
          page: page.id,
          selector: issue.selector ?? "unknown",
          message: issue.message,
        });
      }
    }
  }

  const keyboard = await readJson(settings.keyboard);
  if (!keyboard) {
    findings.push({
      code: "lps_a11y_keyboard_report_missing",
      impact: "critical",
      criterion: "Todo 23 QA scenario: Playwright keyboard journeys",
      route: settings.keyboard,
      page: "keyboard",
      selector: "playwright",
      message: "No keyboard-journey report is attached to this run.",
    });
  } else {
    const results = new Map(
      (keyboard.journeys ?? []).map((journey) => [`${journey.id}:${journey.locale}`, journey]),
    );
    for (const journey of KEYBOARD_JOURNEYS) {
      for (const locale of journey.locales) {
        const result = results.get(`${journey.id}:${locale}`);
        if (!result) {
          findings.push({
            code: "lps_a11y_keyboard_journey_missing",
            impact: "critical",
            criterion: "Todo 23 QA scenario: Playwright keyboard journeys in both locales",
            route: `${journey.id}/${locale}`,
            page: journey.id,
            selector: locale,
            message: "This keyboard journey was not executed in this locale.",
          });
          continue;
        }
        if (result.status !== "passed") {
          findings.push({
            code: "lps_a11y_keyboard_journey_failed",
            impact: "critical",
            criterion: "Todo 23 acceptance: no traps, focus visible and unobscured",
            route: result.url ?? `${journey.id}/${locale}`,
            page: journey.id,
            selector: result.selector ?? locale,
            message: result.message ?? "The keyboard journey failed.",
          });
        }
      }
    }
  }

  findings.sort((left, right) =>
    `${left.page}${left.code}${left.selector}`.localeCompare(
      `${right.page}${right.code}${right.selector}`,
    ),
  );
  const summary = summarize(findings);
  return {
    lane: "a11y",
    schemaVersion: 1,
    status: summary.blocking === 0 ? "passed" : "failed",
    generatedAt: new Date().toISOString(),
    inventory: {
      routes: inventory.length,
      templates: coverage.templates.length,
      templatesCovered: coverage.covered.length,
      templatesAuditedAsSource: coverage.templates.length,
      fallbackOnlyTemplates: coverage.fallbackOnly,
      keyboardJourneys: KEYBOARD_JOURNEYS.reduce(
        (total, journey) => total + journey.locales.length,
        0,
      ),
      documentsAudited: documents.length,
    },
    ...summary,
    findings,
  };
}

/** CLI entry point used by `scripts/run-qa.mjs`. */
export async function runA11yCli(argv = []) {
  const report = await runA11yQa(argv);
  const output = `${JSON.stringify(report, null, 2)}\n`;
  const reportPath = option(argv, "report", "");
  if (reportPath) await writeFile(reportPath, output);
  process.stdout.write(output);
  if (report.status !== "passed") process.exitCode = 1;
}
