/**
 * DNS and TLS cutover readiness validation.
 *
 * Pure plan validation over a documented cutover plan object. It performs no
 * DNS query, no TLS handshake, no HTTP request and no mutation: it lints the
 * plan that a live cutover would execute, so an unsafe plan is refused before
 * anyone touches a zone or requests a certificate.
 *
 * Every failing check names the exact offending item (host, redirect edge or
 * health-check id) so refusal is actionable rather than a bare boolean.
 */

/** Ordered readiness checks evaluated for every plan. */
export const REQUIRED_CHECKS = [
  "host-coverage",
  "canonicalization",
  "redirect-loop",
  "certificate-validity",
  "certificate-renewal-path",
  "hsts-timing",
  "health-checks",
  "rollback-plan",
];

function pass(check, item, detail) {
  return { check, status: "pass", item, detail };
}

function fail(check, item, detail) {
  return { check, status: "fail", item, detail };
}

function hostOf(url) {
  try {
    return new URL(url).host;
  } catch {
    return "";
  }
}

function dnsHosts(plan) {
  return new Set((plan?.dns?.records ?? []).map((record) => record.host));
}

function tlsFor(plan, host) {
  return (plan?.tls ?? []).find((entry) => entry.host === host);
}

function checkHostCoverage(plan) {
  const canonical = plan.canonicalHost;
  const alternate = plan.alternateHost;
  const hosts = dnsHosts(plan);

  for (const host of [canonical, alternate]) {
    if (!hosts.has(host)) {
      return fail("host-coverage", host, `No DNS record covers ${host} in the cutover plan.`);
    }
    const tls = tlsFor(plan, host);
    if (tls === undefined) {
      return fail("host-coverage", host, `No TLS entry covers ${host} in the cutover plan.`);
    }
    const covered = (tls.san ?? []).includes(host);
    if (!covered) {
      return fail(
        "host-coverage",
        host,
        `Certificate for ${host} does not list ${host} in its SAN set (${(tls.san ?? []).join(", ") || "empty"}).`,
      );
    }
  }
  return pass(
    "host-coverage",
    `${canonical} + ${alternate}`,
    `DNS and certificate SAN coverage present for ${canonical} and ${alternate}.`,
  );
}

function redirectChain(plan) {
  const edges = new Map((plan.redirects ?? []).map((redirect) => [redirect.from, redirect]));
  const start = `https://${plan.alternateHost}/`;
  const chain = [];
  const seen = new Set();
  let current = start;
  while (edges.has(current)) {
    if (seen.has(current)) {
      return { chain, loop: [...chain.map((edge) => edge.from), current] };
    }
    seen.add(current);
    const edge = edges.get(current);
    chain.push(edge);
    current = edge.to;
  }
  return { chain, loop: null, finalUrl: current };
}

function checkCanonicalization(plan) {
  const canonical = plan.canonicalHost;
  const alternate = plan.alternateHost;
  const { chain, loop, finalUrl } = redirectChain(plan);

  if (loop !== null) {
    return fail(
      "canonicalization",
      alternate,
      `Canonicalization from ${alternate} never terminates: ${loop.join(" -> ")}.`,
    );
  }
  if (chain.length === 0) {
    return fail(
      "canonicalization",
      alternate,
      `No canonical redirect is planned from https://${alternate}/ to https://${canonical}/.`,
    );
  }
  if (chain.length !== 1) {
    return fail(
      "canonicalization",
      alternate,
      `Canonicalization from ${alternate} takes ${chain.length} hops; exactly 1 hop is required.`,
    );
  }
  if (chain[0].status !== 301) {
    return fail(
      "canonicalization",
      alternate,
      `Canonical redirect from ${alternate} uses status ${chain[0].status}; a permanent 301 is required.`,
    );
  }
  if (hostOf(finalUrl) !== canonical) {
    return fail(
      "canonicalization",
      alternate,
      `Canonical redirect from ${alternate} ends on ${hostOf(finalUrl) || finalUrl} instead of ${canonical} (1 hop).`,
    );
  }
  return pass(
    "canonicalization",
    alternate,
    `https://${alternate}/ reaches https://${canonical}/ in 1 hop with a 301.`,
  );
}

