<?php
/**
 * Research-first homepage helpers.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use LPS\ContentModel\Translations;
use WP_Post;

/** Owns homepage journeys, feature eligibility, and media fallback. */
final class Homepage {
	/**
	 * Returns three locale-specific journeys.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<int, array{page_key: string, label: string, url: string}>
	 */
	public static function journeys( string $locale ): array {
		$english = 'en' === $locale;
		if ( $english ) {
			return array(
				array(
					'page_key' => 'opportunities',
					'label'    => 'Join LPS',
					'url'      => '/en/opportunities/',
				),
				array(
					'page_key' => 'collaborate',
					'label'    => 'Collaborate',
					'url'      => '/en/collaborate/',
				),
				array(
					'page_key' => 'infrastructure',
					'label'    => 'Partner',
					'url'      => '/en/infrastructure/',
				),
			);
		}
		return array(
			array(
				'page_key' => 'opportunities',
				'label'    => 'Junte-se ao LPS',
				'url'      => '/pt-br/oportunidades/',
			),
			array(
				'page_key' => 'collaborate',
				'label'    => 'Colabore',
				'url'      => '/pt-br/colabore/',
			),
			array(
				'page_key' => 'infrastructure',
				'label'    => 'Seja parceiro',
				'url'      => '/pt-br/infraestrutura/',
			),
		);
	}

	/**
	 * Checks whether a record may appear as a homepage feature.
	 *
	 * @param array<mixed, mixed> $record Record fields.
	 * @param string              $locale Requested locale slug.
	 * @param string              $today  Current date in YYYY-MM-DD.
	 */
	public static function eligible_feature( array $record, string $locale, string $today ): bool {
		if ( ( $record['status'] ?? '' ) !== 'publish' ) {
			return false;
		}
		if ( ( $record['state'] ?? '' ) !== 'published' ) {
			return false;
		}
		if ( ( $record['locale'] ?? '' ) !== $locale ) {
			return false;
		}
		if ( '' === trim( self::text( $record['source_id'] ?? '' ) ) ) {
			return false;
		}
		if ( ( $record['stale'] ?? true ) ) {
			return false;
		}
		$until = trim( self::text( $record['featured_until'] ?? '' ) );
		if ( '' !== $until && $until < $today ) {
			return false;
		}
		return true;
	}

