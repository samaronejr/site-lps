<?php
/**
 * Server-rendered search results, facets, counts, and boundary states.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

/**
 * Renders the locale search surface without any client-side dependency.
 *
 * Every control is a plain GET form field, so the surface works identically
 * with scripting disabled and every state stays addressable by URL.
 */
final class SearchSurfaces {
	/**
	 * Localized copy for both supported locales.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const COPY = array(
		'pt-br' => array(
			'kicker'         => 'Busca',
			'legend'         => 'Buscar no site',
			'label'          => 'O que você procura?',
			'hint'           => 'Tente uma linha de pesquisa, um nome de professor, um código de disciplina ou um parceiro.',
			'header_lead'    => 'A busca roda no servidor e funciona sem JavaScript. Os filtros restringem o resultado por coleção, área e ano.',
			'record'         => 'Tipo de registro',
			'all'            => 'Todos os tipos',
			'submit'         => 'Buscar',
			'filters'        => 'Filtros',
			'apply'          => 'Aplicar filtros',
			'clear'          => 'Limpar filtros',
			'results'        => 'Resultados da busca',
			'empty'          => 'Nenhum registro corresponde a esta busca.',
			'empty_hint'     => 'Revise a grafia, remova filtros ou use um termo mais curto.',
			'prompt'         => 'Digite um termo para buscar registros publicados neste idioma.',
			'too_short'      => 'A busca precisa de pelo menos 2 caracteres.',
			'too_long'       => 'A busca aceita no máximo 100 caracteres.',
			'malformed'      => 'A busca contém caracteres que não podem ser interpretados.',
			'pagination'     => 'Paginação dos resultados',
			'previous'       => 'Página anterior',
			'next'           => 'Próxima página',
			'page'           => 'Página',
			'previous_short' => 'Anterior',
			'next_short'     => 'Próxima',
			'one_result'     => '1 resultado',
			'many_results'   => '%d resultados',
			'no_results'     => '0 resultado',
			'showing'        => 'Mostrando %1$d–%2$d de %3$d',
			'no_facets'      => 'Nenhum filtro disponível para este tipo.',
			'current_search' => 'Busca atual',
		),
		'en'    => array(
			'kicker'         => 'Search',
			'legend'         => 'Search the site',
			'label'          => 'What are you looking for?',
			'hint'           => 'Try a research line, a professor\'s name, a course code or a partner.',
			'header_lead'    => 'Search runs on the server and works without JavaScript. Filters narrow results by collection, area and year.',
			'record'         => 'Record type',
			'all'            => 'All types',
			'submit'         => 'Search',
			'filters'        => 'Filters',
			'apply'          => 'Apply filters',
			'clear'          => 'Clear filters',
			'results'        => 'Search results',
			'empty'          => 'No record matches this search.',
			'empty_hint'     => 'Check the spelling, remove filters, or use a shorter term.',
			'prompt'         => 'Enter a term to search records published in this language.',
			'too_short'      => 'Search needs at least 2 characters.',
			'too_long'       => 'Search accepts at most 100 characters.',
			'malformed'      => 'The search contains characters that cannot be interpreted.',
			'pagination'     => 'Results pagination',
			'previous'       => 'Previous page',
			'next'           => 'Next page',
			'page'           => 'Page',
			'previous_short' => 'Previous',
			'next_short'     => 'Next',
			'one_result'     => '1 result',
			'many_results'   => '%d results',
			'no_results'     => '0 results',
			'showing'        => 'Showing %1$d–%2$d of %3$d',
			'no_facets'      => 'No filter is available for this type.',
			'current_search' => 'Current search',
		),
	);

	/**
	 * Localized record type labels.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const TYPE_LABELS = array(
		'pt-br' => array(
			'lps_publication'   => 'Publicações',
			'lps_project'       => 'Projetos',
			'lps_person'        => 'Pessoas',
			'lps_news'          => 'Notícias',
			'lps_opportunity'   => 'Oportunidades',
			'lps_event'         => 'Eventos',
			'lps_research_area' => 'Áreas de pesquisa',
			'lps_course'        => 'Disciplinas',
			'lps_offering'      => 'Turmas',
			'lps_resource'      => 'Materiais de ensino',
		),
		'en'    => array(
			'lps_publication'   => 'Publications',
			'lps_project'       => 'Projects',
			'lps_person'        => 'People',
			'lps_news'          => 'News',
			'lps_opportunity'   => 'Opportunities',
			'lps_event'         => 'Events',
			'lps_research_area' => 'Research areas',
			'lps_course'        => 'Courses',
			'lps_offering'      => 'Offerings',
			'lps_resource'      => 'Teaching materials',
		),
	);

	/**
	 * Localized facet labels.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const FACET_LABELS = array(
		'pt-br' => array(
			'year'       => 'Ano',
			'type'       => 'Tipo',
			'person'     => 'Pessoa',
			'project'    => 'Projeto',
			'area'       => 'Área de pesquisa',
			'domain'     => 'Domínio de aplicação',
			'status'     => 'Situação',
			'role'       => 'Papel',
			'category'   => 'Categoria',
			'state'      => 'Estado',
			'audience'   => 'Público',
			'date'       => 'Data',
			'term'       => 'Período letivo',
			'level'      => 'Nível',
			'instructor' => 'Docente',
			'language'   => 'Idioma',
		),
		'en'    => array(
			'year'       => 'Year',
			'type'       => 'Type',
			'person'     => 'Person',
			'project'    => 'Project',
			'area'       => 'Research area',
			'domain'     => 'Application domain',
			'status'     => 'Status',
			'role'       => 'Role',
			'category'   => 'Category',
			'state'      => 'State',
			'audience'   => 'Audience',
			'date'       => 'Date',
			'term'       => 'Academic term',
			'level'      => 'Level',
			'instructor' => 'Instructor',
			'language'   => 'Language',
		),
	);

	/**
	 * Localized labels for closed-vocabulary facet values.
	 *
	 * Open-vocabulary values (terms, instructors, languages, types) render
	 * their stored slug; closed vocabularies get readable labels so the
	 * current/previous offering filter is clear in both locales.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private const FACET_VALUE_LABELS = array(
		'status' => array(
			'current'  => array(
				'pt-br' => 'Atuais',
				'en'    => 'Current',
			),
			'previous' => array(
				'pt-br' => 'Anteriores',
				'en'    => 'Previous',
			),
		),
		'level'  => array(
			'undergraduate' => array(
				'pt-br' => 'Graduação',
				'en'    => 'Undergraduate',
			),
			'graduate'      => array(
				'pt-br' => 'Pós-graduação',
				'en'    => 'Graduate',
			),
			'extension'     => array(
				'pt-br' => 'Extensão',
				'en'    => 'Extension',
			),
		),
	);

	/**
	 * Returns one localized string.
	 *
	 * @param string $key    Copy key.
	 * @param string $locale Supported locale slug.
	 */
	public static function copy( string $key, string $locale ): string {
		$table = self::COPY[ $locale ] ?? self::COPY['pt-br'];
		return $table[ $key ] ?? '';
	}

