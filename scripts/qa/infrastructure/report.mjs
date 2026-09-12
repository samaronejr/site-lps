function values(records) {
  return records.length > 0 ? records.map((record) => `\`${record}\``).join(", ") : "none observed";
}

function cell(value) {
  return value ?? "not observed";
}

export function renderInfrastructureReport(report) {
  const hostSections = report.hosts
    .map((host) => {
      const hops = host.http.chain.length
        ? host.http.chain
            .map(
              (hop, index) =>
                `${index + 1}. \`${hop.url}\` - \`${hop.statusCode ?? "no response"}\`${hop.location ? ` - Location: \`${hop.location}\`` : ""}`,
            )
            .join("\n")
        : "No HTTP response was received.";
      return `## ${host.hostname} (${host.role})

### DNS - ${host.dns.status}

| Record | Status | Values | Evidence |
| --- | --- | --- | --- |
| A | ${host.dns.records.A.status} | ${values(host.dns.records.A.records)} | ${host.dns.records.A.evidence} |
| AAAA | ${host.dns.records.AAAA.status} | ${values(host.dns.records.AAAA.records)} | ${host.dns.records.AAAA.evidence} |
| CNAME | ${host.dns.records.CNAME.status} | ${values(host.dns.records.CNAME.records)} | ${host.dns.records.CNAME.evidence} |

Overall DNS evidence: ${host.dns.evidence}

### TLS - ${host.tls.status}

| Field | Observed value |
| --- | --- |
| Subject | ${cell(host.tls.subject)} |
| SAN | ${host.tls.san.length ? host.tls.san.map((name) => `\`${name}\``).join(", ") : "not observed"} |
| Issuer | ${cell(host.tls.issuer)} |
| Valid from | ${cell(host.tls.validFrom)} |
| Valid to | ${cell(host.tls.validTo)} |
| SHA-256 fingerprint | ${cell(host.tls.fingerprint256)} |
| Verification error | ${cell(host.tls.authorizationError)} |

Evidence: ${host.tls.evidence}

### HTTP - ${host.http.status}

${hops}

Final URL: \`${host.http.finalUrl}\`

Evidence: ${host.http.evidence}`;
    })
    .join("\n\n");

  const capabilityRows = report.capabilities
    .map((item) => `| ${item.name} | ${item.status} | ${item.evidence} |`)
    .join("\n");

  return `# Infrastructure preflight

Generated from read-only live DNS, TLS, and HTTP checks at **${report.checkedAt}**.

- Canonical host: \`https://${report.canonicalHost}/\`
- Alternate host: \`https://${report.alternateHost}/\`
- Overall status: **${report.overallStatus}**
- Mutation mode: **${report.mutationMode}**

Statuses in this report are restricted to \`confirmed\`, \`failed\`, and \`owner-unconfirmed\`. Missing owner evidence is never inferred from the public surface.

${hostSections}

## Operational capabilities

| Capability | Status | Evidence |
| --- | --- | --- |
${capabilityRows}

## Launch decision

Production launch is blocked while any item is \`failed\` or \`owner-unconfirmed\`. DNS/TLS/HTTP failures require UFRJ/COPPE infrastructure ownership to remediate and re-run this preflight. Each operational capability requires documentary confirmation from its accountable owner; public DNS or HTTP behavior is not evidence of runtime, access, recovery, or incident processes.
`;
}
