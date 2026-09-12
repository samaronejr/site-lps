import { execFile } from "node:child_process";
import { readFile } from "node:fs/promises";
import { promisify } from "node:util";
import { describe, expect, it } from "vitest";
import { CAPABILITIES, evaluateReport } from "../../scripts/qa/infrastructure/checker.mjs";
import { renderInfrastructureReport } from "../../scripts/qa/infrastructure/report.mjs";

const execFileAsync = promisify(execFile);

async function fixture(name) {
  return JSON.parse(await readFile(`tests/fixtures/infrastructure/${name}.json`, "utf8"));
}

async function runFixture(name) {
  try {
    const result = await execFileAsync(
      process.execPath,
      [
        "scripts/run-qa.mjs",
        "infrastructure",
        "--fixture",
        `tests/fixtures/infrastructure/${name}.json`,
      ],
      { cwd: process.cwd() },
    );
    return { exitCode: 0, stdout: result.stdout, stderr: result.stderr };
  } catch (error) {
    return { exitCode: error.code, stdout: error.stdout, stderr: error.stderr };
  }
}

describe("infrastructure preflight", () => {
  it("emits all required facts and owner-unconfirmed capabilities for a healthy fixture", async () => {
    const result = await runFixture("healthy");
    const report = JSON.parse(result.stdout);

    expect(result.exitCode).toBe(0);
    expect(report.overallStatus).toBe("owner-unconfirmed");
    expect(report.hosts[0].dns.records).toHaveProperty("A");
    expect(report.hosts[0].dns.records).toHaveProperty("AAAA");
    expect(report.hosts[0].dns.records).toHaveProperty("CNAME");
    expect(report.hosts[0].tls).toMatchObject({
      subject: "CN=canonical.example",
      san: ["canonical.example", "www.canonical.example"],
      issuer: "CN=Fixture CA",
      validFrom: "2026-01-01T00:00:00.000Z",
      validTo: "2027-01-01T00:00:00.000Z",
    });
    expect(report.capabilities.map((item) => item.name)).toEqual(CAPABILITIES);
    expect(report.capabilities.every((item) => item.status === "owner-unconfirmed")).toBe(true);
    expect(renderInfrastructureReport(report)).toContain("https://www.canonical.example/");
  });

  it("rejects an expired certificate using the fixture observation timestamp", async () => {
    const result = await runFixture("expired-certificate");
    const report = JSON.parse(result.stdout);

    expect(result.exitCode).toBe(1);
    expect(report.overallStatus).toBe("failed");
    expect(report.hosts.every((host) => host.tls.status === "failed")).toBe(true);
    expect(report.hosts[0].tls.evidence).toContain("outside its validity window");
  });

  it("rejects a redirect loop deterministically", async () => {
    const result = await runFixture("redirect-loop");
    const report = JSON.parse(result.stdout);

    expect(result.exitCode).toBe(1);
    expect(report.overallStatus).toBe("failed");
    expect(report.hosts.every((host) => host.http.status === "failed")).toBe(true);
    expect(report.hosts[0].http.evidence).toContain("Redirect loop detected");
  });

  it("rejects statuses outside the three-state vocabulary", async () => {
    const raw = await fixture("healthy");
    raw.hosts[0].dns.status = "unknown";

    expect(() => evaluateReport(raw)).toThrow(
      "Every check must use confirmed, failed, or owner-unconfirmed status",
    );
  });
});
