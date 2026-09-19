# Content model and data dictionary

Authoritative source: `wp-content/plugins/lps-content-model/includes/class-contracts.php`
(records, fields, sanitizers, REST exposure), `class-teachingcontracts.php` (teaching entities,
field ownership, normalization, uniqueness identities, temporal state, copy-forward),
`class-teachingmigrations.php` (indexed uniqueness registries and additive schema migrations),
`class-importcontracts.php` (provenance fields),
`class-relationships.php` (relationship storage and reverse lookups),
`class-taxonomies.php` (controlled vocabularies) and `class-policy.php` (state machine). This
document describes what those files implement; when they change, this document is reported stale by
`node tests/docs/docs-checker.mjs`.

Field names are WordPress post-meta keys. Two keys are never exposed through REST:
`_lps_owner_user_id` and `_lps_translation_reviewer_id`.

## Collections

The governance collections, their owner roles and review cadences are the executable matrix
`tests/fixtures/governance/role-collection-matrix.json` (printed by `npm run qa:governance`):

| Collection | Post type | Owner role | Review cadence |
| --- | --- | --- | --- |
| `site-settings` | option `lps_site_settings` | administrator | `P90D` |
| `page` | `page` | section-editor | `P180D` |
| `person` | `lps_person` | section-editor | `P90D` |
| `organization` | `lps_organization` | section-editor | `P180D` |
| `research-area` | `lps_research_area` | section-editor | `P180D` |
| `project` | `lps_project` | section-editor | `P90D` |
| `publication` | `lps_publication` | section-editor | `P365D` |
| `opportunity` | `lps_opportunity` | section-editor | `P30D` |
| `news` | `lps_news` | section-editor | `P365D` |
| `event` | `lps_event` | section-editor | `P30D` |
| `media-asset` | attachment | section-editor | `P365D` |
| `redirect` | `lps_redirect` | publisher | `P365D` |
| `teaching` | `lps_course`, `lps_term`, `lps_offering`, `lps_unit`, `lps_resource` | section-editor | `P180D` |

`Roles::collection_for_post_type()` maps a post type to its collection key; per-account collection
assignment is stored in the user meta `_lps_assigned_collections`.

## Editorial state machine

`_lps_state` moves only along the transitions implemented in `class-policy.php`:

| From | To |
| --- | --- |
| `draft` | `draft`, `in_review`, `published`, `archived` |
| `in_review` | `draft`, `in_review`, `published`, `archived` |
| `published` | `in_review`, `published`, `archived` |
| `archived` | `archived` |

Archived is terminal: a referenced record is archived, never hard-deleted.

## Fields present on every record

| Field | Type | Meaning |
| --- | --- | --- |
| `_lps_record_id` | record id | Immutable internal record identifier. |
| `_lps_origin` | origin | Provenance claim: `native` (authored in the CMS) or `imported` (written by the import boundary). Resolved against real provenance fields; a record with neither is `ambiguous` and withheld from public surfaces until reconciled. |
| `_lps_locale` | locale | Record locale (`pt-br` authoritative, `en` reviewed variant). |
| `_lps_state` | state | Editorial state (see the state machine). |
| `_lps_owner_user_id` | integer, private | Accountable owner account. |
| `_lps_review_date` | date | Next review date driven by the collection cadence. |
| `_lps_created_at`, `_lps_updated_at`, `_lps_archived_at` | datetime | Lifecycle timestamps. |
| `_lps_published_slug` | slug | Immutable first published slug. |
| `_lps_claim_verified`, `_lps_claim_source_url`, `_lps_claim_reviewed_at` | boolean, url, date | Institutional claim verification and its source. |
| `_lps_source_revision`, `_lps_source_hash` | integer, text | Current authoritative Portuguese revision and its hash. |
| `_lps_reviewed_source_hash` | text | Last reviewer-approved Portuguese hash; drives stale-translation detection. |
| `_lps_translation_reviewed_at` | datetime | Translation review timestamp. |
| `_lps_translation_reviewer_id` | integer, private | Independent translation reviewer. |

## Migration provenance fields (every imported record)

