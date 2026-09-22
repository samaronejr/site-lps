#!/usr/bin/env node
/**
 * Static preview server for the institutional redesign.
 *
 * Serves `showcase/institutional-redesign/public` on 0.0.0.0 so the preview is
 * reachable from outside the sandbox. No framework, no dependency, no external
 * request: it maps a path to a file, falls back to `index.html` inside a directory
 * (that is how `/sobre/` resolves), and 404s with the route list.
 *
 * Usage: node showcase/institutional-redesign/server.mjs [port]
 */

import { createReadStream, existsSync, statSync } from "node:fs";
import { createServer } from "node:http";
import { dirname, extname, join, normalize, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, "public");
const port = Number(process.argv[2] ?? process.env.PORT ?? 4173);

const types = {
  ".html": "text/html; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
  ".mjs": "text/javascript; charset=utf-8",
  ".svg": "image/svg+xml",
  ".png": "image/png",
  ".jpg": "image/jpeg",
  ".webmanifest": "application/manifest+json",
  ".woff2": "font/woff2",
  ".txt": "text/plain; charset=utf-8",
  ".json": "application/json; charset=utf-8",
};

const notFound = `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">
<title>404 — pré-visualização LPS</title>
<style>body{font-family:system-ui,sans-serif;margin:12vh auto;max-width:44rem;padding:0 1.5rem;color:#0e2233}
code{background:#eef3f9;padding:.15em .4em;border-radius:4px}a{color:#0b5fbf}</style></head>
<body><h1>404 — página não encontrada</h1>
<p>Esta pré-visualização cobre as rotas do site institucional. Comece pela <a href="/">página inicial</a>
ou pela versão em inglês em <a href="/en/">/en/</a>.</p>
<p><code>Get the full route list from <code>/sitemap-preview.txt</code></code></p>
</body></html>`;

const server = createServer((request, response) => {
  const url = new URL(request.url ?? "/", `http://${request.headers.host ?? "localhost"}`);
  const pathname = decodeURIComponent(url.pathname);
  // `normalize` collapses any `..` segment before it can escape the output root.
  const candidate = normalize(join(root, pathname));

  if (!candidate.startsWith(root)) {
    response.writeHead(403).end("Forbidden");
    return;
  }

  let file = candidate;
  if (existsSync(file) && statSync(file).isDirectory()) {
    file = join(file, "index.html");
  }

  if (!existsSync(file) || !statSync(file).isFile()) {
    response.writeHead(404, { "content-type": "text/html; charset=utf-8" }).end(notFound);
    return;
  }

  const extension = extname(file).toLowerCase();
  response.writeHead(200, {
    "content-type": types[extension] ?? "application/octet-stream",
    "cache-control": extension === ".html" ? "no-store" : "public, max-age=300",
    // The shell is self-contained: scripts, styles and fonts all come from here.
    "x-content-type-options": "nosniff",
  });
  createReadStream(file).pipe(response);
});

server.listen(port, "0.0.0.0", () => {
  console.log(`Institutional redesign preview listening on 0.0.0.0:${port}`);
  console.log(`Serving ${root}`);
});
