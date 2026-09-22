/**
 * LPS staging edge — the institution-managed Nginx equivalent.
 *
 * Terminates TLS, redirects HTTP to HTTPS, enforces the documented static
 * denials and security headers, rate-limits the credential surface, honours
 * the application's s-maxage/stale-while-revalidate cache contract with a
 * surrogate-key-aware full-page cache, consumes the purge queue the
 * application writes, exposes the health surface, and emits privacy-shaped
 * structured access logs (path only, never the query string).
 *
 * Configuration arrives only through environment variables; secrets stay in
 * ops/secrets.env, loaded by the launcher.
 *
 *   LPS_EDGE_ORIGIN      origin base URL, e.g. http://127.0.0.1:8927
 *   LPS_EDGE_HTTPS_PORT  TLS listen port (default 8443)
 *   LPS_EDGE_HTTP_PORT   plain-HTTP redirect port (default 8080)
 *   LPS_EDGE_TLS_CERT    PEM certificate path
 *   LPS_EDGE_TLS_KEY     PEM private key path
 *   LPS_EDGE_ROOT        staging root (ops/, releases/, current)
 *   LPS_PURGE_TOKEN      bearer token for POST /lps-ops/purge
 */

import { createHash } from "node:crypto";
import {
  appendFileSync,
  existsSync,
  mkdirSync,
  readdirSync,
  readFileSync,
  realpathSync,
  renameSync,
  rmSync,
  statSync,
  watch,
  writeFileSync,
} from "node:fs";
import { createServer as createHttpServer } from "node:http";
import { createServer as createHttpsServer } from "node:https";
import path from "node:path";

const ORIGIN = process.env.LPS_EDGE_ORIGIN ?? "http://127.0.0.1:8927";
const HTTPS_PORT = Number(process.env.LPS_EDGE_HTTPS_PORT ?? 8443);
const HTTP_PORT = Number(process.env.LPS_EDGE_HTTP_PORT ?? 8080);
const ROOT = process.env.LPS_EDGE_ROOT ?? ".omo/staging";
const OPS = `${ROOT}/ops`;
const LOGS = `${OPS}/logs`;
const RUN = `${OPS}/run`;
const CACHE_DIR = `${OPS}/cache/pages`;
const PURGE_QUEUE = `${OPS}/cache/purge-queue.jsonl`;
const PURGE_RECEIPTS = `${OPS}/cache/purge-receipts.jsonl`;
const FAULT_FILE = `${RUN}/fault`;
const MAINTENANCE_FILE = `${RUN}/maintenance`;
const PURGE_TOKEN = process.env.LPS_PURGE_TOKEN ?? "";

for (const dir of [LOGS, RUN, CACHE_DIR]) mkdirSync(dir, { recursive: true });

const APPROVED_QUERY_KEYS = new Set([
  "area",
  "category",
  "domain",
  "page",
  "q",
  "record",
  "status",
  "type",
  "year",
]);
const PRIVATE_PATH_PREFIXES = ["/wp-admin/", "/wp-login.php", "/wp-cron.php"];
const PRIVATE_PATH_EXACT = new Set(["/wp-json/wp/v2/users"]);
const PRIVATE_COOKIE_PREFIXES = [
  "wordpress_logged_in_",
  "wordpress_sec_",
  "comment_author_",
  "wp-postpass_",
];
const STATIC_ASSET = /\.(?:css|js|woff2?|png|jpe?g|webp|avif|ico|mp4|webm|mp3|ogg|oga)$/i;
const UPLOADS_EXEC =
  /^\/wp-content\/uploads\/.*\.(?:php[0-9]*|phtml|phar|cgi|pl|py|sh|html?|svg|js)(?:\.|$)/i;
const UPLOADS_DOC = /^\/wp-content\/uploads\/.*\.(?:pdf|vtt)$/i;

const STATIC_HEADERS = {
  "x-content-type-options": "nosniff",
  "x-frame-options": "DENY",
  "referrer-policy": "no-referrer",
  "permissions-policy":
    "camera=(), microphone=(), geolocation=(), payment=(), usb=(), browsing-topics=()",
  "x-permitted-cross-domain-policies": "none",
  "content-security-policy":
    "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
};

function logLine(file, record) {
  appendFileSync(file, `${JSON.stringify(record)}\n`);
}

function accessLog(req, res, cacheStatus, ms) {
  logLine(`${LOGS}/access.jsonl`, {
    ts: new Date().toISOString(),
    ip: req.socket.remoteAddress,
    method: req.method,
    path: new URL(req.url, "https://edge").pathname,
    status: res.statusCode,
    bytes: res.getHeader("content-length") ?? 0,
    cache: cacheStatus,
    ms,
  });
}