These fields are written only by the import boundary and are read-only in the
editor. Their presence resolves `_lps_origin` to `imported` regardless of any
stored claim, so an unreviewed import can never be marked native.

| Field | Meaning |
| --- | --- |
| `_lps_import_source_id` | Migration source identifier from `content/inventory/records.csv`. |
| `_lps_import_source_url` | Legacy source URL. |
| `_lps_import_captured_at` | Capture timestamp of the source snapshot. |
| `_lps_import_checksum` | Source checksum used for change detection. |
| `_lps_import_rights` | Rights state recorded at import. |
| `_lps_import_review_state` | Review state recorded at import. |
| `_lps_import_fingerprint` | Normalized fingerprint for duplicate detection. |
| `_lps_import_reviewed_fields` | Fields protected from silent import overwrite. |
| `_lps_crossref_fields`, `_lps_crossref_cache_key`, `_lps_crossref_cached_at` | Fields derived from the deterministic Crossref cache and its identity. |

## Site settings

Single typed option `lps_site_settings` (`Contracts::site_settings_schema()`):

| Setting | Type | Notes |
| --- | --- | --- |
| `official_name` | string | Official laboratory name. |
| `acronym` | string | `LPS`. |
| `parent_ufrj` | string | UFRJ parent institution. |
| `parent_coppe` | string | COPPE parent institution. |
| `founded_year` | integer | Founding year. |
| `address` | string | Public postal address. |
| `public_contact` | email | Public role contact; never a personal address. |
| `timezone` | string | Site timezone. |
| `official_website` | url | Canonical institutional URL. |
| `orcid_organization` | string | Organisation identifier where applicable. |
| `logo_id` | integer | Attachment ID of the approved logo. |
| `privacy_contact` | email | Privacy contact role address. |
| `accessibility_contact` | email | Accessibility/barrier reporting role address. |
| `title_pt_br`, `title_en` | string | Localized site titles. |
| `tagline_pt_br`, `tagline_en` | string | Localized taglines. |
| `footer_pt_br`, `footer_en` | string | Localized footer statements. |

`public_contact`, `privacy_contact` and `accessibility_contact` are unresolved launch blockers: no
owned public route has been confirmed, so they must not be filled with a personal address.

## Page

`_lps_page_key`, `_lps_primary_audience`, `_lps_canonical_task`, `_lps_affiliation`,
`_lps_governance`, `_lps_location`, `_lps_funding`, `_lps_report_contact`, `_lps_claims`,
`_lps_role_contacts`, `_lps_journeys`.

## Person

`_lps_canonical_name`, `_lps_sort_name`, `_lps_person_status`, `_lps_roles`, `_lps_affiliations`,
`_lps_start_date`, `_lps_end_date`, `_lps_public_email`, `_lps_orcid`, `_lps_lattes_url`,
`_lps_scholar_url`, `_lps_website_url`, `_lps_research_area_ids`, `_lps_credentials`,
`_lps_photo_rights`, `_lps_privacy_reviewed`.

`_lps_public_email` requires documentary evidence that the address is an intended public contact;
`_lps_photo_rights` plus `_lps_privacy_reviewed` gate any published photograph.

## Organization

`_lps_organization_name`, `_lps_acronym`, `_lps_organization_kind`, `_lps_country_code`,
`_lps_canonical_url`, `_lps_ror_id`, `_lps_logo_asset_id`, `_lps_public_profile`.

A profile is public only when `_lps_public_profile` is true.

## Research area

`_lps_stable_key` (language-neutral, never translated), `_lps_sort_order`, `_lps_label`,
`_lps_localized_slug`, `_lps_synonyms`.

## Project

`_lps_project_status`, `_lps_start_date`, `_lps_end_date`, `_lps_member_ids`, `_lps_funder_ids`,
`_lps_partner_ids`, `_lps_grant_ids`, `_lps_research_area_ids`, `_lps_application_domains`,
`_lps_asset_ids`, `_lps_links`.

## Publication

`_lps_publication_type`, `_lps_publication_status`, `_lps_authoritative_title`, `_lps_language`,
`_lps_publication_date`, `_lps_date_precision`, `_lps_doi`, `_lps_isbn`, `_lps_issn`,
`_lps_arxiv_id`, `_lps_venue`, `_lps_citation`, `_lps_author_ids`, `_lps_license`,
`_lps_canonical_url`, `_lps_open_access_url`, `_lps_pdf_url`, `_lps_code_url`, `_lps_data_url`,
`_lps_project_ids`, `_lps_research_area_ids`.

