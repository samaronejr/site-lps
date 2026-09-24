<?php
/**
 * Renderers for the authenticated faculty task dashboard.
 *
 * The dashboard follows the Operate mode of the design contract
 * (DESIGN.md §7.4): the highest-density surface, a compact masthead, a task
 * list instead of marketing navigation, structured fields with
 * context-aware defaults, and a locked presentation — no layout choices,
 * no free-form page building. Every control the surface renders is one the
 * server will accept: publish, release, review and copy controls appear
 * only when the account's persisted grants and role allow them, and every
 * form posts to a nonce-protected `admin-post.php` handler that re-checks
 * the boundary.
 *
 * Every renderer returns escaped markup only; the model is assembled by
 * `TaskDashboard::model_for_user` from persisted state. Escaping helpers
 * are internal so the renderers run in unit tests without WordPress loaded.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

use LPS\ContentModel\Policy;
use LPS\ContentModel\TaskDashboard;
use LPS\ContentModel\TeachingContracts;
use WP_User;

if ( ! class_exists( TaskDashboard::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/plugins/lps-content-model/includes/class-taskdashboard.php';
}

if ( ! class_exists( SeoSurfaces::class ) ) {
	require_once __DIR__ . '/class-seosurfaces.php';
}

/** Renders the dashboard document and its task views. */
final class DashboardSurfaces {
	/**
	 * Renders the complete HTML document for one dashboard view.
	 *
	 * @param string  $view   View key from `DashboardRoutes::match_path`.
	 * @param string  $locale Supported locale slug.
	 * @param WP_User $user   Signed-in account.
	 * @param int     $id     Offering record ID for the workspace view.
	 */
	public static function document( string $view, string $locale, WP_User $user, int $id = 0 ): string {
		$model   = TaskDashboard::model_for_user( $user, $locale );
		$english = 'en' === $locale;
		$title   = self::view_title( $view, $locale );
		$body    = self::view( $view, $model, $locale, $id );
		$header  = Shell::header_markup( $locale, DashboardRoutes::dashboard_path( $locale ) );
		$footer  = Shell::footer_markup( $locale );
		$css     = function_exists( 'get_theme_file_uri' ) ? get_theme_file_uri( 'assets/css/theme.css' ) : '';
		$version = function_exists( 'wp_get_theme' ) ? (string) wp_get_theme()->get( 'Version' ) : '';
		$css_url = '' !== $css ? $css . ( '' !== $version ? '?ver=' . rawurlencode( $version ) : '' ) : '';
		$html    = '<!DOCTYPE html><html lang="' . self::esc( DashboardRoutes::bcp47( $locale ) ) . '"><head>'
			. '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<title>' . self::esc( $title . ' — LPS' ) . '</title>'
			. SeoSurfaces::icon_links()
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Standalone dashboard document outside wp_head; the theme stylesheet is emitted directly.
			. ( '' !== $css_url ? '<link rel="stylesheet" href="' . self::esc( $css_url ) . '">' : '' )
			. '</head><body class="lps-dashboard">'
			. $header
			. '<main id="lps-main" class="lps-main-content lps-page-grid lps-dashboard-main">'
			. '<h1 class="lps-page-title">' . self::esc( $title ) . '</h1>'
			. self::notice_html( $locale )
			. $body
			. '</main>'
			. $footer
			. '</body></html>';
		return $html;
	}

	/**
	 * Renders one dashboard view body.
	 *
	 * @param string               $view   View key.
	 * @param array<string, mixed> $model  Dashboard model.
	 * @param string               $locale Supported locale slug.
	 * @param int                  $id     Offering record ID for the workspace view.
	 */
	public static function view( string $view, array $model, string $locale, int $id = 0 ): string {
		return match ( $view ) {
			'offering' => self::offering_view( $model, $locale, $id ),
			'news'     => self::news_view( $model, $locale ),
			'profile'  => self::profile_view( $model, $locale ),
			'review'   => self::review_view( $model, $locale ),
			'create'   => self::create_view( $model, $locale ),
			'course'   => self::course_view( $model, $locale ),
			default    => self::home_view( $model, $locale ),
		};
	}

	/**
	 * Renders the dashboard home: the task list and the scoped work queues.
	 *
	 * The empty state is explicit: an account with no offerings sees the task
	 * list and an assignment notice, never a blank page or a dead control.
	 *
	 * @param array<string, mixed> $model  Dashboard model.
	 * @param string               $locale Supported locale slug.
	 */
	public static function home_view( array $model, string $locale ): string {
		$english = 'en' === $locale;
		$html    = '<section class="lps-dashboard-home" data-dashboard-view="home">';
		$html   .= '<nav class="lps-dashboard-tasks" aria-label="' . self::esc( $english ? 'Dashboard tasks' : 'Tarefas do painel' ) . '"><ul class="lps-task-list">';
		foreach ( self::task_links( $model, $locale ) as $task ) {
			$html .= '<li><a class="lps-task-link" href="' . self::esc( $task['url'] ) . '">' . self::esc( $task['label'] ) . '</a><p class="lps-field-hint">' . self::esc( $task['hint'] ) . '</p></li>';
		}
		$html     .= '</ul></nav>';
		$offerings = self::records( $model['offerings'] ?? null );
		if ( array() === $offerings ) {
			$html .= '<div class="lps-alert lps-alert-info" data-dashboard-empty="offerings"><p>'
				. self::esc(
					$english
					? 'No offering is assigned to your account yet. When an editor assigns one, it appears here with its units, materials and copy-forward task.'
					: 'Nenhuma oferta está atribuída à sua conta ainda. Quando um editor atribuir uma, ela aparece aqui com suas unidades, materiais e a tarefa de cópia.'
				)
				. '</p></div>';
		} else {
			$html .= '<section class="lps-dashboard-section" aria-labelledby="lps-dash-offerings"><h2 id="lps-dash-offerings">'
				. self::esc( $english ? 'My offerings' : 'Minhas ofertas' ) . '</h2><ul class="lps-record-list">';
			foreach ( $offerings as $offering ) {
				$html .= '<li class="lps-record">'
					. '<a href="' . self::esc( DashboardRoutes::view_path( 'offering', $locale, Policy::sanitize_integer( $offering['id'] ?? 0 ) ) ) . '">' . self::esc( self::text( $offering['title'] ?? '' ) ) . '</a>'
					. ' ' . self::chip( self::text( $offering['state'] ?? 'draft' ), $locale )
					. self::identity_line( $offering )
					. '</li>';
			}
			$html .= '</ul></section>';
		}
		$news = is_array( $model['news'] ?? null ) ? $model['news'] : array();
		if ( array() !== $news ) {
			$html .= '<section class="lps-dashboard-section" aria-labelledby="lps-dash-news"><h2 id="lps-dash-news">'
				. self::esc( $english ? 'My news submissions' : 'Minhas notícias' ) . '</h2><ul class="lps-record-list">';
			foreach ( $news as $item ) {
				$item  = self::record( $item );
				$html .= '<li class="lps-record">' . self::esc( self::text( $item['title'] ?? '' ) )
					. ' ' . self::chip( self::text( $item['state'] ?? 'draft' ), $locale )
					. self::item_links( $item, $locale )
					. self::note_html( $item, $locale )
					. '</li>';
			}
			$html .= '</ul></section>';
		}
		return $html . '</section>';
	}

	/**
	 * Renders one offering workspace: units, materials, and the copy task.
	 *
	 * @param array<string, mixed> $model  Dashboard model.
	 * @param string               $locale Supported locale slug.
	 * @param int                  $id     Offering record ID.
	 */
	public static function offering_view( array $model, string $locale, int $id ): string {
		$english  = 'en' === $locale;
		$offering = null;
		foreach ( self::records( $model['offerings'] ?? null ) as $candidate ) {
			if ( Policy::sanitize_integer( $candidate['id'] ?? 0 ) === $id ) {
				$offering = $candidate;
				break;
			}
		}
		if ( null === $offering ) {
			return '<div class="lps-alert lps-alert-error" data-dashboard-view="offering-denied"><p>'
				. self::esc( $english ? 'This offering is outside your assigned scope.' : 'Esta oferta está fora do seu escopo atribuído.' )
				. '</p></div>';
		}
		$html  = '<section class="lps-dashboard-offering" data-dashboard-view="offering" data-offering-id="' . (int) $id . '">';
		$html .= '<p class="lps-kicker">' . self::esc( $english ? 'Offering workspace' : 'Área da oferta' ) . '</p>';
		$html .= '<h2>' . self::esc( self::text( $offering['title'] ?? '' ) ) . '</h2>';
		$html .= '<p>' . self::chip( self::text( $offering['state'] ?? 'draft' ), $locale ) . '</p>';
		if ( ! empty( $offering['can_publish'] ) && 'publish' !== self::text( $offering['status'] ?? 'draft' ) ) {
			$html .= self::action_form( 'lps_dashboard_publish', array( 'post_id' => Policy::sanitize_integer( $offering['id'] ?? 0 ) ), $english ? 'Publish the offering' : 'Publicar a oferta', 'publish-offering' );
		}
		$html  .= self::identity_line( $offering );
		$public = self::safe_url( self::text( $offering['public_url'] ?? '' ) );
		$edit   = self::safe_url( self::text( $offering['edit_url'] ?? '' ) );
		if ( '' !== $public ) {
			$html .= '<p><a href="' . self::esc( $public ) . '">' . self::esc( $english ? 'View the public page' : 'Ver a página pública' ) . '</a></p>';
		} elseif ( '' !== $edit && ! empty( $offering['can_edit'] ) ) {
			$html .= '<p><a href="' . self::esc( $edit ) . '">' . self::esc( $english ? 'Edit the offering record' : 'Editar o registro da oferta' ) . '</a></p>';
		}
		$html .= self::team_html( $offering, $locale );
		$html .= self::units_html( $offering, $locale );
		$html .= self::resources_html( $offering, $locale );
		$html .= self::unit_form( $offering, $locale );
		$html .= self::resource_form( $offering, $locale );
		$html .= self::copy_form( $offering, $model, $locale );
		return $html . '</section>';
	}

