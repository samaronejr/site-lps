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
	 * Renders the people directory as the showcase's single faculty section.
	 *
	 * @param string             $locale Supported locale slug.
	 * @param array<mixed,mixed> $people People rows.
	 */
	public static function people_listing( string $locale, array $people ): string {
		$english = 'en' === $locale;
		$listed  = array();
		foreach ( $people as $person ) {
			if ( ! is_array( $person ) || ! self::is_published( $person ) ) {
				continue;
			}
			$listed[] = $person;
		}
		$collisions  = self::colliding_names( $listed );
		$status_opts = self::status_options( $locale );
		$role_opts   = self::role_options( $locale );

		$html  = '<div class="lps-people">';
		$html .= '<section class="lps-section lps-section--flush" aria-labelledby="people-faculty">';
		$html .= '<div class="lps-section-head"><div>'
			. '<p class="lps-kicker">' . self::esc( $english ? 'Faculty' : 'Corpo docente' ) . '</p>'
			. '<h2 id="people-faculty">' . self::esc( $english ? 'Faculty' : 'Professores' ) . '</h2>'
			. '</div></div>';
		$html .= '<div class="lps-people-grid">';
		foreach ( $listed as $person ) {
			$html .= self::person_card( $person, self::text( $person['status'] ?? '' ), $collisions, $status_opts, $role_opts, $locale );
		}
		$html .= '</div></section>';
		$html .= self::people_epilogue( $locale );
		$html .= '</div>';
		return $html;
	}

	/**
	 * Renders the band the directory closes with: the team composition cards,
	 * the review notice, the participation tracks and the hand-off band.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function people_epilogue( string $locale ): string {
		$english = 'en' === $locale;
		$cards   = TrustSurfaces::editorial_card(
			$english ? 'Post-doctoral researchers' : 'Pesquisadores de pós-doutorado',
			$english
				? 'The laboratory hosts post-doctoral researchers who take part in the ATLAS, sonar and industry projects, usually with funding from research agencies.'
				: 'O laboratório recebe pesquisadores de pós-doutorado que participam dos projetos do ATLAS, de sonar e dos projetos com a indústria, normalmente com financiamento de agências de fomento.'
		);
		$cards  .= TrustSurfaces::editorial_card(
			$english ? 'Graduate and undergraduate students' : 'Estudantes de pós-graduação e graduação',
			$english
				? 'Master and doctoral students at PEE/COPPE and undergraduate students at Poli/UFRJ develop their research inside the laboratory.'
				: 'Estudantes de mestrado e doutorado do PEE/COPPE e estudantes de graduação da Poli/UFRJ desenvolvem sua pesquisa dentro do laboratório.'
		);
		$html    = TrustSurfaces::editorial_section(
			'people-team',
			$english ? 'Team' : 'Equipe',
			$english ? 'Researchers and students' : 'Pesquisadores e estudantes',
			'<div class="lps-grid lps-grid--2">' . $cards . '</div>'
				. '<div class="lps-mt-8">' . TrustSurfaces::alert_band(
					'warning',
					$english
						? 'The public source does not publish a current list of post-doctoral researchers and students, and personal data cannot be published without a documented legal basis and each person\'s agreement.'
						: 'A fonte pública não publica uma lista atual de pesquisadores de pós-doutorado e estudantes, e dados pessoais não podem ser publicados sem base legal documentada e concordância de cada pessoa.',
					$english ? 'The full team list is pending review' : 'A lista completa da equipe aguarda revisão'
				) . '</div>'
		);
		$tracks  = array(
			array(
				$english ? 'Undergraduate research' : 'Iniciação científica',
				$english
					? 'For UFRJ undergraduate students interested in signal processing, machine learning and instrumentation.'
					: 'Para estudantes de graduação da UFRJ interessados em processamento de sinais, aprendizado de máquina e instrumentação.',
			),
			array(
				$english ? "Master's and doctoral programs" : 'Mestrado e doutorado',
				$english
					? 'Selection through the Electrical Engineering Program at COPPE, with supervision available in the Computational Intelligence area.'
					: 'Seleção pelo Programa de Engenharia Elétrica da COPPE, com possibilidade de orientação na área de Inteligência Computacional.',
			),
			array(
				$english ? 'Junior research initiation' : 'Iniciação científica júnior',
				$english
					? 'The laboratory works with secondary and technical students in training activities.'
					: 'O laboratório atua com estudantes do ensino médio e técnico em atividades de formação.',
			),
		);
		$cards   = '';
		foreach ( $tracks as $track ) {
			$cards .= TrustSurfaces::editorial_card( $track[0], $track[1] );
		}
		$html .= TrustSurfaces::editorial_section(
			'people-join',
			$english ? 'Take part' : 'Participe',
			$english ? 'Work with the laboratory' : 'Trabalhe com o laboratório',
			'<div class="lps-grid lps-grid--3">' . $cards . '</div>'
				. '<p class="lps-mt-6"><a class="lps-more" href="' . self::esc( TrustRoutes::archive_path( 'lps_opportunity', $locale ) ) . '">' . self::esc( $english ? 'Opportunities and how to apply' : 'Oportunidades e como se candidatar' ) . '</a></p>'
		);
		$html .= '<section class="lps-section">' . TrustSurfaces::cta_band(
			$english
				? 'The professors\' own pages carry their full CVs and course material'
				: 'As páginas próprias dos professores reúnem currículos e material didático',
			$english
				? 'Each professor maintains a public page with their research interests, courses and support material.'
				: 'Cada professor mantém uma página pública com seus interesses de pesquisa, disciplinas e material de apoio.',
			array(
				array(
					'href'  => 'https://github.com/lps-ufrj-br',
					'label' => 'GitHub LPS',
				),
				array(
					'href'  => 'https://lattes.cnpq.br/',
					'label' => 'Lattes CNPq',
				),
			)
		) . '</section>';
		return $html;
	}

	/**
	 * Renders one person card inside the people grid.
	 *
	 * @param array<mixed,mixed>    $person      Listed person row.
	 * @param string                $status      Cohort the card belongs to.
	 * @param array<int, string>    $collisions  Normalized names present more than once.
	 * @param array<string, string> $status_opts Localized status labels.
	 * @param array<string, string> $role_opts   Localized role labels.
	 * @param string                $locale      Supported locale slug.
	 */
	private static function person_card( array $person, string $status, array $collisions, array $status_opts, array $role_opts, string $locale ): string {
		$english = 'en' === $locale;
		$slug    = self::text( $person['slug'] ?? '' );
		$name    = self::text( $person['name'] ?? '' );
		$base    = $english ? '/en/people/' : '/pt-br/pessoas/';
		$url     = $base . $slug . '/';

		$labels = array();
		foreach ( self::string_list( $person['roles'] ?? array() ) as $role ) {
			if ( isset( $role_opts[ $role ] ) ) {
				$labels[] = $role_opts[ $role ];
			}
		}
		$role_line = $labels;
		if ( 'active' !== $status && isset( $status_opts[ $status ] ) ) {
			array_unshift( $role_line, $status_opts[ $status ] );
		}

		$html  = '<article class="lps-person-card" id="' . self::esc( $slug ) . '">';
		$html .= '<div class="lps-person-header">';
		$html .= '<span class="lps-monogram' . ( 'in-memoriam' === $status ? ' lps-monogram--memoriam' : '' ) . '" aria-hidden="true">' . self::esc( self::monogram( $name ) ) . '</span>';
		$html .= '<div><h3>' . self::esc( $name ) . '</h3>';
		if ( array() !== $role_line ) {
			$html .= '<p class="lps-role">' . self::esc( implode( ' · ', $role_line ) ) . '</p>';
		}
		$html .= '</div></div>';

		$summary = self::text( $person['summary'] ?? '' );
		if ( '' !== $summary ) {
			$html .= '<p class="lps-summary">' . self::esc( $summary ) . '</p>';
		}

		$topics = self::string_list( $person['research_topics'] ?? array() );
		if ( array() !== $topics ) {
			$html .= '<ul class="lps-term-token">';
			foreach ( $topics as $topic ) {
				$html .= '<li>' . self::esc( $topic ) . '</li>';
			}
			$html .= '</ul>';
		}

		$html .= '<div class="lps-person-contact">';
		$html .= '<a class="lps-more" href="' . self::esc( $url ) . '">' . self::esc( $english ? 'Know more' : 'Saiba mais' ) . '</a>';
		$email = trim( self::text( $person['public_email'] ?? '' ) );
		if ( '' !== $email && ! empty( $person['privacy_reviewed'] ) ) {
			$html .= '<a class="lps-meta" href="mailto:' . self::esc( $email ) . '">' . self::esc( $email ) . '</a>';
		}
		if ( 'in-memoriam' === $status && isset( $status_opts[ $status ] ) ) {
			$html .= '<span class="lps-meta">' . self::esc( mb_strtolower( $status_opts[ $status ], 'UTF-8' ) ) . '</span>';
		}
		if ( in_array( self::normalized_name( $name ), $collisions, true ) ) {
			$qualifier = isset( $labels[0] ) ? $labels[0] : $slug;
			$html     .= '<span class="lps-meta lps-disambiguation">' . self::esc( $qualifier ) . '</span>';
		}
		$html .= '</div></article>';
		return $html;
	}

	/**
	 * Returns the two-letter monogram of a displayed name.
	 *
	 * Connectors and generational suffixes do not count toward the monogram, so
	 * "Natanael Nunes de Moura Junior" reads as NM like the showcase cards do.
	 *
	 * @param string $name Displayed name.
	 */
	private static function monogram( string $name ): string {
		$skippable = array( 'de', 'da', 'do', 'dos', 'das', 'e', 'junior', 'filho', 'neto', 'sobrinho' );
		$parts     = preg_split( '/\s+/u', trim( $name ) );
		$tokens    = array();
		foreach ( is_array( $parts ) ? $parts : array() as $token ) {
			$key = self::normalized_name( $token );
			if ( '' === $key || in_array( $key, $skippable, true ) ) {
				continue;
			}
			$tokens[] = $key;
		}
		if ( array() === $tokens ) {
			return '';
		}
		$first = mb_substr( $tokens[0], 0, 1, 'UTF-8' );
		$last  = mb_substr( $tokens[ count( $tokens ) - 1 ], 0, 1, 'UTF-8' );
		return mb_strtoupper( $first . $last, 'UTF-8' );
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
		$roles     = self::string_list( $person['roles'] ?? array() );
		$role_opts = self::role_options( $locale );
		$labels    = array();
		foreach ( $roles as $role ) {
			if ( isset( $role_opts[ $role ] ) ) {
				$labels[] = $role_opts[ $role ];
			}
		}
		$status      = self::text( $person['status'] ?? '' );
		$status_opts = self::status_options( $locale );
		if ( 'active' !== $status && isset( $status_opts[ $status ] ) ) {
			array_unshift( $labels, $status_opts[ $status ] );
		}

		$html  = '<article class="lps-person">';
		$html .= '<div class="lps-page-header"><div class="lps-page-header-inner lps-page-grid">';
		if ( array() !== $labels ) {
			$html .= '<p class="lps-kicker">' . self::esc( implode( ' · ', $labels ) ) . '</p>';
		}
		$html   .= '<h1 class="lps-page-title">' . self::esc( $name ) . '</h1>';
		$summary = self::text( $person['summary'] ?? '' );
		if ( '' !== $summary ) {
			$html .= '<p class="lps-lead">' . self::esc( $summary ) . '</p>';
		}
		$html .= self::translation_notice( $person, $locale );
		if ( in_array( 'external-collaborator', $roles, true ) ) {
			$external = $english ? 'External collaborator - not LPS staff' : 'Colaboração externa - não integra a equipe do LPS';
			$html    .= '<p class="lps-external">' . self::esc( $external ) . '</p>';
		}
		$html .= '</div></div>';

		$html .= '<div class="lps-page-grid"><div class="lps-with-aside lps-with-aside--single"><div class="lps-stack">';

		$reviewed  = (bool) ( $person['privacy_reviewed'] ?? false );
		$photo_url = self::text( $person['photo_url'] ?? '' );
		$rights    = self::text( $person['photo_rights'] ?? '' );
		// A cleared portrait whose file is absent would render as a broken image, which
		// the media contract forbids: the published no-photo state is used instead.
		$photo_present = array_key_exists( 'photo_present', $person )
			? (bool) $person['photo_present']
			: self::local_file_exists( $photo_url );
		$photo         = '';
		if ( $reviewed && $photo_present && 'cleared' === $rights && 0 === strpos( $photo_url, '/' ) && false === strpos( $photo_url, '://' ) ) {
			$alt   = self::text( $person['photo_alt'] ?? $name );
			$photo = '<img class="lps-person-photo" src="' . self::esc( $photo_url ) . '" alt="' . self::esc( $alt ) . '">';
		}

		$start  = trim( self::text( $person['start_date'] ?? '' ) );
		$end    = trim( self::text( $person['end_date'] ?? '' ) );
		$tenure = '';
		if ( '' !== $end ) {
			$tenure = '<p class="lps-tenure">' . self::esc( ( $english ? 'Left the laboratory on ' : 'Deixou o laboratório em ' ) . $end ) . '</p>';
		} elseif ( '' !== $start ) {
			$tenure = '<p class="lps-tenure">' . self::esc( ( $english ? 'Joined on ' : 'Ingressou em ' ) . $start ) . '</p>';
		}

		$bio = trim( self::text( $person['bio'] ?? '' ) );
		if ( '' !== $bio && function_exists( 'wp_kses_post' ) && function_exists( 'wpautop' ) ) {
			$bio = function_exists( 'do_blocks' ) ? (string) do_blocks( $bio ) : $bio;
			$bio = '<div class="lps-reading">' . wpautop( wp_kses_post( $bio ) ) . '</div>';
		} else {
			$bio = '';
		}
		$reading = $bio . $tenure;

		$html .= '<section class="lps-section lps-section--flush" aria-labelledby="lps-person-about">';
		$html .= '<div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( $english ? 'Background' : 'Trajetória' ) . '</p><h2 id="lps-person-about">' . self::esc( $english ? 'About' : 'Quem é' ) . '</h2></div></div>';
		if ( '' !== $photo ) {
			$html .= '<div class="lps-split"><div>' . $photo . '</div><div>' . $reading . '</div></div></section>';
		} else {
			$html .= $reading . '</section>';
		}

		$topics = self::string_list( $person['research_topics'] ?? array() );
		if ( array() !== $topics ) {
			$html .= '<section class="lps-section" aria-labelledby="lps-person-areas">';
			$html .= '<div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( $english ? 'Research' : 'Pesquisa' ) . '</p><h2 id="lps-person-areas">' . self::esc( $english ? 'Research areas' : 'Linhas de atuação' ) . '</h2></div></div>';
			$html .= '<ul class="lps-term-token">';
			foreach ( $topics as $topic ) {
				$html .= '<li>' . self::esc( $topic ) . '</li>';
			}
			$html .= '</ul></section>';
		}

		$html .= self::teaching_section( $person, $locale );

		$history = isset( $person['history'] ) && is_array( $person['history'] ) ? $person['history'] : array();
		if ( array() !== $history ) {
			$items = '';
			foreach ( $history as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$title = trim( self::text( $entry['title'] ?? '' ) );
				$url   = trim( self::text( $entry['url'] ?? '' ) );
				if ( '' === $title || '' === $url ) {
					continue;
				}
				$items .= '<li><h3><a href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a></h3></li>';
			}
			if ( '' !== $items ) {
				$html .= '<section class="lps-section lps-person-record" aria-labelledby="lps-person-record">';
				$html .= '<div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( $english ? 'Record' : 'Acervo' ) . '</p><h2 id="lps-person-record">' . self::esc( $english ? 'Historical record' : 'Registro histórico' ) . '</h2></div></div>';
				$html .= '<ul class="lps-record-list">' . $items . '</ul></section>';
			}
		}

		$html .= '<section class="lps-section" aria-labelledby="lps-person-notes">';
		$html .= '<div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( $english ? 'Classes' : 'Aulas' ) . '</p><h2 id="lps-person-notes">' . self::esc( $english ? 'Classes and notes' : 'Aulas e notas' ) . '</h2></div></div>';
		$html .= '<div class="lps-alert lps-alert-info"><p>' . self::esc( $english ? 'Class material is published by the professor in the restricted area. When this professor publishes material, it appears here.' : 'O material de aula é publicado pelo professor na área restrita. Quando houver material deste professor, ele aparece aqui.' ) . '</p></div></section>';

		$email = $reviewed ? trim( self::text( $person['public_email'] ?? '' ) ) : '';
		$orcid = trim( self::text( $person['orcid'] ?? '' ) );
		if ( '' === $orcid || ! self::valid_orcid( $orcid ) ) {
			$orcid = '';
		}
		$lattes = trim( self::text( $person['lattes_url'] ?? '' ) );
		if ( 1 !== preg_match( '#^https?://lattes\.cnpq\.br/\d{16}$#', $lattes ) ) {
			$lattes = '';
		}
		$extra_profiles = array(
			'scholar_url'  => 'Scholar',
			'github_url'   => 'GitHub',
			'linkedin_url' => 'LinkedIn',
			'website_url'  => $english ? 'Website' : 'Site pessoal',
		);
		$profiles       = '';
		if ( '' !== $orcid ) {
			$profiles .= '<li><a class="lps-meta" href="https://orcid.org/' . self::esc( $orcid ) . '">ORCID</a></li>';
		}
		if ( '' !== $lattes ) {
			$profiles .= '<li><a class="lps-meta" href="' . self::esc( $lattes ) . '">Lattes</a></li>';
		}
		foreach ( $extra_profiles as $key => $label ) {
			$url = trim( self::text( $person[ $key ] ?? '' ) );
			if ( '' !== $url && 0 === strpos( $url, 'http' ) ) {
				$profiles .= '<li><a class="lps-meta" href="' . self::esc( $url ) . '">' . self::esc( $label ) . '</a></li>';
			}
		}
		if ( '' !== $email || '' !== $profiles ) {
			$cards = '';
			if ( '' !== $email ) {
				$cards .= '<article class="lps-card"><div class="lps-card-body"><h3 class="lps-card-title">' . self::esc( $english ? 'Contact' : 'Contato' ) . '</h3><p><a class="lps-breakable" href="mailto:' . self::esc( $email ) . '">' . self::esc( $email ) . '</a></p></div><div class="lps-card-foot"><span></span><a class="lps-more" href="mailto:' . self::esc( $email ) . '">' . self::esc( $english ? 'Write an email' : 'Escrever e-mail' ) . '</a></div></article>';
			}
			if ( '' !== $profiles ) {
				$cards .= '<article class="lps-card"><div class="lps-card-body"><h3 class="lps-card-title">' . self::esc( $english ? 'External profiles' : 'Perfis externos' ) . '</h3><p>' . self::esc( $english ? 'Résumé, code repositories and professional profiles maintained by the professor.' : 'Currículo, repositórios de código e perfis profissionais mantidos pelo professor.' ) . '</p></div><div class="lps-card-foot"><span></span><ul class="lps-source-list">' . $profiles . '</ul></div></article>';
			}
			$html .= '<section class="lps-section" aria-labelledby="lps-person-where">';
			$html .= '<div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( 'Links' ) . '</p><h2 id="lps-person-where">' . self::esc( $english ? 'Where to find' : 'Onde encontrar' ) . '</h2></div></div>';
			$html .= '<div class="lps-grid lps-grid--2">' . $cards . '</div></section>';
		}

		$sign_in = $english ? '/en/sign-in/' : '/pt-br/entrar/';
		$html   .= '<section class="lps-section"><div class="lps-cta-band lps-cta-band--split"><div><h2>' . self::esc( $english ? 'Are you this professor? Sign in to edit this page.' : 'É este professor? Entre para editar esta página.' ) . '</h2><p>' . self::esc( $english ? 'Editing this page uses the same account as the laboratory editorial system; nothing here is editable without authentication.' : 'A edição desta página usa a mesma conta do sistema editorial do laboratório; nada aqui é editável sem autenticação.' ) . '</p></div><div class="lps-button-row"><a class="lps-button lps-button-primary" href="' . self::esc( $sign_in ) . '">' . self::esc( $english ? 'Sign in' : 'Entrar no site' ) . '</a></div></div></section>';

		$html .= '</div></div></div></article>';
		return $html;
	}

	/**
	 * Renders the person's taught courses as a code/course/level table.
	 *
	 * Entries arrive pre-sorted (newest term first) from the canonical
	 * `teaching_team` rows via `TeachingRecords::person_history`; the surface
	 * deduplicates them by course so each discipline appears once.
	 *
	 * @param array<mixed,mixed> $person Person row.
	 * @param string             $locale Supported locale slug.
	 */
	private static function teaching_section( array $person, string $locale ): string {
		$english = 'en' === $locale;
		$entries = isset( $person['teaching'] ) && is_array( $person['teaching'] ) ? $person['teaching'] : array();
		$courses = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$course = isset( $entry['course'] ) && is_array( $entry['course'] ) ? $entry['course'] : array();
			$slug   = self::text( $course['slug'] ?? '' );
			$title  = self::text( $course['title'] ?? '' );
			if ( '' === $slug || '' === $title || isset( $courses[ $slug ] ) ) {
				continue;
			}
			$courses[ $slug ] = $course;
		}
		$html  = '<section class="lps-section lps-person-teaching" aria-labelledby="lps-person-subjects">';
		$html .= '<div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( $english ? 'Teaching' : 'Docência' ) . '</p><h2 id="lps-person-subjects">' . self::esc( $english ? 'Courses' : 'Disciplinas' ) . '</h2></div></div>';
		if ( array() === $courses ) {
			return $html . '<div class="lps-alert lps-alert-info"><p>' . self::esc( $english ? 'No published offerings for this professor.' : 'Nenhuma oferta publicada para este professor.' ) . '</p></div></section>';
		}
		$name    = self::text( $person['name'] ?? '' );
		$caption = $english ? 'Courses — ' . $name : 'Disciplinas — ' . $name;
		$head    = $english ? array( 'Code', 'Course', 'Level' ) : array( 'Código', 'Disciplina', 'Nível' );
		$levels  = array(
			'undergraduate' => $english ? 'Undergraduate' : 'Graduação',
			'graduate'      => $english ? 'Graduate' : 'Pós-graduação',
			'extension'     => $english ? 'Extension' : 'Extensão',
		);
		$html   .= '<div class="lps-table-scroll" tabindex="0" role="region" aria-label="' . self::esc( $caption ) . '"><table><caption>' . self::esc( $caption ) . '</caption><thead><tr>';
		foreach ( $head as $cell ) {
			$html .= '<th scope="col">' . self::esc( $cell ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $courses as $course ) {
			$code  = self::text( $course['code'] ?? '' );
			$level = self::text( $course['level'] ?? '' );
			$url   = self::text( $course['url'] ?? '' );
			$html .= '<tr><td><span class="lps-course-code">' . self::esc( '' !== $code ? $code : '—' ) . '</span></td><th scope="row">';
			$html .= '' !== $url ? '<a href="' . self::esc( $url ) . '">' . self::esc( self::text( $course['title'] ?? '' ) ) . '</a>' : self::esc( self::text( $course['title'] ?? '' ) );
			$html .= '</th><td>' . self::esc( $levels[ $level ] ?? ( '' !== $level ? $level : '—' ) ) . '</td></tr>';
		}
		return $html . '</tbody></table></div></section>';
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
		$english  = 'en' === $locale;
		$name     = self::text( $org['name'] ?? '' );
		$tag      = 2 === $heading_level ? 'h2' : 'h1';
		$logo     = self::text( $org['logo_url'] ?? '' );
		$rights   = self::text( $org['logo_rights'] ?? '' );
		$has_logo = 'cleared' === $rights && '' !== $logo && 0 === strpos( $logo, '/' ) && false === strpos( $logo, '://' );
		$acronym  = self::text( $org['acronym'] ?? '' );

		$html  = '<article class="lps-org lps-card">';
		$html .= '<div class="lps-card-body">';
		if ( $has_logo ) {
			$html .= '<img class="lps-org-logo" src="' . self::esc( $logo ) . '" alt="' . self::esc( $name ) . '">';
		} else {
			$monogram = '' !== $acronym && mb_strlen( $acronym ) <= 6 ? mb_strtoupper( $acronym, 'UTF-8' ) : self::monogram( $name );
			if ( '' !== $monogram ) {
				$html .= '<span class="lps-monogram" aria-hidden="true">' . self::esc( $monogram ) . '</span>';
			}
		}
		$html .= '<' . $tag . '>' . self::esc( $name ) . '</' . $tag . '>';
		$html .= self::translation_notice( $org, $locale );

		$meta    = array();
		$kind    = self::text( $org['kind'] ?? '' );
		$country = self::text( $org['country_code'] ?? '' );
		foreach ( array( $kind, $acronym, $country ) as $part ) {
			if ( '' !== $part ) {
				$meta[] = $part;
			}
		}
		$ror = self::text( $org['ror_id'] ?? '' );
		if ( array() !== $meta || '' !== $ror ) {
			$html .= '<p class="lps-meta">' . self::esc( implode( ' · ', $meta ) );
			if ( '' !== $ror ) {
				$ror_url = 0 === strpos( $ror, 'http' ) ? $ror : 'https://ror.org/' . $ror;
				$html   .= ( array() !== $meta ? ' · ' : '' ) . '<a class="lps-meta" href="' . self::esc( $ror_url ) . '" rel="noopener">' . self::esc( 'ROR ' . $ror ) . '</a>';
			}
			$html .= '</p>';
		}

		$summary = self::text( $org['summary'] ?? '' );
		if ( '' === $summary && is_string( $org['public_profile'] ) ) {
			$summary = self::text( $org['public_profile'] );
		}
		if ( '' !== $summary ) {
			$html .= '<p class="lps-summary">' . self::esc( $summary ) . '</p>';
		}
		$html .= '</div>';

		$canonical = self::text( $org['canonical_url'] ?? '' );
		if ( '' !== $canonical ) {
			$html .= '<div class="lps-card-foot"><span></span><a class="lps-more" href="' . self::esc( $canonical ) . '" rel="noopener">' . self::esc( $english ? 'Official site' : 'Site oficial' ) . '</a></div>';
		}
		$html .= '</article>';
		return $html;
	}

	/**
	 * Renders infrastructure facilities.
	 *
	 * @param string                           $locale        Supported locale slug.
	 * @param array<mixed,mixed>               $facilities    Facilities.
	 * @param array<string, array<int,string>> $organizations Organization names grouped by kind.
	 */
	public static function infrastructure_page( string $locale, array $facilities, array $organizations = array() ): string {
		$english   = 'en' === $locale;
		$html      = '<div class="lps-infra">';
		$stats     = array(
			array( 'Caloba', $english ? 'Own HPC cluster (SLURM)' : 'Cluster HPC próprio (SLURM)' ),
			array( 'CPU + GPU', $english ? 'Compute partitions' : 'Filas de processamento' ),
			array( 'Singularity', $english ? 'Workload containers' : 'Contêineres para workloads' ),
			array( $english ? 'Building H, room 220' : 'Bloco H, s. 220', $english ? 'Headquarters at CT' : 'Sede no Centro de Tecnologia' ),
			array( '310 m²', $english ? 'Room with mezzanine on Bloco H\'s 2nd floor' : 'Sala com mezanino no 2º andar do Bloco H' ),
			array( '≈40', $english ? 'Interconnected machines (access, processing and GPU)' : 'Máquinas interconectadas (acesso, processamento e GPU)' ),
		);
		$stat_band = '<div class="lps-stat-band lps-shadow-none"><ul>';
		foreach ( $stats as $stat ) {
			$stat_band .= '<li class="lps-stat"><strong>' . self::esc( $stat[0] ) . '</strong><span>' . self::esc( $stat[1] ) . '</span></li>';
		}
		$stat_band   .= '</ul></div>';
		$capabilities = $english
			? array(
				'Digital signal processing and machine learning',
				'Supervised and unsupervised data modeling',
				'Caloba multi-node cluster with CPU and GPU partitions managed by SLURM',
				'Singularity containers and virtualization (Proxmox) for reproducible workloads',
			)
			: array(
				'Processamento digital de sinais e aprendizado de máquina',
				'Modelagem de dados supervisionada e não supervisionada',
				'Cluster Caloba multi-nó com partições CPU e GPU gerenciado por SLURM',
				'Contêineres Singularity e virtualização (Proxmox) para workloads reproduzíveis',
			);
		$intro_cards  = TrustSurfaces::editorial_card(
			$english ? 'Capabilities' : 'Capacidades',
			implode( ' · ', $capabilities )
		);
		$intro_cards .= TrustSurfaces::record_card(
			array(
				'title'     => $english ? 'Computing' : 'Computação',
				'body'      => $english
					? 'The Caloba cluster — SLURM-managed multi-node compute with CPU and GPU partitions, Singularity containers and Proxmox virtualization — plus Maestro, the laboratory\'s workload-orchestration stack used for high-energy physics jobs.'
					: 'O cluster Caloba — computação multi-nó gerenciada por SLURM com partições CPU e GPU, contêineres Singularity e virtualização Proxmox — além do Maestro, a pilha de orquestração de workloads do laboratório usada em tarefas de física de altas energias.',
				'foot_html' => '<ul class="lps-source-list"><li><a class="lps-meta" href="https://lps-ufrj-br.github.io/datacenter/" rel="external">' . self::esc( $english ? 'Datacenter documentation' : 'Documentação do datacenter' ) . '</a></li><li><a class="lps-meta" href="https://lps-ufrj-br.github.io/maestro-lightning/" rel="external">Maestro</a></li></ul>',
			)
		);
		$html        .= TrustSurfaces::editorial_section(
			'lps-infra-intro',
			$english ? 'Facilities' : 'Instalações',
			$english ? 'What the laboratory has' : 'O que o laboratório tem',
			$stat_band . '<div class="lps-grid lps-grid--2 lps-mt-10">' . $intro_cards . '</div>',
			true
		);
		$articles     = '';
		$index        = 0;
		foreach ( $facilities as $facility ) {
			if ( ! is_array( $facility ) ) {
				continue;
			}
			$anchor      = 'lps-infra-facility-' . ( ++$index );
			$articles   .= '<section class="lps-section" aria-labelledby="' . self::esc( $anchor ) . '">';
			$articles   .= '<div class="lps-section-head"><div><p class="lps-kicker">' . self::esc( $english ? 'Facilities' : 'Instalações' ) . '</p><h2 id="' . self::esc( $anchor ) . '">' . self::esc( self::text( $facility['name'] ?? '' ) ) . '</h2></div></div>';
			$articles   .= self::translation_notice( $facility, $locale );
			$claims      = isset( $facility['claims'] ) && is_array( $facility['claims'] ) ? $facility['claims'] : array();
			$claims_html = '';
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
				$claims_html .= '<p>' . self::esc( $text ) . ' <a class="lps-claim-source lps-breakable" href="' . self::esc( $source ) . '">' . self::esc( $english ? 'Source' : 'Fonte' ) . '</a> ';
				$claims_html .= '<span class="lps-meta">' . self::esc( $english ? 'Source reviewed ' : 'Fonte revisada em ' ) . '<time datetime="' . self::esc( $reviewed ) . '">' . self::esc( $reviewed ) . '</time></span></p>';
			}
			if ( '' !== $claims_html ) {
				$articles .= '<div class="lps-reading">' . $claims_html . '</div>';
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
						$items .= '<li><a class="lps-meta" href="' . self::esc( $url ) . '">' . self::esc( $title ) . '</a></li>';
					}
				}
				if ( '' !== $items ) {
					$articles .= '<section class="lps-infra-group"><h3>' . self::esc( $label ) . '</h3><ul class="lps-source-list">' . $items . '</ul></section>';
				}
			}
			$articles .= '</section>';
		}
		if ( '' === $articles ) {
			$html .= '<p class="lps-empty">' . self::esc( $english ? 'No published facilities' : 'Nenhuma infraestrutura publicada' ) . '</p>';
		}
		$html .= $articles;
		$html .= self::infrastructure_partners_section( $locale, $organizations );
		$html .= self::infrastructure_collaboration_section( $locale );
		$html .= self::infrastructure_location_section( $locale );
		$html .= '</div>';
		return $html;
	}

	/**
	 * Renders the partnerships band: institutions, funders and the documented
	 * international collaborations as text-logo lists.
	 *
	 * @param string                           $locale        Supported locale slug.
	 * @param array<string, array<int,string>> $organizations Organization names grouped by kind.
	 */
	private static function infrastructure_partners_section( string $locale, array $organizations ): string {
		$english = 'en' === $locale;
		$inner   = '';
		$groups  = array(
			'partners' => $english ? 'Institutions and companies' : 'Instituições e empresas',
			'funders'  => $english ? 'Funding agencies' : 'Agências de fomento',
		);
		$first   = true;
		foreach ( $groups as $kind => $label ) {
			$names = $organizations[ $kind ] ?? array();
			if ( array() === $names ) {
				continue;
			}
			$inner .= '<h3 class="lps-kicker' . ( $first ? '' : ' lps-mt-8' ) . '">' . self::esc( $label ) . '</h3>';
			$inner .= '<ul class="lps-logo-band">';
			foreach ( $names as $name ) {
				$name = self::text( $name );
				if ( '' !== $name ) {
					$inner .= '<li>' . self::esc( $name ) . '</li>';
				}
			}
			$inner .= '</ul>';
			$first  = false;
		}
		$inner .= '<h3 class="lps-kicker' . ( $first ? '' : ' lps-mt-8' ) . '">' . self::esc( $english ? 'International collaboration' : 'Colaboração internacional' ) . '</h3>';
		$inner .= '<ul class="lps-logo-band"><li>CERN · ATLAS</li><li>' . self::esc( $english ? 'Brazilian Navy · Navy Research Institute' : 'Marinha do Brasil · Instituto de Pesquisas da Marinha' ) . '</li></ul>';
		return TrustSurfaces::editorial_section(
			'parcerias',
			$english ? 'Partnerships' : 'Parcerias',
			$english ? 'Who the laboratory works with' : 'Com quem o laboratório trabalha',
			$inner,
			false,
			null,
			$english
				? 'Companies, funding agencies and international collaborations documented on the laboratory\'s public pages.'
				: 'Empresas, agências de fomento e colaborações internacionais documentadas nas páginas públicas do laboratório.'
		);
	}

	/**
	 * Renders the three numbered collaboration cards.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function infrastructure_collaboration_section( string $locale ): string {
		$english = 'en' === $locale;
		$items   = array(
			array(
				$english ? 'Contract research and R&D' : 'Pesquisa contratada e P&D',
				$english
					? 'High-relevance projects with companies, advancing the innovation capacity of national industry.'
					: 'Projetos de alta relevância com empresas, avançando a capacidade de produção da indústria nacional com inovação.',
			),
			array(
				$english ? 'Talent development' : 'Formação de pessoal',
				$english
					? 'LPS graduates meet labour-market demands with high qualification; technology-based companies have been created within the laboratory.'
					: 'Egressos do LPS atendem às demandas do mercado de trabalho com alta qualificação; empresas de base tecnológica foram criadas no âmbito do laboratório.',
			),
			array(
				$english ? 'International cooperation' : 'Cooperação internacional',
				$english
					? 'Collaborations with researchers in energy, experimental high-energy physics, quantum computing, defence, medicine and data quality.'
					: 'Colaborações com pesquisadores de energia, física experimental de altas energias, computação quântica, defesa, medicina e qualidade de dados.',
			),
		);
		$cards   = '';
		foreach ( $items as $index => $item ) {
			$cards .= TrustSurfaces::record_card(
				array(
					'title'    => $item[0],
					'body'     => $item[1],
					'numbered' => sprintf( '%02d', $index + 1 ),
				)
			);
		}
		return TrustSurfaces::editorial_section(
			'lps-infra-collaboration',
			$english ? 'Collaborate' : 'Colabore',
			$english ? 'Three ways to work with the laboratory' : 'Três formas de trabalhar com o laboratório',
			'<div class="lps-grid lps-grid--3">' . $cards . '</div>'
		);
	}

	/**
	 * Renders the visit split: documented address facts beside the
	 * technical-visit card.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function infrastructure_location_section( string $locale ): string {
		$english = 'en' === $locale;
		$facts   = '<dl class="lps-facts">'
			. '<dt>' . self::esc( $english ? 'Address' : 'Endereço' ) . '</dt><dd>' . self::esc( 'Av. Athos da Silveira Ramos, 149' ) . '</dd>'
			. '<dt>' . self::esc( $english ? 'Building' : 'Bloco' ) . '</dt><dd>' . self::esc( $english ? 'Technology Centre, Building H — Ilha do Fundão' : 'Centro de Tecnologia, Bloco H — Ilha do Fundão' ) . '</dd>'
			. '<dt>' . self::esc( 'CEP' ) . '</dt><dd>' . self::esc( '21941-914 — Rio de Janeiro/RJ' ) . '</dd>'
			. '<dt>' . self::esc( $english ? 'Telephone' : 'Telefone' ) . '</dt><dd>' . self::esc( '(21) 3938-8205 (' . ( $english ? 'Extension 8205' : 'Ramal 8205' ) . ')' ) . '</dd>'
			. '<dt>' . self::esc( $english ? 'Office' : 'Secretaria' ) . '</dt><dd><a href="mailto:secretaria@lps.ufrj.br">' . self::esc( 'secretaria@lps.ufrj.br' ) . '</a></dd>'
			. '</dl>';
		$visit   = TrustSurfaces::record_card(
			array(
				'title'  => $english ? 'Technical visits and meetings' : 'Visitas técnicas e reuniões',
				'body'   => $english
					? 'The laboratory has a lecture and meeting room and receives technical visits by appointment. Requests go through the laboratory office.'
					: 'O laboratório dispõe de sala de palestras e reuniões e recebe visitas técnicas com agendamento. Os pedidos são feitos pela secretaria do laboratório.',
				'action' => array(
					'href'  => TrustRoutes::page_path( 'contact', $locale ),
					'label' => $english ? 'Request a visit' : 'Solicitar visita',
				),
			)
		);
		return TrustSurfaces::editorial_section(
			'lps-infra-location',
			$english ? 'Location' : 'Localização',
			$english ? 'Visit the laboratory' : 'Visite o laboratório',
			'<div class="lps-split"><div>' . $facts . '</div><div>' . $visit . '</div></div>'
		);
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
	 * Returns role options.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<string, string>
	 */
	private static function role_options( string $locale ): array {
		$english = 'en' === $locale;
		if ( $english ) {
			return array(
				'student'                    => 'Student',
				'researcher'                 => 'Researcher',
				'professor'                  => 'Professor',
				'professor-titular'          => 'Full Professor',
				'professor-titular-emerito'  => 'Full Professor (Emeritus)',
				'professor-adjunto'          => 'Adjunct Professor',
				'coordenador-lps'            => 'LPS Coordinator',
				'coordenador-embrapii'       => 'Coordinator (EMBRAPII)',
				'pesquisador-permanente-lps' => 'Permanent LPS researcher',
				'pesquisador-atlas'          => 'ATLAS researcher',
				'technical-staff'            => 'Technical staff',
				'external-collaborator'      => 'External collaborator',
				'alumni'                     => 'Alumni',
			);
		}
		return array(
			'student'                    => 'Estudante',
			'researcher'                 => 'Pesquisador',
			'professor'                  => 'Professor',
			'professor-titular'          => 'Professor Titular',
			'professor-titular-emerito'  => 'Professor Titular (Emérito)',
			'professor-adjunto'          => 'Professor Adjunto',
			'coordenador-lps'            => 'Coordenador do LPS',
			'coordenador-embrapii'       => 'Coordenador (EMBRAPII)',
			'pesquisador-permanente-lps' => 'Pesquisador permanente do LPS',
			'pesquisador-atlas'          => 'Pesquisador ATLAS',
			'technical-staff'            => 'Equipe técnica',
			'external-collaborator'      => 'Colaboração externa',
			'alumni'                     => 'Egresso',
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
