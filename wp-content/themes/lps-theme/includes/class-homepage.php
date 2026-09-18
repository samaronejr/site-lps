<?php
/**
 * Research-first homepage helpers.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use LPS\ContentModel\Media;
use LPS\ContentModel\MediaPolicy;
use LPS\ContentModel\PublicationPolicy;
use LPS\ContentModel\PublicationRecords;
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
	 * The eligibility condition is the plugin-owned
	 * `PublicationPolicy::visibility_decision()` on the `feature` surface — the
	 * same decision search, feeds, and previews evaluate — never a homepage
	 * reinvention. A legitimately reviewed native record is eligible without
	 * import fields; an unreviewed imported or ambiguous record is not.
	 *
	 * @param array<mixed, mixed> $record Record fields.
	 * @param string              $locale Requested locale slug.
	 * @param string              $today  Current date in YYYY-MM-DD.
	 */
	public static function eligible_feature( array $record, string $locale, string $today ): bool {
		if ( ! class_exists( PublicationPolicy::class ) ) {
			return false;
		}
		return PublicationPolicy::visibility_decision( $record, PublicationPolicy::SURFACE_FEATURE, $locale, $today )['visible'];
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
	 * Renders a record's governed image or an accessible no-image fallback.
	 *
	 * Records expose imagery through the locked media blocks, resolved by
	 * `Media::record_image()` into a usage/asset pair. The usage must still pass
	 * `MediaPolicy::usage_errors()` — localized alt, caption, context, credit,
	 * rights holder, license, checksum, cleared rights and privacy review — so
	 * an attachment whose rights lapse after publication fails closed. The
	 * homepage owns placement: the mission feature is the single hero (eager,
	 * high priority) and every other slot renders as content (lazy), keeping
	 * exactly one LCP candidate per page.
	 *
	 * @param array<string, mixed> $record    Record with a resolved media pair.
	 * @param string               $locale    Supported locale slug.
	 * @param string               $placement Slot placement: hero or content.
	 */
	public static function feature_media_markup( array $record, string $locale, string $placement = 'content' ): string {
		$media = $record['media'] ?? array();
		$usage = is_array( $media ) && is_array( $media['usage'] ?? null ) ? $media['usage'] : array();
		$asset = is_array( $media ) && is_array( $media['asset'] ?? null ) ? $media['asset'] : array();
		if ( array() !== $usage && class_exists( MediaPolicy::class ) ) {
			$usage['locale']    = $locale;
			$usage['placement'] = 'hero' === $placement ? 'hero' : 'content';
			$usage['block']     = 'image';
			if ( array() === MediaPolicy::usage_errors( $usage, $asset ) ) {
				return MediaPolicy::render_image( $usage, $asset );
			}
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
	 * Decision inputs come from `PublicationRecords::publication_record()`,
	 * which reads provenance from the authoritative record; display fields are
	 * merged on top for the homepage's own composition.
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
		$meta     = Translations::merge_shared_meta( $post->post_type, $post_id, $meta );
		$decision = class_exists( PublicationRecords::class ) ? PublicationRecords::publication_record( $post ) : array();
		$locale   = self::text( $decision['locale'] ?? Translations::locale( $post_id ) );
		return array_merge(
			$meta,
			$decision,
			array(
				'type'           => $post->post_type,
				'title'          => $post->post_title,
				'summary'        => $post->post_excerpt,
				'url'            => get_permalink( $post_id ),
				'record_id'      => $meta['_lps_record_id'],
				'source_id'      => class_exists( PublicationPolicy::class ) ? PublicationPolicy::provenance_id( array_merge( $meta, $decision ) ) : '',
				'status'         => $post->post_status,
				'state'          => $meta['_lps_state'],
				'locale'         => $locale,
				'stale'          => ! empty( $decision['stale'] ),
				'review_date'    => $meta['_lps_review_date'],
				'page_key'       => 'collaboration' === $meta['_lps_page_key'] ? 'collaborate' : $meta['_lps_page_key'],
				'cta'            => $meta['_lps_canonical_task'],
				'featured_until' => 'lps_event' === $post->post_type ? substr( self::text( $meta['_lps_ends_at'] ), 0, 10 ) : $meta['_lps_featured_until'],
				'date'           => self::text( 'lps_publication' === $post->post_type ? $meta['_lps_publication_date'] : $meta['_lps_canonical_date'] ),
				'media'          => Media::record_image( $post ),
			)
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
	 * Requires the unified feature decision plus a titled, locale-safe link.
	 *
	 * Provenance, review currency, ownership, claim verification, and the
	 * feature window are all evaluated inside
	 * `PublicationPolicy::visibility_decision()`; the homepage adds only its
	 * own presentation constraints (a non-empty title and a URL on the
	 * requested locale route).
	 *
	 * @param array<string, mixed> $record CMS record.
	 * @param string               $locale Requested locale.
	 * @param string               $today Current date.
	 */
	private static function reviewed_feature( array $record, string $locale, string $today ): bool {
		$url = self::text( $record['url'] ?? '' );
		return self::eligible_feature( $record, $locale, $today )
			&& '' !== trim( self::text( $record['title'] ?? '' ) )
			&& 1 === preg_match( '~^(?:https?://[^/]+)?/' . $locale . '/~', $url );
	}

	/**
	 * Builds one visual module of the §7 composition. The ten frozen data
	 * sections map onto eight modules: evidence renders inside research and
	 * contact inside the partners handoff; the other eight map one-to-one.
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
			return self::journeys_module( $html, $heading, $items, $locale );
		}
		if ( 'mission' === $section ) {
			return self::mission_module( $html, $items, $locale );
		}
		if ( 'research' === $section ) {
			return self::research_module( $html, $heading, $items, $locale, $sections['evidence'][ $english ? 1 : 2 ] );
		}
		if ( 'partners' === $section ) {
			return self::partners_module( $html, $heading, $items, $locale, $sections['contact'][ $english ? 1 : 2 ] );
		}
		$items = array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === $section ) );
		if ( array() === $items ) {
			// Sparse policy: optional modules are omitted entirely; only the
			// required contact close keeps a single compact notice in place.
			return 'contact' === $section ? $html . '<h2 id="lps-home-' . $section . '">' . self::escape( $heading ) . '</h2>' . self::empty_notice( 'contact', $locale ) . '</section>' : '';
		}
		if ( 'latest' === $section ) {
			usort( $items, static fn( array $left, array $right ): int => strcmp( self::text( $right['date'] ?? '' ), self::text( $left['date'] ?? '' ) ) );
		}
		$items      = 'projects' === $section ? self::select_features( $items, $locale, $today ) : array_slice( $items, 0, 'contact' === $section ? 1 : 4 );
		$html      .= '<h2 id="lps-home-' . $section . '">' . self::escape( $heading ) . '</h2>';
		$media_slot = in_array( $section, array( 'projects', 'people', 'infrastructure' ), true );
		foreach ( $items as $record ) {
			$meta  = 'latest' === $section ? self::dated_meta( $record, $locale ) : '';
			$html .= self::record_markup( $record, $locale, $meta, 'h3', $media_slot );
		}
		return $html . '</section>';
	}

	/**
	 * Renders the mission feature: H1 statement, deck, governed action and media.
	 *
	 * @param string                           $html    Opened section tag.
	 * @param array<int, array<string, mixed>> $items   Reviewed snapshot.
	 * @param string                           $locale  Supported locale.
	 */
	private static function mission_module( string $html, array $items, string $locale ): string {
		$items = array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'mission' ) ), 0, 1 );
		if ( ! isset( $items[0] ) ) {
			return $html . '<h1 id="lps-home-mission">LPS</h1>' . self::empty_notice( 'mission', $locale ) . '</section>';
		}
		$record  = $items[0];
		$html    = str_replace( ' class="lps-home-section"', ' data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '" class="lps-home-section"', $html );
		$html   .= '<article class="lps-record" data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '" data-record-id="' . self::escape( self::text( $record['record_id'] ?? '' ) ) . '">';
		$html   .= '<h1 id="lps-home-mission">' . self::escape( self::text( $record['title'] ) ) . '</h1>';
		$summary = trim( self::text( $record['summary'] ?? '' ) );
		if ( '' !== $summary ) {
			$html .= '<p>' . self::escape( $summary ) . '</p>';
		}
		$cta = trim( self::text( $record['cta'] ?? '' ) );
		if ( '' !== $cta ) {
			$html .= '<p><a class="lps-button" href="' . self::escape( self::text( $record['url'] ) ) . '">' . self::escape( $cta ) . '</a></p>';
		}
		return $html . '</article>' . self::feature_media_markup( $record, $locale, 'hero' ) . '</section>';
	}

	/**
	 * Renders the three audience journeys as one action band.
	 *
	 * @param string                           $html    Opened section tag.
	 * @param string                           $heading Localized module heading.
	 * @param array<int, array<string, mixed>> $items   Reviewed snapshot.
	 * @param string                           $locale  Supported locale.
	 */
	private static function journeys_module( string $html, string $heading, array $items, string $locale ): string {
		$english = 'en' === $locale;
		$html   .= '<h2 id="lps-home-journeys">' . self::escape( $heading ) . '</h2><ul class="lps-home-journeys">';
		foreach ( self::journeys( $locale ) as $journey ) {
			$matches = array_values( array_filter( $items, static fn( array $record ): bool => ( $record['page_key'] ?? '' ) === $journey['page_key'] && '' !== trim( self::text( $record['cta'] ?? '' ) ) ) );
			$html   .= '<li><span class="lps-journey-label">' . self::escape( $journey['label'] ) . '</span>';
			if ( isset( $matches[0] ) ) {
				$record = $matches[0];
				$html  .= '<a class="lps-button" data-home-journey="' . $journey['page_key'] . '" data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '" href="' . self::escape( self::text( $record['url'] ) ) . '">' . self::escape( self::text( $record['cta'] ) ) . '</a>';
			} else {
				$html .= '<span aria-disabled="true">' . ( $english ? 'Information not published' : 'Informações não publicadas' ) . '</span>';
			}
			$html .= '</li>';
		}
		return $html . '</ul></section>';
	}

	/**
	 * Renders research themes with the evidence stratum nested in one module.
	 *
	 * @param string                           $html             Opened section tag.
	 * @param string                           $heading          Localized module heading.
	 * @param array<int, array<string, mixed>> $items            Reviewed snapshot.
	 * @param string                           $locale           Supported locale.
	 * @param string                           $evidence_heading Localized stratum heading.
	 */
	private static function research_module( string $html, string $heading, array $items, string $locale, string $evidence_heading ): string {
		$themes   = array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'research' ) ), 0, 4 );
		$evidence = array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'evidence' ) ), 0, 4 );
		if ( array() === $themes && array() === $evidence ) {
			return '';
		}
		if ( array() === $themes ) {
			// Evidence alone carries the module: promote its stratum heading to
			// h2 so the section keeps a valid name and sequential headings.
			$html  = str_replace( 'aria-labelledby="lps-home-research"', 'aria-labelledby="lps-home-evidence"', $html );
			$html .= '<section class="lps-home-stratum" data-home-section="evidence" aria-labelledby="lps-home-evidence"><h2 class="lps-kicker" id="lps-home-evidence">' . self::escape( $evidence_heading ) . '</h2>';
			foreach ( $evidence as $record ) {
				$html .= self::record_markup( $record, $locale, self::provenance_meta( $record, $locale ), 'h3' );
			}
			return $html . '</section></section>';
		}
		$html .= '<h2 id="lps-home-research">' . self::escape( $heading ) . '</h2>';
		foreach ( $themes as $record ) {
			$html .= self::record_markup( $record, $locale );
		}
		if ( array() === $evidence ) {
			return $html . '</section>';
		}
		$html .= '<section class="lps-home-stratum" data-home-section="evidence" aria-labelledby="lps-home-evidence"><h3 class="lps-kicker" id="lps-home-evidence">' . self::escape( $evidence_heading ) . '</h3>';
		foreach ( $evidence as $record ) {
			$html .= self::record_markup( $record, $locale, self::provenance_meta( $record, $locale ), 'h4' );
		}
		return $html . '</section></section>';
	}

	/**
	 * Renders partner rows with the contact handoff nested as the closing strip.
	 *
	 * @param string                           $html            Opened section tag.
	 * @param string                           $heading         Localized module heading.
	 * @param array<int, array<string, mixed>> $items           Reviewed snapshot.
	 * @param string                           $locale          Supported locale.
	 * @param string                           $contact_heading Localized stratum heading.
	 */
	private static function partners_module( string $html, string $heading, array $items, string $locale, string $contact_heading ): string {
		$partners = array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'partners' ) ), 0, 4 );
		$contact  = array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'contact' ) ), 0, 1 );
		// The contact handoff is required, so this module always renders; the
		// optional partner rows and their heading are omitted when empty.
		if ( array() === $partners ) {
			$html = str_replace( 'aria-labelledby="lps-home-partners"', 'aria-labelledby="lps-home-contact"', $html );
		} else {
			$html .= '<h2 id="lps-home-partners">' . self::escape( $heading ) . '</h2>';
			foreach ( $partners as $record ) {
				$html .= self::record_markup( $record, $locale );
			}
		}
		$level = array() === $partners ? 'h2' : 'h3';
		$html .= '<section class="lps-home-stratum lps-home-handoff" data-home-section="contact" aria-labelledby="lps-home-contact"><' . $level . ' class="lps-kicker" id="lps-home-contact">' . self::escape( $contact_heading ) . '</' . $level . '>';
		if ( ! isset( $contact[0] ) ) {
			return $html . self::empty_notice( 'contact', $locale ) . '</section></section>';
		}
		$record  = $contact[0];
		$summary = trim( self::text( $record['summary'] ?? '' ) );
		if ( '' !== $summary ) {
			$html .= '<p>' . self::escape( $summary ) . '</p>';
		}
		$label = trim( self::text( $record['cta'] ?? '' ) );
		$label = '' !== $label ? $label : self::text( $record['title'] );
		$html .= '<p><a class="lps-button" data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '" href="' . self::escape( self::text( $record['url'] ) ) . '">' . self::escape( $label ) . '</a></p>';
		return $html . '</section></section>';
	}

	/**
	 * Renders one record row with provenance attributes and optional metadata.
	 *
	 * Rows in the media-slot sections (projects, people, infrastructure) render
	 * the record's governed image when one was referenced; a referenced image
	 * that fails rights or accessibility review renders the labeled fallback,
	 * while a record that references no image stays text-only.
	 *
	 * @param array<string, mixed> $record     CMS record.
	 * @param string               $locale     Supported locale.
	 * @param string               $meta       Pre-built metadata markup.
	 * @param string               $level      Heading level for the linked title.
	 * @param bool                 $media_slot Whether this row carries an image slot.
	 */
	private static function record_markup( array $record, string $locale, string $meta = '', string $level = 'h3', bool $media_slot = false ): string {
		$html = '<article class="lps-record" data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '" data-record-id="' . self::escape( self::text( $record['record_id'] ?? '' ) ) . '">';
		if ( $media_slot && ! empty( $record['media'] ) ) {
			$html .= self::feature_media_markup( $record, $locale );
		}
		$html   .= $meta;
		$html   .= '<' . $level . '><a href="' . self::escape( self::text( $record['url'] ) ) . '">' . self::escape( self::text( $record['title'] ) ) . '</a></' . $level . '>';
		$summary = trim( self::text( $record['summary'] ?? '' ) );
		if ( '' !== $summary ) {
			$html .= '<p>' . self::escape( $summary ) . '</p>';
		}
		return $html . '</article>';
	}

	/**
	 * Builds the date-plus-type metadata line of a dated record row.
	 *
	 * @param array<string, mixed> $record CMS record.
	 * @param string               $locale Supported locale.
	 */
	private static function dated_meta( array $record, string $locale ): string {
		// Canonical news dates carry a time; the row prints the ISO day so every
		// dated record in the module shares the publication row's format.
		$date = substr( trim( self::text( $record['date'] ?? '' ) ), 0, 10 );
		$type = self::type_label( self::text( $record['type'] ?? '' ), $locale );
		$html = '';
		if ( '' !== $date ) {
			$html .= '<time datetime="' . self::escape( $date ) . '">' . self::escape( $date ) . '</time>';
		}
		if ( '' !== $type ) {
			$html .= ( '' === $html ? '' : ' · ' ) . '<span class="lps-kicker">' . self::escape( $type ) . '</span>';
		}
		return '' === $html ? '' : '<p class="lps-meta">' . $html . '</p>';
	}

	/**
	 * Builds the type-plus-review-date provenance line of an evidence record.
	 *
	 * @param array<string, mixed> $record CMS record.
	 * @param string               $locale Supported locale.
	 */
	private static function provenance_meta( array $record, string $locale ): string {
		$type     = self::type_label( self::text( $record['type'] ?? '' ), $locale );
		$reviewed = trim( self::text( $record['review_date'] ?? '' ) );
		$html     = '';
		if ( '' !== $type ) {
			$html .= '<span class="lps-kicker">' . self::escape( $type ) . '</span>';
		}
		if ( '' !== $reviewed ) {
			$label = 'en' === $locale ? 'Last reviewed' : 'Última revisão';
			$html .= ( '' === $html ? '' : ' · ' ) . $label . ': <time datetime="' . self::escape( $reviewed ) . '">' . self::escape( $reviewed ) . '</time>';
		}
		return '' === $html ? '' : '<p class="lps-meta">' . $html . '</p>';
	}

	/**
	 * Returns the localized record-type label used by homepage kickers.
	 *
	 * @param string $type   Record post type.
	 * @param string $locale Supported locale.
	 */
	private static function type_label( string $type, string $locale ): string {
		$labels = array(
			'lps_research_area' => array( 'Pesquisa', 'Research' ),
			'lps_project'       => array( 'Projetos', 'Projects' ),
			'lps_publication'   => array( 'Publicações', 'Publications' ),
			'lps_person'        => array( 'Pessoas', 'People' ),
			'lps_news'          => array( 'Notícias', 'News' ),
			'lps_event'         => array( 'Eventos', 'Events' ),
			'lps_opportunity'   => array( 'Oportunidades', 'Opportunities' ),
			'lps_organization'  => array( 'Organizações', 'Organizations' ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ][ 'en' === $locale ? 1 : 0 ] : '';
	}

	/**
	 * Returns the truthful not-published notice for a required data section.
	 *
	 * Only the mission feature and the contact handoff may render this notice;
	 * every other module or stratum is omitted entirely when it has no
	 * reviewed records, so a sparse homepage never repeats empty panels.
	 *
	 * @param string $section Locked section key.
	 * @param string $locale  Supported locale.
	 */
	private static function empty_notice( string $section, string $locale ): string {
		$message = 'en' === $locale
			? 'Reviewed information has not been published for this section.'
			: 'Informações revisadas ainda não foram publicadas nesta seção.';
		return '<p data-home-empty="' . $section . '">' . $message . '</p>';
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