	/**
	 * Renders the news lane: authored items and the submission form.
	 *
	 * @param array<string, mixed> $model  Dashboard model.
	 * @param string               $locale Supported locale slug.
	 */
	public static function news_view( array $model, string $locale ): string {
		$english = 'en' === $locale;
		if ( empty( $model['news_scope'] ) && ! self::may_collection( 'create', 'news' ) ) {
			return '<div class="lps-alert lps-alert-error" data-dashboard-view="news-denied"><p>'
				. self::esc( $english ? 'Your account has no news scope.' : 'Sua conta não tem escopo de notícias.' )
				. '</p></div>';
		}
		$html    = '<section class="lps-dashboard-news" data-dashboard-view="news">';
		$html   .= '<p class="lps-summary">' . self::esc(
			$english
			? 'Submissions enter review as drafts; an editor approves or returns them with a note. A saved draft is never public.'
			: 'Os envios entram em revisão como rascunhos; um editor aprova ou devolve com uma nota. Um rascunho salvo nunca é público.'
		) . '</p>';
		$preview = class_exists( TaskDashboard::class ) ? self::record( TaskDashboard::news_preview() ) : array();
		if ( array() !== $preview ) {
			$html .= self::news_preview_html( $preview, $locale );
		}
		$items = is_array( $model['news'] ?? null ) ? $model['news'] : array();
		if ( array() !== $items ) {
			$html .= '<ul class="lps-record-list">';
			foreach ( $items as $item ) {
				$item  = self::record( $item );
				$html .= '<li class="lps-record">' . self::esc( self::text( $item['title'] ?? '' ) )
					. ' ' . self::chip( self::text( $item['state'] ?? 'draft' ), $locale )
					. self::item_links( $item, $locale )
					. self::note_html( $item, $locale )
					. self::translation_task_html( $item, $locale );
				if ( 'draft' === self::text( $item['status'] ?? '' ) ) {
					// A rejected item keeps its note and reopens for edits; the
					// resubmit returns it to review instead of minting a new record.
					$html .= '<details class="lps-dashboard-edit"><summary>' . self::esc( $english ? 'Edit and resubmit' : 'Editar e reenviar' ) . '</summary>'
						. self::form_open( 'lps_dashboard_news', true )
						. self::hidden( 'id', (string) Policy::sanitize_integer( $item['id'] ?? 0 ) )
						. self::field( 'title', TaskDashboard::field_label( 'post_title', $locale ), 'text', self::text( $item['title'] ?? '' ), $locale, true )
						. self::textarea( 'excerpt', TaskDashboard::field_label( 'post_excerpt', $locale ), self::text( $item['summary'] ?? '' ), $locale, true )
						. self::textarea( 'content', TaskDashboard::field_label( 'post_content', $locale ), self::text( $item['content'] ?? '' ), $locale, true )
						. self::field( 'canonical_date', TaskDashboard::field_label( '_lps_canonical_date', $locale ), 'datetime-local', self::text( $item['date'] ?? '' ), $locale, true )
						. self::select( 'news_category', TaskDashboard::field_label( 'news_category', $locale ), TaskDashboard::news_category_options(), self::text( $item['category'] ?? '' ), $locale, false )
						. self::file_field( 'featured_image', TaskDashboard::field_label( 'featured_image', $locale ), $locale )
						. self::field( 'featured_alt', TaskDashboard::field_label( 'featured_alt', $locale ), 'text', '', $locale, false )
						. self::submit( $english ? 'Resubmit for review' : 'Reenviar para revisão' )
						. '</form></details>';
				}
				$html .= '</li>';
			}
			$html .= '</ul>';
		}
		$recall = self::recall( 'news' );
		$html  .= '<section class="lps-dashboard-form" aria-labelledby="lps-news-form"><h2 id="lps-news-form">'
			. self::esc( $english ? 'Submit a news item' : 'Enviar uma notícia' ) . '</h2>'
			. '<p class="lps-field-hint">' . self::esc(
				$english
				? 'Before the item is submitted you see the card exactly as it will appear publicly; the submit happens on the confirmation step.'
				: 'Antes do envio você vê o cartão exatamente como aparecerá publicamente; a submissão acontece na etapa de confirmação.'
			) . '</p>'
			. self::form_open( 'lps_dashboard_news', true )
			. self::hidden( 'step', 'preview' )
			. self::field( 'title', TaskDashboard::field_label( 'post_title', $locale ), 'text', self::text( $recall['title'] ?? '' ), $locale, true )
			. self::textarea( 'excerpt', TaskDashboard::field_label( 'post_excerpt', $locale ), self::text( $recall['excerpt'] ?? '' ), $locale, true )
			. self::textarea( 'content', TaskDashboard::field_label( 'post_content', $locale ), self::text( $recall['content'] ?? '' ), $locale, true )
			. self::field( 'canonical_date', TaskDashboard::field_label( '_lps_canonical_date', $locale ), 'datetime-local', self::text( $recall['canonical_date'] ?? '' ), $locale, true )
			. self::select( 'news_category', TaskDashboard::field_label( 'news_category', $locale ), TaskDashboard::news_category_options(), self::text( $recall['news_category'] ?? '' ), $locale, false )
			. self::file_field( 'featured_image', TaskDashboard::field_label( 'featured_image', $locale ), $locale )
			. self::field( 'featured_alt', TaskDashboard::field_label( 'featured_alt', $locale ), 'text', self::text( $recall['featured_alt'] ?? '' ), $locale, false )
			. self::submit( $english ? 'Preview the card' : 'Pré-visualizar o cartão' )
			. '</form></section>';
		return $html . '</section>';
	}

	/**
	 * Renders the staged preview block: the public card, then confirm/cancel.
	 *
	 * The card mirrors the public news markup — date label, topic chip, title,
	 * summary and the featured image — so the confirmation is an informed one.
	 * Confirm re-reads the staged payload server-side; cancel discards it and
	 * its staged attachment.
	 *
	 * @param array<string, mixed> $preview Staged payload.
	 * @param string               $locale  Supported locale slug.
	 */
	private static function news_preview_html( array $preview, string $locale ): string {
		$english  = 'en' === $locale;
		$date     = self::text( $preview['canonical_date'] ?? '' );
		$category = self::text( $preview['news_category'] ?? '' );
		$label    = '' !== $category && class_exists( TaskDashboard::class ) ? TaskDashboard::news_category_label( $category ) : '';
		$image    = '';
		$url      = self::safe_url( self::text( $preview['attachment_url'] ?? '' ) );
		$alt      = self::text( $preview['attachment_alt'] ?? self::text( $preview['featured_alt'] ?? '' ) );
		if ( '' !== $url ) {
			$image = '<figure class="lps-news-preview-media"><img src="' . self::esc( $url ) . '" alt="' . self::esc( $alt ) . '" loading="lazy">'
				. ( '' !== $alt ? '<figcaption>' . self::esc( $alt ) . '</figcaption>' : '' )
				. '</figure>';
		}
		$html  = '<section class="lps-dashboard-section lps-news-preview" aria-labelledby="lps-news-preview">';
		$html .= '<p class="lps-kicker">' . self::esc( $english ? 'Preview' : 'Pré-visualização' ) . '</p>';
		$html .= '<h2 id="lps-news-preview">' . self::esc( $english ? 'This is how the card will appear publicly' : 'É assim que o cartão aparecerá publicamente' ) . '</h2>';
		$html .= '<div class="lps-news-preview-card"><div class="lps-event-date"><strong>' . self::esc( '' !== $date ? substr( $date, 0, 10 ) : '—' ) . '</strong><span>'
			. self::esc( '' !== $label ? $label : ( $english ? 'News' : 'Notícia' ) ) . '</span></div>'
			. '<div><h3>' . self::esc( self::text( $preview['title'] ?? '' ) ) . '</h3>'
			. '<p>' . self::esc( self::text( $preview['excerpt'] ?? '' ) ) . '</p></div>'
			. '<span class="lps-meta">' . self::esc( '' !== $date ? substr( $date, 0, 10 ) : '—' ) . '</span></div>';
		$html .= $image;
		$html .= '<div class="lps-body lps-reading lps-news-preview-body"><p>' . self::esc( self::text( $preview['content'] ?? '' ) ) . '</p></div>';
		$html .= '<div class="lps-button-row">'
			. self::form_open( 'lps_dashboard_news' )
			. self::hidden( 'step', 'confirm' )
			. self::submit( $english ? 'Confirm and submit for review' : 'Confirmar e enviar para revisão' )
			. '</form>'
			. self::form_open( 'lps_dashboard_news' )
			. self::hidden( 'step', 'cancel' )
			. '<button class="lps-button" type="submit">' . self::esc( $english ? 'Discard preview' : 'Descartar pré-visualização' ) . '</button>'
			. '</form></div>';
		return $html . '</section>';
	}

