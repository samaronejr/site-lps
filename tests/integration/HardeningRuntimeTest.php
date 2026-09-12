<?php
/**
 * Real WordPress capability, nonce, escaping, upload and REST regressions.
 *
 * @package LPS\Tests
 */

declare(strict_types=1);

namespace LPS\Tests;

use LPS\ContentModel\Hardening;
use LPS\ContentModel\MediaUploads;
use LPS\ContentModel\MFA;
use LPS\ContentModel\Plugin;
use LPS\ContentModel\Roles;
use LPS\ContentModel\SearchIndex;
use PHPUnit\Framework\TestCase;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class HardeningRuntimeTest extends TestCase {
	private int $user_id;
	private int $post_id;

	protected function setUp(): void {
		wp_set_current_user( 0 );
		$_POST = array();
		$login = 'security-' . wp_generate_uuid4();
		$user  = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 32 ), 'role' => 'lps_contributor', 'user_email' => $login . '@example.test' ) );
		self::assertIsInt( $user );
		$this->user_id = $user;
		update_user_meta( $user, Roles::COLLECTIONS_META, array( 'person' ) );
		$post = wp_insert_post( array( 'post_type' => 'lps_person', 'post_title' => 'Security fixture', 'post_status' => 'draft', 'post_author' => $user ), true );
		self::assertIsInt( $post );
		$this->post_id = $post;
	}

	protected function tearDown(): void {
		$_POST = array();
		wp_set_current_user( 0 );
	}

	public function test_admin_metadata_requires_nonce_and_target_capability_then_sanitizes(): void {
		$post = get_post( $this->post_id );
		self::assertInstanceOf( WP_Post::class, $post );
		wp_set_current_user( $this->user_id );
		$_POST = array( 'lps_meta' => array( '_lps_canonical_name' => '<img src=x onerror=alert(1)>Safe' ) );
		Plugin::save_admin_fields( $post->ID, $post );
		self::assertSame( '', get_post_meta( $post->ID, '_lps_canonical_name', true ) );
		$_POST['_lps_content_model_nonce'] = 'invalid';
		Plugin::save_admin_fields( $post->ID, $post );
		self::assertSame( '', get_post_meta( $post->ID, '_lps_canonical_name', true ) );
		$_POST['_lps_content_model_nonce'] = wp_create_nonce( 'lps_content_model_save' );
		update_user_meta( $this->user_id, Roles::COLLECTIONS_META, array( 'project' ) );
		Plugin::save_admin_fields( $post->ID, $post );
		self::assertSame( '', get_post_meta( $post->ID, '_lps_canonical_name', true ) );
		update_user_meta( $this->user_id, Roles::COLLECTIONS_META, array( 'person' ) );
		Plugin::save_admin_fields( $post->ID, $post );
		self::assertSame( 'Safe', get_post_meta( $post->ID, '_lps_canonical_name', true ) );
	}

	public function test_stored_attribute_xss_is_escaped_in_the_real_admin_panel(): void {
		update_post_meta( $this->post_id, '_lps_canonical_name', '\" autofocus onfocus=alert(1) <script>bad</script>' );
		$post = get_post( $this->post_id );
		self::assertInstanceOf( WP_Post::class, $post );
		ob_start();
		Plugin::render_meta_box( $post );
		$html = ob_get_clean();
		self::assertIsString( $html );
		$dom = new \DOMDocument();
		$dom->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
		$xpath = new \DOMXPath( $dom );
		$hits  = $xpath->query( '//script|//*[@onfocus]|//*[@onerror]' );
		self::assertInstanceOf( \DOMNodeList::class, $hits );
		self::assertSame( 0, $hits->length );
	}

	public function test_native_and_custom_privileged_roles_cannot_bypass_mfa(): void {
		foreach ( array( 'administrator', 'lps_administrator', 'lps_publisher' ) as $role ) {
			$user = new WP_User( $this->user_id );
			$user->set_role( $role );
			wp_set_current_user( 0 );
			wp_set_current_user( $this->user_id );
			update_user_meta( $user->ID, '_two_factor_enabled_providers', array( 'Two_Factor_Totp' ) );
			delete_user_meta( $user->ID, '_two_factor_totp_key' );
			self::assertFalse( MFA::is_enrolled( $user->ID ) );
			foreach ( array( 'upload_files', 'delete_users', 'manage_options', 'publish_pages', 'lps_publish' ) as $cap ) {
				self::assertFalse( current_user_can( $cap ), $role . ':' . $cap );
			}
			self::assertTrue( current_user_can( 'read' ) );
		}
		update_user_meta( $this->user_id, '_two_factor_totp_key', 'JBSWY3DPEHPK3PXP' );
		self::assertTrue( MFA::is_enrolled( $this->user_id ) );
		self::assertTrue( current_user_can( 'lps_publish' ) );
	}

	public function test_upload_adapter_rejects_executable_and_xss_documents(): void {
		foreach ( array( 'shell.php.jpg' => '<?php echo 1;', 'disguised.pdf' => '<script>alert(1)</script>', 'active.pdf' => '%PDF-1.7 /J#61vaScript (alert(1))', 'active.vtt' => "WEBVTT\n<script>alert(1)</script>" ) as $name => $content ) {
			$path = tempnam( sys_get_temp_dir(), 'lps-security-' );
			self::assertIsString( $path );
			file_put_contents( $path, $content );
			try {
				$result = MediaUploads::validate_upload( array( 'name' => $name, 'tmp_name' => $path, 'size' => strlen( $content ), 'error' => 0 ) );
				self::assertIsString( $result['error'], $name );
				self::assertStringStartsWith( '[lps_media_', $result['error'] );
			} finally {
				unlink( $path );
			}
		}
	}

	public function test_public_rest_schema_and_logged_in_view_exclude_private_fields(): void {
		$registered = get_registered_meta_keys( 'post', 'lps_person' );
		self::assertFalse( $registered['_lps_owner_user_id']['show_in_rest'] );
		self::assertFalse( $registered['_lps_translation_reviewer_id']['show_in_rest'] );
		wp_set_current_user( $this->user_id );
		$post = get_post( $this->post_id );
		self::assertInstanceOf( WP_Post::class, $post );
		$response = new WP_REST_Response( array( 'id' => $post->ID, 'author' => $this->user_id, 'meta' => array( '_lps_owner_user_id' => 111, '_lps_translation_reviewer_id' => 222, '_lps_public_email' => 'LPS_PRIVATE_SENTINEL@example.test', '_lps_record_id' => 'public-id' ) ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/people/' . $post->ID );
		$request->set_param( 'context', 'view' );
		$result = Hardening::public_rest( $response, $post, $request )->get_data();
		self::assertSame( array( 'id' => $post->ID, 'meta' => array( '_lps_record_id' => 'public-id' ) ), $result );
		self::assertFalse( wp_is_application_passwords_available() );
		self::assertFalse( apply_filters( 'xmlrpc_enabled', true ) );
		self::assertSame( array(), apply_filters( 'xmlrpc_methods', array( 'pingback.ping' => 'fake' ) ) );
	}

	public function test_rest_write_permissions_and_external_http_are_real_boundaries(): void {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/wp/v2/people' );
		$request->set_body_params( array( 'title' => 'forbidden', 'status' => 'draft' ) );
		self::assertContains( rest_do_request( $request )->get_status(), array( 401, 403 ) );
		self::assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users' ) )->get_status() );
		$result = wp_remote_get( 'https://tracker.invalid/pixel' );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'lps_external_runtime_request_denied', $result->get_error_code() );
		self::assertSame( array(), Hardening::allowed_plugins( array( 'tracker/tracker.php' ) ) );
	}

	public function test_login_and_admin_surfaces_keep_the_policy_headers_over_core_defaults(): void {
		self::assertSame( PHP_INT_MAX, has_action( 'login_init', array( Hardening::class, 'send_headers' ) ) );
		self::assertSame( PHP_INT_MAX, has_action( 'admin_init', array( Hardening::class, 'send_headers' ) ) );
		$policy = Hardening::headers( false, false, 'fixture' );
		self::assertSame( 'DENY', $policy['X-Frame-Options'] );
		self::assertSame( 'no-referrer', $policy['Referrer-Policy'] );
		self::assertStringContainsString( "script-src 'self' 'nonce-fixture'", $policy['Content-Security-Policy'] );
	}

	public function test_private_fields_cannot_enter_the_search_row(): void {
		$row = SearchIndex::build_row( array( 'post_id' => $this->post_id, 'locale' => 'pt-br', 'post_type' => 'lps_person', 'title' => 'Public name', '_lps_private_notes' => 'LPS_PRIVATE_SENTINEL', '_lps_owner_user_id' => 'LPS_PRIVATE_SENTINEL', 'actor_user_id' => 'LPS_PRIVATE_SENTINEL' ) );
		self::assertStringNotContainsString( 'LPS_PRIVATE_SENTINEL', (string) wp_json_encode( $row ) );
	}

	public function test_unprovisioned_non_production_install_activates_while_declared_production_still_fails_closed(): void {
		self::assertSame( 'local', wp_get_environment_type() );
		self::assertFalse( Hardening::provisioning_ready(), 'The local fixture is deliberately unprovisioned.' );
		self::assertFalse( Hardening::enforces_production( Hardening::declared_environment() ) );
		$terminated = array();
		$record     = static function ( callable $handler ) use ( &$terminated ): callable {
			$terminated[] = 'wp_die';
			return $handler;
		};
		add_filter( 'wp_die_handler', $record );
		Hardening::production_gate();
		remove_filter( 'wp_die_handler', $record );
		self::assertSame( array(), $terminated, 'The gate must not terminate an unprovisioned non-production request.' );
		self::assertTrue( Hardening::enforces_production( 'production' ), 'A declared production environment still enforces provisioning.' );
	}
}
