<?php
/**
 * Presentation-free structured content contracts.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

require_once __DIR__ . '/class-importcontracts.php';
require_once __DIR__ . '/class-teachingcontracts.php';
require_once __DIR__ . '/class-publicationpolicy.php';

/**
 * Canonical record, metadata, and option schemas.
 *
 * This canonical typed schema data table is intentionally kept together.
 *
 * @phpstan-type TypeDefinition array{labels: array{name: string, singular_name: string}, public: bool, builtin: bool, show_in_rest: bool, rest_base: string, supports: list<string>}
 * @phpstan-type FieldDefinition array{type: string, single: bool, description: string, show_in_rest: bool, sanitize_callback: callable(mixed): mixed, auth_callback: callable(mixed, string, int, int): bool}
 * @phpstan-type PropertyDefinition array{type: string, minimum?: int, maximum?: int, format?: string}
 */
final class Contracts {
	// allow: SIZE_OK - canonical typed schema data table is intentionally kept together.
	public const VERSION     = '1.1.0';
	public const OPTION_NAME = 'lps_site_settings';

	/**
	 * Returns portable record type definitions.
	 *
	 * @return array<string, TypeDefinition>
	 */
	public static function post_types(): array {
		return array(
			'page'              => self::type( 'Pages', 'Page', 'pages', true, true ),
			'lps_person'        => self::type( 'People', 'Person', 'people' ),
			'lps_organization'  => self::type( 'Organizations', 'Organization', 'organizations' ),
			'lps_research_area' => self::type( 'Research Areas', 'Research Area', 'research-areas' ),
			'lps_project'       => self::type( 'Projects', 'Project', 'projects' ),
			'lps_publication'   => self::type( 'Publications', 'Publication', 'publications' ),
			'lps_news'          => self::type( 'News', 'News item', 'news' ),
			'lps_opportunity'   => self::type( 'Opportunities', 'Opportunity', 'opportunities' ),
			'lps_event'         => self::type( 'Events', 'Event', 'events' ),
			'lps_redirect'      => self::type( 'Redirects', 'Redirect', 'redirects', false ),
		) + TeachingContracts::post_types();
	}