	/**
	 * Returns the accessible message for one query boundary violation.
	 *
	 * @param string $code   Boundary violation code.
	 * @param string $locale Supported locale slug.
	 */
	public static function error_message( string $code, string $locale ): string {
		if ( 'too_short' === $code || 'too_long' === $code || 'malformed' === $code ) {
			return self::copy( $code, $locale );
		}
		return '';
	}

	/**
	 * Returns the announced result count.
	 *
	 * @param int    $total  Matched record count.
	 * @param string $locale Supported locale slug.
	 */
	public static function count_label( int $total, string $locale ): string {
		if ( 0 >= $total ) {
			return self::copy( 'no_results', $locale );
		}
		if ( 1 === $total ) {
			return self::copy( 'one_result', $locale );
		}
		return sprintf( self::copy( 'many_results', $locale ), $total );
	}

	/**
	 * Renders the complete search surface.
	 *
	 * @param array<string, mixed>              $state        Sanitized request state.
	 * @param array<string, mixed>              $result       Search result payload.
	 * @param array<string, array<string, int>> $facet_counts Available facet values with counts.
	 * @param array<string, array<int, string>> $definitions  Approved facet contract of the selected type.
	 * @param string                            $locale       Supported locale slug.
	 * @param string                            $action       Route path the form submits to.
	 */
	public static function render( array $state, array $result, array $facet_counts, array $definitions, string $locale, string $action ): string {
		$query  = self::text( $state['query'] ?? '' );
		$record = self::text( $state['record'] ?? '' );
		$facets = self::facet_state( $state['facets'] ?? array() );
		$error  = self::text( $state['error'] ?? '' );
		$total  = self::number( $result['total'] ?? 0 );

		$header = class_exists( Shell::class )
			? Shell::page_header_markup(
				array(
					'kicker' => self::copy( 'kicker', $locale ),
					'title'  => self::copy( 'legend', $locale ),
					'lead'   => self::copy( 'header_lead', $locale ),
				),
				$locale
			)
			: '';

		$inner  = self::form( $query, $record, $facets, $facet_counts, $definitions, $locale, $action );
		$inner .= '<a class="lps-button lps-button-primary" href="' . ( 'en' === $locale ? '/en/collaborate/' : '/pt-br/colabore/' ) . '">' . self::esc( 'en' === $locale ? 'Collaborate' : 'Colabore' ) . '</a>';
		if ( '' !== $error ) {
			$inner .= '<p class="lps-search-error" id="lps-search-status" role="alert">' . self::esc( self::error_message( $error, $locale ) ) . '</p>';
		} elseif ( '' === trim( $query ) ) {
			$inner .= '<p class="lps-search-prompt" id="lps-search-status" role="status">' . self::esc( self::copy( 'prompt', $locale ) ) . '</p>';
		} else {
			$inner .= '<p class="lps-search-count" id="lps-search-status" role="status">' . self::esc( self::count_label( $total, $locale ) ) . '</p>';
			if ( 0 === $total ) {
				$inner .= '<p class="lps-search-empty">' . self::esc( self::copy( 'empty', $locale ) ) . '</p>';
				$inner .= '<p class="lps-search-empty-hint">' . self::esc( self::copy( 'empty_hint', $locale ) ) . '</p>';
			} else {
				$inner .= self::results( $result, $locale );
				$inner .= self::pagination( $result, $state, $locale, $action );
			}
		}
		return $header . '<section class="lps-search lps-search-surface lps-section lps-section--flush" aria-labelledby="lps-search-title"><h2 id="lps-search-title" class="screen-reader-text">' . self::esc( self::copy( 'legend', $locale ) ) . '</h2>' . $inner . '</section>';
	}