function sendStatic(res, status, body, extra = {}) {
  const headers = { ...STATIC_HEADERS, "content-type": "text/plain; charset=utf-8", ...extra };
  res.writeHead(status, headers);
  res.end(body);
}

function currentRelease() {
  try {
    return realpathSync(`${ROOT}/current`);
  } catch {
    return null;
  }
}

function maintenanceActive() {
  return existsSync(MAINTENANCE_FILE);
}

function faultActive() {
  return existsSync(FAULT_FILE);
}

// --- rate limiting: limit_req zone=lps_auth rate=5r/m burst=20 nodelay ---
const buckets = new Map();
function rateLimited(ip) {
  const now = Date.now();
  const bucket = buckets.get(ip) ?? { tokens: 20, updated: now };
  bucket.tokens = Math.min(20, bucket.tokens + ((now - bucket.updated) / 60_000) * 5);
  bucket.updated = now;
  buckets.set(ip, bucket);
  if (bucket.tokens < 1) return true;
  bucket.tokens -= 1;
  return false;
}

// --- full-page cache honouring the application's contract ---
function cacheKey(req) {
  const url = new URL(req.url, "https://edge");
  const kept = [...url.searchParams.entries()]
    .filter(([key]) => APPROVED_QUERY_KEYS.has(key))
    .sort(([a], [b]) => a.localeCompare(b));
  const query = kept.map(([k, v]) => `${k}=${v}`).join("&");
  return createHash("sha256").update(`${req.method} ${url.pathname}?${query}`).digest("hex");
}

function cachePaths(key) {
  return { body: `${CACHE_DIR}/${key}.body`, meta: `${CACHE_DIR}/${key}.json` };
}

function cacheRead(key) {
  const { body, meta } = cachePaths(key);
  try {
    const parsed = JSON.parse(readFileSync(meta, "utf8"));
    const bytes = readFileSync(body);
    return { meta: parsed, body: bytes };
  } catch {
    return null;
  }
}

function cacheWrite(key, meta, body) {
  const { body: bodyPath, meta: metaPath } = cachePaths(key);
  const tmp = `${key}.${process.pid}.tmp`;
  writeFileSync(`${CACHE_DIR}/${tmp}`, body);
  writeFileSync(metaPath, JSON.stringify(meta));
  try {
    renameSync(`${CACHE_DIR}/${tmp}`, bodyPath);
  } catch {
    writeFileSync(bodyPath, body);
  }
}

function cacheDelete(key) {
  const { body, meta } = cachePaths(key);
  for (const file of [body, meta]) {
    try {
      rmSync(file, { force: true });
    } catch {
      // best effort
    }
  }
}

function purgeTargets(targets) {
  const purged = [];
  for (const target of targets) {
    let pathname = target;
    try {
      pathname = new URL(target).pathname;
    } catch {
      // already a path
    }
    for (const method of ["GET", "HEAD"]) {
      const key = createHash("sha256").update(`${method} ${pathname}?`).digest("hex");
      cacheDelete(key);
      purged.push(`${method} ${pathname}`);
    }
  }
  return purged;
}

function purgeAll() {
  let count = 0;
  for (const file of readdirSync(CACHE_DIR)) {
    if (file.endsWith(".body") || file.endsWith(".json") || file.endsWith(".tmp")) {
      rmSync(`${CACHE_DIR}/${file}`, { force: true });
      count += 1;
    }
  }
  return count;
}

function recordPurge(source, targets, purged) {
  logLine(PURGE_RECEIPTS, {
    ts: new Date().toISOString(),
    source,
    targets,
    purged,
  });
}

// Purge queue: the application appends batches; the edge consumes them the
// way a host consumes its CDN purge API. Offset persists across restarts.
let purgeOffset = 0;
const OFFSET_FILE = `${OPS}/cache/purge-offset`;
try {
  purgeOffset = Number(readFileSync(OFFSET_FILE, "utf8")) || 0;
} catch {
  purgeOffset = 0;
}

function drainPurgeQueue() {
  let stat;
  try {
    stat = statSync(PURGE_QUEUE);
  } catch {
    return;
  }
  if (stat.size < purgeOffset) purgeOffset = 0;
  if (stat.size === purgeOffset) return;
  const fd = readFileSync(PURGE_QUEUE, "utf8");
  const fresh = fd.slice(purgeOffset);
  purgeOffset = stat.size;
  writeFileSync(OFFSET_FILE, String(purgeOffset));
  for (const line of fresh.split("\n")) {
    if (!line.trim()) continue;
    try {
      const entry = JSON.parse(line);
      const purged = purgeTargets(entry.targets ?? []);
      recordPurge("queue", entry.targets ?? [], purged);
    } catch (error) {
      recordPurge("queue-error", [line.slice(0, 200)], [String(error)]);
    }
  }
}

