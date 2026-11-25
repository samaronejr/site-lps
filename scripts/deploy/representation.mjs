import { brotliCompressSync, constants, gzipSync } from "node:zlib";

export function originRequestHeaders(headers) {
  const omit = new Set(["host", "connection", "keep-alive", "content-length", "transfer-encoding"]);
  const forwarded = Object.fromEntries(
    Object.entries(headers).filter(([name]) => !name.startsWith(":") && !omit.has(name)),
  );
  forwarded["accept-encoding"] = "identity";
  return forwarded;
}

export function machineDocumentTarget(url) {
  const match = /^\/(?:sitemap|wp-sitemap)(?:-(pt-br|en))?\.xml$/.exec(url.pathname);
  if (!match) return `${url.pathname}${url.search}`;
  return `/index.php?lps_seo_document=sitemap-${match[1] ?? "index"}`;
}

const STATIC_ASSET_PATH = /\.(?:css|js|woff2?|png|jpe?g|webp|avif|ico|mp4|webm|mp3|ogg|oga)$/i;

export function isStaticAssetPath(pathname) {
  return STATIC_ASSET_PATH.test(pathname);
}

export function staticAssetCacheControl() {
  return "public, max-age=31536000, s-maxage=31536000, immutable";
}

export function cacheableQueryEntries(url, approvedKeys) {
  const staticAsset = isStaticAssetPath(url.pathname);
  return [...url.searchParams.entries()]
    .filter(([key]) => approvedKeys.has(key) || (staticAsset && key === "ver"))
    .sort(([a], [b]) => a.localeCompare(b));
}

export function hasUnapprovedCacheQuery(url, approvedKeys) {
  const staticAsset = isStaticAssetPath(url.pathname);
  return [...url.searchParams.keys()].some(
    (key) => !approvedKeys.has(key) && !(staticAsset && key === "ver"),
  );
}

export function negotiateEncoding(header = "") {
  const preferences = new Map(
    header
      .toLowerCase()
      .split(",")
      .map((part) => {
        const [name, ...parameters] = part.trim().split(";");
        const quality = parameters.find((p) => p.trim().startsWith("q="));
        const q = quality ? Number(quality.trim().slice(2)) : 1;
        return [name, Number.isFinite(q) && q >= 0 && q <= 1 ? q : 0];
      }),
  );
  const quality = (name) => preferences.get(name) ?? preferences.get("*") ?? 0;
  const br = quality("br");
  const gzip = quality("gzip");
  if (br > 0 && br >= gzip) return "br";
  return gzip > 0 ? "gzip" : null;
}

export function sendRepresentation(req, res, status, bytes) {
  const type = String(res.getHeader("content-type") ?? "");
  const compressible =
    /^(?:text\/|application\/(?:javascript|json|xml|[^;]+\+xml)|image\/svg\+xml)/i.test(type);
  const eligible =
    compressible &&
    !res.hasHeader("content-range") &&
    !res.hasHeader("content-encoding") &&
    !/no-transform/i.test(String(res.getHeader("cache-control") ?? ""));
  let body = bytes;
  if (eligible && status !== 204 && status !== 304) {
    const vary = String(res.getHeader("vary") ?? "")
      .split(",")
      .map((v) => v.trim())
      .filter(Boolean);
    if (!vary.some((v) => /^(?:accept-encoding|\*)$/i.test(v))) vary.push("Accept-Encoding");
    res.setHeader("vary", vary.join(", "));
    const encoding = negotiateEncoding(req.headers["accept-encoding"]);
    if (encoding && (body.length > 0 || req.method === "HEAD")) {
      body =
        encoding === "br"
          ? brotliCompressSync(body, { params: { [constants.BROTLI_PARAM_QUALITY]: 4 } })
          : gzipSync(body);
      res.setHeader("content-encoding", encoding);
      res.removeHeader("etag");
    }
    if (req.method !== "HEAD") res.setHeader("content-length", body.length);
    else res.removeHeader("content-length");
  }
  res.writeHead(status);
  res.end(req.method === "HEAD" || status === 204 || status === 304 ? undefined : body);
}
