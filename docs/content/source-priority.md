# Source-of-truth and source-priority policy

Portuguese is the authoritative editorial language. This does not permit an untranslated or
unreviewed Portuguese record to stand in for required English material. A translation preserves the
approved shared facts and receives independent review before publication.

## Precedence

For each field, use the first applicable current, documented source below. The collection matrix
selects its applicable ordered subset. A source can establish only the fields it actually supports.

1. Current official LPS record for LPS-owned facts.
2. Current official UFRJ/COPPE record for institutional affiliation, official services, or facts it
   owns.
3. The authoritative identifier registry or official publisher for identifiers and bibliographic
   metadata, including DOI/ISBN/ISSN/ORCID where applicable.
4. Current official record of the relevant organization, funder, partner, organizer, or rights
   holder for facts that organization owns.
5. A verified primary source with clear provenance for scoped factual support.
6. Migration inventory and verified legacy material only as discovery/provenance evidence. It is not
   sufficient authority for a current claim, public email, photo use, rights, consent, legal basis,
   or publication approval.

The executable matrix uses these controlled source labels: `official-lps-record`,
`official-ufrj-coppe-record`, `authoritative-identifier-registry`, `official-publisher-record`,
`official-organization-record`, `official-funder-or-partner-record`, `official-organizer-record`,
`rights-holder-record`, `verified-primary-source`, and `migration-inventory-record`.

## Conflict and uncertainty

Capture source URL or identifier, source owner, retrieval or verification date, and the precise
field supported. A section editor compares conflicting evidence using the applicable precedence.
Where sources have equal authority, or the owner/scope is uncertain, do not merge a convenient
version: retain the conflict, request documentary clarification, and keep the affected claim draft
or annotate it as unresolved. A publisher may publish only the reviewed resolution, not an inferred
one.

Never manufacture a source from an unsourced assertion. Do not turn a discovered email, photograph,
legacy PDF, or stale profile into evidence of permission or current truth. Legal bases, rights, and
consent require their own documentary evidence; this policy neither selects nor approves them.

The import boundary enforces this mechanically: records declaring `synthetic` fixture data or a
fixture provenance path are rejected (`lps_import_synthetic_record`); records sourced from the
retired `lps.ufrj.br` site or `web.archive.org`/`archive.org` are rejected as active content
(`lps_import_legacy_scrape_source`) — those hosts remain legitimate only as archive-only
provenance and redirect evidence; imported `lps_course` records without `_lps_catalog_source_url`
and `lps_term` records without `_lps_term_source` quarantine for an authoritative catalog or
calendar source (`lps_import_course_source_required`); and a verified claim without its source URL
and review date quarantines (`lps_import_claim_unsourced`).

## Corrections, retirement, and provenance

Corrections cite the superseding source and retain the prior revision trail. Takedown assessment
uses the correction/takedown workflow and does not erase provenance or a referenced record merely
to hide a dispute. A retirement decision records whether the public URL is retained, redirected
once, deliberately returns 410, or becomes private/excluded. Legacy sources remain attached as
provenance and discovery context, not promoted above current authoritative records.