Author order is authoritative and preserved; a duplicate DOI is rejected with `lps_duplicate_doi`.

## Opportunity

`_lps_opportunity_type`, `_lps_audiences`, `_lps_opens_at`, `_lps_closes_at`, `_lps_positions`,
`_lps_stipend`, `_lps_project_ids`, `_lps_supervisor_ids`, `_lps_funder_ids`, `_lps_location`,
`_lps_mode`, `_lps_contact`, `_lps_contact_is_role`, `_lps_application_url`,
`_lps_application_url_approved`, `_lps_eligibility`, `_lps_application_instructions`.

Public state is derived from `_lps_opens_at` and `_lps_closes_at`; a closed opportunity keeps a
stable page and becomes noindex after 90 days.

## News and Event

News: `_lps_canonical_date`, `_lps_news_status`, `_lps_related_record_ids`, `_lps_featured_until`.

Dashboard review fields (private, never exposed through REST): `_lps_review_comments` carries the
reviewer's note on a rejected news item or proposal so the author sees the required fix;
`_lps_profile_proposals` stores the pending profile-change rows on a person record
(`id`, `fields`, `state`, `note`, `submitted_at`, `reviewed_at`, `reviewer_id`) until an editor
approves or rejects them.

Event: `_lps_starts_at`, `_lps_ends_at`, `_lps_event_status`, `_lps_related_record_ids`,
`_lps_speaker_ids`, `_lps_organizer_ids`, `_lps_venue`, `_lps_online_url`, `_lps_registration_url`,
`_lps_recording_url`.

## Redirect

`_lps_redirect_source` (unique normalized path), `_lps_redirect_target`, `_lps_redirect_gone`
(HTTP 410), `_lps_redirect_status`, `_lps_redirect_reason`, `_lps_redirect_provenance`,
`_lps_verified_at`. Verify the graph with `wp lps redirects verify`.

## Teaching entities

The five teaching record types are registered by explicit `register_post_type()` calls in
`TeachingContracts::register()`. Field ownership is declared per key: `shared` fields are owned by
the authoritative Portuguese record (English variants cannot write them), `localized` fields are
per-variant editorial text, and `system` fields are boundary-written or derived. `_lps_version_id`,
`_lps_storage_key` and `_lps_uploader_user_id` are never exposed through REST.

### Course (`lps_course`, public)

`_lps_course_code` (uppercase ASCII official code), `_lps_course_level`
(`undergraduate`/`graduate`/`extension`), `_lps_calendar_key`, `_lps_program`,
`_lps_catalog_source_url` (authoritative catalog source for the code/level/program, shared);
`_lps_prerequisites`, `_lps_syllabus` (localized). A published offering keeps its own syllabus
snapshot, so later edits to the default syllabus do not rewrite history. Imported course
records without `_lps_catalog_source_url` and imported term records without `_lps_term_source`
are quarantined at the import boundary (`lps_import_course_source_required`).

### Academic term (`lps_term`, internal)

`_lps_calendar_key`, `_lps_term_code`, `_lps_period_label`, `_lps_period_type`
(`semester`/`trimester`/`quarter`/`annual`/`intensive`), `_lps_starts_on`, `_lps_ends_on` (shared);
`_lps_term_token` (system, immutable calendar-qualified route token `{code}-{calendar}`);
`_lps_term_source` (shared). Term boundaries are stored in the institutional timezone
(`America/Sao_Paulo` by default). `starts_on > ends_on` is rejected.

### Course offering (`lps_offering`, public)

`_lps_section_key` (normalized: folded, lowercased, hyphenated ASCII), `_lps_schedule`, `_lps_venue`,
`_lps_lms_url` + `_lps_lms_url_approved`, `_lps_cancelled` (shared); `_lps_syllabus_snapshot`
(localized); `_lps_temporal_status` (system, derived: `upcoming`/`current`/`completed`/`cancelled`);
`_lps_copy_operation_id`, `_lps_copy_source_offering_id` (system, copy-forward provenance).
Temporal status is separate from the editorial `_lps_state` machine: a completed offering stays
published and searchable, and end-of-term never archives a record. Co-teaching is many-to-many
through the `teaching_team` relationship (`lead`, `co-teacher`, `assistant`).

