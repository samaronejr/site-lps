import { readFile } from "node:fs/promises";

/**
 * Internal route and link integrity checks for the public surfaces.
 *
 * The lane answers two questions against a running site:
 *
 * 1. Route status — every static route declared in the IA route fixture
 *    (`tests/fixtures/ia/routes.json`, parameterized `:segment` routes
 *    excluded) plus the machine routes (robots.txt and the locale sitemaps)
 *    answers with a successful status.
 * 2. Link status — every same-origin `href` discovered on those documents
 *    resolves to a successful status after following redirects, so a broken
 *    internal link can never ship silently.
 *
 * External hosts, `mailto:`/`tel:`/fragment-only links and authenticated
 * surfaces (`wp-admin`, `wp-login.php`) are out of scope by contract: the
 * lane covers the public internal graph only.
 */

const MACHINE_PATHS = ["/robots.txt", "/sitemap.xml", "/sitemap-pt-br.xml", "/sitemap-en.xml"];

const SKIP_PREFIXES = ["/wp-admin", "/wp-login.php", "/wp-content/uploads/.quarantine"];

/**
 * Reads the static public routes declared by the IA route fixture.
 *
 * Parameterized routes (`:segment`) are template families, not addresses, so
 * they are covered indirectly through the links discovered on their parents.
 *
 * @param {string} routeFixture Path to the IA route fixture.
 * @returns {Promise<string[]>} Static locale routes.
 */
export async function staticRoutes(routeFixture) {
  const fixture = JSON.parse(await readFile(routeFixture, "utf8"));
  const routes = [];
  for (const page of fixture.pages ?? []) {
    for (const route of Object.values(page.routes ?? {})) {
      if (typeof route === "string" && !route.includes(":")) {
        routes.push(route);
      }
    }
  }
  return [...new Set(routes)].sort();
}

/**
 * Extracts same-site link targets from one HTML document.
 *
 * A link is internal when it is root-relative or its origin belongs to the
 * site's origin set — the request origin plus the canonical origin the
 * documents declare (the development runtime serves `127.0.0.1` while
 * `WP_SITEURL` names `localhost`, and both are the same site).
 *
 * @param {string}        body    Document body.
 * @param {Set<string>}   origins Origins treated as the same site.
 * @returns {{internal: Array<{origin: string, path: string}>, external: number}}
 * Internal targets and the count of skipped external/scheme links.
 */
export function internalLinks(body, origins) {
  const internal = new Map();
  let external = 0;
  for (const match of body.matchAll(/href\s*=\s*"([^"]*)"/gi)) {
    const raw = match[1].trim();
    if (raw === "" || raw.startsWith("#")) {
      continue;
    }
    if (/^(mailto|tel|javascript|data):/i.test(raw)) {
      external += 1;
      continue;
    }
    let origin = "";
    let path = null;
    if (raw.startsWith("//")) {
      external += 1;
      continue;
    }
    if (/^https?:\/\//i.test(raw)) {
      try {
        const url = new URL(raw);
        if (!origins.has(url.origin)) {
          external += 1;
          continue;
        }
        origin = url.origin;
        path = url.pathname + url.search;
      } catch {
        continue;
      }
    } else if (raw.startsWith("/")) {
      path = raw;
    } else {
      // Relative links resolve against the document path; the public surface
      // only emits root-relative or absolute links, so anything else is
      // reported as external rather than guessed.
      external += 1;
      continue;
    }
    if (SKIP_PREFIXES.some((prefix) => path.startsWith(prefix))) {
      continue;
    }
    const clean = path.split("#")[0];
    if (!internal.has(clean)) {
      internal.set(clean, { origin, path: clean });
    }
  }
  return { internal: [...internal.values()], external };
}

/**
 * Returns the canonical origin one HTML document declares for itself.
 *
 * @param {string} body Document body.
 * @returns {string} Canonical origin, or an empty string.
 */