	/**
	 * Selects up to three explicit features ordered by feature_order.
	 *
	 * @param array<int, mixed> $records Candidate records.
	 * @param string            $locale  Requested locale slug.
	 * @param string            $today   Current date in YYYY-MM-DD.
	 * @return array<int, array<mixed, mixed>>
	 */
	public static function select_features( array $records, string $locale, string $today ): array {
		$eligible = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( isset( $record['feature_order'] ) && self::eligible_feature( $record, $locale, $today ) ) {
				$eligible[] = $record;
			}
		}
		usort(
			$eligible,
			static function ( array $left, array $right ): int {
				return self::num( $left['feature_order'] ) <=> self::num( $right['feature_order'] );
			}
		);
		return array_slice( $eligible, 0, 3 );
	}

	/**
	 * Renders feature media or an accessible no-image fallback.
	 *
	 * @param array<string, mixed> $record Record with title and image.
	 * @param string               $locale Supported locale slug.
	 */
	public static function feature_media_markup( array $record, string $locale ): string {
		$image = $record['image'] ?? null;
		if ( is_string( $image ) && '' !== trim( $image ) ) {
			$title = self::text( $record['title'] ?? '' );
			return '<figure class="lps-feature-media"><img src="' . self::escape( $image ) . '" alt="' . self::escape( $title ) . '"></figure>';
		}
		$english  = 'en' === $locale;
		$fallback = $english ? 'Image not published' : 'Imagem não publicada';
		$title    = trim( self::text( $record['title'] ?? '' ) );
		$label    = '' !== $title ? $fallback . ': ' . $title : $fallback;
		return '<div class="lps-feature-media-fallback" role="img" aria-label="' . self::escape( $label ) . '">' . self::escape( $fallback ) . '</div>';
	}

	/**
	 * Renders one locked section using the queried CMS homepage.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		$home_id = get_queried_object_id();
		$locale  = str_starts_with( get_locale(), 'en' ) ? 'en' : 'pt-br';
		$today   = current_time( 'Y-m-d' );
		// All ten blocks share one request-local snapshot, never a persistent stale cache.
		/**
		 * Request-local snapshot cache shared by the ten locked sections.
		 *
		 * @var array<string, array<int, array<string, mixed>>> $snapshots
		 */
		static $snapshots = array();
		$key              = $home_id . ':' . $locale . ':' . $today;
		if ( ! isset( $snapshots[ $key ] ) ) {
			$snapshots[ $key ] = self::cms_records( $home_id, $locale, $today );
		}
		return self::section_markup( self::text( $attributes['section'] ?? '' ), $locale, $snapshots[ $key ], $today );
	}

	/**
	 * Reads explicit, public selections from the Portuguese authority.
	 *
	 * @param int    $home_id Current homepage ID.
	 * @param string $locale Current locale.
	 * @param string $today  Institutional date.
	 * @return array<int, array<string, mixed>>
	 */
	public static function cms_records( int $home_id, string $locale, string $today ): array {
		if ( ! class_exists( Translations::class ) || 'home' !== get_post_meta( $home_id, '_lps_page_key', true ) ) {
			return array();
		}
		$home = self::cms_record( $home_id );
		if ( ! self::reviewed_feature( $home, $locale, $today ) ) {
			return array();
		}
		$home['section'] = 'mission';
		$records         = array( $home );
		$seen            = array();
		foreach ( Translations::relationships_for( $home_id, 'related_record' ) as $selection ) {
			$role = self::text( $selection['relationship_role'] ?? '' );
			if ( empty( $selection['public_visibility'] ) || ! in_array( $role, array( 'featured', 'context' ), true ) ) {
				continue;
			}
			$target_id = self::num( $selection['target_post_id'] ?? 0 );
			$target_id = Translations::locale( $target_id ) === $locale ? $target_id : ( Translations::variants( $target_id )[ $locale ] ?? 0 );
			if ( 0 === $target_id || isset( $seen[ $target_id ] ) ) {
				continue;
			}
			$record                   = self::cms_record( $target_id );
			$record['feature_order']  = self::num( $selection['sort_order'] ?? 0 );
			$record['featured_from']  = self::text( $selection['start_date'] ?? '' );
			$end                      = self::text( $selection['end_date'] ?? '' );
			$until                    = self::text( $record['featured_until'] ?? '' );
			$record['featured_until'] = '' === $until ? $end : ( '' === $end ? $until : min( $until, $end ) );
			$record['section']        = 'context' === $role ? 'evidence' : self::record_section( $record );
			if ( self::reviewed_feature( $record, $locale, $today ) ) {
				$seen[ $target_id ] = true;
				$records[]          = $record;
			}
		}
		return $records;
	}

	/**
	 * Adapts governed post fields without copying bodies or unreviewed media.
	 *
	 * @param int $post_id Localized record ID.
	 * @return array<string, mixed>
	 */
	private static function cms_record( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}
		$meta = array();
		foreach ( array( 'record_id', 'import_source_id', 'import_review_state', 'state', 'review_date', 'page_key', 'canonical_task', 'public_profile', 'featured_until', 'ends_at', 'event_status', 'canonical_date', 'publication_date' ) as $field ) {
			$meta[ '_lps_' . $field ] = get_post_meta( $post_id, '_lps_' . $field, true );
		}
		$meta   = Translations::merge_shared_meta( $post->post_type, $post_id, $meta );
		$locale = Translations::locale( $post_id );
		$source = Translations::source_id( $post_id );
		$valid  = null !== $source && 'publish' === get_post_status( $source ) && 'published' === get_post_meta( $source, '_lps_state', true );
		if ( 'lps_organization' === $post->post_type && empty( $meta['_lps_public_profile'] ) ) {
			$valid = false;
		}
		if ( 'lps_event' === $post->post_type && in_array( $meta['_lps_event_status'], array( 'cancelled', 'postponed' ), true ) ) {
			$valid = false;
		}
		return array(
			'type'           => $post->post_type,
			'title'          => $post->post_title,
			'summary'        => $post->post_excerpt,
			'url'            => get_permalink( $post_id ),
			'record_id'      => $meta['_lps_record_id'],
			'source_id'      => $meta['_lps_import_source_id'],
			'status'         => $post->post_status,
			'state'          => $meta['_lps_state'],
			'locale'         => $locale,
			'stale'          => 'en' === $locale && Translations::is_stale( $post_id ),
			'reviewed'       => $valid && 'reviewed' === $meta['_lps_import_review_state'],
			'review_date'    => $meta['_lps_review_date'],
			'page_key'       => 'collaboration' === $meta['_lps_page_key'] ? 'collaborate' : $meta['_lps_page_key'],
			'cta'            => $meta['_lps_canonical_task'],
			'featured_until' => 'lps_event' === $post->post_type ? substr( self::text( $meta['_lps_ends_at'] ), 0, 10 ) : $meta['_lps_featured_until'],
			'date'           => self::text( 'lps_publication' === $post->post_type ? $meta['_lps_publication_date'] : $meta['_lps_canonical_date'] ),
		);
	}

	/**
	 * Maps record types and stable page keys to the frozen composition.
	 *
	 * @param array<string, mixed> $record CMS record.
	 */
	private static function record_section( array $record ): string {
		return match ( $record['type'] ?? '' ) {
			'lps_research_area' => 'research',
			'lps_project' => 'projects',
			'lps_person' => 'people',
			'lps_publication', 'lps_news', 'lps_event' => 'latest',
			'lps_organization' => 'partners',
			'page' => match ( $record['page_key'] ?? '' ) {
				'opportunities', 'collaborate' => 'journeys',
				'infrastructure' => 'infrastructure',
				'contact' => 'contact',
				default => '',
			},
			default => '',
		};
	}

	/**
	 * Requires provenance, current review, matching locale and a safe record link.
	 *
	 * @param array<string, mixed> $record CMS record.
	 * @param string               $locale Requested locale.
	 * @param string               $today Current date.
	 */
	private static function reviewed_feature( array $record, string $locale, string $today ): bool {
		$url  = self::text( $record['url'] ?? '' );
		$from = self::text( $record['featured_from'] ?? '' );
		return self::eligible_feature( $record, $locale, $today )
			&& true === ( $record['reviewed'] ?? false )
			&& self::text( $record['review_date'] ?? '' ) >= $today
			&& '' !== trim( self::text( $record['title'] ?? '' ) )
			&& ( '' === $from || $from <= $today )
			&& 1 === preg_match( '~^(?:https?://[^/]+)?/' . $locale . '/~', $url );
	}

	/**
	 * Builds a reusable, localized section. Missing media uses a text-only layout.
	 *
	 * @param string                           $section Locked section key.
	 * @param string                           $locale Supported locale.
	 * @param array<int, array<string, mixed>> $records CMS snapshot.
	 * @param string                           $today Institutional date.
	 */
	public static function section_markup( string $section, string $locale, array $records, string $today ): string {
		$sections = array(
			'mission'        => array( '<section data-home-section="mission"', 'LPS', 'LPS' ),
			'research'       => array( '<section data-home-section="research"', 'Research themes', 'Temas de pesquisa' ),
			'evidence'       => array( '<section data-home-section="evidence"', 'Evidence and outputs', 'Evidências e resultados' ),
			'projects'       => array( '<section data-home-section="projects"', 'Featured projects', 'Projetos em destaque' ),
			'journeys'       => array( '<section data-home-section="journeys"', 'Take part in LPS', 'Participe do LPS' ),
			'people'         => array( '<section data-home-section="people"', 'People and research', 'Pessoas e pesquisa' ),
			'infrastructure' => array( '<section data-home-section="infrastructure"', 'Infrastructure and capabilities', 'Infraestrutura e capacidades' ),
			'latest'         => array( '<section data-home-section="latest"', 'Publications, news and events', 'Publicações, notícias e eventos' ),
			'partners'       => array( '<section data-home-section="partners"', 'Partners and funders', 'Parceiros e financiadores' ),
			'contact'        => array( '<section data-home-section="contact"', 'Collaboration and contact', 'Colaboração e contato' ),
		);
		if ( ! isset( $sections[ $section ] ) ) {
			return '';
		}
		$english = 'en' === $locale;
		$heading = $sections[ $section ][ $english ? 1 : 2 ];
		$html    = $sections[ $section ][0] . ' class="lps-home-section" aria-labelledby="lps-home-' . $section . '">';
		$items   = array_values( array_filter( $records, static fn( array $record ): bool => self::reviewed_feature( $record, $locale, $today ) ) );
		if ( 'journeys' === $section ) {
			$html .= '<p class="lps-home-kicker" aria-hidden="true">05</p><h2 id="lps-home-journeys">' . self::escape( $heading ) . '</h2><ul class="lps-home-journeys">';
			$index = 0;
			foreach ( self::journeys( $locale ) as $journey ) {
				$index  ++;
				$matches = array_values( array_filter( $items, static fn( array $record ): bool => ( $record['page_key'] ?? '' ) === $journey['page_key'] && '' !== trim( self::text( $record['cta'] ?? '' ) ) ) );
				$html   .= '<li>';
				$html   .= '<span class="lps-journey-index" aria-hidden="true">' . str_pad( (string) $index, 2, '0', STR_PAD_LEFT ) . '</span>';
				$html   .= '<h3 class="lps-journey-label">' . self::escape( $journey['label'] ) . '</h3>';
				if ( isset( $matches[0] ) ) {
					$record = $matches[0];
					$html  .= '<a class="lps-button" data-home-journey="' . $journey['page_key'] . '" data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '" href="' . self::escape( self::text( $record['url'] ) ) . '">' . self::escape( self::text( $record['cta'] ) ) . '</a>';
				} else {
					$html .= '<p class="lps-journey-note" aria-disabled="true">' . ( $english ? 'Information not published' : 'Informações não publicadas' ) . '</p>';
				}
				$html .= '</li>';
			}
			return $html . '</ul></section>';
		}
		$items = array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === $section ) );
		if ( 'latest' === $section ) {
			usort( $items, static fn( array $left, array $right ): int => strcmp( self::text( $right['date'] ?? '' ), self::text( $left['date'] ?? '' ) ) );
		}
		$items = 'projects' === $section ? self::select_features( $items, $locale, $today ) : array_slice( $items, 0, 'mission' === $section || 'contact' === $section ? 1 : 4 );
		if ( 'mission' === $section && isset( $items[0] ) ) {
			$heading = self::text( $items[0]['title'] );
			$html    = str_replace( ' class="lps-home-section"', ' data-source-id="' . self::escape( self::text( $items[0]['source_id'] ) ) . '" class="lps-home-section"', $html );
		}
		$level   = 'mission' === $section ? 'h1' : 'h2';
		$numbers = array(
			'research'       => '02',
			'evidence'       => '03',
			'projects'       => '04',
			'people'         => '06',
			'infrastructure' => '07',
			'latest'         => '08',
			'partners'       => '09',
			'contact'        => '10',
		);
		if ( isset( $numbers[ $section ] ) ) {
			$html .= '<p class="lps-home-kicker" aria-hidden="true">' . $numbers[ $section ] . '</p>';
		}
		$html .= '<' . $level . ' id="lps-home-' . $section . '">' . self::escape( $heading ) . '</' . $level . '>';
		if ( 'mission' === $section ) {
			$html .= '<p class="lps-mission-meta"' . ( $english ? '' : ' lang="pt-BR"' ) . '>' . ( $english ? 'Founded in 1996 at the Federal University of Rio de Janeiro — UFRJ / COPPE' : 'Fundado em 1996 na Universidade Federal do Rio de Janeiro — UFRJ / COPPE' ) . '</p>';
		}
		if ( array() === $items ) {
			return $html . '<p data-home-empty="' . $section . '">' . ( $english ? 'Reviewed information has not been published for this section.' : 'Informações revisadas ainda não foram publicadas nesta seção.' ) . '</p></section>';
		}
		$html .= '<div class="lps-records">';
		foreach ( $items as $record ) {
			$html .= '<article class="lps-record" data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '" data-record-id="' . self::escape( self::text( $record['record_id'] ?? '' ) ) . '">';
			if ( 'mission' !== $section ) {
				$html .= '<h3><a href="' . self::escape( self::text( $record['url'] ) ) . '">' . self::escape( self::text( $record['title'] ) ) . '</a></h3>';
			}
			$html .= '<p>' . self::escape( self::text( $record['summary'] ?? '' ) ) . '</p></article>';
		}
		return $html . '</div></section>';
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
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return (string) $value;
		}
		return '';
	}

	/**
	 * Converts boundary input to int.
	 *
	 * @param mixed $value Boundary input.
	 */
	private static function num( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Escapes one text value for markup.
	 *
	 * @param string $value Untrusted text value.
	 */
	private static function escape( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
