<?php
/**
 * Research, project, and publication discovery surfaces.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

/** Owns locale-aware discovery rendering and citation exports. */
final class DiscoverySurfaces {
	/**
	 * Formats a date honoring known precision.
	 *
	 * @param string $date      Date in YYYY-MM-DD or empty.
	 * @param string $precision One of unknown, year, month, day.
	 * @param string $locale    Supported locale slug.
	 */
	public static function format_date( string $date, string $precision, string $locale ): string {
		$english = 'en' === $locale;
		$unknown = $english ? 'Date not provided' : 'Data não informada';
		$clean   = trim( $date );
		if ( 'unknown' === $precision || '' === $clean ) {
			return $unknown;
		}
		if ( 1 !== preg_match( '/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/', $clean, $parts ) ) {
			return $unknown;
		}
		$year  = (int) $parts[1];
		$month = isset( $parts[2] ) ? (int) $parts[2] : 0;
		$day   = isset( $parts[3] ) ? (int) $parts[3] : 0;
		if ( 0 !== $month && ( 1 > $month || 12 < $month ) ) {
			return $unknown;
		}
		if ( 0 !== $day && ! checkdate( $month, $day, $year ) ) {
			return $unknown;
		}
		if ( 'year' === $precision || 0 === $month ) {
			return (string) $year;
		}
		$months = self::month_names( $english );
		if ( 'month' === $precision || 0 === $day ) {
			return $english ? $months[ $month ] . ' ' . $year : $months[ $month ] . ' de ' . $year;
		}
		return $english ? $day . ' ' . $months[ $month ] . ' ' . $year : $day . ' de ' . $months[ $month ] . ' de ' . $year;
	}

	/**
	 * Returns localized month names indexed by month number.
	 *
	 * @param bool $english Whether the English locale is active.
	 * @return array<int, string>
	 */
	private static function month_names( bool $english ): array {
		if ( $english ) {
			return array(
				1  => 'January',
				2  => 'February',
				3  => 'March',
				4  => 'April',
				5  => 'May',
				6  => 'June',
				7  => 'July',
				8  => 'August',
				9  => 'September',
				10 => 'October',
				11 => 'November',
				12 => 'December',
			);
		}
		return array(
			1  => 'janeiro',
			2  => 'fevereiro',
			3  => 'março',
			4  => 'abril',
			5  => 'maio',
			6  => 'junho',
			7  => 'julho',
			8  => 'agosto',
			9  => 'setembro',
			10 => 'outubro',
			11 => 'novembro',
			12 => 'dezembro',
		);
	}

