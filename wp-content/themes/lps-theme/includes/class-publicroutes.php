<?php
/**
 * Locale routes and privacy gates for people, organization, and infrastructure surfaces.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use LPS\ContentModel\Relationships;
use LPS\ContentModel\TeachingRecords;
use LPS\ContentModel\Translations;
use WP_Post;
use WP_Query;

require_once __DIR__ . '/class-publicsurfaces.php';

/** Binds privacy-gated public records to their frozen locale routes. */
final class PublicRoutes {
	public const LOCALE_QUERY_VAR = 'lps_public_locale';
	public const VIEW_QUERY_VAR   = 'lps_public_view';

	/**
	 * Frozen locale path segment for each public view.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const SEGMENTS = array(
		'lps_person'         => array(
			'pt-br' => 'pessoas',
			'en'    => 'people',
		),
		'lps_organization'   => array(
			'pt-br' => 'organizacoes',
			'en'    => 'organizations',
		),
		'lps_infrastructure' => array(
			'pt-br' => 'infraestrutura',
			'en'    => 'infrastructure',
		),
	);

	/**
	 * BCP47 language tag for each supported locale slug.
	 *
	 * @var array<string, string>
	 */
	private const LANGUAGE_TAGS = array(
		'pt-br' => 'pt-BR',
		'en'    => 'en',
	);

	/**
	 * Controlled person status values.
	 *
	 * @var array<int, string>
	 */
	private const STATUSES = array( 'active', 'alumni', 'in-memoriam' );

	/**
	 * Returns the localized archive path for a public view.
	 *
	 * @param string $post_type Public view key.
	 * @param string $locale    Supported locale slug.
	 */
	public static function archive_path( string $post_type, string $locale ): string {
		$segment = self::SEGMENTS[ $post_type ][ $locale ] ?? '';
		return '' === $segment ? '' : '/' . $locale . '/' . $segment . '/';
	}

	/**
	 * Returns the localized single-record path.
	 *
	 * @param string $post_type Public view key.
	 * @param string $locale    Supported locale slug.
	 * @param string $slug      Record slug.
	 */
	public static function single_path( string $post_type, string $locale, string $slug ): string {
		$archive = self::archive_path( $post_type, $locale );
		return '' === $archive || '' === $slug ? $archive : $archive . $slug . '/';
	}

	/**
	 * Resolves a public path back to its view, locale, and slug.
	 *
	 * @param string $path Public request path.
	 * @return array{post_type: string, locale: string, slug: string}|null
	 */
	public static function match_path( string $path ): ?array {
		$clean = '/' . trim( $path, '/' ) . '/';
		if ( 1 !== preg_match( '#^/(pt-br|en)/([^/]+)/(?:([^/]+)/)?$#', $clean, $parts ) ) {
			return null;
		}
		foreach ( self::SEGMENTS as $post_type => $segments ) {
			if ( $segments[ $parts[1] ] === $parts[2] ) {
				return array(
					'post_type' => $post_type,
					'locale'    => $parts[1],
					'slug'      => $parts[3] ?? '',
				);
			}
		}
		return null;
	}

	/**
	 * Returns the BCP47 language tag for a supported locale slug.
	 *
	 * @param string $locale Supported locale slug.
	 */
	public static function bcp47( string $locale ): string {
		return self::LANGUAGE_TAGS[ $locale ] ?? self::LANGUAGE_TAGS['pt-br'];
	}

	/**
	 * Returns WordPress rewrite rules for every public route.
	 *
	 * @return array<string, string>
	 */
	public static function rewrite_rules(): array {
		$rules = array();
		foreach ( self::SEGMENTS as $view => $segments ) {
			foreach ( $segments as $locale => $segment ) {
				$base   = $locale . '/' . $segment;
				$suffix = '&' . self::VIEW_QUERY_VAR . '=' . $view . '&' . self::LOCALE_QUERY_VAR . '=' . $locale . '&lang=' . $locale;

				if ( 'lps_infrastructure' === $view ) {
					// Infrastructure is an editorial page, so its route resolves to the page itself.
					$rules[ $base . '/?$' ] = 'index.php?pagename=' . $segment . $suffix;
					continue;
				}

				$query                                   = 'index.php?post_type=' . $view . $suffix;
				$rules[ $base . '/page/([0-9]{1,})/?$' ] = $query . '&paged=$matches[1]';
				$rules[ $base . '/([^/]+)/?$' ]          = $query . '&name=$matches[1]';
				$rules[ $base . '/?$' ]                  = $query;
			}
		}
		return $rules;
	}