	/**
	 * Returns metadata definitions keyed by record type and machine key.
	 *
	 * @return array<string, array<string, FieldDefinition>>
	 */
	public static function meta_fields(): array {
		$common = array(
			'_lps_record_id'               => self::field( 'string', 'Immutable internal record ID', 'record_id' ),
			'_lps_origin'                  => self::field( 'string', 'Record origin: native authoring or reviewed import', 'origin' ),
			'_lps_claim_verified'          => self::field( 'boolean', 'Institutional claim is verified', 'boolean' ),
			'_lps_claim_source_url'        => self::field( 'string', 'Institutional claim source URL', 'url' ),
			'_lps_claim_reviewed_at'       => self::field( 'string', 'Institutional claim review date', 'date' ),
			'_lps_locale'                  => self::field( 'string', 'Record locale', 'locale' ),
			'_lps_state'                   => self::field( 'string', 'Editorial state', 'state' ),
			'_lps_owner_user_id'           => self::field( 'integer', 'Accountable owner user ID', 'integer' ),
			'_lps_review_date'             => self::field( 'string', 'Next review date', 'date' ),
			'_lps_created_at'              => self::field( 'string', 'Record creation timestamp', 'datetime' ),
			'_lps_updated_at'              => self::field( 'string', 'Record update timestamp', 'datetime' ),
			'_lps_archived_at'             => self::field( 'string', 'Archive timestamp', 'datetime' ),
			'_lps_published_slug'          => self::field( 'string', 'Immutable first published slug', 'slug' ),
			'_lps_source_revision'         => self::field( 'integer', 'Current authoritative Portuguese revision', 'integer' ),
			'_lps_source_hash'             => self::field( 'string', 'Current authoritative Portuguese source hash', 'text' ),
			'_lps_reviewed_source_hash'    => self::field( 'string', 'Last reviewer-approved Portuguese source hash', 'text' ),
			'_lps_translation_reviewed_at' => self::field( 'string', 'Translation review timestamp', 'datetime' ),
			'_lps_translation_reviewer_id' => self::field( 'integer', 'Translation reviewer user ID', 'integer' ),
		);
		$common = array_merge( $common, ImportContracts::fields( array( self::class, 'field' ) ) );

		$specific = array(
			'page'              => array(
				'_lps_page_key'         => self::field( 'string', 'Stable page key', 'key' ),
				'_lps_primary_audience' => self::field( 'string', 'Primary audience', 'text' ),
				'_lps_canonical_task'   => self::field( 'string', 'Canonical task', 'text' ),
				'_lps_affiliation'      => self::field( 'string', 'Verified institutional affiliation', 'text' ),
				'_lps_governance'       => self::field( 'string', 'Governance statement', 'text' ),
				'_lps_location'         => self::field( 'string', 'Physical location', 'text' ),
				'_lps_funding'          => self::field( 'string', 'Funding and partner context', 'text' ),
				'_lps_report_contact'   => self::field( 'string', 'Barrier or privacy reporting address', 'email' ),
				'_lps_claims'           => self::field( 'string', 'Sourced institutional claims document', 'textarea' ),
				'_lps_role_contacts'    => self::field( 'string', 'Public role contacts document', 'textarea' ),
				'_lps_journeys'         => self::field( 'string', 'Collaboration handoff journeys document', 'textarea' ),
			),
			'lps_person'        => array(
				'_lps_canonical_name'    => self::field( 'string', 'Canonical name', 'text' ),
				'_lps_sort_name'         => self::field( 'string', 'Sort name', 'text' ),
				'_lps_person_status'     => self::field( 'string', 'Person status', 'key' ),
				'_lps_roles'             => self::field( 'array', 'Controlled roles', 'string_array' ),
				'_lps_affiliations'      => self::field( 'array', 'Affiliations', 'string_array' ),
				'_lps_start_date'        => self::field( 'string', 'Start date', 'date' ),
				'_lps_end_date'          => self::field( 'string', 'End date', 'date' ),
				'_lps_public_email'      => self::field( 'string', 'Approved public email', 'email' ),
				'_lps_orcid'             => self::field( 'string', 'ORCID', 'orcid' ),
				'_lps_lattes_url'        => self::field( 'string', 'Lattes URL', 'url' ),
				'_lps_scholar_url'       => self::field( 'string', 'Scholar URL', 'url' ),
				'_lps_website_url'       => self::field( 'string', 'Website URL', 'url' ),
				'_lps_research_area_ids' => self::field( 'array', 'Research area record IDs', 'id_array' ),
				'_lps_credentials'       => self::field( 'array', 'Credentials', 'string_array' ),
				'_lps_photo_rights'      => self::field( 'string', 'Photo rights state', 'key' ),
				'_lps_privacy_reviewed'  => self::field( 'boolean', 'Privacy review completed', 'boolean' ),
			),
			'lps_organization'  => array(
				'_lps_organization_name' => self::field( 'string', 'Official name', 'text' ),
				'_lps_acronym'           => self::field( 'string', 'Acronym', 'text' ),
				'_lps_organization_kind' => self::field( 'string', 'Organization kind', 'key' ),
				'_lps_country_code'      => self::field( 'string', 'ISO country code', 'country' ),
				'_lps_canonical_url'     => self::field( 'string', 'Canonical URL', 'url' ),
				'_lps_ror_id'            => self::field( 'string', 'ROR identifier', 'ror' ),
				'_lps_logo_asset_id'     => self::field( 'integer', 'Logo attachment ID', 'integer' ),
				'_lps_public_profile'    => self::field( 'boolean', 'Public profile enabled', 'boolean' ),
			),
			'lps_research_area' => array(
				'_lps_stable_key'     => self::field( 'string', 'Language-neutral key', 'key' ),
				'_lps_sort_order'     => self::field( 'integer', 'Sort order', 'integer' ),
				'_lps_label'          => self::field( 'string', 'Localized label', 'text' ),
				'_lps_localized_slug' => self::field( 'string', 'Localized slug', 'slug' ),
				'_lps_synonyms'       => self::field( 'array', 'Controlled synonyms', 'string_array' ),
			),
			'lps_project'       => array(
				'_lps_project_status'      => self::field( 'string', 'Project status', 'key' ),
				'_lps_start_date'          => self::field( 'string', 'Start date', 'date' ),
				'_lps_end_date'            => self::field( 'string', 'End date', 'date' ),
				'_lps_member_ids'          => self::field( 'array', 'Member record IDs', 'id_array' ),
				'_lps_funder_ids'          => self::field( 'array', 'Funder record IDs', 'id_array' ),
				'_lps_partner_ids'         => self::field( 'array', 'Partner record IDs', 'id_array' ),
				'_lps_grant_ids'           => self::field( 'array', 'Grant identifiers', 'string_array' ),
				'_lps_research_area_ids'   => self::field( 'array', 'Research area record IDs', 'id_array' ),
				'_lps_application_domains' => self::field( 'array', 'Application domain keys', 'string_array' ),
				'_lps_asset_ids'           => self::field( 'array', 'Media attachment IDs', 'integer_array' ),
				'_lps_links'               => self::field( 'array', 'Canonical project links', 'url_array' ),
			),
			'lps_publication'   => array(
				'_lps_publication_type'    => self::field( 'string', 'Publication type', 'key' ),
				'_lps_publication_status'  => self::field( 'string', 'Publication status', 'key' ),
				'_lps_authoritative_title' => self::field( 'string', 'Authoritative title', 'text' ),
				'_lps_language'            => self::field( 'string', 'Publication language', 'language' ),
				'_lps_publication_date'    => self::field( 'string', 'Publication date', 'date' ),
				'_lps_date_precision'      => self::field( 'string', 'Date precision', 'key' ),
				'_lps_doi'                 => self::field( 'string', 'Normalized DOI compatibility value', 'doi' ),
				'_lps_isbn'                => self::field( 'string', 'ISBN', 'text' ),
				'_lps_issn'                => self::field( 'string', 'ISSN', 'text' ),
				'_lps_arxiv_id'            => self::field( 'string', 'arXiv identifier', 'text' ),
				'_lps_venue'               => self::field( 'string', 'Venue', 'text' ),
				'_lps_citation'            => self::field( 'string', 'Authoritative citation', 'textarea' ),
				'_lps_author_ids'          => self::field( 'array', 'Ordered author record IDs', 'id_array' ),
				'_lps_license'             => self::field( 'string', 'License', 'text' ),
				'_lps_canonical_url'       => self::field( 'string', 'Canonical URL', 'url' ),
				'_lps_open_access_url'     => self::field( 'string', 'Open access URL', 'url' ),
				'_lps_pdf_url'             => self::field( 'string', 'PDF URL', 'url' ),
				'_lps_code_url'            => self::field( 'string', 'Code URL', 'url' ),
				'_lps_data_url'            => self::field( 'string', 'Data URL', 'url' ),
				'_lps_project_ids'         => self::field( 'array', 'Project record IDs', 'id_array' ),
				'_lps_research_area_ids'   => self::field( 'array', 'Research area record IDs', 'id_array' ),
			),
			'lps_news'          => array(
				'_lps_canonical_date'     => self::field( 'string', 'Canonical publication date', 'datetime' ),
				'_lps_news_status'        => self::field( 'string', 'News status', 'key' ),
				'_lps_related_record_ids' => self::field( 'array', 'Related record IDs', 'id_array' ),
				'_lps_featured_until'     => self::field( 'string', 'Featured-until date', 'date' ),
			),
			'lps_opportunity'   => array(
				'_lps_opportunity_type'         => self::field( 'string', 'Opportunity type', 'key' ),
				'_lps_audiences'                => self::field( 'array', 'Audience keys', 'string_array' ),
				'_lps_opens_at'                 => self::field( 'string', 'Application opening timestamp', 'datetime' ),
				'_lps_closes_at'                => self::field( 'string', 'Application closing timestamp', 'datetime' ),
				'_lps_positions'                => self::field( 'integer', 'Number of positions', 'integer' ),
				'_lps_stipend'                  => self::field( 'string', 'Stipend statement', 'text' ),
				'_lps_project_ids'              => self::field( 'array', 'Project record IDs', 'id_array' ),
				'_lps_supervisor_ids'           => self::field( 'array', 'Supervisor record IDs', 'id_array' ),
				'_lps_funder_ids'               => self::field( 'array', 'Funder record IDs', 'id_array' ),
				'_lps_location'                 => self::field( 'string', 'Location', 'text' ),
				'_lps_mode'                     => self::field( 'string', 'Attendance mode', 'key' ),
				'_lps_contact'                  => self::field( 'string', 'Approved public contact', 'email' ),
				'_lps_contact_is_role'          => self::field( 'boolean', 'Contact is a public role account', 'boolean' ),
				'_lps_application_url'          => self::field( 'string', 'External application URL', 'url' ),
				'_lps_application_url_approved' => self::field( 'boolean', 'External application URL approved', 'boolean' ),
				'_lps_eligibility'              => self::field( 'string', 'Localized eligibility', 'textarea' ),
				'_lps_application_instructions' => self::field( 'string', 'Localized application instructions', 'textarea' ),
			),
			'lps_event'         => array(
				'_lps_starts_at'          => self::field( 'string', 'Event start timestamp', 'datetime' ),
				'_lps_ends_at'            => self::field( 'string', 'Event end timestamp', 'datetime' ),
				'_lps_event_status'       => self::field( 'string', 'Event status', 'key' ),
				'_lps_related_record_ids' => self::field( 'array', 'Related record IDs', 'id_array' ),
				'_lps_speaker_ids'        => self::field( 'array', 'Speaker record IDs', 'id_array' ),
				'_lps_organizer_ids'      => self::field( 'array', 'Organizer record IDs', 'id_array' ),
				'_lps_venue'              => self::field( 'string', 'Venue', 'text' ),
				'_lps_online_url'         => self::field( 'string', 'Online event URL', 'url' ),
				'_lps_registration_url'   => self::field( 'string', 'Registration URL', 'url' ),
				'_lps_recording_url'      => self::field( 'string', 'Recording URL', 'url' ),
			),
			'lps_redirect'      => array(
				'_lps_redirect_source'     => self::field( 'string', 'Unique normalized source path', 'path' ),
				'_lps_redirect_target'     => self::field( 'string', 'Target URL or path', 'url_or_path' ),
				'_lps_redirect_gone'       => self::field( 'boolean', 'Return HTTP 410', 'boolean' ),
				'_lps_redirect_status'     => self::field( 'integer', 'HTTP status', 'integer' ),
				'_lps_redirect_reason'     => self::field( 'string', 'Editorial reason', 'textarea' ),
				'_lps_redirect_provenance' => self::field( 'string', 'Source provenance', 'url' ),
				'_lps_verified_at'         => self::field( 'string', 'Verification timestamp', 'datetime' ),
			),
		);

		$specific = array_merge( $specific, TeachingContracts::specific_meta_fields( array( self::class, 'field' ) ) );

		$result = array();
		foreach ( array_keys( self::post_types() ) as $post_type ) {
			$result[ $post_type ] = array_merge( $common, $specific[ $post_type ] );
			foreach ( array( '_lps_owner_user_id', '_lps_translation_reviewer_id' ) as $private_key ) {
				$result[ $post_type ][ $private_key ]['show_in_rest'] = false;
			}
		}
		return $result;
	}

