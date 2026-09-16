// Fixture harness for the DESIGN.md 2/7/9 institutional-mark guardrails.
//
// Every fixture is applied as an overlay on top of a throwaway copy of the REAL
// theme and the REAL showcase, and the checkers are then executed from inside
// that copy. Two properties follow from that design:
//
//   1. A finding produced here is demonstrably caused by the fixture, because
//      the same tree without the overlay is the tree `npm run qa:design-system`
//      already passes.
//   2. The harness never edits the working tree, so running it before the rules
//      exist (RED) and after they exist (GREEN) exercises exactly the checker
//      source that is on disk at that moment. It cannot fake either result.
//
// Usage:
//   node scripts/qa/design-guardrails/run-fixtures.mjs --expect fail
//   node scripts/qa/design-guardrails/run-fixtures.mjs --expect pass   (RED baseline)
//   node scripts/qa/design-guardrails/run-fixtures.mjs --malformed
import { execFile } from "node:child_process";
import { cp, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { promisify } from "node:util";

const execFileAsync = promisify(execFile);
const repoRoot = resolve(new URL("../../../", import.meta.url).pathname);

const TIMEOUT_MS = 60_000;
const MAX_BUFFER = 16 * 1024 * 1024;

const SANDBOX_TREES = [
  "scripts/lib",
  "wp-content/themes/lps-theme",
  "showcase/scientific-editorial",
];

const THEME_CSS = "wp-content/themes/lps-theme/assets/css/theme.css";
const THEME_JSON = "wp-content/themes/lps-theme/theme.json";
const THEME_TEMPLATE = "wp-content/themes/lps-theme/templates/index.html";
const THEME_CHECKER = "wp-content/themes/lps-theme/scripts/check-theme.mjs";
const SHOWCASE_CSS = "showcase/scientific-editorial/styles.css";
const SHOWCASE_HTML = "showcase/scientific-editorial/index.html";
const SHOWCASE_CHECKER = "showcase/scientific-editorial/scripts/check-design-system.mjs";

function option(name, fallback) {
  const index = process.argv.indexOf(name);
  return index === -1 ? fallback : process.argv[index + 1];
}

function flag(name) {
  return process.argv.includes(name);
}

async function createSandbox() {
  const sandbox = await mkdtemp(join(tmpdir(), "lps-guardrails-"));
  for (const tree of SANDBOX_TREES) {
    await cp(join(repoRoot, tree), join(sandbox, tree), { recursive: true, dereference: true });
  }
  return sandbox;
}

/** Split a fixture stylesheet into `:root` token declarations and everything else. */
function splitFixtureCss(source) {
  const rootDeclarations = [];
  const remainder = source.replace(/:root\s*\{([^}]*)\}/g, (_match, body) => {
    rootDeclarations.push(body.trim());
    return "";
  });
  return { rootDeclarations, remainder };
}

/**
 * Merge a fixture stylesheet into a target stylesheet the way an implementer
 * would write it: token declarations join the existing `:root` block (so the
 * pre-existing UNTOKENIZED_COLOR rule correctly treats them as tokens), and the
 * rule bodies are appended.
 */