// The queue file may not exist until the first publish; create it and watch
// the directory so a rotate/recreate cannot detach the watcher.
if (!existsSync(PURGE_QUEUE)) writeFileSync(PURGE_QUEUE, "");
watch(path.dirname(PURGE_QUEUE), { persistent: false }, (_event, filename) => {
  if (filename === path.basename(PURGE_QUEUE)) drainPurgeQueue();
});
drainPurgeQueue();

// --- request handling ---
function hasPrivateCookie(req) {
  const cookie = req.headers.cookie ?? "";
  return PRIVATE_COOKIE_PREFIXES.some((prefix) => cookie.includes(prefix));
}

function isPrivatePath(pathname) {
  return (
    PRIVATE_PATH_EXACT.has(pathname) ||
    PRIVATE_PATH_PREFIXES.some((prefix) => pathname.startsWith(prefix))
  );
}

function denied(pathname) {
  if (pathname === "/xmlrpc.php") return 403;
  if (
    /^\/(?:readme\.html|license\.txt|wp-admin\/(?:install|setup-config|upgrade)\.php)$/i.test(
      pathname,
    )
  )
    return 403;
  if (/\/\.[^/]/.test(pathname) || pathname.startsWith("/.")) return 403;
  if (/(?:\/tests\/|debug|\.log$|\.sql$|\.sqlite$)/i.test(pathname)) return 403;
  if (/\/includes\/[^/]*\.(?:php|inc|module|html)$/i.test(pathname)) return 403;
  if (UPLOADS_EXEC.test(pathname)) return 403;
  return 0;
}

async function fetchOrigin(req, body) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 30_000);
  try {
    const headers = { ...req.headers };
    delete headers.host;
    delete headers["accept-encoding"];
    // The body is re-serialized below; forwarding the client's framing
    // headers (content-length / transfer-encoding) desynchronizes the
    // origin's parser — a chunked POST would stall and 502.
    delete headers["content-length"];
    delete headers["transfer-encoding"];
    headers["x-forwarded-proto"] = "https";
    headers["x-forwarded-for"] = req.socket.remoteAddress;
    return await fetch(`${ORIGIN}${req.url}`, {
      method: req.method,
      headers,
      body,
      redirect: "manual",
      signal: controller.signal,
    });
  } finally {
    clearTimeout(timer);
  }
}

function copyHeaders(originRes, res, pathname) {
  const skip = new Set([
    "connection",
    "keep-alive",
    "transfer-encoding",
    "server",
    "x-powered-by",
    "date",
    "set-cookie",
  ]);
  for (const [name, value] of originRes.headers.entries()) {
    if (skip.has(name)) continue;
    res.setHeader(name, value);
  }
  // set-cookie is multi-valued: setHeader() per entry would collapse every
  // cookie into the last one (wp-login needs auth + logged_in + test).
  const setCookies = originRes.headers.getSetCookie();
  if (setCookies.length > 0) res.setHeader("set-cookie", setCookies);
  // Edge-generated responses and static assets carry the static header set;
  // PHP responses keep their own nonced CSP (never a second fixed one).
  if (!res.hasHeader("content-security-policy")) {
    for (const [name, value] of Object.entries(STATIC_HEADERS)) res.setHeader(name, value);
  } else {
    for (const [name, value] of Object.entries(STATIC_HEADERS)) {
      if (name !== "content-security-policy" && !res.hasHeader(name)) res.setHeader(name, value);
    }
  }
  res.setHeader("strict-transport-security", "max-age=31536000");
  if (STATIC_ASSET.test(pathname)) {
    res.setHeader("cache-control", "public, max-age=31536000, immutable");
  }
  if (UPLOADS_DOC.test(pathname)) {
    res.setHeader("content-type", "application/octet-stream");
    res.setHeader("content-disposition", "attachment");
    res.setHeader("x-content-type-options", "nosniff");
    res.setHeader("content-security-policy", "sandbox; default-src 'none'; frame-ancestors 'none'");
  }
}

function parseSMaxage(cacheControl) {
  const match = /s-maxage=(\d+)/.exec(cacheControl ?? "");
  return match ? Number(match[1]) : 0;
}

function parseSwr(cacheControl) {
  const match = /stale-while-revalidate=(\d+)/.exec(cacheControl ?? "");
  return match ? Number(match[1]) : 0;
}