	/**
	 * Replaces the document language tag on public routes.
	 *
	 * @param string $output Rendered language attributes.
	 * @param string $path   Public request path.
	 */
	public static function language_attributes_for_path( string $output, string $path ): string {
		$route = self::match_path( $path );
		if ( null === $route ) {
			return $output;
		}
		$tagged = preg_replace( '/lang="[^"]*"/', 'lang="' . self::bcp47( $route['locale'] ) . '"', $output );
		return is_string( $tagged ) ? $tagged : $output;
	}

	/**
	 * Builds the publishable person record from stored metadata.
	 *
	 * Storage keys never reach the returned record: only reviewed, rights-cleared,
	 * and consent-approved values are copied onto the public shape.
	 *
	 * @param string             $slug    Person slug.
	 * @param string             $title   Displayed name.
	 * @param array<mixed,mixed> $meta    Stored person metadata.
	 * @param array<int, mixed>  $history Historical project and publication links.
	 * @return array<string, mixed>
	 */
	public static function person_record( string $slug, string $title, array $meta, array $history = array() ): array {
		$reviewed = self::flag( $meta, '_lps_privacy_reviewed' );
		$status   = self::value( $meta, '_lps_person_status' );
		$status   = in_array( $status, self::STATUSES, true ) ? $status : 'active';
		$roles    = self::strings( $meta['_lps_roles'] ?? array() );
		$rights   = self::value( $meta, '_lps_photo_rights' );
		$photo    = self::value( $meta, '_lps_photo_url' );
		$email    = self::value( $meta, '_lps_public_email' );

		$published = 'archived' !== self::value( $meta, '_lps_state' );
		if ( 'in-memoriam' === $status && ! self::flag( $meta, '_lps_in_memoriam_approved' ) ) {
			$published = false;
		}

		$publishable_photo = $reviewed && 'cleared' === $rights && self::is_local_path( $photo );

		return array(
			'slug'             => $slug,
			'name'             => '' === $title ? self::value( $meta, '_lps_canonical_name' ) : $title,
			'roles'            => $roles,
			'status'           => $status,
			'areas'            => self::strings( $meta['_lps_research_area_ids'] ?? array() ),
			'public_email'     => $reviewed && self::is_email( $email ) ? $email : '',
			'privacy_reviewed' => $reviewed,
			'photo_url'        => $publishable_photo ? $photo : '',
			'photo_rights'     => $publishable_photo ? 'cleared' : '',
			'photo_alt'        => $publishable_photo ? self::value( $meta, '_lps_photo_alt' ) : '',
			'orcid'            => self::value( $meta, '_lps_orcid' ),
			'lattes_url'       => self::value( $meta, '_lps_lattes_url' ),
			'start_date'       => self::value( $meta, '_lps_start_date' ),
			'end_date'         => self::value( $meta, '_lps_end_date' ),
			'external'         => in_array( 'external-collaborator', $roles, true ),
			'history'          => array_values( $history ),
			'published'        => $published,
		);
	}

	/**
	 * Builds the publishable organization record from stored metadata.
	 *
	 * @param string             $slug  Organization slug.
	 * @param string             $title Displayed name.
	 * @param array<mixed,mixed> $meta  Stored organization metadata.
	 * @return array<string, mixed>
	 */
	public static function organization_record( string $slug, string $title, array $meta ): array {
		if ( ! self::flag( $meta, '_lps_public_profile' ) ) {
			return array(
				'slug'           => $slug,
				'public_profile' => false,
			);
		}
		$logo    = self::value( $meta, '_lps_logo_url' );
		$rights  = self::value( $meta, '_lps_logo_rights' );
		$cleared = 'cleared' === $rights && self::is_local_path( $logo );

		return array(
			'slug'           => $slug,
			'name'           => '' === $title ? self::value( $meta, '_lps_organization_name' ) : $title,
			'kind'           => self::value( $meta, '_lps_organization_kind' ),
			'acronym'        => self::value( $meta, '_lps_acronym' ),
			'country_code'   => self::value( $meta, '_lps_country_code' ),
			'canonical_url'  => self::value( $meta, '_lps_canonical_url' ),
			'ror_id'         => self::value( $meta, '_lps_ror_id' ),
			'logo_url'       => $cleared ? $logo : '',
			'logo_rights'    => $cleared ? 'cleared' : '',
			'public_profile' => true,
		);
	}

