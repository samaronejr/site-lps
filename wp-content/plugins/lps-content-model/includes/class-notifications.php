<?php
/**
 * Faculty notification emails: review outcomes and access grants.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Post;
use WP_User;

require_once __DIR__ . '/class-contracts.php';
require_once __DIR__ . '/class-policy.php';
require_once __DIR__ . '/class-roles.php';
require_once __DIR__ . '/class-teachingpolicy.php';

/**
 * Emails the account a decision or grant landed for.
 *
 * Every message is assembled in the recipient's own locale and names only
 * that account's records — a notification never carries another account's
 * data. Delivery is whatever `wp_mail` resolves on the host; the staging
 * ops capture intercepts it there instead of sending real mail.
 */
final class Notifications {

	/**
	 * Emails the news author when the review lane decides on the submission.
	 *
	 * @param WP_Post $post     News record the decision landed on.
	 * @param string  $decision `approve` (published) or `reject` (returned with note).
	 * @param string  $note     Review note the editor wrote for the author.
	 */
	public static function news_decision( WP_Post $post, string $decision, string $note ): void {
		if ( 'lps_news' !== $post->post_type ) {
			return;
		}
		$recipient = get_user_by( 'id', (int) $post->post_author );
		if ( ! $recipient instanceof WP_User ) {
			return;
		}
		$locale    = self::locale_for_user( (int) $recipient->ID );
		$english   = 'en' === $locale;
		$title     = '' !== $post->post_title ? $post->post_title : ( $english ? 'Untitled' : 'Sem título' );
		$dashboard = self::dashboard_url( $locale );
		$greet     = $english ? "Hello {$recipient->display_name}," : "Olá, {$recipient->display_name}!";
		$sign      = $english ? 'The LPS editorial team' : 'Equipe editorial do LPS';
		if ( 'reject' === $decision ) {
			$subject = $english ? "News item returned by review: {$title}" : "Notícia devolvida pela revisão: {$title}";
			$body    = "{$greet}\n\n"
				. ( $english ? "Your news item \"{$title}\" was returned by the editorial review with this note:" : "Sua notícia \"{$title}\" foi devolvida pela revisão editorial com esta nota:" )
				. "\n\n{$note}\n\n"
				. ( $english ? 'Fix it and resubmit from the dashboard:' : 'Corrija e reenvie pelo painel:' )
				. " {$dashboard}\n\n— {$sign}";
		} else {
			$subject = $english ? "News item published: {$title}" : "Notícia publicada: {$title}";
			$body    = "{$greet}\n\n"
				. ( $english ? "Your news item \"{$title}\" was approved and is now public." : "Sua notícia \"{$title}\" foi aprovada e está pública." )
				. "\n\n"
				. ( $english ? 'See it on the dashboard:' : 'Veja no painel:' )
				. " {$dashboard}\n\n— {$sign}";
		}
		self::send( $recipient, $subject, $body );
	}

	/**
	 * Emails the account a persisted scope grant was registered for.
	 *
	 * @param int                   $target_user_id Account receiving the grant.
	 * @param array<string, scalar> $grant          Persisted grant context (`scope`, `offering_id`, `role`, `expires_at`).
	 */
	public static function scope_granted( int $target_user_id, array $grant ): void {
		$recipient = get_user_by( 'id', $target_user_id );
		if ( ! $recipient instanceof WP_User ) {
			return;
		}
		$locale    = self::locale_for_user( $target_user_id );
		$english   = 'en' === $locale;
		$scope     = self::scope_label( $grant, $locale );
		$role      = Policy::scalar_string( $grant['role'] ?? '' );
		$clause    = '' !== $role ? self::role_clause( $role, $locale ) : '';
		$expires   = Policy::scalar_string( $grant['expires_at'] ?? '' );
		$detail    = '' !== $expires
			? ( $english ? ", valid until {$expires}" : ", válido até {$expires}" )
			: '';
		$dashboard = self::dashboard_url( $locale );
		$greet     = $english ? "Hello {$recipient->display_name}," : "Olá, {$recipient->display_name}!";
		$sign      = $english ? 'The LPS editorial team' : 'Equipe editorial do LPS';
		$subject   = $english ? "Access granted: {$scope}" : "Acesso liberado: {$scope}";
		$body      = "{$greet}\n\n"
			. ( $english ? "A new access was granted to your account: {$scope}{$clause}{$detail}." : "Um novo acesso foi liberado na sua conta: {$scope}{$clause}{$detail}." )
			. "\n\n"
			. ( $english ? 'Your tasks are on the dashboard:' : 'Suas tarefas estão no painel:' )
			. " {$dashboard}\n\n— {$sign}";
		self::send( $recipient, $subject, $body );
	}

