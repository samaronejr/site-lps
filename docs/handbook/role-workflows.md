# Role-specific workflows

Nine roles are implemented in
`wp-content/plugins/lps-content-model/includes/class-securitypolicy.php` and installed as WordPress
roles by `class-roles.php` (slug pattern `lps_<role>`, e.g. `lps_section_editor`). Actions are
custom primitive capabilities named `lps_<action>` (e.g. `lps_publish`).

## Prerequisites

- An individual named account with exactly one policy role.
- Collection assignments recorded by an administrator (`_lps_assigned_collections`) for
  `contributor`, `translator` and `section-editor`; other roles are not collection-scoped.
- Teaching scope grants recorded by an administrator or a section editor assigned the `teaching`
  collection (`_lps_teaching_grants`) for `professor` and `delegate`; scoped roles hold no
  collection rights at all.
- An enabled Two-Factor provider for `publisher`, `administrator` and `professor` — every role
  holding public publishing authority.
- The routine gate commands available locally: `npm run test` and `tools/composer test`.

## Authoritative action matrix

Actions are exactly those granted by `SecurityPolicy::ACTIONS`. This table is machine-checked against
that source by `node tests/docs/docs-checker.mjs`; a claim the policy denies fails the gate.

| Role | Allowed actions (policy) | Collection-scoped | MFA required |
| --- | --- | --- | --- |
| contributor | `create`, `edit`, `submit` | yes | no |
| translator | `edit`, `submit` | yes | no |
| section-editor | `create`, `edit`, `submit`, `review`, `archive`, `grant-scope`, `revoke-scope` | yes | no |
| publisher | `create`, `edit`, `submit`, `review`, `publish`, `unpublish`, `archive`, `redirect` | no | yes |
| administrator | `create`, `edit`, `submit`, `review`, `publish`, `unpublish`, `archive`, `import`, `redirect`, `settings`, `audit`, `dormant-report`, `grant-scope`, `revoke-scope` | no | yes |
| privacy-auditor | `review`, `audit`, `dormant-report` | no | no |
| deployer | `deploy` | no | no |
| professor | `create`, `edit`, `submit`, `publish`, `copy-forward` | offering-scoped | yes |
| delegate | `create`, `edit`, `submit` | offering-scoped | no |

Audited actions retained in the immutable ledger (`class-audit.php`): `create`, `edit`, `submit`,
`review`, `publish`, `unpublish`, `archive`, `import`, `redirect`, `settings`, `grant-scope`,
`revoke-scope`.

## Offering-scoped roles

`professor` and `delegate` are governed by `class-teachingpolicy.php`, not by collection
assignment. Every scoped action requires a persisted grant on the account (`_lps_teaching_grants`)
covering the exact scope: one offering record, or the `news` scope for designated faculty news
editors. Scope resolves only from server-side state — the grant list and canonical relationships —
never from user-controlled owner, person, offering, or relation IDs. Revocation and expiry are
evaluated on every request, so a revoked or expired grant denies immediately.

Scoped roles may touch only `lps_offering`, `lps_unit`, `lps_resource` and `lps_news`. Professors
publish cleared materials (`lps_unit`, `lps_resource`) and scoped news; delegates prepare drafts
and never publish or write release fields. A record a delegate creates inside a granted scope
carries the `_lps_prepared_by` marker — a system-written provenance field no scoped role may
write — so the professor's dashboard shows the draft with a prepared-by badge, and the professor
edits and submits it under her own authority while the marker stays as provenance. Offering publication, course and term records,
teaching-team membership, owner fields, review states, scan and storage fields stay with
institutional editors — a scoped role writing them is denied before any mutation. Professors may
copy forward an assigned offering (`copy-forward`); the operation creates drafts only.

## Professor

Routine work: maintain assigned offerings, units and materials; publish cleared teaching files;
send news through the scoped lane when designated.

1. Accept the offering scope grant recorded by an administrator or teaching section editor.
2. Edit the offering's schedule, venue and syllabus snapshot; create and order units; attach
   resources and set release state once rights, accessibility and scan reviews are approved.
3. Publish cleared materials inside the granted offering; copy the offering forward to a new
   term/section as a draft with the required resets — the reviewed team is supplied explicitly,
   sensitive and term-bound fields are reset, and only explicitly selected public versions are
   reused. Propagate allowlisted corrections (schedule, venue, syllabus snapshot, LMS link,
   cancellation) to explicitly selected offerings of the same course; every target keeps its own
   revision history.
4. Publish news directly only when the account also holds the `news` scope grant.

Boundaries: MFA is mandatory because the role publishes publicly. You cannot publish offerings,
courses or terms, edit other offerings, change the teaching team, owner, review, scan or storage
fields, manage grants, or touch any non-teaching collection. An attempt is denied without any data
change.

## Delegate

Routine work: prepare drafts inside granted offerings for a professor.

1. Accept the delegate scope grant recorded by an administrator or teaching section editor.
2. Create and edit draft units, resources and offering descriptive fields inside the granted
   offering. Every draft you create keeps the `_lps_prepared_by` marker with your account.
3. Submit drafts for the professor or an editor to review. The professor sees your drafts marked
   "prepared by" with your name, edits them and submits or publishes them under her own
   authority; your marker stays as provenance.

Boundaries: you cannot publish anything, write release or withdrawal fields, copy offerings
forward, manage grants, or touch records outside the granted offering. An attempt is denied
without any data change.

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

## Faculty task dashboard

The dashboard (`/pt-br/painel/`, `/en/dashboard/`) is the simplified authenticated surface for
faculty work. It renders server-side only — no JavaScript, no layout editing — and every form
posts to `admin-post.php` handlers that re-check the persisted grant on the server. The task list
is derived from the account's grants and role, so a professor sees only the tasks their scope
covers and a delegate never sees publish controls.

| Task | Who | What it does |
| --- | --- | --- |
| My profile | professor, delegate | Propose changes to the linked person record (bio, public e-mail, ORCID, Lattes, Scholar, website). A proposal is stored pending; an editor approves or rejects it before the public record changes. |
| My offerings | professor, delegate | Open the assigned offering workspace: create units, attach materials, upload and select file versions, release and publish cleared materials, copy the offering forward. |
| Submit news | professor (news scope) | Draft a news item and send it to review; a rejected item returns with the reviewer's note and can be edited and resubmitted. |
| Review queue | section editor, publisher | Approve or reject pending news and profile proposals; a rejection always requires a note. |
| Create offering | section editor, publisher | Create a new offering draft on a published course and term with a reviewed teaching team. |

Recoverable validation: a denied submit redirects back to the same form with the field named and
the entered values recalled, so nothing is lost. The dashboard sends `Cache-Control: private,
no-store` and `X-Robots-Tag: noindex, nofollow` on every view.

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
  fixtures in `wp-content/plugins/lps-content-model/tests/SecurityContractsTest.php`, plus the
  offering-scope grant, expiry, field-allowlist and denial contracts in
  `LpsRedesignTask04Test.php`.
- `npm run qa:governance` prints the role/collection matrix used by governance.
- `npm run qa:security` runs the passive security lane.
- Per-role end-to-end acceptance simulations are specified in
  [role acceptance simulations](role-acceptance-simulations.md) and are **PENDING** a live CMS.
