# Infrastructure preflight

Generated from read-only live DNS, TLS, and HTTP checks at **2026-08-30T23:07:34.838Z**.

- Canonical host: `https://lps.ufrj.br/`
- Alternate host: `https://www.lps.ufrj.br/`
- Overall status: **failed**
- Mutation mode: **read-only**

Statuses in this report are restricted to `confirmed`, `failed`, and `owner-unconfirmed`. Missing owner evidence is never inferred from the public surface.

## lps.ufrj.br (canonical)

### DNS - confirmed

| Record | Status | Values | Evidence |
| --- | --- | --- | --- |
| A | confirmed | `146.164.147.40` | DNS A query completed |
| AAAA | confirmed | none observed | DNS AAAA query completed with no records |
| CNAME | confirmed | none observed | DNS CNAME query completed with no records |

Overall DNS evidence: Host has an address or CNAME record

### TLS - failed

| Field | Observed value |
| --- | --- |
| Subject | not observed |
| SAN | not observed |
| Issuer | not observed |
| Valid from | not observed |
| Valid to | not observed |
| SHA-256 fingerprint | not observed |
| Verification error | connection-timeout |

Evidence: TLS connection timed out after 8000ms

### HTTP - failed

No HTTP response was received.

Final URL: `https://lps.ufrj.br/`

Evidence: HTTP request failed: request timed out after 8000ms

## www.lps.ufrj.br (alternate)

### DNS - confirmed

| Record | Status | Values | Evidence |
| --- | --- | --- | --- |
| A | confirmed | `146.164.147.7` | DNS A query completed |
| AAAA | confirmed | none observed | DNS AAAA query completed |
| CNAME | confirmed | `proxy-server.lps.ufrj.br` | DNS CNAME query completed |

Overall DNS evidence: Host has an address or CNAME record

### TLS - failed

| Field | Observed value |
| --- | --- |
| Subject | CN=lps.ufrj.br |
| SAN | `lps.ufrj.br`, `www.lps.ufrj.br` |
| Issuer | C=US, O=Let's Encrypt, CN=E7 |
| Valid from | 2025-09-28T18:30:38.000Z |
| Valid to | 2025-12-27T18:30:37.000Z |
| SHA-256 fingerprint | D4:AD:95:51:22:AA:BB:F4:3D:B3:4A:83:D1:78:96:87:60:C7:E0:86:35:92:7D:22:3C:77:C9:C1:79:C2:6E:B4 |
| Verification error | CERT_HAS_EXPIRED |

Evidence: TLS certificate is outside its validity window

### HTTP - failed

1. `https://www.lps.ufrj.br/` - `301` - Location: `https://sites.google.com/lps.ufrj.br/lps/in%C3%ADcio/`
2. `https://sites.google.com/lps.ufrj.br/lps/in%C3%ADcio/` - `200`

Final URL: `https://sites.google.com/lps.ufrj.br/lps/in%C3%ADcio/`

Evidence: HTTP chain does not end on canonical HTTPS host lps.ufrj.br

## Operational capabilities

| Capability | Status | Evidence |
| --- | --- | --- |
| runtime | owner-unconfirmed | No owner evidence for maintained WordPress, PHP, database versions, support, or runtime ownership |
| staging | owner-unconfirmed | No owner evidence for a staging endpoint, access, production parity, or staging ownership |
| deployment | owner-unconfirmed | No owner evidence for SSH/deployment access, release path, deployer, or rollback path |
| cron | owner-unconfirmed | No owner evidence for the scheduler mechanism, frequency, execution access, or scheduler ownership |
| cache purge | owner-unconfirmed | No owner evidence for cache layers, purge procedure, purge access, or cache ownership |
| backup/restore | owner-unconfirmed | No owner evidence for backup scope, schedule, retention, restore owner, or restore rehearsal |
| logs | owner-unconfirmed | No owner evidence for log locations, access, retention, or log-review ownership |
| monitoring | owner-unconfirmed | No owner evidence for uptime/service checks, alert routing, or response ownership |
| contacts | owner-unconfirmed | No named DNS, TLS, hosting, deployment, or incident contact and escalation route was provided |

## Launch decision

Production launch is blocked while any item is `failed` or `owner-unconfirmed`. DNS/TLS/HTTP failures require UFRJ/COPPE infrastructure ownership to remediate and re-run this preflight. Each operational capability requires documentary confirmation from its accountable owner; public DNS or HTTP behavior is not evidence of runtime, access, recovery, or incident processes.
