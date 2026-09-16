<?php
/**
 * People, organization, and infrastructure route contracts.
 *
 * @package LPS\Theme\Tests
 */

declare(strict_types=1);

namespace LPS\Theme\Tests;

use LPS\Theme\PublicRoutes;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname( __DIR__ ) . '/includes/class-publicroutes.php';

/** Contract tests for the public people, organization, and infrastructure routes. */
final class PublicRoutesTest extends \PHPUnit\Framework\TestCase {
	/**
	 * Provides the frozen locale route for each public record type.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function locale_routes(): array {
		return array(
			'people pt-br'         => array( 'lps_person', 'pt-br', '/pt-br/pessoas/' ),
			'people en'            => array( 'lps_person', 'en', '/en/people/' ),
			'organizations pt-br'  => array( 'lps_organization', 'pt-br', '/pt-br/organizacoes/' ),
			'organizations en'     => array( 'lps_organization', 'en', '/en/organizations/' ),
			'infrastructure pt-br' => array( 'lps_infrastructure', 'pt-br', '/pt-br/infraestrutura/' ),
			'infrastructure en'    => array( 'lps_infrastructure', 'en', '/en/infrastructure/' ),
		);
	}

	/**
	 * Every public record type has a frozen route in both locales.
	 *
	 * @param string $post_type Data-provider value.
	 * @param string $locale    Data-provider value.
	 * @param string $path      Data-provider value.
	 */
	#[DataProvider( 'locale_routes' )]
	public function test_every_public_type_has_a_frozen_route_in_both_locales( string $post_type, string $locale, string $path ): void {
		self::assertSame( $path, PublicRoutes::archive_path( $post_type, $locale ) );
		self::assertSame( $path . 'um-registro/', PublicRoutes::single_path( $post_type, $locale, 'um-registro' ) );
	}

	/**
	 * Public paths resolve back to record type, locale, and slug.
	 *
	 * @param string $post_type Data-provider value.
	 * @param string $locale    Data-provider value.
	 * @param string $path      Data-provider value.
	 */
	#[DataProvider( 'locale_routes' )]
	public function test_public_paths_resolve_back_to_type_locale_and_slug( string $post_type, string $locale, string $path ): void {
		$archive = PublicRoutes::match_path( $path );
		self::assertIsArray( $archive );
		self::assertSame( $post_type, $archive['post_type'] );
		self::assertSame( $locale, $archive['locale'] );
		self::assertSame( '', $archive['slug'] );

		$single = PublicRoutes::match_path( $path . 'ana-alvares/' );
		self::assertIsArray( $single );
		self::assertSame( 'ana-alvares', $single['slug'] );
	}

	/** Unknown paths do not match a public route. */
	public function test_unknown_paths_do_not_match_a_public_route(): void {
		self::assertNull( PublicRoutes::match_path( '/pt-br/publicacoes/' ) );
		self::assertNull( PublicRoutes::match_path( '/fr/people/' ) );
		self::assertNull( PublicRoutes::match_path( '/en/' ) );
	}

	/** Route locales are declared as BCP47 language tags. */
	public function test_route_locales_are_declared_as_bcp47_language_tags(): void {
		self::assertSame( 'pt-BR', PublicRoutes::bcp47( 'pt-br' ) );
		self::assertSame( 'en', PublicRoutes::bcp47( 'en' ) );
	}

	/** Rewrite rules cover archive, paged, and single requests. */
	public function test_rewrite_rules_cover_archive_paged_and_single_requests(): void {
		$rules = PublicRoutes::rewrite_rules();

		self::assertNotSame( array(), $rules );
		foreach ( $rules as $pattern => $query ) {
			self::assertStringStartsWith( 'index.php?', $query );
			self::assertStringContainsString( 'lps_public_locale=', $query );
			self::assertSame( 1, preg_match( '/^[^^].*\$$/', $pattern ), 'Rewrite patterns are anchored at the end.' );
		}
		$targets = array_values( $rules );
		self::assertNotSame(
			array(),
			array_filter( $targets, static fn( string $query ): bool => str_contains( $query, 'paged=' ) ),
			'A paged archive rule exists.'
		);
		self::assertNotSame(
			array(),
			array_filter( $targets, static fn( string $query ): bool => str_contains( $query, 'name=' ) ),
			'A single-record rule exists.'
		);
	}