	/**
	 * Returns the single typed site-settings REST schema.
	 *
	 * @return array{type: string, additionalProperties: bool, properties: array<string, PropertyDefinition>}
	 */
	public static function site_settings_schema(): array {
		$properties = array(
			'official_name'         => array( 'type' => 'string' ),
			'acronym'               => array( 'type' => 'string' ),
			'parent_ufrj'           => array( 'type' => 'string' ),
			'parent_coppe'          => array( 'type' => 'string' ),
			'founded_year'          => array(
				'type'    => 'integer',
				'minimum' => 1900,
				'maximum' => 2100,
			),
			'address'               => array( 'type' => 'string' ),
			'public_contact'        => array(
				'type'   => 'string',
				'format' => 'email',
			),
			'timezone'              => array( 'type' => 'string' ),
			'official_website'      => array(
				'type'   => 'string',
				'format' => 'uri',
			),
			'orcid_organization'    => array( 'type' => 'string' ),
			'logo_id'               => array( 'type' => 'integer' ),
			'privacy_contact'       => array(
				'type'   => 'string',
				'format' => 'email',
			),
			'accessibility_contact' => array(
				'type'   => 'string',
				'format' => 'email',
			),
			'title_pt_br'           => array( 'type' => 'string' ),
			'title_en'              => array( 'type' => 'string' ),
			'tagline_pt_br'         => array( 'type' => 'string' ),
			'tagline_en'            => array( 'type' => 'string' ),
			'footer_pt_br'          => array( 'type' => 'string' ),
			'footer_en'             => array( 'type' => 'string' ),
		);
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
		);
	}

	/**
	 * Builds one record-type definition.
	 *
	 * @param string $plural    Plural label.
	 * @param string $singular  Singular label.
	 * @param string $rest_base REST collection base.
	 * @param bool   $is_public Whether public queries are supported.
	 * @param bool   $builtin   Whether WordPress owns the type.
	 * @return TypeDefinition
	 */
	private static function type( string $plural, string $singular, string $rest_base, bool $is_public = true, bool $builtin = false ): array {
		return array(
			'labels'       => array(
				'name'          => $plural,
				'singular_name' => $singular,
			),
			'public'       => $is_public,
			'builtin'      => $builtin,
			'show_in_rest' => true,
			'rest_base'    => $rest_base,
			'supports'     => array( 'title', 'editor', 'excerpt', 'author', 'revisions', 'custom-fields', 'thumbnail' ),
		);
	}

	/**
	 * Builds one typed metadata definition.
	 *
	 * @param string $type        REST primitive type.
	 * @param string $description Accessible editor description.
	 * @param string $sanitizer   Sanitizer selector.
	 * @return FieldDefinition
	 */
	public static function field( string $type, string $description, string $sanitizer ): array {
		$callback = match ( $sanitizer ) {
			'integer' => array( Policy::class, 'sanitize_integer' ),
			'boolean' => array( Policy::class, 'sanitize_boolean' ),
			'string_array', 'id_array', 'integer_array', 'url_array' => array( Policy::class, 'sanitize_array' ),
			'url', 'url_or_path' => array( Policy::class, 'sanitize_url' ),
			'email' => array( Policy::class, 'sanitize_email' ),
			'doi' => array( RelationshipPolicy::class, 'normalize_doi' ),
			'record_id' => array( Policy::class, 'sanitize_record_id' ),
			'origin' => array( PublicationPolicy::class, 'sanitize_origin' ),
			'course_code' => array( TeachingContracts::class, 'normalize_course_code' ),
			'term_code' => array( TeachingContracts::class, 'normalize_term_code' ),
			'section_key' => array( TeachingContracts::class, 'normalize_section_key' ),
			'version_id' => array( TeachingContracts::class, 'normalize_version_id' ),
			'resource_language' => array( TeachingContracts::class, 'normalize_language' ),
			'iso_date' => array( TeachingContracts::class, 'normalize_iso_date' ),
			'textarea' => array( Policy::class, 'sanitize_textarea' ),
			default => array( Policy::class, 'sanitize_text' ),
		};
		return array(
			'type'              => $type,
			'single'            => true,
			'description'       => $description,
			'show_in_rest'      => true,
			'sanitize_callback' => $callback,
			'auth_callback'     => array( Policy::class, 'can_edit_meta' ),
		);
	}
}