	/**
	 * Renders the EN-translation task on one news item when the variant is
	 * missing or stale.
	 *
	 * @param array<string, mixed> $item   News record row.
	 * @param string               $locale Supported locale slug.
	 */
	private static function translation_task_html( array $item, string $locale ): string {
		$english = 'en' === $locale;
		$state   = self::text( $item['translation_state'] ?? '' );
		if ( ! in_array( $state, array( 'missing', 'stale' ), true ) ) {
			return '';
		}
		$item_id = Policy::sanitize_integer( $item['id'] ?? 0 );
		$recall  = self::recall( 'news-translation-' . $item_id );
		$title   = '' !== self::text( $recall['en_title'] ?? '' ) ? self::text( $recall['en_title'] ?? '' ) : self::text( $item['en_title'] ?? '' );
		$excerpt = '' !== self::text( $recall['en_excerpt'] ?? '' ) ? self::text( $recall['en_excerpt'] ?? '' ) : self::text( $item['en_excerpt'] ?? '' );
		$content = '' !== self::text( $recall['en_content'] ?? '' ) ? self::text( $recall['en_content'] ?? '' ) : self::text( $item['en_content'] ?? '' );
		$summary = 'stale' === $state
			? ( $english ? 'Update the EN translation' : 'Atualizar a tradução EN' )
			: ( $english ? 'Add the EN translation' : 'Adicionar tradução EN' );
		$html    = '<details class="lps-dashboard-edit"><summary>' . self::esc( $summary ) . '</summary>';
		if ( 'stale' === $state ) {
			$html .= '<p class="lps-field-hint">' . self::esc(
				$english
				? 'The Portuguese record changed since the English variant was last reviewed; refresh it and resubmit.'
				: 'O registro em português mudou desde a última revisão da variante em inglês; atualize-a e reenvie.'
			) . '</p>';
		}
		$html .= self::form_open( 'lps_dashboard_news_translation' )
			. self::hidden( 'news_id', (string) $item_id )
			. self::field( 'en_title', TaskDashboard::field_label( 'en_title', $locale ), 'text', $title, $locale, true )
			. self::textarea( 'en_excerpt', TaskDashboard::field_label( 'en_excerpt', $locale ), $excerpt, $locale, true )
			. self::textarea( 'en_content', TaskDashboard::field_label( 'en_content', $locale ), $content, $locale, true )
			. self::submit( $english ? 'Submit the EN translation' : 'Enviar a tradução EN' )
			. '</form></details>';
		return $html;
	}

	/**
	 * Renders one labelled file input with the upload caps in its hint.
	 *
	 * @param string $name   Field name.
	 * @param string $label  Field label.
	 * @param string $locale Supported locale slug.
	 */
	private static function file_field( string $name, string $label, string $locale ): string {
		$id   = 'lps-f-' . sanitize_key( str_replace( array( '[', ']' ), array( '-', '' ), $name ) );
		$html = '<p class="lps-field"><label for="' . self::esc( $id ) . '">' . self::esc( $label ) . '</label>'
			. '<input id="' . self::esc( $id ) . '" name="' . self::esc( $name ) . '" type="file" accept="image/jpeg,image/png,image/webp">'
			. '<span class="lps-field-hint">' . self::esc( 'en' === $locale ? 'JPG, PNG or WebP, up to 15 MB.' : 'JPG, PNG ou WebP, até 15 MB.' ) . '</span>';
		return $html . '</p>';
	}

	/**
	 * Renders the profile lane: current values, the proposal form, history.
	 *
	 * @param array<string, mixed> $model  Dashboard model.
	 * @param string               $locale Supported locale slug.
	 */
	public static function profile_view( array $model, string $locale ): string {
		$english   = 'en' === $locale;
		$person_id = Policy::sanitize_integer( $model['person_id'] ?? 0 );
		if ( 0 >= $person_id ) {
			return '<div class="lps-alert lps-alert-info" data-dashboard-view="profile-unresolved"><p>'
				. self::esc(
					$english
					? 'Your account is not linked to one person record yet. When a teaching team names exactly one person across your offerings, the profile proposal form appears here.'
					: 'Sua conta ainda não está ligada a um único registro de pessoa. Quando uma equipe docente nomear exatamente uma pessoa nas suas ofertas, o formulário de proposta de perfil aparece aqui.'
				)
				. '</p></div>';
		}
		$person  = function_exists( 'get_post' ) ? get_post( $person_id ) : null;
		$current = array();
		foreach ( TaskDashboard::proposal_fields() as $field ) {
			if ( 'post_excerpt' === $field || 'post_content' === $field ) {
				$current[ $field ] = $person instanceof \WP_Post ? ( 'post_excerpt' === $field ? $person->post_excerpt : $person->post_content ) : '';
				continue;
			}
			$current[ $field ] = function_exists( 'get_post_meta' ) ? Policy::scalar_string( get_post_meta( $person_id, $field, true ) ) : '';
		}
		$recall        = self::recall( 'profile' );
		$recall_fields = is_array( $recall['fields'] ?? null ) ? $recall['fields'] : array();
		$html          = '<section class="lps-dashboard-profile" data-dashboard-view="profile">';
		$html         .= '<p class="lps-summary">' . self::esc(
			$english
			? 'Profile changes are proposals: an editor reviews them before the public record changes. The current value is shown beside each field.'
			: 'Alterações de perfil são propostas: um editor as revisa antes de o registro público mudar. O valor atual aparece ao lado de cada campo.'
		) . '</p>';
		$html         .= '<h2>' . self::esc( $person instanceof \WP_Post ? $person->post_title : '' ) . '</h2>';
		$html         .= '<section class="lps-dashboard-form" aria-labelledby="lps-profile-form"><h3 id="lps-profile-form">'
			. self::esc( $english ? 'Propose profile changes' : 'Propor alterações de perfil' ) . '</h3>'
			. self::form_open( 'lps_dashboard_profile' );
		foreach ( TaskDashboard::proposal_fields() as $field ) {
			$value = array_key_exists( $field, $recall_fields ) ? self::text( $recall_fields[ $field ] ) : '';
			$label = TaskDashboard::field_label( $field, $locale );
			$hint  = self::text( $current[ $field ] ?? '' );
			if ( 'post_excerpt' === $field || 'post_content' === $field ) {
				$html .= self::textarea( 'fields[' . $field . ']', $label, $value, $locale, false, $hint );
				continue;
			}
			$type  = '_lps_public_email' === $field ? 'email' : 'text';
			$html .= self::field( 'fields[' . $field . ']', $label, $type, $value, $locale, false, $hint );
		}
		$html     .= self::submit( $english ? 'Send proposal for review' : 'Enviar proposta para revisão' ) . '</form></section>';
		$proposals = is_array( $model['proposals'] ?? null ) ? $model['proposals'] : array();
		if ( array() !== $proposals ) {
			$html .= '<section class="lps-dashboard-section" aria-labelledby="lps-proposal-history"><h3 id="lps-proposal-history">'
				. self::esc( $english ? 'Proposal history' : 'Histórico de propostas' ) . '</h3><ul class="lps-record-list">';
			foreach ( $proposals as $proposal ) {
				$proposal = self::record( $proposal );
				$fields   = is_array( $proposal['fields'] ?? null ) ? $proposal['fields'] : array();
				$html    .= '<li class="lps-record">' . self::chip( self::text( $proposal['state'] ?? 'pending' ), $locale )
					. ' <span class="lps-meta">' . self::esc( implode( ', ', array_map( static fn( string $key ): string => TaskDashboard::field_label( $key, $locale ), array_keys( $fields ) ) ) ) . '</span>'
					. self::note_html( $proposal, $locale )
					. '</li>';
			}
			$html .= '</ul></section>';
		}
		return $html . '</section>';
	}

