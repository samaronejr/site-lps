# Role-specific workflows

Seven roles are implemented in
`wp-content/plugins/lps-content-model/includes/class-securitypolicy.php` and installed as WordPress
roles by `class-roles.php` (slug pattern `lps_<role>`, e.g. `lps_section_editor`). Actions are
custom primitive capabilities named `lps_<action>` (e.g. `lps_publish`).

## Prerequisites

- An individual named account with exactly one policy role.
- Collection assignments recorded by an administrator (`_lps_assigned_collections`) for
  `contributor`, `translator` and `section-editor`; other roles are not collection-scoped.
- An enabled Two-Factor provider for `publisher` and `administrator`.
- The routine gate commands available locally: `npm run test` and `tools/composer test`.

## Authoritative action matrix

Actions are exactly those granted by `SecurityPolicy::ACTIONS`. This table is machine-checked against
that source by `node tests/docs/docs-checker.mjs`; a claim the policy denies fails the gate.

| Role | Allowed actions (policy) | Collection-scoped | MFA required |
| --- | --- | --- | --- |
| contributor | `create`, `edit`, `submit` | yes | no |
| translator | `edit`, `submit` | yes | no |
| section-editor | `create`, `edit`, `submit`, `review`, `archive` | yes | no |
| publisher | `create`, `edit`, `submit`, `review`, `publish`, `unpublish`, `archive`, `redirect` | no | yes |
| administrator | `create`, `edit`, `submit`, `review`, `publish`, `unpublish`, `archive`, `import`, `redirect`, `settings`, `audit`, `dormant-report` | no | yes |
| privacy-auditor | `review`, `audit`, `dormant-report` | no | no |
| deployer | `deploy` | no | no |

Audited actions retained in the immutable ledger (`class-audit.php`): `create`, `edit`, `submit`,
`review`, `publish`, `unpublish`, `archive`, `import`, `redirect`, `settings`.

## Contributor

Routine work: create and edit your own drafts, attach provenance, submit for review.

1. Create the record in an assigned collection; set identity, provenance and owner fields.
2. Attach relationships and rights-cleared media; leave unknown facts empty.
3. Submit for review (`draft` → `in_review`).
4. Respond to review findings by editing the draft; never re-submit unchanged.

Boundaries: you cannot publish, approve your own work, change roles or settings, delete records, or
bypass a privacy or accessibility gate. An attempt is denied without any data change.

## Translator

Routine work: create and update the English variant of assigned collections.

1. Open the English variant of a record whose Portuguese source is reviewed.
2. Edit only localized editorial fields: title, excerpt, body, and the localized metadata
   (`_lps_label`, `_lps_synonyms`, `_lps_eligibility`, `_lps_application_instructions`).
3. Mark the translation ready and submit it for independent review.

Boundaries: you cannot write shared identifiers, relations, canonical dates or source records; you
cannot write in the `pt-br` locale; you cannot review or publish your own translation. See
[translation and freshness](translation-freshness.md).

## Section editor

Routine work: own the assigned collections and their review queue.

1. Assess the submitted revision against source priority, factual scope, freshness and expiry,
   relationships, accessibility and media rights.
2. Review translations you did not produce; reject a stale English variant.
3. Archive superseded records (`archive`), retaining historical relationships and provenance.
4. Send a complete reviewed revision to a publisher.

Boundaries: you cannot publish your own work, administer accounts or infrastructure, or overrule
unresolved privacy/rights evidence.

## Publisher

Routine work: make and reverse publication decisions.

1. Confirm the reviewed revision is complete and its publish gates pass.
2. Publish, schedule, unpublish or restore the revision; the decision is attributable.
3. Manage redirects (`redirect`) when a published slug changes, verifying with
   `wp lps redirects verify`.
4. Release reviewed corrections and act on takedown requests together with the privacy auditor.

Boundaries: MFA is mandatory; you cannot treat an unresolved source, privacy, rights, legal-basis or
review issue as approved, and you cannot use a shared account.

## Administrator

Routine work: accounts, settings, imports and audit availability.

1. Provision individual accounts, assign exactly one role and the collection assignments.
2. Maintain site settings (`lps_site_settings`) from official records only.
3. Run reviewed imports (`import`) through the CLI — see
   [the publication and import guide](publication-import-guide.md).
4. Keep audit and revision history available; run the dormant-account report (`dormant-report`).

Boundaries: administrative access does not bypass editorial, privacy or publication gates, and an
unnamed operational contact must not be presented as confirmed.

## Privacy auditor

Routine work: review public personal data, photographs, media rights and public contact routes.

1. Inspect the record's rights holder, license, provenance and privacy-review fields.
2. Record a block or an escalation; a block keeps the item non-public.
3. Participate in takedown assessment with the publisher.

Boundaries: you cannot invent a legal basis, rights or consent, publish content, or deploy.

## Deployer

Routine work: deploy approved versioned artifacts and retain deployment evidence.

1. Build from a clean checkout: `npm ci`, `tools/composer install`, `npm run build`.
2. Deploy the `dist/` artifacts as defined in
   [the release runbook index](../operations/release-runbook-index.md).
3. Retain the deployment and rollback transcript.

Boundaries: no editorial account, no content edits, no reusable publishing secret.

## Verifying the boundary

- `tools/composer test` runs the table-driven role/action/collection matrix and the MFA-bypass
  fixtures in `wp-content/plugins/lps-content-model/tests/SecurityContractsTest.php`.
- `npm run qa:governance` prints the role/collection matrix used by governance.
- `npm run qa:security` runs the passive security lane.
- Per-role end-to-end acceptance simulations are specified in
  [role acceptance simulations](role-acceptance-simulations.md) and are **PENDING** a live CMS.
