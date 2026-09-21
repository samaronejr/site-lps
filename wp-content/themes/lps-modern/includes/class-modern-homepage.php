<?php
/**
 * Modern homepage sections with governed CMS features and curated fallbacks.
 *
 * Reviewed CMS records always win. When none are published for a section, the
 * theme renders curated fallback copy taken verbatim from the reviewed corpus
 * (content/corpus/records/record-*.json) and the 2026-09-21 public scrape
 * (docs/content/lps-google-sites-scrape-2026-09-21.md). Fallback blocks carry
 * data-provenance="curated-corpus" so reviewers can tell them apart.
 *
 * @package LPS\Modern
 */

declare(strict_types=1);

namespace LPS\Modern;

use LPS\ContentModel\Translations;
use WP_Post;

/** Owns the ten locked modern homepage sections. */
final class ModernHomepage {
	/**
	 * Returns the three audience journeys for one locale.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<int, array{page_key: string, label: string, text: string, url: string, icon: string}>
	 */
	public static function journeys( string $locale ): array {
		if ( 'en' === $locale ) {
			return array(
				array(
					'page_key' => 'opportunities',
					'label'    => 'Study at LPS',
					'text'     => 'From junior scientific initiation to postdoctoral research, via Poli/UFRJ and COPPE graduate programs.',
					'url'      => '/en/opportunities/',
					'icon'     => 'cap',
				),
				array(
					'page_key' => 'collaborate',
					'label'    => 'Research with us',
					'text'     => 'Signal processing, computational intelligence, and software for complex, critical environments.',
					'url'      => '/en/collaborate/',
					'icon'     => 'flask',
				),
				array(
					'page_key' => 'infrastructure',
					'label'    => 'Partner with LPS',
					'text'     => 'Industry-grade projects, 310 m² of labs, and a team wired for technology transfer.',
					'url'      => '/en/infrastructure/',
					'icon'     => 'handshake',
				),
			);
		}
		return array(
			array(
				'page_key' => 'opportunities',
				'label'    => 'Estude no LPS',
				'text'     => 'Da iniciação científica júnior ao pós-doutorado, passando pela Poli/UFRJ e pela pós-graduação da COPPE.',
				'url'      => '/pt-br/oportunidades/',
				'icon'     => 'cap',
			),
			array(
				'page_key' => 'collaborate',
				'label'    => 'Pesquise conosco',
				'text'     => 'Processamento de sinais, inteligência computacional e software para ambientes complexos e críticos.',
				'url'      => '/pt-br/colabore/',
				'icon'     => 'flask',
			),
			array(
				'page_key' => 'infrastructure',
				'label'    => 'Seja parceiro',
				'text'     => 'Projetos com a indústria, 310 m² de laboratórios e uma equipe voltada à transferência de tecnologia.',
				'url'      => '/pt-br/infraestrutura/',
				'icon'     => 'handshake',
			),
		);
	}

	/**
	 * Returns the six curated research areas for one locale.
	 *
	 * Areas mirror the partnership signature published on the institutional
	 * home page (energy, HEP, quantum, defense, medicine, data quality).
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<int, array{label: string, text: string, icon: string}>
	 */
	public static function research_areas( string $locale ): array {
		$more = 'en' === $locale ? '/en/research/' : '/pt-br/pesquisa/';
		if ( 'en' === $locale ) {
			return array(
				array(
					'label' => 'Energy',
					'text'  => 'Electrical, nuclear, oil & gas: monitoring, modeling, and decision support.',
					'icon'  => 'bolt',
					'url'   => $more,
				),
				array(
					'label' => 'High-energy physics',
					'text'  => 'ATLAS/CERN online filtering, event simulation, and energy estimation.',
					'icon'  => 'atom',
					'url'   => $more,
				),
				array(
					'label' => 'Quantum computing',
					'text'  => 'Quantum machine learning and hybrid classical-quantum methods.',
					'icon'  => 'cpu',
					'url'   => $more,
				),
				array(
					'label' => 'Defense & sonar',
					'text'  => 'Passive sonar, underwater acoustics, and novelty detection with the Brazilian Navy.',
					'icon'  => 'shield',
					'url'   => $more,
				),
				array(
					'label' => 'Medicine & health',
					'text'  => 'Computer-aided diagnosis and biomedical signal analysis.',
					'icon'  => 'pulse',
					'url'   => $more,
				),
				array(
					'label' => 'Data & AI',
					'text'  => 'Data quality, deep learning, and interpretable models for critical systems.',
					'icon'  => 'database',
					'url'   => $more,
				),
			);
		}
		return array(
			array(
				'label' => 'Energia',
				'text'  => 'Elétrica, nuclear, óleo e gás: monitoramento, modelagem e apoio à decisão.',
				'icon'  => 'bolt',
				'url'   => $more,
			),
			array(
				'label' => 'Física de altas energias',
				'text'  => 'Filtragem online, simulação de eventos e estimação de energia no ATLAS/CERN.',
				'icon'  => 'atom',
				'url'   => $more,
			),
			array(
				'label' => 'Computação quântica',
				'text'  => 'Aprendizado de máquina quântico e métodos híbridos clássico-quânticos.',
				'icon'  => 'cpu',
				'url'   => $more,
			),
			array(
				'label' => 'Defesa e sonar',
				'text'  => 'Sonar passivo, acústica submarina e detecção de novidade com a Marinha do Brasil.',
				'icon'  => 'shield',
				'url'   => $more,
			),
			array(
				'label' => 'Medicina e saúde',
				'text'  => 'Diagnóstico auxiliado por computador e análise de sinais biomédicos.',
				'icon'  => 'pulse',
				'url'   => $more,
			),
			array(
				'label' => 'Dados e IA',
				'text'  => 'Qualidade de dados, aprendizado profundo e modelos interpretáveis para sistemas críticos.',
				'icon'  => 'database',
				'url'   => $more,
			),
		);
	}

