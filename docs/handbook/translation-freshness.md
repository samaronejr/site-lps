# Translation and freshness guide

Portuguese (`pt-br`) is the authoritative editorial language; English (`en`) is a separately
reviewed locale, never a fallback and never machine-published. The implementation is
`wp-content/plugins/lps-content-model/includes/class-translationpolicy.php`; Polylang provides only
the locale association and routing.

## Prerequisites

- A `translator` role account with the collection assigned, or a `section-editor` account for review.
- The Portuguese record already reviewed: its `_lps_source_hash` is the hash you are translating
  against.
- The reviewer must be a different person from the translator (`independentTranslationReviewRequired`
  in `tests/fixtures/governance/role-collection-matrix.json`).

## What a translator may write

| Writable in `en` | Never writable by a translator |
| --- | --- |
| `post_title`, `post_excerpt`, `post_content` | Any field in the `pt-br` locale |
| `_lps_label`, `_lps_synonyms` | Shared identifiers (`_lps_record_id`, `_lps_doi`, `_lps_orcid`, …) |
| `_lps_eligibility`, `_lps_application_instructions` | Canonical dates, relations, source records, states |

An attempt to write a shared field from a translation returns the policy error rather than silently
saving.

## Required English coverage

English is required for Home, About, Research, Projects, People, Publications, Infrastructure,
Opportunities, Collaboration, Contact and the privacy/accessibility notices, and for the public
teaching records `lps_course` and `lps_offering`. News and events are Portuguese-first with optional
reviewed English summaries; terms, units and resources keep their authored language and are not
translated record pairs. A required locale pair publishes atomically: an Opportunity change that
alters material English content cannot publish half-updated. `hreflang` alternates are emitted only
for published pairs.

Teaching shared fields follow the same Portuguese-authority rule: section keys, schedules, term
boundaries, release states and file/version identifiers are owned by the authoritative record, so an
English variant can never write them or bypass offering uniqueness. The material sets that must
synchronize atomically are `_lps_starts_on`/`_lps_ends_on` (term), `_lps_cancelled`/`_lps_schedule`
(offering) and `_lps_release_state`/`_lps_release_at`/`_lps_withdrawn_at`/`_lps_version_id`/
`_lps_external_url` (resource).

## How staleness is detected

Freshness is content-derived, not date-derived, and it is field-specific:

1. `TranslationPolicy::source_hash()` hashes the authoritative Portuguese material fields. For the
   teaching record types the hash covers exactly the localized (per-variant editorial) fields
   declared by `TeachingContracts::field_ownership()` — a shared schedule, term boundary, release
   state, or file/version identifier never forces a meaningless re-translation, and an
   authored-language resource never requires translated file bytes. For the pre-existing record
   types the hash keeps its established coverage so reviewed hashes already stored stay valid.
2. The value is stored as `_lps_source_hash`; the reviewer-approved value is
   `_lps_reviewed_source_hash`.
3. `TranslationPolicy::is_stale()` compares them. Different hashes mean the English variant no longer
   matches the source it was reviewed against, so it is `stale` and blocked from publication until an
   independent reviewer clears it and the reviewed hash is updated.
4. `_lps_translation_reviewed_at` and `_lps_translation_reviewer_id` record who cleared it and when.

The report ordering puts `stale` rows ahead of clean rows so a reviewer sees the blocked items first.

## Translating a record

1. Confirm the Portuguese record is `published` or reviewed and note its material fields.
2. Open the `en` variant and translate meaning, not words: keep identifiers, numbers, dates,
   institutional names and citation strings exactly as the authoritative record has them.
3. Never mix languages in one field, and never leave a Portuguese sentence inside an English variant
   as a stand-in. If material is untranslated, the variant stays unpublished.
4. Submit for independent review. The reviewing section editor checks factual equivalence, then
   clears the translation, which updates the reviewed hash.

## Freshness and expiry beyond translation

| Cadence | Collections |
| --- | --- |
| `P30D` | opportunity, event |
| `P90D` | site-settings, person, project |
| `P180D` | page, organization, research-area, teaching |
| `P365D` | publication, news, media-asset, redirect |

The cadence is the maximum ordinary interval. Review earlier whenever a source, status, deadline,
public contact, privacy state, rights state or accessibility state changes. Closed opportunities keep
a stable page and become `noindex after 90 days`. Cancelled and postponed events keep an explicit
status. News is dated material and is never silently refreshed as current information.

## Documentation freshness

The same principle applies to this handbook. `docs/documentation-contract.json` records, for each
documented step, the sources it describes and their SHA-256 hashes plus a review date and a maximum
age. `node tests/docs/docs-checker.mjs` reports:

- `stale-documentation` — a described source file changed since the documented review; the finding
  names the document, the step id and the changed path.
- `expired-documentation` — the documented review is older than its maximum age.

Repair means re-reading the source, correcting the document and then updating the recorded hash and
review date. Updating the hash without re-reading the source is falsifying a review.

## Verifying

- `tools/composer test` runs
  `wp-content/plugins/lps-content-model/tests/TranslationContractsTest.php`, covering writable
  fields, required pairs, stale detection and publish gating.
- `npm run qa:content` reports missing or incomplete required locale pairs in the corpus.
- `npm run qa:seo` proves reciprocal alternates exist only for published pairs.
- `node tests/docs/docs-checker.mjs` reports stale or expired documentation steps.