	/**
	 * Renders the review queue for editors: news submissions and proposals.
	 *
	 * @param array<string, mixed> $model  Dashboard model.
	 * @param string               $locale Supported locale slug.
	 */
	public static function review_view( array $model, string $locale ): string {
		$english = 'en' === $locale;
		if ( empty( $model['may_review'] ) ) {
			return '<div class="lps-alert lps-alert-error" data-dashboard-view="review-denied"><p>'
				. self::esc( $english ? 'Your account has no review queue.' : 'Sua conta não tem fila de revisão.' )
				. '</p></div>';
		}
		$queue = is_array( $model['review'] ?? null ) ? $model['review'] : array(
			'news'      => array(),
			'proposals' => array(),
		);
		$html  = '<section class="lps-dashboard-review" data-dashboard-view="review">';
		$html .= '<p class="lps-summary">' . self::esc(
			$english
			? 'Approve publishes the item; reject returns it to draft with a note the author sees. A rejection always needs the note.'
			: 'Aprovar publica o item; rejeitar o devolve a rascunho com uma nota que o autor vê. Uma rejeição sempre precisa da nota.'
		) . '</p>';
		$news  = is_array( $queue['news'] ?? null ) ? $queue['news'] : array();
		$html .= '<section class="lps-dashboard-section" aria-labelledby="lps-review-news"><h2 id="lps-review-news">'
			. self::esc( $english ? 'News submissions' : 'Notícias enviadas' ) . '</h2>';
		if ( array() === $news ) {
			$html .= '<p class="lps-field-hint">' . self::esc( $english ? 'No news item is waiting for review.' : 'Nenhuma notícia aguarda revisão.' ) . '</p>';
		} else {
			$html .= '<ul class="lps-record-list">';
			foreach ( $news as $item ) {
				$item  = self::record( $item );
				$html .= '<li class="lps-record"><strong>' . self::esc( self::text( $item['title'] ?? '' ) ) . '</strong>'
					. ' <span class="lps-meta">' . self::esc( self::text( $item['date'] ?? '' ) ) . '</span>'
					. '<p>' . self::esc( self::text( $item['summary'] ?? '' ) ) . '</p>'
					. self::review_form( 'news', Policy::sanitize_integer( $item['id'] ?? 0 ), 0, '', $locale )
					. '</li>';
			}
			$html .= '</ul>';
		}
		$html     .= '</section>';
		$proposals = is_array( $queue['proposals'] ?? null ) ? $queue['proposals'] : array();
		$html     .= '<section class="lps-dashboard-section" aria-labelledby="lps-review-proposals"><h2 id="lps-review-proposals">'
			. self::esc( $english ? 'Profile proposals' : 'Propostas de perfil' ) . '</h2>';
		if ( array() === $proposals ) {
			$html .= '<p class="lps-field-hint">' . self::esc( $english ? 'No profile proposal is waiting for review.' : 'Nenhuma proposta de perfil aguarda revisão.' ) . '</p>';
		} else {
			$html .= '<ul class="lps-record-list">';
			foreach ( $proposals as $row ) {
				$row      = self::record( $row );
				$proposal = self::record( $row['proposal'] ?? array() );
				$fields   = is_array( $proposal['fields'] ?? null ) ? $proposal['fields'] : array();
				$html    .= '<li class="lps-record"><strong>' . self::esc( self::text( $row['person'] ?? '' ) ) . '</strong><dl class="lps-proposal-diff">';
				foreach ( $fields as $field => $value ) {
					$html .= '<dt>' . self::esc( TaskDashboard::field_label( (string) $field, $locale ) ) . '</dt><dd>' . self::esc( self::text( $value ) ) . '</dd>';
				}
				$html .= '</dl>'
					. self::review_form( 'proposal', 0, Policy::sanitize_integer( $row['person_id'] ?? 0 ), self::text( $proposal['id'] ?? '' ), $locale )
					. '</li>';
			}
			$html .= '</ul>';
		}
		return $html . '</section></section>';
	}

	/**
	 * Renders the offering create form for institutional editors.
	 *
	 * @param array<string, mixed> $model  Dashboard model.
	 * @param string               $locale Supported locale slug.
	 */
	public static function create_view( array $model, string $locale ): string {
		$english = 'en' === $locale;
		if ( ! self::may_collection( 'create', 'teaching' ) ) {
			return '<div class="lps-alert lps-alert-error" data-dashboard-view="create-denied"><p>'
				. self::esc( $english ? 'Offering creation is an institutional editor task.' : 'A criação de ofertas é uma tarefa de editor institucional.' )
				. '</p></div>';
		}
		$recall      = self::recall( 'offering' );
		$recall_meta = is_array( $recall['meta'] ?? null ) ? $recall['meta'] : array();
		$courses     = self::records( $model['courses'] ?? null );
		$terms       = self::records( $model['terms'] ?? null );
		$people      = self::records( $model['people'] ?? null );
		$html        = '<section class="lps-dashboard-create" data-dashboard-view="create">';
		$html       .= '<p class="lps-summary">' . self::esc(
			$english
			? 'An offering binds one course to one term and section with its teaching team. The record starts as a draft.'
			: 'Uma oferta liga uma disciplina a um período e turma com sua equipe docente. O registro começa como rascunho.'
		) . '</p>';
		$html       .= self::form_open( 'lps_dashboard_offering' )
			. self::field( 'title', TaskDashboard::field_label( 'post_title', $locale ), 'text', self::text( $recall['title'] ?? '' ), $locale, true )
			. self::textarea( 'excerpt', TaskDashboard::field_label( 'post_excerpt', $locale ), self::text( $recall['excerpt'] ?? '' ), $locale, true )
			. self::textarea( 'content', TaskDashboard::field_label( 'post_content', $locale ), self::text( $recall['content'] ?? '' ), $locale, false )
			. self::select( 'course_id', TaskDashboard::field_label( 'course_id', $locale ), self::options_for( $courses, 'code' ), Policy::sanitize_integer( $recall['course_id'] ?? 0 ), $locale, true )
			. self::select( 'term_id', TaskDashboard::field_label( 'new_term_id', $locale ), self::options_for( $terms, 'label' ), Policy::sanitize_integer( $recall['term_id'] ?? 0 ), $locale, true )
			. self::field( 'section', TaskDashboard::field_label( 'new_section', $locale ), 'text', self::text( $recall['section'] ?? '' ), $locale, true )
			. self::field( 'schedule', TaskDashboard::field_label( '_lps_schedule', $locale ), 'text', self::text( $recall_meta['_lps_schedule'] ?? '' ), $locale, false )
			. self::field( 'venue', TaskDashboard::field_label( '_lps_venue', $locale ), 'text', self::text( $recall_meta['_lps_venue'] ?? '' ), $locale, false )
			. self::team_fields( $people, array(), $locale )
			. self::submit( $english ? 'Create the draft offering' : 'Criar a oferta em rascunho' )
			. '</form>';
		return $html . '</section>';
	}

	/**
	 * Renders the trusted course-plus-offering create form.
	 *
	 * Professors submit the course identity and its first offering in one
	 * pass — no editorial gate, per the site owner's decision. Accounts that
	 * do not qualify (a professor without an enrolled second factor, or a
	 * role outside the lane) see a denial panel instead of a dead form.
	 *
	 * @param array<string, mixed> $model  Dashboard model.
	 * @param string               $locale Supported locale slug.
	 */
	public static function course_view( array $model, string $locale ): string {
		$english = 'en' === $locale;
		if ( empty( $model['may_course'] ) ) {
			$message = ! empty( $model['mfa_needed'] )
				? ( $english ? 'Enroll the second factor on your sign-in before creating subjects.' : 'Ative a verificação em duas etapas na sua conta antes de criar disciplinas.' )
				: ( $english ? 'Creating subjects is a professor task.' : 'Criar disciplinas é uma tarefa de professor.' );
			return '<div class="lps-alert lps-alert-error" data-dashboard-view="course-denied"><p>'
				. self::esc( $message ) . '</p></div>';
		}
		$recall = self::recall( 'course' );
		$terms  = self::records( $model['terms'] ?? null );
		$people = self::records( $model['people'] ?? null );
		$levels = array();
		foreach ( TeachingContracts::COURSE_LEVELS as $level ) {
			$levels[] = array(
				'id'    => $level,
				'title' => TaskDashboard::field_label( 'level-' . $level, $locale ),
			);
		}
		$recall_team = self::records( $recall['team'] ?? null );
		$html        = '<section class="lps-dashboard-create" data-dashboard-view="course">';
		$html       .= '<p class="lps-summary">' . self::esc(
			$english
			? 'One submit registers the course and opens its first offering bound to a term and section. Both records start as drafts and the offering workspace opens right away.'
			: 'Um único envio cadastra a disciplina e abre a primeira oferta ligada a um período e turma. Ambos os registros começam como rascunho e a área da oferta abre em seguida.'
		) . '</p>';
		$html       .= self::form_open( 'lps_dashboard_course' )
			. '<fieldset class="lps-fieldset"><legend>' . self::esc( $english ? 'Course' : 'Disciplina' ) . '</legend>'
			. self::field( 'title', TaskDashboard::field_label( 'post_title', $locale ), 'text', self::text( $recall['title'] ?? '' ), $locale, true )
			. self::field( 'course_code', TaskDashboard::field_label( 'course_code', $locale ), 'text', self::text( $recall['course_code'] ?? '' ), $locale, true )
			. self::select( 'course_level', TaskDashboard::field_label( 'course_level', $locale ), $levels, self::text( $recall['course_level'] ?? '' ), $locale, true )
			. self::field( 'calendar_key', TaskDashboard::field_label( 'calendar_key', $locale ), 'text', self::text( $recall['calendar_key'] ?? '' ), $locale, true )
			. self::field( 'program', TaskDashboard::field_label( '_lps_program', $locale ), 'text', self::text( $recall['program'] ?? '' ), $locale, false )
			. self::textarea( 'prerequisites', TaskDashboard::field_label( '_lps_prerequisites', $locale ), self::text( $recall['prerequisites'] ?? '' ), $locale, false )
			. self::textarea( 'syllabus', TaskDashboard::field_label( '_lps_syllabus', $locale ), self::text( $recall['syllabus'] ?? '' ), $locale, false )
			. self::textarea( 'excerpt', TaskDashboard::field_label( 'post_excerpt', $locale ), self::text( $recall['excerpt'] ?? '' ), $locale, true )
			. self::textarea( 'content', TaskDashboard::field_label( 'post_content', $locale ), self::text( $recall['content'] ?? '' ), $locale, true )
			. '</fieldset>'
			. '<fieldset class="lps-fieldset"><legend>' . self::esc( $english ? 'First offering' : 'Primeira oferta' ) . '</legend>'
			. self::select( 'term_id', TaskDashboard::field_label( 'term_id', $locale ), self::options_for( $terms, 'label' ), Policy::sanitize_integer( $recall['term_id'] ?? 0 ), $locale, true )
			. self::field( 'section', TaskDashboard::field_label( 'section', $locale ), 'text', self::text( $recall['section'] ?? '' ), $locale, true )
			. self::field( 'schedule', TaskDashboard::field_label( '_lps_schedule', $locale ), 'text', self::text( $recall['schedule'] ?? '' ), $locale, false )
			. self::field( 'venue', TaskDashboard::field_label( '_lps_venue', $locale ), 'text', self::text( $recall['venue'] ?? '' ), $locale, false )
			. self::team_fields( $people, $recall_team, $locale )
			. '</fieldset>'
			. self::submit( $english ? 'Create subject and first offering' : 'Criar disciplina e primeira oferta' )
			. '</form>';
		return $html . '</section>';
	}