	/**
	 * Returns the hero statistics for one locale (institutional published facts).
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function stats( string $locale ): array {
		if ( 'en' === $locale ) {
			return array(
				array(
					'value' => '1996',
					'label' => 'founded at UFRJ',
				),
				array(
					'value' => '4',
					'label' => 'full-time professors',
				),
				array(
					'value' => '310 m²',
					'label' => 'of laboratory space',
				),
				array(
					'value' => '~40',
					'label' => 'networked workstations',
				),
			);
		}
		return array(
			array(
				'value' => '1996',
				'label' => 'fundação na UFRJ',
			),
			array(
				'value' => '4',
				'label' => 'professores em tempo integral',
			),
			array(
				'value' => '310 m²',
				'label' => 'de laboratórios',
			),
			array(
				'value' => '~40',
				'label' => 'estações em rede',
			),
		);
	}

	/**
	 * Returns the curated people fallback for one locale.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<int, array{name: string, role: string, focus: string, url: string}>
	 */
	public static function people_fallback( string $locale ): array {
		$english = 'en' === $locale;
		return array(
			array(
				'name'  => 'Luiz Pereira Calôba',
				'role'  => $english ? 'Full Professor (Emeritus)' : 'Professor Titular (Emérito)',
				'focus' => $english ? 'Signal processing, neural networks, UFRJ–CERN since 1988' : 'Processamento de sinais, redes neurais, UFRJ–CERN desde 1988',
				'url'   => 'http://lattes.cnpq.br/3238659802153968',
			),
			array(
				'name'  => 'José Manoel de Seixas',
				'role'  => $english ? 'Full Professor, UFRJ' : 'Professor Titular, UFRJ',
				'focus' => $english ? 'Computational intelligence, calorimetry, sonar' : 'Inteligência computacional, calorimetria, sonar',
				'url'   => 'http://lattes.cnpq.br/1404632471755241',
			),
			array(
				'name'  => 'Natanael Nunes de Moura Junior',
				'role'  => $english ? 'Professor, UFRJ · LPS coordinator' : 'Professor, UFRJ · Coordenador do LPS',
				'focus' => $english ? 'ATLAS/CERN, passive sonar, oil & gas AI' : 'ATLAS/CERN, sonar passivo, IA para óleo e gás',
				'url'   => 'http://lattes.cnpq.br/2696393506316122',
			),
			array(
				'name'  => 'João Victor da Fonseca Pinto',
				'role'  => $english ? 'Adjunct Professor DEL/UFRJ · LPS researcher' : 'Professor Adjunto DEL/UFRJ · Pesquisador do LPS',
				'focus' => $english ? 'ATLAS online filtering, event simulation, quantum' : 'Filtragem online no ATLAS, simulação de eventos, quântica',
				'url'   => 'http://lattes.cnpq.br/3592331377050716',
			),
		);
	}

	/**
	 * Returns the curated partners fallback (EMBRAPII roster + lab collaborations).
	 *
	 * @return array<int, string>
	 */
	public static function partners_fallback(): array {
		return array(
			'Petrobras',
			'Eletrobras',
			'Embraer',
			'Inmetro',
			'EPE',
			'OLX',
			'National Instruments',
			'Samsung',
			'Murabei',
			'CERN / ATLAS',
			'Marinha do Brasil',
			'RENAFAE',
		);
	}

