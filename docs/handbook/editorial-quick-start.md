# Editorial quick start

Read this once before your first publication. It covers what every editorial account must know,
regardless of role. Your role's exact limits are in [role workflows](role-workflows.md); the binding
policy is [editorial governance](../content/governance.md).

## Prerequisites

- An individual named account. Shared, borrowed or role-named accounts are rejected at the account
  boundary (`lps_shared_account_forbidden`).
- For a publisher, professor or administrator account: an enabled Two-Factor provider. Without it,
  only read and profile access remain — publishing, uploads, deletion, user management and settings
  stay locked. Every role that can publish publicly meets the same MFA contract.
- Your collection assignments. A contributor, translator or section editor can only see and edit the
  collections assigned to their account (`_lps_assigned_collections`); an unassigned collection is
  invisible, not merely read-only.
- For professor and delegate accounts: your teaching scope grants (`_lps_teaching_grants`), recorded
  by an administrator or a section editor assigned the `teaching` collection. A scoped account can
  only act inside its granted offerings — or the `news` scope for designated faculty news editors —
  and a revoked or expired grant denies immediately.
- The source for what you are about to publish: a current official record, an authoritative
  identifier registry entry, or another documented primary source. See
  [source priority](../content/source-priority.md).

Until named role holders, an owned public correction route, an accessibility contact and documented
legal bases exist, publication of personal data and public contact routes stays blocked. Those are
launch blockers in `tests/fixtures/governance/role-collection-matrix.json`, not paperwork.

## The five things the system will not let you do

1. Publish your own draft as a contributor, or review your own translation as a translator.
2. Publish a record whose required source, rights, privacy, accessibility, translation or review
   evidence is missing — it stays `draft`.
3. Publish an English variant whose Portuguese source changed after the last translation review; the
   variant is stale until an independent reviewer clears it.
4. Publish a photograph or personal e-mail without recorded rights, provenance and privacy review.
5. Delete a referenced record. Archive it; `archived` is terminal and the history stays attributable.

## Your first record, end to end

1. **Draft.** Create the record in its collection. Fill the identity fields, then the provenance
   fields: `_lps_import_source_url` or `_lps_claim_source_url`, `_lps_claim_reviewed_at`, the owner
   and the next `_lps_review_date`. Unknown facts stay empty — never a guess, never a placeholder.
2. **Relate.** Attach the real relationships (research areas, projects, people, funders) instead of
   retyping lists. Reverse relationships are derived automatically.
3. **Media.** Add media only with credit, rights holder, license and privacy review recorded, plus
   per-usage alternative text or an explicit decorative mark. An essential PDF needs an accessible
   HTML equivalent. See [the accessibility authoring checklist](accessibility-authoring-checklist.md).
4. **Translate.** Required bilingual material gets an English variant from a translator; localized
   editorial fields only. See [translation and freshness](translation-freshness.md).
5. **Submit for review.** State moves `draft` → `in_review`. The section editor checks source
   priority, factual scope, freshness, relationships, accessibility and translation review.
6. **Publish.** A distinct publisher with MFA publishes. The action is attributable and reversible;
   the first published slug becomes immutable and any later change produces a one-hop redirect.
7. **Review on cadence.** Your collection's cadence (`P30D` to `P365D`) is the maximum interval, not
   a grace period. Any change of source, status, deadline, contact, privacy state, rights or
   accessibility state requires review now.

## States you will see

`draft` → `in_review` → `published` → `archived`. `published` can return to `in_review`;
`archived` is terminal. Derived public states — an opportunity open or closed by date, an event
cancelled or postponed — are computed from the record's dates and status, never typed into the body
text.

## Corrections and takedowns

Log the claimed issue, the affected record or asset, the source supplied, the time received and the
disposition. A factual correction is triaged by the section editor against source priority and
released by a publisher with the revision trail intact. A takedown, privacy, rights or
public-contact request goes to the publisher and the privacy auditor, who may restrict or unpublish
while evidence is reviewed. Never silently alter provenance and never hard-delete a referenced
record. Full process: [editorial workflow](../content/editorial-workflow.md).

## Checks you can run yourself

| Command | Answers |
| --- | --- |
| `npm run qa:governance` | Does every collection still have an owner role, cadence, translation obligation, archive rule and correction path? |
| `npm run qa:content` | Does the corpus reconcile with the inventory, with no placeholder, orphan or missing locale pair? |
| `npm run qa:links` | Is every internal link and identifier still valid? |
| `npm run qa:ia` | Does a new route, label or facet respect the frozen architecture? |
| `npm run qa:a11y` | Do the public routes still pass the accessibility gate? |

If a check fails, read [troubleshooting](../operations/troubleshooting.md) before changing content.
