import { Resolver } from "node:dns/promises";
import { request } from "node:https";
import tls from "node:tls";

export const CAPABILITIES = [
  "runtime",
  "staging",
  "deployment",
  "cron",
  "cache purge",
  "backup/restore",
  "logs",
  "monitoring",
  "contacts",
];

const CAPABILITY_EVIDENCE = {
  runtime:
    "No owner evidence for maintained WordPress, PHP, database versions, support, or runtime ownership",
  staging:
    "No owner evidence for a staging endpoint, access, production parity, or staging ownership",
  deployment:
    "No owner evidence for SSH/deployment access, release path, deployer, or rollback path",
  cron: "No owner evidence for the scheduler mechanism, frequency, execution access, or scheduler ownership",
  "cache purge":
    "No owner evidence for cache layers, purge procedure, purge access, or cache ownership",
  "backup/restore":
    "No owner evidence for backup scope, schedule, retention, restore owner, or restore rehearsal",
  logs: "No owner evidence for log locations, access, retention, or log-review ownership",
  monitoring: "No owner evidence for uptime/service checks, alert routing, or response ownership",
  contacts:
    "No named DNS, TLS, hosting, deployment, or incident contact and escalation route was provided",
};

const ALLOWED_STATUSES = new Set(["confirmed", "failed", "owner-unconfirmed"]);

function errorMessage(error) {
  return error instanceof Error ? error.message : String(error);
}

function distinguishedName(value) {
  if (!value || typeof value !== "object") return null;
  return Object.entries(value)
    .map(([key, part]) => `${key}=${part}`)
    .join(", ");
}

function parseSans(value) {
  if (!value) return [];
  return value
    .split(",")
    .map((part) => part.trim().replace(/^DNS:/, ""))
    .filter(Boolean);
}

function pemFromRaw(raw) {
  const body =
    raw
      .toString("base64")
      .match(/.{1,64}/g)
      ?.join("\n") ?? "";
  return `-----BEGIN CERTIFICATE-----\n${body}\n-----END CERTIFICATE-----\n`;
}

async function resolveRecord(resolver, hostname, type) {
  try {
    const records =
      type === "A"
        ? await resolver.resolve4(hostname)
        : type === "AAAA"
          ? await resolver.resolve6(hostname)
          : await resolver.resolveCname(hostname);
    return {
      status: "confirmed",
      records: [...records].sort(),
      evidence: `DNS ${type} query completed`,
    };
  } catch (error) {
    if (["ENODATA", "ENOTFOUND"].includes(error?.code)) {
      return {
        status: "confirmed",
        records: [],
        evidence: `DNS ${type} query completed with no records`,
      };
    }
    return { status: "failed", records: [], evidence: errorMessage(error) };
  }
}

export async function inspectDns(hostname) {
  const resolver = new Resolver();
  const [a, aaaa, cname] = await Promise.all(
    ["A", "AAAA", "CNAME"].map((type) => resolveRecord(resolver, hostname, type)),
  );
  const records = { A: a, AAAA: aaaa, CNAME: cname };
  const queryFailed = Object.values(records).some((entry) => entry.status === "failed");
  const addressable = a.records.length > 0 || aaaa.records.length > 0 || cname.records.length > 0;
  return {
    status: queryFailed || !addressable ? "failed" : "confirmed",
    records,
    evidence: queryFailed
      ? "One or more DNS queries failed"
      : addressable
        ? "Host has an address or CNAME record"
        : "Host has no A, AAAA, or CNAME record",
  };
}

