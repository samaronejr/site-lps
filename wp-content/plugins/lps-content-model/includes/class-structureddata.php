<?php
/**
 * Schema.org JSON-LD emission for every public record type.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

require_once __DIR__ . '/class-seopolicy.php';

/**
 * Builds the structured-data graph published by the public surface.
 *
 * Every value is copied from a stored, reviewed field. Identifiers are emitted
 * only when the record actually carries them, ratings and reviews are never
 * emitted at all, and a scholarship is never described as employment.
 */
final class StructuredData {
	/**
	 * Publication type to Schema.org type.
	 *
	 * @var array<string, string>
	 */
	private const PUBLICATION_TYPES = array(
		'journal-article'  => 'ScholarlyArticle',
		'conference-paper' => 'ScholarlyArticle',
		'preprint'         => 'ScholarlyArticle',
		'book'             => 'Book',
		'book-chapter'     => 'Chapter',
		'thesis'           => 'Thesis',
		'dataset'          => 'Dataset',
		'software'         => 'SoftwareSourceCode',
		'report'           => 'Report',
	);

	/**
	 * Opportunity types that describe a genuine employment relationship.
	 *
	 * Every other opportunity - scholarships, fellowships, internships, and
	 * grants - is educational funding and must never claim employment.
	 *
	 * @var array<int, string>
	 */
	private const EMPLOYMENT_TYPES = array( 'employment', 'staff-position', 'faculty-position' );

	/**
	 * Derived event state to Schema.org event status.
	 *
	 * @var array<string, string>
	 */
	private const EVENT_STATUSES = array(
		'cancelled' => 'https://schema.org/EventCancelled',
		'postponed' => 'https://schema.org/EventPostponed',
		'upcoming'  => 'https://schema.org/EventScheduled',
		'ongoing'   => 'https://schema.org/EventScheduled',
		'past'      => 'https://schema.org/EventScheduled',
	);

	/**
	 * Builds the WebSite node.
	 *
	 * @param array<string, mixed> $site Site identity.
	 * @return array<string, mixed>
	 */
	public static function website( array $site ): array {
		$site_url = self::site_url( $site );
		return array(
			'@type'      => 'WebSite',
			'@id'        => $site_url . '/#website',
			'url'        => $site_url . '/',
			'name'       => self::text( $site['name'] ?? '' ),
			'inLanguage' => SeoPolicy::bcp47( self::text( $site['locale'] ?? SeoPolicy::DEFAULT_LOCALE ) ),
			'publisher'  => array( '@id' => $site_url . '/#organization' ),
		);
	}

	/**
	 * Builds the ResearchOrganization node.
	 *
	 * @param array<string, mixed> $site Site identity.
	 * @return array<string, mixed>
	 */
	public static function research_organization( array $site ): array {
		$site_url = self::site_url( $site );
		$node     = array(
			'@type' => 'ResearchOrganization',
			'@id'   => $site_url . '/#organization',
			'url'   => $site_url . '/',
			'name'  => self::text( $site['name'] ?? '' ),
		);
		$acronym  = self::text( $site['acronym'] ?? '' );
		if ( '' !== $acronym ) {
			$node['alternateName'] = $acronym;
		}
		$founded = self::text( $site['founded'] ?? '' );
		if ( '' !== $founded ) {
			$node['foundingDate'] = $founded;
		}
		$parents = array();
		foreach ( self::strings( $site['parents'] ?? array() ) as $parent ) {
			$parents[] = array(
				'@type' => 'Organization',
				'name'  => $parent,
			);
		}
		if ( array() !== $parents ) {
			$node['parentOrganization'] = $parents;
		}
		$address = self::text( $site['address'] ?? '' );
		if ( '' !== $address ) {
			$node['address'] = $address;
		}
		$contact = self::text( $site['contact'] ?? '' );
		if ( '' !== $contact ) {
			$node['email'] = $contact;
		}
		$logo = self::logo( $site['logo'] ?? null );
		if ( array() !== $logo ) {
			$node['logo'] = $logo;
		}
		return $node;
	}