	/**
	 * Returns the language-neutral metadata keys owned by the Portuguese authority.
	 *
	 * Localized values, such as a photo description, stay with the variant that
	 * renders them; identity, status, dates, rights, and publication state belong
	 * to the authority record and must read the same in both locales.
	 *
	 * @param string $post_type Record type.
	 * @return array<int, string>
	 */
	public static function authority_meta_keys( string $post_type ): array {
		$common  = array( '_lps_state' );
		$by_type = array(
			'lps_person'       => array(
				'_lps_canonical_name',
				'_lps_sort_name',
				'_lps_person_status',
				'_lps_roles',
				'_lps_affiliations',
				'_lps_start_date',
				'_lps_end_date',
				'_lps_public_email',
				'_lps_orcid',
				'_lps_lattes_url',
				'_lps_scholar_url',
				'_lps_website_url',
				'_lps_credentials',
				'_lps_photo_rights',
				'_lps_photo_url',
				'_lps_privacy_reviewed',
				'_lps_research_area_ids',
				'_lps_in_memoriam_approved',
			),
			'lps_organization' => array(
				'_lps_organization_name',
				'_lps_acronym',
				'_lps_organization_kind',
				'_lps_country_code',
				'_lps_canonical_url',
				'_lps_ror_id',
				'_lps_logo_url',
				'_lps_logo_rights',
				'_lps_public_profile',
			),
			'page'             => array( '_lps_page_key' ),
		);
		return array_merge( $common, $by_type[ $post_type ] ?? array() );
	}

	/**
	 * Merges the Portuguese authority fields into one translated variant.
	 *
	 * @param string               $post_type Record type.
	 * @param array<string, mixed> $local     Stored metadata of the variant.
	 * @param array<string, mixed> $authority Stored metadata of the authority record.
	 * @return array<string, mixed>
	 */
	public static function with_authority_meta( string $post_type, array $local, array $authority ): array {
		foreach ( self::authority_meta_keys( $post_type ) as $key ) {
			if ( array_key_exists( $key, $authority ) ) {
				$local[ $key ] = $authority[ $key ];
			}
		}
		return $local;
	}

	/**
	 * Builds the publishable facility record from stored metadata.
	 *
	 * A capability claim without a source and a review date is dropped rather than
	 * rendered, so the page never states an unverified capability.
	 *
	 * @param string             $slug  Facility slug.
	 * @param string             $title Facility name.
	 * @param array<mixed,mixed> $meta  Stored facility metadata.
	 * @return array<string, mixed>
	 */
	public static function facility_record( string $slug, string $title, array $meta ): array {
		$claims = array();
		$stored = $meta['_lps_capability_claims'] ?? array();
		if ( is_array( $stored ) ) {
			foreach ( $stored as $claim ) {
				if ( ! is_array( $claim ) ) {
					continue;
				}
				$text     = trim( self::text( $claim['text'] ?? '' ) );
				$source   = trim( self::text( $claim['source_url'] ?? '' ) );
				$reviewed = trim( self::text( $claim['reviewed_at'] ?? '' ) );
				if ( '' === $text || '' === $source || '' === $reviewed ) {
					continue;
				}
				$claims[] = array(
					'text'        => $text,
					'source_url'  => $source,
					'reviewed_at' => $reviewed,
				);
			}
		}

		return array(
			'slug'      => $slug,
			'name'      => $title,
			'claims'    => $claims,
			'equipment' => self::links( $meta['_lps_equipment_links'] ?? array() ),
			'research'  => self::links( $meta['_lps_research_links'] ?? array() ),
			'projects'  => self::links( $meta['_lps_project_links'] ?? array() ),
			'contacts'  => self::links( $meta['_lps_contact_links'] ?? array() ),
		);
	}