export function inspectTls(hostname, timeoutMs = 8_000) {
  return new Promise((resolve) => {
    let settled = false;
    const finish = (value) => {
      if (settled) return;
      settled = true;
      resolve(value);
    };
    const socket = tls.connect({
      host: hostname,
      port: 443,
      servername: hostname,
      rejectUnauthorized: false,
    });
    socket.setTimeout(timeoutMs);
    socket.once("secureConnect", () => {
      const certificate = socket.getPeerCertificate(true);
      const validFrom = certificate.valid_from
        ? new Date(certificate.valid_from).toISOString()
        : null;
      const validTo = certificate.valid_to ? new Date(certificate.valid_to).toISOString() : null;
      const now = Date.now();
      const validityFailed =
        !validFrom || !validTo || now < Date.parse(validFrom) || now > Date.parse(validTo);
      const authorizationError = socket.authorizationError
        ? String(socket.authorizationError)
        : null;
      const status = validityFailed || authorizationError ? "failed" : "confirmed";
      const result = {
        status,
        subject: distinguishedName(certificate.subject),
        san: parseSans(certificate.subjectaltname),
        issuer: distinguishedName(certificate.issuer),
        validFrom,
        validTo,
        fingerprint256: certificate.fingerprint256 ?? null,
        authorizationError,
        evidence:
          status === "confirmed"
            ? "TLS certificate is trusted, hostname-valid, and within its validity window"
            : validityFailed
              ? "TLS certificate is outside its validity window"
              : `TLS verification failed: ${authorizationError}`,
        certificatePem: certificate.raw ? pemFromRaw(certificate.raw) : null,
      };
      socket.end();
      finish(result);
    });
    socket.once("timeout", () => {
      socket.destroy();
      finish({
        status: "failed",
        subject: null,
        san: [],
        issuer: null,
        validFrom: null,
        validTo: null,
        fingerprint256: null,
        authorizationError: "connection-timeout",
        evidence: `TLS connection timed out after ${timeoutMs}ms`,
        certificatePem: null,
      });
    });
    socket.once("error", (error) => {
      finish({
        status: "failed",
        subject: null,
        san: [],
        issuer: null,
        validFrom: null,
        validTo: null,
        fingerprint256: null,
        authorizationError: errorMessage(error),
        evidence: `TLS connection failed: ${errorMessage(error)}`,
        certificatePem: null,
      });
    });
  });
}

function normalizeLocation(value) {
  if (!value) return null;
  const decoded = Buffer.from(value, "latin1").toString("utf8");
  return decoded.includes("�") ? value : decoded;
}

function requestHop(url, timeoutMs) {
  return new Promise((resolve, reject) => {
    const req = request(
      url,
      {
        method: "GET",
        rejectUnauthorized: false,
        headers: { "user-agent": "lps-infrastructure-preflight/1" },
      },
      (response) => {
        response.resume();
        resolve({
          url,
          statusCode: response.statusCode ?? null,
          location: response.headers.location
            ? new URL(normalizeLocation(response.headers.location), url).href
            : null,
        });
      },
    );
    req.setTimeout(timeoutMs, () =>
      req.destroy(new Error(`request timed out after ${timeoutMs}ms`)),
    );
    req.once("error", reject);
    req.end();
  });
}

export async function inspectHttp(hostname, timeoutMs = 8_000, maxRedirects = 10) {
  const initialUrl = `https://${hostname}/`;
  const visited = new Set();
  const chain = [];
  let current = initialUrl;
  try {
    for (let redirectCount = 0; redirectCount <= maxRedirects; redirectCount += 1) {
      if (visited.has(current)) {
        return {
          status: "failed",
          chain,
          finalUrl: current,
          evidence: `Redirect loop detected at ${current}`,
        };
      }
      visited.add(current);
      const hop = await requestHop(current, timeoutMs);
      chain.push(hop);
      if (hop.statusCode && hop.statusCode >= 300 && hop.statusCode < 400 && hop.location) {
        current = hop.location;
        continue;
      }
      const status =
        hop.statusCode && hop.statusCode >= 200 && hop.statusCode < 400 ? "confirmed" : "failed";
      return {
        status,
        chain,
        finalUrl: current,
        evidence:
          status === "confirmed"
            ? `HTTP chain completed with status ${hop.statusCode}`
            : `HTTP chain ended with status ${hop.statusCode}`,
      };
    }
    return {
      status: "failed",
      chain,
      finalUrl: current,
      evidence: `Exceeded ${maxRedirects} redirects`,
    };
  } catch (error) {
    return {
      status: "failed",
      chain,
      finalUrl: current,
      evidence: `HTTP request failed: ${errorMessage(error)}`,
    };
  }
}