Published courses, offerings and released resources are indexed in the locale search index:
course codes, titles, instructors, term labels and authored resource languages are searchable,
while `_lps_storage_key`, `_lps_version_id`, `_lps_uploader_user_id` and every other private field
stay out. The offering `status` search facet exposes only `current`/`previous`, derived from term
boundaries at query time, and a `scheduled` resource becomes searchable when its `_lps_release_at`
passes — no scheduler run is required. Terms and units are internal and never indexed.

### Teaching unit (`lps_unit`, internal)

`_lps_anchor` (stable anchor), `_lps_position` (ordered, ≥ 1), `_lps_topic_date` (shared).
`unit_offering` is exactly-one: a unit cannot reference another offering.

### Teaching resource (`lps_resource`, internal)

`_lps_resource_type`, `_lps_resource_language` (authored language), `_lps_external_url`,
`_lps_sha256`, `_lps_byte_size`, `_lps_mime_type`, `_lps_scan_state`, `_lps_scan_version`,
`_lps_release_state` (`draft`/`scheduled`/`released`/`withdrawn`), `_lps_release_at`,
`_lps_withdrawn_at`, `_lps_rights_review`, `_lps_accessibility_review` (shared); `_lps_version_id`,
`_lps_storage_key`, `_lps_uploader_user_id` (system, private). A resource is one immutable local
version or one external URL, never both; a correction creates a new version. `resource_offering` is
exactly-one and `resource_unit` is at-most-one, and the unit must belong to the resource's offering.

### Uniqueness registries

`TeachingMigrations` owns three indexed registry tables: `{prefix}lps_term_registry` enforces
`(calendar_key, term_code)` plus the immutable `term_token`, and `{prefix}lps_offering_registry`
enforces `(authoritative course, authoritative term, normalized section key)` through a unique
`identity_hash`. Translated record IDs resolve to the Portuguese authority before hashing, so an
English variant cannot bypass uniqueness. Claims are released only on hard deletion.
`{prefix}lps_resource_version_registry` records every immutable resource version under its
`lpsver:<sha256>` identifier with a unique opaque `storage_key`, the content `sha256`, byte size,
MIME pair, state (`quarantined`/`cleared`/`infected`/`failed`), scan verdict and display names.
A version row is never mutated in place: only `state` and `scan_verdict` change through the scan
boundary, and a correction mints a new version row rather than editing an existing one.

### Copy-forward

`TeachingContracts::copy_forward_plan_error()` validates the operation contract: new IDs in draft,
a new term/section, explicit teaching-team review, resets for announcements, deadlines, release
times, active notices and unreleased/withdrawn resources, and reuse of already-public immutable
versions only after explicit selection. One operation completes with a manifest or leaves no
half-published offering; retrying the same `operation_id` must not duplicate it.

`TeachingCopy::copy_forward()` (`POST /lps/v1/teaching/offerings/{id}/copy-forward`) executes the
contract in one server-side operation: the new offering is created as a draft under the reviewed
team, units are cloned in `_lps_position` order with `_lps_topic_date` reset, and every resource
arrives as a draft stub — `_lps_release_state`, `_lps_release_at`, `_lps_withdrawn_at`, version
identity and review states reset — except that an explicitly selected `cleared`+`clean` version
referenced by an effectively released resource is reused verbatim, and an effectively released
external URL carries forward with its approved reviews. Sensitive and term-bound fields
(`_lps_lms_url`, `_lps_lms_url_approved`, `_lps_cancelled`, `_lps_temporal_status`) are reset; the
syllabus snapshot, schedule and venue copy forward. The completed manifest is persisted under
`lps_copy_op_{operation_id}` and stamped on the draft as `_lps_copy_operation_id`, so a retry
replays the same record instead of duplicating it; a mid-operation failure rolls the partial
graph back and releases the identity claim. Scoped grants apply: a professor copies only granted
offerings.

