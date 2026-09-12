/**
 * Passive security scan for the public and credential surfaces.
 *
 * Implements the OWASP ZAP passive-rule classes that apply to this site
 * (header policy, cookie policy, information disclosure, third-party content,
 * private-field leakage) without sending any attack payload: every request is
 * a plain GET a normal visitor could make.
 */

const SENTINELS = [
  "_lps_owner_user_id",
  "_lps_translation_reviewer_id",
  "_lps_private_notes",
  "_lps_review_notes",
  "actor_user_id",
  "_two_factor_totp_key",
  "LPS_PRIVATE_SENTINEL",
  "DB_PASSWORD",
  "AUTH_SALT",
];

const DEBUG_PATTERNS = [
  /\bFatal error\b/i,
  /\bWarning:\s.*\son line\s\d+/i,
  /\bNotice:\s.*\son line\s\d+/i,
  /\bDeprecated:\s.*\son line\s\d+/i,
  /Stack trace:/i,
  /\bcall_user_func_array\(\)/,
];

const REQUIRED_HEADERS = {
  "content-security-policy": "high",
  "x-content-type-options": "medium",
  "x-frame-options": "medium",
  "referrer-policy": "medium",
  "permissions-policy": "low",
};

function finding(severity, rule, url, detail) {
  return { severity, rule, url, detail };
}

function checkCsp(url, csp, findings) {
  if (!csp) return;
  const directives = new Map(
    csp
      .split(";")
      .map((part) => part.trim())
      .filter(Boolean)
      .map((part) => {
        const [name, ...values] = part.split(/\s+/);
        return [name.toLowerCase(), values];
      }),
  );
  const script = directives.get("script-src") ?? directives.get("default-src") ?? [];
  for (const unsafe of ["'unsafe-inline'", "'unsafe-eval'", "*", "data:", "http:", "https:"]) {
    if (script.includes(unsafe)) {
      findings.push(
        finding("high", "csp-unsafe-script-source", url, `script-src allows ${unsafe}`),
      );
    }
  }
  for (const [name, expected] of [
    ["object-src", "'none'"],
    ["base-uri", "'none'"],
    ["frame-ancestors", "'none'"],
  ]) {
    const value = directives.get(name);
    if (value === undefined) {
      findings.push(finding("medium", "csp-missing-directive", url, `${name} is not declared`));
      continue;
    }
    if (!value.includes(expected) && !(name === "frame-ancestors" && value.includes("'self'"))) {
      findings.push(finding("medium", "csp-weak-directive", url, `${name} is ${value.join(" ")}`));
    }
  }
  for (const values of directives.values()) {
    for (const value of values) {
      if (/^https?:\/\//i.test(value)) {
        findings.push(finding("high", "csp-external-source", url, `external source ${value}`));
      }
    }
  }
}

function checkCookies(url, response, isPublic, findings) {
  const cookies =
    typeof response.headers.getSetCookie === "function" ? response.headers.getSetCookie() : [];
  for (const cookie of cookies) {
    const name = cookie.split("=")[0].trim();
    if (isPublic) {
      findings.push(finding("high", "public-cookie", url, `public response sets cookie ${name}`));
      continue;
    }
    if (!/;\s*httponly/i.test(cookie)) {
      findings.push(finding("medium", "cookie-missing-httponly", url, `${name} lacks HttpOnly`));
    }
  }
}

function checkDisclosure(url, response, body, findings) {
  const server = response.headers.get("server") ?? "";
  if (/\d/.test(server)) {
    findings.push(finding("medium", "version-disclosure", url, `Server: ${server}`));
  }
  const powered = response.headers.get("x-powered-by");
  if (powered) {
    findings.push(finding("medium", "version-disclosure", url, `X-Powered-By: ${powered}`));
  }
  if (/<meta[^>]+name=["']generator["'][^>]*content=["'][^"']*\d/i.test(body)) {
    findings.push(
      finding("medium", "generator-disclosure", url, "generator meta exposes a version"),
    );
  }
  for (const pattern of DEBUG_PATTERNS) {
    if (pattern.test(body)) {
      findings.push(finding("high", "debug-output", url, `response matches ${pattern}`));
    }
  }
  for (const sentinel of SENTINELS) {
    if (body.includes(sentinel)) {
      findings.push(finding("critical", "private-field-leak", url, `response exposes ${sentinel}`));
    }
  }
  if (/<title>Index of /i.test(body)) {
    findings.push(finding("high", "directory-listing", url, "directory listing is enabled"));
  }
}

function checkThirdParty(url, origin, body, findings) {
  const active =
    /<(?:script|iframe|object|embed|link|img|video|audio|source|form)\b[^>]*\b(?:src|href|action|data)=["']([^"']+)["']/gi;
  for (const match of body.matchAll(active)) {
    const raw = match[1];
    if (
      /^(?:#|mailto:|tel:|data:|\/|\.|[a-z0-9-]+\.[a-z]|\?)/i.test(raw) === false &&
      !/^https?:/i.test(raw)
    )
      continue;
    if (!/^https?:\/\//i.test(raw)) continue;
    if (raw.startsWith(origin)) continue;
    const tag = match[0].slice(1, 10).split(/[\s>]/)[0].toLowerCase();
    if (tag === "a") continue;
    findings.push(finding("high", "third-party-runtime-request", url, `<${tag}> loads ${raw}`));
  }
}

export async function passiveScan({ origin, targets, publicPaths }) {
  const findings = [];
  const scanned = [];
  for (const path of targets) {
    const url = origin + path;
    const response = await fetch(url, { redirect: "manual" });
    const body = await response.text();
    const isPublic = publicPaths.includes(path);
    scanned.push({ path, status: response.status, bytes: body.length, public: isPublic });
    if (response.status >= 500) {
      findings.push(finding("high", "server-error", url, `status ${response.status}`));
    }
    const contentType = response.headers.get("content-type") ?? "";
    if (response.status < 400 || contentType.includes("text/html")) {
      for (const [header, severity] of Object.entries(REQUIRED_HEADERS)) {
        if (!response.headers.get(header)) {
          findings.push(finding(severity, "missing-security-header", url, `missing ${header}`));
        }
      }
      checkCsp(url, response.headers.get("content-security-policy"), findings);
    }
    checkCookies(url, response, isPublic, findings);
    checkDisclosure(url, response, body, findings);
    if (contentType.includes("text/html")) checkThirdParty(url, origin, body, findings);
  }
  const counts = { critical: 0, high: 0, medium: 0, low: 0 };
  for (const item of findings) counts[item.severity] += 1;
  return {
    lane: "security",
    schemaVersion: 1,
    origin,
    scanned,
    counts,
    findings,
    status: counts.critical === 0 && counts.high === 0 ? "passed" : "failed",
  };
}