	/**
	 * Renders a research area with its published projects.
	 *
	 * @param array<string, mixed> $record   Research area record.
	 * @param array<int, mixed>    $projects Published projects in this area.
	 * @param string               $locale   Supported locale slug.
	 */
	public static function render_area( array $record, array $projects, string $locale ): string {
		$english = 'en' === $locale;
		$title   = self::text( $record['title'] ?? '' );
		$summary = self::text( $record['summary'] ?? '' );
		$body    = self::text( $record['body'] ?? '' );
		$html    = '<article class="lps-research-area">';
		$html   .= '<h1>' . self::esc( $title ) . '</h1>';
		if ( '' !== $summary ) {
			$html .= '<p class="lps-summary">' . self::esc( $summary ) . '</p>';
		}
		if ( '' !== $body ) {
			$html .= '<div class="lps-body lps-reading">' . self::rich( $body ) . '</div>';
		}
		// The projects stratum is a named section of record rows, not a bare list.
		$html .= '<h2>' . self::esc( $english ? 'Projects' : 'Projetos' ) . '</h2>';
		if ( array() === $projects ) {
			$html .= '<p class="lps-empty">' . self::esc( $english ? 'No published projects in this area' : 'Nenhum projeto publicado nesta área' ) . '</p>';
			return $html . '</article>';
		}
		$html .= '<ul class="lps-area-projects">';
		foreach ( $projects as $project ) {
			if ( ! is_array( $project ) ) {
				continue;
			}
			$project_title = self::text( $project['title'] ?? '' );
			$project_url   = self::safe_url( self::text( $project['url'] ?? '' ) );
			if ( '' === $project_title ) {
				continue;
			}
			$html           .= '<li class="lps-record"><h3>';
			$html           .= '' === $project_url ? self::esc( $project_title ) : '<a href="' . self::esc( $project_url ) . '">' . self::esc( $project_title ) . '</a>';
			$html           .= '</h3>';
			$project_summary = self::text( $project['summary'] ?? '' );
			if ( '' !== $project_summary ) {
				$html .= '<p>' . self::esc( $project_summary ) . '</p>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		return $html . '</article>';
	}

	/**
	 * Renders a project with summary before technical body.
	 *
	 * @param array<string, mixed> $record        Project record.
	 * @param array<string, mixed> $relationships Derived relationships.
	 * @param string               $locale        Supported locale slug.
	 */
	public static function render_project( array $record, array $relationships, string $locale ): string {
		$english      = 'en' === $locale;
		$title        = self::text( $record['title'] ?? '' );
		$summary      = self::text( $record['summary'] ?? '' );
		$body         = self::text( $record['body'] ?? '' );
		$status       = self::text( $record['status'] ?? '' );
		$status_label = self::project_status_label( $status, $locale );
		if ( '' === $status_label ) {
			$status_label = $english ? 'Ongoing project' : 'Projeto em andamento';
		}
		$status_class = 'lps-status';
		if ( in_array( $status, array( 'active', 'current' ), true ) ) {
			$status_class .= ' lps-status-success';
		} elseif ( 'suspended' === $status ) {
			$status_class .= ' lps-status-warning';
		}
		$html  = '<article class="lps-project">';
		$html .= '<h1>' . self::esc( $title ) . '</h1>';
		$html .= '<p class="lps-meta"><span class="' . $status_class . '">' . self::esc( $status_label ) . '</span>';
		$html .= self::project_dates_meta( $record, $locale );
		$html .= '</p>';
		if ( '' !== $summary ) {
			$html .= '<p class="lps-summary">' . self::esc( $summary ) . '</p>';
		}
		if ( '' !== $body ) {
			$html .= '<div class="lps-body lps-reading">' . self::rich( $body ) . '</div>';
		}
		$members = isset( $relationships['members'] ) && is_array( $relationships['members'] ) ? $relationships['members'] : array();
		if ( array() !== $members ) {
			$html .= '<h2>' . self::esc( $english ? 'Team' : 'Equipe' ) . '</h2>';
			$html .= '<ul class="lps-members">';
			foreach ( $members as $member ) {
				if ( ! is_array( $member ) ) {
					continue;
				}
				$name = self::text( $member['name'] ?? '' );
				$url  = self::safe_url( self::text( $member['url'] ?? '' ) );
				if ( '' === $name ) {
					continue;
				}
				if ( self::is_archived( $member ) ) {
					$archived_label = $english ? 'Archived member' : 'Integrante arquivado';
					$html          .= '<li><span>' . self::esc( $name ) . '</span> <span class="lps-status">' . self::esc( $archived_label ) . '</span></li>';
					continue;
				}
				if ( '' !== $url ) {
					$html .= '<li><a href="' . self::esc( $url ) . '">' . self::esc( $name ) . '</a></li>';
				} else {
					$html .= '<li>' . self::esc( $name ) . '</li>';
				}
			}
			$html .= '</ul>';
		}
		$funders = isset( $relationships['funders'] ) && is_array( $relationships['funders'] ) ? $relationships['funders'] : array();
		if ( array() !== $funders ) {
			$html .= '<h2>' . self::esc( $english ? 'Funders' : 'Financiadores' ) . '</h2>';
			$html .= '<ul class="lps-funders">';
			foreach ( $funders as $funder ) {
				if ( ! is_array( $funder ) ) {
					continue;
				}
				$name = self::text( $funder['name'] ?? '' );
				$url  = self::safe_url( self::text( $funder['url'] ?? '' ) );
				if ( '' !== $url ) {
					$html .= '<li><a href="' . self::esc( $url ) . '">' . self::esc( $name ) . '</a></li>';
				} elseif ( '' !== $name ) {
					$html .= '<li>' . self::esc( $name ) . '</li>';
				}
			}
			$html .= '</ul>';
		}
		$publications = isset( $relationships['publications'] ) && is_array( $relationships['publications'] ) ? $relationships['publications'] : array();
		if ( array() !== $publications ) {
			$html .= '<h2>' . self::esc( $english ? 'Publications' : 'Publicações' ) . '</h2>';
			$html .= '<ul class="lps-publications">';
			foreach ( $publications as $publication ) {
				if ( ! is_array( $publication ) ) {
					continue;
				}
				$pub_title = self::text( $publication['title'] ?? '' );
				$pub_url   = self::safe_url( self::text( $publication['url'] ?? '' ) );
				if ( '' !== $pub_url ) {
					$html .= '<li><a href="' . self::esc( $pub_url ) . '">' . self::esc( $pub_title ) . '</a></li>';
				} elseif ( '' !== $pub_title ) {
					$html .= '<li>' . self::esc( $pub_title ) . '</li>';
				}
			}
			$html .= '</ul>';
		}
		$html .= '</article>';
		return $html;
	}

	/**
	 * Renders a publication preserving authoritative order.
	 *
	 * @param array<string, mixed> $record    Publication record.
	 * @param array<int, mixed>    $authors   Ordered authors.
	 * @param array<int, mixed>    $relations Reverse relationships.
	 * @param string               $locale    Supported locale slug.
	 */
	public static function render_publication( array $record, array $authors, array $relations, string $locale ): string {
		$english  = 'en' === $locale;
		$id       = self::num( $record['id'] ?? 0 );
		$title    = self::text( $record['title'] ?? '' );
		$summary  = self::text( $record['summary'] ?? '' );
		$abstract = self::text( $record['abstract'] ?? '' );
		$doi      = trim( self::text( $record['doi'] ?? '' ) );
		$venue    = trim( self::text( $record['venue'] ?? '' ) );
		$type     = trim( self::text( $record['type'] ?? '' ) );
		$html     = '<article class="lps-publication">';
		$html    .= '<h1>' . self::esc( $title ) . '</h1>';
		// Metadata leads the record: precision-aware date, venue, and the
		// controlled publication-type key.
		$date      = self::text( $record['date'] ?? '' );
		$precision = self::text( $record['date_precision'] ?? 'unknown' );
		$meta      = array();
		if ( 'unknown' !== $precision && 1 === preg_match( '/^\d{4}(-\d{2})?(-\d{2})?$/', $date ) ) {
			$meta[] = '<time datetime="' . self::esc( $date ) . '">' . self::esc( self::format_date( $date, $precision, $locale ) ) . '</time>';
		} else {
			$meta[] = self::esc( self::format_date( $date, $precision, $locale ) );
		}
		if ( '' !== $venue ) {
			$meta[] = self::esc( $venue );
		}
		if ( '' !== $type ) {
			$meta[] = self::esc( $type );
		}
		$html .= '<p class="lps-meta">' . implode( ' · ', $meta ) . '</p>';
		if ( '' !== $summary ) {
			$html .= '<p class="lps-summary">' . self::esc( $summary ) . '</p>';
		}
		if ( '' !== $abstract ) {
			$html .= '<div class="lps-abstract lps-reading">' . self::esc( $abstract ) . '</div>';
		}
		if ( array() !== $authors ) {
			$html .= '<h2>' . self::esc( $english ? 'Authors' : 'Autores' ) . '</h2>';
			$html .= '<ul class="lps-authors">';
			foreach ( $authors as $author ) {
				if ( ! is_array( $author ) ) {
					continue;
				}
				$name  = self::text( $author['name'] ?? '' );
				$url   = self::safe_url( self::text( $author['url'] ?? '' ) );
				$orcid = trim( self::text( $author['orcid'] ?? '' ) );
				$item  = '';
				if ( '' !== $url ) {
					$item .= '<a href="' . self::esc( $url ) . '">' . self::esc( $name ) . '</a>';
				} else {
					$item .= self::esc( $name );
				}
				if ( '' !== $orcid ) {
					$item .= ' <a href="https://orcid.org/' . self::esc( $orcid ) . '">ORCID</a>';
				}
				$html .= '<li>' . $item . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '<section class="lps-access">';
		$html .= '<h2>' . self::esc( $english ? 'Access and downloads' : 'Acesso e downloads' ) . '</h2>';
		if ( '' !== $doi ) {
			$html .= '<p class="lps-doi">DOI <a class="lps-breakable" href="https://doi.org/' . self::esc( $doi ) . '">' . self::esc( $doi ) . '</a></p>';
		} else {
			$html .= '<p class="lps-doi">' . self::esc( $english ? 'DOI not assigned' : 'DOI não atribuído' ) . '</p>';
		}
		$links            = array(
			'canonical_url'   => $english ? 'Canonical record' : 'Registro canônico',
			'open_access_url' => $english ? 'Open-access copy' : 'Cópia em acesso aberto',
			'pdf_url'         => 'PDF',
			'code_url'        => $english ? 'Code' : 'Código',
			'data_url'        => $english ? 'Data' : 'Dados',
		);
		$html_alternative = self::safe_url( self::text( $record['record_url'] ?? '' ) );
		$has_html_text    = '' !== $summary || '' !== $abstract;

		$html .= '<ul class="lps-access-links">';
		foreach ( $links as $key => $label ) {
			$url = self::safe_url( self::text( $record[ $key ] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}
			if ( 'pdf_url' === $key ) {
				// A PDF may never be the only route to the record: the published
				// abstract or editorial summary on this page is the accessible
				// alternative, and it is declared explicitly rather than assumed.
				if ( $has_html_text && '' !== $html_alternative ) {
					$html .= '<li><a href="' . self::esc( $url ) . '" data-html-alternative="' . self::esc( $html_alternative ) . '">' . self::esc( $label ) . '</a> ';
					$html .= '<span class="lps-meta">' . self::esc( $english ? 'The abstract on this page is the accessible HTML alternative.' : 'O resumo desta página é a alternativa acessível em HTML.' ) . '</span></li>';
					continue;
				}
				$html .= '<li><a href="' . self::esc( $url ) . '">' . self::esc( $label ) . '</a> ';
				$html .= '<span class="lps-meta">' . self::esc( $english ? 'No accessible HTML alternative is published for this document yet.' : 'Ainda não há alternativa acessível em HTML para este documento.' ) . '</span></li>';
				continue;
			}
			$html .= '<li><a href="' . self::esc( $url ) . '">' . self::esc( $label ) . '</a></li>';
		}
		$pdf = self::safe_url( self::text( $record['pdf_url'] ?? '' ) );
		if ( '' === $pdf ) {
			$html .= '<li class="lps-empty">' . self::esc( $english ? 'PDF unavailable' : 'PDF indisponível' ) . '</li>';
		}
		$html .= '</ul>';
		// A download control is not inline in a sentence, so the pair is marked as a
		// control cluster and carries the minimum target size of its own.
		if ( $id > 0 ) {
			$html .= '<p class="lps-citation-downloads"><a href="?lps_citation=bibtex&amp;id=' . $id . '">BibTeX</a> <a href="?lps_citation=csl-json&amp;id=' . $id . '">CSL-JSON</a></p>';
		} else {
			$html .= '<p class="lps-citation-downloads"><a href="?lps_citation=bibtex">BibTeX</a> <a href="?lps_citation=csl-json">CSL-JSON</a></p>';
		}
		$html .= '</section>';
		if ( array() !== $relations ) {
			$role_labels = array(
				'preprint-of' => $english ? 'Preprint of' : 'Preprint de',
				'output-of'   => $english ? 'Output of' : 'Produto de',
			);
			$html       .= '<h2>' . self::esc( $english ? 'Related records' : 'Registros relacionados' ) . '</h2>';
			$html       .= '<ul class="lps-relations">';
			foreach ( $relations as $relation ) {
				if ( ! is_array( $relation ) ) {
					continue;
				}
				$rel_title = self::text( $relation['title'] ?? '' );
				$rel_url   = self::safe_url( self::text( $relation['url'] ?? '' ) );
				$rel_role  = self::text( $relation['role'] ?? '' );
				$rel_label = $role_labels[ $rel_role ] ?? $rel_role;
				$html     .= '<li>';
				if ( '' !== $rel_label ) {
					$html .= '<span>' . self::esc( $rel_label ) . '</span> ';
				}
				if ( '' !== $rel_url ) {
					$html .= '<a href="' . self::esc( $rel_url ) . '">' . self::esc( $rel_title ) . '</a>';
				} else {
					$html .= self::esc( $rel_title );
				}
				$html .= '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '</article>';
		return $html;
	}

	/**
	 * Builds a BibTeX record preserving author order.
	 *
	 * @param array<string, mixed> $record  Publication record.
	 * @param array<int, mixed>    $authors Ordered authors.
	 */
	public static function bibtex( array $record, array $authors ): string {
		$names = array();
		foreach ( $authors as $author ) {
			if ( is_array( $author ) && '' !== trim( self::text( $author['name'] ?? '' ) ) ) {
				$names[] = trim( self::text( $author['name'] ?? '' ) );
			}
		}
		$key   = 'lps' . self::num( $record['id'] ?? 0 );
		$title = self::text( $record['title'] ?? '' );
		$year  = substr( self::text( $record['date'] ?? '' ), 0, 4 );
		$bib   = '@article{' . $key . ',' . "\n";
		$bib  .= '  title = {' . $title . '},' . "\n";
		$bib  .= '  author = {' . implode( ' and ', $names ) . '},' . "\n";
		if ( '' !== $year ) {
			$bib .= '  year = {' . $year . '},' . "\n";
		}
		$doi = trim( self::text( $record['doi'] ?? '' ) );
		if ( '' !== $doi ) {
			$bib .= '  doi = {' . $doi . '},' . "\n";
		}
		$venue = trim( self::text( $record['venue'] ?? '' ) );
		if ( '' !== $venue ) {
			$bib .= '  journal = {' . $venue . '},' . "\n";
		}
		$bib .= '}' . "\n";
		return $bib;
	}

	/**
	 * Builds CSL-JSON preserving author order.
	 *
	 * @param array<string, mixed> $record  Publication record.
	 * @param array<int, mixed>    $authors Ordered authors.
	 */
	public static function csl_json( array $record, array $authors ): string {
		$list = array();
		foreach ( $authors as $author ) {
			if ( is_array( $author ) && '' !== trim( self::text( $author['name'] ?? '' ) ) ) {
				$list[] = array( 'literal' => trim( self::text( $author['name'] ?? '' ) ) );
			}
		}
		$data = array(
			'id'     => 'lps' . self::num( $record['id'] ?? 0 ),
			'type'   => 'article-journal',
			'title'  => self::text( $record['title'] ?? '' ),
			'author' => $list,
		);
		$doi  = trim( self::text( $record['doi'] ?? '' ) );
		if ( '' !== $doi ) {
			$data['DOI'] = $doi;
		}
		$venue = trim( self::text( $record['venue'] ?? '' ) );
		if ( '' !== $venue ) {
			$data['container-title'] = $venue;
		}
		return (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fallback when WP not loaded.
	}

	/**
	 * Renders a server-rendered listing with GET filters and pagination.
	 *
	 * @param string               $kind       Listing kind.
	 * @param array<int, mixed>    $items      Listing rows.
	 * @param array<string, mixed> $filters    Active filters.
	 * @param array<string, mixed> $pagination Pagination state.
	 * @param string               $locale     Supported locale slug.
	 */
	public static function render_listing( string $kind, array $items, array $filters, array $pagination, string $locale ): string {
		$english   = 'en' === $locale;
		$labels    = array(
			'research'     => $english ? 'Research areas pagination' : 'Paginação de áreas de pesquisa',
			'projects'     => $english ? 'Projects pagination' : 'Paginação de projetos',
			'publications' => $english ? 'Publications pagination' : 'Paginação de publicações',
			'people'       => $english ? 'People pagination' : 'Paginação de pessoas',
			'news'         => $english ? 'News pagination' : 'Paginação de notícias',
		);
		$nav_label = $labels[ $kind ] ?? ( $english ? 'Pagination' : 'Paginação' );
		$html      = '<section class="lps-listing" data-listing="' . self::esc( $kind ) . '">';
		$html     .= '<form class="lps-listing-filters" method="get" action="" role="search" aria-label="' . self::esc( $english ? 'Filter this listing' : 'Filtrar esta listagem' ) . '">';
		$html     .= '<fieldset><legend>' . self::esc( $english ? 'Filters' : 'Filtros' ) . '</legend>';
		$active    = array();
		$has_field = false;
		foreach ( $filters as $name => $spec ) {
			$key = (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( self::text( $name ) ) );
			if ( '' === $key ) {
				continue;
			}
			$field     = 'lps-listing-filter-' . $key;
			$options   = is_array( $spec ) && is_array( $spec['options'] ?? null ) && array() !== $spec['options'] ? $spec['options'] : null;
			$value     = is_array( $spec ) ? self::text( $spec['value'] ?? '' ) : self::text( $spec );
			$has_field = true;
			if ( '' !== $value ) {
				$active[ $key ] = $value;
			}
			$html .= '<p class="lps-listing-filter"><label for="' . self::esc( $field ) . '">' . self::esc( self::filter_label( $key, $locale ) ) . '</label>';
			if ( null !== $options ) {
				$html .= '<select id="' . self::esc( $field ) . '" name="' . self::esc( $key ) . '">';
				$html .= '<option value="">' . self::esc( $english ? 'All' : 'Todos' ) . '</option>';
				foreach ( $options as $option_value => $option_label ) {
					$selected = (string) $option_value === $value ? ' selected' : '';
					$html    .= '<option value="' . self::esc( (string) $option_value ) . '"' . $selected . '>' . self::esc( self::text( $option_label ) ) . '</option>';
				}
				$html .= '</select></p>';
				continue;
			}
			$input = 'q' === $key ? 'search' : 'text';
			$html .= '<input type="' . $input . '" id="' . self::esc( $field ) . '" name="' . self::esc( $key ) . '" value="' . self::esc( $value ) . '"></p>';
		}
		$html    .= '<p class="lps-listing-actions"><button class="lps-button lps-button-primary" type="submit">' . self::esc( $english ? 'Filter' : 'Filtrar' ) . '</button>';
		$base_url = self::safe_url( self::text( $pagination['base_url'] ?? '' ) );
		if ( $has_field && array() !== $active && '' !== $base_url ) {
			$html .= ' <a class="lps-listing-clear" href="' . self::esc( $base_url ) . '">' . self::esc( $english ? 'Clear filters' : 'Limpar filtros' ) . '</a>';
		}
		$html .= '</p></fieldset></form>';
		$html .= '<p class="lps-listing-count" role="status">' . self::esc( self::listing_count_label( count( $items ), $locale ) ) . '</p>';
		$html .= '<h2>' . self::esc( $english ? 'Results' : 'Resultados' ) . '</h2>';
		$html .= '<ul class="lps-listing-results">';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$title   = self::text( $item['title'] ?? '' );
			$url     = self::safe_url( self::text( $item['url'] ?? '' ) );
			$summary = self::text( $item['summary'] ?? '' );
			$meta    = self::text( $item['meta'] ?? '' );
			$html   .= '<li class="lps-record">';
			if ( '' !== $meta ) {
				$html .= '<p class="lps-meta">' . self::esc( $meta ) . '</p>';
			}
			$html .= '<h3><a href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a></h3>';
			if ( '' !== $summary ) {
				$html .= '<p>' . self::esc( $summary ) . '</p>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		// Pagination links carry the active filters so a filtered page stays
		// shareable; `paged` is the archive query var WordPress paginates on.
		$current = self::num( $pagination['current'] ?? 1 );
		$total   = self::num( $pagination['total'] ?? 1 );
		$query   = $active;
		$html   .= '<nav aria-label="' . self::esc( $nav_label ) . '"><ul>';
		for ( $page = 1; $page <= $total; $page++ ) {
			if ( $page === $current ) {
				$html .= '<li aria-current="page"><span>' . $page . '</span></li>';
			} else {
				$query['paged'] = $page;
				$html          .= '<li><a href="' . self::esc( $base_url . '?' . http_build_query( $query ) ) . '">' . $page . '</a></li>';
			}
		}
		$html .= '</ul></nav></section>';
		return $html;
	}

	/**
	 * Returns the localized, human-readable label of one listing filter.
	 *
	 * A filter never presents its storage key to a reader: an unmapped key is
	 * announced as a filter with its key in parentheses, so the control keeps a
	 * meaningful name instead of a bare identifier.
	 *
	 * @param string $name   Filter key.
	 * @param string $locale Supported locale slug.
	 */
	public static function filter_label( string $name, string $locale ): string {
		$english = 'en' === $locale;
		$labels  = $english
			? array(
				'q'       => 'Search term',
				'year'    => 'Year',
				'type'    => 'Type',
				'status'  => 'Project status',
				'area'    => 'Research area',
				'domain'  => 'Application domain',
				'role'    => 'Role',
				'person'  => 'Person',
				'project' => 'Project',
			)
			: array(
				'q'       => 'Termo de busca',
				'year'    => 'Ano',
				'type'    => 'Tipo',
				'status'  => 'Status do projeto',
				'area'    => 'Área de pesquisa',
				'domain'  => 'Domínio de aplicação',
				'role'    => 'Papel',
				'person'  => 'Pessoa',
				'project' => 'Projeto',
			);
		if ( isset( $labels[ $name ] ) ) {
			return $labels[ $name ];
		}
		return ( $english ? 'Filter' : 'Filtro' ) . ' (' . $name . ')';
	}

	/**
	 * Returns the localized listing result count announced to assistive technology.
	 *
	 * @param int    $total  Listed record count.
	 * @param string $locale Supported locale slug.
	 */
	public static function listing_count_label( int $total, string $locale ): string {
		$english = 'en' === $locale;
		if ( 0 === $total ) {
			return $english ? 'No record on this page' : 'Nenhum registro nesta página';
		}
		if ( 1 === $total ) {
			return $english ? '1 record on this page' : '1 registro nesta página';
		}
		return $english
			? sprintf( '%d records on this page', $total )
			: sprintf( '%d registros nesta página', $total );
	}

	/**
	 * Returns the localized label of one approved project status key.
	 *
	 * The keys are the closed `project-status` facet vocabulary; `current` is
	 * accepted as the legacy seed value and reads as ongoing. An empty or
	 * unknown key returns an empty string so callers can fall back honestly.
	 *
	 * @param string $status Stored status key.
	 * @param string $locale Supported locale slug.
	 */
	public static function project_status_label( string $status, string $locale ): string {
		$english = 'en' === $locale;
		$labels  = array(
			'planned'   => $english ? 'Planned project' : 'Projeto planejado',
			'active'    => $english ? 'Ongoing project' : 'Projeto em andamento',
			'current'   => $english ? 'Ongoing project' : 'Projeto em andamento',
			'completed' => $english ? 'Completed project' : 'Projeto concluído',
			'suspended' => $english ? 'Suspended project' : 'Projeto suspenso',
		);
		return $labels[ $status ] ?? '';
	}

	/**
	 * Returns the localized options of one closed-vocabulary listing filter.
	 *
	 * Option keys are the language-neutral controlled terms; labels mirror
	 * `content/taxonomies/controlled-vocabularies.yaml` and the approved
	 * `project-status` facet vocabulary. An open-vocabulary or unknown filter
	 * returns an empty map so the control falls back to a text input.
	 *
	 * @param string $name   Filter key.
	 * @param string $locale Supported locale slug.
	 * @return array<string, string>
	 */
	public static function filter_options( string $name, string $locale ): array {
		$english = 'en' === $locale;
		$options = array(
			'area'   => $english
				? array(
					'instrumentation'            => 'Instrumentation',
					'signal-processing'          => 'Signal processing',
					'computational-intelligence' => 'Computational intelligence',
					'software-engineering'       => 'Software engineering',
				)
				: array(
					'instrumentation'            => 'Instrumentação',
					'signal-processing'          => 'Processamento de sinais',
					'computational-intelligence' => 'Inteligência computacional',
					'software-engineering'       => 'Engenharia de software',
				),
			'domain' => $english
				? array(
					'electrical-nuclear-energy' => 'Electrical and nuclear energy',
					'oil-and-gas'               => 'Oil and gas',
					'high-energy-physics'       => 'High-energy physics',
					'defense'                   => 'Defense',
					'medicine'                  => 'Medicine',
					'veterinary-science'        => 'Veterinary science',
					'data-quality'              => 'Data quality',
				)
				: array(
					'electrical-nuclear-energy' => 'Energia elétrica e nuclear',
					'oil-and-gas'               => 'Petróleo e gás',
					'high-energy-physics'       => 'Física de altas energias',
					'defense'                   => 'Defesa',
					'medicine'                  => 'Medicina',
					'veterinary-science'        => 'Ciência veterinária',
					'data-quality'              => 'Qualidade de dados',
				),
			'status' => $english
				? array(
					'planned'   => 'Planned',
					'active'    => 'Active',
					'completed' => 'Completed',
					'suspended' => 'Suspended',
				)
				: array(
					'planned'   => 'Planejado',
					'active'    => 'Em andamento',
					'completed' => 'Concluído',
					'suspended' => 'Suspenso',
				),
		);
		return $options[ $name ] ?? array();
	}

	/**
	 * Builds the date fragment of a project meta line.
	 *
	 * Dates are rendered at their recorded precision; a missing side is named
	 * instead of fabricated.
	 *
	 * @param array<string, mixed> $record Project record.
	 * @param string               $locale Supported locale slug.
	 */
	private static function project_dates_meta( array $record, string $locale ): string {
		$english = 'en' === $locale;
		$start   = self::format_date( self::text( $record['start_date'] ?? '' ), 'day', $locale );
		$end     = self::format_date( self::text( $record['end_date'] ?? '' ), 'day', $locale );
		$unknown = $english ? 'Date not provided' : 'Data não informada';
		if ( $start !== $unknown && $end !== $unknown ) {
			return ' · ' . self::esc( $start . ' – ' . $end );
		}
		if ( $start !== $unknown ) {
			$label = $english ? 'Started ' : 'Iniciado em ';
			return ' · ' . self::esc( $label . $start );
		}
		if ( $end !== $unknown ) {
			$label = $english ? 'Ended ' : 'Concluído em ';
			return ' · ' . self::esc( $label . $end );
		}
		return '';
	}

	/**
	 * Escapes one text value for markup.
	 *
	 * @param string $value Untrusted text value.
	 */
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
	/**
	 * Renders stored editorial content as sanitised HTML.
	 *
	 * Record bodies are authored as WordPress block markup. Escaping them printed
	 * block delimiters and tags as visible page text, so the content is rendered
	 * through the block renderer and then reduced to the allowed post markup set.
	 *
	 * @param string $content Stored post content.
	 */
	private static function rich( string $content ): string {
		$rendered = function_exists( 'do_blocks' ) ? (string) do_blocks( $content ) : $content;
		if ( function_exists( 'wp_kses_post' ) ) {
			return (string) wp_kses_post( $rendered );
		}
		// Unit-test seam without WordPress: keep the allowed inline set only.
		return strip_tags( $rendered, '<p><a><em><strong><ul><ol><li><h2><h3><h4><blockquote><code><br><figure><figcaption><img><table><thead><tbody><tr><th><td><caption><time><abbr><sup><sub><span>' );
	}

	/**
	 * Escapes a value for HTML output.
	 *
	 * @param string $value Raw value.
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
			return in_array( strtolower( $scheme ), array( 'http', 'https', 'mailto' ), true ) ? $clean : '';
		}
		return 1 === preg_match( '#^/(?!/)#', $clean ) ? $clean : '';
	}

	/**
	 * Reports whether a related record is archived.
	 *
	 * @param array<mixed, mixed> $relation Related record row.
	 */
	private static function is_archived( array $relation ): bool {
		if ( isset( $relation['archived'] ) && ( true === $relation['archived'] || 'true' === $relation['archived'] || 1 === $relation['archived'] ) ) {
			return true;
		}
		return 'archived' === self::text( $relation['status'] ?? '' );
	}
}
