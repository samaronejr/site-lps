<?php
/**
 * Portable relationship validation and normalization policy.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

require_once __DIR__ . '/class-teachingcontracts.php';

/** Defines canonical relationship direction, shape, cardinality, and identifier rules. */
final class RelationshipPolicy {
	/**
	 * Returns every approved canonical relationship type and endpoint contract.
	 *
	 * @return array<string, array{source_types: array<int, string>, target_types: array<int, string>, roles: array<int, string>}>
	 */
	public static function relation_specs(): array {
		return array(
			'project_member'         => array(
				'source_types' => array( 'lps_project' ),
				'target_types' => array( 'lps_person' ),
				'roles'        => array( 'lead', 'member', 'collaborator' ),
			),
			'project_funder'         => array(
				'source_types' => array( 'lps_project' ),
				'target_types' => array( 'lps_organization' ),
				'roles'        => array( 'funder', 'co-funder', 'sponsor' ),
			),
			'project_partner'        => array(
				'source_types' => array( 'lps_project' ),
				'target_types' => array( 'lps_organization' ),
				'roles'        => array( 'partner', 'implementation-partner' ),
			),
			'related_record'         => array(
				'source_types' => array( 'page', 'lps_news', 'lps_event' ),
				'target_types' => array( 'page', 'lps_person', 'lps_organization', 'lps_research_area', 'lps_project', 'lps_publication', 'lps_news', 'lps_opportunity', 'lps_event' ),
				'roles'        => array( 'related', 'featured', 'context' ),
			),
			'event_speaker'          => array(
				'source_types' => array( 'lps_event' ),
				'target_types' => array( 'lps_person' ),
				'roles'        => array( 'speaker', 'keynote', 'panelist', 'moderator' ),
			),
			'event_organizer'        => array(
				'source_types' => array( 'lps_event' ),
				'target_types' => array( 'lps_person', 'lps_organization' ),
				'roles'        => array( 'organizer', 'host', 'co-organizer' ),
			),
			'opportunity_supervisor' => array(
				'source_types' => array( 'lps_opportunity' ),
				'target_types' => array( 'lps_person' ),
				'roles'        => array( 'primary', 'co-supervisor' ),
			),
			'opportunity_project'    => array(
				'source_types' => array( 'lps_opportunity' ),
				'target_types' => array( 'lps_project' ),
				'roles'        => array( 'host-project' ),
			),
			'publication_project'    => array(
				'source_types' => array( 'lps_publication' ),
				'target_types' => array( 'lps_project' ),
				'roles'        => array( 'output-of', 'supported-by' ),
			),
			'research_area'          => array(
				'source_types' => array( 'lps_person', 'lps_project', 'lps_publication' ),
				'target_types' => array( 'lps_research_area' ),
				'roles'        => array( 'primary', 'secondary' ),
			),
			'publication_relation'   => array(
				'source_types' => array( 'lps_publication' ),
				'target_types' => array( 'lps_publication' ),
				'roles'        => array( 'preprint-of', 'final-of', 'version-of', 'correction-of' ),
			),
			'offering_course'        => array(
				'source_types' => array( 'lps_offering' ),
				'target_types' => array( 'lps_course' ),
				'roles'        => array( 'instance-of' ),
			),
			'offering_term'          => array(
				'source_types' => array( 'lps_offering' ),
				'target_types' => array( 'lps_term' ),
				'roles'        => array( 'scheduled-in' ),
			),
			'teaching_team'          => array(
				'source_types' => array( 'lps_offering' ),
				'target_types' => array( 'lps_person' ),
				'roles'        => array( 'lead', 'co-teacher', 'assistant' ),
			),
			'unit_offering'          => array(
				'source_types' => array( 'lps_unit' ),
				'target_types' => array( 'lps_offering' ),
				'roles'        => array( 'part-of' ),
			),
			'resource_offering'      => array(
				'source_types' => array( 'lps_resource' ),
				'target_types' => array( 'lps_offering' ),
				'roles'        => array( 'attached-to' ),
			),
			'resource_unit'          => array(
				'source_types' => array( 'lps_resource' ),
				'target_types' => array( 'lps_unit' ),
				'roles'        => array( 'supports' ),
			),
		);
	}