export function canonicalOrigin(body) {
  const canonical = body.match(/<link[^>]+rel="canonical"[^>]+href="(https?:\/\/[^"]+)"/i)
    ?? body.match(/<meta[^>]+property="og:url"[^>]+content="(https?:\/\/[^"]+)"/i);
  if (!canonical) {
    return "";
  }
  try {
    return new URL(canonical[1]).origin;
  } catch {
    return "";
  }
}

/**
 * Warms the session cookie the development runtime requires.
 *
 * The Playground auto-login handshake answers anonymous requests with a
 * redirect unless the marker cookie is already set; the first response's
 * `Set-Cookie` values are replayed on every later request.
 *
 * @param {string} origin    Site origin.
 * @param {number} timeoutMs Request timeout.
 * @returns {Promise<string>} Cookie header value.
 */
async function warmUp(origin, timeoutMs) {
  // The development runtime answers anonymous requests with the auto-login
  // handshake (a 2FA challenge page) unless the marker cookie is already
  // set; sending it up front keeps every fetched document a real page.
  const response = await fetch(`${origin}/pt-br/`, {
    redirect: "manual",
    headers: { cookie: "playground_auto_login_already_happened=1" },
    signal: AbortSignal.timeout(timeoutMs),
  });
  const raw = response.headers.getSetCookie?.() ?? [];
  await response.arrayBuffer();
  const session = raw.map((value) => value.split(";")[0]).join("; ");
  return session === ""
    ? "playground_auto_login_already_happened=1"
    : `playground_auto_login_already_happened=1; ${session}`;
}

const MAX_ATTEMPTS = 4;

/**
 * Fetches one path, retrying transient server and transport failures.
 *
 * @param {string} origin    Site origin.
 * @param {string} path      Request path with optional query.
 * @param {string} cookie    Session cookie header.
 * @param {number} timeoutMs Request timeout.
 * @param {number} attempt   Current attempt (internal).
 * @returns {Promise<{path: string, status: number, contentType: string, location: string, body: string}>}
 */
async function fetchDocument(origin, path, cookie, timeoutMs, attempt = 0) {
  let response;
  try {
    response = await fetch(`${origin}${path}`, {
      redirect: "manual",
      headers: cookie === "" ? {} : { cookie },
      signal: AbortSignal.timeout(timeoutMs),
    });
  } catch (error) {
    if (attempt + 1 >= MAX_ATTEMPTS) {
      return { path, status: 0, contentType: "", location: "", body: "", error: String(error) };
    }
    await pause(attempt);
    return fetchDocument(origin, path, cookie, timeoutMs, attempt + 1);
  }
  const body = await response.text();
  if (response.status >= 500 && attempt + 1 < MAX_ATTEMPTS) {
    await pause(attempt);
    return fetchDocument(origin, path, cookie, timeoutMs, attempt + 1);
  }
  return {
    path,
    status: response.status,
    contentType: (response.headers.get("content-type") ?? "").split(";")[0].trim(),
    location: response.headers.get("location") ?? "",
    body,
  };
}

function pause(attempt) {
  return new Promise((resolve) => setTimeout(resolve, 150 * (attempt + 1)));
}

/**
 * Resolves one link target to its final status after redirects.
 *
 * Redirects are followed manually so a chain that leaves the origin or loops
 * is reported instead of silently passing or hanging.
 *
 * @param {string} origin    Site origin.
 * @param {string} path      Link target.
 * @param {string} cookie    Session cookie header.
 * @param {number} timeoutMs Request timeout.
 * @returns {Promise<{status: number, hops: number}>}
 */
async function resolveLink(target, origins, cookie, timeoutMs) {
  let origin = target.origin === "" ? [...origins][0] : target.origin;
  let current = target.path;
  for (let hop = 0; hop <= 3; hop += 1) {
    const document = await fetchDocument(origin, current, cookie, timeoutMs);
    if (document.status === 0) {
      return { status: 0, hops: hop };
    }
    if (document.status < 300 || document.status >= 400 || document.location === "") {
      return { status: document.status, hops: hop };
    }
    const location = document.location;
    if (location.startsWith("/")) {
      current = location;
      continue;
    }
    try {
      const url = new URL(location);
      if (!origins.has(url.origin)) {
        return { status: document.status, hops: hop, external: true };
      }
      origin = url.origin;
      current = url.pathname + url.search;
    } catch {
      return { status: document.status, hops: hop };
    }
  }
  return { status: 0, hops: 4, loop: true };
}

