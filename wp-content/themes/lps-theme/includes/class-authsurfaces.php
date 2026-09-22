<?php
/**
 * Renderers for the branded sign-in surface.
 *
 * The laboratory publishes its own sign-in page instead of sending professors
 * and administrators to the WordPress default form: the surface belongs to the
 * institutional design system, and it has to say who may sign in, where an
 * account comes from, and what happens with two-step verification.
 *
 * Presentation only, and deliberately so: authentication itself stays in core.
 * The form posts the core field names (`log`, `pwd`, `rememberme`,
 * `redirect_to`) to `AuthRoutes`, which calls `wp_signon()`. That keeps every
 * existing guarantee — the `authenticate` filter chain, the `wp_login_failed`
 * and `wp_login` actions the audit ledger records, and the content-model
 * capability stripping that enforces two-step verification — because the
 * credentials are verified by core and not by this surface.
 *
 * The renderers take plain values (never a user object) so they run in unit
 * tests without WordPress loaded, and they return escaped markup only.
 *
 * @package LPS\Theme
 */

declare(strict_types=1);

namespace LPS\Theme;

/** Renders the sign-in document and its states. */
final class AuthSurfaces {

	/**
	 * Renders the complete sign-in document.
	 *
	 * `noindex, nofollow` and `private, no-store` are set by the route: a form
	 * that receives credentials must never be cached, and it is not a search
	 * result.
	 *
	 * @param string               $locale Supported locale slug.
	 * @param array<string, mixed> $state  Surface state (`error`, `attempted`, `redirect_to`, `signed_in`, `user_name`, `user_role`, `can_admin`).
	 */
	public static function document( string $locale, array $state ): string {
		$english = 'en' === $locale;
		$title   = $english ? 'Sign in' : 'Entrar';
		$header  = Shell::header_markup( $locale, Shell::signin_path( $locale ) );
		$footer  = Shell::footer_markup( $locale );
		$css     = function_exists( 'get_theme_file_uri' ) ? get_theme_file_uri( 'assets/css/theme.css' ) : '';
		$version = function_exists( 'wp_get_theme' ) ? (string) wp_get_theme()->get( 'Version' ) : '';
		$css_url = '' !== $css ? $css . ( '' !== $version ? '?ver=' . rawurlencode( $version ) : '' ) : '';
		return '<!DOCTYPE html><html lang="' . self::esc( self::bcp47( $locale ) ) . '"><head>'
			. '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<title>' . self::esc( $title . ' — LPS' ) . '</title>'
			. ( '' !== $css_url ? '<link rel="stylesheet" href="' . self::esc( $css_url ) . '">' : '' ) // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This route renders a whole standalone document; wp_enqueue_style has no pipeline to attach to.
			. '</head><body class="lps-signin-page">'
			. $header
			. '<main id="lps-main" class="lps-main-content lps-page-grid lps-signin-main">'
			. self::body( $locale, $state )
			. '</main>'
			. $footer
			. '</body></html>';
	}

	/**
	 * Renders the sign-in surface body.
	 *
	 * @param string               $locale Supported locale slug.
	 * @param array<string, mixed> $state  Surface state.
	 */
	public static function body( string $locale, array $state ): string {
		if ( ! empty( $state['signed_in'] ) ) {
			return self::header_block( $locale ) . self::signed_in( $locale, $state );
		}
		return self::header_block( $locale )
			. '<div class="lps-signin-grid">'
			. self::form( $locale, $state )
			. self::audience( $locale )
			. '</div>';
	}

