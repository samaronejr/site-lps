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

if ( ! class_exists( SeoSurfaces::class ) ) {
	require_once __DIR__ . '/class-seosurfaces.php';
}

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
		$header  = Shell::header_markup(
			$locale,
			Shell::signin_path( $locale ),
			array(
				'pt-br' => Shell::signin_path( 'pt-br' ),
				'en'    => Shell::signin_path( 'en' ),
			)
		);
		$footer  = Shell::footer_markup( $locale );
		$css     = function_exists( 'get_theme_file_uri' ) ? get_theme_file_uri( 'assets/css/theme.css' ) : '';
		$version = function_exists( 'wp_get_theme' ) ? (string) wp_get_theme()->get( 'Version' ) : '';
		$file    = function_exists( 'get_theme_file_path' ) ? get_theme_file_path( 'assets/css/theme.css' ) : '';
		if ( '' !== $file && is_file( $file ) ) {
			$version .= '.' . (string) filemtime( $file );
		}
		$css_url = '' !== $css ? $css . ( '' !== $version ? '?ver=' . rawurlencode( $version ) : '' ) : '';
		return '<!DOCTYPE html><html lang="' . self::esc( self::bcp47( $locale ) ) . '"><head>'
			. '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<title>' . self::esc( $title . ' — LPS' ) . '</title>'
			. SeoSurfaces::icon_links()
			. ( '' !== $css_url ? '<link rel="stylesheet" href="' . self::esc( $css_url ) . '">' : '' ) // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This route renders a whole standalone document; wp_enqueue_style has no pipeline to attach to.
			. '</head><body class="lps-signin-page">'
			. $header
			. '<main id="lps-main" class="lps-main-content">'
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
			. '<div class="lps-page-grid"><section class="lps-section lps-section--flush" aria-labelledby="signin-form">'
			. '<div class="lps-signin-layout">'
			. self::form( $locale, $state )
			. self::audience( $locale )
			. '</div></section></div>';
	}

	/**
	 * Renders the page header of the surface.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function header_block( string $locale ): string {
		$english = 'en' === $locale;
		$crumb   = '<nav class="lps-breadcrumbs lps-page-grid" aria-label="'
			. self::esc( $english ? 'Breadcrumb' : 'Trilha de navegação' ) . '"><ol><li><a href="'
			. self::esc( $english ? '/en/' : '/pt-br/' ) . '">'
			. self::esc( $english ? 'Home' : 'Início' )
			. '</a></li><li aria-current="page">'
			. self::esc( $english ? 'Sign in' : 'Entrar' ) . '</li></ol></nav>';
		return $crumb
			. '<div class="lps-page-header lps-signin-header"><div class="lps-page-header-inner lps-page-grid">'
			. '<p class="lps-kicker">' . self::esc( $english ? 'Restricted access' : 'Acesso restrito' ) . '</p>'
			. '<h1 class="lps-page-title">' . self::esc( $english ? 'Sign in' : 'Entrar no site' ) . '</h1>'
			. '<p class="lps-lead">' . self::esc(
				$english
					? 'The restricted area is for professors, laboratory staff and site administrators. Access is individual, requires a second factor for privileged roles and is recorded for audit.'
					: 'A área restrita é destinada a professores, à equipe do laboratório e aos administradores do site. O acesso é individual, exige segundo fator para papéis privilegiados e fica registrado para auditoria.'
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
		$google    = self::google_button( $locale, $redirect );
		return '<form class="lps-signin-card" id="signin" method="post" action="' . self::esc( $action ) . '">'
			. '<h2 class="lps-card-title" id="signin-form">' . self::esc( $english ? 'Credentials' : 'Identificação' ) . '</h2>'
			. $notice
			. '<input type="hidden" name="redirect_to" value="' . self::esc( $redirect ) . '">'
			. ( function_exists( 'wp_nonce_field' ) ? wp_nonce_field( 'lps_signin', '_lps_signin_nonce', true, false ) : '' )
			. '<p class="lps-field"><label for="lps-signin-user">'
			. self::esc( $english ? 'Username or e-mail' : 'Usuário ou e-mail' )
			. '</label><input id="lps-signin-user" name="log" type="text" value="' . self::esc( $attempted ) . '" autocomplete="username" autocapitalize="none" spellcheck="false" required' . $aria . '></p>'
			. '<p class="lps-field"><label for="lps-signin-pass">'
			. self::esc( $english ? 'Password' : 'Senha' )
			. '</label><input id="lps-signin-pass" name="pwd" type="password" autocomplete="current-password" required' . $aria . '></p>'
			. '<div class="lps-signin-meta"><p class="lps-checkbox"><input id="lps-signin-remember" name="rememberme" type="checkbox" value="forever"><label for="lps-signin-remember">'
			. self::esc( $english ? 'Keep me signed in on this device' : 'Manter a sessão neste navegador' )
			. '</label></p><a class="lps-meta" href="' . self::esc( $lost ) . '">'
			. self::esc( $english ? 'I forgot my password' : 'Esqueci minha senha' )
			. '</a></div>'
			. '<button class="lps-button lps-button-primary lps-signin-submit" type="submit">'
			. self::esc( $english ? 'Sign in' : 'Entrar' )
			. '</button>'
			. ( '' !== $google ? '<div class="lps-signin-divider" role="separator"><span>' . self::esc( $english ? 'or' : 'ou' ) . '</span></div>' . $google : '' )
			. '<p class="lps-field-hint">' . self::esc(
				$english
					? "Use a trusted browser: the session grants access to the laboratory's content editing."
					: 'Use sempre um navegador confiável: a sessão dá acesso à edição de conteúdo do laboratório.'
			) . '</p>'
			. '</form>';
	}

	/**
	 * Renders the Google Workspace entry, when the OAuth credentials exist.
	 *
	 * The button sits beside the credential submit inside the form's action
	 * row. It is a plain link to the OAuth endpoint — the state that binds
	 * the attempt lives in a transient, so a static link is the whole
	 * affordance and the flow survives a cookieless page cache.
	 *
	 * @param string $locale    Supported locale slug.
	 * @param string $redirect  Requested post-login destination.
	 */
	private static function google_button( string $locale, string $redirect ): string {
		if ( ! class_exists( 'LPS\Theme\GoogleOauth' ) || ! GoogleOauth::configured() ) {
			return '';
		}
		$english = 'en' === $locale;
		return '<a class="lps-button lps-button-ghost lps-sso-google" href="'
			. self::esc( GoogleOauth::start_url( $locale, $redirect ) )
			. '" aria-label="'
			. self::esc( $english ? 'Sign in with Google Workspace — @lps.ufrj.br accounts that already exist here' : 'Entrar com Google Workspace — contas @lps.ufrj.br que já existam aqui' )
			. '"><svg class="lps-sso-icon" viewBox="0 0 18 18" aria-hidden="true" focusable="false">'
			. '<path d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z" fill="#4285F4"/>'
			. '<path d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z" fill="#34A853"/>'
			. '<path d="M3.97 10.71a5.4 5.4 0 0 1 0-3.42V4.96H.96a9 9 0 0 0 0 8.08l3.01-2.33z" fill="#FBBC05"/>'
			. '<path d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.59C13.46.9 11.43 0 9 0A9 9 0 0 0 .96 4.96l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z" fill="#EA4335"/>'
			. '</svg><span>'
			. self::esc( $english ? 'Sign in with Google' : 'Entrar com Google' )
			. '</span></a>';
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
		return '<div class="lps-page-grid"><section class="lps-section lps-section--flush" aria-labelledby="lps-signin-state-title">'
			. '<div class="lps-signin-card lps-signin-state">'
			. '<h2 class="lps-card-title" id="lps-signin-state-title">' . self::esc( $english ? 'You are already signed in' : 'Você já está conectado' ) . '</h2>'
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
			. '</div></section></div>';
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
				array( 'Professors and researchers', 'Maintain their own page: the courses they teach, classes, lessons and notes. What is published appears on the professor\'s public page.' ),
				array( 'Laboratory staff', 'Maintain news, projects, publications and the material archive.' ),
				array( 'Site administrators', 'Review content, manage roles and take care of the site configuration.' ),
			)
			: array(
				array( 'Professores e pesquisadores', 'Mantêm a própria página: disciplinas que lecionam, turmas, aulas e notas. O que é publicado aparece na página pública do professor.' ),
				array( 'Equipe do laboratório', 'Mantêm notícias, projetos, publicações e o acervo de materiais.' ),
				array( 'Administradores do site', 'Revisam o conteúdo, administram papéis e cuidam da configuração do site.' ),
			);
		$items   = '';
		foreach ( $rows as $row ) {
			$items .= '<li><strong>' . self::esc( $row[0] ) . '</strong><p>' . self::esc( $row[1] ) . '</p></li>';
		}
		$help       = $english
			? array(
				'The account is created by the laboratory office, with the institutional email address.',
				'On first access, the second factor is enrolled in an authenticator app; without it the professor or administrator role is not granted.',
				'Password recovery uses the link below, on the site\'s own domain.',
				'Access problems are handled by secretaria@lps.ufrj.br; never share your password.',
			)
			: array(
				'A conta é criada pela secretaria do laboratório, com o e-mail institucional.',
				'No primeiro acesso, o segundo fator é cadastrado em um aplicativo autenticador; sem ele o papel de professor ou administrador não é concedido.',
				'A recuperação de senha é feita pelo link abaixo, no domínio do próprio site.',
				'Problemas de acesso são tratados por secretaria@lps.ufrj.br; nunca compartilhe a senha.',
			);
		$help_items = '';
		foreach ( $help as $step ) {
			$help_items .= '<li>' . self::esc( $step ) . '</li>';
		}
		return '<div class="lps-signin-side">'
			. '<h2 class="lps-kicker">' . self::esc( $english ? 'Who signs in here' : 'Quem entra por aqui' ) . '</h2>'
			. '<ul class="lps-signin-audience">' . $items . '</ul>'
			. '<h2 class="lps-kicker lps-mt-8">' . self::esc( $english ? 'First access and help' : 'Primeiro acesso e ajuda' ) . '</h2>'
			. '<ol class="lps-signin-help">' . $help_items . '</ol>'
			. '</div>';
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