	/**
	 * Returns forbidden reverse aliases mapped to their canonical forward type.
	 *
	 * @return array<string, string>
	 */
	public static function reverse_aliases(): array {
		return array(
			'person_project'                 => 'project_member',
			'organization_funded_project'    => 'project_funder',
			'organization_partnered_project' => 'project_partner',
			'person_speaking_event'          => 'event_speaker',
			'person_organized_event'         => 'event_organizer',
			'person_supervised_opportunity'  => 'opportunity_supervisor',
			'project_publication'            => 'publication_project',
			'research_area_record'           => 'research_area',
			'course_offering'                => 'offering_course',
			'term_offering'                  => 'offering_term',
			'person_teaching'                => 'teaching_team',
			'offering_unit'                  => 'unit_offering',
			'offering_resource'              => 'resource_offering',
			'unit_resource'                  => 'resource_unit',
		);
	}

	/**
	 * Sorts relationship rows deterministically without inventing order.
	 *
	 * @param array<int, array<string, mixed>> $rows Candidate rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sort_rows( array $rows ): array {
		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$position = self::integer( $left['sort_order'] ?? 0 ) <=> self::integer( $right['sort_order'] ?? 0 );
				if ( 0 !== $position ) {
					return $position;
				}
				$source = self::integer( $left['source_post_id'] ?? 0 ) <=> self::integer( $right['source_post_id'] ?? 0 );
				if ( 0 !== $source ) {
					return $source;
				}
				return self::integer( $left['target_post_id'] ?? $left['author_post_id'] ?? 0 ) <=> self::integer( $right['target_post_id'] ?? $right['author_post_id'] ?? 0 );
			}
		);
		return $rows;
	}

	/**
	 * Normalizes ordered authorship rows.
	 *
	 * @param array<int, array<string, mixed>> $authors Candidate authors.
	 * @return array<int, array{author_kind: string, author_post_id: int, display_name: string, orcid: string, affiliation: string, author_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}>
	 */
	public static function normalize_authors( array $authors ): array {
		$normalized = array();
		foreach ( $authors as $author ) {
			$normalized[] = array(
				'author_kind'       => self::text( $author['author_kind'] ?? '' ),
				'author_post_id'    => self::integer( $author['author_post_id'] ?? 0 ),
				'display_name'      => self::text( $author['display_name'] ?? '' ),
				'orcid'             => self::normalize_orcid( $author['orcid'] ?? '' ),
				'affiliation'       => self::text( $author['affiliation'] ?? '' ),
				'author_role'       => self::text( $author['author_role'] ?? 'author' ),
				'sort_order'        => self::integer( $author['sort_order'] ?? 0 ),
				'start_date'        => self::date( $author['start_date'] ?? '' ),
				'end_date'          => self::date( $author['end_date'] ?? '' ),
				'public_visibility' => self::boolean( $author['public_visibility'] ?? true ),
			);
		}
		/**
		 * Typed normalized authors.
		 *
		 * @var array<int, array{author_kind: string, author_post_id: int, display_name: string, orcid: string, affiliation: string, author_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}> $ordered
		 */
		$ordered = self::sort_rows( $normalized );
		return $ordered;
	}

	/**
	 * Returns all ordered-authorship shape errors.
	 *
	 * @param array<int, array<string, mixed>> $authors Candidate authors.
	 * @return array<int, string>
	 */
	public static function author_errors( array $authors ): array {
		if ( array() === $authors ) {
			return array( 'lps_authors_required' );
		}
		$errors = self::ordering_errors( $authors );
		foreach ( $authors as $author ) {
			$raw_orcid = self::text( $author['orcid'] ?? '' );
			if ( '' !== $raw_orcid && '' === self::normalize_orcid( $raw_orcid ) ) {
				$errors[] = 'lps_invalid_orcid';
			}
		}
		$normalized = self::normalize_authors( $authors );
		foreach ( $normalized as $author ) {
			if ( ! in_array( $author['author_kind'], array( 'internal', 'external', 'collective' ), true ) ) {
				$errors[] = 'lps_invalid_author_kind';
			}
			if ( 'internal' === $author['author_kind'] && 0 >= $author['author_post_id'] ) {
				$errors[] = 'lps_internal_author_required';
			}
			if ( in_array( $author['author_kind'], array( 'external', 'collective' ), true ) && '' === $author['display_name'] ) {
				$errors[] = 'lps_external_author_name_required';
			}
			if ( ! self::valid_date_range( $author['start_date'], $author['end_date'] ) ) {
				$errors[] = 'lps_invalid_relationship_dates';
			}
		}
		return array_values( array_unique( $errors ) );
	}