	/**
	 * Renders the page header of the surface.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function header_block( string $locale ): string {
		$english = 'en' === $locale;
		return '<div class="lps-page-header lps-signin-header"><div class="lps-page-header-inner">'
			. '<p class="lps-kicker">' . self::esc( $english ? 'Restricted area' : 'Área restrita' ) . '</p>'
			. '<h1 class="lps-page-title">' . self::esc( $english ? 'Sign in' : 'Entrar no site' ) . '</h1>'
			. '<p class="lps-lead">' . self::esc(
				$english
					? 'Restricted area for the laboratory professors, editorial team and site administrators.'
					: 'Área restrita para os professores do laboratório, a equipe editorial e os administradores do site.'
			) . '</p>'
			. '</div></div>';
	}

	/**
	 * Renders the credential form.
	 *
	 * @param string               $locale Supported locale slug.
	 * @param array<string, mixed> $state  Surface state.
	 */
	private static function form( string $locale, array $state ): string {
		$english   = 'en' === $locale;
		$action    = Shell::signin_path( $locale );
		$redirect  = self::text( $state['redirect_to'] ?? '' );
		$attempted = self::text( $state['attempted'] ?? '' );
		$lost      = function_exists( 'wp_lostpassword_url' )
			? wp_lostpassword_url( $action )
			: '/wp-login.php?action=lostpassword';
		$notice    = self::notice( $locale, self::text( $state['error'] ?? '' ) );
		$aria      = '' !== $notice ? ' aria-describedby="lps-signin-error"' : '';
		return '<section class="lps-signin" aria-labelledby="lps-signin-title">'
			. '<h2 id="lps-signin-title">' . self::esc( $english ? 'Institutional account access' : 'Acesso com conta institucional' ) . '</h2>'
			. $notice
			. '<form class="lps-signin-form lps-dashboard-form" method="post" action="' . self::esc( $action ) . '">'
			. '<input type="hidden" name="redirect_to" value="' . self::esc( $redirect ) . '">'
			. ( function_exists( 'wp_nonce_field' ) ? wp_nonce_field( 'lps_signin', '_lps_signin_nonce', true, false ) : '' )
			. '<p class="lps-field"><label for="lps-signin-user">'
			. self::esc( $english ? 'Username or e-mail' : 'Usuário ou e-mail' )
			. '</label><input id="lps-signin-user" name="log" type="text" value="' . self::esc( $attempted ) . '" autocomplete="username" autocapitalize="none" spellcheck="false" required' . $aria . '></p>'
			. '<p class="lps-field"><label for="lps-signin-pass">'
			. self::esc( $english ? 'Password' : 'Senha' )
			. '</label><input id="lps-signin-pass" name="pwd" type="password" autocomplete="current-password" required' . $aria . '></p>'
			. '<p class="lps-checkbox"><input id="lps-signin-remember" name="rememberme" type="checkbox" value="forever"><label for="lps-signin-remember">'
			. self::esc( $english ? 'Keep me signed in on this browser' : 'Manter conectado neste navegador' )
			. '</label></p>'
			. '<div class="lps-button-row"><button class="lps-button lps-button-primary" type="submit">'
			. self::esc( $english ? 'Sign in' : 'Entrar' )
			. '</button><a class="lps-button lps-button-quiet" href="' . self::esc( $lost ) . '">'
			. self::esc( $english ? 'I forgot my password' : 'Esqueci minha senha' )
			. '</a></div>'
			. '<p class="lps-field-hint">' . self::esc(
				$english
					? 'The password is verified by WordPress itself, and it is never stored by this site.'
					: 'A senha é verificada pelo próprio WordPress e nunca é armazenada por este site.'
			) . '</p>'
			. '</form>'
			. self::google_block( $locale, $redirect )
			. '</section>';
	}

	/**
	 * Renders the Google Workspace entry, when the OAuth credentials exist.
	 *
	 * The button is a plain link to the OAuth endpoint — the state that binds
	 * the attempt lives in a transient, so a static link is the whole
	 * affordance and the flow survives a cookieless page cache.
	 *
	 * @param string $locale    Supported locale slug.
	 * @param string $redirect  Requested post-login destination.
	 */
	private static function google_block( string $locale, string $redirect ): string {
		if ( ! class_exists( 'LPS\Theme\GoogleOauth' ) || ! GoogleOauth::configured() ) {
			return '';
		}
		$english = 'en' === $locale;
		return '<div class="lps-sso" aria-label="'
			. self::esc( $english ? 'Google Workspace sign-in' : 'Entrar com Google Workspace' )
			. '"><p class="lps-sso-rule"><span>'
			. self::esc( $english ? 'or' : 'ou' )
			. '</span></p><a class="lps-button lps-button-ghost lps-sso-google" href="'
			. self::esc( GoogleOauth::start_url( $locale, $redirect ) )
			. '"><svg class="lps-sso-icon" viewBox="0 0 18 18" aria-hidden="true" focusable="false">'
			. '<path d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z" fill="#4285F4"/>'
			. '<path d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z" fill="#34A853"/>'
			. '<path d="M3.97 10.71a5.4 5.4 0 0 1 0-3.42V4.96H.96a9 9 0 0 0 0 8.08l3.01-2.33z" fill="#FBBC05"/>'
			. '<path d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.59C13.46.9 11.43 0 9 0A9 9 0 0 0 .96 4.96l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z" fill="#EA4335"/>'
			. '</svg><span>'
			. self::esc( $english ? 'Sign in with Google' : 'Entrar com Google' )
			. '</span></a><p class="lps-field-hint">'
			. self::esc(
				$english
					? 'Open to accounts on the @lps.ufrj.br domain that already exist here.'
					: 'Aberto a contas do domínio @lps.ufrj.br que já existam aqui.'
			)
			. '</p></div>';
	}