`TeachingCopy::propagate_correction()` (`POST /lps/v1/teaching/offerings/{id}/corrections`)
applies allowlisted field corrections (`_lps_schedule`, `_lps_venue`, `_lps_syllabus_snapshot`,
`_lps_lms_url`, `_lps_lms_url_approved`, `_lps_cancelled`) to an explicit
`affected_offering_ids` list restricted to the same authoritative course. Each target keeps its
own history: the correctable fields ride WordPress revisions, so every propagation writes one
revision per record, and the decision is audited as `edit` with the operation context. The
manifest persists under `lps_correction_op_{operation_id}` for idempotent replay; identity,
system and non-allowlisted fields are rejected (`lps_correction_field_forbidden`), as are
cross-course targets (`lps_correction_course_mismatch`).

### Offering-scoped authorization

`TeachingPolicy` (`class-teachingpolicy.php`) governs the scoped roles `professor` and `delegate`.
They hold no collection or global editing rights: every scoped action requires a persisted grant
record on the account in the `_lps_teaching_grants` user meta, covering the exact scope — one
offering record ID, or the `news` scope for designated faculty news editors. Grants carry
`scope`, `offering_id`, `role`, `granted_at`, `expires_at`, `revoked_at` and `granted_by`;
revocation and expiry are evaluated on every request, so they take effect immediately.

Administrators and section editors assigned the `teaching` collection grant and revoke scopes
(`grant-scope`, `revoke-scope`, both audited); no account may grant itself, and the grant role
must equal the target account's policy role. Scoped roles may touch only `lps_offering`,
`lps_unit`, `lps_resource` and `lps_news`; professors publish cleared `lps_unit`/`lps_resource`
materials and scoped news, while delegates prepare drafts and never publish or write release
fields. Offering publication, course and term records, teaching-team membership, owner, review,
scan and storage fields stay with institutional editors. Scope resolves only from persisted
grants and canonical relationships — user-controlled owner, person, offering or relation IDs
never create access. Person records are content, not accounts: public identity and teaching
history survive account deactivation.

## Media assets

Attachments carry credit, rights holder, license, source, checksum, focal point, dimensions or
duration, privacy review, transcript/caption status and accessible-document status
(`class-mediacontracts.php`, `class-mediapolicy.php`). Alternative text is per usage; a decorative
image must be explicitly marked decorative. Rights-unknown or essential inaccessible assets cannot
be published.

## Relationships

Relationships are stored as typed rows through `Relationships::replace()` with reverse lookups
(`reverse_for()`), plus dedicated helpers for ordered publication authorship
(`replace_authors()`) and controlled project application domains
(`replace_application_domains()`). Referenced records cannot be deleted while
`Relationships::is_referenced()` is true. Project publish gates require a project lead
(`lps_project_lead_required`). Teaching relationships are `offering_course`, `offering_term`,
`teaching_team`, `unit_offering`, `resource_offering` and `resource_unit`; offering publish gates
require a course, a term and a teaching lead, and a resource's unit must belong to its offering
(`lps_cross_offering_unit_reference`).

## Controlled vocabularies

`lps_research_area_key` (people, projects, publications): `instrumentation`, `signal-processing`,
`computational-intelligence`, `software-engineering`.

`lps_application_domain` (projects): `electrical-nuclear-energy`, `oil-and-gas`,
`high-energy-physics`, `defense`, `medicine`, `veterinary-science`, `data-quality`.

Both are seeded from `content/taxonomies/controlled-vocabularies.yaml`; the server-rendered
search facets are frozen in `content/taxonomies/search-facets.yaml` — `level` (courses),
`status`/`term`/`level`/`instructor` (offerings) and `type`/`language` (resources) join the
original nine. An unapproved term is rejected, not created.

## Validation

- `npm run qa:governance` prints the role/collection matrix and validates the contract.
- `npm run qa:ia` validates routes, vocabularies and facets.
- `npm run qa:content` reconciles the corpus in `content/corpus/` against the inventory.
- `tools/composer test` runs the PHP contract suites, including
  `wp-content/plugins/lps-content-model/tests/ContentContractsTest.php`.
