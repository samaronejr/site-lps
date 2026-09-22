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
	 * Returns the two primary links pinned to the mission module.
	 *
	 * These are the plan's first-viewport actions: they are informational
	 * entrances, not CMS features, so they render even when the mission record
	 * itself is absent.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<int, array{key: string, label: string, url: string}>
	 */
	public static function primary_links( string $locale ): array {
		if ( 'en' === $locale ) {
			return array(
				array(
					'key'   => 'research',
					'label' => 'Explore the research',
					'url'   => '/en/research/',
				),
				array(
					'key'   => 'teaching',
					'label' => 'Courses and materials',
					'url'   => '/en/teaching/',
				),
			);
		}
		return array(
			array(
				'key'   => 'research',
				'label' => 'Conheça a pesquisa',
				'url'   => '/pt-br/pesquisa/',
			),
			array(
				'key'   => 'teaching',
				'label' => 'Disciplinas e materiais',
				'url'   => '/pt-br/ensino/',
			),
		);
	}

	/**
	 * Checks whether a record may appear as a homepage feature.
	 *
	 * The eligibility condition is the plugin-owned
	 * `PublicationPolicy::visibility_decision()` on the `feature` surface — the
	 * same origin-aware decision search, feeds, and previews evaluate — never a
	 * homepage reinvention. A legitimately reviewed native record is eligible
	 * without import fields; an unreviewed imported or ambiguous record is not.
	 *
	 * @param array<mixed, mixed> $record Record fields.
	 * @param string              $locale Requested locale slug.
	 * @param string              $today  Current date in YYYY-MM-DD.
	 */
	public static function eligible_feature( array $record, string $locale, string $today ): bool {
		if ( ! class_exists( PublicationPolicy::class ) ) {
			return false;
		}
		return PublicationPolicy::visibility_decision( self::string_keyed( $record ), PublicationPolicy::SURFACE_FEATURE, $locale, $today )['visible'];
	}

	/**
	 * Selects up to three explicit features ordered by feature_order.
	 *
	 * @param array<int, mixed> $records Candidate records.
	 * @param string            $locale  Requested locale slug.
	 * @param string            $today   Current date in YYYY-MM-DD.
	 * @return array<int, array<string, mixed>>
	 */
	public static function select_features( array $records, string $locale, string $today ): array {
		$eligible = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$record = self::string_keyed( $record );
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
	 * Renders a record's governed image, or nothing when no image may render.
	 *
	 * Records expose imagery through the locked media blocks and through
	 * reviewed media IDs on the record, resolved by `Media::record_image()`
	 * into a usage/asset pair. The usage must still pass
	 * `MediaPolicy::usage_errors()` — localized alt, caption, context, credit,
	 * rights holder, license, checksum, cleared rights and privacy review — so
	 * an attachment whose rights lapse after publication fails closed. A
	 * record that references no image, or whose referenced image fails review,
	 * degrades to its text: the homepage never renders a broken-image box.
	 * The homepage owns placement: the mission feature is the single hero
	 * (eager, high priority) and every other slot renders as content (lazy),
	 * keeping exactly one LCP candidate per page.
	 *
	 * @param array<string, mixed> $record    Record with a resolved media pair.
	 * @param string               $locale    Supported locale slug.
	 * @param string               $placement Slot placement: hero or content.
	 */
	public static function feature_media_markup( array $record, string $locale, string $placement = 'content' ): string {
		$media = $record['media'] ?? array();
		$usage = is_array( $media ) ? self::string_keyed( $media['usage'] ?? null ) : array();
		$asset = is_array( $media ) ? self::string_keyed( $media['asset'] ?? null ) : array();
		if ( array() !== $usage && class_exists( MediaPolicy::class ) ) {
			$usage['locale']    = $locale;
			$usage['placement'] = 'hero' === $placement ? 'hero' : 'content';
			$usage['block']     = 'image';
			if ( array() === MediaPolicy::usage_errors( $usage, $asset ) ) {
				return MediaPolicy::render_image( $usage, $asset );
			}
		}
		return '';
	}

	/**
	 * Renders one locked section using the queried CMS homepage.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		$home_id = self::home_id();
		$locale  = str_starts_with( get_locale(), 'en' ) ? 'en' : 'pt-br';
		$today   = current_time( 'Y-m-d' );
		// All locked modules share one request-local snapshot, never a persistent stale cache.
		/**
		 * Request-local snapshot cache shared by the locked sections.
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
	 * Resolves the governed homepage record for this request.
	 *
	 * The queried object wins when it is the governed home page; otherwise the
	 * configured static front page is used, and finally the single published
	 * page carrying the `home` page key. The last fallback keeps the
	 * composition truthful when the language root renders the posts index
	 * (Polylang serves the static page at the language root only when its
	 * redirect options are enabled) — the template is locked to the front
	 * page, so the governed home record is always the intended source.
	 */
	private static function home_id(): int {
		$queried = get_queried_object_id();
		if ( 0 < $queried && 'home' === get_post_meta( $queried, '_lps_page_key', true ) ) {
			return $queried;
		}
		$front = self::num( get_option( 'page_on_front' ) );
		if ( 0 < $front && 'home' === get_post_meta( $front, '_lps_page_key', true ) ) {
			return $front;
		}
		foreach ( get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'numberposts' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Identity lookup requires the meta key.
				'meta_key'    => '_lps_page_key',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Identity lookup requires the meta value.
				'meta_value'  => 'home',
			)
		) as $candidate ) {
			return $candidate->ID;
		}
		return 0;
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
	 * merged on top for the homepage's own composition. The `media` pair
	 * carries the reviewed media IDs and the governed attachment metadata
	 * (dimensions, focal point, credit, rights, responsive sources) that the
	 * governed renderer consumes.
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
		foreach ( array( 'record_id', 'import_source_id', 'import_review_state', 'state', 'review_date', 'page_key', 'canonical_task', 'public_profile', 'featured_until', 'starts_at', 'ends_at', 'event_status', 'venue', 'canonical_date', 'publication_date' ) as $field ) {
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
				'date'           => self::text( 'lps_event' === $post->post_type ? $meta['_lps_starts_at'] : ( 'lps_publication' === $post->post_type ? $meta['_lps_publication_date'] : $meta['_lps_canonical_date'] ) ),
				'event_status'   => self::text( $meta['_lps_event_status'] ),
				'venue'          => self::text( $meta['_lps_venue'] ),
				'media'          => Media::record_image( $post ),
			)
		);
	}

	/**
	 * Maps record types and stable page keys to the homepage composition.
	 *
	 * The composition keeps one research module (areas plus the projects,
	 * evidence, and infrastructure strata), one differentiated latest module,
	 * one people module, the journeys band, and the partners module carrying
	 * the required contact handoff.
	 *
	 * @param array<string, mixed> $record CMS record.
	 */
	public static function record_section( array $record ): string {
		return match ( $record['type'] ?? '' ) {
			'lps_research_area' => 'research',
			'lps_project' => 'projects',
			'lps_person' => 'people',
			'lps_publication', 'lps_news', 'lps_event' => 'latest',
			'lps_organization' => 'partners',
			'page' => match ( $record['page_key'] ?? '' ) {
				'opportunities', 'collaborate', 'collaboration' => 'journeys',
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
	 * Builds one module of the homepage composition.
	 *
	 * @param string                           $section Locked section key.
	 * @param string                           $locale Supported locale.
	 * @param array<int, array<string, mixed>> $records CMS snapshot.
	 * @param string                           $today Institutional date.
	 */
	public static function section_markup( string $section, string $locale, array $records, string $today ): string {
		$sections = array(
			'mission'  => array( 'LPS', 'LPS' ),
			'research' => array( 'Research', 'Pesquisa' ),
			'latest'   => array( 'Publications, news and events', 'Publicações, notícias e eventos' ),
			'teaching' => array( 'Teaching', 'Ensino' ),
			'people'   => array( 'People', 'Pessoas' ),
			'journeys' => array( 'Take part in LPS', 'Participe do LPS' ),
			'partners' => array( 'Partners and funders', 'Parceiros e financiadores' ),
		);
		$strata   = array(
			'projects'       => array( 'Featured projects', 'Projetos em destaque' ),
			'evidence'       => array( 'Evidence and outputs', 'Evidências e resultados' ),
			'infrastructure' => array( 'Infrastructure and capabilities', 'Infraestrutura e capacidades' ),
			'contact'        => array( 'Collaboration and contact', 'Colaboração e contato' ),
		);
		if ( ! isset( $sections[ $section ] ) ) {
			return '';
		}
		$english = 'en' === $locale;
		$heading = $sections[ $section ][ $english ? 0 : 1 ];
		$html    = '<section data-home-section="' . $section . '" class="lps-home-section" aria-labelledby="lps-home-' . $section . '">';
		$items   = array_values( array_filter( $records, static fn( array $record ): bool => self::reviewed_feature( $record, $locale, $today ) ) );
		if ( 'mission' === $section ) {
			return self::mission_module( $html, $items, $locale );
		}
		if ( 'journeys' === $section ) {
			return self::journeys_module( $html, $heading, $items, $locale );
		}
		if ( 'teaching' === $section ) {
			return self::teaching_module( $html, $heading, $locale );
		}
		if ( 'research' === $section ) {
			return self::research_module( $html, $heading, $items, $locale, $today, $strata );
		}
		if ( 'partners' === $section ) {
			return self::partners_module( $html, $heading, $items, $locale, $strata['contact'][ $english ? 0 : 1 ] );
		}
		if ( 'latest' === $section ) {
			return self::latest_module( $html, $heading, $items, $locale );
		}
		return self::people_module( $html, $heading, $items, $locale );
	}

	/**
	 * Renders the mission feature: H1 statement, deck, primary links and media.
	 *
	 * The two primary links are informational entrances pinned by the plan's
	 * first-viewport contract; they render even when the mission record is
	 * absent. A missing or unreviewed feature image degrades to the text
	 * layout — never a broken-image box.
	 *
	 * @param string                           $html    Opened section tag.
	 * @param array<int, array<string, mixed>> $items   Reviewed snapshot.
	 * @param string                           $locale  Supported locale.
	 */
	private static function mission_module( string $html, array $items, string $locale ): string {
		$items = array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'mission' ) ), 0, 1 );
		if ( ! isset( $items[0] ) ) {
			return $html . '<h1 id="lps-home-mission">LPS</h1>' . self::primary_links_markup( $locale ) . self::empty_notice( 'mission', $locale ) . '</section>';
		}
		$record  = $items[0];
		$html    = str_replace( ' class="lps-home-section"', ' data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '" class="lps-home-section"', $html );
		$html   .= '<article class="lps-record" data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '" data-record-id="' . self::escape( self::text( $record['record_id'] ?? '' ) ) . '">';
		$html   .= '<h1 id="lps-home-mission">' . self::escape( self::text( $record['title'] ) ) . '</h1>';
		$summary = trim( self::text( $record['summary'] ?? '' ) );
		if ( '' !== $summary ) {
			$html .= '<p>' . self::escape( $summary ) . '</p>';
		}
		$html .= self::primary_links_markup( $locale );
		$cta   = trim( self::text( $record['cta'] ?? '' ) );
		if ( '' !== $cta ) {
			$html .= '<p><a class="lps-button" href="' . self::escape( self::text( $record['url'] ) ) . '">' . self::escape( $cta ) . '</a></p>';
		}
		return $html . '</article>' . self::feature_media_markup( $record, $locale, 'hero' ) . '</section>';
	}

	/**
	 * Renders the pinned first-viewport action links.
	 *
	 * @param string $locale Supported locale.
	 */
	private static function primary_links_markup( string $locale ): string {
		$html = '<p class="lps-home-actions">';
		foreach ( self::primary_links( $locale ) as $link ) {
			$html .= '<a class="lps-button" data-home-action="' . $link['key'] . '" href="' . self::escape( $link['url'] ) . '">' . self::escape( $link['label'] ) . '</a> ';
		}
		return $html . '</p>';
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
	 * Renders the research module: linked areas plus the projects, evidence,
	 * and infrastructure strata in one band.
	 *
	 * @param string                                     $html    Opened section tag.
	 * @param string                                     $heading Localized module heading.
	 * @param array<int, array<string, mixed>>           $items   Reviewed snapshot.
	 * @param string                                     $locale  Supported locale.
	 * @param string                                     $today   Institutional date.
	 * @param array<string, array{0: string, 1: string}> $strata  Localized stratum headings.
	 */
	private static function research_module( string $html, string $heading, array $items, string $locale, string $today, array $strata ): string {
		$english = 'en' === $locale;
		$areas   = array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'research' ) ), 0, 4 );
		$groups  = array(
			'projects'       => self::select_features( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'projects' ) ), $locale, $today ),
			'evidence'       => array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'evidence' ) ), 0, 4 ),
			'infrastructure' => array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'infrastructure' ) ), 0, 4 ),
		);
		$groups  = array_filter( $groups, static fn( array $records ): bool => array() !== $records );
		if ( array() === $areas && array() === $groups ) {
			return '';
		}
		$body = '';
		if ( array() !== $areas ) {
			$body .= '<h2 id="lps-home-research">' . self::escape( $heading ) . '</h2>';
			foreach ( $areas as $record ) {
				$body .= self::record_markup( $record, $locale );
			}
		}
		$first = array() === $areas;
		foreach ( $groups as $key => $records ) {
			$level = $first ? 'h2' : 'h3';
			$inner = array() === $areas || $first ? 'h3' : 'h4';
			$body .= '<section class="lps-home-stratum" data-home-section="' . $key . '" aria-labelledby="lps-home-' . $key . '"><' . $level . ' class="lps-kicker" id="lps-home-' . $key . '">' . self::escape( $strata[ $key ][ $english ? 0 : 1 ] ) . '</' . $level . '>';
			foreach ( $records as $record ) {
				$meta  = 'evidence' === $key ? self::provenance_meta( $record, $locale ) : '';
				$body .= self::record_markup( $record, $locale, $meta, $inner, 'projects' === $key || 'infrastructure' === $key );
			}
			$body .= '</section>';
			$first = false;
		}
		if ( array() === $areas ) {
			// A lone stratum carries the module: its heading is promoted to h2
			// and names the section so headings stay sequential.
			$first_key = (string) array_key_first( $groups );
			$html      = str_replace( 'aria-labelledby="lps-home-research"', 'aria-labelledby="lps-home-' . $first_key . '"', $html );
		}
		return $html . $body . '</section>';
	}

	/**
	 * Renders the differentiated latest module: one featured record with its
	 * media slot, then dated rows, then the archive entrance.
	 *
	 * @param string                           $html    Opened section tag.
	 * @param string                           $heading Localized module heading.
	 * @param array<int, array<string, mixed>> $items   Reviewed snapshot.
	 * @param string                           $locale  Supported locale.
	 */
	private static function latest_module( string $html, string $heading, array $items, string $locale ): string {
		$items = array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'latest' ) );
		if ( array() === $items ) {
			return '';
		}
		usort( $items, static fn( array $left, array $right ): int => strcmp( self::text( $right['date'] ?? '' ), self::text( $left['date'] ?? '' ) ) );
		$items = array_slice( $items, 0, 4 );
		$html .= '<h2 id="lps-home-latest">' . self::escape( $heading ) . '</h2>';
		foreach ( $items as $index => $record ) {
			$featured = 0 === $index;
			$meta     = self::dated_meta( $record, $locale );
			$row      = self::record_markup( $record, $locale, $meta, 'h3', $featured );
			if ( $featured ) {
				$row = str_replace( 'class="lps-record"', 'class="lps-record lps-record--featured"', $row );
			}
			$html .= $row;
		}
		$label = 'en' === $locale ? 'All news and events' : 'Todas as notícias e eventos';
		$url   = 'en' === $locale ? '/en/news/' : '/pt-br/noticias/';
		return $html . '<p class="lps-home-links"><a href="' . self::escape( $url ) . '">' . self::escape( $label ) . '</a></p></section>';
	}

	/**
	 * Renders the compact teaching entrance.
	 *
	 * The entrance is a persistent task route, not an optional CMS module: it
	 * always renders so a sparse homepage keeps its teaching action.
	 *
	 * @param string $html    Opened section tag.
	 * @param string $heading Localized module heading.
	 * @param string $locale  Supported locale.
	 */
	private static function teaching_module( string $html, string $heading, string $locale ): string {
		$english = 'en' === $locale;
		$summary = $english
			? 'Courses, current and previous offerings, and published teaching materials.'
			: 'Disciplinas, ofertas em andamento e anteriores, e materiais didáticos publicados.';
		$label   = $english ? 'Courses and materials' : 'Disciplinas e materiais';
		$url     = $english ? '/en/teaching/' : '/pt-br/ensino/';
		return $html . '<h2 id="lps-home-teaching">' . self::escape( $heading ) . '</h2>'
			. '<p>' . self::escape( $summary ) . '</p>'
			. '<p class="lps-home-actions"><a class="lps-button" data-home-action="teaching" href="' . self::escape( $url ) . '">' . self::escape( $label ) . '</a></p>'
			. '</section>';
	}

	/**
	 * Renders the people module with its collaboration entrances.
	 *
	 * @param string                           $html    Opened section tag.
	 * @param string                           $heading Localized module heading.
	 * @param array<int, array<string, mixed>> $items   Reviewed snapshot.
	 * @param string                           $locale  Supported locale.
	 */
	private static function people_module( string $html, string $heading, array $items, string $locale ): string {
		$items = array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'people' ) ), 0, 4 );
		if ( array() === $items ) {
			return '';
		}
		$english = 'en' === $locale;
		$html   .= '<h2 id="lps-home-people">' . self::escape( $heading ) . '</h2>';
		foreach ( $items as $record ) {
			$html .= self::record_markup( $record, $locale, '', 'h3', true );
		}
		$links = $english
			? array(
				array( 'People', '/en/people/' ),
				array( 'Collaborate', '/en/collaborate/' ),
			)
			: array(
				array( 'Pessoas', '/pt-br/pessoas/' ),
				array( 'Colabore', '/pt-br/colabore/' ),
			);
		$html .= '<p class="lps-home-links">';
		foreach ( $links as $index => $link ) {
			$html .= ( 0 === $index ? '' : ' ' ) . '<a href="' . self::escape( $link[1] ) . '">' . self::escape( $link[0] ) . '</a>';
		}
		return $html . '</p></section>';
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
			// With no partner rows the section has no heading of its own, so the
			// landmark label is dropped entirely: repointing it at the nested
			// contact heading would name two landmarks identically (axe
			// landmark-unique). An unnamed section is a plain group, which is
			// the honest semantics for a handoff-only band.
			$html = str_replace( ' aria-labelledby="lps-home-partners"', '', $html );
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
	 * Rows in media slots render the record's governed image when one was
	 * referenced and still passes review; a referenced image that fails rights
	 * or accessibility review, or a record that references no image, stays
	 * text-only.
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
	 * Events additionally carry their explicit status and venue so upcoming,
	 * cancelled, and held events are differentiated from news and
	 * publications, never silently mixed.
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
		if ( 'lps_event' === ( $record['type'] ?? '' ) ) {
			$status = self::event_status_label( self::text( $record['event_status'] ?? '' ), $locale );
			if ( '' !== $status ) {
				$html .= ( '' === $html ? '' : ' · ' ) . '<span class="lps-event-state">' . self::escape( $status ) . '</span>';
			}
			$venue = trim( self::text( $record['venue'] ?? '' ) );
			if ( '' !== $venue ) {
				$html .= ( '' === $html ? '' : ' · ' ) . self::escape( $venue );
			}
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
	 * Returns the localized event-status label used by dated event rows.
	 *
	 * @param string $status Stored event status.
	 * @param string $locale Supported locale.
	 */
	private static function event_status_label( string $status, string $locale ): string {
		$labels = array(
			'scheduled' => array( 'Agendado', 'Scheduled' ),
			'cancelled' => array( 'Cancelado', 'Cancelled' ),
			'postponed' => array( 'Adiado', 'Postponed' ),
			'held'      => array( 'Realizado', 'Held' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ][ 'en' === $locale ? 1 : 0 ] : '';
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
	 * Narrows a boundary record to a string-keyed map.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<string, mixed>
	 */
	private static function string_keyed( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$map = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$map[ $key ] = $item;
			}
		}
		return $map;
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