	/**
	 * Returns one inline icon (project-original geometric SVG, currentColor).
	 *
	 * @param string $name Icon name.
	 */
	public static function icon( string $name ): string {
		$paths = array(
			'cap'       => '<path d="M12 4 2 9l10 5 10-5-10-5Z"/><path d="M6 11.5V16c0 1.5 2.7 3 6 3s6-1.5 6-3v-4.5"/><path d="M22 9v6"/>',
			'flask'     => '<path d="M10 3h4"/><path d="M10 3v6l-5.5 9.5A2 2 0 0 0 6.2 21h11.6a2 2 0 0 0 1.7-2.5L14 9V3"/><path d="M7.5 15h9"/>',
			'handshake' => '<path d="M3 7.5 9 3l4 4 2.5-2.5L21 10l-5.5 5.5-2-2-2 2-7-7 2-2-3.5-1Z"/><path d="M9.5 13.5 14 18l-1.5 3-5.5-5.5"/>',
			'bolt'      => '<path d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/>',
			'atom'      => '<circle cx="12" cy="12" r="1.5"/><ellipse cx="12" cy="12" rx="10" ry="4"/><ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(60 12 12)"/><ellipse cx="12" cy="12" rx="10" ry="4" transform="rotate(120 12 12)"/>',
			'cpu'       => '<rect x="7" y="7" width="10" height="10" rx="2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
			'shield'    => '<path d="M12 2 4 5.5V11c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5.5L12 2Z"/><path d="m8.5 11.5 2.5 2.5 4.5-5"/>',
			'pulse'     => '<path d="M3 12h4l2.5-6 4 12L16 12h5"/>',
			'database'  => '<ellipse cx="12" cy="5.5" rx="8" ry="3"/><path d="M4 5.5V18.5c0 1.7 3.6 3 8 3s8-1.3 8-3V5.5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
			'arrow'     => '<path d="M4 12h15"/><path d="m13 6 6 6-6 6"/>',
		);
		$body  = $paths[ $name ] ?? $paths['arrow'];
		return '<svg class="lpsx-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $body . '</svg>';
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
	 * @param string            $locale  Requested locale.
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
	 * Renders one locked section using the queried CMS homepage.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render( array $attributes ): string {
		$home_id = function_exists( 'get_queried_object_id' ) ? get_queried_object_id() : 0;
		$locale  = function_exists( 'get_locale' ) && str_starts_with( (string) get_locale(), 'en' ) ? 'en' : 'pt-br';
		if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only request routing.
			$req_locale = ModernShell::locale_from_path( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
			$locale     = $req_locale;
		}
		$today = function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
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
		if ( ! class_exists( Translations::class ) || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}
		if ( 'home' !== get_post_meta( $home_id, '_lps_page_key', true ) ) {
			return array();
		}
		$home = self::cms_record( $home_id );
		if ( ! self::reviewed_feature( $home, $locale, $today ) ) {
			return array();
		}
		$home['section'] = 'hero';
		$records         = array( $home );
		$seen            = array();
		foreach ( Translations::relationships_for( $home_id, 'related_record' ) as $selection ) {
			if ( ! is_array( $selection ) ) {
				continue;
			}
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
			$record['section']        = 'context' === $role ? 'about' : self::record_section( $record );
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
		if ( ! function_exists( 'get_post' ) ) {
			return array();
		}
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
		$valid  = null !== $source && function_exists( 'get_post_status' ) && 'publish' === get_post_status( $source ) && 'published' === get_post_meta( $source, '_lps_state', true );
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
			'url'            => function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '',
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
	 * Maps record types and stable page keys to the modern composition.
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
	 * Builds a reusable, localized modern section.
	 *
	 * @param string                           $section Locked section key.
	 * @param string                           $locale Supported locale.
	 * @param array<int, array<string, mixed>> $records CMS snapshot.
	 * @param string                           $today Institutional date.
	 */
	public static function section_markup( string $section, string $locale, array $records, string $today ): string {
		return match ( $section ) {
			'hero' => self::hero_markup( $locale ),
			'journeys' => self::journeys_markup( $locale ),
			'research' => self::research_markup( $locale ),
			'projects' => self::projects_markup( $locale, $records, $today ),
			'about' => self::about_markup( $locale ),
			'people' => self::people_markup( $locale, $records, $today ),
			'infrastructure' => self::infrastructure_markup( $locale ),
			'latest' => self::latest_markup( $locale, $records, $today ),
			'partners' => self::partners_markup( $locale, $records, $today ),
			'contact' => self::contact_markup( $locale ),
			default => '',
		};
	}

	/**
	 * Builds the section eyebrow header with an optional archive link.
	 *
	 * @param string $id       Heading ID.
	 * @param string $kicker   Eyebrow text.
	 * @param string $heading  Section heading.
	 * @param string $lead     Optional lead paragraph.
	 * @param string $link_url Optional archive URL.
	 * @param string $link_txt Optional archive link text.
	 */
	private static function section_head( string $id, string $kicker, string $heading, string $lead = '', string $link_url = '', string $link_txt = '' ): string {
		$html = '<div class="lpsx-section-head"><div><p class="lpsx-kicker">' . self::escape( $kicker ) . '</p><h2 id="' . $id . '">' . self::escape( $heading ) . '</h2>';
		if ( '' !== $lead ) {
			$html .= '<p class="lpsx-section-lead">' . self::escape( $lead ) . '</p>';
		}
		$html .= '</div>';
		if ( '' !== $link_url && '' !== $link_txt ) {
			$html .= '<p class="lpsx-section-link"><a href="' . self::escape( $link_url ) . '">' . self::escape( $link_txt ) . ' ' . self::icon( 'arrow' ) . '</a></p>';
		}
		return $html . '</div>';
	}

	/** Builds the hero band (curated institutional presentation, both locales). */
	private static function hero_markup( string $locale ): string {
		$english = 'en' === $locale;
		$kicker  = $english ? 'Since 1996 · UFRJ / COPPE' : 'Desde 1996 · UFRJ / COPPE';
		$title   = $english ? 'Signals that move science, industry, and society.' : 'Sinais que movem ciência, indústria e sociedade.';
		$lead    = $english
			? 'The Signal Processing Laboratory combines teaching, research, and outreach in signal processing and computational intelligence — from junior scientific initiation to the postdoc.'
			: 'O Laboratório de Processamento de Sinais une ensino, pesquisa e extensão em processamento de sinais e inteligência computacional — da iniciação científica júnior ao pós-doutorado.';
		$cta1    = $english ? 'Explore the research' : 'Conheça a pesquisa';
		$cta2    = $english ? 'Opportunities' : 'Oportunidades';
		$url1    = $english ? '/en/research/' : '/pt-br/pesquisa/';
		$url2    = $english ? '/en/opportunities/' : '/pt-br/oportunidades/';
		$stats   = '';
		foreach ( self::stats( $locale ) as $stat ) {
			$stats .= '<li><strong>' . self::escape( $stat['value'] ) . '</strong><span>' . self::escape( $stat['label'] ) . '</span></li>';
		}
		$visual_title = $english ? 'Laboratory at a glance' : 'O laboratório em resumo';
		$visual_items = $english
			? array(
				'Graduate teaching at COPPE/UFRJ, undergraduate at Poli/UFRJ',
				'National and international research partnerships',
				'Close industry collaboration and technology transfer',
			)
			: array(
				'Pós-graduação na COPPE/UFRJ, graduação na Poli/UFRJ',
				'Parcerias de pesquisa nacionais e internacionais',
				'Colaboração estreita com a indústria e transferência de tecnologia',
			);
		$visual_list  = '';
		foreach ( $visual_items as $item ) {
			$visual_list .= '<li>' . self::escape( $item ) . '</li>';
		}
		return '<section class="lpsx-hero" data-home-section="hero" aria-labelledby="lpsx-home-hero" data-provenance="curated-corpus"><div class="lpsx-wrap lpsx-hero-grid">'
			. '<div class="lpsx-hero-copy"><p class="lpsx-kicker lpsx-kicker-light">' . self::escape( $kicker ) . '</p><h1 id="lpsx-home-hero">' . self::escape( $title ) . '</h1>'
			. '<p class="lpsx-hero-lead">' . self::escape( $lead ) . '</p>'
			. '<p class="lpsx-hero-cta"><a class="lpsx-btn lpsx-btn-light" href="' . $url1 . '">' . self::escape( $cta1 ) . '</a> <a class="lpsx-btn lpsx-btn-ghost-light" href="' . $url2 . '">' . self::escape( $cta2 ) . '</a></p>'
			. '<ul class="lpsx-stats">' . $stats . '</ul></div>'
			. '<aside class="lpsx-hero-card" aria-label="' . self::escape( $visual_title ) . '"><h2>' . self::escape( $visual_title ) . '</h2><ul>' . $visual_list . '</ul></aside>'
			. '</div></section>';
	}

	/** Builds the audience journey cards. */
	private static function journeys_markup( string $locale ): string {
		$english = 'en' === $locale;
		$kicker  = $english ? 'Journeys' : 'Jornadas';
		$heading = $english ? 'Choose your journey' : 'Escolha a sua jornada';
		$cards   = '';
		foreach ( self::journeys( $locale ) as $journey ) {
			$cards .= '<li class="lpsx-card"><div class="lpsx-card-icon">' . self::icon( $journey['icon'] ) . '</div>'
				. '<h3><a href="' . self::escape( $journey['url'] ) . '">' . self::escape( $journey['label'] ) . '</a></h3>'
				. '<p>' . self::escape( $journey['text'] ) . '</p></li>';
		}
		return '<section class="lpsx-section" data-home-section="journeys" aria-labelledby="lpsx-home-journeys" data-provenance="curated-corpus"><div class="lpsx-wrap">'
			. self::section_head( 'lpsx-home-journeys', $kicker, $heading )
			. '<ul class="lpsx-cards lpsx-cards-3">' . $cards . '</ul></div></section>';
	}

	/** Builds the research-area grid. */
	private static function research_markup( string $locale ): string {
		$english = 'en' === $locale;
		$kicker  = $english ? 'Research' : 'Pesquisa';
		$heading = $english ? 'Research at LPS' : 'Pesquisa no LPS';
		$lead    = $english
			? 'Six connected fronts, from energy and high-energy physics to defense, medicine, and data quality.'
			: 'Seis frentes conectadas, de energia e física de altas energias a defesa, medicina e qualidade de dados.';
		$link    = $english ? '/en/research/' : '/pt-br/pesquisa/';
		$link_tx = $english ? 'All research areas' : 'Todas as áreas';
		$cards   = '';
		foreach ( self::research_areas( $locale ) as $area ) {
			$cards .= '<li class="lpsx-card"><div class="lpsx-card-icon">' . self::icon( $area['icon'] ) . '</div>'
				. '<h3><a href="' . self::escape( $area['url'] ) . '">' . self::escape( $area['label'] ) . '</a></h3>'
				. '<p>' . self::escape( $area['text'] ) . '</p></li>';
		}
		return '<section class="lpsx-section lpsx-section-alt" data-home-section="research" aria-labelledby="lpsx-home-research" data-provenance="curated-corpus"><div class="lpsx-wrap">'
			. self::section_head( 'lpsx-home-research', $kicker, $heading, $lead, $link, $link_tx )
			. '<ul class="lpsx-cards lpsx-cards-3">' . $cards . '</ul></div></section>';
	}

	/**
	 * Builds featured projects: reviewed CMS records win, curated index otherwise.
	 *
	 * @param string                           $locale Supported locale.
	 * @param array<int, array<string, mixed>> $records CMS snapshot.
	 * @param string                           $today Institutional date.
	 */
	private static function projects_markup( string $locale, array $records, string $today ): string {
		$english = 'en' === $locale;
		$kicker  = $english ? 'Projects' : 'Projetos';
		$heading = $english ? 'Featured projects' : 'Projetos em destaque';
		$items   = array_values(
			array_filter(
				$records,
				static fn( array $record ): bool => 'projects' === ( $record['section'] ?? '' ) && self::reviewed_feature( $record, $locale, $today )
			)
		);
		$items   = array_slice( $items, 0, 3 );
		if ( array() !== $items ) {
			$cards = '';
			foreach ( $items as $record ) {
				$cards .= '<li class="lpsx-card"><p class="lpsx-card-tag">' . self::escape( $english ? 'Project' : 'Projeto' ) . '</p>'
					. '<h3><a data-source-id="' . self::escape( self::text( $record['source_id'] ?? '' ) ) . '" href="' . self::escape( self::text( $record['url'] ?? '' ) ) . '">' . self::escape( self::text( $record['title'] ?? '' ) ) . '</a></h3>'
					. '<p>' . self::escape( self::text( $record['summary'] ?? '' ) ) . '</p></li>';
			}
			return '<section class="lpsx-section" data-home-section="projects" aria-labelledby="lpsx-home-projects"><div class="lpsx-wrap">'
				. self::section_head( 'lpsx-home-projects', $kicker, $heading )
				. '<ul class="lpsx-cards lpsx-cards-3">' . $cards . '</ul></div></section>';
		}
		$entries = $english
			? array(
				array(
					'title'   => 'Event simulation in HEP',
					'text'    => 'Simulation and reconstruction of high-energy physics events (institutional index entry).',
				),
				array(
					'title'   => 'ATLAS online filtering system',
					'text'    => 'Online event filtering for the ATLAS experiment at CERN (institutional index entry).',
				),
			)
			: array(
				array(
					'title'   => 'Simulação de eventos em HEP',
					'text'    => 'Simulação e reconstrução de eventos em física de altas energias (verbete do índice institucional).',
				),
				array(
					'title'   => 'Sistema de Filtragem Online do ATLAS',
					'text'    => 'Filtragem online de eventos para o experimento ATLAS no CERN (verbete do índice institucional).',
				),
			);
		$cards   = '';
		foreach ( $entries as $entry ) {
			$cards .= '<li class="lpsx-card"><p class="lpsx-card-tag">' . self::escape( $english ? 'Project index' : 'Índice de projetos' ) . '</p>'
				. '<h3>' . self::escape( $entry['title'] ) . '</h3><p>' . self::escape( $entry['text'] ) . '</p></li>';
		}
		$note = $english
			? 'The institutional source publishes the project titles but no scope, dates, team, or status yet. Individual records will appear here once their documentation is reviewed.'
			: 'A fonte institucional publica os títulos dos projetos, mas ainda não escopo, datas, equipe ou situação. Os registros individuais aparecerão aqui após a revisão documental.';
		return '<section class="lpsx-section" data-home-section="projects" aria-labelledby="lpsx-home-projects" data-provenance="curated-corpus"><div class="lpsx-wrap">'
			. self::section_head( 'lpsx-home-projects', $kicker, $heading )
			. '<ul class="lpsx-cards lpsx-cards-2">' . $cards . '</ul><p class="lpsx-note">' . self::escape( $note ) . '</p></div></section>';
	}

	/** Builds the about band with mission and values. */
	private static function about_markup( string $locale ): string {
		$english = 'en' === $locale;
		$kicker  = $english ? 'About' : 'Sobre';
		$heading = $english ? 'About LPS' : 'Sobre o LPS';
		$body    = $english
			? 'Founded in 1996 at UFRJ, the Signal Processing Laboratory generates knowledge and technological innovation through teaching, research, and outreach — training highly qualified professionals at every level and partnering with the public and private sectors.'
			: 'Fundado em 1996 na UFRJ, o Laboratório de Processamento de Sinais gera conhecimento e inovação tecnológica por meio do ensino, da pesquisa e da extensão — formando profissionais altamente qualificados em todos os níveis e atuando com os setores público e privado.';
		$vision  = $english
			? 'Vision: a national and international reference in innovative engineering solutions, focused on computational intelligence, signal processing, and software.'
			: 'Visão: ser referência nacional e internacional em soluções inovadoras de engenharia, com foco em inteligência computacional, processamento de sinais e software.';
		$values  = $english
			? array(
				array(
					'label' => 'Innovation',
					'text'  => 'Vocation for engineering innovation, including technology-based spin-offs.',
				),
				array(
					'label' => 'Collaboration',
					'text'  => 'Solid partnerships across energy, defense, medicine, and physics.',
				),
				array(
					'label' => 'Excellence',
					'text'  => 'Technical, scientific, and innovation excellence in every activity.',
				),
				array(
					'label' => 'Education',
					'text'  => 'A stimulating environment preparing people for academia and industry.',
				),
			)
			: array(
				array(
					'label' => 'Inovação',
					'text'  => 'Vocação para inovar em engenharia, inclusive com empresas de base tecnológica.',
				),
				array(
					'label' => 'Colaboração',
					'text'  => 'Parcerias sólidas em energia, defesa, medicina e física.',
				),
				array(
					'label' => 'Excelência',
					'text'  => 'Excelência técnica, científica e de inovação em todas as atividades.',
				),
				array(
					'label' => 'Formação',
					'text'  => 'Ambiente estimulante que prepara para a academia e a indústria.',
				),
			);
		$items   = '';
		foreach ( $values as $value ) {
			$items .= '<li><strong>' . self::escape( $value['label'] ) . '</strong><span>' . self::escape( $value['text'] ) . '</span></li>';
		}
		$cta  = $english ? 'More about the laboratory' : 'Mais sobre o laboratório';
		$url  = $english ? '/en/about/' : '/pt-br/sobre/';
		return '<section class="lpsx-section lpsx-section-alt" data-home-section="about" aria-labelledby="lpsx-home-about" data-provenance="curated-corpus"><div class="lpsx-wrap lpsx-split">'
			. '<div><p class="lpsx-kicker">' . self::escape( $kicker ) . '</p><h2 id="lpsx-home-about">' . self::escape( $heading ) . '</h2>'
			. '<p>' . self::escape( $body ) . '</p><p>' . self::escape( $vision ) . '</p>'
			. '<p><a class="lpsx-btn lpsx-btn-primary" href="' . $url . '">' . self::escape( $cta ) . '</a></p></div>'
			. '<ul class="lpsx-values">' . $items . '</ul></div></section>';
	}

	/**
	 * Builds the people preview: reviewed CMS records win, curated roster otherwise.
	 *
	 * @param string                           $locale Supported locale.
	 * @param array<int, array<string, mixed>> $records CMS snapshot.
	 * @param string                           $today Institutional date.
	 */
	private static function people_markup( string $locale, array $records, string $today ): string {
		$english = 'en' === $locale;
		$kicker  = $english ? 'People' : 'Pessoas';
		$heading = $english ? 'People and research' : 'Pessoas e pesquisa';
		$lead    = $english
			? 'Four full-time professors — two of them full professors — plus postdocs and graduate and undergraduate students.'
			: 'Quatro professores em tempo integral — dois deles titulares — além de pós-doutorandos e estudantes de pós e graduação.';
		$link    = $english ? '/en/people/' : '/pt-br/pessoas/';
		$link_tx = $english ? 'All people' : 'Todas as pessoas';
		$items   = array_values(
			array_filter(
				$records,
				static fn( array $record ): bool => 'people' === ( $record['section'] ?? '' ) && self::reviewed_feature( $record, $locale, $today )
			)
		);
		$items   = array_slice( $items, 0, 4 );
		if ( array() !== $items ) {
			$cards = '';
			foreach ( $items as $record ) {
				$initials = self::initials( self::text( $record['title'] ?? '' ) );
				$cards   .= '<li class="lpsx-card lpsx-person"><p class="lpsx-avatar" aria-hidden="true">' . self::escape( $initials ) . '</p>'
					. '<h3><a data-source-id="' . self::escape( self::text( $record['source_id'] ?? '' ) ) . '" href="' . self::escape( self::text( $record['url'] ?? '' ) ) . '">' . self::escape( self::text( $record['title'] ?? '' ) ) . '</a></h3>'
					. '<p>' . self::escape( self::text( $record['summary'] ?? '' ) ) . '</p></li>';
			}
			return '<section class="lpsx-section" data-home-section="people" aria-labelledby="lpsx-home-people"><div class="lpsx-wrap">'
				. self::section_head( 'lpsx-home-people', $kicker, $heading, $lead, $link, $link_tx )
				. '<ul class="lpsx-cards lpsx-cards-4">' . $cards . '</ul></div></section>';
		}
		$lattes = $english ? 'Lattes CV' : 'Currículo Lattes';
		$cards  = '';
		foreach ( self::people_fallback( $locale ) as $person ) {
			$cards .= '<li class="lpsx-card lpsx-person"><p class="lpsx-avatar" aria-hidden="true">' . self::escape( self::initials( $person['name'] ) ) . '</p>'
				. '<h3>' . self::escape( $person['name'] ) . '</h3>'
				. '<p class="lpsx-person-role">' . self::escape( $person['role'] ) . '</p>'
				. '<p>' . self::escape( $person['focus'] ) . '</p>'
				. '<p><a href="' . self::escape( $person['url'] ) . '" rel="external">' . self::escape( $lattes ) . '</a></p></li>';
		}
		$memorial = $english
			? 'In memoriam: Prof. Antônio Carlos Moreirão de Queiroz, former full professor at DEL and PESC.'
			: 'In memoriam: Prof. Antônio Carlos Moreirão de Queiroz, ex-professor titular do DEL e do PESC.';
		return '<section class="lpsx-section" data-home-section="people" aria-labelledby="lpsx-home-people" data-provenance="curated-corpus"><div class="lpsx-wrap">'
			. self::section_head( 'lpsx-home-people', $kicker, $heading, $lead, $link, $link_tx )
			. '<ul class="lpsx-cards lpsx-cards-4">' . $cards . '</ul><p class="lpsx-note">' . self::escape( $memorial ) . '</p></div></section>';
	}

	/** Builds the infrastructure band. */
	private static function infrastructure_markup( string $locale ): string {
		$english = 'en' === $locale;
		$kicker  = $english ? 'Infrastructure' : 'Infraestrutura';
		$heading = $english ? 'Infrastructure and capabilities' : 'Infraestrutura e capacidades';
		$body    = $english
			? 'Across 310 m², LPS runs a presentation and virtual-meeting room, an acoustic room, about 40 PCs on the LPS domain, programmable-device development software, and state-of-the-art instrumentation for system development and analysis.'
			: 'Em 310 m², o LPS mantém sala de apresentações e reuniões virtuais, sala acústica, cerca de 40 computadores no domínio LPS, software para dispositivos programáveis e instrumentação de ponta para desenvolvimento e análise de sistemas.';
		$points  = $english
			? array( '310 m² of equipped laboratories', 'Presentation room + acoustic room', '~40 networked PCs on the LPS domain', 'State-of-the-art instrumentation' )
			: array( '310 m² de laboratórios equipados', 'Sala de apresentações + sala acústica', '~40 PCs em rede no domínio LPS', 'Instrumentação no estado da arte' );
		$items   = '';
		foreach ( $points as $point ) {
			$items .= '<li>' . self::escape( $point ) . '</li>';
		}
		$cta  = $english ? 'Technical documentation' : 'Documentação técnica';
		$docs = 'https://lps-ufrj-br.github.io/datacenter/';
		return '<section class="lpsx-section lpsx-section-alt" data-home-section="infrastructure" aria-labelledby="lpsx-home-infrastructure" data-provenance="curated-corpus"><div class="lpsx-wrap lpsx-split">'
			. '<div><p class="lpsx-kicker">' . self::escape( $kicker ) . '</p><h2 id="lpsx-home-infrastructure">' . self::escape( $heading ) . '</h2>'
			. '<p>' . self::escape( $body ) . '</p>'
			. '<p><a class="lpsx-btn lpsx-btn-secondary" href="' . $docs . '" rel="external">' . self::escape( $cta ) . '</a></p></div>'
			. '<ul class="lpsx-checklist">' . $items . '</ul></div></section>';
	}

	/**
	 * Builds latest publications/news/events strictly from reviewed CMS records.
	 *
	 * @param string                           $locale Supported locale.
	 * @param array<int, array<string, mixed>> $records CMS snapshot.
	 * @param string                           $today Institutional date.
	 */
	private static function latest_markup( string $locale, array $records, string $today ): string {
		$english = 'en' === $locale;
		$kicker  = $english ? 'Latest' : 'Recentes';
		$heading = $english ? 'Publications, news, and events' : 'Publicações, notícias e eventos';
		$items   = array_values(
			array_filter(
				$records,
				static fn( array $record ): bool => 'latest' === ( $record['section'] ?? '' ) && self::reviewed_feature( $record, $locale, $today )
			)
		);
		usort( $items, static fn( array $left, array $right ): int => strcmp( self::text( $right['date'] ?? '' ), self::text( $left['date'] ?? '' ) ) );
		$items = array_slice( $items, 0, 3 );
		if ( array() === $items ) {
			$empty = $english ? 'Reviewed publications, news, and events will appear here once they are published.' : 'Publicações, notícias e eventos revisados aparecerão aqui após a publicação.';
			return '<section class="lpsx-section" data-home-section="latest" aria-labelledby="lpsx-home-latest"><div class="lpsx-wrap">'
				. self::section_head( 'lpsx-home-latest', $kicker, $heading )
				. '<div class="lpsx-empty" data-home-empty="latest"><p>' . self::escape( $empty ) . '</p></div></div></section>';
		}
		$cards = '';
		foreach ( $items as $record ) {
			$date = self::text( $record['date'] ?? '' );
			$tag  = '' === $date ? '' : '<p class="lpsx-card-tag">' . self::escape( $date ) . '</p>';
			$cards .= '<li class="lpsx-card">' . $tag
				. '<h3><a data-source-id="' . self::escape( self::text( $record['source_id'] ?? '' ) ) . '" href="' . self::escape( self::text( $record['url'] ?? '' ) ) . '">' . self::escape( self::text( $record['title'] ?? '' ) ) . '</a></h3>'
				. '<p>' . self::escape( self::text( $record['summary'] ?? '' ) ) . '</p></li>';
		}
		return '<section class="lpsx-section" data-home-section="latest" aria-labelledby="lpsx-home-latest"><div class="lpsx-wrap">'
			. self::section_head( 'lpsx-home-latest', $kicker, $heading )
			. '<ul class="lpsx-cards lpsx-cards-3">' . $cards . '</ul></div></section>';
	}

	/**
	 * Builds the partners strip: reviewed organizations win, curated roster otherwise.
	 *
	 * @param string                           $locale Supported locale.
	 * @param array<int, array<string, mixed>> $records CMS snapshot.
	 * @param string                           $today Institutional date.
	 */
	private static function partners_markup( string $locale, array $records, string $today ): string {
		$english = 'en' === $locale;
		$kicker  = $english ? 'Partners' : 'Parceiros';
		$heading = $english ? 'Partners and funders' : 'Parceiros e financiadores';
		$lead    = $english
			? 'A long tradition of collaboration with industry and research institutions, in Brazil and abroad.'
			: 'Longa tradição de colaboração com a indústria e instituições de pesquisa, no Brasil e no exterior.';
		$items   = array_values(
			array_filter(
				$records,
				static fn( array $record ): bool => 'partners' === ( $record['section'] ?? '' ) && self::reviewed_feature( $record, $locale, $today )
			)
		);
		if ( array() !== $items ) {
			$chips = '';
			foreach ( array_slice( $items, 0, 12 ) as $record ) {
				$chips .= '<li><a data-source-id="' . self::escape( self::text( $record['source_id'] ?? '' ) ) . '" href="' . self::escape( self::text( $record['url'] ?? '' ) ) . '">' . self::escape( self::text( $record['title'] ?? '' ) ) . '</a></li>';
			}
			return '<section class="lpsx-section lpsx-section-alt" data-home-section="partners" aria-labelledby="lpsx-home-partners"><div class="lpsx-wrap">'
				. self::section_head( 'lpsx-home-partners', $kicker, $heading, $lead )
				. '<ul class="lpsx-chips">' . $chips . '</ul></div></section>';
		}
		$chips = '';
		foreach ( self::partners_fallback() as $partner ) {
			$chips .= '<li>' . self::escape( $partner ) . '</li>';
		}
		return '<section class="lpsx-section lpsx-section-alt" data-home-section="partners" aria-labelledby="lpsx-home-partners" data-provenance="curated-corpus"><div class="lpsx-wrap">'
			. self::section_head( 'lpsx-home-partners', $kicker, $heading, $lead )
			. '<ul class="lpsx-chips">' . $chips . '</ul></div></section>';
	}

	/** Builds the closing collaboration CTA band. */
	private static function contact_markup( string $locale ): string {
		$english = 'en' === $locale;
		$heading = $english ? 'Let’s build the next signal together.' : 'Vamos construir o próximo sinal juntos.';
		$body    = $english
			? 'Study, research, or partner with a laboratory that has connected UFRJ science to industry since 1996.'
			: 'Estude, pesquise ou seja parceiro de um laboratório que conecta a ciência da UFRJ à indústria desde 1996.';
		$cta1    = $english ? 'Opportunities' : 'Oportunidades';
		$cta2    = $english ? 'Contact' : 'Contato';
		$url1    = $english ? '/en/opportunities/' : '/pt-br/oportunidades/';
		$url2    = $english ? '/en/contact/' : '/pt-br/contato/';
		return '<section class="lpsx-cta" data-home-section="contact" aria-labelledby="lpsx-home-contact" data-provenance="curated-corpus"><div class="lpsx-wrap lpsx-cta-inner">'
			. '<h2 id="lpsx-home-contact">' . self::escape( $heading ) . '</h2><p>' . self::escape( $body ) . '</p>'
			. '<p class="lpsx-cta-actions"><a class="lpsx-btn lpsx-btn-light" href="' . $url1 . '">' . self::escape( $cta1 ) . '</a> <a class="lpsx-btn lpsx-btn-ghost-light" href="' . $url2 . '">' . self::escape( $cta2 ) . '</a></p>'
			. '</div></section>';
	}

	/**
	 * Derives display initials from a person name.
	 *
	 * @param string $name Full name.
	 */
	private static function initials( string $name ): string {
		$words = preg_split( '/\s+/', trim( $name ) );
		if ( ! is_array( $words ) || array() === $words ) {
			return '';
		}
		$first = mb_substr( $words[0], 0, 1, 'UTF-8' );
		$last  = count( $words ) > 1 ? mb_substr( $words[ count( $words ) - 1 ], 0, 1, 'UTF-8' ) : '';
		return mb_strtoupper( $first . $last, 'UTF-8' );
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
