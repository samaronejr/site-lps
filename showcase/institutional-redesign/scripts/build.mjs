#!/usr/bin/env node
/**
 * Builds the static institutional redesign preview.
 *
 * Output: `showcase/institutional-redesign/public/` (generated, git-ignored).
 *
 * The point of this build is fidelity: `assets/css/theme.css` and the fonts are copied
 * from the WordPress theme itself, so what runs in the preview is the stylesheet the
 * theme ships. Only the HTML is generated, and only from the components that mirror the
 * theme's PHP surface renderers.
 *
 * Usage: node showcase/institutional-redesign/scripts/build.mjs
 */

import { cp, mkdir, readdir, rm, writeFile } from "node:fs/promises";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { breadcrumbs, document } from "../src/components.mjs";
import { routes } from "../src/pages.mjs";

const here = dirname(fileURLToPath(import.meta.url));
const previewRoot = resolve(here, "..");
const repoRoot = resolve(previewRoot, "..", "..");
const themeRoot = join(repoRoot, "wp-content", "themes", "lps-theme");
const outRoot = join(previewRoot, "public");

const written = [];

async function copyAssets() {
  await mkdir(join(outRoot, "assets", "css"), { recursive: true });
  await mkdir(join(outRoot, "assets", "fonts"), { recursive: true });
  await mkdir(join(outRoot, "assets", "img"), { recursive: true });

  // The shipped stylesheet, byte-identical.
  await cp(
    join(themeRoot, "assets", "css", "theme.css"),
    join(outRoot, "assets", "css", "theme.css"),
  );

  // Self-hosted fonts (SIL OFL 1.1) and their licence.
  for (const file of await readdir(join(themeRoot, "assets", "fonts"))) {
    await cp(join(themeRoot, "assets", "fonts", file), join(outRoot, "assets", "fonts", file));
  }

  // Brand artwork: the four approved variants plus the favicon set.
  await cp(join(themeRoot, "assets", "img", "mark"), join(outRoot, "assets", "img", "mark"), {
    recursive: true,
  });

  // Decorative motifs referenced by the stylesheet.
  await cp(join(themeRoot, "assets", "img", "decor"), join(outRoot, "assets", "img", "decor"), {
    recursive: true,
  });
}

async function writePage(route, locale) {
  const path = locale === "en" ? route.en : route.pt;
  const page = route.build(locale);
  // A route may sit under a section (a professor page under "Pessoas"), so the
  // trail is built from the declared ancestry rather than from one label.
  const crumbs = route.breadcrumb
    ? breadcrumbs(locale, [
        ...(route.parents ?? []).map((parent) => ({ href: parent.href, label: parent.label })),
        { label: route.breadcrumb },
      ])
    : "";
  const canonical = locale === "en" ? route.en : route.pt;
  const alternates = `\n<link rel="alternate" hreflang="pt-BR" href="${route.pt}">\n<link rel="alternate" hreflang="en" href="${route.en}">\n<link rel="alternate" hreflang="x-default" href="${route.pt}">`;

  const html = document(locale, {
    title: page.title,
    description: page.description,
    path,
    body: `${crumbs}${page.body}`,
    canonicalPath: canonical,
    alternates: { pt: route.pt, en: route.en },
  }).replace("</head>", `${alternates}</head>`);

  const file =
    path === "/"
      ? join(outRoot, "index.html")
      : join(outRoot, path.replace(/^\/|\/$/g, ""), "index.html");
  await mkdir(dirname(file), { recursive: true });
  await writeFile(file, html, "utf8");
  written.push(file.replace(`${repoRoot}/`, ""));

  // Guard: no page may ship without the shared shell.
  if (!html.includes("lps-site-header") || !html.includes("lps-site-footer")) {
    throw new Error(`Shell missing in ${file}`);
  }
  return html;
}

async function main() {
  await rm(outRoot, { recursive: true, force: true });
  await copyAssets();

  for (const route of routes) {
    for (const locale of ["pt-br", "en"]) {
      await writePage(route, locale);
    }
  }

  // A sitemap for the preview, so the route set is inspectable without clicking.
  const urls = routes.flatMap((route) => [route.pt, route.en]);
  await writeFile(
    join(outRoot, "sitemap-preview.txt"),
    `# Institutional redesign preview — route set\n${urls.sort().join("\n")}\n`,
    "utf8",
  );

  console.log(`Built ${written.length} pages from ${routes.length} routes (pt-BR + en).`);
  console.log(`Output: ${outRoot.replace(`${repoRoot}/`, "")}`);
}

await main();