	/**
	 * Renders the no-JavaScript search form including its facet controls.
	 *
	 * @param string                            $query        Raw query string.
	 * @param string                            $record       Selected record type.
	 * @param array<string, array<int, string>> $facets       Active facet values.
	 * @param array<string, array<string, int>> $facet_counts Available facet values with counts.
	 * @param array<string, array<int, string>> $definitions  Approved facet contract.
	 * @param string                            $locale       Supported locale slug.
	 * @param string                            $action       Route path the form submits to.
	 */
	private static function form( string $query, string $record, array $facets, array $facet_counts, array $definitions, string $locale, string $action ): string {
		$html  = '<form class="lps-search-form" method="get" action="' . self::esc( $action ) . '" role="search" aria-label="' . self::esc( self::copy( 'legend', $locale ) ) . '">';
		$html .= '<label for="lps-search-q">' . self::esc( self::copy( 'label', $locale ) ) . '</label>';
		$html .= '<div class="lps-search-row">';
		$html .= '<input type="search" id="lps-search-q" name="q" value="' . self::esc( $query ) . '" minlength="2" maxlength="100" autocomplete="off" aria-describedby="lps-search-hint">';
		$html .= '<button class="lps-button lps-button-primary" type="submit">' . self::esc( self::copy( 'submit', $locale ) ) . '</button>';
		$html .= '</div>';
		$html .= '<p class="lps-search-hint" id="lps-search-hint">' . self::esc( self::copy( 'hint', $locale ) ) . '</p>';
		$html .= '<p><label for="lps-search-record">' . self::esc( self::copy( 'record', $locale ) ) . '</label>';
		$html .= '<select id="lps-search-record" name="record">';
		$html .= '<option value=""' . ( '' === $record ? ' selected' : '' ) . '>' . self::esc( self::copy( 'all', $locale ) ) . '</option>';
		foreach ( self::TYPE_LABELS[ $locale ] ?? self::TYPE_LABELS['pt-br'] as $type => $label ) {
			$html .= '<option value="' . self::esc( $type ) . '"' . ( $type === $record ? ' selected' : '' ) . '>' . self::esc( $label ) . '</option>';
		}
		$html .= '</select></p>';
		if ( array() !== $definitions ) {
			$html .= '<fieldset class="lps-search-facets"><legend>' . self::esc( self::copy( 'filters', $locale ) ) . '</legend><div class="lps-grid lps-grid--3">';
			foreach ( $definitions as $facet => $allowed ) {
				$values = $facet_counts[ $facet ] ?? array();
				if ( array() === $values ) {
					continue;
				}
				$html .= '<fieldset class="lps-search-facet"><legend>' . self::esc( self::facet_label( $facet, $locale ) ) . '</legend><ul class="lps-search-facet-values">';
				foreach ( $values as $value => $count ) {
					$id      = 'lps-facet-' . $facet . '-' . preg_replace( '/[^a-z0-9-]/', '', (string) $value );
					$checked = in_array( (string) $value, $facets[ $facet ] ?? array(), true ) ? ' checked' : '';
					$html   .= '<li class="lps-search-facet-value">';
					$html   .= '<input type="checkbox" id="' . self::esc( (string) $id ) . '" name="' . self::esc( $facet ) . '[]" value="' . self::esc( (string) $value ) . '"' . $checked . '>';
					$html   .= '<label for="' . self::esc( (string) $id ) . '">' . self::esc( self::facet_value_label( $facet, (string) $value, $locale ) ) . ' <span class="lps-facet-count">(' . self::number( $count ) . ')</span></label>';
					$html   .= '</li>';
				}
				$html .= '</ul></fieldset>';
			}
			$html .= '</div></fieldset>';
		}
		return $html . '</form>';
	}

