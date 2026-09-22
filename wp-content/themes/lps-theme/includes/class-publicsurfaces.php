<?php
/**
 * People, organization, and infrastructure surfaces.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

/** Owns privacy-aware public rendering. */
final class PublicSurfaces {
	/**
	 * Renders people listing with GET facets.
	 *
	 * @param string             $locale Supported locale slug.
	 * @param array<mixed,mixed> $people People rows.
	 * @param array<mixed,mixed> $filters Active filters.
	 */
	public static function people_listing( string $locale, array $people, array $filters ): string {
		$english = 'en' === $locale;
		$html    = '<section class="lps-people">';
		$html   .= '<form method="get" action="" role="search" aria-label="' . self::esc( $english ? 'Filter this listing' : 'Filtrar esta listagem' ) . '">';
		$html   .= '<label>' . self::esc( $english ? 'Role' : 'Papel' ) . '<select name="role[]">';
		foreach ( self::role_options( $locale ) as $value => $label ) {
			$selected = in_array( $value, self::string_list( $filters['role'] ?? array() ), true ) ? ' selected' : '';
			$html    .= '<option value="' . self::esc( $value ) . '"' . $selected . '>' . self::esc( $label ) . '</option>';
		}
		$html .= '</select></label>';
		$html .= '<label>' . self::esc( $english ? 'Status' : 'Vínculo' ) . '<select name="status[]">';
		foreach ( self::status_options( $locale ) as $value => $label ) {
			$selected = in_array( $value, self::string_list( $filters['status'] ?? array() ), true ) ? ' selected' : '';
			$html    .= '<option value="' . self::esc( $value ) . '"' . $selected . '>' . self::esc( $label ) . '</option>';
		}
		$html .= '</select></label>';
		$html .= '<label>' . self::esc( $english ? 'Area' : 'Área' ) . '<select name="area[]">';
		$areas = self::area_options( $locale );
		foreach ( $areas as $value => $label ) {
			$selected = in_array( $value, self::string_list( $filters['area'] ?? array() ), true ) ? ' selected' : '';
			$html    .= '<option value="' . self::esc( $value ) . '"' . $selected . '>' . self::esc( $label ) . '</option>';
		}
		$html  .= '</select></label>';
		$html  .= '<button class="lps-button lps-button-primary" type="submit">' . self::esc( $english ? 'Filter' : 'Filtrar' ) . '</button></form>';
		$listed = array();
		foreach ( $people as $person ) {
			if ( ! is_array( $person ) || ! self::is_published( $person ) ) {
				continue;
			}
			if ( ! self::matches_filters( $person, $filters ) ) {
				continue;
			}
			$listed[] = $person;
		}
		$collisions = self::colliding_names( $listed );

		$html .= '<p class="lps-people-count" id="lps-people-status" role="status">' . self::esc( self::count_label( count( $listed ), $locale ) ) . '</p>';
		// The directory is grouped by affiliation cohort, not a flat list: each
		// cohort keeps its own heading so the listing reads as an institutional
		// directory instead of one undifferentiated result set.
		$status_opts = self::status_options( $locale );
		$cohorts     = array();
		foreach ( $listed as $person ) {
			$status               = self::text( $person['status'] ?? '' );
			$cohorts[ $status ][] = $person;
		}
		$ordered_statuses = array_merge( array( 'active', 'alumni', 'in-memoriam' ), array_diff( array_keys( $cohorts ), array( 'active', 'alumni', 'in-memoriam' ) ) );
		foreach ( $ordered_statuses as $status ) {
			$members = $cohorts[ $status ] ?? array();
			if ( array() === $members ) {
				continue;
			}
			$label = $status_opts[ $status ] ?? $status;
			$html .= '<section class="lps-people-cohort" data-cohort="' . self::esc( $status ) . '">';
			$html .= '<h2>' . self::esc( $label ) . '</h2>';
			$html .= '<ul class="lps-people-list">';
			foreach ( $members as $person ) {
				$slug   = self::text( $person['slug'] ?? '' );
				$name   = self::text( $person['name'] ?? '' );
				$base   = $english ? '/en/people/' : '/pt-br/pessoas/';
				$html  .= '<li><a href="' . self::esc( $base . $slug . '/' ) . '">' . self::esc( $name ) . '</a> ';
				$opts   = self::role_options( $locale );
				$labels = array();
				foreach ( self::string_list( $person['roles'] ?? array() ) as $role ) {
					if ( isset( $opts[ $role ] ) ) {
						$labels[] = $opts[ $role ];
					}
				}
				foreach ( $labels as $role_label ) {
					$html .= '<span class="lps-role">' . self::esc( $role_label ) . '</span> ';
				}
				if ( isset( $status_opts[ $status ] ) ) {
					$html .= '<span class="lps-status">' . self::esc( $status_opts[ $status ] ) . '</span>';
				}
				if ( in_array( self::normalized_name( $name ), $collisions, true ) ) {
					$qualifier = isset( $labels[0] ) ? $labels[0] : $slug;
					$html     .= '<span class="lps-disambiguation">' . self::esc( $qualifier ) . '</span>';
				}
				$html .= '</li>';
			}
			$html .= '</ul></section>';
		}
		$html .= '</section>';
		return $html;
	}