	/**
	 * Reports whether one public address may answer with a page.
	 *
	 * Listings always answer. A single address answers only when its record is
	 * publishable, so a withheld person or a hidden organization never resolves
	 * with the surrounding page chrome that would still carry its name.
	 *
	 * @param string                         $post_type Record type.
	 * @param array<int, array<mixed,mixed>> $records   Publishable records of the locale.
	 * @param string                         $slug      Requested record slug, empty on a listing.
	 */
	public static function is_addressable( string $post_type, array $records, string $slug ): bool {
		if ( '' === $slug ) {
			return true;
		}
		foreach ( $records as $record ) {
			if ( self::value( $record, 'slug' ) !== $slug ) {
				continue;
			}
			if ( 'lps_organization' === $post_type ) {
				return true === ( $record['public_profile'] ?? false );
			}
			return false !== ( $record['published'] ?? true );
		}
		return false;
	}

	/** Answers a withheld or unknown single address with the 404 template. */
	public static function guard_withheld_records(): void {
		$route = self::match_path( self::request_path() );
		if ( null === $route || '' === $route['slug'] || 'lps_infrastructure' === $route['post_type'] ) {
			return;
		}
		$records = 'lps_organization' === $route['post_type']
			? self::organizations( $route['locale'] )
			: self::people( $route['locale'] );
		if ( self::is_addressable( $route['post_type'], $records, $route['slug'] ) ) {
			return;
		}
		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
	}

	/** Registers routes, query vars, and the public language tag. */
	public static function boot(): void {
		add_filter( 'rewrite_rules_array', array( self::class, 'register_routes' ), 998 );
		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
		add_filter( 'redirect_canonical', array( self::class, 'keep_locale_route' ), 10, 2 );
		add_filter( 'language_attributes', array( self::class, 'route_language_attributes' ), 210 );
		add_action( 'template_redirect', array( self::class, 'guard_withheld_records' ), 5 );
	}

	/**
	 * Prepends the public rewrite rules.
	 *
	 * @param array<string, string> $rules Registered rewrite rules.
	 * @return array<string, string>
	 */
	public static function register_routes( array $rules ): array {
		return array_merge( self::rewrite_rules(), $rules );
	}

	/**
	 * Registers the public query vars.
	 *
	 * @param array<int, string> $vars Registered public query vars.
	 * @return array<int, string>
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = self::LOCALE_QUERY_VAR;
		$vars[] = self::VIEW_QUERY_VAR;
		return $vars;
	}

	/**
	 * Keeps public locale routes stable instead of redirecting to the default permalink.
	 *
	 * @param string|false $redirect_url  Proposed canonical URL.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public static function keep_locale_route( string|false $redirect_url, string $requested_url ): string|false {
		$path = wp_parse_url( $requested_url, PHP_URL_PATH );
		if ( is_string( $path ) && null !== self::match_path( $path ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Applies the route language tag to the current request.
	 *
	 * @param string $output Rendered language attributes.
	 */
	public static function route_language_attributes( string $output ): string {
		return self::language_attributes_for_path( $output, self::request_path() );
	}

	/** Renders the public surface addressed by the current request. */
	public static function render_block(): string {
		$route = self::match_path( self::request_path() );
		if ( null === $route ) {
			return '';
		}
		$locale = $route['locale'];
		if ( 'lps_infrastructure' === $route['post_type'] ) {
			return PublicSurfaces::infrastructure_page( $locale, self::facilities( $locale ) );
		}
		if ( 'lps_organization' === $route['post_type'] ) {
			return self::render_organizations( $locale, $route['slug'] );
		}
		return self::render_people( $locale, $route['slug'] );
	}

	/**
	 * Renders the people listing or one profile.
	 *
	 * @param string $locale Supported locale slug.
	 * @param string $slug   Requested person slug, empty on the listing.
	 */
	private static function render_people( string $locale, string $slug ): string {
		$people = self::people( $locale );
		if ( '' === $slug ) {
			return PublicSurfaces::people_listing( $locale, $people, self::filters() );
		}
		foreach ( $people as $person ) {
			if ( $slug === $person['slug'] ) {
				return PublicSurfaces::person_profile( $locale, $person );
			}
		}
		return PublicSurfaces::person_profile( $locale, array() );
	}