function evaluateTls(raw, checkedAt) {
  if (raw.status === "failed" && !raw.validTo) return raw;
  const now = Date.parse(checkedAt);
  const validFrom = Date.parse(raw.validFrom);
  const validTo = Date.parse(raw.validTo);
  if (
    !Number.isFinite(validFrom) ||
    !Number.isFinite(validTo) ||
    now < validFrom ||
    now > validTo
  ) {
    return { ...raw, status: "failed", evidence: "TLS certificate is outside its validity window" };
  }
  return raw;
}

function evaluateHttp(raw, role, canonicalHost) {
  const seen = new Set();
  for (const hop of raw.chain) {
    if (seen.has(hop.url)) {
      return { ...raw, status: "failed", evidence: `Redirect loop detected at ${hop.url}` };
    }
    seen.add(hop.url);
  }
  if (raw.status === "failed") return raw;
  let final;
  try {
    final = new URL(raw.finalUrl);
  } catch {
    return { ...raw, status: "failed", evidence: "HTTP chain has an invalid final URL" };
  }
  if (final.protocol !== "https:" || final.hostname !== canonicalHost) {
    return {
      ...raw,
      status: "failed",
      evidence: `HTTP chain does not end on canonical HTTPS host ${canonicalHost}`,
    };
  }
  if (role === "alternate" && raw.chain.length !== 2) {
    return {
      ...raw,
      status: "failed",
      evidence: "Alternate host is not a one-hop canonical redirect",
    };
  }
  return raw;
}

export function evaluateReport(raw) {
  const hosts = raw.hosts.map((host) => ({
    ...host,
    tls: evaluateTls(host.tls, raw.checkedAt),
    http: evaluateHttp(host.http, host.role, raw.canonicalHost),
  }));
  const capabilities = CAPABILITIES.map((name) => {
    const supplied = raw.capabilities?.find((item) => item.name === name);
    return (
      supplied ?? {
        name,
        status: "owner-unconfirmed",
        evidence: CAPABILITY_EVIDENCE[name],
      }
    );
  });
  const statuses = [
    ...hosts.flatMap((host) => [
      host.dns.status,
      ...Object.values(host.dns.records).map((record) => record.status),
      host.tls.status,
      host.http.status,
    ]),
    ...capabilities.map((item) => item.status),
  ];
  if (statuses.some((status) => !ALLOWED_STATUSES.has(status))) {
    throw new Error("Every check must use confirmed, failed, or owner-unconfirmed status");
  }
  return {
    schemaVersion: 1,
    checkedAt: raw.checkedAt,
    canonicalHost: raw.canonicalHost,
    alternateHost: raw.alternateHost,
    mutationMode: "read-only",
    overallStatus: statuses.includes("failed")
      ? "failed"
      : statuses.includes("owner-unconfirmed")
        ? "owner-unconfirmed"
        : "confirmed",
    hosts,
    capabilities,
  };
}

export async function inspectInfrastructure({ host, alternate, timeoutMs = 8_000 }) {
  const checkedAt = new Date().toISOString();
  const hosts = await Promise.all(
    [host, alternate].map(async (hostname, index) => {
      const [dns, tlsResult, http] = await Promise.all([
        inspectDns(hostname),
        inspectTls(hostname, timeoutMs),
        inspectHttp(hostname, timeoutMs),
      ]);
      const { certificatePem, ...tlsReport } = tlsResult;
      return {
        hostname,
        role: index === 0 ? "canonical" : "alternate",
        dns,
        tls: tlsReport,
        http,
        certificatePem,
      };
    }),
  );
  const reportHosts = hosts.map(({ certificatePem: _certificatePem, ...item }) => item);
  return {
    report: evaluateReport({
      checkedAt,
      canonicalHost: host,
      alternateHost: alternate,
      hosts: reportHosts,
    }),
    certificates: Object.fromEntries(hosts.map((item) => [item.hostname, item.certificatePem])),
  };
}