	/**
	 * Renders the failure notice, if any.
	 *
	 * The message is deliberately the same for an unknown account and a wrong
	 * password: a differentiated message would turn the form into an account
	 * enumeration oracle. The hint below it says so, so the shared wording reads
	 * as policy rather than as a vague error.
	 *
	 * @param string $locale Supported locale slug.
	 * @param string $error  Error key from the route (`credentials` or `empty`).
	 */
	private static function notice( string $locale, string $error ): string {
		if ( '' === $error ) {
			return '';
		}
		$english = 'en' === $locale;
		if ( str_starts_with( $error, 'sso' ) ) {
			return self::sso_notice( $locale, $error );
		}
		$title = 'empty' === $error
			? ( $english ? 'Enter your username and password' : 'Informe usuário e senha' )
			: ( $english ? 'It was not possible to sign in' : 'Não foi possível entrar' );
		$body  = $english
			? 'The username or the password does not match an account. For privacy, an unknown account and a wrong password return the same message.'
			: 'O usuário ou a senha não correspondem a uma conta. Por privacidade, usuário inexistente e senha incorreta retornam a mesma mensagem.';
		return '<div class="lps-alert lps-alert-error" id="lps-signin-error" role="alert"><p><strong>'
			. self::esc( $title ) . '</strong> ' . self::esc( $body ) . '</p></div>';
	}

	/**
	 * Renders the Google-flow failure notice for one error key.
	 *
	 * Each key names a distinct, actionable state — an expired attempt, an
	 * out-of-domain Google account, an identity with no matching site
	 * account — so the copy tells the visitor what to do rather than
	 * echoing a protocol error. Failures that would reveal whether an
	 * account exists collapse into the generic key.
	 *
	 * @param string $locale Supported locale slug.
	 * @param string $error  Error key sent by the OAuth route.
	 */
	private static function sso_notice( string $locale, string $error ): string {
		$english  = 'en' === $locale;
		$messages = array(
			'sso'             => $english
				? 'Sign-in with Google failed. Try again — if it keeps failing, use your password or ask the team.'
				: 'Não foi possível entrar com Google. Tente de novo — se persistir, use sua senha ou fale com a equipe.',
			'sso_domain'      => $english
				? 'Google sign-in only accepts accounts on the @lps.ufrj.br domain.'
				: 'O acesso com Google aceita apenas contas do domínio @lps.ufrj.br.',
			'sso_unlinked'    => $english
				? 'No account on this site matches that Google e-mail yet. Ask a site administrator to create it, or sign in with your password.'
				: 'Nenhuma conta deste site corresponde a esse e-mail Google ainda. Peça a um administrador para criá-la, ou entre com sua senha.',
			'sso_state'       => $english
				? 'That sign-in attempt expired. Start again and complete the Google step without going back.'
				: 'A tentativa de login expirou. Comece de novo e conclua a etapa do Google sem voltar.',
			'sso_unavailable' => $english
				? 'Google sign-in is not configured on this site yet. Use your password.'
				: 'O acesso com Google ainda não está configurado neste site. Use sua senha.',
		);
		$body     = $messages[ $error ] ?? $messages['sso'];
		$title    = $english ? 'It was not possible to sign in' : 'Não foi possível entrar';
		return '<div class="lps-alert lps-alert-error" id="lps-signin-error" role="alert"><p><strong>'
			. self::esc( $title ) . '</strong> ' . self::esc( $body ) . '</p></div>';
	}