async function handle(req, res) {
  const started = Date.now();
  const url = new URL(req.url, "https://edge");
  const pathname = url.pathname;

  // Operations surface (loopback-only semantics: bound to 127.0.0.1).
  if (pathname === "/lps-ops/health") {
    const originHealth = await fetch(`${ORIGIN}/wp-json/lps-ops/v1/health`, {
      signal: AbortSignal.timeout(15_000),
    })
      .then(async (r) => ({ status: r.status, body: await r.json().catch(() => null) }))
      .catch((error) => ({ status: 0, body: null, error: String(error) }));
    const maintenance = maintenanceActive();
    const fault = faultActive();
    const ok = !fault && originHealth.status === 200;
    const payload = {
      status: fault ? "fault" : maintenance ? "maintenance" : ok ? "ok" : "degraded",
      maintenance,
      fault,
      origin: originHealth,
      release: currentRelease()?.split("/").at(-2) ?? null,
      time: new Date().toISOString(),
    };
    sendStatic(res, ok || maintenance ? 200 : 503, JSON.stringify(payload), {
      "content-type": "application/json",
      "cache-control": "private, no-store",
    });
    accessLog(req, res, "BYPASS", Date.now() - started);
    return;
  }

  if (pathname === "/lps-ops/purge" && req.method === "POST") {
    const auth = req.headers.authorization ?? "";
    if (PURGE_TOKEN === "" || auth !== `Bearer ${PURGE_TOKEN}`) {
      sendStatic(res, 403, "purge denied\n");
      accessLog(req, res, "BYPASS", Date.now() - started);
      return;
    }
    let body = "";
    for await (const chunk of req) body += chunk;
    let parsed;
    try {
      parsed = JSON.parse(body);
    } catch {
      sendStatic(res, 400, "invalid purge payload\n");
      accessLog(req, res, "BYPASS", Date.now() - started);
      return;
    }
    if (parsed.all === true) {
      const count = purgeAll();
      recordPurge("api-all", ["*"], [`${count} entries`]);
      sendStatic(res, 200, JSON.stringify({ purged: "all", entries: count }), {
        "content-type": "application/json",
      });
      accessLog(req, res, "PURGE", Date.now() - started);
      return;
    }
    const targets = Array.isArray(parsed.urls) ? parsed.urls : [];
    const purged = purgeTargets(targets);
    recordPurge("api", targets, purged);
    sendStatic(res, 200, JSON.stringify({ purged }), { "content-type": "application/json" });
    accessLog(req, res, "PURGE", Date.now() - started);
    return;
  }

  // Fault injection for alert-routing tests: every proxied request fails.
  if (faultActive()) {
    sendStatic(res, 502, "origin fault injected\n");
    accessLog(req, res, "BYPASS", Date.now() - started);
    return;
  }

  // Maintenance: the edge owns the window so every surface, including
  // responses the origin would cache, returns the same 503 + Retry-After.
  if (maintenanceActive()) {
    sendStatic(res, 503, "Service unavailable — maintenance in progress.\n", {
      "retry-after": "600",
      "cache-control": "private, no-store",
    });
    accessLog(req, res, "BYPASS", Date.now() - started);
    return;
  }

  const denial = denied(pathname);
  if (denial) {
    sendStatic(res, denial, `${denial}\n`);
    accessLog(req, res, "BYPASS", Date.now() - started);
    return;
  }

  if (pathname === "/wp-login.php" && rateLimited(req.socket.remoteAddress ?? "unknown")) {
    sendStatic(res, 429, "throttled\n", { "retry-after": "60" });
    accessLog(req, res, "BYPASS", Date.now() - started);
    return;
  }

  const safeMethod = req.method === "GET" || req.method === "HEAD";
  // Unapproved query keys bypass the shared cache entirely: the application
  // marks them no-store, and the edge must not collapse them into the
  // approved-key entry for the same path.
  const hasUnapprovedQuery = [...url.searchParams.keys()].some(
    (key) => !APPROVED_QUERY_KEYS.has(key),
  );
  const cacheable =
    safeMethod && !isPrivatePath(pathname) && !hasPrivateCookie(req) && !hasUnapprovedQuery;
  const key = cacheable ? cacheKey(req) : null;

  if (cacheable) {
    const hit = cacheRead(key);
    if (hit) {
      const age = (Date.now() - hit.meta.stored) / 1000;
      if (age <= hit.meta.ttl) {
        for (const [name, value] of Object.entries(hit.meta.headers)) res.setHeader(name, value);
        res.setHeader("x-lps-edge-cache", "HIT");
        res.setHeader("age", String(Math.floor(age)));
        res.writeHead(hit.meta.status);
        res.end(req.method === "HEAD" ? undefined : hit.body);
        accessLog(req, res, "HIT", Date.now() - started);
        return;
      }
      if (age <= hit.meta.ttl + hit.meta.swr) {
        // Serve stale, revalidate in the background.
        for (const [name, value] of Object.entries(hit.meta.headers)) res.setHeader(name, value);
        res.setHeader("x-lps-edge-cache", "STALE");
        res.setHeader("age", String(Math.floor(age)));
        res.writeHead(hit.meta.status);
        res.end(req.method === "HEAD" ? undefined : hit.body);
        accessLog(req, res, "STALE", Date.now() - started);
        revalidate(req, key).catch(() => {});
        return;
      }
    }
  }

  let body = null;
  if (!safeMethod) {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    body = Buffer.concat(chunks);
  }

  let originRes;
  try {
    originRes = await fetchOrigin(req, body);
  } catch {
    sendStatic(res, 502, `origin unreachable\n`);
    accessLog(req, res, "ERROR", Date.now() - started);
    return;
  }

  const originBody = Buffer.from(await originRes.arrayBuffer());
  copyHeaders(originRes, res, pathname);

  const cacheControl = originRes.headers.get("cache-control") ?? "";
  const ttl = parseSMaxage(cacheControl);
  const swr = parseSwr(cacheControl);
  const storable =
    cacheable &&
    ttl > 0 &&
    !/private|no-store/.test(cacheControl) &&
    (originRes.status === 200 || originRes.status === 404);

  if (storable) {
    const headers = {};
    for (const [name, value] of originRes.headers.entries()) {
      if (
        ![
          "connection",
          "keep-alive",
          "transfer-encoding",
          "server",
          "x-powered-by",
          "date",
          // Never store set-cookie in the shared page cache: a HIT would
          // replay one client's cookie to another.
          "set-cookie",
        ].includes(name)
      )
        headers[name] = value;
    }
    for (const [name, value] of Object.entries(STATIC_HEADERS)) {
      if (!headers[name]) headers[name] = value;
    }
    headers["strict-transport-security"] = "max-age=31536000";
    cacheWrite(
      key,
      { status: originRes.status, headers, stored: Date.now(), ttl, swr },
      originBody,
    );
  }

  res.setHeader("x-lps-edge-cache", storable ? "MISS" : "BYPASS");
  res.writeHead(originRes.status);
  res.end(req.method === "HEAD" ? undefined : originBody);
  accessLog(req, res, storable ? "MISS" : "BYPASS", Date.now() - started);
}

