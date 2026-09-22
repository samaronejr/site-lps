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
use LPS\ContentModel\Relationships;
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
		foreach ( array( 'record_id', 'import_source_id', 'import_source_url', 'import_review_state', 'state', 'review_date', 'page_key', 'canonical_task', 'public_profile', 'featured_until', 'starts_at', 'ends_at', 'event_status', 'venue', 'canonical_date', 'publication_date' ) as $field ) {
			$meta[ '_lps_' . $field ] = get_post_meta( $post_id, '_lps_' . $field, true );
		}
		$meta     = Translations::merge_shared_meta( $post->post_type, $post_id, $meta );
		$decision = class_exists( PublicationRecords::class ) ? PublicationRecords::publication_record( $post ) : array();
		$locale   = self::text( $decision['locale'] ?? Translations::locale( $post_id ) );
		return array_merge(
			$meta,
			$decision,
			array(
				'type'              => $post->post_type,
				'title'             => $post->post_title,
				'summary'           => $post->post_excerpt,
				'url'               => get_permalink( $post_id ),
				'record_id'         => $meta['_lps_record_id'],
				'source_id'         => class_exists( PublicationPolicy::class ) ? PublicationPolicy::provenance_id( array_merge( $meta, $decision ) ) : '',
				'status'            => $post->post_status,
				'state'             => $meta['_lps_state'],
				'locale'            => $locale,
				'post_id'           => $post_id,
				'import_source_url' => self::text( $meta['_lps_import_source_url'] ),
				'stale'             => ! empty( $decision['stale'] ),
				'review_date'       => $meta['_lps_review_date'],
				'page_key'          => 'collaboration' === $meta['_lps_page_key'] ? 'collaborate' : $meta['_lps_page_key'],
				'cta'               => $meta['_lps_canonical_task'],
				'featured_until'    => 'lps_event' === $post->post_type ? substr( self::text( $meta['_lps_ends_at'] ), 0, 10 ) : $meta['_lps_featured_until'],
				'date'              => self::text( 'lps_event' === $post->post_type ? $meta['_lps_starts_at'] : ( 'lps_publication' === $post->post_type ? $meta['_lps_publication_date'] : $meta['_lps_canonical_date'] ) ),
				'event_status'      => self::text( $meta['_lps_event_status'] ),
				'venue'             => self::text( $meta['_lps_venue'] ),
				'media'             => Media::record_image( $post ),
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
			'journeys' => array( 'Take part in LPS', 'Participe do LPS' ),
			'research' => array( 'Research', 'Pesquisa' ),
			'teaching' => array( 'Teaching', 'Ensino' ),
			'people'   => array( 'People', 'Pessoas' ),
			'latest'   => array( 'Publications, news and events', 'Publicações, notícias e eventos' ),
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
		$items = array_values( array_filter( $records, static fn( array $record ): bool => self::reviewed_feature( $record, $locale, $today ) ) );
		if ( 'mission' === $section ) {
			return self::mission_module( $items, $locale );
		}
		if ( 'journeys' === $section ) {
			return self::journeys_module( $items, $locale );
		}
		if ( 'teaching' === $section ) {
			return self::teaching_module( $locale );
		}
		if ( 'research' === $section ) {
			return self::research_module( $items, $locale, $today );
		}
		if ( 'partners' === $section ) {
			return self::partners_module( $items, $locale );
		}
		if ( 'latest' === $section ) {
			return self::latest_module( $items, $locale );
		}
		return self::people_module( $items, $locale );
	}

	/**
	 * Renders the mission feature as the hero band plus the stat band.
	 *
	 * The kicker, display title, support list and stats are informational
	 * constants pinned by the plan's first-viewport contract; the governed
	 * mission record supplies the lead deck when it is present. The two
	 * primary links are informational entrances that render even when the
	 * mission record is absent; a missing record shows the not-published
	 * notice instead of a fabricated lead.
	 *
	 * @param array<int, array<string, mixed>> $items   Reviewed snapshot.
	 * @param string                           $locale  Supported locale.
	 */
	private static function mission_module( array $items, string $locale ): string {
		$english = 'en' === $locale;
		$items   = array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'mission' ) ), 0, 1 );
		$record  = $items[0] ?? null;
		$source  = null !== $record ? ' data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '"' : '';
		$kicker  = $english ? 'Signal Processing Laboratory · UFRJ/COPPE' : 'Laboratório de Processamento de Sinais · UFRJ/COPPE';
		$title   = $english ? 'Signals, data and computational intelligence in the service of engineering' : 'Sinais, dados e inteligência computacional a serviço da engenharia';
		$accent  = $english ? 'computational intelligence' : 'inteligência computacional';
		$title   = str_replace( $accent, '<span class="lps-hero-accent">' . $accent . '</span>', $title );
		$lead    = null !== $record ? trim( self::text( $record['summary'] ?? '' ) ) : '';
		if ( '' === $lead ) {
			$lead = $english
				? 'Founded in 1996, LPS brings together teaching, research and extension work at COPPE/UFRJ. We research signal processing, machine learning and software engineering applied to energy, defence, medicine, oil and gas, and experimental high-energy physics.'
				: 'Fundado em 1996, o LPS reúne ensino, pesquisa e extensão na COPPE/UFRJ. Pesquisamos processamento de sinais, aprendizado de máquina e engenharia de software aplicados a energia, defesa, medicina, óleo e gás e física experimental de altas energias.';
		}
		$html  = '<section data-home-section="mission"' . $source . ' aria-labelledby="lps-home-mission">';
		$html .= '<div class="lps-hero"><div class="lps-hero-inner lps-page-grid"><div>'
			. '<p class="lps-kicker">' . self::escape( $kicker ) . '</p>'
			. '<h1 class="lps-hero-title" id="lps-home-mission">' . $title . '</h1>'
			. '<p class="lps-hero-lead">' . self::escape( $lead ) . '</p>'
			. self::primary_links_markup( $locale )
			. '</div><div class="lps-hero-support"><h2>' . self::escape( $english ? 'What the laboratory does' : 'O que o laboratório faz' ) . '</h2><ul>';
		foreach ( self::hero_support_items( $locale ) as $item ) {
			$html .= '<li><span>' . self::escape( $item ) . '</span></li>';
		}
		$html .= '</ul></div></div></div>';
		if ( null === $record ) {
			$html .= '<div class="lps-page-grid">' . self::empty_notice( 'mission', $locale ) . '</div>';
		}
		return $html . self::stat_band_markup( $locale ) . '</section>';
	}

	/**
	 * Returns the localized hero support bullets.
	 *
	 * @param string $locale Supported locale.
	 * @return array<int, string>
	 */
	private static function hero_support_items( string $locale ): array {
		return 'en' === $locale
			? array(
				'Research in signal processing and computational intelligence at COPPE/UFRJ',
				'Online filtering and event simulation in the ATLAS experiment at CERN',
				'Passive sonar and underwater acoustics with the Brazilian Navy',
				'Applied projects with industry, from prototype to operation',
			)
			: array(
				'Pesquisa em processamento de sinais e inteligência computacional na COPPE/UFRJ',
				'Filtragem online e simulação de eventos no experimento ATLAS, no CERN',
				'Sonar passivo e acústica submarina com a Marinha do Brasil',
				'Projetos aplicados com a indústria, do protótipo à operação',
			);
	}

	/**
	 * Renders the institutional stat band under the hero.
	 *
	 * @param string $locale Supported locale.
	 */
	private static function stat_band_markup( string $locale ): string {
		$stats = 'en' === $locale
			? array(
				array( '1996', 'Founded' ),
				array( '4', 'Full-time professors' ),
				array( '310 m²', 'Facilities in Building H' ),
				array( '1988', 'UFRJ–CERN collaboration' ),
			)
			: array(
				array( '1996', 'Ano de fundação' ),
				array( '4', 'Professores em tempo integral' ),
				array( '310 m²', 'Instalações no Bloco H' ),
				array( '1988', 'Colaboração UFRJ–CERN' ),
			);
		$html  = '<div class="lps-stat-band"><ul class="lps-page-grid">';
		foreach ( $stats as $stat ) {
			$html .= '<li class="lps-stat"><strong>' . self::escape( $stat[0] ) . '</strong><span>' . self::escape( $stat[1] ) . '</span></li>';
		}
		return $html . '</ul></div>';
	}

	/**
	 * Renders the pinned first-viewport action links.
	 *
	 * @param string $locale Supported locale.
	 */
	private static function primary_links_markup( string $locale ): string {
		$html     = '<div class="lps-hero-actions">';
		$variants = array( 'lps-button-primary', 'lps-button-ghost' );
		foreach ( self::primary_links( $locale ) as $index => $link ) {
			$variant = $variants[ $index ] ?? 'lps-button-ghost';
			$html   .= '<a class="lps-button ' . $variant . '" data-home-action="' . $link['key'] . '" href="' . self::escape( $link['url'] ) . '">' . self::escape( $link['label'] ) . '</a>';
		}
		return $html . '</div>';
	}

	/**
	 * Renders the three audience journeys as journey cards.
	 *
	 * @param array<int, array<string, mixed>> $items   Reviewed snapshot.
	 * @param string                           $locale  Supported locale.
	 */
	private static function journeys_module( array $items, string $locale ): string {
		$english = 'en' === $locale;
		$copy    = array(
			'opportunities'  => $english
				? array( 'COPPE · PEE · Poli', 'Join LPS', 'Research initiation, graduate programs and post-doctoral work in signal processing and computational intelligence.' )
				: array( 'COPPE · PEE · Poli', 'Junte-se ao LPS', 'Iniciação científica, pós-graduação e pós-doutorado em processamento de sinais e inteligência computacional.' ),
			'collaborate'    => $english
				? array( 'R&D · consulting', 'Collaborate', 'Contract research, R&D projects and workforce development with industry and public bodies.' )
				: array( 'P&D · consultoria', 'Colabore', 'Pesquisa contratada, projetos de P&D e formação de pessoal com a indústria e órgãos públicos.' ),
			'infrastructure' => $english
				? array( 'Partnerships', 'Partner', 'Facilities, laboratories and institutional channels for joint projects.' )
				: array( 'Parcerias', 'Seja parceiro', 'Instalações, laboratórios e canais institucionais para projetos conjuntos.' ),
		);
		$html    = '<section id="percurso" class="lps-section" data-home-section="journeys" aria-labelledby="home-journeys"><div class="lps-page-grid">'
			. self::section_head_markup( 'journeys', 'home-journeys', $locale )
			. '<ul class="lps-home-journeys">';
		foreach ( self::journeys( $locale ) as $journey ) {
			$text    = $copy[ $journey['page_key'] ] ?? array( '', $journey['label'], '' );
			$matches = array_values( array_filter( $items, static fn( array $record ): bool => ( $record['page_key'] ?? '' ) === $journey['page_key'] && '' !== trim( self::text( $record['cta'] ?? '' ) ) ) );
			$html   .= '<li class="lps-journey"><span class="lps-journey-label">' . self::escape( $text[0] ) . '</span><h3>' . self::escape( $text[1] ) . '</h3><p>' . self::escape( $text[2] ) . '</p>';
			if ( isset( $matches[0] ) ) {
				$record = $matches[0];
				$html  .= '<a class="lps-more" data-home-journey="' . $journey['page_key'] . '" data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '" href="' . self::escape( $journey['url'] ) . '">' . self::escape( self::text( $record['cta'] ) ) . '</a>';
			} else {
				$html .= '<span aria-disabled="true">' . ( $english ? 'Information not published' : 'Informações não publicadas' ) . '</span>';
			}
			$html .= '</li>';
		}
		return $html . '</ul></div></section>';
	}

	/**
	 * Renders the research module: linked areas plus the projects, evidence,
	 * and infrastructure strata in one band.
	 *
	 * @param array<int, array<string, mixed>> $items  Reviewed snapshot.
	 * @param string                           $locale Supported locale.
	 * @param string                           $today  Institutional date.
	 */
	private static function research_module( array $items, string $locale, string $today ): string {
		$areas  = array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'research' ) ), 0, 6 );
		$groups = array(
			'projects'       => self::select_features( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'projects' ) ), $locale, $today ),
			'evidence'       => array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'evidence' ) ), 0, 3 ),
			'infrastructure' => array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'infrastructure' ) ), 0, 3 ),
		);
		$groups = array_filter( $groups, static fn( array $records ): bool => array() !== $records );
		if ( array() === $areas && array() === $groups ) {
			return '';
		}
		$html = '';
		if ( array() !== $areas ) {
			$cards = '';
			foreach ( $areas as $record ) {
				$cards .= self::feature_card_markup( $record, $locale, true, '' );
			}
			$html .= self::card_section_markup( 'pesquisa', 'research', 'lps-section--tint', $locale, $cards );
		}
		foreach ( $groups as $key => $records ) {
			$cards = '';
			foreach ( $records as $record ) {
				$cards .= self::feature_card_markup( $record, $locale, false, self::card_foot_meta( $record, $key, $locale ) );
			}
			$anchor = 'projects' === $key ? 'projetos' : $key;
			$html  .= self::card_section_markup( $anchor, $key, '', $locale, $cards );
		}
		return $html;
	}

	/**
	 * Wraps one card grid in its named section band with a section head.
	 *
	 * @param string $anchor   Section anchor id, empty to skip.
	 * @param string $key      Section copy key and data-home-section value.
	 * @param string $modifier Extra lps-section modifier class, or empty.
	 * @param string $locale   Supported locale.
	 * @param string $cards    Pre-built card markup.
	 */
	private static function card_section_markup( string $anchor, string $key, string $modifier, string $locale, string $cards ): string {
		$id = '' !== $anchor ? ' id="' . $anchor . '"' : '';
		return '<section' . $id . ' class="lps-section' . ( '' !== $modifier ? ' ' . $modifier : '' ) . '" data-home-section="' . $key . '" aria-labelledby="home-' . $key . '"><div class="lps-page-grid">'
			. self::section_head_markup( $key, 'home-' . $key, $locale )
			. '<div class="lps-grid lps-grid--3">' . $cards . '</div></div></section>';
	}

	/**
	 * Renders one record as a showcase card.
	 *
	 * Accent cards (research areas) render their title unlinked and carry no
	 * media plate; standard cards (projects, evidence, infrastructure) render
	 * a decorative media plate, a linked title, topic chips and an optional
	 * foot line.
	 *
	 * @param array<string, mixed> $record     CMS record.
	 * @param string               $locale     Supported locale.
	 * @param bool                 $accent     Whether the card is an accent card.
	 * @param string               $foot_meta  Foot line text, empty to omit.
	 */
	private static function feature_card_markup( array $record, string $locale, bool $accent, string $foot_meta ): string {
		$html = '<article class="lps-card' . ( $accent ? ' lps-card--accent' : '' ) . '" data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '" data-record-id="' . self::escape( self::text( $record['record_id'] ?? '' ) ) . '">';
		if ( ! $accent ) {
			$html .= '<div class="lps-card-media" aria-hidden="true"></div>';
		}
		$html .= '<div class="lps-card-body"><h3 class="lps-card-title">';
		$title = self::escape( self::text( $record['title'] ) );
		if ( $accent ) {
			$html .= $title;
		} else {
			$html .= '<a href="' . self::escape( self::text( $record['url'] ) ) . '">' . $title . '</a>';
		}
		$html   .= '</h3>';
		$summary = trim( self::text( $record['summary'] ?? '' ) );
		if ( '' !== $summary ) {
			$html .= '<p>' . self::escape( $summary ) . '</p>';
		}
		$topics = self::record_topics( $record );
		if ( array() !== $topics ) {
			$html .= '<ul class="lps-term-token">';
			foreach ( $topics as $topic ) {
				$html .= '<li>' . self::escape( $topic ) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '</div>';
		if ( '' !== $foot_meta ) {
			$html .= '<div class="lps-card-foot"><span class="lps-meta">' . self::escape( $foot_meta ) . '</span></div>';
		}
		return $html . '</article>';
	}

	/**
	 * Returns the foot line of a stratum card: project status or provenance.
	 *
	 * @param array<string, mixed> $record CMS record.
	 * @param string               $key    Stratum key.
	 * @param string               $locale Supported locale.
	 */
	private static function card_foot_meta( array $record, string $key, string $locale ): string {
		if ( 'projects' === $key ) {
			$post_id = self::num( $record['post_id'] ?? 0 );
			$status  = 0 < $post_id ? self::text( get_post_meta( $post_id, '_lps_project_status', true ) ) : '';
			$english = 'en' === $locale;
			$labels  = array(
				'active'    => $english ? 'Ongoing' : 'Em andamento',
				'completed' => $english ? 'Completed' : 'Concluído',
				'paused'    => $english ? 'Paused' : 'Pausado',
				'planned'   => $english ? 'Planned' : 'Planejado',
			);
			return $labels[ $status ] ?? ( $english ? 'Ongoing' : 'Em andamento' );
		}
		return '';
	}

	/**
	 * Reads a record's governed topic list.
	 *
	 * @param array<string, mixed> $record CMS record.
	 * @return array<int, string>
	 */
	private static function record_topics( array $record ): array {
		$post_id = self::num( $record['post_id'] ?? 0 );
		if ( 0 === $post_id || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}
		$topics = get_post_meta( $post_id, '_lps_topics', true );
		if ( ! is_array( $topics ) ) {
			return array();
		}
		return array_values( array_filter( array_map( static fn( $topic ): string => trim( self::text( $topic ) ), $topics ) ) );
	}

	/**
	 * Renders the differentiated latest module: one featured record with its
	 * media slot, then dated rows, then the archive entrance.
	 *
	 * @param array<int, array<string, mixed>> $items  Reviewed snapshot.
	 * @param string                           $locale Supported locale.
	 */
	private static function latest_module( array $items, string $locale ): string {
		$items = array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'latest' ) );
		if ( array() === $items ) {
			return '';
		}
		usort( $items, static fn( array $left, array $right ): int => strcmp( self::text( $right['date'] ?? '' ), self::text( $left['date'] ?? '' ) ) );
		$items  = array_slice( $items, 0, 4 );
		$agenda = '';
		foreach ( $items as $record ) {
			$agenda .= self::agenda_item_markup( $record, $locale );
		}
		return '<section id="noticias" class="lps-section lps-section--tint" data-home-section="latest" aria-labelledby="home-latest"><div class="lps-page-grid">'
			. self::section_head_markup( 'latest', 'home-latest', $locale )
			. '<ol class="lps-agenda">' . $agenda . '</ol></div></section>';
	}

	/**
	 * Renders one dated record as an agenda row.
	 *
	 * The date tile prints the record's year — the shared period granularity
	 * across news, events and publications — with the localized record-type
	 * label beneath. Imported records keep their provenance line.
	 *
	 * @param array<string, mixed> $record CMS record.
	 * @param string               $locale Supported locale.
	 */
	private static function agenda_item_markup( array $record, string $locale ): string {
		$english = 'en' === $locale;
		$date    = trim( self::text( $record['date'] ?? '' ) );
		$year    = '' !== $date ? substr( $date, 0, 4 ) : '';
		$types   = array(
			'lps_news'        => $english ? 'News' : 'Notícia',
			'lps_event'       => $english ? 'Event' : 'Evento',
			'lps_publication' => $english ? 'Publication' : 'Publicação',
		);
		$type    = $types[ self::text( $record['type'] ?? '' ) ] ?? '';
		if ( 'lps_event' === ( $record['type'] ?? '' ) ) {
			$states = array(
				'cancelled' => $english ? 'Cancelled' : 'Cancelado',
				'postponed' => $english ? 'Postponed' : 'Adiado',
			);
			$state  = $states[ self::text( $record['event_status'] ?? '' ) ] ?? '';
			$type   = '' !== $state && '' !== $type ? $type . ' · ' . $state : $type;
		}
		$html    = '<li data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '" data-record-id="' . self::escape( self::text( $record['record_id'] ?? '' ) ) . '">';
		$html   .= '<div class="lps-event-date"><strong>' . self::escape( '' !== $year ? $year : '—' ) . '</strong><span>' . self::escape( $type ) . '</span></div>';
		$html   .= '<div><h3><a href="' . self::escape( self::text( $record['url'] ) ) . '">' . self::escape( self::text( $record['title'] ) ) . '</a></h3>';
		$summary = trim( self::text( $record['summary'] ?? '' ) );
		if ( '' !== $summary ) {
			$html .= '<p>' . self::escape( $summary ) . '</p>';
		}
		$html  .= '</div>';
		$source = trim( self::text( $record['import_source_url'] ?? '' ) );
		if ( '' !== $source ) {
			$html .= '<span class="lps-more">' . ( $english ? 'Source: ' : 'Fonte: ' ) . '<span class="lps-meta">' . self::escape( $english ? 'Legacy site' : 'Site anterior' ) . '</span></span>';
		}
		return $html . '</li>';
	}

	/**
	 * Renders the teaching module: entrance copy plus the live courses table.
	 *
	 * The entrance is a persistent task route, not an optional CMS module: it
	 * always renders so a sparse homepage keeps its teaching action.
	 *
	 * @param string $locale Supported locale.
	 */
	private static function teaching_module( string $locale ): string {
		$english = 'en' === $locale;
		$copy    = self::home_section_copy( 'teaching', $locale );
		$more    = self::text( $copy['more'] ?? '' );
		$html    = '<section id="ensino" class="lps-section lps-section--alt" data-home-section="teaching" aria-labelledby="home-teaching"><div class="lps-page-grid"><div class="lps-split"><div>'
			. '<p class="lps-kicker">' . self::escape( self::text( $copy['kicker'] ?? '' ) ) . '</p>'
			. '<h2 id="home-teaching">' . self::escape( self::text( $copy['title'] ?? '' ) ) . '</h2>'
			. '<p class="lps-lead">' . self::escape( self::text( $copy['lead'] ?? '' ) ) . '</p>'
			. '<p><a class="lps-more" data-home-action="teaching" href="' . self::escape( $more ) . '">' . self::escape( self::text( $copy['more_label'] ?? '' ) ) . '</a></p></div><div>';
		$courses = self::home_courses( $locale );
		if ( array() !== $courses ) {
			$caption = $english ? 'Courses currently taught by the laboratory' : 'Disciplinas atualmente ministradas pelo laboratório';
			$head    = $english ? array( 'Code', 'Course', 'Professor', 'Level' ) : array( 'Código', 'Disciplina', 'Professor', 'Nível' );
			$html   .= '<div class="lps-table-scroll" tabindex="0" role="region" aria-label="' . self::escape( $caption ) . '"><table><caption>' . self::escape( $caption ) . '</caption><thead><tr>';
			foreach ( $head as $cell ) {
				$html .= '<th scope="col">' . self::escape( $cell ) . '</th>';
			}
			$html .= '</tr></thead><tbody>';
			foreach ( $courses as $course ) {
				$html .= '<tr><td><span class="lps-course-code">' . self::escape( $course['code'] ) . '</span></td>'
					. '<th scope="row"><a href="' . self::escape( $course['url'] ) . '">' . self::escape( $course['title'] ) . '</a></th>'
					. '<td>' . ( '' !== $course['professor'] ? self::escape( $course['professor'] ) : '—' ) . '</td>'
					. '<td><span class="lps-level ' . $course['level_class'] . '">' . self::escape( $course['level_label'] ) . '</span></td></tr>';
			}
			$html .= '</tbody></table></div>';
		}
		return $html . '</div></div></div></section>';
	}

	/**
	 * Returns published courses of the requested locale for the home table.
	 *
	 * Professors resolve through the governed `teaching_team` relationship;
	 * a course without a wired team renders an em dash, never a guess.
	 *
	 * @param string $locale Supported locale.
	 * @return array<int, array{code: string, title: string, url: string, professor: string, level_label: string, level_class: string}>
	 */
	private static function home_courses( string $locale ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'   => 'lps_course',
				'post_status' => 'publish',
				'numberposts' => 50,
				'orderby'     => 'title',
				'order'       => 'ASC',
				'fields'      => 'all',
			)
		);
		$rows  = array();
		foreach ( $posts as $post ) {
			if ( get_post_meta( $post->ID, '_lps_locale', true ) !== $locale || 'published' !== get_post_meta( $post->ID, '_lps_state', true ) ) {
				continue;
			}
			$names = array();
			if ( class_exists( Relationships::class ) ) {
				foreach ( Relationships::reverse_for( $post->ID, 'offering_course' ) as $edge ) {
					$offering_id = self::num( $edge['source_post_id'] );
					if ( 0 >= $offering_id ) {
						continue;
					}
					foreach ( Relationships::for_source( $offering_id, 'teaching_team' ) as $member ) {
						$target = self::num( $member['target_post_id'] );
						$target = Translations::locale( $target ) === $locale ? $target : self::num( Translations::variants( $target )[ $locale ] ?? 0 );
						$person = 0 < $target ? get_post( $target ) : null;
						if ( $person instanceof WP_Post && '' !== $person->post_title && ! in_array( $person->post_title, $names, true ) ) {
							$names[] = $person->post_title;
						}
					}
				}
			}
			$level  = self::text( get_post_meta( $post->ID, '_lps_course_level', true ) );
			$rows[] = array(
				'code'        => self::text( get_post_meta( $post->ID, '_lps_course_code', true ) ),
				'title'       => $post->post_title,
				'url'         => (string) get_permalink( $post->ID ),
				'professor'   => implode( ' · ', $names ),
				'level_label' => self::course_level_label( $level, $locale ),
				'level_class' => 'graduate' === $level ? 'lps-level-grad' : 'lps-level-undergrad',
			);
		}
		return array_slice( $rows, 0, 4 );
	}

	/**
	 * Returns the localized course-level label.
	 *
	 * @param string $level  Stored level key.
	 * @param string $locale Supported locale.
	 */
	private static function course_level_label( string $level, string $locale ): string {
		$english = 'en' === $locale;
		$labels  = array(
			'undergraduate' => $english ? 'Undergraduate' : 'Graduação',
			'graduate'      => $english ? 'Graduate' : 'Pós-graduação',
			'extension'     => $english ? 'Extension' : 'Extensão',
		);
		return $labels[ $level ] ?? $level;
	}

	/**
	 * Renders the people module with its collaboration entrances.
	 *
	 * @param array<int, array<string, mixed>> $items  Reviewed snapshot.
	 * @param string                           $locale Supported locale.
	 */
	private static function people_module( array $items, string $locale ): string {
		$items = array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'people' ) ), 0, 5 );
		if ( array() === $items ) {
			return '';
		}
		$cards = '';
		foreach ( $items as $record ) {
			$cards .= self::person_card_markup( $record, $locale );
		}
		return '<section id="pessoas" class="lps-section" data-home-section="people" aria-labelledby="home-people"><div class="lps-page-grid">'
			. self::section_head_markup( 'people', 'home-people', $locale )
			. '<div class="lps-people-grid">' . $cards . '</div></div></section>';
	}

	/**
	 * Renders one person record as a people card.
	 *
	 * The monogram replaces the photo slot when the person's image rights are
	 * not cleared — the card never renders an unreviewed photo. The contact
	 * line keeps the profile entrance beside the published public email.
	 *
	 * @param array<string, mixed> $record CMS record.
	 * @param string               $locale Supported locale.
	 */
	private static function person_card_markup( array $record, string $locale ): string {
		$english  = 'en' === $locale;
		$post_id  = self::num( $record['post_id'] ?? 0 );
		$status   = 0 < $post_id ? self::text( get_post_meta( $post_id, '_lps_person_status', true ) ) : '';
		$memoriam = 'in-memoriam' === $status;
		$roles    = 0 < $post_id ? get_post_meta( $post_id, '_lps_roles', true ) : array();
		$email    = 0 < $post_id ? trim( self::text( get_post_meta( $post_id, '_lps_public_email', true ) ) ) : '';
		$slug     = 0 < $post_id ? self::text( get_post_field( 'post_name', $post_id ) ) : '';
		$html     = '<article class="lps-person-card"' . ( '' !== $slug ? ' id="' . self::escape( $slug ) . '"' : '' ) . ' data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '" data-record-id="' . self::escape( self::text( $record['record_id'] ?? '' ) ) . '">';
		$html    .= '<div class="lps-person-header"><span class="lps-monogram' . ( $memoriam ? ' lps-monogram--memoriam' : '' ) . '" aria-hidden="true">' . self::escape( self::person_initials( self::text( $record['title'] ) ) ) . '</span><div>';
		$html    .= '<h3>' . self::escape( self::text( $record['title'] ) ) . '</h3>';
		$role     = self::person_role_labels( array_values( is_array( $roles ) ? $roles : array() ), $locale );
		if ( '' !== $role ) {
			$html .= '<p class="lps-role">' . self::escape( $role ) . '</p>';
		}
		$html   .= '</div></div>';
		$summary = trim( self::text( $record['summary'] ?? '' ) );
		if ( '' !== $summary ) {
			$html .= '<p class="lps-summary">' . self::escape( $summary ) . '</p>';
		}
		$topics = self::record_topics( $record );
		if ( array() !== $topics ) {
			$html .= '<ul class="lps-term-token">';
			foreach ( $topics as $topic ) {
				$html .= '<li>' . self::escape( $topic ) . '</li>';
			}
			$html .= '</ul>';
		}
		$profile = '' !== $slug ? ( $english ? '/en/people/' : '/pt-br/pessoas/' ) . $slug . '/' : self::text( $record['url'] );
		$html   .= '<div class="lps-person-contact"><a class="lps-more" href="' . self::escape( $profile ) . '">' . self::escape( $english ? 'Learn more' : 'Saiba mais' ) . '</a>';
		if ( '' !== $email ) {
			$html .= '<a class="lps-meta" href="mailto:' . self::escape( $email ) . '">' . self::escape( $email ) . '</a>';
		}
		return $html . '</div></article>';
	}

	/**
	 * Returns a person's display initials (first and last word).
	 *
	 * @param string $name Display name.
	 */
	private static function person_initials( string $name ): string {
		$split = preg_split( '~\s+~u', trim( $name ) );
		$parts = array_values( array_filter( is_array( $split ) ? $split : array() ) );
		if ( array() === $parts ) {
			return '';
		}
		$first = function_exists( 'mb_substr' ) ? mb_substr( (string) $parts[0], 0, 1 ) : substr( (string) $parts[0], 0, 1 );
		$last  = count( $parts ) > 1 ? ( function_exists( 'mb_substr' ) ? mb_substr( (string) $parts[ count( $parts ) - 1 ], 0, 1 ) : substr( (string) $parts[ count( $parts ) - 1 ], 0, 1 ) ) : '';
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $first . $last ) : strtoupper( $first . $last );
	}

	/**
	 * Returns the localized joined role label of a person record.
	 *
	 * @param array<int, mixed> $roles  Stored role slugs.
	 * @param string            $locale Supported locale.
	 */
	private static function person_role_labels( array $roles, string $locale ): string {
		$english = 'en' === $locale;
		$labels  = array(
			'professor'                  => $english ? 'Professor' : 'Professor',
			'professor-titular'          => $english ? 'Full Professor' : 'Professor Titular',
			'professor-titular-emerito'  => $english ? 'Emeritus Full Professor' : 'Professor Titular Emérito',
			'professor-adjunto'          => $english ? 'Associate Professor' : 'Professor Adjunto',
			'coordenador-lps'            => $english ? 'LPS Coordinator' : 'Coordenador do LPS',
			'pesquisador-permanente-lps' => $english ? 'Permanent LPS Researcher' : 'Pesquisador Permanente do LPS',
			'pesquisador'                => $english ? 'Researcher' : 'Pesquisador',
			'researcher'                 => $english ? 'Researcher' : 'Pesquisador',
			'student'                    => $english ? 'Student' : 'Estudante',
			'technical-staff'            => $english ? 'Technical staff' : 'Equipe técnica',
			'external-collaborator'      => $english ? 'External collaborator' : 'Colaboração externa',
			'alumni'                     => $english ? 'Alumni' : 'Egresso',
		);
		$found   = array();
		foreach ( $roles as $role ) {
			$role = self::text( $role );
			if ( isset( $labels[ $role ] ) ) {
				$found[] = $labels[ $role ];
			}
		}
		return implode( ' · ', $found );
	}

	/**
	 * Renders partner rows with the contact handoff nested as the closing strip.
	 *
	 * @param array<int, array<string, mixed>> $items  Reviewed snapshot.
	 * @param string                           $locale Supported locale.
	 */
	private static function partners_module( array $items, string $locale ): string {
		$contact = array_slice( array_values( array_filter( $items, static fn( array $record ): bool => ( $record['section'] ?? '' ) === 'contact' ) ), 0, 1 );
		$logos   = self::partner_logos( $locale );
		$html    = '';
		if ( array() !== $logos ) {
			$html .= '<section id="parceiros" class="lps-section" data-home-section="partners" aria-labelledby="home-partners"><div class="lps-page-grid">'
				. self::section_head_markup( 'partners', 'home-partners', $locale )
				. self::partner_marquee_markup( $logos )
				. '</div></section>';
		}
		return $html . self::cta_band_markup( $contact, $locale );
	}

	/**
	 * Returns every published organization with a rights-cleared logo.
	 *
	 * The marquee lists all cleared partner and funder marks of the locale,
	 * not only the records featured on the homepage.
	 *
	 * @param string $locale Supported locale.
	 * @return array<int, array{src: string, alt: string}>
	 */
	private static function partner_logos( string $locale ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'   => 'lps_organization',
				'post_status' => 'publish',
				'numberposts' => 100,
			)
		);
		$logos = array();
		$seen  = array();
		foreach ( $posts as $post ) {
			if ( get_post_meta( $post->ID, '_lps_locale', true ) !== $locale || 'published' !== get_post_meta( $post->ID, '_lps_state', true ) ) {
				continue;
			}
			$logo = trim( self::text( get_post_meta( $post->ID, '_lps_logo_url', true ) ) );
			if ( '' === $logo || 'cleared' !== get_post_meta( $post->ID, '_lps_logo_rights', true ) || isset( $seen[ $logo ] ) ) {
				continue;
			}
			$seen[ $logo ] = true;
			$logos[]       = array(
				'src' => $logo,
				'alt' => $post->post_title,
			);
		}
		return $logos;
	}

	/**
	 * Renders the drifting partner marquee.
	 *
	 * The track lists every mark once and then repeats it aria-hidden so the
	 * loop reads as a continuous band; the animation is defined inline so the
	 * marquee keeps working when only this fragment renders.
	 *
	 * @param array<int, array{src: string, alt: string}> $logos Logo pairs.
	 */
	private static function partner_marquee_markup( array $logos ): string {
		$items = '';
		foreach ( $logos as $logo ) {
			$items .= '<li><img src="' . self::escape( $logo['src'] ) . '" alt="' . self::escape( $logo['alt'] ) . '" decoding="async" /></li>';
		}
		foreach ( $logos as $logo ) {
			$items .= '<li aria-hidden="true"><img src="' . self::escape( $logo['src'] ) . '" alt="" decoding="async" /></li>';
		}
		return '<div class="lps-partner-marquee"><style>@keyframes lps-partner-drift{from{transform:translateX(-50%)}to{transform:translateX(0)}}.lps-partner-track{animation:lps-partner-drift 60s linear infinite}.lps-partner-track--roomy{animation-duration:90s}.lps-partner-marquee:hover .lps-partner-track,.lps-partner-marquee:focus-within .lps-partner-track{animation-play-state:paused}@media (prefers-reduced-motion:reduce){.lps-partner-track{animation:none}}</style><ul class="lps-partner-track lps-partner-track--roomy">' . $items . '</ul></div>';
	}

	/**
	 * Renders the closing call-to-action band from the contact records.
	 *
	 * @param array<int, array<string, mixed>> $contact Contact records.
	 * @param string                           $locale  Supported locale.
	 */
	private static function cta_band_markup( array $contact, string $locale ): string {
		$english = 'en' === $locale;
		$title   = $english ? 'Bring a signal processing or machine learning problem' : 'Traga um problema de processamento de sinais ou aprendizado de máquina';
		$lead    = $english ? 'The laboratory does contract research, R&D projects and workforce development with industry and public bodies.' : 'O laboratório realiza pesquisa contratada, projetos de P&D e formação de pessoal com a indústria e órgãos públicos.';
		$record  = $contact[0] ?? null;
		if ( null === $record ) {
			return '<section class="lps-section" data-home-section="contact" aria-labelledby="lps-home-contact"><div class="lps-page-grid"><div class="lps-cta-band lps-cta-band--split"><div><h2 id="lps-home-contact">' . self::escape( $title ) . '</h2><p>' . self::escape( $lead ) . '</p>' . self::empty_notice( 'contact', $locale ) . '</div></div></div></section>';
		}
		$primary    = trim( self::text( $record['cta'] ?? '' ) );
		$primary    = '' !== $primary ? $primary : ( $english ? 'Talk to the laboratory' : 'Fale com o laboratório' );
		$ghost      = $english ? 'Capabilities' : 'Capacidades';
		$ghost_href = $english ? '/en/infrastructure/' : '/pt-br/infraestrutura/';
		return '<section class="lps-section" data-home-section="contact" aria-labelledby="lps-home-contact"><div class="lps-page-grid"><div class="lps-cta-band lps-cta-band--split"><div><h2 id="lps-home-contact">' . self::escape( $title ) . '</h2><p>' . self::escape( $lead ) . '</p></div><div class="lps-button-row"><a class="lps-button lps-button-primary" data-home-action="contact" data-source-id="' . self::escape( self::text( $record['source_id'] ) ) . '" href="' . self::escape( self::text( $record['url'] ) ) . '">' . self::escape( $primary ) . '</a><a class="lps-button lps-button-ghost" href="' . self::escape( $ghost_href ) . '">' . self::escape( $ghost ) . '</a></div></div></div></section>';
	}

	/**
	 * Builds the localized two-column section head.
	 *
	 * @param string $key        Section copy key.
	 * @param string $heading_id ID carried by the rendered h2.
	 * @param string $locale     Supported locale.
	 */
	private static function section_head_markup( string $key, string $heading_id, string $locale ): string {
		$copy       = self::home_section_copy( $key, $locale );
		$lead       = trim( self::text( $copy['lead'] ?? '' ) );
		$more       = trim( self::text( $copy['more'] ?? '' ) );
		$more_label = trim( self::text( $copy['more_label'] ?? '' ) );
		if ( '' === $more_label ) {
			$more_label = 'en' === $locale ? 'See all →' : 'Ver tudo →';
		}
		$html = '<div class="lps-section-head"><div><p class="lps-kicker">' . self::escape( self::text( $copy['kicker'] ?? '' ) ) . '</p><h2 id="' . self::escape( $heading_id ) . '">' . self::escape( self::text( $copy['title'] ?? '' ) ) . '</h2></div><div>';
		if ( '' !== $lead ) {
			$html .= '<p class="lps-lead">' . self::escape( $lead ) . '</p>';
		}
		if ( '' !== $more ) {
			$html .= '<p class="lps-mt-4"><a class="lps-more" href="' . self::escape( $more ) . '">' . self::escape( $more_label ) . '</a></p>';
		}
		return $html . '</div></div>';
	}

	/**
	 * Returns the localized editorial copy of each homepage section head.
	 *
	 * Kickers, titles and leads are the plan's informational constants; record
	 * bodies stay governed. `more` is the archive entrance, `more_label` the
	 * link text.
	 *
	 * @param string $key    Section key.
	 * @param string $locale Supported locale.
	 * @return array<string, string>
	 */
	private static function home_section_copy( string $key, string $locale ): array {
		$english = 'en' === $locale;
		$copy    = array(
			'journeys'       => array(
				'kicker' => $english ? 'Education' : 'Formação',
				'title'  => $english ? 'Choose your path' : 'Escolha o seu percurso',
				'lead'   => $english ? 'The laboratory works from junior research initiation to post-doctorate, at the Polytechnic School and at COPPE.' : 'A atuação do laboratório cobre da iniciação científica júnior ao pós-doutorado, na Escola Politécnica e na COPPE.',
			),
			'research'       => array(
				'kicker' => $english ? 'Research' : 'Pesquisa',
				'title'  => $english ? 'Research areas' : 'Linhas de pesquisa',
				'lead'   => $english ? 'The main areas of activity are digital signal processing, supervised and unsupervised data modelling, feature engineering, recommender systems, time-series analysis and fault, fraud and novelty detection.' : 'As principais áreas de atuação são o processamento digital de sinais, a modelagem de dados supervisionada e não supervisionada, a engenharia de características, os sistemas de recomendação, a análise de séries temporais e a detecção de falhas, fraudes e novidades.',
				'more'   => $english ? '/en/research/' : '/pt-br/pesquisa/',
			),
			'projects'       => array(
				'kicker' => $english ? 'Projects' : 'Projetos',
				'title'  => $english ? 'Research in partnership' : 'Pesquisa em parceria',
				'lead'   => $english ? 'Selected projects registered in the laboratory\'s public material, with their partners.' : 'Projetos selecionados registrados no material público do laboratório, com seus parceiros.',
				'more'   => $english ? '/en/projects/' : '/pt-br/projetos/',
			),
			'evidence'       => array(
				'kicker' => $english ? 'Outputs' : 'Produção',
				'title'  => $english ? 'Outputs and evidence' : 'Produção e evidências',
				'lead'   => $english ? 'Publications and institutional records produced by the laboratory.' : 'Publicações e registros institucionais produzidos pelo laboratório.',
				'more'   => $english ? '/en/publications/' : '/pt-br/publicacoes/',
			),
			'infrastructure' => array(
				'kicker' => $english ? 'Infrastructure' : 'Infraestrutura',
				'title'  => $english ? 'Infrastructure and capabilities' : 'Infraestrutura e capacidades',
				'lead'   => $english ? 'Laboratories, equipment and technical capabilities available for projects.' : 'Laboratórios, equipamentos e capacidades técnicas disponíveis para projetos.',
				'more'   => $english ? '/en/infrastructure/' : '/pt-br/infraestrutura/',
			),
			'teaching'       => array(
				'kicker'     => $english ? 'Teaching' : 'Ensino',
				'title'      => $english ? 'Courses, materials and supervision' : 'Disciplinas, materiais e orientação',
				'lead'       => $english ? 'The laboratory teaches at the Polytechnic School and in the Electrical Engineering Program at COPPE, from instrumentation to deep learning.' : 'O laboratório atua na Escola Politécnica e no Programa de Engenharia Elétrica da COPPE, da instrumentação ao aprendizado profundo.',
				'more'       => $english ? '/en/teaching/' : '/pt-br/ensino/',
				'more_label' => $english ? 'All courses' : 'Todas as disciplinas',
			),
			'people'         => array(
				'kicker' => $english ? 'People' : 'Pessoas',
				'title'  => $english ? 'Who works here' : 'Quem trabalha aqui',
				'lead'   => $english ? 'Four full-time professors, two of them tenured, plus post-doctoral researchers and graduate and undergraduate students.' : 'Quatro professores em tempo integral, dos quais dois são titulares, além de pesquisadores de pós-doutorado e estudantes de pós-graduação e graduação.',
				'more'   => $english ? '/en/people/' : '/pt-br/pessoas/',
			),
			'latest'         => array(
				'kicker' => $english ? 'News and events' : 'Notícias e eventos',
				'title'  => $english ? 'Latest institutional records' : 'Últimos registros institucionais',
				'lead'   => $english ? 'Every entry is dated and traceable to its public source.' : 'Cada entrada é datada e rastreável à fonte pública de origem.',
				'more'   => $english ? '/en/news/' : '/pt-br/noticias/',
			),
			'partners'       => array(
				'kicker' => $english ? 'Partners and funders' : 'Parceiros e financiadores',
				'title'  => $english ? 'Industry, agencies and international collaborations' : 'Indústria, agências e colaborações internacionais',
				'lead'   => $english ? 'Companies, funding agencies and the international collaborations that sustain the laboratory\'s projects.' : 'Empresas, agências de fomento e as colaborações internacionais que sustentam os projetos do laboratório.',
				'more'   => $english ? '/en/infrastructure/#parcerias' : '/pt-br/infraestrutura/#parcerias',
			),
		);
		return isset( $copy[ $key ] ) ? $copy[ $key ] : array();
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
