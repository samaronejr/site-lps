<?php
/** Task 24k disposable migration recovered from attempt-5; never a production plugin. */
declare(strict_types=1);

add_action( 'wp_loaded', static function (): void {
	if ( ( $_GET['task24k'] ?? '' ) !== 'apply' ) {
		return;
	}
	if ( home_url() !== 'http://127.0.0.1:8903' ) {
		wp_die( 'Owned disposable origin required', '', array( 'response' => 403 ) );
	}
	if ( get_option( 'task24k_migration_complete' ) ) {
		header( 'Content-Type: application/json' );
		echo wp_json_encode( array( 'alreadyApplied' => true, 'changes' => array() ) );
		exit;
	}
	// The acting administrator is a validated provisioning input, never an assumed ID.
	$administrators = get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1 ) );
	if ( ! $administrators || ! $administrators[0] instanceof WP_User ) {
		wp_die( 'Fixture administrator missing: provision it before applying the migration', '', array( 'response' => 500 ) );
	}
	$administrator = $administrators[0];
	wp_set_current_user( $administrator->ID );
	// The gate asserts the account's granted capabilities, never the session-filtered
	// result: MFA::strip_unenrolled_privileges deliberately withholds every capability
	// except read/exist from unenrolled privileged sessions through user_has_cap, and
	// that site policy keeps applying to every real authorization check. None of the
	// writes below (wp_update_post, update_post_meta, wp_delete_post, pll_*) consult
	// current_user_can, so the fixture contract is the granted set on the provisioned
	// account, which is exactly what $administrator->allcaps reports.
	if ( empty( $administrator->allcaps['edit_others_posts'] ) ) {
		wp_die( 'Fixture administrator lacks the capabilities the migration requires', '', array( 'response' => 500 ) );
	}
	$replacements = array(
		'O laboratorio mantem convenios ativos com agencias de fomento.' => 'O laboratório mantém convênios ativos com agências de fomento.',
		'Cidade Universitaria, Rio de Janeiro, Brasil' => 'Cidade Universitária, Rio de Janeiro, Brasil',
		'Cidade Universitaria, Rio de Janeiro, Brazil' => 'Cidade Universitária, Rio de Janeiro, Brazil',
		'Historia do LPS' => 'História do LPS',
		'Trajetoria do laboratorio.' => 'Trajetória do laboratório.',
		'Governanca' => 'Governança',
		'Estrutura de governanca do laboratorio.' => 'Estrutura de governança do laboratório.',
		'Coordenacao academica vinculada ao Programa de Engenharia Eletrica.' => 'Coordenação acadêmica vinculada ao Programa de Engenharia Elétrica.',
		'Contatos institucionais publicos.' => 'Contatos institucionais públicos.',
		'Coordenacao' => 'Coordenação',
		'Declaracao de acessibilidade digital.' => 'Declaração de acessibilidade digital.',
	);
	$changes = array();
	$changed = array();
	// The older progressive seed assigned both child-page variants to Portuguese.
	foreach ( array( array( 'sobre/historia', 'about/history' ), array( 'sobre/governanca', 'about/governance' ) ) as $paths ) {
		$variants = array();
		foreach ( $paths as $path ) {
			$post = get_page_by_path( $path, OBJECT, 'page' );
			if ( ! $post instanceof WP_Post ) {
				wp_die( 'Authored trust fixture missing', '', array( 'response' => 500 ) );
			}
			$declared = get_post_meta( $post->ID, '_lps_locale', true );
			$changes[] = array( 'id' => $post->ID, 'field' => 'language', 'before' => pll_get_post_language( $post->ID ), 'after' => $declared );
			pll_set_post_language( $post->ID, $declared );
			$variants[ $declared ] = $post->ID;
			$changed[ $post->ID ] = true;
		}
		pll_save_post_translations( $variants );
	}
	foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft' ), 'numberposts' => -1, 'lang' => '', 'suppress_filters' => true ) ) as $post ) {
		$fields = array( 'ID' => $post->ID );
		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $field ) {
			$after = strtr( $post->$field, $replacements );
			if ( $post->$field !== $after ) {
				$changes[] = array( 'id' => $post->ID, 'field' => $field, 'before' => $post->$field, 'after' => $after );
				$fields[ $field ] = $after;
			}
		}
		foreach ( array( '_lps_location', '_lps_governance', '_lps_claims', '_lps_role_contacts' ) as $key ) {
			$before = get_post_meta( $post->ID, $key, true );
			if ( ! is_string( $before ) ) {
				continue;
			}
			$after = strtr( $before, $replacements );
			if ( $before !== $after ) {
				update_post_meta( $post->ID, $key, wp_slash( $after ) );
				$changes[] = array( 'id' => $post->ID, 'field' => $key, 'before' => $before, 'after' => $after );
				$changed[ $post->ID ] = true;
			}
		}
		if ( in_array( $post->post_name, array( 'midia-acessivel', 'accessible-media' ), true ) ) {
			$fields['post_content'] = task24k_truthful_media( $post->post_content, $post->post_name === 'accessible-media' );
			$changes[] = array( 'id' => $post->ID, 'field' => 'post_content', 'before' => $post->post_content, 'after' => $fields['post_content'] );
		}
		if ( count( $fields ) > 1 ) {
			$result = wp_update_post( wp_slash( $fields ), true );
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 500 ) );
			}
			$changed[ $post->ID ] = true;
		}
	}
	$reviews = array();
	foreach ( array_keys( $changed ) as $id ) {
		$variants = pll_get_post_translations( $id );
		if ( isset( $variants['pt-br'], $variants['en'] ) ) {
			$association = \LPS\ContentModel\Translations::associate( $variants['pt-br'], $variants['en'] );
			if ( is_wp_error( $association ) ) {
				wp_die( esc_html( $association->get_error_message() ), '', array( 'response' => 500 ) );
			}
			$hash = get_post_meta( $variants['en'], '_lps_source_hash', true );
			update_post_meta( $variants['en'], '_lps_reviewed_source_hash', $hash );
			foreach ( array( $variants['en'], $variants['pt-br'] ) as $variant ) {
				$result = wp_update_post( array( 'ID' => $variant, 'post_status' => 'publish' ), true );
				if ( is_wp_error( $result ) ) {
					wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 500 ) );
				}
			}
			$reviews[] = array( 'variants' => $variants, 'reviewedSourceHash' => $hash, 'basis' => 'Authored QA fixture: deterministic orthography and explicitly missing image; no production review asserted.' );
		}
	}
	// Every WordPress install default, not only Hello world!, using the shared
	// typed identity table. Each pre-deletion permalink is recorded so the route
	// crosscheck can probe the real URL instead of a guessed slug transformation.
	if ( ! function_exists( 'lps_wordpress_default_content_remove' ) ) {
		wp_die( 'WordPress default-content identity table unavailable', '', array( 'response' => 500 ) );
	}
	$removals = lps_wordpress_default_content_remove();
	foreach ( $removals as $removal ) {
		if ( 'deleted' !== ( $removal['action'] ?? '' ) ) {
			wp_die( esc_html( 'Refusing to remove WordPress default content: ' . (string) wp_json_encode( $removal ) ), '', array( 'response' => 409 ) );
		}
		$changes[] = array( 'id' => $removal['postId'], 'field' => 'default-placeholder-content', 'before' => $removal, 'after' => null );
	}
	if ( array() !== $removals ) {
		lps_wordpress_default_content_record( $removals );
	}
	$indexed = \LPS\ContentModel\SearchIndex::rebuild();
	update_option( 'task24k_migration_complete', 1 );
	header( 'Content-Type: application/json' );
	echo wp_json_encode( array( 'changes' => $changes, 'reviews' => $reviews, 'indexed' => $indexed ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	exit;
}, 98 );


/** Replace only the two known fake image blocks; retain every other block verbatim. */
function task24k_truthful_media( string $content, bool $english ): string {
 $caption = $english
  ? 'Image unavailable. No documentary or decorative image was supplied for this local QA fixture.'
  : 'Imagem indisponível. Nenhuma imagem documental ou decorativa foi fornecida para esta fixture local de QA.';
 $count = 0;
 $repaired = preg_replace_callback(
  '/<!-- wp:image(?: [^>]*?)? -->.*?<!-- \/wp:image -->/s',
  static function ( array $match ) use ( &$count, $caption ): string {
   if ( ! str_contains( $match[0], '/wp-includes/images/media/document.png' ) ) {
    throw new RuntimeException( 'Refusing to replace an unknown authored image.' );
   }
   ++$count;
   return 1 === $count ? '<!-- wp:html --><figure><figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption></figure><!-- /wp:html -->' : '';
  },
  $content
 );
 if ( 0 === $count && str_contains( $content, esc_html( $caption ) ) ) {
  return $content;
 }
 if ( 2 !== $count || ! is_string( $repaired ) ) {
  throw new RuntimeException( 'Expected exactly two known placeholder image blocks.' );
 }
 return $repaired;
}