async function revalidate(req, key) {
  const originRes = await fetchOrigin(req, null);
  const originBody = Buffer.from(await originRes.arrayBuffer());
  const cacheControl = originRes.headers.get("cache-control") ?? "";
  const ttl = parseSMaxage(cacheControl);
  if (ttl <= 0 || /private|no-store/.test(cacheControl)) return;
  const headers = {};
  for (const [name, value] of originRes.headers.entries()) {
    if (
      ![
        "connection",
        "keep-alive",
        "transfer-encoding",
        "server",
        "x-powered-by",
        "date",
        // Never store set-cookie in the shared page cache.
        "set-cookie",
      ].includes(name)
    )
      headers[name] = value;
  }
  cacheWrite(
    key,
    {
      status: originRes.status,
      headers,
      stored: Date.now(),
      ttl,
      swr: parseSwr(cacheControl),
    },
    originBody,
  );
}

const tls = {
  cert: readFileSync(process.env.LPS_EDGE_TLS_CERT),
  key: readFileSync(process.env.LPS_EDGE_TLS_KEY),
};

createHttpsServer(tls, (req, res) => {
  handle(req, res).catch((error) => {
    sendStatic(res, 500, "edge error\n");
    logLine(`${LOGS}/edge-errors.jsonl`, {
      ts: new Date().toISOString(),
      error: String(error?.stack ?? error),
    });
  });
}).listen(HTTPS_PORT, "127.0.0.1", () => {
  console.log(`edge https ready on 127.0.0.1:${HTTPS_PORT}`);
});

createHttpServer((req, res) => {
  const url = new URL(req.url, "http://edge");
  res.writeHead(301, {
    location: `https://127.0.0.1:${HTTPS_PORT}${url.pathname}${url.search}`,
    ...STATIC_HEADERS,
  });
  res.end();
}).listen(HTTP_PORT, "127.0.0.1", () => {
  console.log(`edge http->https redirect ready on 127.0.0.1:${HTTP_PORT}`);
});