	/**
	 * Renders the signed-in state.
	 *
	 * A signed-in visitor never sees the form again: an empty form next to a
	 * valid session reads as a failed sign-in and invites a second, pointless
	 * submission.
	 *
	 * @param string               $locale Supported locale slug.
	 * @param array<string, mixed> $state  Surface state.
	 */
	private static function signed_in( string $locale, array $state ): string {
		$english = 'en' === $locale;
		$name    = self::text( $state['user_name'] ?? '' );
		$role    = self::text( $state['user_role'] ?? '' );
		$logout  = function_exists( 'wp_logout_url' ) ? wp_logout_url( Shell::signin_path( $locale ) ) : '/wp-login.php?action=logout';
		$links   = '<li><a href="' . self::esc( Shell::member_path( $locale ) ) . '">'
			. self::esc( $english ? 'My area' : 'Minha área' ) . '</a></li>'
			. '<li><a href="' . self::esc( self::dashboard_path( $locale ) ) . '">'
			. self::esc( $english ? 'Task dashboard' : 'Painel de tarefas' ) . '</a></li>';
		if ( ! empty( $state['can_admin'] ) ) {
			$links .= '<li><a href="' . self::esc( self::admin_url() ) . '">'
				. self::esc( $english ? 'WordPress administration' : 'Administração do WordPress' ) . '</a></li>';
		}
		return '<section class="lps-signin lps-signin-state" aria-labelledby="lps-signin-state-title">'
			. '<h2 id="lps-signin-state-title">' . self::esc( $english ? 'You are already signed in' : 'Você já está conectado' ) . '</h2>'
			. '<p class="lps-signin-identity">' . self::esc( $name )
			. ( '' !== $role ? ' <span class="lps-meta">' . self::esc( $role ) . '</span>' : '' ) . '</p>'
			. '<ul class="lps-link-list">' . $links . '</ul>'
			. '<p class="lps-field-hint">' . self::esc(
				$english
					? 'To sign in with another account, sign out first.'
					: 'Para entrar com outra conta, saia primeiro.'
			) . '</p>'
			. '<p><a class="lps-button lps-button-ghost" href="' . self::esc( $logout ) . '">'
			. self::esc( $english ? 'Sign out' : 'Sair' ) . '</a></p>'
			. '</section>';
	}

	/**
	 * Renders who may sign in and where an account comes from.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function audience( string $locale ): string {
		$english = 'en' === $locale;
		$rows    = $english
			? array(
				array( 'Professors', 'Publish the subjects you teach, the classes of each offering and the notes and material your students download — all of it on your own page.' ),
				array( 'Editorial team', 'Publish news, events and opportunities inside the collections you are assigned to.' ),
				array( 'Administrators', 'Administer the site, review submissions and manage accounts.' ),
			)
			: array(
				array( 'Professores', 'Publicam as disciplinas que lecionam, as aulas de cada oferta e as notas e materiais que os estudantes baixam — tudo na própria página.' ),
				array( 'Equipe editorial', 'Publicam notícias, eventos e oportunidades nas coleções às quais estão designados.' ),
				array( 'Administradores', 'Administram o site, revisam submissões e gerenciam contas.' ),
			);
		$items   = '';
		foreach ( $rows as $row ) {
			$items .= '<li class="lps-signin-audience-item"><h3>' . self::esc( $row[0] ) . '</h3><p>' . self::esc( $row[1] ) . '</p></li>';
		}
		$secretariat = 'secretaria@lps.ufrj.br';
		return '<section class="lps-signin-audience" aria-labelledby="lps-signin-audience-title">'
			. '<h2 id="lps-signin-audience-title">' . self::esc( $english ? 'Who signs in here' : 'Quem entra por aqui' ) . '</h2>'
			. '<ul class="lps-signin-audience-list">' . $items . '</ul>'
			. '<div class="lps-signin-note"><h3>' . self::esc( $english ? 'Accounts and security' : 'Contas e segurança' ) . '</h3>'
			. '<p>' . self::esc(
				$english
					? 'Accounts are issued by the laboratory secretariat and are individual — never shared.'
					: 'As contas são abertas pela secretaria do laboratório e são individuais — nunca compartilhadas.'
			) . ' <a class="lps-breakable" href="mailto:' . self::esc( $secretariat ) . '">' . self::esc( $secretariat ) . '</a></p>'
			. '<p>' . self::esc(
				$english
					? 'Publishing roles require two-step verification: an account without it keeps its access but loses the privilege to publish.'
					: 'Perfis com permissão de publicação exigem verificação em duas etapas: uma conta sem ela mantém o acesso, mas perde o privilégio de publicar.'
			) . '</p>'
			. '</div></section>';
	}

	/**
	 * Resolves the task-dashboard path without requiring the route class.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function dashboard_path( string $locale ): string {
		return class_exists( DashboardRoutes::class ) ? DashboardRoutes::dashboard_path( $locale ) : '/';
	}

	/** Returns the administration URL, or the site root without WordPress. */
	private static function admin_url(): string {
		return function_exists( 'admin_url' ) ? admin_url() : '/wp-admin/';
	}

	/**
	 * Returns the BCP47 language tag of a supported locale.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function bcp47( string $locale ): string {
		return 'en' === $locale ? 'en' : 'pt-BR';
	}

	/**
	 * Returns a string value from mixed state input.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function text( mixed $value ): string {
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Escapes text for an HTML context.
	 *
	 * @param string $value Raw text.
	 */
	private static function esc( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