	/**
	 * Normalizes canonical relationship rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Candidate rows.
	 * @return array<int, array{target_post_id: int, relationship_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}>
	 */
	public static function normalize_relationships( array $rows ): array {
		$normalized = array();
		foreach ( $rows as $row ) {
			$normalized[] = array(
				'target_post_id'    => self::integer( $row['target_post_id'] ?? 0 ),
				'relationship_role' => self::text( $row['relationship_role'] ?? '' ),
				'sort_order'        => self::integer( $row['sort_order'] ?? 0 ),
				'start_date'        => self::date( $row['start_date'] ?? '' ),
				'end_date'          => self::date( $row['end_date'] ?? '' ),
				'public_visibility' => self::boolean( $row['public_visibility'] ?? true ),
			);
		}
		/**
		 * Typed normalized relationships.
		 *
		 * @var array<int, array{target_post_id: int, relationship_role: string, sort_order: int, start_date: string, end_date: string, public_visibility: bool}> $ordered
		 */
		$ordered = self::sort_rows( $normalized );
		return $ordered;
	}

	/**
	 * Returns set-level relationship shape and cardinality errors.
	 *
	 * @param string                           $relationship_type Canonical relationship type.
	 * @param array<int, array<string, mixed>> $rows              Candidate rows.
	 * @return array<int, string>
	 */
	public static function relationship_errors( string $relationship_type, array $rows ): array {
		if ( isset( self::reverse_aliases()[ $relationship_type ] ) || str_starts_with( $relationship_type, 'reverse_' ) ) {
			return array( 'lps_manual_reverse_forbidden' );
		}
		$specs = self::relation_specs();
		if ( ! isset( $specs[ $relationship_type ] ) ) {
			return array( 'lps_unknown_relationship_type' );
		}

		$errors     = self::ordering_errors( $rows );
		$normalized = self::normalize_relationships( $rows );
		$targets    = array();
		foreach ( $normalized as $row ) {
			if ( 0 >= $row['target_post_id'] ) {
				$errors[] = 'lps_orphan_relationship_target';
			}
			if ( isset( $targets[ $row['target_post_id'] ] ) ) {
				$errors[] = 'lps_duplicate_relationship_target';
			}
			$targets[ $row['target_post_id'] ] = true;
			if ( ! in_array( $row['relationship_role'], $specs[ $relationship_type ]['roles'], true ) ) {
				$errors[] = 'lps_invalid_relationship_role';
			}
			if ( ! self::valid_date_range( $row['start_date'], $row['end_date'] ) ) {
				$errors[] = 'lps_invalid_relationship_dates';
			}
		}

		if ( 'project_member' === $relationship_type && ! in_array( 'lead', array_column( $normalized, 'relationship_role' ), true ) ) {
			$errors[] = 'lps_project_lead_required';
		}
		if ( 'research_area' === $relationship_type ) {
			$roles         = array_column( $normalized, 'relationship_role' );
			$primary_count = count( array_keys( $roles, 'primary', true ) );
			if ( 0 === $primary_count ) {
				$errors[] = 'lps_primary_research_area_required';
			} elseif ( 1 < $primary_count ) {
				$errors[] = 'lps_multiple_primary_research_areas';
			}
			if ( 4 < count( array_keys( $roles, 'secondary', true ) ) ) {
				$errors[] = 'lps_too_many_secondary_research_areas';
			}
		}
		if ( in_array( $relationship_type, array( 'offering_course', 'offering_term', 'teaching_team', 'unit_offering', 'resource_offering', 'resource_unit' ), true ) ) {
			$errors = array_merge( $errors, TeachingContracts::relationship_errors( $relationship_type, $normalized ) );
		}
		return array_values( array_unique( $errors ) );
	}