	/**
	 * Renders the units list with per-unit publish controls.
	 *
	 * @param array<string, mixed> $offering Offering workspace model.
	 * @param string               $locale   Supported locale slug.
	 */
	private static function units_html( array $offering, string $locale ): string {
		$english = 'en' === $locale;
		$units   = is_array( $offering['units'] ?? null ) ? $offering['units'] : array();
		$html    = '<section class="lps-dashboard-section" aria-labelledby="lps-units"><h3 id="lps-units">'
			. self::esc( $english ? 'Units' : 'Unidades' ) . '</h3>';
		if ( array() === $units ) {
			$html .= '<p class="lps-field-hint">' . self::esc( $english ? 'No units yet; add the first one below.' : 'Nenhuma unidade ainda; adicione a primeira abaixo.' ) . '</p>';
		} else {
			$html .= '<ol class="lps-record-list">';
			foreach ( $units as $unit ) {
				$unit  = self::record( $unit );
				$html .= '<li class="lps-record">' . self::esc( self::text( $unit['title'] ?? '' ) )
					. ' ' . self::chip( self::text( $unit['state'] ?? 'draft' ), $locale )
					. ' <span class="lps-meta">' . self::esc( self::text( $unit['anchor'] ?? '' ) ) . '</span>';
				if ( ! empty( $offering['can_publish'] ) && 'publish' !== self::text( $unit['status'] ?? '' ) ) {
					$html .= self::action_form( 'lps_dashboard_publish', array( 'post_id' => Policy::sanitize_integer( $unit['id'] ?? 0 ) ), $english ? 'Publish unit' : 'Publicar unidade', 'publish-unit' );
				}
				$html .= '</li>';
			}
			$html .= '</ol>';
		}
		return $html . '</section>';
	}

	/**
	 * Renders the materials list with version, release and publish controls.
	 *
	 * @param array<string, mixed> $offering Offering workspace model.
	 * @param string               $locale   Supported locale slug.
	 */
	private static function resources_html( array $offering, string $locale ): string {
		$english   = 'en' === $locale;
		$resources = is_array( $offering['resources'] ?? null ) ? $offering['resources'] : array();
		$html      = '<section class="lps-dashboard-section" aria-labelledby="lps-resources"><h3 id="lps-resources">'
			. self::esc( $english ? 'Materials' : 'Materiais' ) . '</h3>';
		if ( array() === $resources ) {
			$html .= '<p class="lps-field-hint">' . self::esc( $english ? 'No materials yet; add the first one below.' : 'Nenhum material ainda; adicione o primeiro abaixo.' ) . '</p>';
		} else {
			$html .= '<ul class="lps-record-list">';
			foreach ( $resources as $resource ) {
				$resource    = self::record( $resource );
				$resource_id = Policy::sanitize_integer( $resource['id'] ?? 0 );
				$state       = self::text( $resource['state'] ?? 'draft' );
				$html       .= '<li class="lps-record" data-resource-id="' . $resource_id . '"><strong>' . self::esc( self::text( $resource['title'] ?? '' ) ) . '</strong>'
					. ' ' . self::chip( $state, $locale )
					. ' <span class="lps-meta">' . self::esc( self::text( $resource['resource_type'] ?? '' ) . ' · ' . self::text( $resource['resource_language'] ?? '' ) ) . '</span>';
				$download    = self::safe_url( self::text( $resource['download_url'] ?? '' ) );
				if ( 'released' === self::text( $resource['release_state'] ?? '' ) && 'publish' === self::text( $resource['status'] ?? '' ) && '' !== $download ) {
					$html .= ' <a href="' . self::esc( $download ) . '">' . self::esc( $english ? 'Download' : 'Baixar' ) . '</a>';
				} elseif ( '' !== self::text( $resource['scan_state'] ?? '' ) && 'clean' !== self::text( $resource['scan_state'] ?? '' ) ) {
					$html .= ' ' . self::chip( 'scan-' . self::text( $resource['scan_state'] ?? 'pending' ), $locale );
				}
				if ( ! empty( $offering['can_edit'] ) ) {
					$html .= self::version_form( $resource_id, $locale );
				}
				if ( ! empty( $offering['can_publish'] ) ) {
					if ( 'released' !== self::text( $resource['release_state'] ?? '' ) ) {
						$html .= self::release_form( $resource_id, $locale );
					} else {
						$html .= self::action_form(
							'lps_dashboard_release',
							array(
								'resource_id'    => $resource_id,
								'release_action' => 'withdraw',
							),
							$english ? 'Withdraw' : 'Retirar',
							'withdraw-resource'
						);
					}
					if ( 'publish' !== self::text( $resource['status'] ?? '' ) ) {
						$html .= self::action_form( 'lps_dashboard_publish', array( 'post_id' => $resource_id ), $english ? 'Publish material' : 'Publicar material', 'publish-resource' );
					}
				}
				$html .= '</li>';
			}
			$html .= '</ul>';
		}
		return $html . '</section>';
	}

	/**
	 * Renders the unit create form.
	 *
	 * @param array<string, mixed> $offering Offering workspace model.
	 * @param string               $locale   Supported locale slug.
	 */
	private static function unit_form( array $offering, string $locale ): string {
		if ( empty( $offering['can_edit'] ) ) {
			return '';
		}
		$english     = 'en' === $locale;
		$offering_id = Policy::sanitize_integer( $offering['id'] ?? 0 );
		$recall      = self::recall( 'unit-' . $offering_id );
		$meta        = is_array( $recall['meta'] ?? null ) ? $recall['meta'] : array();
		return '<section class="lps-dashboard-form" aria-labelledby="lps-unit-form"><h3 id="lps-unit-form">'
			. self::esc( $english ? 'Add a unit' : 'Adicionar unidade' ) . '</h3>'
			. self::form_open( 'lps_dashboard_unit' )
			. self::hidden( 'offering_id', (string) $offering_id )
			. self::field( 'title', TaskDashboard::field_label( 'post_title', $locale ), 'text', self::text( $recall['title'] ?? '' ), $locale, true )
			. self::textarea( 'excerpt', TaskDashboard::field_label( 'post_excerpt', $locale ), self::text( $recall['excerpt'] ?? '' ), $locale, false )
			. self::field( 'anchor', TaskDashboard::field_label( '_lps_anchor', $locale ), 'text', self::text( $meta['_lps_anchor'] ?? '' ), $locale, true )
			. self::field( 'position', TaskDashboard::field_label( '_lps_position', $locale ), 'number', (string) Policy::sanitize_integer( $meta['_lps_position'] ?? 0 ), $locale, true )
			. self::field( 'topic_date', TaskDashboard::field_label( '_lps_topic_date', $locale ), 'date', self::text( $meta['_lps_topic_date'] ?? '' ), $locale, false )
			. self::submit( $english ? 'Create the draft unit' : 'Criar a unidade em rascunho' )
			. '</form></section>';
	}