function checkRedirectLoop(plan) {
  const edges = new Map((plan.redirects ?? []).map((redirect) => [redirect.from, redirect.to]));
  for (const start of edges.keys()) {
    const path = [];
    const seen = new Set();
    let node = start;
    while (edges.has(node)) {
      if (seen.has(node)) {
        const cycle = [...path.slice(path.indexOf(node)), node];
        return fail("redirect-loop", node, `Redirect cycle detected: ${cycle.join(" -> ")}.`);
      }
      seen.add(node);
      path.push(node);
      node = edges.get(node);
    }
  }
  return pass("redirect-loop", "redirects", "Redirect graph is acyclic.");
}

function checkCertificateValidity(plan) {
  const reference = Date.parse(plan.referenceTime);
  for (const host of [plan.canonicalHost, plan.alternateHost]) {
    const tls = tlsFor(plan, host);
    if (tls === undefined) {
      return fail("certificate-validity", host, `No TLS entry exists for ${host}.`);
    }
    if (tls.reachable !== true) {
      return fail(
        "certificate-validity",
        host,
        `TLS endpoint for ${host} is not reachable in the recorded plan evidence.`,
      );
    }
    if (!tls.notAfter || !tls.notBefore) {
      return fail(
        "certificate-validity",
        host,
        `Certificate validity window for ${host} is not recorded.`,
      );
    }
    const notAfter = Date.parse(tls.notAfter);
    const notBefore = Date.parse(tls.notBefore);
    if (notAfter <= reference) {
      return fail(
        "certificate-validity",
        host,
        `Certificate for ${host} expired at ${tls.notAfter} (reference ${plan.referenceTime}).`,
      );
    }
    if (notBefore > reference) {
      return fail(
        "certificate-validity",
        host,
        `Certificate for ${host} is not valid before ${tls.notBefore}.`,
      );
    }
  }
  return pass(
    "certificate-validity",
    `${plan.canonicalHost} + ${plan.alternateHost}`,
    "Both hosts present a certificate valid at the reference time.",
  );
}

function checkRenewalPath(plan) {
  for (const host of [plan.canonicalHost, plan.alternateHost]) {
    const tls = tlsFor(plan, host);
    if (tls === undefined) {
      return fail("certificate-renewal-path", host, `No TLS entry exists for ${host}.`);
    }
    const issuance = tls.issuance ?? {};
    const renewal = tls.renewal ?? {};
    if (!issuance.method) {
      return fail(
        "certificate-renewal-path",
        host,
        `No certificate issuance method is documented for ${host}.`,
      );
    }
    if (!renewal.method || typeof renewal.leadTimeDays !== "number") {
      return fail(
        "certificate-renewal-path",
        host,
        `No automated renewal method with a lead time is documented for ${host}.`,
      );
    }
    if (!renewal.ownerRole) {
      return fail(
        "certificate-renewal-path",
        host,
        `Renewal for ${host} has no accountable owner role.`,
      );
    }
  }
  return pass(
    "certificate-renewal-path",
    `${plan.canonicalHost} + ${plan.alternateHost}`,
    "Issuance and owned automated renewal are documented for both hosts.",
  );
}

function checkHstsTiming(plan) {
  const hsts = plan.hsts ?? {};
  const required = hsts.requiredStableTlsDays ?? 30;
  if (hsts.enabled !== true) {
    return pass(
      "hsts-timing",
      "hsts",
      `HSTS stays disabled until TLS is stable for ${required} days on both hosts.`,
    );
  }
  for (const host of [plan.canonicalHost, plan.alternateHost]) {
    const tls = tlsFor(plan, host);
    const stable = tls?.stableSinceDays ?? 0;
    if (stable < required) {
      return fail(
        "hsts-timing",
        host,
        `HSTS is enabled while ${host} has only ${stable} stable TLS day(s); ${required} are required before this irreversible header.`,
      );
    }
  }
  if (typeof hsts.maxAgeSeconds !== "number" || hsts.maxAgeSeconds <= 0) {
    return fail("hsts-timing", "hsts", "HSTS is enabled without a positive max-age.");
  }
  return pass(
    "hsts-timing",
    "hsts",
    `HSTS enabled after ${required} stable TLS days on both hosts.`,
  );
}

