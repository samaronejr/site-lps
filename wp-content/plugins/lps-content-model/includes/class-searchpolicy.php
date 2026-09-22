<?php
/**
 * Locale search normalization, relevance, facet, and pagination policy.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

require_once __DIR__ . '/class-teachingcontracts.php';

/**
 * Owns every search decision that must hold with or without WordPress loaded.
 *
 * The policy is deliberately storage-free: the index table feeds it plain rows so
 * the same normalization, weighting, facet, and pagination rules are provable in
 * unit tests and reused verbatim by the rendered search and listing surfaces.
 */
final class SearchPolicy {
	public const MIN_QUERY_LENGTH = 2;
	public const MAX_QUERY_LENGTH = 100;
	public const PER_PAGE         = 20;
	public const MAX_PER_PAGE     = 100;

	/**
	 * Highest number of values one facet may carry in a single request.
	 *
	 * Capping the combination space is what keeps filtered URLs finite and
	 * therefore non-crawlable as an unbounded set.
	 */
	public const MAX_FACET_VALUES = 5;

	private const WEIGHT_EXACT  = 1000;
	private const WEIGHT_HIGH   = 100;
	private const WEIGHT_MEDIUM = 20;
	private const WEIGHT_LOW    = 5;
	private const PHRASE_BONUS  = 300;

	/**
	 * Characters preserved by normalization because identifiers depend on them.
	 */
	private const ALLOWED_PATTERN = '/[^a-z0-9:\/._-]+/';

	/**
	 * Accent folding map applied after lowercasing.
	 *
	 * @var array<string, string>
	 */
	private const FOLDING = array(
		'á' => 'a',
		'à' => 'a',
		'â' => 'a',
		'ã' => 'a',
		'ä' => 'a',
		'å' => 'a',
		'ā' => 'a',
		'é' => 'e',
		'è' => 'e',
		'ê' => 'e',
		'ë' => 'e',
		'ē' => 'e',
		'í' => 'i',
		'ì' => 'i',
		'î' => 'i',
		'ï' => 'i',
		'ī' => 'i',
		'ó' => 'o',
		'ò' => 'o',
		'ô' => 'o',
		'õ' => 'o',
		'ö' => 'o',
		'ō' => 'o',
		'ú' => 'u',
		'ù' => 'u',
		'û' => 'u',
		'ü' => 'u',
		'ū' => 'u',
		'ç' => 'c',
		'ñ' => 'n',
		'ý' => 'y',
		'ÿ' => 'y',
		'ß' => 'ss',
		'æ' => 'ae',
		'œ' => 'oe',
		'ø' => 'o',
		'đ' => 'd',
		'ł' => 'l',
		'š' => 's',
		'ž' => 'z',
		'č' => 'c',
		'ř' => 'r',
	);

	/**
	 * Approved facets per record type, in the order surfaces must render them.
	 *
	 * An empty value list marks an open vocabulary that is validated by shape
	 * only; a populated list is a closed controlled vocabulary.
	 *
	 * @var array<string, array<string, array<int, string>>>
	 */
	private const FACETS = array(
		'lps_publication' => array(
			'year'    => array(),
			'type'    => array(),
			'person'  => array(),
			'project' => array(),
			'area'    => array( 'instrumentation', 'signal-processing', 'computational-intelligence', 'software-engineering' ),
		),
		'lps_project'     => array(
			'status' => array( 'planned', 'active', 'completed', 'suspended' ),
			'area'   => array( 'instrumentation', 'signal-processing', 'computational-intelligence', 'software-engineering' ),
			'domain' => array( 'electrical-nuclear-energy', 'oil-and-gas', 'high-energy-physics', 'defense', 'medicine', 'veterinary-science', 'data-quality' ),
		),
		'lps_person'      => array(
			'role'   => array(),
			'status' => array( 'active', 'alumni', 'in-memoriam' ),
			'area'   => array( 'instrumentation', 'signal-processing', 'computational-intelligence', 'software-engineering' ),
		),
		'lps_news'        => array(
			'category' => array(),
			'year'     => array(),
		),
		'lps_opportunity' => array(
			'state'    => array( 'open', 'upcoming', 'closed' ),
			'type'     => array(),
			'audience' => array(),
		),
		'lps_event'       => array(
			'date' => array(),
			'type' => array(),
		),
		'lps_course'      => array(
			'level' => array( 'undergraduate', 'graduate', 'extension' ),
		),
		'lps_offering'    => array(
			'status'     => array( 'current', 'previous' ),
			'term'       => array(),
			'level'      => array( 'undergraduate', 'graduate', 'extension' ),
			'instructor' => array(),
		),
		'lps_resource'    => array(
			'type'     => array(),
			'language' => array(),
		),
	);