	/** Public documents declare the route language tag. */
	public function test_public_documents_declare_the_route_language_tag(): void {
		self::assertSame(
			'lang="en" dir="ltr"',
			PublicRoutes::language_attributes_for_path( 'lang="en-US" dir="ltr"', '/en/people/ana/' )
		);
		self::assertSame(
			'lang="pt-BR"',
			PublicRoutes::language_attributes_for_path( 'lang="en-US"', '/pt-br/infraestrutura/' )
		);
		self::assertSame(
			'lang="en-US"',
			PublicRoutes::language_attributes_for_path( 'lang="en-US"', '/en/publications/paper/' )
		);
	}

	/**
	 * Returns the stored metadata of a reviewed active person.
	 *
	 * @return array<string, mixed>
	 */
	private function reviewed_meta(): array {
		return array(
			'_lps_canonical_name'    => 'Ana Álvares',
			'_lps_person_status'     => 'active',
			'_lps_roles'             => array( 'student', 'researcher' ),
			'_lps_research_area_ids' => array( 'signal-processing' ),
			'_lps_public_email'      => 'ana.publica@example.org',
			'_lps_private_email'     => 'ana.privada@example.org',
			'_lps_phone'             => '+55 21 0000-0000',
			'_lps_home_address'      => 'Rua Privada 10',
			'_lps_privacy_reviewed'  => true,
			'_lps_photo_url'         => '/uploads/ana.jpg',
			'_lps_photo_rights'      => 'cleared',
			'_lps_orcid'             => '0000-0002-1825-0097',
		);
	}

	/** A reviewed person publishes only the approved contact and the rights-cleared photo. */
	public function test_person_record_publishes_only_reviewed_contact_and_cleared_photo(): void {
		$record = PublicRoutes::person_record( 'ana-alvares', 'Ana Álvares', $this->reviewed_meta() );

		self::assertTrue( $record['published'] );
		self::assertSame( 'ana.publica@example.org', $record['public_email'] );
		self::assertSame( '/uploads/ana.jpg', $record['photo_url'] );
		self::assertSame( array( 'student', 'researcher' ), $record['roles'] );

		$encoded = (string) wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		self::assertStringNotContainsString( 'ana.privada@example.org', $encoded );
		self::assertStringNotContainsString( 'Rua Privada 10', $encoded );
		self::assertStringNotContainsString( '+55 21 0000-0000', $encoded );
		foreach ( array_keys( $record ) as $key ) {
			self::assertStringStartsNotWith( '_lps_', (string) $key, 'Public records carry no raw storage keys.' );
		}
	}

	/** An unreviewed person publishes neither the stored email nor the photo. */
	public function test_person_record_withholds_contact_without_privacy_review(): void {
		$meta                          = $this->reviewed_meta();
		$meta['_lps_privacy_reviewed'] = false;
		$record                        = PublicRoutes::person_record( 'ana-alvares', 'Ana Álvares', $meta );

		self::assertTrue( $record['published'] );
		self::assertSame( '', $record['public_email'] );
		self::assertSame( '', $record['photo_url'] );
	}

	/** An uncleared photo is never published even after a privacy review. */
	public function test_person_record_withholds_photo_without_cleared_rights(): void {
		$meta                      = $this->reviewed_meta();
		$meta['_lps_photo_rights'] = 'unknown';
		$record                    = PublicRoutes::person_record( 'ana-alvares', 'Ana Álvares', $meta );

		self::assertSame( '', $record['photo_url'] );
		self::assertSame( 'ana.publica@example.org', $record['public_email'] );
	}

	/** An in-memoriam record stays unpublished until the family approval is recorded. */
	public function test_person_record_requires_approval_for_in_memoriam(): void {
		$meta                       = $this->reviewed_meta();
		$meta['_lps_person_status'] = 'in-memoriam';

		$pending = PublicRoutes::person_record( 'ana-alvares', 'Ana Álvares', $meta );
		self::assertFalse( $pending['published'] );

		$meta['_lps_in_memoriam_approved'] = true;
		$approved                          = PublicRoutes::person_record( 'ana-alvares', 'Ana Álvares', $meta );
		self::assertTrue( $approved['published'] );
		self::assertSame( 'in-memoriam', $approved['status'] );
	}