	/**
	 * Returns a typed endpoint violation, if any.
	 *
	 * @param string      $relationship_type Canonical relationship type.
	 * @param string      $source_type       Source post type.
	 * @param string|null $target_type       Target post type, when the target exists.
	 * @param bool        $target_exists     Whether the target record exists.
	 */
	public static function endpoint_error( string $relationship_type, string $source_type, ?string $target_type, bool $target_exists ): ?string {
		if ( isset( self::reverse_aliases()[ $relationship_type ] ) || str_starts_with( $relationship_type, 'reverse_' ) ) {
			return 'lps_manual_reverse_forbidden';
		}
		$specs = self::relation_specs();
		if ( ! isset( $specs[ $relationship_type ] ) ) {
			return 'lps_unknown_relationship_type';
		}
		if ( ! in_array( $source_type, $specs[ $relationship_type ]['source_types'], true ) ) {
			return 'lps_invalid_relationship_source_type';
		}
		if ( ! $target_exists || null === $target_type ) {
			return 'lps_orphan_relationship_target';
		}
		if ( ! in_array( $target_type, $specs[ $relationship_type ]['target_types'], true ) ) {
			return 'lps_invalid_relationship_target_type';
		}
		return null;
	}

	/**
	 * Derives a reverse list from canonical forward rows.
	 *
	 * @param array<int, array<string, mixed>> $rows              Canonical rows.
	 * @param int                              $target_post_id    Target record.
	 * @param string|null                      $relationship_type Optional type filter.
	 * @return array<int, array<string, mixed>>
	 */
	public static function reverse_rows( array $rows, int $target_post_id, ?string $relationship_type = null ): array {
		$reverse = array_values(
			array_filter(
				$rows,
				static fn ( array $row ): bool => self::integer( $row['target_post_id'] ?? 0 ) === $target_post_id
					&& ( null === $relationship_type || self::text( $row['relationship_type'] ?? '' ) === $relationship_type )
			)
		);
		return self::sort_rows( $reverse );
	}

	/**
	 * Person departure and death never invalidate an existing scholarly relationship.
	 *
	 * @param string $person_status Controlled person status.
	 */
	public static function historical_target_allowed( string $person_status ): bool {
		return in_array( $person_status, array( 'active', 'alumni', 'departed', 'deceased', 'in-memoriam' ), true );
	}

