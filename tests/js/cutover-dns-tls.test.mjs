import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import {
  formatReadinessReport,
  REQUIRED_CHECKS,
  validateCutoverReadiness,
} from "../../scripts/cutover/dns-tls-core.mjs";

const fixture = (name) =>
  JSON.parse(
    readFileSync(
      fileURLToPath(new URL(`../fixtures/cutover/${name}.json`, import.meta.url)),
      "utf8",
    ),
  );

const failuresOf = (report) => report.findings.filter((finding) => finding.status === "fail");
const failedChecks = (report) => failuresOf(report).map((finding) => finding.check);

describe("readiness contract", () => {
  it("evaluates every required cutover check exactly once", () => {
    const report = validateCutoverReadiness(fixture("healthy"));

    expect(report.findings.map((finding) => finding.check)).toEqual(REQUIRED_CHECKS);
    expect(REQUIRED_CHECKS).toEqual([
      "host-coverage",
      "canonicalization",
      "redirect-loop",
      "certificate-validity",
      "certificate-renewal-path",
      "hsts-timing",
      "health-checks",
      "rollback-plan",
    ]);
  });

  it("approves the healthy baseline plan", () => {
    const report = validateCutoverReadiness(fixture("healthy"));

    expect(report.ready).toBe(true);
    expect(failuresOf(report)).toEqual([]);
    expect(report.planId).toBe("cutover-healthy-baseline");
  });

  it("proves both hosts are covered and canonicalization is one hop", () => {
    const report = validateCutoverReadiness(fixture("healthy"));
    const coverage = report.findings.find((finding) => finding.check === "host-coverage");
    const canonical = report.findings.find((finding) => finding.check === "canonicalization");

    expect(coverage.status).toBe("pass");
    expect(coverage.detail).toContain("www.canonical.example");
    expect(canonical.detail).toContain("1 hop");
  });

  it("renders a report naming every failing item", () => {
    const report = validateCutoverReadiness(fixture("current-live-risk"));
    const rendered = formatReadinessReport(report);

    expect(rendered).toContain("cutover-current-live-risk");
    expect(rendered).toContain("| check |");
    for (const finding of failuresOf(report)) {
      expect(rendered).toContain(finding.item);
    }
  });
});