	/**
	 * Folds one untrusted value to its comparable search form.
	 *
	 * Markup is dropped, accents folded, case lowered, and everything except the
	 * characters identifiers depend on collapses to single spaces.
	 *
	 * @param string $value Untrusted text value.
	 */
	public static function normalize( string $value ): string {
		$text = $value;
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}
		$text = strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- WordPress strips script bodies entirely; hostile markup must fold to comparable text, not vanish.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$text = mb_strtolower( $text, 'UTF-8' );
		$text = strtr( $text, self::FOLDING );
		$text = (string) preg_replace( self::ALLOWED_PATTERN, ' ', $text );
		$text = (string) preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	/**
	 * Splits one normalized query into its distinct terms.
	 *
	 * @param string $query Untrusted query string.
	 * @return array<int, string>
	 */
	public static function terms( string $query ): array {
		$normalized = self::normalize( $query );
		if ( '' === $normalized ) {
			return array();
		}
		return array_values( array_unique( explode( ' ', $normalized ) ) );
	}

	/**
	 * Reports the boundary violation of one query, or null when it is usable.
	 *
	 * @param string $query Untrusted query string.
	 */
	public static function query_error( string $query ): ?string {
		if ( ! mb_check_encoding( $query, 'UTF-8' ) ) {
			return 'malformed';
		}
		if ( 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $query ) ) {
			return 'malformed';
		}
		$length = mb_strlen( trim( $query ), 'UTF-8' );
		if ( self::MAX_QUERY_LENGTH < $length ) {
			return 'too_long';
		}
		if ( self::MIN_QUERY_LENGTH > $length ) {
			return 'too_short';
		}
		if ( '' === self::normalize( $query ) ) {
			return 'malformed';
		}
		return null;
	}

	/**
	 * Returns the approved facet contract of one record type.
	 *
	 * @param string $post_type Record type.
	 * @return array<string, array<int, string>>
	 */
	public static function facet_definitions( string $post_type ): array {
		return self::FACETS[ $post_type ] ?? array();
	}

	/**
	 * Returns every record type that exposes listing facets.
	 *
	 * @return array<int, string>
	 */
	public static function faceted_post_types(): array {
		return array_keys( self::FACETS );
	}

	/**
	 * Keeps only approved facet keys and values from untrusted request input.
	 *
	 * @param array<mixed, mixed>               $raw         Untrusted facet input.
	 * @param array<string, array<int, string>> $definitions Approved facet contract.
	 * @return array<string, array<int, string>>
	 */
	public static function sanitize_facets( array $raw, array $definitions ): array {
		$clean = array();
		foreach ( $definitions as $facet => $allowed ) {
			$candidates = $raw[ $facet ] ?? null;
			if ( is_string( $candidates ) ) {
				$candidates = array( $candidates );
			}
			if ( ! is_array( $candidates ) ) {
				continue;
			}
			$values = array();
			foreach ( $candidates as $candidate ) {
				if ( ! is_string( $candidate ) ) {
					continue;
				}
				$value = strtolower( trim( $candidate ) );
				if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $value ) ) {
					continue;
				}
				if ( array() !== $allowed && ! in_array( $value, $allowed, true ) ) {
					continue;
				}
				if ( ! in_array( $value, $values, true ) ) {
					$values[] = $value;
				}
				if ( self::MAX_FACET_VALUES === count( $values ) ) {
					break;
				}
			}
			if ( array() !== $values ) {
				$clean[ $facet ] = $values;
			}
		}
		return $clean;
	}

	/**
	 * Reports whether one indexed row satisfies every active facet.
	 *
	 * Facets combine with AND across keys and OR inside a single key.
	 *
	 * @param array<string, mixed>              $record Indexed row.
	 * @param array<string, array<int, string>> $facets Sanitized active facets.
	 */
	public static function matches_facets( array $record, array $facets ): bool {
		$available = isset( $record['facets'] ) && is_array( $record['facets'] ) ? $record['facets'] : array();
		foreach ( $facets as $facet => $values ) {
			$present = isset( $available[ $facet ] ) && is_array( $available[ $facet ] ) ? $available[ $facet ] : array();
			$hit     = false;
			foreach ( $values as $value ) {
				if ( in_array( $value, $present, true ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Scores one indexed row against the normalized query terms.
	 *
	 * Exact identifier and name hits outrank title hits, which outrank summary
	 * and taxonomy hits, which outrank body hits. A row that misses any term
	 * scores zero and never reaches the result set.
	 *
	 * @param array<string, mixed> $record Indexed row.
	 * @param array<int, string>   $terms  Normalized query terms.
	 * @param string               $phrase Full normalized query.
	 */
	public static function score( array $record, array $terms, string $phrase ): int {
		if ( array() === $terms ) {
			return 0;
		}
		$exact  = self::normalized_list( $record['exact'] ?? array() );
		$high   = self::normalize( self::text( $record['high'] ?? '' ) );
		$medium = self::normalize( self::text( $record['medium'] ?? '' ) );
		$low    = self::normalize( self::text( $record['low'] ?? '' ) );

		$score = 0;
		if ( in_array( $phrase, $exact, true ) ) {
			$score += self::WEIGHT_EXACT;
		}
		if ( '' !== $phrase && $high === $phrase ) {
			$score += self::WEIGHT_EXACT;
		}
		foreach ( $terms as $term ) {
			$matched = false;
			foreach ( $exact as $identifier ) {
				if ( $identifier === $term ) {
					$score  += self::WEIGHT_EXACT;
					$matched = true;
					break;
				}
			}
			if ( '' !== $high && str_contains( $high, $term ) ) {
				$score  += self::WEIGHT_HIGH;
				$matched = true;
			}
			if ( '' !== $medium && str_contains( $medium, $term ) ) {
				$score  += self::WEIGHT_MEDIUM;
				$matched = true;
			}
			if ( '' !== $low && str_contains( $low, $term ) ) {
				$score  += self::WEIGHT_LOW;
				$matched = true;
			}
			if ( ! $matched ) {
				return 0;
			}
		}
		if ( '' !== $phrase && str_contains( $high, $phrase ) ) {
			$score += self::PHRASE_BONUS;
		}
		return $score;
	}

	/**
	 * Runs one locale-isolated, faceted, paginated search over indexed rows.
	 *
	 * @param array<int, array<string, mixed>>  $records  Indexed rows of any locale.
	 * @param string                            $query    Untrusted query string.
	 * @param string                            $locale   Supported locale slug.
	 * @param array<string, array<int, string>> $facets   Sanitized active facets.
	 * @param int                               $page     Requested page, one-based.
	 * @param int                               $per_page Results per page.
	 * @param string|null                       $now      Evaluation instant (ISO-8601); null means now.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int, error: string|null}
	 */
	public static function search_records( array $records, string $query, string $locale, array $facets, int $page = 1, int $per_page = self::PER_PAGE, ?string $now = null ): array {
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$page     = max( 1, $page );
		$now      = self::evaluation_instant( $now );
		$error    = self::query_error( $query );
		if ( null !== $error ) {
			return self::result( array(), 0, $page, $per_page, $error );
		}

		$phrase  = self::normalize( $query );
		$terms   = self::terms( $query );
		$matched = array();
		foreach ( $records as $record ) {
			if ( self::text( $record['locale'] ?? '' ) !== $locale ) {
				continue;
			}
			if ( ! self::lifecycle_visible( $record, $now ) ) {
				continue;
			}
			$record['facets'] = self::derived_facets( $record, $now );
			if ( ! self::matches_facets( $record, $facets ) ) {
				continue;
			}
			$score = self::score( $record, $terms, $phrase );
			if ( 0 === $score ) {
				continue;
			}
			$record['score'] = $score;
			$matched[]       = $record;
		}

		usort(
			$matched,
			static function ( array $left, array $right ): int {
				$by_score = self::number( $right['score'] ) <=> self::number( $left['score'] );
				if ( 0 !== $by_score ) {
					return $by_score;
				}
				return self::number( $left['post_id'] ?? 0 ) <=> self::number( $right['post_id'] ?? 0 );
			}
		);

		$total = count( $matched );
		$items = array_slice( $matched, ( $page - 1 ) * $per_page, $per_page );
		return self::result( $items, $total, $page, $per_page, null );
	}

	/**
	 * Builds the canonical, shareable query string of one search state.
	 *
	 * Parameters are emitted in a frozen order so the same state always produces
	 * byte-identical URLs that are safe to share and to cache.
	 *
	 * @param string                            $base   Route path.
	 * @param string                            $query  Untrusted query string.
	 * @param array<string, array<int, string>> $facets Sanitized active facets.
	 * @param int                               $page   Requested page, one-based.
	 */
	public static function canonical_url( string $base, string $query, array $facets, int $page = 1 ): string {
		$parts   = array();
		$trimmed = trim( $query );
		if ( '' !== $trimmed ) {
			$parts[] = 'q=' . rawurlencode( $trimmed );
		}
		ksort( $facets );
		foreach ( $facets as $facet => $values ) {
			sort( $values );
			foreach ( $values as $value ) {
				$parts[] = rawurlencode( $facet ) . '[]=' . rawurlencode( $value );
			}
		}
		if ( 1 < $page ) {
			$parts[] = 'page=' . $page;
		}
		return array() === $parts ? $base : $base . '?' . implode( '&', $parts );
	}

	/**
	 * Returns the robots directive for one search state.
	 *
	 * Only the bare route stays indexable; every query and facet combination is
	 * followed but never indexed, so filters cannot generate crawlable space.
	 *
	 * @param string                            $query  Untrusted query string.
	 * @param array<string, array<int, string>> $facets Sanitized active facets.
	 * @param int                               $page   Requested page, one-based.
	 */
	public static function robots_directive( string $query, array $facets, int $page = 1 ): string {
		if ( '' === trim( $query ) && array() === $facets && 1 >= $page ) {
			return 'index,follow';
		}
		return 'noindex,follow';
	}

	/**
	 * Counts the available values of every approved facet for one row set.
	 *
	 * @param array<int, array<string, mixed>>  $records     Indexed rows.
	 * @param array<string, array<int, string>> $definitions Approved facet contract.
	 * @param string|null                       $now         Evaluation instant (ISO-8601); null means now.
	 * @return array<string, array<string, int>>
	 */
	public static function facet_counts( array $records, array $definitions, ?string $now = null ): array {
		$now    = self::evaluation_instant( $now );
		$counts = array();
		foreach ( array_keys( $definitions ) as $facet ) {
			$counts[ $facet ] = array();
		}
		foreach ( $records as $record ) {
			if ( ! self::lifecycle_visible( $record, $now ) ) {
				continue;
			}
			$record['facets'] = self::derived_facets( $record, $now );
			if ( ! isset( $record['facets'] ) || ! is_array( $record['facets'] ) ) {
				continue;
			}
			foreach ( $record['facets'] as $facet => $values ) {
				if ( ! is_string( $facet ) || ! isset( $counts[ $facet ] ) || ! is_array( $values ) ) {
					continue;
				}
				foreach ( $values as $value ) {
					if ( ! is_string( $value ) ) {
						continue;
					}
					$counts[ $facet ][ $value ] = ( $counts[ $facet ][ $value ] ?? 0 ) + 1;
				}
			}
		}
		foreach ( $counts as $facet => $values ) {
			ksort( $values );
			$counts[ $facet ] = $values;
		}
		return $counts;
	}

	/**
	 * Maps one temporal status to the public current/previous filter bucket.
	 *
	 * Offerings not yet finished — upcoming and current — are `current`;
	 * completed and cancelled offerings are `previous`. Unknown values map to
	 * `previous` so a malformed status can never masquerade as current.
	 *
	 * @param string $temporal_status Derived temporal status.
	 */
	public static function offering_status_bucket( string $temporal_status ): string {
		return in_array( $temporal_status, array( 'upcoming', 'current' ), true ) ? 'current' : 'previous';
	}

	/**
	 * Reports whether one indexed row is visible at the evaluation instant.
	 *
	 * Resource rows carry their release lifecycle so the same fail-closed rule
	 * the download resolver applies decides search exposure: a stopped
	 * scheduler can never expose a scheduled resource early, and a due release
	 * never waits on cron. A resource row without lifecycle data is treated as
	 * unreleased — rows written before the lifecycle column existed fail
	 * closed rather than leaking a stale release state.
	 *
	 * @param array<string, mixed> $record Indexed row.
	 * @param string               $now    Evaluation instant (ISO-8601).
	 */
	private static function lifecycle_visible( array $record, string $now ): bool {
		if ( 'lps_resource' !== self::text( $record['post_type'] ?? '' ) ) {
			return true;
		}
		$lifecycle = isset( $record['lifecycle'] ) && is_array( $record['lifecycle'] ) ? $record['lifecycle'] : array();
		$state     = TeachingContracts::effective_release_state(
			self::text( $lifecycle['release_state'] ?? '' ),
			self::text( $lifecycle['release_at'] ?? '' ),
			$now
		);
		return 'released' === $state;
	}

	/**
	 * Recomputes the time-derived facet values of one row at read time.
	 *
	 * An offering's current/previous bucket derives from its term boundaries
	 * and cancellation flag on every read, so a term transition can never
	 * leave a stale filter value behind. Rows without lifecycle data keep
	 * their stored facets untouched.
	 *
	 * @param array<string, mixed> $record Indexed row.
	 * @param string               $now    Evaluation instant (ISO-8601).
	 * @return array<string, array<int, string>>
	 */
	private static function derived_facets( array $record, string $now ): array {
		$facets = isset( $record['facets'] ) && is_array( $record['facets'] ) ? $record['facets'] : array();
		if ( 'lps_offering' !== self::text( $record['post_type'] ?? '' ) ) {
			return $facets;
		}
		$lifecycle = isset( $record['lifecycle'] ) && is_array( $record['lifecycle'] ) ? $record['lifecycle'] : array();
		if ( array() === $lifecycle ) {
			return $facets;
		}
		$temporal = TeachingContracts::temporal_status(
			$lifecycle['starts_on'] ?? '',
			$lifecycle['ends_on'] ?? '',
			$lifecycle['cancelled'] ?? false,
			substr( $now, 0, 10 )
		);
		if ( '' !== $temporal ) {
			$facets['status'] = array( self::offering_status_bucket( $temporal ) );
		}
		return $facets;
	}

	/**
	 * Returns the evaluation instant for lifecycle decisions.
	 *
	 * @param string|null $now Candidate instant (ISO-8601); null means now.
	 */
	private static function evaluation_instant( ?string $now ): string {
		$text = is_string( $now ) ? trim( $now ) : '';
		return '' === $text ? gmdate( 'c' ) : $text;
	}

	/**
	 * Shapes one search result payload.
	 *
	 * @param array<int, array<string, mixed>> $items    Page of matched rows.
	 * @param int                              $total    Total matched rows.
	 * @param int                              $page     Requested page, one-based.
	 * @param int                              $per_page Results per page.
	 * @param string|null                      $error    Boundary violation, when any.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int, error: string|null}
	 */
	private static function result( array $items, int $total, int $page, int $per_page, ?string $error ): array {
		return array(
			'items'    => array_values( $items ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => 0 === $total ? 0 : (int) ceil( $total / $per_page ),
			'error'    => $error,
		);
	}

	/**
	 * Normalizes a list of exact identifiers.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<int, string>
	 */
	private static function normalized_list( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$list = array();
		foreach ( $value as $item ) {
			$normalized = self::normalize( self::text( $item ) );
			if ( '' !== $normalized ) {
				$list[] = $normalized;
			}
		}
		return $list;
	}

	/**
	 * Converts boundary input to string.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function text( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * Converts boundary input to int.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function number( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