function applyCssOverlay(targetCss, fixtureCss) {
  const { rootDeclarations, remainder } = splitFixtureCss(fixtureCss);
  let css = targetCss;
  if (rootDeclarations.length > 0) {
    const start = css.search(/^:root\s*\{/m);
    if (start === -1) throw new Error("target stylesheet has no top-level :root block");
    const close = css.indexOf("}", css.indexOf("{", start));
    if (close === -1) throw new Error("target :root block is not terminated");
    css = `${css.slice(0, close)}  ${rootDeclarations.join("\n  ")}\n${css.slice(close)}`;
  }
  const tail = remainder.trim();
  return tail.length > 0 ? `${css}\n${tail}\n` : css;
}

function applyHtmlOverlay(targetHtml, fixtureHtml, anchor) {
  const snippet = fixtureHtml.trim();
  return targetHtml.includes(anchor)
    ? targetHtml.replace(anchor, `${snippet}\n${anchor}`)
    : `${targetHtml}\n${snippet}\n`;
}

function applyThemeJsonOverlay(theme, overlay) {
  if (overlay.addPaletteEntry) theme.settings.color.palette.push(overlay.addPaletteEntry);
  if (overlay.setStyleElementLinkTextColor) {
    theme.styles.elements.link.color.text = overlay.setStyleElementLinkTextColor;
  }
  if (overlay.setStyleColorBackground) {
    theme.styles.color.background = overlay.setStyleColorBackground;
  }
  return theme;
}

async function execChecker(args, cwd) {
  try {
    const { stdout, stderr } = await execFileAsync(process.execPath, args, {
      cwd,
      encoding: "utf8",
      timeout: TIMEOUT_MS,
      maxBuffer: MAX_BUFFER,
    });
    return { exitCode: 0, stdout, stderr };
  } catch (error) {
    return {
      exitCode: error.killed ? "TIMEOUT" : (error.code ?? 1),
      stdout: error.stdout ?? "",
      stderr: error.stderr ?? String(error.message ?? error),
    };
  }
}

function parseReport(stdout) {
  try {
    return { report: JSON.parse(stdout), parseError: null };
  } catch (error) {
    return { report: null, parseError: String(error.message ?? error) };
  }
}

function summarise(execResult) {
  const { report, parseError } = parseReport(execResult.stdout);
  const findings = report?.findings ?? [];
  return {
    exitCode: execResult.exitCode,
    status: report?.status ?? "unreadable",
    findingCount: report?.findingCount ?? findings.length,
    codes: [...new Set(findings.map(({ code }) => code))].sort(),
    guardrailFindings: findings.filter(({ code }) => GUARDRAIL_CODES.has(code)),
    parseError,
    stderr: execResult.stderr.trim().split("\n").slice(0, 4).join("\n"),
  };
}

const GUARDRAIL_CODES = new Set([
  "GRADIENT_ON_SURFACE",
  "WAVEFORM_ORNAMENT",
  "BRAND_COLOR_IN_UI",
  "BRAND_COLOR_IN_PALETTE",
  "MALFORMED_STYLESHEET",
]);

async function runFixture(fixture, fixtureDir) {
  const sandbox = await createSandbox();
  try {
    const cssParts = [];
    for (const name of fixture.css ?? []) {
      cssParts.push(await readFile(join(fixtureDir, name), "utf8"));
    }
    const fixtureCss = cssParts.join("\n");

    for (const target of [THEME_CSS, SHOWCASE_CSS]) {
      const path = join(sandbox, target);
      await writeFile(path, applyCssOverlay(await readFile(path, "utf8"), fixtureCss));
    }

    if (fixture.html) {
      const snippet = await readFile(join(fixtureDir, fixture.html), "utf8");
      const templatePath = join(sandbox, THEME_TEMPLATE);
      await writeFile(
        templatePath,
        applyHtmlOverlay(await readFile(templatePath, "utf8"), snippet, "</main>"),
      );
      const showcasePath = join(sandbox, SHOWCASE_HTML);
      await writeFile(
        showcasePath,
        applyHtmlOverlay(await readFile(showcasePath, "utf8"), snippet, "</body>"),
      );
    }

    if (fixture.themeJsonOverlay) {
      const overlay = JSON.parse(
        await readFile(join(fixtureDir, fixture.themeJsonOverlay), "utf8"),
      );
      const path = join(sandbox, THEME_JSON);
      const theme = applyThemeJsonOverlay(JSON.parse(await readFile(path, "utf8")), overlay);
      await writeFile(path, `${JSON.stringify(theme, null, 2)}\n`);
    }

    const lanes = {};
    for (const lane of fixture.lanes) {
      if (lane === "theme") {
        lanes.theme = summarise(await execChecker([join(sandbox, THEME_CHECKER)], sandbox));
      } else if (lane === "showcase") {
        lanes.showcase = summarise(
          await execChecker(
            [
              join(sandbox, SHOWCASE_CHECKER),
              join(sandbox, SHOWCASE_CSS),
              join(sandbox, SHOWCASE_HTML),
            ],
            sandbox,
          ),
        );
      }
    }
    return { id: fixture.id, title: fixture.title, expect: fixture.expect ?? [], lanes };
  } finally {
    await rm(sandbox, { force: true, recursive: true });
  }
}

function judge(result, mode) {
  const problems = [];
  const expectPass = result.expect.length === 0;
  for (const [lane, summary] of Object.entries(result.lanes)) {
    if (summary.parseError)
      problems.push(`${lane}: checker output is not JSON (${summary.parseError})`);
    if (expectPass || mode === "pass") {
      if (summary.exitCode !== 0)
        problems.push(`${lane}: expected exit 0, got ${summary.exitCode}`);
      continue;
    }
    if (summary.exitCode === 0) problems.push(`${lane}: expected a non-zero exit, got 0`);
    for (const code of result.expect) {
      const laneCodes = new Set(summary.codes);
      const expectedHere = code === "BRAND_COLOR_IN_PALETTE" ? lane === "theme" : true;
      if (expectedHere && !laneCodes.has(code))
        problems.push(`${lane}: missing expected rule ${code}`);
    }
  }
  return problems;
}

async function runMalformed() {
  const cases = [];

  const jsonSandbox = await createSandbox();
  try {
    await writeFile(join(jsonSandbox, THEME_JSON), '{"settings": {"color": {\n');
    cases.push({
      id: "M1-malformed-theme-json",
      lane: "theme",
      ...summarise(await execChecker([join(jsonSandbox, THEME_CHECKER)], jsonSandbox)),
    });
  } finally {
    await rm(jsonSandbox, { force: true, recursive: true });
  }

  // An unterminated :root block is the classic way a positional "is this inside
  // :root?" test can be tricked into swallowing a later violation.
  const brokenCss = [
    ":root {",
    "  --color-brand-accent: #00aff1;",
    ".smuggled-link { color: var(--color-brand-accent); }",
    "}",
  ].join("\n");

  // A truncated stylesheet: unbalanced braces, no violation to find. It must be
  // rejected as unreadable rather than reported as clean.
  const truncatedCss = ".truncated-block {\n  color: var(--color-ink);\n";

  for (const [id, target, checker, args, appended] of [
    ["M2-malformed-theme-css", THEME_CSS, THEME_CHECKER, null, brokenCss],
    [
      "M3-malformed-showcase-css",
      SHOWCASE_CSS,
      SHOWCASE_CHECKER,
      [SHOWCASE_CSS, SHOWCASE_HTML],
      brokenCss,
    ],
    ["M4-truncated-theme-css", THEME_CSS, THEME_CHECKER, null, truncatedCss],
  ]) {
    const sandbox = await createSandbox();
    try {
      const path = join(sandbox, target);
      await writeFile(path, `${await readFile(path, "utf8")}\n${appended}\n`);
      const argv = [join(sandbox, checker), ...(args ?? []).map((item) => join(sandbox, item))];
      cases.push({ id, lane: checker, ...summarise(await execChecker(argv, sandbox)) });
    } finally {
      await rm(sandbox, { force: true, recursive: true });
    }
  }

  process.stdout.write(`${JSON.stringify({ mode: "malformed", cases }, null, 2)}\n`);
  const silentPass = cases.filter((item) => item.exitCode === 0);
  if (silentPass.length > 0) {
    process.stderr.write(
      `malformed input silently passed: ${silentPass.map(({ id }) => id).join(", ")}\n`,
    );
    process.exitCode = 1;
  }
}

const fixtureDir = resolve(repoRoot, option("--fixtures", "tests/fixtures/design-guardrails"));

if (flag("--malformed")) {
  await runMalformed();
} else {
  const mode = option("--expect", "fail");
  if (!["fail", "pass"].includes(mode)) {
    process.stderr.write("--expect takes 'fail' (rules must fire) or 'pass' (RED baseline)\n");
    process.exitCode = 2;
  } else {
    const manifest = JSON.parse(await readFile(join(fixtureDir, "manifest.json"), "utf8"));
    const only = option("--fixture", null);
    const selected = only ? manifest.fixtures.filter(({ id }) => id === only) : manifest.fixtures;
    const results = [];
    for (const fixture of selected) {
      const result = await runFixture(fixture, fixtureDir);
      result.problems = judge(result, mode);
      results.push(result);
    }
    const failed = results.filter(({ problems }) => problems.length > 0);
    process.stdout.write(
      `${JSON.stringify(
        {
          mode,
          fixtureDir,
          checkedFixtures: results.length,
          unmetExpectations: failed.length,
          results,
        },
        null,
        2,
      )}\n`,
    );
    if (failed.length > 0) process.exitCode = 1;
  }
}