	/**
	 * Renders the organization listing or one profile.
	 *
	 * @param string $locale Supported locale slug.
	 * @param string $slug   Requested organization slug, empty on the listing.
	 */
	private static function render_organizations( string $locale, string $slug ): string {
		$html = '';
		foreach ( self::organizations( $locale ) as $organization ) {
			if ( '' !== $slug && $slug !== $organization['slug'] ) {
				continue;
			}
			// A requested slug renders the record's own page (H1); the listing nests them (H2).
			$html .= PublicSurfaces::organization_profile( $locale, $organization, '' === $slug ? 2 : 1 );
		}
		return $html;
	}

	/**
	 * Returns the published people of one locale.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<int, array<string, mixed>>
	 */
	public static function people( string $locale ): array {
		$people = array();
		foreach ( self::records( 'lps_person', $locale ) as $post ) {
			$record             = self::person_record( $post->post_name, $post->post_title, self::meta( $post->ID ), self::history( $post, $locale ) );
			$record['stale']    = self::is_stale_translation( $post );
			$record['teaching'] = self::teaching_history( $post, $locale );
			if ( true === $record['published'] ) {
				$people[] = $record;
			}
		}
		return $people;
	}

	/**
	 * Returns the published organizations of one locale.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<int, array<string, mixed>>
	 */
	public static function organizations( string $locale ): array {
		$organizations = array();
		foreach ( self::records( 'lps_organization', $locale ) as $post ) {
			$record          = self::organization_record( $post->post_name, $post->post_title, self::meta( $post->ID ) );
			$record['stale'] = self::is_stale_translation( $post );
			if ( true === $record['public_profile'] ) {
				$organizations[] = $record;
			}
		}
		return $organizations;
	}

	/**
	 * Returns the infrastructure facilities of one locale.
	 *
	 * @param string $locale Supported locale slug.
	 * @return array<int, array<string, mixed>>
	 */
	private static function facilities( string $locale ): array {
		$facilities = array();
		foreach ( self::records( 'page', $locale ) as $post ) {
			$meta = self::meta( $post->ID );
			if ( 'infrastructure-facility' !== self::value( $meta, '_lps_page_key' ) ) {
				continue;
			}
			$facility          = self::facility_record( $post->post_name, $post->post_title, $meta );
			$facility['stale'] = self::is_stale_translation( $post );
			$facilities[]      = $facility;
		}
		return $facilities;
	}

	/**
	 * Returns the stable historical links of one person.
	 *
	 * Membership and authorship links survive a departure so archived work keeps
	 * resolving to the same public addresses.
	 *
	 * @param WP_Post $post   Person record.
	 * @param string  $locale Supported locale slug.
	 * @return array<int, array<string, string>>
	 */
	private static function history( WP_Post $post, string $locale ): array {
		if ( ! class_exists( Relationships::class ) ) {
			return array();
		}
		$authority = self::authority_id( $post );
		$history   = array();
		foreach ( Relationships::reverse_for( $authority, 'project_member' ) as $row ) {
			$project = self::localized_post( $row['source_post_id'], $locale );
			if ( $project instanceof WP_Post && 'lps_project' === $project->post_type && 'publish' === $project->post_status ) {
				$history[] = array(
					'title' => $project->post_title,
					'url'   => DiscoveryRoutes::single_path( 'lps_project', $locale, $project->post_name ),
				);
			}
		}
		foreach ( Relationships::publications_for_author( $authority ) as $row ) {
			$publication = self::localized_post( $row['publication_id'], $locale );
			if ( $publication instanceof WP_Post && 'publish' === $publication->post_status ) {
				$history[] = array(
					'title' => $publication->post_title,
					'url'   => DiscoveryRoutes::single_path( 'lps_publication', $locale, $publication->post_name ),
				);
			}
		}
		return $history;
	}