	/**
	 * Renders the material create form with an optional initial upload.
	 *
	 * @param array<string, mixed> $offering Offering workspace model.
	 * @param string               $locale   Supported locale slug.
	 */
	private static function resource_form( array $offering, string $locale ): string {
		if ( empty( $offering['can_edit'] ) ) {
			return '';
		}
		$english     = 'en' === $locale;
		$offering_id = Policy::sanitize_integer( $offering['id'] ?? 0 );
		$recall      = self::recall( 'resource-' . $offering_id );
		$meta        = is_array( $recall['meta'] ?? null ) ? $recall['meta'] : array();
		$units       = array();
		foreach ( self::records( $offering['units'] ?? null ) as $unit ) {
			$units[] = array(
				'id'    => Policy::sanitize_integer( $unit['id'] ?? 0 ),
				'title' => self::text( $unit['title'] ?? '' ),
			);
		}
		$types = array();
		foreach ( TeachingContracts::RESOURCE_TYPES as $type ) {
			$types[] = array(
				'id'    => $type,
				'title' => $type,
			);
		}
		return '<section class="lps-dashboard-form" aria-labelledby="lps-resource-form"><h3 id="lps-resource-form">'
			. self::esc( $english ? 'Add a material' : 'Adicionar material' ) . '</h3>'
			. self::form_open( 'lps_dashboard_resource', true )
			. self::hidden( 'offering_id', (string) $offering_id )
			. self::field( 'title', TaskDashboard::field_label( 'post_title', $locale ), 'text', self::text( $recall['title'] ?? '' ), $locale, true )
			. self::textarea( 'excerpt', TaskDashboard::field_label( 'post_excerpt', $locale ), self::text( $recall['excerpt'] ?? '' ), $locale, false )
			. self::select( 'resource_type', TaskDashboard::field_label( '_lps_resource_type', $locale ), $types, self::text( $meta['_lps_resource_type'] ?? 'document' ), $locale, true )
			. self::field( 'resource_language', TaskDashboard::field_label( '_lps_resource_language', $locale ), 'text', self::text( $meta['_lps_resource_language'] ?? 'pt-br' ), $locale, true )
			. self::select( 'unit_id', TaskDashboard::field_label( 'unit_id', $locale ), $units, Policy::sanitize_integer( $recall['unit_id'] ?? 0 ), $locale, false )
			. self::field( 'external_url', TaskDashboard::field_label( '_lps_external_url', $locale ), 'url', self::text( $recall['external_url'] ?? '' ), $locale, false )
			. self::field( 'file', TaskDashboard::field_label( 'file', $locale ), 'file', '', $locale, false )
			. '<p class="lps-field-hint">' . self::esc( $english ? 'Attach a file or an external URL, never both.' : 'Anexe um arquivo ou uma URL externa, nunca ambos.' ) . '</p>'
			. self::submit( $english ? 'Create the draft material' : 'Criar o material em rascunho' )
			. '</form></section>';
	}

	/**
	 * Renders the copy-forward task form.
	 *
	 * The operation identifier is minted server-side into the form so a
	 * retried submit replays the stored result instead of duplicating the
	 * offering.
	 *
	 * @param array<string, mixed> $offering Offering workspace model.
	 * @param array<string, mixed> $model    Dashboard model.
	 * @param string               $locale   Supported locale slug.
	 */
	private static function copy_form( array $offering, array $model, string $locale ): string {
		if ( empty( $offering['can_copy'] ) ) {
			return '';
		}
		$english     = 'en' === $locale;
		$offering_id = Policy::sanitize_integer( $offering['id'] ?? 0 );
		$recall      = self::recall( 'copy-' . $offering_id );
		$terms       = self::records( $model['terms'] ?? null );
		$team        = self::records( $offering['team'] ?? null );
		// The current team may hold unpublished people; the option list must
		// include them so the reviewed-team submit is not blocked.
		$team_ids  = array_map( static fn( array $member ): int => Policy::sanitize_integer( $member['person_id'] ?? 0 ), $team );
		$people    = class_exists( TaskDashboard::class ) ? TaskDashboard::people_options( $team_ids ) : self::records( $model['people'] ?? null );
		$reusable  = is_array( $offering['reusable'] ?? null ) ? $offering['reusable'] : array();
		$operation = function_exists( 'wp_generate_uuid4' ) ? 'copy-' . wp_generate_uuid4() : 'copy-' . (string) $offering_id;
		$html      = '<section class="lps-dashboard-form" aria-labelledby="lps-copy-form"><h3 id="lps-copy-form">'
			. self::esc( $english ? 'Copy to the next term' : 'Copiar para o próximo período' ) . '</h3>'
			. '<p class="lps-field-hint">' . self::esc(
				$english
				? 'The copy creates a draft offering on the target term with the reviewed team and the selected cleared materials. An editor still publishes it.'
				: 'A cópia cria uma oferta em rascunho no período de destino com a equipe revisada e os materiais selecionados. Um editor ainda a publica.'
			) . '</p>'
			. self::form_open( 'lps_dashboard_copy' )
			. self::hidden( 'offering_id', (string) $offering_id )
			. self::hidden( 'operation_id', $operation )
			. self::select( 'new_term_id', TaskDashboard::field_label( 'new_term_id', $locale ), self::options_for( $terms, 'label' ), Policy::sanitize_integer( $recall['new_term_id'] ?? 0 ), $locale, true )
			. self::field( 'new_section', TaskDashboard::field_label( 'new_section', $locale ), 'text', self::text( $recall['new_section'] ?? '' ), $locale, true )
			. self::field( 'title', TaskDashboard::field_label( 'post_title', $locale ), 'text', self::text( $recall['title'] ?? '' ), $locale, false )
			. self::team_fields( $people, $team, $locale )
			. '<label class="lps-checkbox"><input type="checkbox" name="team_reviewed" value="1"> '
			. self::esc( $english ? 'I reviewed the teaching team for the new term' : 'Revisei a equipe docente para o novo período' ) . '</label>';
		if ( array() !== $reusable ) {
			$html .= '<fieldset class="lps-fieldset"><legend>' . self::esc( $english ? 'Cleared materials to carry over' : 'Materiais liberados a levar' ) . '</legend>';
			foreach ( $reusable as $row ) {
				$row   = self::record( $row );
				$html .= '<label class="lps-checkbox"><input type="checkbox" name="versions[]" value="' . self::esc( self::text( $row['version_id'] ?? '' ) ) . '"> '
					. self::esc( self::text( $row['resource'] ?? '' ) ) . '</label>';
			}
			$html .= '</fieldset>';
		}
		$html .= self::submit( $english ? 'Create the next-term draft' : 'Criar o rascunho do próximo período' ) . '</form></section>';
		return $html;
	}

	/**
	 * Renders the teaching-team selector rows.
	 *
	 * @param array<int, array<string, mixed>> $people   Published people options.
	 * @param array<int, array<string, mixed>> $selected Current team rows.
	 * @param string                           $locale   Supported locale slug.
	 */
	private static function team_fields( array $people, array $selected, string $locale ): string {
		$english = 'en' === $locale;
		$roles   = array();
		foreach ( TeachingContracts::TEAM_ROLES as $role ) {
			$roles[] = array(
				'id'    => $role,
				'title' => $role,
			);
		}
		$rows = max( 1, count( $selected ) );
		$html = '<fieldset class="lps-fieldset" data-team-fields><legend>' . self::esc( TaskDashboard::field_label( 'team', $locale ) ) . '</legend>';
		for ( $index = 0; $index < $rows; $index++ ) {
			$member = self::record( $selected[ $index ] ?? array() );
			$html  .= '<div class="lps-team-row">'
				. self::select( 'team[' . $index . '][person_id]', $english ? 'Person' : 'Pessoa', $people, Policy::sanitize_integer( $member['person_id'] ?? 0 ), $locale, 0 === $index )
				. self::select( 'team[' . $index . '][role]', $english ? 'Role' : 'Função', $roles, self::text( $member['role'] ?? 'lead' ), $locale, true )
				. '</div>';
		}
		return $html . '</fieldset>';
	}

	/**
	 * Renders one review decision form (approve or reject with note).
	 *
	 * @param string $kind        `news` or `proposal`.
	 * @param int    $post_id     News record ID.
	 * @param int    $person_id   Person record ID for proposals.
	 * @param string $proposal_id Proposal identifier for proposals.
	 * @param string $locale      Supported locale slug.
	 */
	private static function review_form( string $kind, int $post_id, int $person_id, string $proposal_id, string $locale ): string {
		$english = 'en' === $locale;
		return self::form_open( 'lps_dashboard_review' )
			. self::hidden( 'kind', $kind )
			. self::hidden( 'post_id', (string) $post_id )
			. self::hidden( 'person_id', (string) $person_id )
			. self::hidden( 'proposal_id', $proposal_id )
			. self::field( 'note', TaskDashboard::field_label( 'note', $locale ), 'text', '', $locale, false )
			. '<div class="lps-button-row">'
			. '<button class="lps-button lps-button-primary" type="submit" name="decision" value="approve">' . self::esc( $english ? 'Approve' : 'Aprovar' ) . '</button>'
			. '<button class="lps-button" type="submit" name="decision" value="reject">' . self::esc( $english ? 'Reject with note' : 'Rejeitar com nota' ) . '</button>'
			. '</div></form>';
	}