	/**
	 * Renders the matched records.
	 *
	 * @param array<string, mixed> $result Search result payload.
	 * @param string               $locale Supported locale slug.
	 */
	private static function results( array $result, string $locale ): string {
		$items = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		$html  = '<h2 id="lps-search-results" class="lps-mt-8">' . self::esc( self::copy( 'results', $locale ) ) . '</h2>';
		$html .= '<ol class="lps-search-results lps-mt-4" aria-labelledby="lps-search-results">';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$title   = self::text( $item['title'] ?? '' );
			$url     = self::safe_url( self::text( $item['url'] ?? '' ) );
			$summary = self::text( $item['summary'] ?? '' );
			$type    = self::type_label( self::text( $item['post_type'] ?? '' ), $locale );
			$html   .= '<li class="lps-search-result">';
			if ( '' !== $type ) {
				$html .= '<p class="lps-search-kind">' . self::esc( $type ) . '</p>';
			}
			$html .= '<h3>' . ( '' === $url ? self::esc( $title ) : '<a href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a>' ) . '</h3>';
			if ( '' !== $summary ) {
				$html .= '<p class="lps-summary">' . self::esc( $summary ) . '</p>';
			}
			$html .= '</li>';
		}
		return $html . '</ol>';
	}

	/**
	 * Renders canonical, shareable pagination links.
	 *
	 * @param array<string, mixed> $result Search result payload.
	 * @param array<string, mixed> $state  Sanitized request state.
	 * @param string               $locale Supported locale slug.
	 * @param string               $action Route path.
	 */
	private static function pagination( array $result, array $state, string $locale, string $action ): string {
		$pages   = self::number( $result['pages'] ?? 0 );
		$current = self::number( $result['page'] ?? 1 );
		if ( 2 > $pages ) {
			return '';
		}
		$per_page = self::number( $result['per_page'] ?? 20 );
		$total    = self::number( $result['total'] ?? 0 );
		$first    = ( ( $current - 1 ) * $per_page ) + 1;
		$last     = min( $total, $current * $per_page );
		$html     = '<nav class="lps-search-pagination" aria-label="' . self::esc( self::copy( 'pagination', $locale ) ) . '">';
		$html    .= '<p class="lps-search-range">' . self::esc( sprintf( self::copy( 'showing', $locale ), $first, $last, $total ) ) . '</p><ul>';
		$previous = self::copy( 'previous', $locale );
		if ( 1 < $current ) {
			$url   = SearchRoutes::canonical_url( $action, $state, $current - 1 );
			$html .= '<li><a class="lps-search-step" href="' . self::esc( $url ) . '" aria-label="' . self::esc( $previous ) . '">' . self::esc( self::copy( 'previous_short', $locale ) ) . '</a></li>';
		} else {
			$html .= '<li><span class="lps-search-step" aria-disabled="true">' . self::esc( self::copy( 'previous_short', $locale ) ) . '</span></li>';
		}
		for ( $page = 1; $page <= $pages; $page++ ) {
			$label = self::copy( 'page', $locale ) . ' ' . $page;
			if ( $page === $current ) {
				$html .= '<li aria-current="page"><span>' . $page . '</span></li>';
				continue;
			}
			$url   = SearchRoutes::canonical_url( $action, $state, $page );
			$html .= '<li><a href="' . self::esc( $url ) . '" aria-label="' . self::esc( $label ) . '">' . $page . '</a></li>';
		}
		$html .= '<li><span class="lps-meta">' . self::esc( $current . ' / ' . $pages ) . '</span></li>';
		$next  = self::copy( 'next', $locale );
		if ( $current < $pages ) {
			$url   = SearchRoutes::canonical_url( $action, $state, $current + 1 );
			$html .= '<li><a class="lps-search-step" href="' . self::esc( $url ) . '" aria-label="' . self::esc( $next ) . '">' . self::esc( self::copy( 'next_short', $locale ) ) . '</a></li>';
		} else {
			$html .= '<li><span class="lps-search-step" aria-disabled="true">' . self::esc( self::copy( 'next_short', $locale ) ) . '</span></li>';
		}
		return $html . '</ul></nav>';
	}

	/**
	 * Returns the localized label of one record type.
	 *
	 * @param string $post_type Record type.
	 * @param string $locale    Supported locale slug.
	 */
	public static function type_label( string $post_type, string $locale ): string {
		$table = self::TYPE_LABELS[ $locale ] ?? self::TYPE_LABELS['pt-br'];
		return $table[ $post_type ] ?? '';
	}

	/**
	 * Returns the localized label of one facet.
	 *
	 * @param string $facet  Facet key.
	 * @param string $locale Supported locale slug.
	 */
	public static function facet_label( string $facet, string $locale ): string {
		$table = self::FACET_LABELS[ $locale ] ?? self::FACET_LABELS['pt-br'];
		return $table[ $facet ] ?? $facet;
	}

	/**
	 * Returns the localized label of one facet value, or the raw slug.
	 *
	 * @param string $facet  Facet key.
	 * @param string $value  Stored facet value.
	 * @param string $locale Supported locale slug.
	 */
	public static function facet_value_label( string $facet, string $value, string $locale ): string {
		$labels = self::FACET_VALUE_LABELS[ $facet ][ $value ] ?? null;
		if ( is_array( $labels ) ) {
			return $labels[ $locale ] ?? $labels['pt-br'];
		}
		return $value;
	}

	/**
	 * Normalizes the active facet state.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<string, array<int, string>>
	 */
	private static function facet_state( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$state = array();
		foreach ( $value as $facet => $values ) {
			if ( ! is_string( $facet ) || ! is_array( $values ) ) {
				continue;
			}
			$list = array();
			foreach ( $values as $item ) {
				if ( is_string( $item ) ) {
					$list[] = $item;
				}
			}
			$state[ $facet ] = $list;
		}
		return $state;
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

	/**
	 * Escapes one text value for markup.
	 *
	 * @param string $value Untrusted text value.
	 */
	private static function esc( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Returns the URL only when its scheme is safe to render as a link.
	 *
	 * @param string $url Untrusted URL value.
	 */
	private static function safe_url( string $url ): string {
		$clean = trim( $url );
		if ( '' === $clean ) {
			return '';
		}
		$scheme = parse_url( $clean, PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this renderer runs without WordPress loaded in unit tests.
		if ( is_string( $scheme ) ) {
			return in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ? $clean : '';
		}
		return 1 === preg_match( '#^/(?!/)#', $clean ) ? $clean : '';
	}
}
