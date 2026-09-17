# Editorial governance

This policy governs publication of the public LPS site. It allocates responsibilities by role, not
by named person. No current assignee, approval, legal basis, right, consent, public contact route,
or accessibility contact is asserted here. Those missing facts are launch blockers, not defaults
that an editor may fill by inference.

The executable role/collection contract is
`tests/fixtures/governance/role-collection-matrix.json`. `npm run qa:governance` validates that
contract and prints its complete matrix. It does not make the unresolved blockers acceptable for a
production launch.

## Roles and account boundary

Every user has an individual account. Shared, borrowed, generic, or role-only accounts are
forbidden. Revisions and workflow actions must retain the individual actor, time, action, and
revision reference. Publisher and administrator accounts require MFA before use.

| Role | May do | Must not do |
| --- | --- | --- |
| Contributor | Create and edit own draft material; attach recorded source/provenance; submit it for review. | Publish, self-approve, change roles/settings, delete records, or bypass privacy/accessibility gates. |
| Translator | Create or update localized editorial fields; identify a translation as ready for review. | Change shared identifiers, relations, canonical dates, or source records; publish or review own translation. |
| Section editor | Own the assigned collections; assess sources, freshness, completeness, accessibility, translation review, archive and correction requests; submit a reviewed revision to a publisher. | Publish own work without a distinct publisher; administer accounts/infrastructure or overrule unresolved privacy/rights evidence. |
| Publisher | Publish, unpublish, restore, or schedule a reviewed revision; make the publication decision attributable and reversible. | Treat an unresolved source, privacy, rights, legal-basis, or required review issue as approved; use a shared account. |
| Administrator | Provision individual accounts and configured settings; preserve audit/revision availability and enforce least privilege. | Use administrative access to bypass editorial, privacy, or publication gates; represent an unnamed operational contact as confirmed. |
| Privacy auditor | Review public personal-data, photo, media-rights, and public-contact evidence; record a block or escalation. | Invent a legal basis, rights, or consent; publish content or deploy infrastructure. |
| Deployer | Deploy approved, versioned artifacts; retain deployment and rollback evidence. | Edit public content, alter editorial approvals, or use deployment access as CMS editorial access. |

## Minimum staffing and launch boundary

Before accounts are provisioned, LPS must name at least two publishers, one administrator,
collection owners, English reviewers, an accessibility contact, and a privacy contact. The machine
contract also requires at least one contributor, section editor, translator, privacy auditor, and
deployer so that every defined responsibility has an accountable individual role. The publisher must be a
different person from the contributor whose work is being published. A translation reviewer must
be independent of its translator.

Production launch is blocked until these assignments are documented, MFA is enrolled for each
publisher and administrator, and each collection has a current owner assignment. The current
machine contract deliberately reports unresolved names and legal bases as blockers; it is not a
roster and must not be read as one.

## Collection control

Each collection has a role owner, ordered source priorities, review period, expiry handling,
translation obligation/reviewer, archive rule, and correction/takedown route in the executable
matrix. An owner assignment is a future named assignment of that role, not a claim that a person
has already accepted it. The required collection set is site settings, pages, people,
organizations, research areas, projects, publications, opportunities, news, events, media assets,
and redirects.

A record remains draft when any required source, review, translation, accessibility, privacy, or
rights evidence is absent. Unknown facts remain null. A review date does not certify a fact that
has no source.

The same rule governs sparse public surfaces: an optional homepage module with no reviewed records
is omitted rather than filled, the required mission and contact notices stay visible and compact,
and an unpublished journey destination renders as a disabled action instead of a link. No surface
may invent content, links, or contacts to appear complete.

## Public personal data and media

Publish a personal email address only when documentary evidence identifies it as an intended public
contact. Do not infer that a work address, a legacy page, or a directory listing authorizes a new
public use. Until an owned role address or approved public contact is confirmed, do not advertise a
contact route that does not exist.

Publish a person photo only when the record contains documentary evidence for the public use,
rights holder/license or other rights basis, provenance, and the applicable privacy review. A photo
found online, in a legacy file, or on a third-party profile is not evidence by itself. The privacy
auditor records a block or escalation; this policy does not supply a legal basis or claim consent.

Institutional marks are media under the same rule. The header wordmark stays text-only; a logo or
mark may render only after an authorized owner supplies the files, usage rules, and written
permission, cited in the redesign evidence. `lps_logo_vector.svg` is unapproved and must not ship.

Public media must also meet the applicable alternative-text, caption, transcript, and contextual
requirements. Rights-unknown, privacy-unreviewed, or inaccessible media remains non-public.

## Launch blockers

The site must not launch while any of the following remains unresolved:

- named role holders, collection owners, English reviewers, privacy contact, or accessibility contact;
- an owned public correction/takedown route;
- documented legal bases for any proposed public personal-data processing;
- required source, rights, privacy, accessibility, translation, review, or expiry evidence;
- an essential service available only through an inaccessible legacy PDF;
- shared accounts, contributor self-publishing, missing publisher/administrator MFA, or missing
  attributable audit history.

New facts, legal conclusions, rights evidence, approvals, and names are external inputs. They must
be recorded from their documentary source before publication rather than added to satisfy this
policy.