/**
 * Runs the links lane against a running site.
 *
 * @param {object}   options
 * @param {string}   options.baseUrl       Site base URL (required).
 * @param {string}   options.routeFixture  IA route fixture path.
 * @param {number}   options.timeoutMs     Per-request timeout.
 * @param {number}   options.concurrency   Parallel fetch pool size.
 * @param {number}   options.maxLinks      Maximum unique links checked.
 * @returns {Promise<object>} Lane report.
 */
export async function runLinksQa(options) {
  const origin = (options.baseUrl ?? "").replace(/\/+$/, "");
  const timeoutMs = options.timeoutMs ?? 120000;
  const concurrency = options.concurrency ?? 4;
  const maxLinks = options.maxLinks ?? 600;
  const routeFixture = options.routeFixture ?? "tests/fixtures/ia/routes.json";

  const report = {
    lane: "links",
    status: "failed",
    baseUrl: origin,
    checkedAt: new Date().toISOString(),
    routes: { checked: 0, failed: 0 },
    links: { checked: 0, broken: 0, skippedExternal: 0 },
    failures: [],
  };

  let cookie;
  try {
    cookie = await warmUp(origin, timeoutMs);
  } catch (error) {
    report.status = "env-unreachable";
    report.hint = "start the playground env first (LPS_BASE_URL)";
    report.error = String(error);
    return report;
  }

  const seedPaths = [...new Set([...MACHINE_PATHS, ...(await staticRoutes(routeFixture))])];
  const documents = new Map();
  for (let index = 0; index < seedPaths.length; index += concurrency) {
    const batch = seedPaths.slice(index, index + concurrency);
    const fetched = await Promise.all(
      batch.map((path) => fetchDocument(origin, path, cookie, timeoutMs)),
    );
    for (const document of fetched) {
      documents.set(document.path, document);
    }
  }

  // The site's canonical origin is learned from the first HTML document so
  // absolute links emitted under WP_SITEURL count as internal even when the
  // lane was pointed at a different loopback name for the same site.
  const origins = new Set([origin]);
  for (const document of documents.values()) {
    if (document.contentType.includes("html")) {
      const canonical = canonicalOrigin(document.body);
      if (canonical !== "") {
        origins.add(canonical);
        break;
      }
    }
  }

  const linkSources = new Map();
  for (const document of documents.values()) {
    report.routes.checked += 1;
    if (document.status === 0 || document.status >= 400) {
      report.routes.failed += 1;
      report.failures.push({
        kind: "route",
        path: document.path,
        status: document.status === 0 ? "unreachable" : document.status,
      });
      continue;
    }
    if (!document.contentType.includes("html")) {
      continue;
    }
    const { internal, external } = internalLinks(document.body, origins);
    report.links.skippedExternal += external;
    for (const target of internal) {
      if (!linkSources.has(target.path)) {
        linkSources.set(target.path, { target, sources: [] });
      }
      linkSources.get(target.path).sources.push(document.path);
    }
  }

  const entries = [...linkSources.values()].slice(0, maxLinks);
  for (let index = 0; index < entries.length; index += concurrency) {
    const batch = entries.slice(index, index + concurrency);
    const resolved = await Promise.all(
      batch.map((entry) => resolveLink(entry.target, origins, cookie, timeoutMs)),
    );
    resolved.forEach((result, offset) => {
      const entry = batch[offset];
      report.links.checked += 1;
      if (result.external) {
        report.links.skippedExternal += 1;
        return;
      }
      if (result.status === 0 || result.status >= 400) {
        report.links.broken += 1;
        report.failures.push({
          kind: "link",
          path: entry.target.path,
          status: result.status === 0 ? "unreachable" : result.status,
          sources: entry.sources.slice(0, 5),
        });
      }
    });
  }

  report.status = report.failures.length === 0 ? "passed" : "failed";
  return report;
}