describe("failure fixtures refuse readiness and name the offending item", () => {
  it("refuses the currently recorded live risk (root timeout, expired www certificate)", () => {
    const report = validateCutoverReadiness(fixture("current-live-risk"));

    expect(report.ready).toBe(false);
    expect(failedChecks(report)).toEqual(
      expect.arrayContaining([
        "certificate-validity",
        "certificate-renewal-path",
        "canonicalization",
        "health-checks",
        "rollback-plan",
      ]),
    );
    const items = failuresOf(report).map((finding) => finding.item);
    expect(items).toContain("lps.ufrj.br");
    expect(items).toContain("www.lps.ufrj.br");
  });

  it("refuses an expired certificate and names the host", () => {
    const report = validateCutoverReadiness(fixture("expired-certificate"));

    expect(report.ready).toBe(false);
    const finding = failuresOf(report).find(
      (entry) => entry.check === "certificate-validity" && entry.item === "canonical.example",
    );
    expect(finding).toBeDefined();
    expect(finding.detail).toContain("2026-04-01");
  });

  it("refuses an uncovered www host on both DNS and certificate coverage", () => {
    const report = validateCutoverReadiness(fixture("uncovered-www"));

    expect(report.ready).toBe(false);
    const coverage = failuresOf(report).find((entry) => entry.check === "host-coverage");
    expect(coverage.item).toBe("www.canonical.example");
    expect(failedChecks(report)).toContain("canonicalization");
  });

  it("refuses a redirect loop and names the cycle", () => {
    const report = validateCutoverReadiness(fixture("redirect-loop"));

    expect(report.ready).toBe(false);
    const finding = failuresOf(report).find((entry) => entry.check === "redirect-loop");
    expect(finding.detail).toContain("https://canonical.example/");
    expect(finding.detail).toContain("->");
  });

  it("refuses a failed health check and names the check id", () => {
    const report = validateCutoverReadiness(fixture("failed-health-check"));

    expect(report.ready).toBe(false);
    const finding = failuresOf(report).find((entry) => entry.check === "health-checks");
    expect(finding.item).toBe("canonical-root");
  });

  it("refuses HSTS enabled before TLS is stable on both hosts", () => {
    const plan = fixture("healthy");
    plan.tls[1].stableSinceDays = 2;
    plan.hsts = {
      enabled: true,
      maxAgeSeconds: 31536000,
      preload: true,
      requiredStableTlsDays: 30,
      plannedEnableAfterStableDays: 30,
    };

    const report = validateCutoverReadiness(plan);

    expect(report.ready).toBe(false);
    const finding = failuresOf(report).find((entry) => entry.check === "hsts-timing");
    expect(finding.item).toBe("www.canonical.example");
    expect(finding.detail).toContain("30");
  });

  it("refuses a missing renewal path and names the host", () => {
    const plan = fixture("healthy");
    plan.tls[0].renewal = { method: null, leadTimeDays: null, ownerRole: null };

    const report = validateCutoverReadiness(plan);

    expect(report.ready).toBe(false);
    const finding = failuresOf(report).find((entry) => entry.check === "certificate-renewal-path");
    expect(finding.item).toBe("canonical.example");
  });

  it("refuses a rollback plan without a command or thresholds", () => {
    const plan = fixture("healthy");
    plan.rollback = { command: [], thresholds: [] };

    const report = validateCutoverReadiness(plan);

    expect(report.ready).toBe(false);
    expect(failedChecks(report)).toContain("rollback-plan");
  });

  it("refuses a multi-hop canonicalization chain", () => {
    const plan = fixture("healthy");
    plan.redirects = [
      { from: "https://www.canonical.example/", to: "https://legacy.example/", status: 301 },
      { from: "https://legacy.example/", to: "https://canonical.example/", status: 301 },
    ];

    const report = validateCutoverReadiness(plan);

    expect(report.ready).toBe(false);
    const finding = failuresOf(report).find((entry) => entry.check === "canonicalization");
    expect(finding.detail).toContain("2 hop");
  });
});

describe("cutover CLI safety and exit codes", () => {
  it("refuses DNS or certificate mutation without an explicit target and confirmation", async () => {
    const { assertCutoverExecutionAllowed } = await import("../../scripts/cutover/dns-tls.mjs");

    expect(assertCutoverExecutionAllowed({ env: {}, flags: {} })).toEqual({
      allowed: false,
      reason:
        "Set LPS_CUTOVER_TARGET to the approved zone alias and pass --confirm-mutates-dns before executing cutover stages.",
    });
    expect(
      assertCutoverExecutionAllowed({ env: { LPS_CUTOVER_TARGET: "@zone" }, flags: {} }).allowed,
    ).toBe(false);
    expect(
      assertCutoverExecutionAllowed({ env: {}, flags: { "confirm-mutates-dns": true } }).allowed,
    ).toBe(false);
    expect(
      assertCutoverExecutionAllowed({
        env: { LPS_CUTOVER_TARGET: "@zone" },
        flags: { "confirm-mutates-dns": true },
      }),
    ).toEqual({ allowed: true, reason: "" });
  });

  it("exposes a guarded rollback subcommand that never mutates without approval", async () => {
    const { CUTOVER_COMMANDS, assertCutoverExecutionAllowed } = await import(
      "../../scripts/cutover/dns-tls.mjs"
    );

    expect(CUTOVER_COMMANDS).toEqual(["check", "apply", "rollback"]);
    expect(assertCutoverExecutionAllowed({ env: {}, flags: {} }, "rollback").allowed).toBe(false);
  });

  it("exits zero on the healthy baseline and non-zero on every failure fixture", async () => {
    const { readinessExitCode } = await import("../../scripts/cutover/dns-tls.mjs");

    expect(readinessExitCode(validateCutoverReadiness(fixture("healthy")))).toBe(0);
    for (const name of [
      "current-live-risk",
      "expired-certificate",
      "uncovered-www",
      "redirect-loop",
      "failed-health-check",
    ]) {
      expect(readinessExitCode(validateCutoverReadiness(fixture(name)))).toBe(1);
    }
  });
});