	/** A departed person keeps a published profile and its historical links. */
	public function test_person_record_keeps_history_after_departure(): void {
		$meta                       = $this->reviewed_meta();
		$meta['_lps_person_status'] = 'alumni';
		$meta['_lps_end_date']      = '2023-12-31';
		$history                    = array(
			array(
				'title' => 'Projeto Atlas',
				'url'   => '/pt-br/projetos/atlas/',
			),
		);

		$record = PublicRoutes::person_record( 'ana-alvares', 'Ana Álvares', $meta, $history );

		self::assertTrue( $record['published'] );
		self::assertSame( 'alumni', $record['status'] );
		self::assertSame( '2023-12-31', $record['end_date'] );
		self::assertSame( $history, $record['history'] );
	}

	/** An archived person is withheld from public surfaces. */
	public function test_person_record_withholds_archived_records(): void {
		$meta               = $this->reviewed_meta();
		$meta['_lps_state'] = 'archived';

		self::assertFalse( PublicRoutes::person_record( 'ana-alvares', 'Ana Álvares', $meta )['published'] );
	}

	/** Organizations without a public profile publish nothing at all. */
	public function test_organization_record_hides_non_public_profiles(): void {
		$hidden = PublicRoutes::organization_record(
			'parceiro-oculto',
			'Parceiro Oculto',
			array(
				'_lps_organization_name' => 'Parceiro Oculto',
				'_lps_organization_kind' => 'partner',
				'_lps_public_profile'    => false,
				'_lps_logo_url'          => '/uploads/oculto.svg',
				'_lps_canonical_url'     => 'https://oculto.example/',
			)
		);
		self::assertFalse( $hidden['public_profile'] );
		self::assertStringNotContainsString( 'oculto.example', (string) wp_json_encode( $hidden, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

		$visible = PublicRoutes::organization_record(
			'parceiro-publico',
			'Parceiro Público',
			array(
				'_lps_organization_name' => 'Parceiro Público',
				'_lps_organization_kind' => 'partner',
				'_lps_public_profile'    => true,
				'_lps_logo_url'          => '/uploads/parceiro.svg',
				'_lps_logo_rights'       => 'unknown',
				'_lps_canonical_url'     => 'https://parceiro.example/',
			)
		);
		self::assertTrue( $visible['public_profile'] );
		self::assertSame( 'https://parceiro.example/', $visible['canonical_url'] );
		self::assertSame( '', $visible['logo_url'], 'A logo without cleared rights is never published.' );
	}

	/** Infrastructure capability claims survive only with a source and a review date. */
	public function test_facility_record_keeps_only_sourced_claims(): void {
		$record = PublicRoutes::facility_record(
			'laboratorio-de-sinais',
			'Laboratório de sinais',
			array(
				'_lps_capability_claims' => array(
					array(
						'text'        => 'Suporta experimentos reprodutíveis.',
						'source_url'  => 'https://coppe.ufrj.br/fonte',
						'reviewed_at' => '2026-08-20',
					),
					array(
						'text'       => 'O laboratório mais rápido do Brasil.',
						'source_url' => '',
					),
				),
			)
		);

		self::assertIsArray( $record['claims'] );
		self::assertCount( 1, $record['claims'] );
		self::assertIsArray( $record['claims'][0] );
		self::assertSame( 'Suporta experimentos reprodutíveis.', $record['claims'][0]['text'] );
		self::assertStringNotContainsString( 'mais rápido', (string) wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/** English variants inherit the Portuguese authority fields they cannot own. */
	public function test_english_variants_inherit_portuguese_authority_fields(): void {
		// Given: an English variant that only stores its localized values.
		$authority = array(
			'_lps_canonical_name'    => 'Ana Álvares',
			'_lps_roles'             => array( 'professor', 'researcher' ),
			'_lps_person_status'     => 'alumni',
			'_lps_end_date'          => '2023-12-31',
			'_lps_privacy_reviewed'  => '1',
			'_lps_photo_rights'      => 'cleared',
			'_lps_photo_url'         => '/wp-content/uploads/ana.jpg',
			'_lps_photo_alt'         => 'Retrato em português.',
			'_lps_research_area_ids' => array( 'signal-processing' ),
			'_lps_state'             => 'published',
		);
		$local     = array(
			'_lps_locale'    => 'en',
			'_lps_photo_alt' => 'Portrait in English.',
		);

		// When: the public record merges the authority fields.
		$merged = PublicRoutes::with_authority_meta( 'lps_person', $local, $authority );

		// Then: language-neutral authority values arrive and localized ones stay local.
		self::assertSame( array( 'professor', 'researcher' ), $merged['_lps_roles'] );
		self::assertSame( 'alumni', $merged['_lps_person_status'] );
		self::assertSame( '2023-12-31', $merged['_lps_end_date'] );
		self::assertSame( 'cleared', $merged['_lps_photo_rights'] );
		self::assertSame( '/wp-content/uploads/ana.jpg', $merged['_lps_photo_url'] );
		self::assertSame( array( 'signal-processing' ), $merged['_lps_research_area_ids'] );
		self::assertSame( 'published', $merged['_lps_state'] );
		self::assertSame( 'en', $merged['_lps_locale'] );
		self::assertSame( 'Portrait in English.', $merged['_lps_photo_alt'] );
	}

	/** A withheld authority state is inherited instead of being lost in translation. */
	public function test_english_variant_inherits_withheld_authority_state(): void {
		// Given: an archived Portuguese authority record.
		$authority = array(
			'_lps_canonical_name' => 'Pessoa Arquivada',
			'_lps_state'          => 'archived',
			'_lps_person_status'  => 'active',
		);

		// When: the English variant merges it.
		$merged = PublicRoutes::with_authority_meta( 'lps_person', array( '_lps_locale' => 'en' ), $authority );
		$record = PublicRoutes::person_record( 'archived-en', 'Archived person', $merged );

		// Then: the English surface withholds the record exactly like the authority.
		self::assertFalse( $record['published'] );
	}

	/** A hidden organization stays hidden on the English surface too. */
	public function test_english_organization_inherits_hidden_public_profile(): void {
		// Given: a Portuguese authority organization that is not publishable.
		$authority = array(
			'_lps_organization_name' => 'Parceiro Oculto',
			'_lps_public_profile'    => '0',
		);

		// When: the English variant merges the authority fields.
		$merged = PublicRoutes::with_authority_meta( 'lps_organization', array( '_lps_locale' => 'en' ), $authority );
		$record = PublicRoutes::organization_record( 'hidden-en', 'Hidden partner', $merged );

		// Then: the English record refuses a public profile as well.
		self::assertFalse( $record['public_profile'] );
	}

	/** Only a publishable record may answer at its own address. */
	public function test_only_publishable_records_are_addressable(): void {
		// Given: one publishable person and one withheld person.
		$people = array(
			array(
				'slug'      => 'pessoa-publicada',
				'published' => true,
			),
			array(
				'slug'      => 'pessoa-sem-consentimento',
				'published' => false,
			),
		);

		// When: each address is checked.
		// Then: the withheld record and unknown slugs are not addressable.
		self::assertTrue( PublicRoutes::is_addressable( 'lps_person', $people, 'pessoa-publicada' ) );
		self::assertFalse( PublicRoutes::is_addressable( 'lps_person', $people, 'pessoa-sem-consentimento' ) );
		self::assertFalse( PublicRoutes::is_addressable( 'lps_person', $people, 'inexistente' ) );
	}

	/** A hidden organization profile is not addressable either. */
	public function test_hidden_organizations_are_not_addressable(): void {
		$organizations = array(
			array(
				'slug'           => 'parceiro-publico',
				'public_profile' => true,
			),
			array(
				'slug'           => 'parceiro-oculto',
				'public_profile' => false,
			),
		);

		self::assertTrue( PublicRoutes::is_addressable( 'lps_organization', $organizations, 'parceiro-publico' ) );
		self::assertFalse( PublicRoutes::is_addressable( 'lps_organization', $organizations, 'parceiro-oculto' ) );
	}

	/** A listing address stays addressable even when it lists nothing. */
	public function test_listing_addresses_stay_addressable(): void {
		self::assertTrue( PublicRoutes::is_addressable( 'lps_person', array(), '' ) );
	}
}