function checkHealthChecks(plan) {
  const checks = plan.healthChecks ?? [];
  if (checks.length === 0) {
    return fail("health-checks", "healthChecks", "No health checks are defined for the cutover.");
  }
  const coveredHosts = new Set(checks.map((check) => hostOf(check.url)));
  for (const host of [plan.canonicalHost, plan.alternateHost]) {
    if (!coveredHosts.has(host)) {
      return fail("health-checks", host, `No health check targets ${host}.`);
    }
  }
  for (const check of checks) {
    if (
      typeof check.expectStatus !== "number" ||
      typeof check.intervalSeconds !== "number" ||
      typeof check.failureThreshold !== "number"
    ) {
      return fail(
        "health-checks",
        check.id ?? check.url ?? "unknown",
        `Health check ${check.id ?? check.url} lacks an expected status, interval or failure threshold.`,
      );
    }
    if (check.lastResult === "fail") {
      return fail(
        "health-checks",
        check.id,
        `Health check ${check.id} (${check.url}) last reported fail; readiness is refused.`,
      );
    }
  }
  return pass(
    "health-checks",
    "healthChecks",
    `${checks.length} health check(s) defined and passing.`,
  );
}

function checkRollbackPlan(plan) {
  const rollback = plan.rollback ?? {};
  if (!Array.isArray(rollback.command) || rollback.command.length === 0) {
    return fail("rollback-plan", "rollback.command", "No rollback command is defined.");
  }
  if (!Array.isArray(rollback.thresholds) || rollback.thresholds.length === 0) {
    return fail("rollback-plan", "rollback.thresholds", "No rollback thresholds are defined.");
  }
  const incomplete = rollback.thresholds.find((threshold) => !threshold.id || !threshold.condition);
  if (incomplete !== undefined) {
    return fail(
      "rollback-plan",
      incomplete.id ?? "rollback.thresholds",
      "A rollback threshold has no id or no condition.",
    );
  }
  return pass(
    "rollback-plan",
    "rollback",
    `Rollback command and ${rollback.thresholds.length} threshold(s) defined.`,
  );
}

const CHECK_IMPLEMENTATIONS = {
  "host-coverage": checkHostCoverage,
  canonicalization: checkCanonicalization,
  "redirect-loop": checkRedirectLoop,
  "certificate-validity": checkCertificateValidity,
  "certificate-renewal-path": checkRenewalPath,
  "hsts-timing": checkHstsTiming,
  "health-checks": checkHealthChecks,
  "rollback-plan": checkRollbackPlan,
};

/**
 * Validates a documented cutover plan for DNS/TLS readiness.
 *
 * @param {object} plan Cutover plan object.
 * @returns {{planId: string, ready: boolean, findings: object[]}}
 */
export function validateCutoverReadiness(plan) {
  const findings = REQUIRED_CHECKS.map((check) => CHECK_IMPLEMENTATIONS[check](plan ?? {}));
  return {
    planId: plan?.planId ?? "",
    canonicalHost: plan?.canonicalHost ?? "",
    alternateHost: plan?.alternateHost ?? "",
    ready: findings.every((finding) => finding.status === "pass"),
    findings,
  };
}

/**
 * Renders a readiness report as Markdown.
 *
 * @param {object} report Report returned by validateCutoverReadiness.
 * @returns {string} Markdown document.
 */
export function formatReadinessReport(report) {
  const lines = [
    `# DNS/TLS cutover readiness — ${report.planId}`,
    "",
    `Canonical host: \`${report.canonicalHost}\` · alternate host: \`${report.alternateHost}\``,
    "",
    `Readiness: **${report.ready ? "ready" : "refused"}**`,
    "",
    "| check | status | item | detail |",
    "| --- | --- | --- | --- |",
    ...report.findings.map(
      (finding) => `| ${finding.check} | ${finding.status} | ${finding.item} | ${finding.detail} |`,
    ),
    "",
  ];
  return `${lines.join("\n")}\n`;
}