	/**
	 * Checks whether a record is either endpoint of canonical relationship rows.
	 *
	 * @param int                              $post_id Record database ID.
	 * @param array<int, array<string, mixed>> $rows    Canonical rows.
	 */
	public static function record_participates( int $post_id, array $rows ): bool {
		foreach ( $rows as $row ) {
			if ( self::integer( $row['source_post_id'] ?? 0 ) === $post_id || self::integer( $row['target_post_id'] ?? 0 ) === $post_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rejects editor-maintained reverse relationship payloads.
	 *
	 * @param array<string, mixed> $payload Boundary payload.
	 */
	public static function manual_reverse_error( array $payload ): ?string {
		foreach ( array( 'reverse_relationships', 'reverse_relations', 'manual_reverse' ) as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				return 'lps_manual_reverse_forbidden';
			}
		}
		$type = self::text( $payload['relationship_type'] ?? '' );
		if ( isset( self::reverse_aliases()[ $type ] ) || str_starts_with( $type, 'reverse_' ) ) {
			return 'lps_manual_reverse_forbidden';
		}
		$meta = $payload['meta'] ?? array();
		if ( is_array( $meta ) ) {
			foreach ( array_keys( $meta ) as $key ) {
				if ( is_string( $key ) && ( str_starts_with( $key, '_lps_reverse_' ) || in_array( $key, self::manual_reverse_meta_keys(), true ) ) ) {
					return 'lps_manual_reverse_forbidden';
				}
			}
		}
		return null;
	}

	/**
	 * Returns legacy forward metadata keys retained as read-only API compatibility declarations.
	 *
	 * @return array<int, string>
	 */
	public static function legacy_relationship_meta_keys(): array {
		return array(
			'_lps_research_area_ids',
			'_lps_member_ids',
			'_lps_funder_ids',
			'_lps_partner_ids',
			'_lps_author_ids',
			'_lps_project_ids',
			'_lps_related_record_ids',
			'_lps_supervisor_ids',
			'_lps_speaker_ids',
			'_lps_organizer_ids',
			'_lps_course_ids',
			'_lps_term_ids',
			'_lps_offering_ids',
			'_lps_unit_ids',
			'_lps_resource_ids',
			'_lps_teaching_team_ids',
		);
	}

	/**
	 * Returns known forbidden reverse metadata keys.
	 *
	 * @return array<int, string>
	 */
	public static function manual_reverse_meta_keys(): array {
		return array(
			'_lps_publication_ids',
			'_lps_member_project_ids',
			'_lps_funded_project_ids',
			'_lps_partner_project_ids',
			'_lps_speaking_event_ids',
			'_lps_organized_event_ids',
			'_lps_supervised_opportunity_ids',
			'_lps_related_from_ids',
			'_lps_offering_course_ids',
			'_lps_offering_term_ids',
			'_lps_teaching_offering_ids',
			'_lps_unit_offering_ids',
			'_lps_resource_offering_ids',
			'_lps_unit_resource_ids',
		);
	}

	/**
	 * Normalizes DOI strings and doi.org URL variants for identity comparison.
	 *
	 * @param mixed $value Boundary DOI input.
	 */
	public static function normalize_doi( mixed $value ): string {
		$doi = self::text( $value );
		if ( '' === $doi ) {
			return '';
		}
		if ( 1 === preg_match( '~^https?://(?:dx\.)?doi\.org/([^?#]+)~i', $doi, $matches ) ) {
			$doi = rawurldecode( $matches[1] );
		} else {
			$doi = (string) preg_replace( '/^doi\s*:\s*/i', '', $doi );
		}
		$doi = strtolower( trim( $doi ) );
		return 1 === preg_match( '/^10\.\d{4,9}\/[^\s<>]+$/', $doi ) ? $doi : '';
	}

	/**
	 * Normalizes and checksum-validates an ORCID identifier.
	 *
	 * @param mixed $value Boundary ORCID input.
	 */
	public static function normalize_orcid( mixed $value ): string {
		$orcid = self::text( $value );
		$orcid = (string) preg_replace( '~^https?://orcid\.org/~i', '', $orcid );
		$orcid = strtoupper( trim( $orcid ) );
		if ( 1 !== preg_match( '/^\d{4}-\d{4}-\d{4}-[\dX]{4}$/', $orcid ) ) {
			return '';
		}
		$compact = str_replace( '-', '', $orcid );
		$total   = 0;
		for ( $index = 0; $index < 15; ++$index ) {
			$total = ( $total + (int) $compact[ $index ] ) * 2;
		}
		$result   = ( 12 - ( $total % 11 ) ) % 11;
		$checksum = 10 === $result ? 'X' : (string) $result;
		return $checksum === $compact[15] ? $orcid : '';
	}

	/**
	 * Returns relationship ordering violations.
	 *
	 * @param array<int, array<string, mixed>> $rows Candidate rows.
	 * @return array<int, string>
	 */
	private static function ordering_errors( array $rows ): array {
		$positions = array();
		foreach ( $rows as $row ) {
			$position = self::integer( $row['sort_order'] ?? 0 );
			if ( 1 > $position || isset( $positions[ $position ] ) ) {
				return array( 'lps_invalid_relationship_order' );
			}
			$positions[ $position ] = true;
		}
		return array();
	}

	/**
	 * Checks an optional ISO date range.
	 *
	 * @param string $start_date Optional inclusive start date.
	 * @param string $end_date   Optional inclusive end date.
	 */
	private static function valid_date_range( string $start_date, string $end_date ): bool {
		if ( '' !== $start_date && 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ) {
			return false;
		}
		if ( '' !== $end_date && 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
			return false;
		}
		return '' === $start_date || '' === $end_date || $start_date <= $end_date;
	}

	/**
	 * Normalizes one optional ISO date without changing its precision.
	 *
	 * @param mixed $value Boundary date input.
	 */
	private static function date( mixed $value ): string {
		$date = self::text( $value );
		return '' === $date || 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	/**
	 * Converts boundary input to non-negative integer form.
	 *
	 * @param mixed $value Boundary integer input.
	 */
	private static function integer( mixed $value ): int {
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Converts boundary input to a boolean.
	 *
	 * @param mixed $value Boundary boolean input.
	 */
	private static function boolean( mixed $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Converts boundary input to single-line plain text.
	 *
	 * @param mixed $value Boundary text input.
	 */
	private static function text( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = (string) preg_replace( '/<[^>]*>/', '', (string) $value );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $text );
		return trim( $text );
	}
}