	/**
	 * Renders one person profile.
	 *
	 * @param string             $locale Supported locale slug.
	 * @param array<mixed,mixed> $person Person row.
	 */
	public static function person_profile( string $locale, array $person ): string {
		if ( ! self::is_published( $person ) ) {
			return '';
		}
		if ( '' === trim( self::text( $person['name'] ?? '' ) ) ) {
			// An absent record renders nothing: an empty profile shell would still
			// tell a visitor that some record exists at this address.
			return '';
		}
		$english   = 'en' === $locale;
		$name      = self::text( $person['name'] ?? '' );
		$html      = '<article class="lps-person">';
		$html     .= '<header class="lps-person-header">';
		$html     .= '<h1>' . self::esc( $name ) . '</h1>';
		$html     .= self::translation_notice( $person, $locale );
		$roles     = self::string_list( $person['roles'] ?? array() );
		$role_opts = self::role_options( $locale );
		$role_list = '';
		foreach ( $roles as $role ) {
			if ( isset( $role_opts[ $role ] ) ) {
				$role_list .= '<span class="lps-role">' . self::esc( $role_opts[ $role ] ) . '</span> ';
			}
		}
		$status      = self::text( $person['status'] ?? '' );
		$status_opts = self::status_options( $locale );
		if ( isset( $status_opts[ $status ] ) ) {
			$role_list .= '<span class="lps-status">' . self::esc( $status_opts[ $status ] ) . '</span>';
		}
		if ( '' !== $role_list ) {
			$html .= '<p class="lps-person-roles">' . $role_list . '</p>';
		}
		if ( in_array( 'external-collaborator', $roles, true ) ) {
			$external = $english ? 'External collaborator - not LPS staff' : 'Colaboração externa - não integra a equipe do LPS';
			$html    .= '<p class="lps-external">' . self::esc( $external ) . '</p>';
		}
		$html     .= '</header>';
		$reviewed  = (bool) ( $person['privacy_reviewed'] ?? false );
		$photo_url = self::text( $person['photo_url'] ?? '' );
		$rights    = self::text( $person['photo_rights'] ?? '' );
		// A cleared portrait whose file is absent would render as a broken image, which
		// the media contract forbids: the published no-photo state is used instead.
		$photo_present = array_key_exists( 'photo_present', $person )
			? (bool) $person['photo_present']
			: self::local_file_exists( $photo_url );
		if ( $reviewed && $photo_present && 'cleared' === $rights && 0 === strpos( $photo_url, '/' ) && false === strpos( $photo_url, '://' ) ) {
			$alt   = self::text( $person['photo_alt'] ?? $name );
			$html .= '<img class="lps-person-photo" src="' . self::esc( $photo_url ) . '" alt="' . self::esc( $alt ) . '">';
		} else {
			$html .= '<p class="lps-person-no-photo">' . self::esc( $english ? 'Photo not published' : 'Foto não publicada' ) . '</p>';
		}
		$contact = '';
		if ( $reviewed ) {
			$public_email = trim( self::text( $person['public_email'] ?? '' ) );
			if ( '' !== $public_email ) {
				$contact .= '<li><a class="lps-breakable" href="mailto:' . self::esc( $public_email ) . '">' . self::esc( $public_email ) . '</a></li>';
			}
		}
		$orcid = trim( self::text( $person['orcid'] ?? '' ) );
		if ( '' !== $orcid && self::valid_orcid( $orcid ) ) {
			$contact .= '<li><a href="https://orcid.org/' . self::esc( $orcid ) . '">ORCID</a></li>';
		}
		$lattes = trim( self::text( $person['lattes_url'] ?? '' ) );
		if ( '' !== $lattes && 1 === preg_match( '#^https?://lattes\.cnpq\.br/\d{16}$#', $lattes ) ) {
			$contact .= '<li><a href="' . self::esc( $lattes ) . '">Lattes</a></li>';
		}
		if ( '' !== $contact ) {
			$html .= '<ul class="lps-person-contact">' . $contact . '</ul>';
		}
		$start = trim( self::text( $person['start_date'] ?? '' ) );
		$end   = trim( self::text( $person['end_date'] ?? '' ) );
		if ( '' !== $end ) {
			$since = $english ? 'Left the laboratory on ' : 'Deixou o laboratório em ';
			$html .= '<p class="lps-tenure">' . self::esc( $since . $end ) . '</p>';
		} elseif ( '' !== $start ) {
			$html .= '<p class="lps-tenure">' . self::esc( ( $english ? 'Joined on ' : 'Ingressou em ' ) . $start ) . '</p>';
		}
		$html   .= self::teaching_section( $person, $locale );
		$history = isset( $person['history'] ) && is_array( $person['history'] ) ? $person['history'] : array();
		if ( array() !== $history ) {
			$html .= '<section class="lps-person-record"><h2>' . self::esc( $english ? 'Historical record' : 'Registro histórico' ) . '</h2>';
			$html .= '<ul class="lps-history">';
			foreach ( $history as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$title = trim( self::text( $entry['title'] ?? '' ) );
				$url   = trim( self::text( $entry['url'] ?? '' ) );
				if ( '' === $title || '' === $url ) {
					continue;
				}
				$html .= '<li><a href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a></li>';
			}
			$html .= '</ul></section>';
		}
		$html .= '</article>';
		return $html;
	}

