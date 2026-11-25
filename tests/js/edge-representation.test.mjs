import { brotliDecompressSync, gunzipSync } from "node:zlib";
import { describe, expect, it } from "vitest";
import {
  cacheableQueryEntries,
  hasUnapprovedCacheQuery,
  isStaticAssetPath,
  machineDocumentTarget,
  negotiateEncoding,
  originRequestHeaders,
  sendRepresentation,
  staticAssetCacheControl,
} from "../../scripts/deploy/representation.mjs";

function response(headers = {}) {
  const values = new Map(Object.entries(headers));
  return {
    values,
    getHeader: (key) => values.get(key),
    hasHeader: (key) => values.has(key),
    setHeader: (key, value) => values.set(key, value),
    removeHeader: (key) => values.delete(key),
    writeHead(status) {
      this.status = status;
    },
    end(body) {
      this.body = body;
    },
  };
}

describe("edge representations", () => {
  it("forwards HTTP/2 headers without pseudo headers, symbols or HTTP/1 framing", () => {
    const headers = originRequestHeaders({
      ":authority": "lps.test",
      ":path": "/pt-br/",
      [Symbol.for("nodejs.http2.sensitiveHeaders")]: [],
      host: "lps.test",
      connection: "keep-alive",
      "keep-alive": "timeout=5",
      "content-length": "12",
      "transfer-encoding": "chunked",
      "accept-encoding": "br, gzip",
      cookie: "session=private",
    });
    expect(Object.fromEntries(new Headers(headers))).toEqual({
      "accept-encoding": "identity",
      cookie: "session=private",
    });
    expect(originRequestHeaders({})).toEqual({ "accept-encoding": "identity" });
  });

  it("bypasses the Playground early sitemap redirect, including the core alias", () => {
    for (const path of ["/sitemap.xml", "/wp-sitemap.xml"]) {
      expect(machineDocumentTarget(new URL(path, "https://lps.test"))).toBe(
        "/index.php?lps_seo_document=sitemap-index",
      );
    }
    expect(machineDocumentTarget(new URL("/sitemap-en.xml", "https://lps.test"))).toBe(
      "/index.php?lps_seo_document=sitemap-en",
    );
    expect(machineDocumentTarget(new URL("/sitemap-pt-br.xml", "https://lps.test"))).toBe(
      "/index.php?lps_seo_document=sitemap-pt-br",
    );
    expect(machineDocumentTarget(new URL("/other.xml?q=1", "https://lps.test"))).toBe(
      "/other.xml?q=1",
    );
  });

  it("makes immutable static assets shared-cacheable without collapsing unapproved queries", () => {
    const approved = new Set(["q"]);
    expect(isStaticAssetPath("/wp-content/themes/lps-theme/assets/fonts/inter-regular.woff2")).toBe(
      true,
    );
    expect(isStaticAssetPath("/wp-content/themes/lps-theme/assets/js/theme.js")).toBe(true);
    expect(isStaticAssetPath("/pt-br/")).toBe(false);
    expect(staticAssetCacheControl()).toBe(
      "public, max-age=31536000, s-maxage=31536000, immutable",
    );
    expect(
      cacheableQueryEntries(new URL("/theme.js?ver=2&utm=x", "https://lps.test"), approved),
    ).toEqual([["ver", "2"]]);
    expect(hasUnapprovedCacheQuery(new URL("/theme.js?ver=2", "https://lps.test"), approved)).toBe(
      false,
    );
    expect(hasUnapprovedCacheQuery(new URL("/theme.js?utm=x", "https://lps.test"), approved)).toBe(
      true,
    );
    expect(hasUnapprovedCacheQuery(new URL("/pt-br/?ver=2", "https://lps.test"), approved)).toBe(
      true,
    );
  });

  it("honors quality values, exclusions, wildcards, and absent encoding", () => {
    expect(negotiateEncoding()).toBeNull();
    expect(negotiateEncoding("br, gzip")).toBe("br");
    expect(negotiateEncoding("br;q=0, gzip;q=0.8")).toBe("gzip");
    expect(negotiateEncoding("br;q=0, gzip;q=0, *;q=1")).toBeNull();
    expect(negotiateEncoding("br;q=bad, gzip;q=0")).toBeNull();
    expect(negotiateEncoding("*;q=0.5")).toBe("br");
  });

  it("encodes the same identity cache body independently on every request", () => {
    const bytes = Buffer.from("<html>laboratório</html>");
    for (const [encoding, decode] of [
      ["br", brotliDecompressSync],
      ["gzip", gunzipSync],
    ]) {
      const res = response({
        "content-type": "text/html",
        vary: "Cookie",
        "content-length": bytes.length,
      });
      sendRepresentation(
        { method: "GET", headers: { "accept-encoding": encoding } },
        res,
        200,
        bytes,
      );
      expect(res.values.get("vary")).toBe("Cookie, Accept-Encoding");
      expect(res.values.get("content-encoding")).toBe(encoding);
      expect(res.values.get("content-length")).toBe(res.body.length);
      expect(decode(res.body)).toEqual(bytes);
    }
    const plain = response({ "content-type": "text/html" });
    sendRepresentation({ method: "GET", headers: {} }, plain, 200, bytes);
    expect(plain.body).toEqual(bytes);
    expect(plain.values.has("content-encoding")).toBe(false);
  });

  it("handles empty, HEAD, errors and uncompressible responses", () => {
    for (const [method, type, status, bytes, headers] of [
      ["GET", "application/xml", 200, Buffer.alloc(0), {}],
      ["HEAD", "text/css", 200, Buffer.from("body{}"), {}],
      ["GET", "image/jpeg", 200, Buffer.from("jpeg"), {}],
      ["GET", "text/plain", 206, Buffer.from("part"), { "content-range": "bytes 0-3/10" }],
    ]) {
      const res = response({ "content-type": type, ...headers });
      sendRepresentation({ method, headers: { "accept-encoding": "br" } }, res, status, bytes);
      expect(res.body).toEqual(method === "HEAD" ? undefined : bytes);
    }
    const error = response({ "content-type": "application/json" });
    sendRepresentation(
      { method: "GET", headers: { "accept-encoding": "gzip" } },
      error,
      500,
      Buffer.from('{"error":true}'),
    );
    expect(error.status).toBe(500);
    expect(gunzipSync(error.body).toString()).toBe('{"error":true}');
  });
});