	/**
	 * Returns the notification locale of one account.
	 *
	 * The account's linked person record carries the editorial `_lps_locale`
	 * used everywhere else on the site; absent that binding the account's own
	 * WordPress locale decides, defaulting to the site's primary pt-BR.
	 *
	 * @param int $user_id Account ID.
	 */
	public static function locale_for_user( int $user_id ): string {
		$person_id = Policy::sanitize_integer( get_user_meta( $user_id, Roles::PERSON_META, true ) );
		if ( 0 < $person_id ) {
			$locale = Policy::scalar_string( get_post_meta( $person_id, '_lps_locale', true ) );
			if ( in_array( $locale, array( 'pt-br', 'en' ), true ) ) {
				return $locale;
			}
		}
		return str_starts_with( get_user_locale( $user_id ), 'en' ) ? 'en' : 'pt-br';
	}

	/**
	 * Sends one plain-text notification to the account's own email address.
	 *
	 * @param WP_User $recipient Account receiving the mail.
	 * @param string  $subject   Localized subject line.
	 * @param string  $body      Localized plain-text body.
	 */
	private static function send( WP_User $recipient, string $subject, string $body ): bool {
		return wp_mail( $recipient->user_email, $subject, $body, self::headers() );
	}

	/**
	 * Returns the From header following the site's configured public contact.
	 *
	 * The institutional `public_contact` setting is the site's published
	 * address; `admin_email` is the fallback WordPress uses for its own
	 * notices. An empty result leaves `wp_mail` on its default envelope.
	 *
	 * @return array<int, string>
	 */
	private static function headers(): array {
		$settings = get_option( Contracts::OPTION_NAME, array() );
		$address  = is_array( $settings ) ? Policy::scalar_string( $settings['public_contact'] ?? '' ) : '';
		if ( '' === $address || false === is_email( $address ) ) {
			$address = Policy::scalar_string( get_option( 'admin_email', '' ) );
		}
		if ( '' === $address || false === is_email( $address ) ) {
			return array();
		}
		$name = Policy::scalar_string( get_option( 'blogname', '' ) );
		return array( 'From: ' . ( '' !== $name ? "{$name} <{$address}>" : $address ) );
	}

	/**
	 * Returns the dashboard root URL of one locale.
	 *
	 * The two roots are the frozen route segments the theme's dashboard
	 * router owns; the email only needs the entry point, never a deep link.
	 *
	 * @param string $locale Supported locale slug.
	 */
	private static function dashboard_url( string $locale ): string {
		return home_url( 'en' === $locale ? '/en/dashboard/' : '/pt-br/painel/' );
	}

	/**
	 * Returns the human-readable scope name of one grant.
	 *
	 * @param array<string, scalar> $grant  Persisted grant context.
	 * @param string                $locale Supported locale slug.
	 */
	private static function scope_label( array $grant, string $locale ): string {
		$english = 'en' === $locale;
		$scope   = Policy::scalar_string( $grant['scope'] ?? '' );
		if ( 'news' === $scope ) {
			return $english ? 'news submissions' : 'envio de notícias';
		}
		if ( 'offering' === $scope ) {
			$offering_id = Policy::sanitize_integer( $grant['offering_id'] ?? 0 );
			$title       = 0 < $offering_id ? Policy::scalar_string( get_post_field( 'post_title', $offering_id ) ) : '';
			if ( '' === $title ) {
				$title = (string) $offering_id;
			}
			return $english ? "the offering \"{$title}\"" : "a oferta \"{$title}\"";
		}
		return '' !== $scope ? $scope : ( $english ? 'the dashboard' : 'o painel' );
	}

	/**
	 * Returns the localized "as {role}" clause of one grant, or empty.
	 *
	 * @param string $role   Scoped role key.
	 * @param string $locale Supported locale slug.
	 */
	private static function role_clause( string $role, string $locale ): string {
		$english = 'en' === $locale;
		$label   = match ( $role ) {
			'lead'      => $english ? 'lead' : 'responsável',
			'co-teacher' => $english ? 'co-teacher' : 'co-docente',
			'assistant' => $english ? 'assistant' : 'assistente',
			'professor' => $english ? 'professor' : 'docente',
			'delegate'  => $english ? 'delegate' : 'delegado',
			default     => '',
		};
		return '' === $label ? '' : ( $english ? ", as {$label}" : ", como {$label}" );
	}
}