	/**
	 * Renders the person's teaching history grouped by derived temporal status.
	 *
	 * Entries arrive pre-sorted (newest term first) from the canonical
	 * `teaching_team` rows via `TeachingRecords::person_history`; the surface
	 * only groups and labels them. A cancelled offering keeps its row under the
	 * previous-offerings group with an explicit cancelled label, matching the
	 * closed/cancelled contract of the event and opportunity surfaces.
	 *
	 * @param array<mixed,mixed> $person Person row.
	 * @param string             $locale Supported locale slug.
	 */
	private static function teaching_section( array $person, string $locale ): string {
		$english = 'en' === $locale;
		$entries = isset( $person['teaching'] ) && is_array( $person['teaching'] ) ? $person['teaching'] : array();
		$groups  = array(
			'current'   => $english ? 'In progress' : 'Em andamento',
			'upcoming'  => $english ? 'Upcoming offerings' : 'Próximas ofertas',
			'completed' => $english ? 'Previous offerings' : 'Ofertas anteriores',
		);
		$grouped = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$status               = self::text( $entry['temporal_status'] ?? '' );
			$bucket               = isset( $groups[ $status ] ) ? $status : 'completed';
			$grouped[ $bucket ][] = $entry;
		}
		if ( array() === $grouped ) {
			return '<section class="lps-person-teaching"><h2>' . self::esc( $english ? 'Courses and materials' : 'Disciplinas e materiais' ) . '</h2>'
				. '<p class="lps-empty">' . self::esc( $english ? 'No published offerings' : 'Nenhuma oferta publicada' ) . '</p></section>';
		}
		$html = '<section class="lps-person-teaching"><h2>' . self::esc( $english ? 'Courses and materials' : 'Disciplinas e materiais' ) . '</h2>';
		foreach ( $groups as $status => $label ) {
			$rows = $grouped[ $status ] ?? array();
			if ( array() === $rows ) {
				continue;
			}
			$html .= '<section class="lps-teaching-group" data-temporal="' . self::esc( $status ) . '"><h3>' . self::esc( $label ) . '</h3><ul class="lps-teaching-history">';
			foreach ( $rows as $entry ) {
				$title = self::text( $entry['title'] ?? '' );
				if ( '' === $title ) {
					continue;
				}
				$url       = self::text( $entry['url'] ?? '' );
				$term      = isset( $entry['term'] ) && is_array( $entry['term'] ) ? $entry['term'] : array();
				$meta      = array();
				$period    = self::text( $term['period_label'] ?? '' );
				$section   = self::text( $entry['section_key'] ?? '' );
				$role      = self::teaching_role_label( self::text( $entry['role'] ?? '' ), $locale );
				$meta[]    = '' !== $period ? $period : self::text( $term['token'] ?? '' );
				$meta[]    = '' !== $section ? strtoupper( $section ) : '';
				$meta[]    = $role;
				$meta      = array_values( array_filter( $meta, static fn( string $part ): bool => '' !== $part ) );
				$cancelled = 'cancelled' === self::text( $entry['temporal_status'] ?? '' );
				$html     .= '<li' . ( $cancelled ? ' data-state="cancelled"' : '' ) . '>';
				$html     .= '' !== $url ? '<a href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a>' : self::esc( $title );
				if ( array() !== $meta ) {
					$html .= ' <span class="lps-meta">' . self::esc( implode( ' · ', $meta ) ) . '</span>';
				}
				if ( $cancelled ) {
					$html .= ' <span class="lps-status lps-status-warning">' . self::esc( $english ? 'Cancelled' : 'Cancelada' ) . '</span>';
				}
				$html .= '</li>';
			}
			$html .= '</ul></section>';
		}
		return $html . '</section>';
	}

	/**
	 * Returns the localized label of one canonical teaching-team role.
	 *
	 * @param string $role   Stored relationship role.
	 * @param string $locale Supported locale slug.
	 */
	private static function teaching_role_label( string $role, string $locale ): string {
		$english = 'en' === $locale;
		$labels  = array(
			'lead'       => $english ? 'Lead instructor' : 'Docente responsável',
			'co-teacher' => $english ? 'Co-teacher' : 'Codocente',
			'assistant'  => $english ? 'Teaching assistant' : 'Assistente de ensino',
		);
		return $labels[ $role ] ?? $role;
	}

	/**
	 * Reports whether a site-relative asset exists on disk.
	 *
	 * @param string $path Site-relative asset path.
	 */
	private static function local_file_exists( string $path ): bool {
		if ( '' === $path || 0 !== strpos( $path, '/' ) ) {
			return false;
		}
		if ( ! defined( 'ABSPATH' ) ) {
			// Unit-test seam without WordPress: the caller supplies `photo_present`.
			return true;
		}
		$root = ABSPATH;
		return is_string( $root ) && is_file( rtrim( $root, '/' ) . $path );
	}

	/**
	 * Renders an organization profile or empty string when hidden.
	 *
	 * @param string             $locale        Supported locale slug.
	 * @param array<mixed,mixed> $org           Organization row.
	 * @param int                $heading_level 1 when the record owns the page, 2 inside a listing.
	 */
	public static function organization_profile( string $locale, array $org, int $heading_level = 1 ): string {
		if ( empty( $org['public_profile'] ) ) {
			return '';
		}
		$name   = self::text( $org['name'] ?? '' );
		$html   = '<article class="lps-org">';
		$tag    = 2 === $heading_level ? 'h2' : 'h1';
		$html  .= '<' . $tag . '>' . self::esc( $name ) . '</' . $tag . '>';
		$html  .= self::translation_notice( $org, $locale );
		$logo   = self::text( $org['logo_url'] ?? '' );
		$rights = self::text( $org['logo_rights'] ?? '' );
		if ( 'cleared' === $rights && '' !== $logo && 0 === strpos( $logo, '/' ) && false === strpos( $logo, '://' ) ) {
			$html .= '<img class="lps-org-logo" src="' . self::esc( $logo ) . '" alt="' . self::esc( $name ) . '">';
		}
		$canonical = self::text( $org['canonical_url'] ?? '' );
		if ( '' !== $canonical ) {
			$html .= '<p><a class="lps-breakable" href="' . self::esc( $canonical ) . '">' . self::esc( $canonical ) . '</a></p>';
		}
		$html .= '</article>';
		return $html;
	}

	/**
	 * Renders infrastructure facilities.
	 *
	 * @param string             $locale Supported locale slug.
	 * @param array<mixed,mixed> $facilities Facilities.
	 */
	public static function infrastructure_page( string $locale, array $facilities ): string {
		$english  = 'en' === $locale;
		$articles = '';
		foreach ( $facilities as $facility ) {
			if ( ! is_array( $facility ) ) {
				continue;
			}
			$articles .= '<article><h2>' . self::esc( self::text( $facility['name'] ?? '' ) ) . '</h2>';
			$articles .= self::translation_notice( $facility, $locale );
			$claims    = isset( $facility['claims'] ) && is_array( $facility['claims'] ) ? $facility['claims'] : array();
			foreach ( $claims as $claim ) {
				if ( ! is_array( $claim ) ) {
					continue;
				}
				$text     = trim( self::text( $claim['text'] ?? '' ) );
				$source   = trim( self::text( $claim['source_url'] ?? '' ) );
				$reviewed = trim( self::text( $claim['reviewed_at'] ?? '' ) );
				if ( '' === $text || '' === $source || '' === $reviewed ) {
					continue;
				}
				$articles .= '<p>' . self::esc( $text ) . ' <a class="lps-claim-source lps-breakable" href="' . self::esc( $source ) . '">' . self::esc( $english ? 'Source' : 'Fonte' ) . '</a> ';
				$articles .= '<span class="lps-meta">' . self::esc( $english ? 'Source reviewed ' : 'Fonte revisada em ' ) . '<time datetime="' . self::esc( $reviewed ) . '">' . self::esc( $reviewed ) . '</time></span></p>';
			}
			foreach ( array(
				'equipment' => $english ? 'Equipment' : 'Equipamento',
				'research'  => $english ? 'Research' : 'Pesquisa',
				'projects'  => $english ? 'Projects' : 'Projetos',
				'contacts'  => $english ? 'Contacts' : 'Contatos',
			) as $key => $label ) {
				$links = isset( $facility[ $key ] ) && is_array( $facility[ $key ] ) ? $facility[ $key ] : array();
				$items = '';
				foreach ( $links as $link ) {
					if ( ! is_array( $link ) ) {
						continue;
					}
					$title = self::text( $link['title'] ?? $link['name'] ?? '' );
					$url   = self::text( $link['url'] ?? '' );
					if ( '' !== $title && '' !== $url ) {
						$items .= '<li><a href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a></li>';
					}
				}
				if ( '' !== $items ) {
					$articles .= '<section class="lps-infra-group"><h3>' . self::esc( $label ) . '</h3><ul>' . $items . '</ul></section>';
				}
			}
			$articles .= '</article>';
		}
		$html = '<section class="lps-infra">';
		if ( '' === $articles ) {
			$html .= '<p class="lps-empty">' . self::esc( $english ? 'No published facilities' : 'Nenhuma infraestrutura publicada' ) . '</p>';
		}
		$html .= $articles . '</section>';
		return $html;
	}

	/**
	 * Renders the explicit stale-translation warning of one record.
	 *
	 * A stale English variant stays at its own URL and announces that its review
	 * trails the Portuguese source; the surface never silently substitutes the
	 * source text for the requested locale.
	 *
	 * @param array<mixed,mixed> $record Public record.
	 * @param string             $locale Supported locale slug.
	 */
	private static function translation_notice( array $record, string $locale ): string {
		if ( empty( $record['stale'] ) ) {
			return '';
		}
		$message = 'en' === $locale
			? 'This English translation is under review: the Portuguese source changed since the last review.'
			: 'Esta tradução está em revisão: a fonte em português mudou desde a última revisão.';
		return '<p class="lps-translation-notice" role="status">' . self::esc( $message ) . '</p>';
	}

	/**
	 * Returns the localized research-area filter options.
	 *
	 * The labels are part of the interface, so they are never left in the
	 * authoritative Portuguese source language while the page is served in English
	 * or the other way around.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<string, string>
	 */
	private static function area_options( string $locale ): array {
		if ( 'en' === $locale ) {
			return array(
				'signal-processing'    => 'Signal processing',
				'software-engineering' => 'Software engineering',
			);
		}
		return array(
			'signal-processing'    => 'Processamento de sinais',
			'software-engineering' => 'Engenharia de software',
		);
	}

	/**
	 * Returns the localized result count announced after a filter is applied.
	 *
	 * @param int    $total  Listed record count.
	 * @param string $locale Supported locale slug.
	 */
	public static function count_label( int $total, string $locale ): string {
		$english = 'en' === $locale;
		if ( 0 === $total ) {
			return $english ? 'No person matches this filter' : 'Nenhuma pessoa corresponde a este filtro';
		}
		if ( 1 === $total ) {
			return $english ? '1 person listed' : '1 pessoa listada';
		}
		return $english
			? sprintf( '%d people listed', $total )
			: sprintf( '%d pessoas listadas', $total );
	}

	/**
	 * Returns role options.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<string, string>
	 */
	private static function role_options( string $locale ): array {
		$english = 'en' === $locale;
		if ( $english ) {
			return array(
				'student'               => 'Student',
				'researcher'            => 'Researcher',
				'professor'             => 'Professor',
				'technical-staff'       => 'Technical staff',
				'external-collaborator' => 'External collaborator',
				'alumni'                => 'Alumni',
			);
		}
		return array(
			'student'               => 'Estudante',
			'researcher'            => 'Pesquisador',
			'professor'             => 'Professor',
			'technical-staff'       => 'Equipe técnica',
			'external-collaborator' => 'Colaboração externa',
			'alumni'                => 'Egresso',
		);
	}

	/**
	 * Returns status options.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<string, string>
	 */
	private static function status_options( string $locale ): array {
		$english = 'en' === $locale;
		if ( $english ) {
			return array(
				'active'      => 'Active',
				'alumni'      => 'Alumni',
				'in-memoriam' => 'In memoriam',
			);
		}
		return array(
			'active'      => 'Ativo',
			'alumni'      => 'Egresso',
			'in-memoriam' => 'In memoriam',
		);
	}

	/**
	 * Reports whether a person record may be published at all.
	 *
	 * Records awaiting consent, such as an unapproved in-memoriam profile, stay
	 * withheld instead of rendering a partial page.
	 *
	 * @param array<mixed,mixed> $person Person row.
	 */
	private static function is_published( array $person ): bool {
		return ! isset( $person['published'] ) || false !== $person['published'];
	}

	/**
	 * Returns the normalized form of a displayed name.
	 *
	 * Diacritics and case are folded so that names colliding only by accent are
	 * still recognized as the same written name.
	 *
	 * @param string $name Displayed name.
	 */
	private static function normalized_name( string $name ): string {
		$folded = strtr(
			mb_strtolower( $name, 'UTF-8' ),
			array(
				'á' => 'a',
				'à' => 'a',
				'â' => 'a',
				'ã' => 'a',
				'ä' => 'a',
				'é' => 'e',
				'ê' => 'e',
				'ë' => 'e',
				'í' => 'i',
				'ï' => 'i',
				'ó' => 'o',
				'ô' => 'o',
				'õ' => 'o',
				'ö' => 'o',
				'ú' => 'u',
				'ü' => 'u',
				'ç' => 'c',
				'ñ' => 'n',
			)
		);
		return trim( preg_replace( '/\s+/u', ' ', $folded ) ?? '' );
	}

	/**
	 * Returns the normalized names shared by more than one listed person.
	 *
	 * @param array<int, array<mixed,mixed>> $people Listed people.
	 * @return array<int, string>
	 */
	private static function colliding_names( array $people ): array {
		$counts = array();
		foreach ( $people as $person ) {
			$key            = self::normalized_name( self::text( $person['name'] ?? '' ) );
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
		$collisions = array();
		foreach ( $counts as $key => $count ) {
			if ( 1 < $count && '' !== $key ) {
				$collisions[] = (string) $key;
			}
		}
		return $collisions;
	}

	/**
	 * Checks person against active filters.
	 *
	 * @param array<mixed,mixed> $person Person row.
	 * @param array<mixed,mixed> $filters Active filters.
	 */
	private static function matches_filters( array $person, array $filters ): bool {
		foreach ( array(
			'role'   => 'roles',
			'status' => 'status',
			'area'   => 'areas',
		) as $filter_key => $person_key ) {
			$wanted = self::string_list( $filters[ $filter_key ] ?? array() );
			if ( array() === $wanted ) {
				continue;
			}
			$have_raw = $person[ $person_key ] ?? array();
			if ( is_string( $have_raw ) ) {
				$have_raw = array( $have_raw );
			}
			$have = self::string_list( $have_raw );
			if ( array() === array_intersect( $wanted, $have ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Validates an ORCID iD checksum.
	 *
	 * @param string $orcid ORCID identifier.
	 */
	private static function valid_orcid( string $orcid ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', $orcid ) ) {
			return false;
		}
		$digits = str_replace( '-', '', $orcid );
		$total  = 0;
		for ( $i = 0; $i < 15; $i++ ) {
			$total = ( $total + (int) $digits[ $i ] ) * 2;
		}
		$remainder = $total % 11;
		$result    = ( 12 - $remainder ) % 11;
		$check     = 10 === $result ? 'X' : (string) $result;
		return $check === $digits[15];
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
	 * Returns string list from boundary input.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<int, string>
	 */
	private static function string_list( mixed $value ): array {
		if ( is_string( $value ) && '' !== $value ) {
			return array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Escapes text for markup.
	 *
	 * @param string $value Untrusted text value.
	 */
	private static function esc( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