	/**
	 * Builds the organization's logo as an ImageObject.
	 *
	 * The logo is the laboratory's own cleared mark (DESIGN.md §9): a local,
	 * self-hosted asset whose rights record lives in the theme's mark directory.
	 * Only a descriptor with a URL is emitted; dimensions ride along when the
	 * site identity supplies them.
	 *
	 * @param mixed $value Site-identity logo descriptor.
	 * @return array<string, mixed>
	 */
	private static function logo( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$url = self::text( $value['url'] ?? '' );
		if ( '' === $url ) {
			return array();
		}
		$node = array(
			'@type' => 'ImageObject',
			'url'   => $url,
		);
		foreach ( array( 'width', 'height' ) as $key ) {
			$dimension = $value[ $key ] ?? null;
			if ( is_numeric( $dimension ) && (int) $dimension > 0 ) {
				$node[ $key ] = (int) $dimension;
			}
		}
		return $node;
	}

	/**
	 * Builds a BreadcrumbList for one page.
	 *
	 * @param string                           $site_url Canonical site URL.
	 * @param string                           $path     Page path.
	 * @param array<int, array<string, mixed>> $trail    Ordered trail entries.
	 * @return array<string, mixed>
	 */
	public static function breadcrumbs( string $site_url, string $path, array $trail ): array {
		$items    = array();
		$position = 0;
		foreach ( $trail as $entry ) {
			$name = self::text( $entry['name'] ?? '' );
			$step = self::text( $entry['path'] ?? '' );
			if ( '' === $name || '' === $step ) {
				continue;
			}
			++$position;
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => $name,
				'item'     => SeoPolicy::canonical_url( $site_url, $step ),
			);
		}
		if ( array() === $items ) {
			return array();
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => SeoPolicy::canonical_url( $site_url, $path ) . '#breadcrumbs',
			'itemListElement' => $items,
		);
	}

	/**
	 * Builds the page-type node describing one document.
	 *
	 * @param string               $site_url  Canonical site URL.
	 * @param string               $path      Page path.
	 * @param string               $page_type Schema.org page type.
	 * @param array<string, mixed> $page      Page fields.
	 * @return array<string, mixed>
	 */
	public static function page( string $site_url, string $path, string $page_type, array $page ): array {
		$canonical   = SeoPolicy::canonical_url( $site_url, $path );
		$node        = array(
			'@type'      => '' === trim( $page_type ) ? 'WebPage' : $page_type,
			'@id'        => $canonical . '#webpage',
			'url'        => $canonical,
			'name'       => self::text( $page['title'] ?? '' ),
			'inLanguage' => SeoPolicy::bcp47( self::text( $page['locale'] ?? SeoPolicy::DEFAULT_LOCALE ) ),
			'isPartOf'   => array( '@id' => rtrim( $site_url, '/' ) . '/#website' ),
		);
		$description = self::text( $page['description'] ?? '' );
		if ( '' !== $description ) {
			$node['description'] = $description;
		}
		return $node;
	}

	/**
	 * Builds a ProfilePage plus its Person for a privacy-reviewed record.
	 *
	 * @param string               $site_url Canonical site URL.
	 * @param string               $path     Page path.
	 * @param array<string, mixed> $person   Person fields.
	 * @return array<string, mixed>
	 */
	public static function profile_page( string $site_url, string $path, array $person ): array {
		if ( true !== ( $person['privacy_reviewed'] ?? false ) ) {
			return array();
		}
		$canonical = SeoPolicy::canonical_url( $site_url, $path );
		$node      = array(
			'@type' => 'Person',
			'@id'   => $canonical . '#person',
			'name'  => self::text( $person['name'] ?? '' ),
			'url'   => $canonical,
		);
		$roles     = self::strings( $person['roles'] ?? array() );
		if ( array() !== $roles ) {
			$node['jobTitle'] = $roles;
		}
		$summary = self::text( $person['summary'] ?? '' );
		if ( '' !== $summary ) {
			$node['description'] = $summary;
		}
		$orcid = self::text( $person['orcid'] ?? '' );
		$links = array();
		if ( '' !== $orcid ) {
			$orcid_url          = 'https://orcid.org/' . $orcid;
			$node['identifier'] = $orcid_url;
			$links[]            = $orcid_url;
		}
		foreach ( array( 'lattes_url', 'scholar_url', 'website_url' ) as $key ) {
			$url = self::text( $person[ $key ] ?? '' );
			if ( '' !== $url ) {
				$links[] = $url;
			}
		}
		if ( array() !== $links ) {
			$node['sameAs'] = $links;
		}
		$email = self::text( $person['public_email'] ?? '' );
		if ( '' !== $email ) {
			$node['email'] = $email;
		}
		$node['affiliation'] = array( '@id' => rtrim( $site_url, '/' ) . '/#organization' );

		return array(
			'@type'      => 'ProfilePage',
			'@id'        => $canonical . '#profilepage',
			'url'        => $canonical,
			'mainEntity' => $node,
		);
	}

	/**
	 * Builds a ResearchProject node.
	 *
	 * @param string               $site_url Canonical site URL.
	 * @param string               $path     Page path.
	 * @param array<string, mixed> $project  Project fields.
	 * @return array<string, mixed>
	 */
	public static function research_project( string $site_url, string $path, array $project ): array {
		$canonical = SeoPolicy::canonical_url( $site_url, $path );
		$node      = array(
			'@type' => 'ResearchProject',
			'@id'   => $canonical . '#project',
			'url'   => $canonical,
			'name'  => self::text( $project['name'] ?? '' ),
		);
		$summary   = self::text( $project['summary'] ?? '' );
		if ( '' !== $summary ) {
			$node['description'] = $summary;
		}
		$start = self::text( $project['start'] ?? '' );
		if ( '' !== $start ) {
			$node['startDate'] = $start;
		}
		$end = self::text( $project['end'] ?? '' );
		if ( '' !== $end ) {
			$node['endDate'] = $end;
		}
		$funders = array();
		foreach ( self::strings( $project['funders'] ?? array() ) as $funder ) {
			$funders[] = array(
				'@type' => 'Organization',
				'name'  => $funder,
			);
		}
		if ( array() !== $funders ) {
			$node['funder'] = $funders;
		}
		$members = array();
		foreach ( self::strings( $project['members'] ?? array() ) as $member ) {
			$members[] = array(
				'@type' => 'Person',
				'name'  => $member,
			);
		}
		if ( array() !== $members ) {
			$node['member'] = $members;
		}
		$node['parentOrganization'] = array( '@id' => rtrim( $site_url, '/' ) . '/#organization' );
		return $node;
	}

	/**
	 * Builds the scholarly node for one publication.
	 *
	 * @param string               $site_url    Canonical site URL.
	 * @param string               $path        Page path.
	 * @param array<string, mixed> $publication Publication fields.
	 * @return array<string, mixed>
	 */
	public static function publication( string $site_url, string $path, array $publication ): array {
		$canonical = SeoPolicy::canonical_url( $site_url, $path );
		$type      = self::text( $publication['type'] ?? '' );
		$node      = array(
			'@type' => self::PUBLICATION_TYPES[ $type ] ?? 'CreativeWork',
			'@id'   => $canonical . '#publication',
			'url'   => $canonical,
			'name'  => self::text( $publication['title'] ?? '' ),
		);
		$date      = self::text( $publication['date'] ?? '' );
		if ( '' !== $date ) {
			$node['datePublished'] = $date;
		}
		$language = self::text( $publication['language'] ?? '' );
		if ( '' !== $language ) {
			$node['inLanguage'] = $language;
		}
		$authors = array();
		foreach ( self::strings( $publication['authors'] ?? array() ) as $author ) {
			$authors[] = array(
				'@type' => 'Person',
				'name'  => $author,
			);
		}
		if ( array() !== $authors ) {
			$node['author'] = $authors;
		}
		$venue = self::text( $publication['venue'] ?? '' );
		if ( '' !== $venue ) {
			$node['isPartOf'] = array(
				'@type' => 'Periodical',
				'name'  => $venue,
			);
		}
		$license = self::text( $publication['license'] ?? '' );
		if ( '' !== $license ) {
			$node['license'] = $license;
		}
		$links = array();
		$doi   = self::text( $publication['doi'] ?? '' );
		if ( '' !== $doi ) {
			$doi_url            = 'https://doi.org/' . $doi;
			$node['identifier'] = $doi_url;
			$links[]            = $doi_url;
		}
		foreach ( array( 'canonical_url', 'open_access_url', 'code_url', 'data_url' ) as $key ) {
			$url = self::text( $publication[ $key ] ?? '' );
			if ( '' !== $url ) {
				$links[] = $url;
			}
		}
		if ( array() !== $links ) {
			$node['sameAs'] = array_values( array_unique( $links ) );
		}
		return $node;
	}

	/**
	 * Builds a NewsArticle node.
	 *
	 * @param string               $site_url Canonical site URL.
	 * @param string               $path     Page path.
	 * @param array<string, mixed> $news     News fields.
	 * @param array<string, mixed> $site     Site identity.
	 * @return array<string, mixed>
	 */
	public static function news_article( string $site_url, string $path, array $news, array $site ): array {
		unset( $site );
		$canonical = SeoPolicy::canonical_url( $site_url, $path );
		$node      = array(
			'@type'     => 'NewsArticle',
			'@id'       => $canonical . '#newsarticle',
			'url'       => $canonical,
			'name'      => self::text( $news['title'] ?? '' ),
			'headline'  => self::text( $news['title'] ?? '' ),
			'publisher' => array( '@id' => rtrim( $site_url, '/' ) . '/#organization' ),
		);
		$summary   = self::text( $news['summary'] ?? '' );
		if ( '' !== $summary ) {
			$node['description'] = $summary;
		}
		$date = self::text( $news['date'] ?? '' );
		if ( '' !== $date ) {
			$node['datePublished'] = $date;
		}
		$modified = self::text( $news['modified'] ?? '' );
		if ( '' !== $modified ) {
			$node['dateModified'] = $modified;
		}
		return $node;
	}

	/**
	 * Builds an Event node.
	 *
	 * @param string               $site_url Canonical site URL.
	 * @param string               $path     Page path.
	 * @param array<string, mixed> $event    Event fields.
	 * @return array<string, mixed>
	 */
	public static function event( string $site_url, string $path, array $event ): array {
		$canonical = SeoPolicy::canonical_url( $site_url, $path );
		$node      = array(
			'@type' => 'Event',
			'@id'   => $canonical . '#event',
			'url'   => $canonical,
			'name'  => self::text( $event['title'] ?? '' ),
		);
		$summary   = self::text( $event['summary'] ?? '' );
		if ( '' !== $summary ) {
			$node['description'] = $summary;
		}
		$starts = self::text( $event['starts_at'] ?? '' );
		if ( '' !== $starts ) {
			$node['startDate'] = $starts;
		}
		$ends = self::text( $event['ends_at'] ?? '' );
		if ( '' !== $ends ) {
			$node['endDate'] = $ends;
		}
		$state = self::text( $event['state'] ?? '' );
		if ( isset( self::EVENT_STATUSES[ $state ] ) ) {
			$node['eventStatus'] = self::EVENT_STATUSES[ $state ];
		}
		$venue  = self::text( $event['venue'] ?? '' );
		$online = self::text( $event['online_url'] ?? '' );
		if ( '' !== $venue ) {
			$node['location'] = array(
				'@type' => 'Place',
				'name'  => $venue,
			);
		}
		if ( '' !== $online ) {
			$node['eventAttendanceMode'] = '' === $venue
				? 'https://schema.org/OnlineEventAttendanceMode'
				: 'https://schema.org/MixedEventAttendanceMode';
		} elseif ( '' !== $venue ) {
			$node['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';
		}
		$node['organizer'] = array( '@id' => rtrim( $site_url, '/' ) . '/#organization' );
		return $node;
	}

	/**
	 * Builds a Course node for one course page.
	 *
	 * Only stored, reviewed fields are emitted: the official code, the
	 * localized title and summary, and the published offering addresses as
	 * `hasCourseInstance` links. An offering that is not publicly visible in
	 * this locale never reaches the list.
	 *
	 * @param string               $site_url  Canonical site URL.
	 * @param string               $path      Page path.
	 * @param array<string, mixed> $course    Course fields.
	 * @return array<string, mixed>
	 */
	public static function course( string $site_url, string $path, array $course ): array {
		$canonical = SeoPolicy::canonical_url( $site_url, $path );
		$node      = array(
			'@type'     => 'Course',
			'@id'       => $canonical . '#course',
			'url'       => $canonical,
			'name'      => self::text( $course['name'] ?? '' ),
			'provider'  => array( '@id' => rtrim( $site_url, '/' ) . '/#organization' ),
			'isPartOf'  => array( '@id' => rtrim( $site_url, '/' ) . '/#website' ),
		);
		$code = self::text( $course['code'] ?? '' );
		if ( '' !== $code ) {
			$node['courseCode'] = $code;
		}
		$summary = self::text( $course['summary'] ?? '' );
		if ( '' !== $summary ) {
			$node['description'] = $summary;
		}
		$language = SeoPolicy::bcp47( self::text( $course['locale'] ?? SeoPolicy::DEFAULT_LOCALE ) );
		if ( '' !== $language ) {
			$node['inLanguage'] = $language;
		}
		$instances = array();
		foreach ( self::strings( $course['instances'] ?? array() ) as $instance ) {
			$instances[] = array(
				'@type' => 'CourseInstance',
				'url'   => $instance,
			);
		}
		if ( array() !== $instances ) {
			$node['hasCourseInstance'] = $instances;
		}
		return $node;
	}

	/**
	 * Builds a CourseInstance node for one offering page.
	 *
	 * The instance links back to its Course node through `isPartOf`, carries
	 * the stored term boundaries and teaching team, and never invents an
	 * attendance mode: a venue string is a Place, an approved LMS handoff is
	 * an online address, and neither is claimed when absent.
	 *
	 * @param string               $site_url Canonical site URL.
	 * @param string               $path     Page path.
	 * @param array<string, mixed> $offering Offering fields.
	 * @return array<string, mixed>
	 */
	public static function course_instance( string $site_url, string $path, array $offering ): array {
		$canonical  = SeoPolicy::canonical_url( $site_url, $path );
		$course_url = self::text( $offering['course_url'] ?? '' );
		$node       = array(
			'@type' => 'CourseInstance',
			'@id'   => $canonical . '#courseinstance',
			'url'   => $canonical,
			'name'  => self::text( $offering['title'] ?? '' ),
		);
		if ( '' !== $course_url ) {
			$node['isPartOf'] = array( '@id' => $course_url . '#course' );
		}
		$summary = self::text( $offering['summary'] ?? '' );
		if ( '' !== $summary ) {
			$node['description'] = $summary;
		}
		$language = SeoPolicy::bcp47( self::text( $offering['locale'] ?? SeoPolicy::DEFAULT_LOCALE ) );
		if ( '' !== $language ) {
			$node['inLanguage'] = $language;
		}
		$starts = self::text( $offering['starts_on'] ?? '' );
		if ( '' !== $starts ) {
			$node['startDate'] = $starts;
		}
		$ends = self::text( $offering['ends_on'] ?? '' );
		if ( '' !== $ends ) {
			$node['endDate'] = $ends;
		}
		$venue = self::text( $offering['venue'] ?? '' );
		$lms   = self::text( $offering['lms_url'] ?? '' );
		if ( '' !== $venue ) {
			$node['location'] = array(
				'@type' => 'Place',
				'name'  => $venue,
			);
		}
		if ( '' !== $lms ) {
			$node['courseMode'] = '' === $venue ? 'online' : 'blended';
		}
		$instructors = array();
		foreach ( self::strings( $offering['instructors'] ?? array() ) as $instructor ) {
			$instructors[] = array(
				'@type' => 'Person',
				'name'  => $instructor,
			);
		}
		if ( array() !== $instructors ) {
			$node['instructor'] = $instructors;
		}
		$node['organizer'] = array( '@id' => rtrim( $site_url, '/' ) . '/#organization' );
		return $node;
	}

	/**
	 * Builds the opportunity node, distinguishing employment from funding.
	 *
	 * @param string               $site_url    Canonical site URL.
	 * @param string               $path        Page path.
	 * @param array<string, mixed> $opportunity Opportunity fields.
	 * @param array<string, mixed> $site        Site identity.
	 * @return array<string, mixed>
	 */
	public static function opportunity( string $site_url, string $path, array $opportunity, array $site ): array {
		unset( $site );
		$canonical     = SeoPolicy::canonical_url( $site_url, $path );
		$type          = self::text( $opportunity['type'] ?? '' );
		$is_employment = in_array( $type, self::EMPLOYMENT_TYPES, true );
		$title         = self::text( $opportunity['title'] ?? '' );
		$summary       = self::text( $opportunity['summary'] ?? '' );
		$opens         = self::text( $opportunity['opens_at'] ?? '' );
		$closes        = self::text( $opportunity['closes_at'] ?? '' );
		$location      = self::text( $opportunity['location'] ?? '' );

		if ( ! $is_employment ) {
			$node = array(
				'@type'                => 'EducationalOccupationalProgram',
				'@id'                  => $canonical . '#program',
				'url'                  => $canonical,
				'name'                 => $title,
				'provider'             => array( '@id' => rtrim( $site_url, '/' ) . '/#organization' ),
				'programPrerequisites' => self::text( $opportunity['eligibility'] ?? '' ),
			);
			if ( '' === $node['programPrerequisites'] ) {
				unset( $node['programPrerequisites'] );
			}
			if ( '' !== $summary ) {
				$node['description'] = $summary;
			}
			if ( '' !== $opens ) {
				$node['applicationStartDate'] = $opens;
			}
			if ( '' !== $closes ) {
				$node['applicationDeadline'] = $closes;
			}
			return $node;
		}

		$node = array(
			'@type'              => 'JobPosting',
			'@id'                => $canonical . '#jobposting',
			'url'                => $canonical,
			'title'              => $title,
			'hiringOrganization' => array( '@id' => rtrim( $site_url, '/' ) . '/#organization' ),
		);
		if ( '' !== $summary ) {
			$node['description'] = $summary;
		}
		if ( '' !== $opens ) {
			$node['datePosted'] = $opens;
		}
		if ( '' !== $closes ) {
			$node['validThrough'] = $closes;
		}
		if ( '' !== $location ) {
			$node['jobLocation'] = array(
				'@type'   => 'Place',
				'address' => $location,
			);
		}
		$candidate = $opportunity['positions'] ?? 0;
		$positions = is_numeric( $candidate ) ? (int) $candidate : 0;
		if ( $positions > 0 ) {
			$node['totalJobOpenings'] = $positions;
		}
		return $node;
	}

	/**
	 * Assembles a deduplicated JSON-LD graph.
	 *
	 * Two nodes can never share one identifier: the first node wins and the
	 * duplicate is dropped so the document stays unambiguous.
	 *
	 * @param array<int, array<string, mixed>> $nodes Candidate nodes.
	 * @return array<string, mixed>
	 */
	public static function graph( array $nodes ): array {
		$graph = array();
		$seen  = array();
		foreach ( $nodes as $node ) {
			if ( array() === $node ) {
				continue;
			}
			$id = is_string( $node['@id'] ?? null ) ? $node['@id'] : '';
			if ( '' !== $id ) {
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
			}
			$graph[] = $node;
		}
		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
	}

	/**
	 * Encodes a graph for safe inline embedding.
	 *
	 * @param array<string, mixed> $graph Assembled graph.
	 */
	public static function encode( array $graph ): string {
		$json = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return '{"@context":"https://schema.org","@graph":[]}';
		}
		return str_replace( array( '<', '>' ), array( '\u003C', '\u003E' ), $json );
	}

	/**
	 * Returns the normalized site origin.
	 *
	 * @param array<string, mixed> $site Site identity.
	 */
	private static function site_url( array $site ): string {
		return rtrim( self::text( $site['site_url'] ?? '' ), '/' );
	}

	/**
	 * Converts a boundary scalar to trimmed text.
	 *
	 * @param mixed $value Boundary value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Converts a boundary list to trimmed non-empty strings.
	 *
	 * @param mixed $value Boundary value.
	 * @return array<int, string>
	 */
	private static function strings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( $value as $item ) {
			$text = self::text( $item );
			if ( '' !== $text ) {
				$result[] = $text;
			}
		}
		return $result;
	}
}