	/**
	 * Returns the derived teaching history of one person in the route locale.
	 *
	 * The entries come from the canonical `teaching_team` rows read backwards
	 * through `TeachingRecords::person_history`, so a co-taught offering appears
	 * on every member's profile with the same course, term, and section data —
	 * never as a duplicated per-person copy.
	 *
	 * @param WP_Post $post   Person record.
	 * @param string  $locale Supported locale slug.
	 * @return array<int, array<string, mixed>>
	 */
	private static function teaching_history( WP_Post $post, string $locale ): array {
		if ( ! class_exists( TeachingRecords::class ) ) {
			return array();
		}
		$history = TeachingRecords::person_history( $post->ID, $locale, 'view' );
		if ( ! is_array( $history ) || ! is_array( $history['entries'] ?? null ) ) {
			return array();
		}
		/**
		 * History entries are record maps as declared by the teaching contract.
		 *
		 * @var array<int, array<string, mixed>> $entries
		 */
		$entries = $history['entries'];
		return $entries;
	}

	/**
	 * Returns published records of one type and locale.
	 *
	 * The set is read one bounded page at a time so a single query never asks
	 * the database for more rows than a pagination limit allows; the pages are
	 * appended in query order, so the listing sees the same records a single
	 * wide query would have returned.
	 *
	 * @param string $post_type Record type.
	 * @param string $locale    Supported locale slug.
	 * @return array<int, WP_Post>
	 */
	private static function records( string $post_type, string $locale ): array {
		$posts  = array();
		$offset = 0;
		do {
			$query = new WP_Query(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'posts_per_page'         => 100,
					'offset'                 => $offset,
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					// Pin the queried locale: on requests without a locale prefix (locale
					// sitemaps, feeds) Polylang would otherwise filter to the default
					// language and silently drop the records of the requested locale.
					'lang'                   => $locale,
					'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- locale is the routing key of this surface.
						array(
							'key'   => '_lps_locale',
							'value' => $locale,
						),
					),
				)
			);
			$batch = count( $query->posts );
			foreach ( $query->posts as $post ) {
				if ( $post instanceof WP_Post ) {
					$posts[] = $post;
				}
			}
			$offset += $batch;
			$kept    = count( $posts );
		} while ( 100 <= $batch && $kept < 200 );
		return $posts;
	}

	/**
	 * Returns the stored metadata of one record.
	 *
	 * @param int $post_id Record database ID.
	 * @return array<string, mixed>
	 */
	private static function meta( int $post_id ): array {
		$stored = get_post_meta( $post_id );
		$meta   = array();
		if ( ! is_array( $stored ) ) {
			return $meta;
		}
		foreach ( $stored as $key => $values ) {
			if ( ! is_string( $key ) || ! is_array( $values ) || ! isset( $values[0] ) ) {
				continue;
			}
			$single       = get_post_meta( $post_id, $key, true );
			$meta[ $key ] = $single;
		}
		$authority = self::authority_id_for( $post_id );
		if ( 0 === $authority || $authority === $post_id ) {
			return $meta;
		}
		$post_type = get_post_type( $post_id );
		return self::with_authority_meta( is_string( $post_type ) ? $post_type : '', $meta, self::meta( $authority ) );
	}

	/**
	 * Returns the Portuguese authority record of one variant, or zero.
	 *
	 * @param int $post_id Variant database ID.
	 */
	private static function authority_id_for( int $post_id ): int {
		if ( ! class_exists( Translations::class ) ) {
			return 0;
		}
		$source = Translations::source_id( $post_id );
		return is_int( $source ) ? $source : 0;
	}

	/**
	 * Returns the sanitized listing filters of the current request.
	 *
	 * A facet is read only when the request actually submitted a string or an
	 * array for it, and every value it carries is reduced to a key while it is
	 * read, so nothing unsanitized exists past this line. Anything else - an
	 * absent facet, a nested array, a value of another type - contributes no
	 * filter at all, exactly as an empty submission does.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function filters(): array {
		$filters = array();
		foreach ( array( 'role', 'status', 'area' ) as $name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public listing facet.
			$raw = isset( $_GET[ $name ] ) && ( is_string( $_GET[ $name ] ) || is_array( $_GET[ $name ] ) ) ? map_deep( wp_unslash( $_GET[ $name ] ), 'sanitize_key' ) : array();
			if ( is_string( $raw ) ) {
				$raw = array( $raw );
			}
			$values = array();
			if ( is_array( $raw ) ) {
				foreach ( $raw as $value ) {
					if ( is_string( $value ) && '' !== $value ) {
						$values[] = $value;
					}
				}
			}
			$filters[ $name ] = $values;
		}
		return $filters;
	}

	/**
	 * Returns the variant of a record that belongs to the current locale.
	 *
	 * @param int    $post_id Related record database ID.
	 * @param string $locale  Supported locale slug.
	 */
	private static function localized_post( int $post_id, string $locale ): ?WP_Post {
		if ( 0 >= $post_id ) {
			return null;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! class_exists( Translations::class ) ) {
			return $post instanceof WP_Post ? $post : null;
		}
		if ( Translations::locale( $post->ID ) === $locale ) {
			return $post;
		}
		// A missing variant resolves to nothing: history links never substitute
		// the other language's record for the requested locale.
		$variants = Translations::variants( $post->ID );
		$variant  = isset( $variants[ $locale ] ) ? get_post( $variants[ $locale ] ) : null;
		return $variant instanceof WP_Post ? $variant : null;
	}

	/**
	 * Reports whether an English record trails its reviewed Portuguese source.
	 *
	 * Staleness is a source-hash comparison owned by the translation policy, never
	 * a timestamp guess: the flag is set only for English variants whose reviewed
	 * hash no longer matches the authority record.
	 *
	 * @param WP_Post $post Record.
	 */
	private static function is_stale_translation( WP_Post $post ): bool {
		if ( ! class_exists( Translations::class ) ) {
			return false;
		}
		return 'en' === Translations::locale( $post->ID ) && Translations::is_stale( $post->ID );
	}

	/**
	 * Returns the database ID of the Portuguese authority record.
	 *
	 * @param WP_Post $post Record.
	 */
	private static function authority_id( WP_Post $post ): int {
		if ( ! class_exists( Translations::class ) ) {
			return $post->ID;
		}
		$source = Translations::source_id( $post->ID );
		return null === $source ? $post->ID : $source;
	}

	/** Returns the sanitized path of the current request. */
	private static function request_path(): string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}
		$path = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		return is_string( $path ) ? $path : '/';
	}

	/**
	 * Reads one stored metadata value as a string.
	 *
	 * @param array<mixed,mixed> $meta Stored metadata.
	 * @param string             $key  Metadata key.
	 */
	private static function value( array $meta, string $key ): string {
		return self::text( $meta[ $key ] ?? '' );
	}

	/**
	 * Reads one stored metadata value as a boolean flag.
	 *
	 * @param array<mixed,mixed> $meta Stored metadata.
	 * @param string             $key  Metadata key.
	 */
	private static function flag( array $meta, string $key ): bool {
		$value = $meta[ $key ] ?? false;
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		return is_string( $value ) && in_array( strtolower( $value ), array( '1', 'true', 'yes' ), true );
	}

	/**
	 * Converts boundary input to a string list.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<int, string>
	 */
	private static function strings( mixed $value ): array {
		if ( is_string( $value ) && '' !== $value ) {
			return array( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$list = array();
		foreach ( $value as $item ) {
			$text = self::text( $item );
			if ( '' !== $text ) {
				$list[] = $text;
			}
		}
		return $list;
	}

	/**
	 * Converts boundary input to a title and URL link list.
	 *
	 * @param mixed $value Boundary input.
	 * @return array<int, array<string, string>>
	 */
	private static function links( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$links = array();
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$title = trim( self::text( $item['title'] ?? $item['name'] ?? '' ) );
			$url   = trim( self::text( $item['url'] ?? '' ) );
			if ( '' !== $title && '' !== $url ) {
				$links[] = array(
					'title' => $title,
					'url'   => $url,
				);
			}
		}
		return $links;
	}

	/**
	 * Reports whether a stored path is a local upload path.
	 *
	 * @param string $path Stored asset path.
	 */
	private static function is_local_path( string $path ): bool {
		return '' !== $path && 0 === strpos( $path, '/' ) && false === strpos( $path, '://' );
	}

	/**
	 * Reports whether a stored contact value is a usable email address.
	 *
	 * @param string $email Stored contact value.
	 */
	private static function is_email( string $email ): bool {
		return '' !== $email && is_string( filter_var( $email, FILTER_VALIDATE_EMAIL ) );
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
}