	/**
	 * Renders the version upload-and-select form on one resource.
	 *
	 * @param int    $resource_id Resource record ID.
	 * @param string $locale      Supported locale slug.
	 */
	private static function version_form( int $resource_id, string $locale ): string {
		$english = 'en' === $locale;
		return self::form_open( 'lps_dashboard_version', true )
			. self::hidden( 'resource_id', (string) $resource_id )
			. self::field( 'file', TaskDashboard::field_label( 'file', $locale ), 'file', '', $locale, true )
			. self::submit( $english ? 'Upload and select' : 'Enviar e selecionar' )
			. '</form>';
	}

	/**
	 * Renders the release/schedule form on one resource.
	 *
	 * @param int    $resource_id Resource record ID.
	 * @param string $locale      Supported locale slug.
	 */
	private static function release_form( int $resource_id, string $locale ): string {
		$english = 'en' === $locale;
		return self::form_open( 'lps_dashboard_release' )
			. self::hidden( 'resource_id', (string) $resource_id )
			. self::field( 'release_at', TaskDashboard::field_label( '_lps_release_at', $locale ), 'datetime-local', '', $locale, false )
			. '<div class="lps-button-row">'
			. '<button class="lps-button lps-button-primary" type="submit" name="release_action" value="release">' . self::esc( $english ? 'Release now' : 'Publicar agora' ) . '</button>'
			. '<button class="lps-button" type="submit" name="release_action" value="schedule">' . self::esc( $english ? 'Schedule' : 'Agendar' ) . '</button>'
			. '</div></form>';
	}

	/**
	 * Renders a single-submit action form (publish, withdraw).
	 *
	 * @param string               $action  Handler action key.
	 * @param array<string, mixed> $fields  Hidden fields.
	 * @param string               $label   Button label.
	 * @param string               $data    `data-action` marker for tests.
	 */
	private static function action_form( string $action, array $fields, string $label, string $data ): string {
		$html = self::form_open( $action );
		foreach ( $fields as $key => $value ) {
			$html .= self::hidden( (string) $key, is_scalar( $value ) ? (string) $value : '' );
		}
		return $html . '<button class="lps-button" type="submit" data-action="' . self::esc( $data ) . '">' . self::esc( $label ) . '</button></form>';
	}

	/**
	 * Renders the status chip for one lifecycle state.
	 *
	 * @param string $state  State key.
	 * @param string $locale Supported locale slug.
	 */
	private static function chip( string $state, string $locale ): string {
		$tone = match ( $state ) {
			'public', 'approved', 'released' => 'success',
			'in-review', 'pending', 'scheduled', 'scan-pending' => 'warning',
			'withdrawn', 'rejected', 'denied', 'scan-failed', 'archived' => 'error',
			default => 'info',
		};
		return '<span class="lps-status lps-status-' . $tone . '" data-state="' . self::esc( $state ) . '">'
			. self::esc( TaskDashboard::state_label( $state, $locale ) ) . '</span>';
	}

	/**
	 * Renders the notice or error banner from the redirect query args.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function notice_html( string $locale ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only notice state, sanitized as a scalar on the next line.
		$raw_notice = isset( $_GET['lps_notice'] ) ? wp_unslash( $_GET['lps_notice'] ) : '';
		$notice     = is_string( $raw_notice ) ? sanitize_key( $raw_notice ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only notice state, sanitized as a scalar on the next line.
		$raw_error = isset( $_GET['lps_error'] ) ? wp_unslash( $_GET['lps_error'] ) : '';
		$error     = is_string( $raw_error ) ? sanitize_key( $raw_error ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only notice state, sanitized as a scalar on the next line.
		$raw_field = isset( $_GET['lps_field'] ) ? wp_unslash( $_GET['lps_field'] ) : '';
		$field     = is_string( $raw_field ) ? sanitize_key( $raw_field ) : '';
		if ( '' !== $notice ) {
			return '<div class="lps-alert lps-alert-success" role="status" data-dashboard-notice="' . self::esc( $notice ) . '"><p>'
				. self::esc( TaskDashboard::notice_message( $notice, $locale ) ) . '</p></div>';
		}
		if ( '' !== $error ) {
			$label = '' !== $field ? TaskDashboard::field_label( $field, $locale ) . ': ' : '';
			return '<div class="lps-alert lps-alert-error" role="alert" data-dashboard-error="' . self::esc( $error ) . '"><p>'
				. self::esc( $label . TaskDashboard::error_message( $error, $locale ) ) . '</p></div>';
		}
		return '';
	}

	/**
	 * Renders the offering identity line (course, term, section).
	 *
	 * @param array<string, mixed> $offering Offering workspace model.
	 */
	private static function identity_line( array $offering ): string {
		$identity = self::record( $offering['identity'] ?? array() );
		$parts    = array_filter(
			array(
				self::text( $identity['course_title'] ?? '' ),
				self::text( $identity['term_label'] ?? '' ),
				self::text( $identity['section_key'] ?? '' ),
				self::text( $offering['temporal_status'] ?? '' ),
			)
		);
		if ( array() === $parts ) {
			return '';
		}
		return '<p class="lps-meta lps-offering-identity">' . self::esc( implode( ' · ', $parts ) ) . '</p>';
	}

	/**
	 * Renders the teaching team list.
	 *
	 * @param array<string, mixed> $offering Offering workspace model.
	 * @param string               $locale   Supported locale slug.
	 */
	private static function team_html( array $offering, string $locale ): string {
		$team = is_array( $offering['team'] ?? null ) ? $offering['team'] : array();
		if ( array() === $team ) {
			return '';
		}
		$names = array();
		foreach ( $team as $member ) {
			$member  = self::record( $member );
			$names[] = self::text( $member['name'] ?? '' ) . ' (' . self::text( $member['role'] ?? '' ) . ')';
		}
		return '<p class="lps-meta">' . self::esc( TaskDashboard::field_label( 'team', $locale ) . ': ' . implode( ', ', $names ) ) . '</p>';
	}

	/**
	 * Renders the public/editor links of one record row.
	 *
	 * @param array<string, mixed> $item   Record row.
	 * @param string               $locale Supported locale slug.
	 */
	private static function item_links( array $item, string $locale ): string {
		$english = 'en' === $locale;
		$html    = '';
		$public  = self::safe_url( self::text( $item['public_url'] ?? '' ) );
		$edit    = self::safe_url( self::text( $item['edit_url'] ?? '' ) );
		if ( '' !== $public ) {
			$html .= ' <a href="' . self::esc( $public ) . '">' . self::esc( $english ? 'View' : 'Ver' ) . '</a>';
		}
		if ( '' !== $edit ) {
			$html .= ' <a href="' . self::esc( $edit ) . '">' . self::esc( $english ? 'Edit' : 'Editar' ) . '</a>';
		}
		return $html;
	}

	/**
	 * Renders the review note attached to one record or proposal.
	 *
	 * @param array<string, mixed> $item   Record row.
	 * @param string               $locale Supported locale slug.
	 */
	private static function note_html( array $item, string $locale ): string {
		$note = self::text( $item['note'] ?? '' );
		if ( '' === $note ) {
			return '';
		}
		return '<p class="lps-field-hint" data-review-note>' . self::esc( TaskDashboard::field_label( 'note', $locale ) . ': ' . $note ) . '</p>';
	}

	/**
	 * Returns the task links the model allows.
	 *
	 * @param array<string, mixed> $model  Dashboard model.
	 * @param string               $locale Supported locale slug.
	 * @return array<int, array{label: string, hint: string, url: string}>
	 */
	private static function task_links( array $model, string $locale ): array {
		$english = 'en' === $locale;
		$tasks   = is_array( $model['tasks'] ?? null ) ? $model['tasks'] : array();
		$map     = array(
			'profile'         => array(
				'label' => $english ? 'My profile' : 'Meu perfil',
				'hint'  => $english ? 'Propose changes to your public person record.' : 'Proponha alterações ao seu registro público de pessoa.',
				'view'  => 'profile',
			),
			'offerings'       => array(
				'label' => $english ? 'My offerings' : 'Minhas ofertas',
				'hint'  => $english ? 'Units, materials and the next-term copy.' : 'Unidades, materiais e a cópia do próximo período.',
				'view'  => 'home',
			),
			'news'            => array(
				'label' => $english ? 'Submit news' : 'Enviar notícia',
				'hint'  => $english ? 'Draft a news item for editorial review.' : 'Rascunhe uma notícia para revisão editorial.',
				'view'  => 'news',
			),
			'review'          => array(
				'label' => $english ? 'Review queue' : 'Fila de revisão',
				'hint'  => $english ? 'Decide pending submissions and proposals.' : 'Decida envios e propostas pendentes.',
				'view'  => 'review',
			),
			'create-offering' => array(
				'label' => $english ? 'Create offering' : 'Criar oferta',
				'hint'  => $english ? 'Bind a course to a term and section.' : 'Ligue uma disciplina a um período e turma.',
				'view'  => 'create',
			),
			'course'          => array(
				'label' => $english ? 'Create subject' : 'Criar disciplina',
				'hint'  => $english ? 'Register a course and open its first offering.' : 'Cadastre uma disciplina e abra a primeira oferta.',
				'view'  => 'course',
			),
		);
		$links   = array();
		foreach ( $tasks as $task ) {
			$key = is_string( $task ) ? $task : '';
			if ( ! isset( $map[ $key ] ) ) {
				continue;
			}
			$links[] = array(
				'label' => $map[ $key ]['label'],
				'hint'  => $map[ $key ]['hint'],
				'url'   => DashboardRoutes::view_path( $map[ $key ]['view'], $locale ),
			);
		}
		return $links;
	}

	/**
	 * Returns the localized title of one view.
	 *
	 * @param string $view   View key.
	 * @param string $locale Supported locale slug.
	 */
	private static function view_title( string $view, string $locale ): string {
		$english = 'en' === $locale;
		return match ( $view ) {
			'offering' => $english ? 'Offering workspace' : 'Área da oferta',
			'news'     => $english ? 'News' : 'Notícias',
			'profile'  => $english ? 'My profile' : 'Meu perfil',
			'review'   => $english ? 'Review queue' : 'Fila de revisão',
			'create'   => $english ? 'Create offering' : 'Criar oferta',
			'course'   => $english ? 'Create subject' : 'Criar disciplina',
			default    => $english ? 'Dashboard' : 'Painel',
		};
	}

	/**
	 * Opens one dashboard form posting to `admin-post.php`.
	 *
	 * @param string $action     Handler action key.
	 * @param bool   $multipart  Whether the form carries a file.
	 */
	private static function form_open( string $action, bool $multipart = false ): string {
		$nonce  = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( $action ) : '';
		$target = function_exists( 'admin_url' ) ? admin_url( 'admin-post.php' ) : '/wp-admin/admin-post.php';
		// The site sends Referrer-Policy: no-referrer, so wp_get_referer() only
		// resolves the originating view through the explicit _wp_http_referer
		// field — the same convention wp-admin forms use.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized as a scalar on the next line.
		$raw_referer = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$referer     = is_string( $raw_referer ) ? sanitize_url( $raw_referer ) : '';
		return '<form method="post" action="' . self::esc( $target ) . '"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . ' data-dashboard-form="' . self::esc( $action ) . '">'
			. self::hidden( 'action', $action )
			. self::hidden( '_wp_http_referer', $referer )
			. self::hidden( '_lps_dashboard_nonce', $nonce );
	}

	/**
	 * Renders one hidden input.
	 *
	 * @param string $name  Field name.
	 * @param string $value Field value.
	 */
	private static function hidden( string $name, string $value ): string {
		return '<input type="hidden" name="' . self::esc( $name ) . '" value="' . self::esc( $value ) . '">';
	}

	/**
	 * Renders one labelled input with its hint and error slot.
	 *
	 * @param string $name     Field name.
	 * @param string $label    Field label.
	 * @param string $type     Input type.
	 * @param string $value    Current value.
	 * @param string $locale   Supported locale slug.
	 * @param bool   $required Whether the field is required.
	 * @param string $hint     Current-value hint.
	 */
	private static function field( string $name, string $label, string $type, string $value, string $locale, bool $required, string $hint = '' ): string {
		$id   = 'lps-f-' . sanitize_key( str_replace( array( '[', ']' ), array( '-', '' ), $name ) );
		$html = '<p class="lps-field"><label for="' . self::esc( $id ) . '">' . self::esc( $label ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</label>'
			. '<input id="' . self::esc( $id ) . '" name="' . self::esc( $name ) . '" type="' . self::esc( $type ) . '" value="' . self::esc( $value ) . '"' . ( $required ? ' required' : '' ) . '>';
		if ( '' !== $hint ) {
			$html .= '<span class="lps-field-hint">' . self::esc( ( 'en' === $locale ? 'Current: ' : 'Atual: ' ) . $hint ) . '</span>';
		}
		return $html . '</p>';
	}

	/**
	 * Renders one labelled textarea.
	 *
	 * @param string $name     Field name.
	 * @param string $label    Field label.
	 * @param string $value    Current value.
	 * @param string $locale   Supported locale slug.
	 * @param bool   $required Whether the field is required.
	 * @param string $hint     Current-value hint.
	 */
	private static function textarea( string $name, string $label, string $value, string $locale, bool $required, string $hint = '' ): string {
		$id   = 'lps-f-' . sanitize_key( str_replace( array( '[', ']' ), array( '-', '' ), $name ) );
		$html = '<p class="lps-field"><label for="' . self::esc( $id ) . '">' . self::esc( $label ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</label>'
			. '<textarea id="' . self::esc( $id ) . '" name="' . self::esc( $name ) . '" rows="4"' . ( $required ? ' required' : '' ) . '>' . self::esc( $value ) . '</textarea>';
		if ( '' !== $hint ) {
			$html .= '<span class="lps-field-hint">' . self::esc( ( 'en' === $locale ? 'Current: ' : 'Atual: ' ) . $hint ) . '</span>';
		}
		return $html . '</p>';
	}

	/**
	 * Renders one labelled select.
	 *
	 * @param string                           $name     Field name.
	 * @param string                           $label    Field label.
	 * @param array<int, array<string, mixed>> $options  Option rows (`id`, `title`, optional `code`/`label`).
	 * @param string|int                       $selected Selected value.
	 * @param string                           $locale   Supported locale slug.
	 * @param bool                             $required Whether the field is required.
	 */
	private static function select( string $name, string $label, array $options, string|int $selected, string $locale, bool $required ): string {
		$id   = 'lps-f-' . sanitize_key( str_replace( array( '[', ']' ), array( '-', '' ), $name ) );
		$html = '<p class="lps-field"><label for="' . self::esc( $id ) . '">' . self::esc( $label ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</label>'
			. '<select id="' . self::esc( $id ) . '" name="' . self::esc( $name ) . '"' . ( $required ? ' required' : '' ) . '>'
			. '<option value="">' . self::esc( 'en' === $locale ? '— Select —' : '— Selecione —' ) . '</option>';
		foreach ( $options as $option ) {
			$option = self::record( $option );
			$value  = self::text( $option['id'] ?? '' );
			$text   = self::text( $option['title'] ?? '' );
			$extra  = self::text( $option['code'] ?? ( $option['label'] ?? '' ) );
			if ( '' !== $extra && $extra !== $text ) {
				$text = '' === $text ? $extra : $text . ' (' . $extra . ')';
			}
			$mark  = (string) $selected === $value ? ' selected' : '';
			$html .= '<option value="' . self::esc( $value ) . '"' . $mark . '>' . self::esc( $text ) . '</option>';
		}
		return $html . '</select></p>';
	}

	/**
	 * Renders the submit button.
	 *
	 * @param string $label Button label.
	 */
	private static function submit( string $label ): string {
		return '<p class="lps-button-row"><button class="lps-button lps-button-primary" type="submit">' . self::esc( $label ) . '</button></p>';
	}

	/**
	 * Returns the stored recall values for one form.
	 *
	 * @param string $key Form key.
	 * @return array<string, mixed>
	 */
	private static function recall( string $key ): array {
		return class_exists( TaskDashboard::class ) ? TaskDashboard::recalled( $key ) : array();
	}

	/**
	 * Returns whether the account holds one collection action.
	 *
	 * @param string $action     Action key.
	 * @param string $collection Collection key.
	 */
	private static function may_collection( string $action, string $collection ): bool {
		return class_exists( \LPS\ContentModel\Roles::class ) && \LPS\ContentModel\Roles::current_user_can_action( $action, $collection );
	}

	/**
	 * Normalizes one option list for the select renderer.
	 *
	 * @param array<int, array<string, mixed>> $rows  Option rows.
	 * @param string                           $extra Secondary label key.
	 * @return array<int, array<string, mixed>>
	 */
	private static function options_for( array $rows, string $extra ): array {
		$options = array();
		foreach ( $rows as $row ) {
			$row       = self::record( $row );
			$options[] = array(
				'id'    => self::text( $row['id'] ?? '' ),
				'title' => self::text( $row['title'] ?? '' ),
				'code'  => self::text( $row[ $extra ] ?? '' ),
			);
		}
		return $options;
	}

	/**
	 * Returns the row list as plain record arrays.
	 *
	 * @param mixed $rows Candidate row list.
	 * @return array<int, array<string, mixed>>
	 */
	private static function records( mixed $rows ): array {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = self::record( $row );
		}
		return $out;
	}

	/**
	 * Returns the row as a plain array.
	 *
	 * @param mixed $row Candidate row.
	 * @return array<string, mixed>
	 */
	private static function record( mixed $row ): array {
		if ( ! is_array( $row ) ) {
			return array();
		}
		$out = array();
		foreach ( $row as $key => $value ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Returns the scalar text of one value.
	 *
	 * @param mixed $value Boundary value.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
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
		$scheme = parse_url( $clean, PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this renderer runs without WordPress loaded.
		if ( is_string( $scheme ) ) {
			return in_array( strtolower( $scheme ), array( 'http', 'https', 'mailto' ), true ) ? $clean : '';
		}
		return 1 === preg_match( '#^/(?!/)#', $clean ) ? $clean : '';
	}
}
