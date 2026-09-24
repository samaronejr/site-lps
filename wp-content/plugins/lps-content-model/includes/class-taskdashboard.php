<?php
/**
 * Faculty task dashboard: scoped task model, form boundary, and review queues.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

namespace LPS\ContentModel;

use WP_Error;
use WP_Post;
use WP_User;
use wpdb;

require_once __DIR__ . '/class-policy.php';
require_once __DIR__ . '/class-securitypolicy.php';
require_once __DIR__ . '/class-teachingpolicy.php';
require_once __DIR__ . '/class-teachingcontracts.php';

/**
 * Owns the authenticated task surface behind the theme's `/painel/` routes.
 *
 * The dashboard is a task interface, not a page builder: every form posts to
 * a nonce-protected `admin-post.php` action that re-checks the server-side
 * role/scope/MFA boundary and delegates to the canonical services
 * (`TeachingRecords`, `TeachingResources`, `TeachingCopy`). Navigation and
 * forms are assembled from persisted grants and capabilities only, so the
 * surface never advertises an action the server would deny.
 *
 * Profile proposals are scoped submissions stored on the person record: a
 * faculty account proposes field changes, an editor reviews them, and the
 * outcome (with the required-fix note on rejection) stays visible to the
 * proposer. News submissions follow the same submit/review/publish cycle
 * through `_lps_state` and `_lps_review_comments`.
 */
final class TaskDashboard {
	/** Person meta holding the scoped profile-proposal list. */
	public const PROPOSALS_META = '_lps_profile_proposals';

	/** Private meta carrying the latest review outcome note. */
	public const REVIEW_NOTE_META = '_lps_review_comments';

	/**
	 * Offering meta holding the public announcement stream.
	 *
	 * Avisos are a dedicated offering-scoped stream: they render immediately
	 * on the public offering page in both locales and deliberately bypass
	 * `publish_record` and its EN-variant pair gate, matching the owner's
	 * low-friction PT-first decision for notices.
	 */
	public const NOTICES_META = '_lps_offering_notices';

	/** Transient prefix for recoverable form state after a denied submit. */
	public const RECALL_PREFIX = 'lps_dash_recall_';

	/** Seconds a recoverable form state is retained. */
	public const RECALL_TTL = 900;

	/** Maximum entries the dashboard home activity card lists. */
	public const ACTIVITY_LIMIT = 8;

	/** Transient prefix for the staged news preview. */
	private const PREVIEW_PREFIX = 'lps_dash_preview_';

	/** Transient prefix for one-shot error details (file name, limits). */
	private const ERROR_DETAIL_PREFIX = 'lps_dash_errdetail_';

	/** News topic keys offered to scoped submissions. */
	private const NEWS_CATEGORIES = array( 'pessoas', 'pesquisa', 'historia', 'ensino', 'institucional', 'parceria' );

	/** Image MIME types a featured-image upload may carry. */
	private const NEWS_IMAGE_MIMES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/** Featured-image upload ceiling in bytes (15 MiB). */
	private const NEWS_IMAGE_MAX_BYTES = 15728640;

	/** Professor-facing label listing the accepted featured-image types. */
	private const NEWS_IMAGE_ALLOWED_LABEL = 'JPG, PNG, WebP';

	/** Proposal lifecycle states. */
	public const PROPOSAL_STATES = array( 'pending', 'approved', 'rejected' );

	/**
	 * Profile fields a faculty account may propose on its own person record.
	 *
	 * The allowlist is deliberately narrower than the person schema: identity,
	 * privacy and review fields stay with institutional editors.
	 *
	 * @return array<int, string>
	 */
	public static function proposal_fields(): array {
		return array(
			'post_excerpt',
			'post_content',
			'_lps_public_email',
			'_lps_orcid',
			'_lps_lattes_url',
			'_lps_scholar_url',
			'_lps_website_url',
		);
	}

	/**
	 * Returns the task entries a role may see on the dashboard.
	 *
	 * The list is derived from the real authorization boundary: scoped roles
	 * see offering tasks only while a grant exists, the news task only with a
	 * news grant, the profile task only when a person record resolves
	 * unambiguously, and the review queue only when the account holds a real
	 * review action. Nothing here grants anything — it mirrors what the
	 * server will enforce.
	 *
	 * @param string $role           Policy role.
	 * @param bool   $has_offerings  Whether at least one offering is in scope.
	 * @param bool   $has_news_scope Whether a news-scope grant exists.
	 * @param bool   $has_person     Whether a person record resolved.
	 * @param bool   $may_review     Whether the account reviews submissions.
	 * @return array<int, string>
	 */
	public static function tasks_for_role( string $role, bool $has_offerings, bool $has_news_scope, bool $has_person, bool $may_review ): array {
		$tasks = array();
		if ( $has_person ) {
			$tasks[] = 'profile';
		}
		if ( $has_offerings ) {
			$tasks[] = 'offerings';
		}
		if ( $has_news_scope ) {
			$tasks[] = 'news';
		}
		if ( $may_review ) {
			$tasks[] = 'review';
		}
		if ( in_array( $role, array( 'section-editor', 'publisher', 'administrator' ), true ) ) {
			$tasks[] = 'create-offering';
		}
		if ( 'administrator' === $role || ( 'professor' === $role && $has_person ) ) {
			$tasks[] = 'course';
		}
		return $tasks;
	}

	/**
	 * Maps stored record state to one lifecycle key for the status chip.
	 *
	 * A saved draft is never presented as a successful publication: `draft`,
	 * `in_review`, `scheduled`, `public`, `withdrawn` and `denied` are
	 * distinct keys the renderer styles separately.
	 *
	 * @param string $post_status   WordPress post status.
	 * @param string $lps_state     Governed editorial state.
	 * @param string $release_state Resource release state (resources only).
	 */
	public static function state_key( string $post_status, string $lps_state, string $release_state = '' ): string {
		if ( 'withdrawn' === $release_state ) {
			return 'withdrawn';
		}
		if ( 'scheduled' === $release_state ) {
			return 'scheduled';
		}
		if ( 'publish' === $post_status || 'published' === $lps_state ) {
			return 'public';
		}
		if ( 'in_review' === $lps_state ) {
			return 'in-review';
		}
		if ( 'lps_archived' === $post_status || 'archived' === $lps_state ) {
			return 'archived';
		}
		return 'draft';
	}

	/**
	 * Normalizes one stored proposal row; unknown keys are dropped.
	 *
	 * @param mixed $proposal Stored proposal row.
	 * @return array{id: string, fields: array<string, string>, note: string, state: string, submitted_at: string, reviewed_at: string, reviewer_id: int}
	 */
	public static function normalize_proposal( mixed $proposal ): array {
		$row    = is_array( $proposal ) ? $proposal : array();
		$fields = array();
		foreach ( is_array( $row['fields'] ?? null ) ? $row['fields'] : array() as $key => $value ) {
			$key = is_string( $key ) ? $key : '';
			if ( ! in_array( $key, self::proposal_fields(), true ) ) {
				continue;
			}
			$fields[ $key ] = Policy::scalar_string( $value );
		}
		$state = Policy::scalar_string( $row['state'] ?? '' );
		return array(
			'id'           => Policy::scalar_string( $row['id'] ?? '' ),
			'fields'       => $fields,
			'note'         => Policy::scalar_string( $row['note'] ?? '' ),
			'state'        => in_array( $state, self::PROPOSAL_STATES, true ) ? $state : 'pending',
			'submitted_at' => Policy::scalar_string( $row['submitted_at'] ?? '' ),
			'reviewed_at'  => Policy::scalar_string( $row['reviewed_at'] ?? '' ),
			'reviewer_id'  => Policy::sanitize_integer( $row['reviewer_id'] ?? 0 ),
		);
	}

	/**
	 * Normalizes the stored proposal list, dropping malformed rows.
	 *
	 * @param mixed $proposals Stored proposal list.
	 * @return array<int, array{id: string, fields: array<string, string>, note: string, state: string, submitted_at: string, reviewed_at: string, reviewer_id: int}>
	 */
	public static function normalize_proposals( mixed $proposals ): array {
		if ( ! is_array( $proposals ) ) {
			return array();
		}
		$normalized = array();
		foreach ( $proposals as $proposal ) {
			$row = self::normalize_proposal( $proposal );
			if ( '' === $row['id'] || array() === $row['fields'] ) {
				continue;
			}
			$normalized[] = $row;
		}
		return $normalized;
	}

	/**
	 * Validates proposal input against the field allowlist.
	 *
	 * At least one field must carry a value; typed fields (email, URL, ORCID)
	 * are validated so a malformed value returns a named field error instead
	 * of a silent drop.
	 *
	 * @param array<string, mixed> $input Boundary input.
	 * @return array<string, string> Field-to-error-code map.
	 */
	public static function proposal_errors( array $input ): array {
		$errors = array();
		$fields = is_array( $input['fields'] ?? null ) ? $input['fields'] : array();
		$has    = false;
		foreach ( $fields as $key => $value ) {
			$key = is_string( $key ) ? $key : '';
			if ( ! in_array( $key, self::proposal_fields(), true ) ) {
				$errors[ '' !== $key ? $key : 'fields' ] = 'lps_dashboard_field_forbidden';
				continue;
			}
			$text = Policy::scalar_string( $value );
			if ( '' === $text ) {
				continue;
			}
			$has = true;
			if ( '_lps_public_email' === $key && '' === Policy::sanitize_email( $text ) ) {
				$errors[ $key ] = 'lps_invalid_email';
			}
			if ( in_array( $key, array( '_lps_lattes_url', '_lps_scholar_url', '_lps_website_url' ), true ) && '' === Policy::sanitize_url( $text ) ) {
				$errors[ $key ] = 'lps_invalid_url';
			}
			if ( '_lps_orcid' === $key && 1 !== preg_match( '/^[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9X]{4}$/', $text ) ) {
				$errors[ $key ] = 'lps_invalid_orcid';
			}
		}
		if ( ! $has ) {
			$errors['fields'] = 'lps_dashboard_proposal_empty';
		}
		return $errors;
	}

	/**
	 * Normalizes the copy-forward team selection from form input.
	 *
	 * Only rows naming a real person ID and a controlled team role survive;
	 * the reviewed flag is a separate explicit boolean, never inferred.
	 *
	 * @param mixed $team Boundary team input (`person_id`/`role` rows).
	 * @return array<int, array{person_id: int, role: string}>
	 */
	public static function team_from_input( mixed $team ): array {
		$rows = array();
		foreach ( is_array( $team ) ? $team : array() as $member ) {
			if ( ! is_array( $member ) ) {
				continue;
			}
			$person_id = Policy::sanitize_integer( $member['person_id'] ?? 0 );
			$role      = Policy::scalar_string( $member['role'] ?? '' );
			if ( 0 >= $person_id || ! in_array( $role, TeachingContracts::TEAM_ROLES, true ) ) {
				continue;
			}
			$rows[] = array(
				'person_id' => $person_id,
				'role'      => $role,
			);
		}
		return $rows;
	}

	/**
	 * Returns the localized label of one lifecycle state key.
	 *
	 * @param string $state  State key from `state_key` or a proposal state.
	 * @param string $locale Supported locale slug.
	 */
	public static function state_label( string $state, string $locale ): string {
		$english = 'en' === $locale;
		$labels  = array(
			'draft'        => $english ? 'Draft' : 'Rascunho',
			'in-review'    => $english ? 'In review' : 'Em revisão',
			'scheduled'    => $english ? 'Scheduled' : 'Agendado',
			'public'       => $english ? 'Public' : 'Público',
			'withdrawn'    => $english ? 'Withdrawn' : 'Retirado',
			'archived'     => $english ? 'Archived' : 'Arquivado',
			'pending'      => $english ? 'Pending review' : 'Aguardando revisão',
			'approved'     => $english ? 'Approved' : 'Aprovado',
			'rejected'     => $english ? 'Rejected' : 'Rejeitado',
			'denied'       => $english ? 'Access denied' : 'Acesso negado',
			'scan-pending' => $english ? 'Scan pending' : 'Verificação pendente',
			'scan-failed'  => $english ? 'Scan failed' : 'Verificação falhou',
		);
		return $labels[ $state ] ?? $state;
	}

	/**
	 * Returns the localized label of one proposal or form field.
	 *
	 * @param string $field  Field key.
	 * @param string $locale Supported locale slug.
	 */
	public static function field_label( string $field, string $locale ): string {
		$english = 'en' === $locale;
		$labels  = array(
			'post_excerpt'           => $english ? 'Summary' : 'Resumo',
			'post_content'           => $english ? 'Body' : 'Conteúdo',
			'post_title'             => $english ? 'Title' : 'Título',
			'_lps_public_email'      => $english ? 'Public e-mail' : 'E-mail público',
			'_lps_orcid'             => 'ORCID',
			'_lps_lattes_url'        => $english ? 'Lattes URL' : 'URL do Lattes',
			'_lps_scholar_url'       => $english ? 'Scholar URL' : 'URL do Scholar',
			'_lps_website_url'       => $english ? 'Website URL' : 'URL do site',
			'_lps_canonical_date'    => $english ? 'Publication date' : 'Data de publicação',
			'_lps_anchor'            => $english ? 'Anchor' : 'Âncora',
			'_lps_position'          => $english ? 'Position' : 'Posição',
			'_lps_topic_date'        => $english ? 'Topic date' : 'Data do tópico',
			'_lps_resource_type'     => $english ? 'Resource type' : 'Tipo de material',
			'_lps_resource_language' => $english ? 'Resource language' : 'Idioma do material',
			'_lps_external_url'      => $english ? 'External URL' : 'URL externa',
			'_lps_release_state'     => $english ? 'Release state' : 'Estado de publicação',
			'_lps_release_at'        => $english ? 'Release at' : 'Publicar em',
			'_lps_schedule'          => $english ? 'Schedule' : 'Horários',
			'_lps_venue'             => $english ? 'Venue' : 'Local',
			'_lps_syllabus_snapshot' => $english ? 'Syllabus snapshot' : 'Ementa publicada',
			'_lps_course_code'       => $english ? 'Course code' : 'Código',
			'_lps_course_level'      => $english ? 'Course level' : 'Nível',
			'_lps_calendar_key'      => $english ? 'Calendar' : 'Calendário',
			'_lps_program'           => $english ? 'Program' : 'Programa',
			'_lps_prerequisites'     => $english ? 'Prerequisites' : 'Pré-requisitos',
			'_lps_syllabus'          => $english ? 'Syllabus' : 'Ementa',
			'course_code'            => $english ? 'Course code' : 'Código',
			'course_level'           => $english ? 'Course level' : 'Nível',
			'calendar_key'           => $english ? 'Calendar' : 'Calendário',
			'term_id'                => $english ? 'Term' : 'Período',
			'section'                => $english ? 'Section' : 'Turma',
			'level-undergraduate'    => $english ? 'Undergraduate' : 'Graduação',
			'level-graduate'         => $english ? 'Graduate' : 'Pós-graduação',
			'level-extension'        => $english ? 'Extension' : 'Extensão',
			'new_term_id'            => $english ? 'Target term' : 'Período de destino',
			'new_section'            => $english ? 'Target section' : 'Turma de destino',
			'team'                   => $english ? 'Teaching team' : 'Equipe docente',
			'team_reviewed'          => $english ? 'Team review' : 'Revisão da equipe',
			'prepared_by'            => $english ? 'Prepared by' : 'Preparado por',
			'note'                   => $english ? 'Review note' : 'Nota de revisão',
			'file'                   => $english ? 'File' : 'Arquivo',
			'fields'                 => $english ? 'Fields' : 'Campos',
			'body'                   => $english ? 'Announcement' : 'Aviso',
			'positions'              => $english ? 'Order' : 'Ordem',
			'offering_notes'         => $english ? 'Term notes' : 'Notas do período',
			'featured_image'         => $english ? 'Featured image' : 'Imagem destacada',
			'featured_alt'           => $english ? 'Image description (alt text and caption)' : 'Descrição da imagem (texto alternativo e legenda)',
			'news_category'          => $english ? 'Topic' : 'Tema',
			'en_title'               => $english ? 'English title' : 'Título em inglês',
			'en_excerpt'             => $english ? 'English summary' : 'Resumo em inglês',
			'en_content'             => $english ? 'English body' : 'Conteúdo em inglês',
		);
		return $labels[ $field ] ?? $field;
	}

	/**
	 * Returns the localized message for one dashboard notice code.
	 *
	 * Notices are the only cross-request channel: a successful write lands on
	 * `lps_notice`, a denied one on `lps_error` plus the typed code, and the
	 * renderer maps both to plain language that names the field and the fix.
	 *
	 * @param string $code   Stable notice or error code.
	 * @param string $locale Supported locale slug.
	 */
	public static function notice_message( string $code, string $locale ): string {
		$english  = 'en' === $locale;
		$messages = array(
			'saved'             => $english ? 'Saved. The record stays a draft until it is published.' : 'Salvo. O registro continua rascunho até ser publicado.',
			'created'           => $english ? 'Created as a draft.' : 'Criado como rascunho.',
			'course-created'    => $english ? 'Course and first offering created as drafts — publish the offering from its workspace and the course goes live with it.' : 'Disciplina e primeira oferta criadas como rascunho — publique a oferta pela área de trabalho e a disciplina entra no ar junto.',
			'published'         => $english ? 'Published. The public link is now live.' : 'Publicado. O link público está ativo.',
			'submitted'         => $english ? 'Submitted for review. An editor will decide it.' : 'Enviado para revisão. Um editor decidirá.',
			'proposal-sent'     => $english ? 'Profile proposal sent for review.' : 'Proposta de perfil enviada para revisão.',
			'proposal-approved' => $english ? 'Profile proposal approved and applied.' : 'Proposta de perfil aprovada e aplicada.',
			'proposal-rejected' => $english ? 'Profile proposal rejected; the note explains the required fix.' : 'Proposta de perfil rejeitada; a nota explica a correção necessária.',
			'reviewed'          => $english ? 'Review recorded.' : 'Revisão registrada.',
			'copied'            => $english ? 'Next-term draft created. An editor still needs to publish it.' : 'Rascunho do próximo período criado. Um editor ainda precisa publicá-lo.',
			'copy-replayed'     => $english ? 'This copy already ran; the existing draft was reused.' : 'Esta cópia já foi executada; o rascunho existente foi reutilizado.',
			'version-uploaded'  => $english ? 'File stored and version minted. Select it on a resource to publish.' : 'Arquivo armazenado e versão registrada. Selecione-a em um material para publicar.',
			'version-selected'  => $english ? 'Version selected on the resource.' : 'Versão selecionada no material.',
			'released'          => $english ? 'Material released for public download.' : 'Material liberado para download público.',
			'scheduled'         => $english ? 'Material scheduled for release.' : 'Material agendado para publicação.',
			'withdrawn'         => $english ? 'Material withdrawn from public delivery.' : 'Material retirado da entrega pública.',
			'notice-posted'     => $english ? 'Announcement posted; it is live on the public offering page.' : 'Aviso publicado; ele já está na página pública da oferta.',
			'notice-removed'    => $english ? 'Announcement removed.' : 'Aviso removido.',
			'ordered'           => $english ? 'Material order saved.' : 'Ordem dos materiais salva.',
			'logged-out'        => $english ? 'Your session ended. Sign in again to continue.' : 'Sua sessão terminou. Entre novamente para continuar.',
			'previewed'         => $english ? 'Preview generated — confirm to send the item for review.' : 'Pré-visualização gerada — confirme para enviar para revisão.',
			'preview-cancelled' => $english ? 'Preview discarded. Nothing was submitted.' : 'Pré-visualização descartada. Nada foi enviado.',
			'translated'        => $english ? 'English translation recorded and submitted for review.' : 'Tradução em inglês registrada e enviada para revisão.',
		);
		return $messages[ $code ] ?? $code;
	}

	/**
	 * Returns the localized message for one denial or validation code.
	 *
	 * @param string $code   Stable error code.
	 * @param string $locale Supported locale slug.
	 */
	public static function error_message( string $code, string $locale ): string {
		$english = 'en' === $locale;
		$upload  = self::upload_message( $code, $english, self::error_detail() );
		if ( null !== $upload ) {
			return $upload;
		}
		$messages = array(
			'lps_dashboard_field_forbidden'           => $english ? 'This field is not editable from the dashboard.' : 'Este campo não é editável pelo painel.',
			'lps_dashboard_proposal_empty'            => $english ? 'Fill at least one field before submitting.' : 'Preencha ao menos um campo antes de enviar.',
			'lps_dashboard_nonce'                     => $english ? 'The form expired. Submit it again.' : 'O formulário expirou. Envie novamente.',
			'lps_dashboard_forbidden'                 => $english ? 'Your account cannot perform this action.' : 'Sua conta não pode executar esta ação.',
			'lps_dashboard_scope'                     => $english ? 'This offering is outside your assigned scope.' : 'Esta oferta está fora do seu escopo atribuído.',
			'lps_dashboard_review_note'               => $english ? 'A rejection needs a note explaining the required fix.' : 'Uma rejeição precisa de uma nota explicando a correção necessária.',
			'lps_dashboard_upload'                    => $english ? 'The file upload failed; try again with a valid file.' : 'O envio do arquivo falhou; tente novamente com um arquivo válido.',
			'lps_dashboard_login'                     => $english ? 'Sign in to use the dashboard.' : 'Entre para usar o painel.',
			'lps_required_title'                      => $english ? 'A title is required.' : 'Um título é obrigatório.',
			'lps_required_summary'                    => $english ? 'A summary is required.' : 'Um resumo é obrigatório.',
			'lps_required_course_code'                => $english ? 'The course code is required.' : 'O código da disciplina é obrigatório.',
			'lps_required_course_level'               => $english ? 'Choose the course level.' : 'Escolha o nível da disciplina.',
			'lps_required_calendar_key'               => $english ? 'The calendar key is required.' : 'A chave de calendário é obrigatória.',
			'lps_required_term_id'                    => $english ? 'Choose the first offering term.' : 'Escolha o período da primeira oferta.',
			'lps_required_body'                       => $english ? 'Body text is required.' : 'O texto é obrigatório.',
			'lps_invalid_email'                       => $english ? 'Enter a valid e-mail address.' : 'Informe um e-mail válido.',
			'lps_invalid_url'                         => $english ? 'Enter a valid URL.' : 'Informe uma URL válida.',
			'lps_invalid_orcid'                       => $english ? 'Enter the ORCID in the 0000-0000-0000-0000 format.' : 'Informe o ORCID no formato 0000-0000-0000-0000.',
			'lps_required_section_key'                => $english ? 'A section key is required.' : 'Uma turma é obrigatória.',
			'lps_required_anchor'                     => $english ? 'A stable anchor is required.' : 'Uma âncora estável é obrigatória.',
			'lps_invalid_unit_position'               => $english ? 'Position must be a positive number.' : 'A posição deve ser um número positivo.',
			'lps_invalid_resource_type'               => $english ? 'Choose a valid material type.' : 'Escolha um tipo de material válido.',
			'lps_invalid_resource_language'           => $english ? 'Enter a valid language tag.' : 'Informe uma etiqueta de idioma válida.',
			'lps_invalid_topic_date'                  => $english ? 'Use the YYYY-MM-DD date format.' : 'Use o formato de data AAAA-MM-DD.',
			'lps_invalid_release_state'               => $english ? 'Choose a valid release state.' : 'Escolha um estado de publicação válido.',
			'lps_release_at_required'                 => $english ? 'A scheduled release needs a date and time.' : 'Uma publicação agendada precisa de data e hora.',
			'lps_resource_rights_not_approved'        => $english ? 'The rights review must be approved before release.' : 'A revisão de direitos precisa estar aprovada antes da publicação.',
			'lps_resource_accessibility_not_approved' => $english ? 'The accessibility review must be approved before release.' : 'A revisão de acessibilidade precisa estar aprovada antes da publicação.',
			'lps_resource_version_or_url_required'    => $english ? 'Attach a file version or an external URL first.' : 'Anexe uma versão de arquivo ou uma URL externa primeiro.',
			'lps_resource_version_missing'            => $english ? 'The selected version does not exist.' : 'A versão selecionada não existe.',
			'lps_resource_version_and_url_conflict'   => $english ? 'A resource is one local version or one external URL, never both.' : 'Um material é uma versão local ou uma URL externa, nunca os dois.',
			'lps_copy_forward_team_review_required'   => $english ? 'Confirm the reviewed teaching team before copying.' : 'Confirme a equipe docente revisada antes de copiar.',
			'lps_teaching_team_required'              => $english ? 'The new offering needs a teaching team with a lead.' : 'A nova oferta precisa de uma equipe docente com responsável.',
			'lps_course_team_creator_missing'         => $english ? 'Include yourself in the teaching team — professors can only create subjects they teach.' : 'Inclua você na equipe docente — professores só criam disciplinas que lecionam.',
			'lps_copy_forward_calendar_mismatch'      => $english ? 'The target term belongs to a different calendar.' : 'O período de destino pertence a outro calendário.',
			'lps_offering_identity_conflict'          => $english ? 'This term and section already exist for the course.' : 'Este período e turma já existem para a disciplina.',
			'lps_offering_course_unpublished'         => $english ? 'The linked course must be published first.' : 'A disciplina vinculada precisa ser publicada primeiro.',
			'lps_teaching_scope_required'             => $english ? 'This record is outside your assigned scope.' : 'Este registro está fora do seu escopo atribuído.',
			'lps_teaching_grant_revoked'              => $english ? 'Your grant on this offering was revoked.' : 'Sua permissão nesta oferta foi revogada.',
			'lps_teaching_grant_expired'              => $english ? 'Your grant on this offering expired.' : 'Sua permissão nesta oferta expirou.',
			'lps_teaching_action_forbidden'           => $english ? 'Your role cannot perform this action.' : 'Seu papel não pode executar esta ação.',
			'lps_teaching_publish_type_forbidden'     => $english ? 'This record type cannot be published from your scope.' : 'Este tipo de registro não pode ser publicado pelo seu escopo.',
			'lps_teaching_field_forbidden'            => $english ? 'This field is outside your editable set.' : 'Este campo está fora do seu conjunto editável.',
			'lps_mfa_required'                        => $english ? 'Multi-factor enrollment is required for this action.' : 'A verificação em duas etapas é obrigatória para esta ação.',
			'lps_invalid_state_transition'            => $english ? 'The stored state cannot move to the requested one.' : 'O estado atual não pode mudar para o solicitado.',
			'lps_teaching_publish_denied'             => $english ? 'The publish gate denied the record; fix the named fields.' : 'A publicação foi negada; corrija os campos indicados.',
			'lps_teaching_offering_invalid'           => $english ? 'The offering record does not exist.' : 'O registro da oferta não existe.',
			'lps_teaching_term_invalid'               => $english ? 'The term record does not exist.' : 'O registro do período não existe.',
			'lps_teaching_unit_invalid'               => $english ? 'The unit record does not exist.' : 'O registro da unidade não existe.',
			'lps_teaching_course_invalid'             => $english ? 'The course record does not exist.' : 'O registro da disciplina não existe.',
			'lps_teaching_locale_forbidden'           => $english ? 'This record is authored on the Portuguese authority.' : 'Este registro é editado na autoridade em português.',
			'lps_teaching_variant_exists'             => $english ? 'The English variant already exists.' : 'A variante em inglês já existe.',
			'lps_teaching_translation_required'       => $english ? 'The Portuguese authority record is required first.' : 'O registro em português é obrigatório primeiro.',
			'lps_unsafe_html'                         => $english ? 'The body contains markup the contract forbids.' : 'O texto contém marcação proibida pelo contrato.',
			'lps_immutable_published_slug'            => $english ? 'A published slug is immutable.' : 'Um slug publicado é imutável.',
			'lps_invalid_version_id'                  => $english ? 'The version identifier is malformed.' : 'O identificador da versão é inválido.',
			'lps_teaching_upload_required'            => $english ? 'Choose a file to upload.' : 'Escolha um arquivo para enviar.',
			'lps_teaching_read_failed'                => $english ? 'The uploaded file could not be read.' : 'O arquivo enviado não pôde ser lido.',
			'lps_teaching_registry_error'             => $english ? 'The version registry could not record the upload.' : 'O registro de versões não pôde gravar o envio.',
			'lps_teaching_executable_name_forbidden'  => $english ? 'This file type is not accepted.' : 'Este tipo de arquivo não é aceito.',
			'lps_storage_root_unwritable'             => $english ? 'The storage root is not writable; contact an administrator.' : 'O armazenamento não está gravável; contate um administrador.',
			'lps_required_alt'                        => $english ? 'A featured image needs its description text.' : 'A imagem destacada precisa da descrição.',
			'lps_preview_expired'                     => $english ? 'The preview expired. Submit the form again.' : 'A pré-visualização expirou. Envie o formulário novamente.',
			'lps_translation_source_invalid'          => $english ? 'The news source was not found in your account.' : 'A notícia de origem não foi encontrada na sua conta.',
			'lps_polylang_required'                   => $english ? 'The translation machinery is unavailable; contact an administrator.' : 'A infraestrutura de tradução está indisponível; contate um administrador.',
			'lps_translation_association_failed'      => $english ? 'The translation pair could not be linked; try again.' : 'O par de tradução não pôde ser associado; tente novamente.',
			'lps_translation_variant_missing'         => $english ? 'The translation variant could not be created; try again.' : 'A variante de tradução não pôde ser criada; tente novamente.',
			'lps_translation_type_mismatch'           => $english ? 'The translation records no longer match; contact an administrator.' : 'Os registros de tradução não correspondem mais; contate um administrador.',
			'lps_english_variant_required'            => $english ? 'Only an associated English variant can be reviewed.' : 'Somente uma variante em inglês associada pode ser revisada.',
			'lps_translation_review_forbidden'        => $english ? 'Your account cannot review this English variant.' : 'Sua conta não pode revisar esta variante em inglês.',
			'lps_portuguese_source_missing'           => $english ? 'The Portuguese source record is missing; contact an administrator.' : 'O registro de origem em português está ausente; contate um administrador.',
		);
		return $messages[ $code ] ?? ( $english ? 'The action was denied (' . $code . ').' : 'A ação foi negada (' . $code . ').' );
	}

	/** Registers the dashboard form handlers. */
	public static function boot(): void {
		add_action( 'admin_post_lps_dashboard_profile', array( self::class, 'handle_profile' ) );
		add_action( 'admin_post_lps_dashboard_unit', array( self::class, 'handle_unit' ) );
		add_action( 'admin_post_lps_dashboard_resource', array( self::class, 'handle_resource' ) );
		add_action( 'admin_post_lps_dashboard_version', array( self::class, 'handle_version' ) );
		add_action( 'admin_post_lps_dashboard_release', array( self::class, 'handle_release' ) );
		add_action( 'admin_post_lps_dashboard_publish', array( self::class, 'handle_publish' ) );
		add_action( 'admin_post_lps_dashboard_news', array( self::class, 'handle_news' ) );
		add_action( 'admin_post_lps_dashboard_news_translation', array( self::class, 'handle_news_translation' ) );
		add_action( 'admin_post_lps_dashboard_copy', array( self::class, 'handle_copy' ) );
		add_action( 'admin_post_lps_dashboard_review', array( self::class, 'handle_review' ) );
		add_action( 'admin_post_lps_dashboard_offering', array( self::class, 'handle_offering' ) );
		add_action( 'admin_post_lps_dashboard_offering_edit', array( self::class, 'handle_offering_edit' ) );
		add_action( 'admin_post_lps_dashboard_course', array( self::class, 'handle_course' ) );
		add_action( 'admin_post_lps_dashboard_notice', array( self::class, 'handle_notice' ) );
		add_action( 'admin_post_lps_dashboard_material', array( self::class, 'handle_material' ) );
		add_action( 'admin_post_lps_dashboard_order', array( self::class, 'handle_order' ) );
	}

	/**
	 * Returns the offering IDs covered by the account's active grants.
	 *
	 * @param int $user_id Account ID.
	 * @return array<int, int>
	 */
	public static function granted_offering_ids( int $user_id ): array {
		$ids = array();
		$now = gmdate( 'c' );
		foreach ( Roles::teaching_grants( $user_id ) as $grant ) {
			if ( 'offering' === $grant['scope'] && TeachingPolicy::grant_is_active( $grant, $now ) ) {
				$ids[] = $grant['offering_id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Returns whether the account holds an active news-scope grant.
	 *
	 * @param int $user_id Account ID.
	 */
	public static function has_news_scope( int $user_id ): bool {
		$now = gmdate( 'c' );
		foreach ( Roles::teaching_grants( $user_id ) as $grant ) {
			if ( 'news' === $grant['scope'] && TeachingPolicy::grant_is_active( $grant, $now ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolves the account's person record, or zero when ambiguous.
	 *
	 * The link is explicit, never guessed: a person record authored by the
	 * account (`post_author`) resolves directly; otherwise the person records
	 * named on the account's granted offerings are collected and only a single
	 * distinct person resolves. Two different people across grants return zero
	 * so the dashboard asks for an explicit assignment instead of guessing.
	 *
	 * @param int $user_id Account ID.
	 */
	public static function person_for_user( int $user_id ): int {
		$linked = Policy::sanitize_integer( get_user_meta( $user_id, Roles::PERSON_META, true ) );
		if ( 0 < $linked && 'lps_person' === get_post_type( $linked ) ) {
			return $linked;
		}
		$authored = array_map(
			'intval',
			(array) get_posts(
				array(
					'post_type'      => 'lps_person',
					'post_status'    => 'any',
					'author'         => $user_id,
					'posts_per_page' => 50,
					'fields'         => 'ids',
				)
			)
		);
		if ( 1 === count( $authored ) ) {
			return (int) $authored[0];
		}
		// Walk the account's grants newest-first: the person it authored on
		// the most recently assigned team is the current assignment. An
		// offering whose team holds several authored people is ambiguous and
		// skipped rather than guessed.
		$grants = Roles::teaching_grants( $user_id );
		usort(
			$grants,
			static fn( array $a, array $b ): int => strcmp( $b['granted_at'], $a['granted_at'] )
		);
		$now = gmdate( 'c' );
		foreach ( $grants as $grant ) {
			if ( 'offering' !== $grant['scope'] || ! TeachingPolicy::grant_is_active( $grant, $now ) ) {
				continue;
			}
			$offering_id = Policy::sanitize_integer( $grant['offering_id'] );
			$team        = array();
			foreach ( Relationships::for_source( $offering_id, 'teaching_team' ) as $member ) {
				$person_id = Policy::sanitize_integer( $member['target_post_id'] );
				if ( 0 < $person_id ) {
					$team[] = $person_id;
				}
			}
			$both = array_values( array_intersect( $authored, $team ) );
			if ( 1 === count( $both ) ) {
				return (int) $both[0];
			}
		}
		return 0;
	}

	/**
	 * Returns the stored proposal list for one person record.
	 *
	 * @param int $person_id Person record ID.
	 * @return array<int, array{id: string, fields: array<string, string>, note: string, state: string, submitted_at: string, reviewed_at: string, reviewer_id: int}>
	 */
	public static function proposals_for_person( int $person_id ): array {
		return self::normalize_proposals( get_post_meta( $person_id, self::PROPOSALS_META, true ) );
	}

	/**
	 * Normalizes one stored announcement row; unknown keys are dropped.
	 *
	 * @param mixed $notice Stored notice row.
	 * @return array{id: string, body: string, created_at: string, author_id: int}
	 */
	public static function normalize_notice( mixed $notice ): array {
		$row = is_array( $notice ) ? $notice : array();
		return array(
			'id'         => Policy::scalar_string( $row['id'] ?? '' ),
			'body'       => Policy::scalar_string( $row['body'] ?? '' ),
			'created_at' => Policy::scalar_string( $row['created_at'] ?? '' ),
			'author_id'  => Policy::sanitize_integer( $row['author_id'] ?? 0 ),
		);
	}

	/**
	 * Normalizes the stored announcement list, newest first.
	 *
	 * Rows missing an id, a body or a timestamp are dropped; the list is the
	 * canonical public order, so the workspace and both locale surfaces share
	 * the same newest-first stream.
	 *
	 * @param mixed $notices Stored notice list.
	 * @return array<int, array{id: string, body: string, created_at: string, author_id: int}>
	 */
	public static function normalize_notices( mixed $notices ): array {
		if ( ! is_array( $notices ) ) {
			return array();
		}
		$normalized = array();
		foreach ( $notices as $index => $notice ) {
			$row = self::normalize_notice( $notice );
			if ( '' === $row['id'] || '' === $row['body'] || '' === $row['created_at'] ) {
				continue;
			}
			$row['_seq']  = $index;
			$normalized[] = $row;
		}
		usort(
			$normalized,
			static fn( array $left, array $right ): int => 0 !== strcmp( $right['created_at'], $left['created_at'] )
				? strcmp( $right['created_at'], $left['created_at'] )
				: $right['_seq'] <=> $left['_seq']
		);
		foreach ( $normalized as $key => $row ) {
			unset( $normalized[ $key ]['_seq'] );
		}
		return $normalized;
	}

	/**
	 * Returns the public announcement stream of one offering, newest first.
	 *
	 * @param int $offering_id Offering authority record ID.
	 * @return array<int, array{id: string, body: string, created_at: string, author_id: int}>
	 */
	public static function notices_for_offering( int $offering_id ): array {
		return self::normalize_notices( get_post_meta( $offering_id, self::NOTICES_META, true ) );
	}

	/**
	 * Rewrites the notice list under an exclusive meta-row lock.
	 *
	 * Post meta offers no compare-and-swap, so concurrent posts or removals
	 * would silently overwrite each other's list. A unique `_lps_notice_lock`
	 * row (`add_post_meta` fails while it exists) serializes each
	 * read-modify-write across both supported database adapters.
	 *
	 * @param int      $offering_id Offering authority record ID.
	 * @param callable $mutate      Receives the current list, returns the replacement or null to abort the write.
	 * @return bool Whether the mutation was applied.
	 */
	private static function mutate_notices( int $offering_id, callable $mutate ): bool {
		$locked = false;
		for ( $attempt = 0; $attempt < 20; $attempt++ ) {
			if ( add_post_meta( $offering_id, '_lps_notice_lock', '1', true ) ) {
				$locked = true;
				break;
			}
			usleep( 50000 );
		}
		if ( ! $locked ) {
			return false;
		}
		$next = $mutate( self::notices_for_offering( $offering_id ) );
		if ( null !== $next ) {
			self::system_meta( $offering_id, self::NOTICES_META, $next );
		}
		delete_post_meta( $offering_id, '_lps_notice_lock' );
		return null !== $next;
	}

	/**
	 * Drops one notice from the list, or returns null when the ID is absent.
	 *
	 * @param array<int, mixed> $notices   Current notices.
	 * @param string            $notice_id Notice ID.
	 * @return array<int, mixed>|null
	 */
	private static function remove_notice( array $notices, string $notice_id ): ?array {
		$kept  = array();
		$found = false;
		foreach ( $notices as $notice ) {
			if ( is_array( $notice ) && ( $notice['id'] ?? '' ) === $notice_id ) {
				$found = true;
				continue;
			}
			$kept[] = $notice;
		}
		return $found ? $kept : null;
	}

	/**
	 * Returns the pending review queue for an editor account.
	 *
	 * News submissions waiting on `in_review` and pending profile proposals
	 * are listed together; the queue is scoped to collections the account may
	 * actually review, so a teaching-only editor never sees person proposals.
	 *
	 * @param int $user_id Account ID.
	 * @return array{news: array<int, array<string, mixed>>, proposals: array<int, array<string, mixed>>}
	 */
	public static function review_queue( int $user_id ): array {
		$queue = array(
			'news'      => array(),
			'proposals' => array(),
		);
		$user  = get_user_by( 'id', $user_id );
		$role  = $user instanceof WP_User ? Roles::policy_role( $user ) : '';
		if ( '' === $role || TeachingPolicy::is_scoped_role( $role ) ) {
			return $queue;
		}
		$assigned = Roles::assigned_collections( $user_id );
		if ( SecurityPolicy::allows( $role, 'review', 'news', $assigned ) || SecurityPolicy::allows( $role, 'publish', 'news', $assigned ) ) {
			$allowed = ! in_array( $role, array( 'contributor', 'translator', 'section-editor' ), true ) || in_array( 'news', $assigned, true );
			if ( $allowed ) {
				$posts = get_posts(
					array(
						'post_type'      => 'lps_news',
						'post_status'    => array( 'draft', 'pending' ),
						'posts_per_page' => 50,
						'fields'         => 'ids',
						'orderby'        => 'date',
						'order'          => 'DESC',
					)
				);
				foreach ( $posts as $post_id ) {
					$state = Policy::scalar_string( get_post_meta( $post_id, '_lps_state', true ) );
					if ( 'in_review' !== $state ) {
						continue;
					}
					$queue['news'][] = array(
						'id'      => (int) $post_id,
						'title'   => Policy::scalar_string( get_post_field( 'post_title', $post_id ) ),
						'author'  => Policy::sanitize_integer( get_post_field( 'post_author', $post_id ) ),
						'date'    => Policy::scalar_string( get_post_meta( $post_id, '_lps_canonical_date', true ) ),
						'summary' => Policy::scalar_string( get_post_field( 'post_excerpt', $post_id ) ),
					);
				}
			}
		}
		if ( SecurityPolicy::allows( $role, 'review', 'person', $assigned ) || SecurityPolicy::allows( $role, 'edit', 'person', $assigned ) ) {
			$allowed = ! in_array( $role, array( 'contributor', 'translator', 'section-editor' ), true ) || in_array( 'person', $assigned, true );
			if ( $allowed ) {
				$people = get_posts(
					array(
						'post_type'      => 'lps_person',
						'post_status'    => 'any',
						// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded selector list for the dashboard, not a public query.
						'posts_per_page' => 200,
						'fields'         => 'ids',
					)
				);
				foreach ( $people as $person_id ) {
					foreach ( self::proposals_for_person( (int) $person_id ) as $proposal ) {
						if ( 'pending' !== $proposal['state'] ) {
							continue;
						}
						$queue['proposals'][] = array(
							'person_id' => (int) $person_id,
							'person'    => Policy::scalar_string( get_post_field( 'post_title', $person_id ) ),
							'proposal'  => $proposal,
						);
					}
				}
			}
		}
		return $queue;
	}

	/**
	 * Assembles the recent-activity list the dashboard home shows one account.
	 *
	 * The audit ledger is the only source: it already records the account's
	 * own actions, the scope grants stored against it, and the review and
	 * publication decisions other accounts landed on its records — a parallel
	 * store would record the same events twice. Labels are resolved here in
	 * the viewer's locale; the ledger itself stays machine-keyed.
	 *
	 * @param int    $user_id Account ID.
	 * @param string $locale  Supported locale slug.
	 * @return array<int, array{label: string, occurred_at: string, at: string}>
	 */
	public static function activity_for_user( int $user_id, string $locale ): array {
		$wpdb = self::database();
		// Ownership is read straight from the posts table: get_posts would let
		// Polylang's front-end query filter hide records in the language the
		// page does not render in, and every status must count — drafts,
		// archives and all. Values are an int and esc_sql'd code constants.
		$types      = implode(
			"', '",
			array_map(
				static function ( string $type ): string {
					return Policy::scalar_string( esc_sql( $type ) );
				},
				array_keys( Contracts::post_types() )
			)
		);
		$object_ids = array_values(
			array_map(
				static function ( $id ): int {
					return Policy::sanitize_integer( $id );
				},
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- post_author is an int, post types are esc_sql'd code constants, the table name is the trusted core prefix, and the feed must read through any page/query cache like the audit ledger it renders.
				(array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_author = {$user_id} AND post_type IN ('{$types}')" )
			)
		);
		$person_id = self::person_for_user( $user_id );
		if ( 0 < $person_id ) {
			$object_ids[] = $person_id;
		}
		$rows  = Audit::entries_for_account( $user_id, $object_ids, self::ACTIVITY_LIMIT );
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::activity_item( $row, $locale );
		}
		return $items;
	}

	/**
	 * Renders one audit row as the localized label the activity card shows.
	 *
	 * @param array<string, int|string> $row    Normalized ledger row.
	 * @param string                    $locale Supported locale slug.
	 * @return array{label: string, occurred_at: string, at: string}
	 */
	private static function activity_item( array $row, string $locale ): array {
		$english = 'en' === $locale;
		$action  = Policy::scalar_string( $row['action'] ?? '' );
		$context = json_decode( Policy::scalar_string( $row['context_json'] ?? '{}' ), true );
		$context = is_array( $context ) ? $context : array();
		$title   = self::activity_title( $row );
		$base    = self::activity_label( $action, $context, $locale );
		$label   = '' !== $title && ! in_array( $action, array( 'grant-scope', 'revoke-scope' ), true )
			? "{$base}: {$title}"
			: $base;
		$stamp   = strtotime( Policy::scalar_string( $row['occurred_at'] ?? '' ) );
		return array(
			'label'       => $label,
			'occurred_at' => Policy::scalar_string( $row['occurred_at'] ?? '' ),
			'at'          => is_int( $stamp ) ? self::activity_date( $stamp, $english ) : '',
		);
	}

	/**
	 * Formats a ledger timestamp for the card in the dashboard's locale.
	 *
	 * `wp_date` translates month names through WordPress's active locale, which
	 * follows the site — not the dashboard route — so the English rendering
	 * uses the fixed month abbreviations instead of a translated format.
	 *
	 * @param int  $stamp   Unix timestamp.
	 * @param bool $english Whether the dashboard renders English.
	 */
	private static function activity_date( int $stamp, bool $english ): string {
		if ( ! $english ) {
			return Policy::scalar_string( wp_date( 'd/m/Y', $stamp ) );
		}
		$months = array( 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' );
		$month  = Policy::sanitize_integer( wp_date( 'n', $stamp ) );
		return $months[ $month - 1 ] . ' ' . Policy::scalar_string( wp_date( 'j', $stamp ) ) . ', ' . Policy::scalar_string( wp_date( 'Y', $stamp ) );
	}

	/**
	 * Maps one ledger action and its context to the human label.
	 *
	 * @param string       $action  Ledger action key.
	 * @param array<mixed> $context Decoded entry context.
	 * @param string       $locale  Supported locale slug.
	 */
	private static function activity_label( string $action, array $context, string $locale ): string {
		$english  = 'en' === $locale;
		$decision = Policy::scalar_string( $context['decision'] ?? '' );
		if ( 'grant-scope' === $action || 'revoke-scope' === $action ) {
			$scope = self::activity_scope_label( $context, $locale );
			return 'grant-scope' === $action
				? ( $english ? "Access granted — {$scope}" : "Acesso liberado — {$scope}" )
				: ( $english ? "Access revoked — {$scope}" : "Acesso revogado — {$scope}" );
		}
		if ( 'submit' === $action ) {
			return match ( $decision ) {
				'news-submission'   => $english ? 'News item sent for review' : 'Notícia enviada para revisão',
				'news-resubmission' => $english ? 'News item resent for review' : 'Notícia reenviada para revisão',
				'profile-proposal'  => $english ? 'Profile proposal sent for review' : 'Proposta de perfil enviada para revisão',
				'course-create'     => $english ? 'Course and first offering created' : 'Disciplina e primeira oferta criadas',
				default             => $english ? 'Sent for review' : 'Enviado para revisão',
			};
		}
		if ( 'review' === $action ) {
			return match ( $decision ) {
				'reject'          => $english ? 'Review returned your news item with a note' : 'Revisão devolveu sua notícia com uma nota',
				'profile-approve' => $english ? 'Profile proposal approved' : 'Proposta de perfil aprovada',
				'profile-reject'  => $english ? 'Profile proposal rejected' : 'Proposta de perfil rejeitada',
				default           => $english ? 'Sent to editorial review' : 'Enviado para revisão editorial',
			};
		}
		if ( 'create' === $action ) {
			if ( isset( $context['operation_id'] ) ) {
				return $english ? 'Next-term draft created' : 'Rascunho do próximo período criado';
			}
			return self::activity_type_label( $context, 'new', $locale );
		}
		if ( 'edit' === $action ) {
			return match ( $decision ) {
				'propagate-correction' => $english ? 'Correction propagated' : 'Correção propagada',
				default                => '_lps_version_id' === Policy::scalar_string( $context['field'] ?? '' )
					? ( $english ? 'File version selected' : 'Versão de arquivo selecionada' )
					: self::activity_type_label( $context, 'updated', $locale ),
			};
		}
		if ( 'publish' === $action ) {
			if ( 'release' === $decision ) {
				return $english ? 'Material released' : 'Material liberado';
			}
			return self::activity_state_label( $context, $locale );
		}
		if ( 'unpublish' === $action ) {
			if ( 'withdraw' === $decision ) {
				return $english ? 'Material withdrawn' : 'Material retirado';
			}
			return self::activity_state_label( $context, $locale );
		}
		return match ( $action ) {
			'archive'  => $english ? 'Archived' : 'Arquivado',
			'import'   => $english ? 'Content import' : 'Importação de conteúdo',
			'redirect' => $english ? 'Redirect changed' : 'Redirecionamento alterado',
			'settings' => $english ? 'Site settings changed' : 'Configuração do site alterada',
			default    => $english ? 'Activity recorded' : 'Atividade registrada',
		};
	}

	/**
	 * Maps a stored status transition to a "from → to" state label.
	 *
	 * @param array<mixed> $context Decoded entry context.
	 * @param string       $locale  Supported locale slug.
	 */
	private static function activity_state_label( array $context, string $locale ): string {
		$map   = array(
			'draft'        => 'draft',
			'pending'      => 'pending',
			'publish'      => 'public',
			'lps_archived' => 'archived',
			'private'      => 'draft',
			'trash'        => 'archived',
		);
		$from  = Policy::scalar_string( $context['from'] ?? '' );
		$to    = Policy::scalar_string( $context['to'] ?? '' );
		$label = self::state_label( Policy::scalar_string( $map[ $to ] ?? $to ), $locale );
		if ( '' !== $from ) {
			$before = self::state_label( Policy::scalar_string( $map[ $from ] ?? $from ), $locale );
			return 'en' === $locale ? "State changed from {$before} to {$label}" : "Estado alterado de {$before} para {$label}";
		}
		return 'en' === $locale ? "Moved to {$label}" : "Movido para {$label}";
	}

	/**
	 * Maps a create/edit context's record type to a gender-correct label.
	 *
	 * @param array<mixed> $context Decoded entry context.
	 * @param string       $form    `new` or `updated`.
	 * @param string       $locale  Supported locale slug.
	 */
	private static function activity_type_label( array $context, string $form, string $locale ): string {
		$english = 'en' === $locale;
		$new     = array(
			'lps_unit'     => $english ? 'New unit' : 'Nova unidade',
			'lps_resource' => $english ? 'New material' : 'Novo material',
			'lps_news'     => $english ? 'New news item' : 'Nova notícia',
			'lps_offering' => $english ? 'New offering' : 'Nova oferta',
			'lps_course'   => $english ? 'New course' : 'Nova disciplina',
			'lps_term'     => $english ? 'New term' : 'Novo período',
			'lps_person'   => $english ? 'New profile' : 'Novo perfil',
		);
		$updated = array(
			'lps_unit'     => $english ? 'Unit updated' : 'Unidade atualizada',
			'lps_resource' => $english ? 'Material updated' : 'Material atualizado',
			'lps_news'     => $english ? 'News item updated' : 'Notícia atualizada',
			'lps_offering' => $english ? 'Offering updated' : 'Oferta atualizada',
			'lps_course'   => $english ? 'Course updated' : 'Disciplina atualizada',
			'lps_term'     => $english ? 'Term updated' : 'Período atualizado',
			'lps_person'   => $english ? 'Profile updated' : 'Perfil atualizado',
		);
		$type    = Policy::scalar_string( $context['post_type'] ?? '' );
		$map     = 'new' === $form ? $new : $updated;
		return $map[ $type ] ?? ( $english ? ( 'new' === $form ? 'New record' : 'Record updated' ) : ( 'new' === $form ? 'Novo registro' : 'Registro atualizado' ) );
	}

	/**
	 * Maps a grant/revoke context to its scope name in the viewer's locale.
	 *
	 * @param array<mixed> $context Decoded entry context.
	 * @param string       $locale  Supported locale slug.
	 */
	private static function activity_scope_label( array $context, string $locale ): string {
		$english = 'en' === $locale;
		$scope   = Policy::scalar_string( $context['scope'] ?? '' );
		if ( 'news' === $scope ) {
			return $english ? 'news submissions' : 'envio de notícias';
		}
		if ( 'offering' === $scope ) {
			$offering_id = Policy::sanitize_integer( $context['offering_id'] ?? 0 );
			$title       = 0 < $offering_id ? Policy::scalar_string( get_post_field( 'post_title', $offering_id ) ) : '';
			return $english
				? ( '' !== $title ? "the offering \"{$title}\"" : 'an offering' )
				: ( '' !== $title ? "a oferta \"{$title}\"" : 'uma oferta' );
		}
		return '' !== $scope ? $scope : ( $english ? 'the dashboard' : 'o painel' );
	}

	/**
	 * Resolves the display title of a row's post object, or empty.
	 *
	 * Grant rows key the object by account ID rather than post ID, so they
	 * never resolve here — their label already carries the scope name.
	 *
	 * @param array<string, int|string> $row Normalized ledger row.
	 */
	private static function activity_title( array $row ): string {
		if ( in_array( Policy::scalar_string( $row['action'] ?? '' ), array( 'grant-scope', 'revoke-scope' ), true ) ) {
			return '';
		}
		$post = get_post( Policy::sanitize_integer( $row['object_id'] ?? 0 ) );
		return $post instanceof WP_Post ? $post->post_title : '';
	}

	/**
	 * Returns the initialized database adapter.
	 *
	 * @throws \RuntimeException Missing adapter.
	 */
	private static function database(): wpdb {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			throw new \RuntimeException( 'WordPress database adapter is unavailable.' );
		}
		return $wpdb;
	}

	/**
	 * Assembles the complete dashboard model for one account.
	 *
	 * @param WP_User $user   Signed-in account.
	 * @param string  $locale Supported locale slug.
	 * @return array<string, mixed>
	 */
	public static function model_for_user( WP_User $user, string $locale ): array {
		$role         = Roles::policy_role( $user );
		$offering_ids = self::granted_offering_ids( $user->ID );
		$offerings    = array();
		foreach ( $offering_ids as $offering_id ) {
			$workspace = self::offering_workspace( $offering_id, $user, $locale );
			if ( null !== $workspace ) {
				$offerings[] = $workspace;
			}
		}
		$person_id  = self::person_for_user( $user->ID );
		$news_scope = self::has_news_scope( $user->ID );
		$may_review = '' !== $role && ! TeachingPolicy::is_scoped_role( $role )
			&& ( SecurityPolicy::allows( $role, 'review' ) || SecurityPolicy::allows( $role, 'publish' ) );
		$tasks      = self::tasks_for_role( $role, array() !== $offerings, $news_scope, 0 < $person_id, $may_review );
		return array(
			'role'       => $role,
			'user'       => $user,
			'tasks'      => $tasks,
			'offerings'  => $offerings,
			'news'       => $news_scope ? self::news_for_user( $user->ID ) : array(),
			'person_id'  => $person_id,
			'proposals'  => 0 < $person_id ? self::proposals_for_person( $person_id ) : array(),
			'review'     => $may_review ? self::review_queue( $user->ID ) : array(
				'news'      => array(),
				'proposals' => array(),
			),
			'terms'      => self::published_terms(),
			'courses'    => self::published_courses(),
			'people'     => self::people_options(),
			'news_scope' => $news_scope,
			'activity'   => self::activity_for_user( $user->ID, $locale ),
			'may_review' => $may_review,
			'may_course' => self::may_course( $user ),
			'mfa'        => MFA::is_enrolled( $user->ID ),
			'mfa_needed' => SecurityPolicy::requires_mfa( $role ) && ! MFA::is_enrolled( $user->ID ),
		);
	}

	/**
	 * Assembles one offering workspace for the dashboard.
	 *
	 * Units, resources, team, identity and the reusable-version list are all
	 * resolved from persisted state; the caller's capabilities decide which
	 * actions the renderer may offer.
	 *
	 * @param int     $offering_id Offering record ID.
	 * @param WP_User $user        Signed-in account.
	 * @param string  $locale      Supported locale slug.
	 * @return array<string, mixed>|null
	 */
	public static function offering_workspace( int $offering_id, WP_User $user, string $locale ): ?array {
		$offering = get_post( $offering_id );
		if ( ! $offering instanceof WP_Post || 'lps_offering' !== $offering->post_type ) {
			return null;
		}
		$identity = TeachingRecords::offering_identity_for( $offering_id );
		$role     = Roles::policy_role( $user );
		$units    = array();
		foreach ( Relationships::reverse_for( $offering_id, 'unit_offering' ) as $row ) {
			$unit_id = Policy::sanitize_integer( $row['source_post_id'] );
			$unit    = 0 < $unit_id ? get_post( $unit_id ) : null;
			if ( ! $unit instanceof WP_Post || 'lps_unit' !== $unit->post_type || 'trash' === $unit->post_status ) {
				continue;
			}
			$unit_prepared = self::prepared_marker_for( $unit_id );
			$units[]       = array(
				'id'               => $unit_id,
				'title'            => $unit->post_title,
				'status'           => $unit->post_status,
				'state'            => self::state_key( $unit->post_status, Policy::scalar_string( get_post_meta( $unit_id, '_lps_state', true ) ) ),
				'excerpt'          => $unit->post_excerpt,
				'content'          => $unit->post_content,
				'anchor'           => Policy::scalar_string( get_post_meta( $unit_id, '_lps_anchor', true ) ),
				'position'         => Policy::sanitize_integer( get_post_meta( $unit_id, '_lps_position', true ) ),
				'topic_date'       => Policy::scalar_string( get_post_meta( $unit_id, '_lps_topic_date', true ) ),
				'prepared_by'      => $unit_prepared,
				'prepared_by_name' => self::prepared_name( $unit_prepared ),
				'edit_url'         => get_edit_post_link( $unit_id, 'raw' ),
			);
		}
		usort(
			$units,
			static fn( array $left, array $right ): int => $left['position'] <=> $right['position']
		);
		$resources = array();
		foreach ( Relationships::reverse_for( $offering_id, 'resource_offering' ) as $row ) {
			$resource_id = Policy::sanitize_integer( $row['source_post_id'] );
			$resource    = 0 < $resource_id ? get_post( $resource_id ) : null;
			if ( ! $resource instanceof WP_Post || 'lps_resource' !== $resource->post_type || 'trash' === $resource->post_status ) {
				continue;
			}
			$unit_rows         = Relationships::for_source( $resource_id, 'resource_unit' );
			$resource_prepared = self::prepared_marker_for( $resource_id );
			$resources[]       = array(
				'id'                   => $resource_id,
				'title'                => $resource->post_title,
				'status'               => $resource->post_status,
				'excerpt'              => $resource->post_excerpt,
				'sort_order'           => Policy::sanitize_integer( $row['sort_order'] ),
				'state'                => self::state_key(
					$resource->post_status,
					Policy::scalar_string( get_post_meta( $resource_id, '_lps_state', true ) ),
					Policy::scalar_string( get_post_meta( $resource_id, '_lps_release_state', true ) )
				),
				'unit_id'              => Policy::sanitize_integer( $unit_rows[0]['target_post_id'] ?? 0 ),
				'resource_type'        => Policy::scalar_string( get_post_meta( $resource_id, '_lps_resource_type', true ) ),
				'resource_language'    => Policy::scalar_string( get_post_meta( $resource_id, '_lps_resource_language', true ) ),
				'version_id'           => Policy::scalar_string( get_post_meta( $resource_id, '_lps_version_id', true ) ),
				'external_url'         => Policy::scalar_string( get_post_meta( $resource_id, '_lps_external_url', true ) ),
				'release_state'        => Policy::scalar_string( get_post_meta( $resource_id, '_lps_release_state', true ) ),
				'release_at'           => Policy::scalar_string( get_post_meta( $resource_id, '_lps_release_at', true ) ),
				'scan_state'           => Policy::scalar_string( get_post_meta( $resource_id, '_lps_scan_state', true ) ),
				'rights_review'        => Policy::scalar_string( get_post_meta( $resource_id, '_lps_rights_review', true ) ),
				'accessibility_review' => Policy::scalar_string( get_post_meta( $resource_id, '_lps_accessibility_review', true ) ),
				'prepared_by'          => $resource_prepared,
				'prepared_by_name'     => self::prepared_name( $resource_prepared ),
				'edit_url'             => get_edit_post_link( $resource_id, 'raw' ),
				'download_url'         => TeachingResources::download_url( $resource_id ),
			);
		}
		$team = array();
		foreach ( Relationships::for_source( $offering_id, 'teaching_team' ) as $member ) {
			$person_id = Policy::sanitize_integer( $member['target_post_id'] );
			$person    = 0 < $person_id ? get_post( $person_id ) : null;
			$team[]    = array(
				'person_id' => $person_id,
				'name'      => $person instanceof WP_Post ? $person->post_title : '',
				'role'      => Policy::scalar_string( $member['relationship_role'] ),
			);
		}
		$reusable = array();
		foreach ( $resources as $resource ) {
			if ( 'released' !== $resource['release_state'] || '' === $resource['version_id'] ) {
				continue;
			}
			$version = TeachingResources::version_for_id( $resource['version_id'] );
			if ( null !== $version && 'cleared' === Policy::scalar_string( $version['state'] ?? '' ) && 'clean' === Policy::scalar_string( $version['scan_verdict'] ?? '' ) ) {
				$reusable[] = array(
					'version_id' => $resource['version_id'],
					'resource'   => $resource['title'],
				);
			}
		}
		return array(
			'id'              => $offering_id,
			'title'           => $offering->post_title,
			'status'          => $offering->post_status,
			'state'           => self::state_key( $offering->post_status, Policy::scalar_string( get_post_meta( $offering_id, '_lps_state', true ) ) ),
			'temporal_status' => Policy::scalar_string( get_post_meta( $offering_id, '_lps_temporal_status', true ) ),
			'schedule'        => Policy::scalar_string( get_post_meta( $offering_id, '_lps_schedule', true ) ),
			'venue'           => Policy::scalar_string( get_post_meta( $offering_id, '_lps_venue', true ) ),
			'content'         => $offering->post_content,
			'notices'         => self::notices_for_offering( $offering_id ),
			'identity'        => $identity,
			'team'            => $team,
			'units'           => $units,
			'resources'       => $resources,
			'reusable'        => $reusable,
			'public_url'      => 'publish' === $offering->post_status ? TeachingRecords::offering_url( $offering_id, $locale ) : '',
			'edit_url'        => get_edit_post_link( $offering_id, 'raw' ),
			'can_edit'        => Roles::current_user_can_scoped_action( 'edit', 'lps_offering', $offering_id ) || Roles::current_user_can_action( 'edit', 'teaching' ),
			'can_publish'     => Roles::current_user_can_scoped_action( 'publish', 'lps_offering', $offering_id ) || Roles::current_user_can_action( 'publish', 'teaching' ),
			'can_copy'        => Roles::current_user_can_scoped_action( 'copy-forward', 'lps_offering', $offering_id ) || Roles::current_user_can_action( 'create', 'teaching' ),
			'role'            => $role,
		);
	}

	/**
	 * Returns the news items the account authored or may review.
	 *
	 * @param int $user_id Account ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function news_for_user( int $user_id ): array {
		$posts = get_posts(
			array(
				'post_type'      => 'lps_news',
				'post_status'    => array( 'draft', 'pending', 'publish', 'future' ),
				'author'         => $user_id,
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		// Delegate-prepared drafts sit in the same news lane as the professor's
		// own submissions, but only for accounts that may publish the lane —
		// adoption is a publisher's action, so a fellow delegate never lists
		// another account's private drafts. Only actionable statuses list here;
		// published items are live, not adoptable work.
		$prepared = ! self::may( 'publish', 'lps_news', 0 ) ? array() : get_posts(
			array(
				'post_type'      => 'lps_news',
				'post_status'    => array( 'draft', 'pending' ),
				'author__not_in' => array( $user_id ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Shared-lane provenance lookup; the lane is small and per-account.
				'meta_query'     => array(
					array(
						'key'     => '_lps_prepared_by',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		// A marker is only provenance when it names the draft's own author —
		// anything else was written outside the delegate stamp and stays hidden.
		$prepared = array_values(
			array_filter(
				$prepared,
				static fn( $prepared_id ) => 0 < self::prepared_marker_for( (int) $prepared_id )
			)
		);
		$items    = array();
		foreach ( array_merge( $posts, $prepared ) as $post_id ) {
			$locale = Translations::locale( (int) $post_id );
			if ( TranslationPolicy::TARGET_LOCALE === $locale ) {
				// The English variant stays bound to its Portuguese authority;
				// the author updates it through the translation task on the
				// source row, never as a separate submission.
				continue;
			}
			$variants    = Translations::variants( (int) $post_id );
			$en_id       = Policy::sanitize_integer( $variants[ TranslationPolicy::TARGET_LOCALE ] ?? 0 );
			$english     = 0 < $en_id ? get_post( $en_id ) : null;
			$prepared_by = self::prepared_marker_for( (int) $post_id );
			$items[]     = array(
				'id'                => (int) $post_id,
				'title'             => Policy::scalar_string( get_post_field( 'post_title', $post_id ) ),
				'summary'           => Policy::scalar_string( get_post_field( 'post_excerpt', $post_id ) ),
				'content'           => Policy::scalar_string( get_post_field( 'post_content', $post_id ) ),
				'status'            => (string) get_post_status( $post_id ),
				'state'             => self::state_key(
					(string) get_post_status( $post_id ),
					Policy::scalar_string( get_post_meta( $post_id, '_lps_state', true ) )
				),
				'date'              => Policy::scalar_string( get_post_meta( $post_id, '_lps_canonical_date', true ) ),
				'category'          => Policy::scalar_string( get_post_meta( $post_id, '_lps_news_category', true ) ),
				'note'              => Policy::scalar_string( get_post_meta( $post_id, self::REVIEW_NOTE_META, true ) ),
				'prepared_by'       => $prepared_by,
				'prepared_by_name'  => self::prepared_name( $prepared_by ),
				'can_resubmit'      => (int) get_post_field( 'post_author', $post_id ) === $user_id || ( 0 < $prepared_by && self::may( 'publish', 'lps_news', 0 ) ),
				'public_url'        => 'publish' === get_post_status( $post_id ) ? (string) get_permalink( $post_id ) : '',
				'edit_url'          => get_edit_post_link( $post_id, 'raw' ),
				'translation_state' => 0 === $en_id ? 'missing' : ( Translations::is_stale( $en_id ) ? 'stale' : 'reviewed' ),
				'en_id'             => $en_id,
				'en_title'          => $english instanceof WP_Post ? Policy::scalar_string( $english->post_title ) : '',
				'en_excerpt'        => $english instanceof WP_Post ? Policy::scalar_string( $english->post_excerpt ) : '',
				'en_content'        => $english instanceof WP_Post ? Policy::scalar_string( $english->post_content ) : '',
			);
		}
		return $items;
	}

	/**
	 * Returns published terms for the copy-forward target selector.
	 *
	 * @return array<int, array{id: int, title: string, label: string, starts_on: string, ends_on: string}>
	 */
	public static function published_terms(): array {
		// Newest terms first: the copy-forward target is always the upcoming
		// term, and a title sort would bury it under older same-label terms.
		$posts = get_posts(
			array(
				'post_type'      => 'lps_term',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Term ordering requires the meta key.
				'meta_key'       => '_lps_starts_on',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
			)
		);
		$terms = array();
		foreach ( $posts as $post_id ) {
			$terms[] = array(
				'id'        => (int) $post_id,
				'title'     => Policy::scalar_string( get_post_field( 'post_title', $post_id ) ),
				'label'     => Policy::scalar_string( get_post_meta( $post_id, '_lps_period_label', true ) ),
				'starts_on' => Policy::scalar_string( get_post_meta( $post_id, '_lps_starts_on', true ) ),
				'ends_on'   => Policy::scalar_string( get_post_meta( $post_id, '_lps_ends_on', true ) ),
			);
		}
		return $terms;
	}

	/**
	 * Returns published courses for the editor create-offering selector.
	 *
	 * @return array<int, array{id: int, title: string, code: string}>
	 */
	public static function published_courses(): array {
		$posts   = get_posts(
			array(
				'post_type'      => 'lps_course',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$courses = array();
		foreach ( $posts as $post_id ) {
			$courses[] = array(
				'id'    => (int) $post_id,
				'title' => Policy::scalar_string( get_post_field( 'post_title', $post_id ) ),
				'code'  => Policy::scalar_string( get_post_meta( $post_id, '_lps_course_code', true ) ),
			);
		}
		return $courses;
	}

	/**
	 * Returns published people for the team selector.
	 *
	 * @param array<int> $include_ids Extra person IDs to keep selectable.
	 * @return array<int, array{id: int, title: string}>
	 */
	public static function people_options( array $include_ids = array() ): array {
		$posts = get_posts(
			array(
				'post_type'      => 'lps_person',
				'post_status'    => 'publish',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded selector list for the dashboard, not a public query.
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		// The current team may hold unpublished people; they must stay
		// selectable so the reviewed-team submit is not blocked by the
		// published-only option list.
		$seen = array_fill_keys( array_map( 'intval', $posts ), true );
		foreach ( $include_ids as $extra_id ) {
			$extra_id = Policy::sanitize_integer( $extra_id );
			if ( 0 < $extra_id && ! isset( $seen[ $extra_id ] ) ) {
				$posts[] = $extra_id;
			}
		}
		$people = array();
		foreach ( $posts as $post_id ) {
			$people[] = array(
				'id'    => (int) $post_id,
				'title' => Policy::scalar_string( get_post_field( 'post_title', $post_id ) ),
			);
		}
		return $people;
	}

	/**
	 * Handles the profile-proposal submit.
	 *
	 * The proposal is stored on the resolved person record as a pending row;
	 * nothing is written to the public record until an editor approves it.
	 */
	public static function handle_profile(): void {
		$user = wp_get_current_user();
		if ( ! self::verify_nonce( 'lps_dashboard_profile' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		$person_id = self::person_for_user( $user->ID );
		if ( 0 >= $person_id ) {
			self::fail( 'lps_dashboard_forbidden' );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Every field is sanitized inside proposal_errors/normalize_proposal.
		$fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array();
		$errors = self::proposal_errors( array( 'fields' => $fields ) );
		if ( array() !== $errors ) {
			self::recall( 'profile', array( 'fields' => $fields ) );
			self::fail( (string) reset( $errors ), (string) array_key_first( $errors ) );
		}
		$proposals   = self::proposals_for_person( $person_id );
		$proposals[] = array(
			'id'           => wp_generate_uuid4(),
			'fields'       => self::normalize_proposal(
				array(
					'id'     => 'x',
					'fields' => $fields,
				)
			)['fields'],
			'note'         => '',
			'state'        => 'pending',
			'submitted_at' => gmdate( 'c' ),
			'reviewed_at'  => '',
			'reviewer_id'  => 0,
		);
		self::system_meta( $person_id, self::PROPOSALS_META, $proposals );
		Audit::record(
			'submit',
			$person_id,
			0,
			array(
				'decision' => 'profile-proposal',
				'fields'   => implode( ',', array_keys( $proposals[ count( $proposals ) - 1 ]['fields'] ) ),
			)
		);
		self::succeed( 'proposal-sent' );
	}

	/**
	 * Handles the unit create and edit forms.
	 */
	public static function handle_unit(): void {
		if ( ! self::verify_nonce( 'lps_dashboard_unit' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		$unit_id = self::post_int( 'id' );
		if ( 0 < $unit_id ) {
			self::update_unit( $unit_id );
		}
		$offering_id = self::post_int( 'offering_id' );
		if ( ! self::may( 'create', 'lps_unit', $offering_id ) ) {
			self::fail( 'lps_dashboard_scope' );
		}
		$input = array(
			'title'       => self::post_text( 'title' ),
			'excerpt'     => self::post_text( 'excerpt' ),
			'content'     => self::post_body( 'content' ),
			'offering_id' => $offering_id,
			'meta'        => array(
				'_lps_anchor'     => self::post_text( 'anchor' ),
				'_lps_position'   => self::post_int( 'position' ),
				'_lps_topic_date' => self::post_text( 'topic_date' ),
			),
		);
		// The dashboard validates the fields the form marks required so a
		// denied submit is recoverable instead of minting a nameless record.
		$errors = array();
		if ( '' === $input['title'] ) {
			$errors['title'] = 'lps_required_title';
		}
		if ( '' === $input['meta']['_lps_anchor'] ) {
			$errors['anchor'] = 'lps_required_anchor';
		}
		if ( 1 > $input['meta']['_lps_position'] ) {
			$errors['position'] = 'lps_invalid_unit_position';
		}
		if ( array() !== $errors ) {
			self::recall( 'unit-' . $offering_id, $input );
			self::fail( (string) reset( $errors ), (string) array_key_first( $errors ) );
		}
		$result = self::call_guarded( static fn() => TeachingRecords::create_unit( $input ) );
		if ( $result instanceof WP_Error ) {
			self::recall( 'unit-' . $offering_id, $input );
			self::fail( (string) $result->get_error_code(), self::error_field( $result ) );
		}
		self::succeed( 'created' );
	}

	/**
	 * Handles the resource create form, optionally with an initial upload.
	 */
	public static function handle_resource(): void {
		if ( ! self::verify_nonce( 'lps_dashboard_resource' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		$offering_id = self::post_int( 'offering_id' );
		if ( ! self::may( 'create', 'lps_resource', $offering_id ) ) {
			self::fail( 'lps_dashboard_scope' );
		}
		$version_id = '';
		$file       = self::posted_file();
		if ( null !== $file ) {
			$upload = TeachingResources::upload_version( $file['name'], $file['tmp_name'], TeachingResources::storage_config(), true );
			if ( null !== $upload['error'] || null === $upload['version'] ) {
				self::recall( 'resource-' . $offering_id, self::resource_input( $offering_id ) );
				self::fail( (string) ( $upload['error'] ?? 'lps_dashboard_upload' ), 'file' );
			}
			$version_id = Policy::scalar_string( $upload['version']['version_id'] ?? '' );
		}
		$input  = self::resource_input( $offering_id );
		$errors = array();
		if ( '' === $input['title'] ) {
			$errors['title'] = 'lps_required_title';
		}
		if ( ! in_array( $input['meta']['_lps_resource_type'], TeachingContracts::RESOURCE_TYPES, true ) ) {
			$errors['resource_type'] = 'lps_invalid_resource_type';
		}
		if ( '' === TeachingContracts::normalize_language( $input['meta']['_lps_resource_language'] ) ) {
			$errors['resource_language'] = 'lps_invalid_resource_language';
		}
		if ( array() !== $errors ) {
			self::recall( 'resource-' . $offering_id, $input );
			self::fail( (string) reset( $errors ), (string) array_key_first( $errors ) );
		}
		$result = TeachingResources::create_resource( array_merge( $input, array( 'version_id' => $version_id ) ) );
		if ( $result instanceof WP_Error ) {
			self::recall( 'resource-' . $offering_id, $input );
			self::fail( (string) $result->get_error_code(), self::error_field( $result ) );
		}
		self::succeed( 'created' );
	}

	/**
	 * Handles the version upload-and-select form on an existing resource.
	 */
	public static function handle_version(): void {
		if ( ! self::verify_nonce( 'lps_dashboard_version' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		$resource_id = self::post_int( 'resource_id' );
		$resource    = 0 < $resource_id ? get_post( $resource_id ) : null;
		if ( ! $resource instanceof WP_Post || 'lps_resource' !== $resource->post_type ) {
			self::fail( 'lps_teaching_offering_invalid' );
		}
		$offering_id = Roles::persisted_offering_id( $resource );
		if ( ! self::may( 'edit', 'lps_resource', $offering_id ) ) {
			self::fail( 'lps_dashboard_scope' );
		}
		$file = self::posted_file();
		if ( null === $file ) {
			self::fail( 'lps_teaching_upload_required', 'file' );
		}
		$upload = TeachingResources::upload_version( $file['name'], $file['tmp_name'], TeachingResources::storage_config(), true );
		if ( null !== $upload['error'] || null === $upload['version'] ) {
			self::fail( (string) ( $upload['error'] ?? 'lps_dashboard_upload' ), 'file' );
		}
		$selected = TeachingResources::select_version( $resource_id, Policy::scalar_string( $upload['version']['version_id'] ?? '' ) );
		if ( $selected instanceof WP_Error ) {
			self::fail( (string) $selected->get_error_code(), self::error_field( $selected ) );
		}
		self::succeed( 'version-selected' );
	}

	/**
	 * Handles the release/schedule/withdraw form on an existing resource.
	 */
	public static function handle_release(): void {
		if ( ! self::verify_nonce( 'lps_dashboard_release' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		$resource_id = self::post_int( 'resource_id' );
		$resource    = 0 < $resource_id ? get_post( $resource_id ) : null;
		if ( ! $resource instanceof WP_Post || 'lps_resource' !== $resource->post_type ) {
			self::fail( 'lps_teaching_offering_invalid' );
		}
		$offering_id = Roles::persisted_offering_id( $resource );
		if ( ! self::may( 'publish', 'lps_resource', $offering_id ) ) {
			self::fail( 'lps_dashboard_scope' );
		}
		$action = self::post_text( 'release_action' );
		if ( 'withdraw' === $action ) {
			$result = TeachingResources::withdraw_resource( $resource_id );
			if ( $result instanceof WP_Error ) {
				self::fail( (string) $result->get_error_code(), self::error_field( $result ) );
			}
			self::succeed( 'withdrawn' );
		}
		$state      = 'schedule' === $action ? 'scheduled' : 'released';
		$release_at = self::post_text( 'release_at' );
		$result     = TeachingResources::release_resource( $resource_id, $state, $release_at, TeachingResources::storage_config() );
		if ( $result instanceof WP_Error ) {
			self::fail( (string) $result->get_error_code(), self::error_field( $result ) );
		}
		self::succeed( 'scheduled' === $state ? 'scheduled' : 'released' );
	}

	/**
	 * Handles the publish action on a scoped record (unit, resource, news).
	 */
	public static function handle_publish(): void {
		if ( ! self::verify_nonce( 'lps_dashboard_publish' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		$post_id = self::post_int( 'post_id' );
		$post    = 0 < $post_id ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, TeachingPolicy::PUBLISHABLE_POST_TYPES, true ) ) {
			self::fail( 'lps_dashboard_forbidden' );
		}
		$offering_id = 'lps_news' === $post->post_type ? 0 : Roles::persisted_offering_id( $post );
		if ( ! self::may( 'publish', $post->post_type, $offering_id ) ) {
			self::fail( 'lps_dashboard_scope' );
		}
		$lifted_course_id = 0;
		if ( 'lps_offering' === $post->post_type ) {
			// A course minted through the create lane has no scoped edit lane of
			// its own, so it publishes through this same trusted boundary —
			// BEFORE the offering. A course still missing its contract fails the
			// whole publish here instead of leaving the offering's public route
			// pointing at a draft (the route resolves through the course).
			// Courses the editorial lane owns stay editor-published: a scoped
			// offering grant carries no authority over them.
			$course_rows = Relationships::for_source( $post_id, 'offering_course' );
			$course_id   = isset( $course_rows[0]['target_post_id'] ) ? (int) $course_rows[0]['target_post_id'] : 0;
			if ( 0 < $course_id && 'publish' !== get_post_status( $course_id ) ) {
				if ( '1' !== Policy::scalar_string( get_post_meta( $course_id, '_lps_pt_first', true ) ) ) {
					self::fail( 'lps_offering_course_unpublished', 'course' );
				}
				Roles::begin_course_create();
				try {
					$course_result = self::call_guarded( static fn() => TeachingRecords::publish_record( $course_id ) );
				} finally {
					Roles::end_course_create();
				}
				if ( $course_result instanceof WP_Error ) {
					self::fail( (string) $course_result->get_error_code(), self::error_field( $course_result ) );
				}
				$lifted_course_id = $course_id;
			}
		}
		$result = TeachingRecords::publish_record( $post_id );
		if ( $result instanceof WP_Error ) {
			if ( 0 < $lifted_course_id ) {
				// The offering stayed a draft, so its course returns to draft
				// as well — the pair only ever goes public together. The save
				// preserves _lps_state=published through complete_record, so
				// the editorial state is written back to draft through the
				// same system-owned meta boundary complete_record uses.
				// _lps_published_slug stays: the course did reach public, and
				// the slug keeps that immutable first-published identity.
				Roles::begin_course_create();
				try {
					wp_update_post(
						array(
							'ID'          => $lifted_course_id,
							'post_status' => 'draft',
						),
						true
					);
				} finally {
					Roles::end_course_create();
				}
				remove_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11 );
				update_post_meta( $lifted_course_id, '_lps_state', 'draft' );
				add_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11, 5 );
			}
			self::fail( (string) $result->get_error_code(), self::error_field( $result ) );
		}
		if ( 'lps_offering' === $post->post_type ) {
			// A professor-created course has no scoped edit lane of its own, so
			// it publishes through this same trusted boundary when its offering
			// goes live. By then the translation contract the course needs
			// (paired EN variant, summary, body) has been authored; while it is
			// unmet the course stays a draft and the offering's public route
			// simply 404s, matching any other unpublished dependency.
			$course_rows = Relationships::for_source( $post_id, 'offering_course' );
			$course_id   = isset( $course_rows[0]['target_post_id'] ) ? (int) $course_rows[0]['target_post_id'] : 0;
			if ( 0 < $course_id && 'publish' !== get_post_status( $course_id ) ) {
				Roles::begin_course_create();
				try {
					self::call_guarded( static fn() => TeachingRecords::publish_record( $course_id ) );
				} finally {
					Roles::end_course_create();
				}
			}
		}
		self::succeed( 'published' );
	}

	/**
	 * Handles the news submission form.
	 *
	 * The item is created as a draft and moved to `in_review`; the scoped
	 * account never publishes it directly from the dashboard — the review
	 * queue decides, matching the institutional approval journey.
	 */
	public static function handle_news(): void {
		$user = wp_get_current_user();
		if ( ! self::verify_nonce( 'lps_dashboard_news' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		if ( ! self::has_news_scope( $user->ID ) && ! Roles::current_user_can_action( 'create', 'news' ) ) {
			self::fail( 'lps_dashboard_forbidden' );
		}
		$step = self::post_text( 'step' );
		if ( 'cancel' === $step ) {
			self::discard_preview( $user->ID );
			self::succeed( 'preview-cancelled' );
		}
		if ( 'confirm' === $step ) {
			self::confirm_news( $user );
		}
		$title    = self::post_text( 'title' );
		$excerpt  = self::post_text( 'excerpt' );
		$content  = self::post_text( 'content' );
		$date     = self::post_text( 'canonical_date' );
		$category = self::sanitize_news_category( self::post_text( 'news_category' ) );
		$alt      = self::post_text( 'featured_alt' );
		$errors   = array();
		if ( '' === $title ) {
			$errors['post_title'] = 'lps_required_title';
		}
		if ( '' === $excerpt ) {
			$errors['post_excerpt'] = 'lps_required_summary';
		}
		if ( '' === $content ) {
			$errors['post_content'] = 'lps_required_body';
		}
		if ( '' === TeachingPolicy::normalize_datetime( $date ) ) {
			$errors['_lps_canonical_date'] = 'lps_invalid_topic_date';
		}
		$input = array(
			'title'          => $title,
			'excerpt'        => $excerpt,
			'content'        => $content,
			'canonical_date' => $date,
			'news_category'  => $category,
			'featured_alt'   => $alt,
		);
		if ( array() !== $errors ) {
			self::recall( 'news', $input );
			self::fail( (string) reset( $errors ), (string) array_key_first( $errors ) );
		}
		$file = self::posted_upload( 'featured_image' );
		if ( null !== $file && '' === $alt ) {
			self::recall( 'news', $input );
			self::fail( 'lps_required_alt', 'featured_alt' );
		}
		$post_id = self::post_int( 'id' );
		if ( 0 < $post_id ) {
			// A resubmit edits the author's own draft and returns it to review;
			// the scoped guard still decides whether the account may touch it.
			// A delegate-prepared draft may be adopted by any account allowed to
			// publish the lane — the professor submits it under her own authority
			// while the delegate's authorship and prepared-by marker stay intact.
			$existing = get_post( $post_id );
			// The stamp always names the draft's creator, so adoption only opens
			// for a marker that echoes post_author — a meta value written by
			// hand can at most self-attribute, never forge another's draft.
			$adoptable = $existing instanceof WP_Post
				&& 0 < self::prepared_marker_for( $post_id )
				&& self::may( 'publish', 'lps_news', 0 );
			if ( ! $existing instanceof WP_Post || 'lps_news' !== $existing->post_type || 'draft' !== $existing->post_status
				|| TranslationPolicy::TARGET_LOCALE === Translations::locale( $post_id )
				|| ( (int) $existing->post_author !== $user->ID && ! $adoptable ) ) {
				self::fail( 'lps_dashboard_forbidden' );
			}
			$attachment_id = 0;
			if ( null !== $file ) {
				$staged = self::stage_news_image( $file, $alt, $user->ID );
				if ( $staged instanceof WP_Error ) {
					self::recall( 'news', $input );
					self::fail_upload( $staged, 'featured_image' );
				}
				$attachment_id = $staged['id'];
			}
			remove_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11 );
			$updated = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_title'   => $title,
					'post_excerpt' => $excerpt,
					'post_content' => $content,
				),
				true
			);
			if ( $updated instanceof WP_Error ) {
				add_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11, 5 );
				self::fail( 'lps_dashboard_forbidden' );
			}
			// complete_record re-adds the field guard during wp_update_post, so
			// the boundary lifts it again for the post-update state writes.
			remove_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11 );
			update_post_meta( $post_id, '_lps_canonical_date', TeachingPolicy::normalize_datetime( $date ) );
			update_post_meta( $post_id, '_lps_news_category', $category );
			if ( 0 < $attachment_id ) {
				update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
			}
			update_post_meta( $post_id, '_lps_state', 'in_review' );
			update_post_meta( $post_id, self::REVIEW_NOTE_META, '' );
			add_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11, 5 );
			Audit::record( 'submit', $post_id, 0, array( 'decision' => 'news-resubmission' ) );
			self::succeed( 'submitted' );
		}
		$attachment = array(
			'id'   => 0,
			'url'  => '',
			'name' => '',
			'alt'  => '',
		);
		if ( null !== $file ) {
			$staged = self::stage_news_image( $file, $alt, $user->ID );
			if ( $staged instanceof WP_Error ) {
				self::recall( 'news', $input );
				self::fail_upload( $staged, 'featured_image' );
			}
			$attachment = $staged;
		}
		// The professor confirms the rendered card before the record exists: the
		// payload is staged per account and the confirm step performs the real
		// draft insert. A fresh preview discards the previously staged image.
		self::store_preview(
			$user->ID,
			array_merge(
				$input,
				array(
					'attachment_id'   => $attachment['id'],
					'attachment_url'  => $attachment['url'],
					'attachment_name' => $attachment['name'],
				)
			)
		);
		self::recall( 'news', $input );
		self::succeed( 'previewed' );
	}

	/**
	 * Performs the confirmed news submission from the staged preview.
	 *
	 * The preview step never creates the record: the payload waits in a
	 * per-account transient and this step validates it again, inserts the
	 * draft, attaches the staged featured image, and moves the item to
	 * `in_review` — the same pipeline the direct submit used before.
	 *
	 * @param WP_User $user Signed-in account.
	 */
	private static function confirm_news( WP_User $user ): void {
		$payload = self::preview_payload( $user->ID );
		if ( array() === $payload ) {
			self::fail( 'lps_preview_expired' );
		}
		$title         = Policy::scalar_string( $payload['title'] ?? '' );
		$excerpt       = Policy::scalar_string( $payload['excerpt'] ?? '' );
		$content       = Policy::scalar_string( $payload['content'] ?? '' );
		$date          = Policy::scalar_string( $payload['canonical_date'] ?? '' );
		$category      = self::sanitize_news_category( Policy::scalar_string( $payload['news_category'] ?? '' ) );
		$attachment_id = Policy::sanitize_integer( $payload['attachment_id'] ?? 0 );
		if ( '' === $title || '' === $excerpt || '' === $content || '' === TeachingPolicy::normalize_datetime( $date ) ) {
			self::discard_preview( $user->ID );
			self::fail( 'lps_preview_expired' );
		}
		if ( 0 < $attachment_id ) {
			$attachment = get_post( $attachment_id );
			if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type || (int) $attachment->post_author !== $user->ID ) {
				self::discard_preview( $user->ID );
				self::fail( 'lps_preview_expired' );
			}
		}
		$meta = array(
			'_lps_locale'         => 'pt-br',
			'_lps_canonical_date' => TeachingPolicy::normalize_datetime( $date ),
			'_lps_news_status'    => 'draft',
		);
		if ( '' !== $category ) {
			$meta['_lps_news_category'] = $category;
		}
		if ( 0 < $attachment_id ) {
			$meta['_thumbnail_id'] = $attachment_id;
		}
		// The insert and the state write are system-owned fields the scoped
		// guard correctly denies to direct writes; the boundary lifts that one
		// guard for its own writes and restores it immediately.
		remove_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11 );
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'lps_news',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
				'post_content' => $content,
				'post_author'  => $user->ID,
				'meta_input'   => $meta,
			),
			true
		);
		if ( $post_id instanceof WP_Error ) {
			add_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11, 5 );
			self::fail( 'lps_dashboard_forbidden' );
		}
		// complete_record re-adds the field guard during wp_insert_post, so the
		// boundary lifts it again for the post-insert state write.
		remove_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11 );
		update_post_meta( (int) $post_id, '_lps_state', 'in_review' );
		add_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11, 5 );
		delete_transient( self::PREVIEW_PREFIX . $user->ID . '_news' );
		Audit::record( 'submit', (int) $post_id, 0, array( 'decision' => 'news-submission' ) );
		self::succeed( 'submitted' );
	}

	/**
	 * Handles the offering-scoped announcement post and removal forms.
	 *
	 * Avisos live on the offering authority as a typed meta list and render
	 * immediately on the public page — a dedicated PT-first stream that never
	 * enters `publish_record`, so no EN variant is required for a notice to
	 * appear on either locale route.
	 */
	public static function handle_notice(): void {
		$user = wp_get_current_user();
		if ( ! self::verify_nonce( 'lps_dashboard_notice' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		$offering_id = self::post_int( 'offering_id' );
		$offering    = 0 < $offering_id ? get_post( $offering_id ) : null;
		if ( ! $offering instanceof WP_Post || 'lps_offering' !== $offering->post_type ) {
			self::fail( 'lps_teaching_offering_invalid' );
		}
		if ( ! self::may( 'edit', 'lps_offering', $offering_id ) ) {
			self::fail( 'lps_dashboard_scope' );
		}
		$action = self::post_text( 'notice_action' );
		if ( 'remove' === $action ) {
			$notice_id = self::post_text( 'notice_id' );
			$mutated   = self::mutate_notices(
				$offering_id,
				static fn( array $notices ): ?array => self::remove_notice( array_values( $notices ), $notice_id )
			);
			if ( ! $mutated ) {
				self::fail( 'lps_dashboard_forbidden' );
			}
			// The notice write is pure metadata, so it never fires
			// `transition_post_status`; touching the offering is the public
			// page cache's only invalidation signal.
			wp_update_post( array( 'ID' => $offering_id ), true );
			Audit::record(
				'edit',
				$offering_id,
				0,
				array(
					'decision'  => 'offering-notice-remove',
					'notice_id' => $notice_id,
				)
			);
			self::succeed( 'notice-removed' );
		}
		$body = self::post_body( 'body' );
		if ( '' === $body ) {
			self::fail( 'lps_required_body', 'body' );
		}
		$entry   = array(
			'id'         => wp_generate_uuid4(),
			'body'       => $body,
			'created_at' => gmdate( 'c' ),
			'author_id'  => $user->ID,
		);
		$mutated = self::mutate_notices(
			$offering_id,
			static fn( array $notices ): array => array_merge( $notices, array( $entry ) )
		);
		if ( ! $mutated ) {
			self::fail( 'lps_dashboard_forbidden' );
		}
		wp_update_post( array( 'ID' => $offering_id ), true );
		Audit::record( 'submit', $offering_id, 0, array( 'decision' => 'offering-notice' ) );
		self::succeed( 'notice-posted' );
	}

	/**
	 * Handles the descriptive edit form on an existing resource.
	 *
	 * Title, summary, type, language and the external URL are the fields a
	 * scoped account may already write; the scoped boundary re-checks the
	 * offering grant before any of them change.
	 */
	public static function handle_material(): void {
		if ( ! self::verify_nonce( 'lps_dashboard_material' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		$resource_id = self::post_int( 'resource_id' );
		$resource    = 0 < $resource_id ? get_post( $resource_id ) : null;
		if ( ! $resource instanceof WP_Post || 'lps_resource' !== $resource->post_type || 'trash' === $resource->post_status ) {
			self::fail( 'lps_dashboard_forbidden' );
		}
		$offering_id = Roles::persisted_offering_id( $resource );
		if ( ! self::may( 'edit', 'lps_resource', $offering_id ) ) {
			self::fail( 'lps_dashboard_scope' );
		}
		$title        = self::post_text( 'title' );
		$excerpt      = self::post_text( 'excerpt' );
		$external_url = self::post_text( 'external_url' );
		$type         = self::post_text( 'resource_type' );
		$language     = self::post_text( 'resource_language' );
		$errors       = array();
		if ( '' === $title ) {
			$errors['title'] = 'lps_required_title';
		}
		if ( ! in_array( $type, TeachingContracts::RESOURCE_TYPES, true ) ) {
			$errors['resource_type'] = 'lps_invalid_resource_type';
		}
		if ( '' === TeachingContracts::normalize_language( $language ) ) {
			$errors['resource_language'] = 'lps_invalid_resource_language';
		}
		if ( '' !== $external_url && '' === Policy::sanitize_url( $external_url ) ) {
			$errors['external_url'] = 'lps_invalid_url';
		}
		$version_id = Policy::scalar_string( get_post_meta( $resource_id, '_lps_version_id', true ) );
		if ( '' !== $external_url && '' !== $version_id ) {
			$errors['external_url'] = 'lps_resource_version_and_url_conflict';
		}
		$released = 'released' === TeachingResources::effective_release_state(
			Policy::scalar_string( get_post_meta( $resource_id, '_lps_release_state', true ) ),
			Policy::scalar_string( get_post_meta( $resource_id, '_lps_release_at', true ) ),
			gmdate( 'c' )
		);
		if ( '' === $external_url && '' === $version_id && $released ) {
			$errors['external_url'] = 'lps_resource_version_or_url_required';
		}
		if ( array() !== $errors ) {
			self::fail( (string) reset( $errors ), (string) array_key_first( $errors ) );
		}
		// Metadata leads the post update so the index and purge hooks that fire
		// on `transition_post_status` observe the final descriptive values.
		update_post_meta( $resource_id, '_lps_resource_type', $type );
		update_post_meta( $resource_id, '_lps_resource_language', TeachingContracts::normalize_language( $language ) );
		update_post_meta( $resource_id, '_lps_external_url', Policy::sanitize_url( $external_url ) );
		$updated = wp_update_post(
			array(
				'ID'           => $resource_id,
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
			),
			true
		);
		if ( $updated instanceof WP_Error ) {
			self::fail( 'lps_dashboard_forbidden' );
		}
		Audit::record(
			'edit',
			$resource_id,
			0,
			array(
				'decision'    => 'material-edit',
				'offering_id' => $offering_id,
			)
		);
		self::succeed( 'saved' );
	}

	/**
	 * Handles the material reorder form on an offering.
	 *
	 * The submitted positions re-sequence the canonical `resource_offering`
	 * rows' `sort_order`, the same ordering the public materials list reads.
	 * Unaddressed rows keep a stable tail position rather than being dropped.
	 */
	public static function handle_order(): void {
		if ( ! self::verify_nonce( 'lps_dashboard_order' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		$offering_id = self::post_int( 'offering_id' );
		$offering    = 0 < $offering_id ? get_post( $offering_id ) : null;
		if ( ! $offering instanceof WP_Post || 'lps_offering' !== $offering->post_type ) {
			self::fail( 'lps_teaching_offering_invalid' );
		}
		if ( ! self::may( 'edit', 'lps_offering', $offering_id ) ) {
			self::fail( 'lps_dashboard_scope' );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Every key and value is sanitized to an integer before use.
		$positions = isset( $_POST['positions'] ) && is_array( $_POST['positions'] ) ? wp_unslash( $_POST['positions'] ) : array();
		$submitted = array();
		foreach ( $positions as $resource_id => $position ) {
			$submitted[ Policy::sanitize_integer( $resource_id ) ] = Policy::sanitize_integer( $position );
		}
		$entries = array();
		foreach ( Relationships::reverse_for( $offering_id, 'resource_offering' ) as $row ) {
			$resource_id = Policy::sanitize_integer( $row['source_post_id'] );
			$entries[]   = array(
				'row'      => $row,
				'position' => $submitted[ $resource_id ] ?? PHP_INT_MAX,
			);
		}
		usort(
			$entries,
			static fn( array $left, array $right ): int => 0 !== ( $left['position'] <=> $right['position'] )
				? $left['position'] <=> $right['position']
				: Policy::sanitize_integer( $left['row']['sort_order'] ) <=> Policy::sanitize_integer( $right['row']['sort_order'] )
		);
		$order   = 0;
		$changed = false;
		foreach ( $entries as $entry ) {
			++$order;
			$row = $entry['row'];
			if ( Policy::sanitize_integer( $row['sort_order'] ) === $order ) {
				continue;
			}
			$row['sort_order'] = $order;
			$result            = Relationships::replace( Policy::sanitize_integer( $row['source_post_id'] ), 'resource_offering', array( $row ) );
			if ( $result instanceof WP_Error ) {
				self::fail( (string) $result->get_error_code(), self::error_field( $result ) );
			}
			$changed = true;
		}
		if ( $changed ) {
			// Relationship rows are a plain table write: no post or meta hook
			// fires, so the offering page must be touched to expire its cache.
			wp_update_post( array( 'ID' => $offering_id ), true );
		}
		Audit::record( 'edit', $offering_id, 0, array( 'decision' => 'material-order' ) );
		self::succeed( 'ordered' );
	}

	/**
	 * Handles the offering-details edit form: schedule, venue, period notes.
	 *
	 * Term-side fields a professor adjusts on their own offering; the scoped
	 * boundary checks the grant on this offering before anything is written.
	 */
	public static function handle_offering_edit(): void {
		if ( ! self::verify_nonce( 'lps_dashboard_offering_edit' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		$offering_id = self::post_int( 'offering_id' );
		$offering    = 0 < $offering_id ? get_post( $offering_id ) : null;
		if ( ! $offering instanceof WP_Post || 'lps_offering' !== $offering->post_type ) {
			self::fail( 'lps_teaching_offering_invalid' );
		}
		if ( ! self::may( 'edit', 'lps_offering', $offering_id ) ) {
			self::fail( 'lps_dashboard_scope' );
		}
		$schedule = self::post_textarea( 'schedule' );
		$venue    = self::post_text( 'venue' );
		$content  = self::post_body( 'content' );
		// Schedule is a material field: the same authority value is mirrored onto
		// every published variant, exactly as the publish boundary synchronizes it.
		$sync = Translations::synchronize_material(
			$offering_id,
			array(
				'_lps_cancelled' => Policy::scalar_string( get_post_meta( $offering_id, '_lps_cancelled', true ) ),
				'_lps_schedule'  => $schedule,
			)
		);
		if ( $sync instanceof WP_Error ) {
			self::fail( (string) $sync->get_error_code(), self::error_field( $sync ) );
		}
		// Venue has no variant mirror — both locale routes read it from the
		// authority record, so a single system write covers the public pages.
		self::system_meta( $offering_id, '_lps_venue', $venue );
		$updated = wp_update_post(
			array(
				'ID'           => $offering_id,
				'post_content' => $content,
			),
			true
		);
		if ( $updated instanceof WP_Error ) {
			self::fail( 'lps_dashboard_forbidden' );
		}
		Audit::record( 'edit', $offering_id, 0, array( 'decision' => 'offering-details' ) );
		self::succeed( 'saved' );
	}

	/**
	 * Handles the news EN-translation task.
	 *
	 * The Portuguese record is submitted first; this task lets the author add
	 * the English title, summary and body afterwards. The handler creates the
	 * EN variant as a draft, binds it with `Translations::associate`, marks it
	 * reviewed against the current source with `Translations::review`, and
	 * returns it to `in_review` so the pair satisfies the bilingual publish
	 * contract without bypassing the editorial queue.
	 */
	public static function handle_news_translation(): void {
		$user = wp_get_current_user();
		if ( ! self::verify_nonce( 'lps_dashboard_news_translation' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		if ( ! self::has_news_scope( $user->ID ) && ! Roles::current_user_can_action( 'create', 'news' ) ) {
			self::fail( 'lps_dashboard_forbidden' );
		}
		$news_id = self::post_int( 'news_id' );
		$source  = 0 < $news_id ? get_post( $news_id ) : null;
		// The English task follows the same adoption rule as the resubmit: a
		// lane publisher may translate a delegate-prepared draft it adopted.
		$adoptable = $source instanceof WP_Post
			&& 0 < self::prepared_marker_for( $news_id )
			&& self::may( 'publish', 'lps_news', 0 );
		if ( ! $source instanceof WP_Post || 'lps_news' !== $source->post_type || TranslationPolicy::TARGET_LOCALE === Translations::locale( $news_id )
			|| ( (int) $source->post_author !== $user->ID && ! $adoptable ) ) {
			self::fail( 'lps_translation_source_invalid', 'news_id' );
		}
		$title   = self::post_text( 'en_title' );
		$excerpt = self::post_text( 'en_excerpt' );
		$content = self::post_text( 'en_content' );
		$errors  = array();
		if ( '' === $title ) {
			$errors['en_title'] = 'lps_required_title';
		}
		if ( '' === $excerpt ) {
			$errors['en_excerpt'] = 'lps_required_summary';
		}
		if ( '' === $content ) {
			$errors['en_content'] = 'lps_required_body';
		}
		if ( array() !== $errors ) {
			self::recall(
				'news-translation-' . $news_id,
				array(
					'en_title'   => $title,
					'en_excerpt' => $excerpt,
					'en_content' => $content,
				)
			);
			self::fail( (string) reset( $errors ), (string) array_key_first( $errors ) );
		}
		$variants = Translations::variants( $news_id );
		$en_id    = Policy::sanitize_integer( $variants[ TranslationPolicy::TARGET_LOCALE ] ?? 0 );
		if ( 0 < $en_id && get_post( $en_id ) instanceof WP_Post ) {
			// A stale variant reopens the same task: the author refreshes the
			// fields and the review stamp, never a second record.
			remove_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11 );
			$updated = wp_update_post(
				array(
					'ID'           => $en_id,
					'post_title'   => $title,
					'post_excerpt' => $excerpt,
					'post_content' => $content,
				),
				true
			);
			add_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11, 5 );
			if ( $updated instanceof WP_Error ) {
				self::fail( 'lps_dashboard_forbidden' );
			}
			self::system_meta( $en_id, '_lps_state', 'in_review' );
		} else {
			remove_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11 );
			$created = wp_insert_post(
				array(
					'post_type'    => 'lps_news',
					'post_status'  => 'draft',
					'post_title'   => $title,
					'post_excerpt' => $excerpt,
					'post_content' => $content,
					'post_author'  => $user->ID,
					'meta_input'   => array(
						'_lps_news_status' => 'draft',
					),
				),
				true
			);
			add_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11, 5 );
			if ( $created instanceof WP_Error ) {
				self::fail( 'lps_dashboard_forbidden' );
			}
			$en_id      = (int) $created;
			$associated = self::call_guarded(
				static function () use ( $news_id, $en_id ) {
					return Translations::associate( $news_id, $en_id );
				}
			);
			if ( $associated instanceof WP_Error ) {
				wp_delete_post( $en_id, true );
				self::fail( (string) $associated->get_error_code(), 'translation' );
			}
			self::system_meta( $en_id, '_lps_state', 'in_review' );
		}
		$reviewed = self::call_guarded(
			static function () use ( $en_id, $user ) {
				return Translations::review( $en_id, $user->ID );
			}
		);
		if ( $reviewed instanceof WP_Error ) {
			self::fail( (string) $reviewed->get_error_code(), 'translation' );
		}
		Audit::record(
			'submit',
			$news_id,
			0,
			array(
				'decision'       => 'news-translation',
				'target_post_id' => $en_id,
			)
		);
		self::succeed( 'translated' );
	}

	/**
	 * Handles the next-term copy form.
	 */
	public static function handle_copy(): void {
		if ( ! self::verify_nonce( 'lps_dashboard_copy' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		$source_id = self::post_int( 'offering_id' );
		$source    = 0 < $source_id ? get_post( $source_id ) : null;
		if ( ! $source instanceof WP_Post || 'lps_offering' !== $source->post_type ) {
			self::fail( 'lps_teaching_offering_invalid' );
		}
		if ( ! self::may_copy( $source_id ) ) {
			self::fail( 'lps_dashboard_scope' );
		}
		$operation_id = self::post_text( 'operation_id' );
		if ( '' === TeachingContracts::normalize_operation_id( $operation_id ) ) {
			$operation_id = 'copy-' . wp_generate_uuid4();
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Every row is normalized inside team_from_input.
		$team     = self::team_from_input( isset( $_POST['team'] ) && is_array( $_POST['team'] ) ? wp_unslash( $_POST['team'] ) : array() );
		$reviewed = '1' === self::post_text( 'team_reviewed' );
		$selected = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Every ID is normalized inside selected_version_ids.
		foreach ( isset( $_POST['versions'] ) && is_array( $_POST['versions'] ) ? wp_unslash( $_POST['versions'] ) : array() as $candidate ) {
			$selected[] = Policy::scalar_string( $candidate );
		}
		$result = TeachingCopy::copy_forward(
			array(
				'operation_id'         => $operation_id,
				'source_offering_id'   => $source_id,
				'new_term_id'          => self::post_int( 'new_term_id' ),
				'new_section'          => self::post_text( 'new_section' ),
				'team'                 => $team,
				'team_reviewed'        => $reviewed,
				'selected_version_ids' => $selected,
				'title'                => self::post_text( 'title' ),
			)
		);
		if ( $result instanceof WP_Error ) {
			self::recall(
				'copy-' . $source_id,
				array(
					'new_term_id' => self::post_int( 'new_term_id' ),
					'new_section' => self::post_text( 'new_section' ),
					'title'       => self::post_text( 'title' ),
				)
			);
			self::fail( (string) $result->get_error_code(), self::error_field( $result ) );
		}
		self::succeed( ! empty( $result['replayed'] ) ? 'copy-replayed' : 'copied' );
	}

	/**
	 * Handles the offering create form for institutional editors.
	 */
	public static function handle_offering(): void {
		if ( ! self::verify_nonce( 'lps_dashboard_offering' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		if ( ! Roles::current_user_can_action( 'create', 'teaching' ) ) {
			self::fail( 'lps_dashboard_forbidden' );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Every row is normalized inside team_from_input.
		$team   = self::team_from_input( isset( $_POST['team'] ) && is_array( $_POST['team'] ) ? wp_unslash( $_POST['team'] ) : array() );
		$input  = array(
			'title'     => self::post_text( 'title' ),
			'excerpt'   => self::post_text( 'excerpt' ),
			'content'   => self::post_text( 'content' ),
			'course_id' => self::post_int( 'course_id' ),
			'term_id'   => self::post_int( 'term_id' ),
			'section'   => self::post_text( 'section' ),
			'team'      => $team,
			'meta'      => array(
				'_lps_schedule' => self::post_text( 'schedule' ),
				'_lps_venue'    => self::post_text( 'venue' ),
			),
		);
		$result = self::call_guarded( static fn() => TeachingRecords::create_offering( $input ) );
		if ( $result instanceof WP_Error ) {
			self::recall( 'offering', $input );
			self::fail( (string) $result->get_error_code(), self::error_field( $result ) );
		}
		self::succeed( 'created' );
	}

	/**
	 * Handles the trusted course-plus-offering create for professors.
	 *
	 * One submit mints the `lps_course` draft, binds the first `lps_offering`
	 * draft to it (term + section + team), and grants the creator offering
	 * scope back so the workspace opens immediately. When the offering leg
	 * fails, the orphaned course draft is removed so a retry cannot collide
	 * with a stale identity claim.
	 */
	public static function handle_course(): void {
		$user = wp_get_current_user();
		if ( ! self::verify_nonce( 'lps_dashboard_course' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		if ( ! self::may_course( $user ) ) {
			self::fail( 'lps_dashboard_forbidden' );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Every row is normalized inside team_from_input.
		$team  = self::team_from_input( isset( $_POST['team'] ) && is_array( $_POST['team'] ) ? wp_unslash( $_POST['team'] ) : array() );
		$input = array(
			'title'         => self::post_text( 'title' ),
			'excerpt'       => self::post_text( 'excerpt' ),
			'content'       => self::post_text( 'content' ),
			'course_code'   => self::post_text( 'course_code' ),
			'course_level'  => self::post_text( 'course_level' ),
			'calendar_key'  => self::post_text( 'calendar_key' ),
			'program'       => self::post_text( 'program' ),
			'prerequisites' => self::post_text( 'prerequisites' ),
			'syllabus'      => self::post_text( 'syllabus' ),
			'term_id'       => self::post_int( 'term_id' ),
			'section'       => self::post_text( 'section' ),
			'schedule'      => self::post_text( 'schedule' ),
			'venue'         => self::post_text( 'venue' ),
			'team'          => $team,
		);
		// The dashboard validates the fields its form marks required so a denied
		// submit is recoverable instead of minting a half-named record.
		$errors = array();
		if ( '' === $input['title'] ) {
			$errors['title'] = 'lps_required_title';
		}
		if ( '' === $input['course_code'] ) {
			$errors['course_code'] = 'lps_required_course_code';
		}
		if ( '' === $input['course_level'] ) {
			$errors['course_level'] = 'lps_required_course_level';
		}
		if ( '' === $input['calendar_key'] ) {
			$errors['calendar_key'] = 'lps_required_calendar_key';
		}
		if ( 0 >= $input['term_id'] ) {
			$errors['term_id'] = 'lps_required_term_id';
		}
		if ( '' === $input['section'] ) {
			$errors['section'] = 'lps_required_section_key';
		}
		if ( '' === $input['excerpt'] ) {
			$errors['excerpt'] = 'lps_required_summary';
		}
		if ( '' === $input['content'] ) {
			$errors['content'] = 'lps_required_body';
		}
		if ( array() !== $errors ) {
			self::recall( 'course', $input );
			self::fail( (string) reset( $errors ), (string) array_key_first( $errors ) );
		}
		// A scoped actor may only create a subject they actually teach: their
		// resolved person record must be on the submitted team. Editors are
		// exempt — they curate offerings on other people's behalf already.
		$role = Roles::policy_role( $user );
		if ( TeachingPolicy::is_scoped_role( $role ) ) {
			$person_id = self::person_for_user( $user->ID );
			$on_team   = false;
			foreach ( $team as $member ) {
				if ( $member['person_id'] === $person_id ) {
					$on_team = true;
					break;
				}
			}
			if ( ! $on_team ) {
				self::recall( 'course', $input );
				self::fail( 'lps_course_team_creator_missing', 'team' );
			}
		}
		// The first offering must sit on the same calendar the course declares:
		// copy-forward enforces that equality, so the create enforces it here.
		$term_calendar = TeachingContracts::normalize_calendar_key( get_post_meta( $input['term_id'], '_lps_calendar_key', true ) );
		if ( '' !== $term_calendar && TeachingContracts::normalize_calendar_key( $input['calendar_key'] ) !== $term_calendar ) {
			self::recall( 'course', $input );
			self::fail( 'lps_copy_forward_calendar_mismatch', 'term_id' );
		}
		// The flag marks the trusted create boundary mid-flight so the scoped
		// relationship guard lets the canonical boundaries write the new
		// offering's editor-owned rows. It is set only after may_course
		// authorized this account and always cleared in the finally.
		Roles::begin_course_create();
		try {
			$course = self::call_guarded(
				static fn() => TeachingRecords::create_course(
					array(
						'title'   => $input['title'],
						'excerpt' => $input['excerpt'],
						'content' => $input['content'],
						'meta'    => array(
							'_lps_course_code'   => $input['course_code'],
							'_lps_course_level'  => $input['course_level'],
							'_lps_calendar_key'  => $input['calendar_key'],
							'_lps_program'       => $input['program'],
							'_lps_prerequisites' => $input['prerequisites'],
							'_lps_syllabus'      => $input['syllabus'],
						),
					)
				)
			);
			if ( $course instanceof WP_Error ) {
				self::recall( 'course', $input );
				self::fail( (string) $course->get_error_code(), self::error_field( $course ) );
			}
			$course_id = Policy::sanitize_integer( $course['id'] ?? 0 );
			// PT-first lane: records minted here may publish before their EN pair
			// exists (the owner approved PT-only submit, EN via a later
			// translation task). `Translations::validate_request` honors the
			// marker for the required-English denials only.
			add_post_meta( $course_id, '_lps_pt_first', '1', true );
			$term_label = Policy::scalar_string( get_post_meta( $input['term_id'], '_lps_period_label', true ) );
			$offering   = self::call_guarded(
				static fn() => TeachingRecords::create_offering(
					array(
						'title'     => '' !== $term_label ? $input['title'] . ' — ' . $term_label . ' ' . $input['section'] : $input['title'] . ' — ' . $input['section'],
						'excerpt'   => $input['excerpt'],
						'content'   => $input['content'],
						'course_id' => $course_id,
						'term_id'   => $input['term_id'],
						'section'   => $input['section'],
						'team'      => $input['team'],
						'meta'      => array(
							'_lps_schedule' => $input['schedule'],
							'_lps_venue'    => $input['venue'],
						),
					)
				)
			);
			if ( $offering instanceof WP_Error ) {
				self::call_guarded(
					static function () use ( $course_id ): array {
						wp_delete_post( $course_id, true );
						return array( 'deleted' => true );
					}
				);
				self::recall( 'course', $input );
				self::fail( (string) $offering->get_error_code(), self::error_field( $offering ) );
			}
			// The course stays a draft here: its publish contract needs a paired
			// EN variant that cannot exist yet (PT-first submission is allowed).
			// The offering's scoped publish lane lifts it through this same
			// boundary when the offering goes live — see handle_publish.
			$offering_id = Policy::sanitize_integer( $offering['id'] ?? 0 );
			add_post_meta( $offering_id, '_lps_pt_first', '1', true );
			if ( 'professor' === $role || 'delegate' === $role ) {
				// The trusted lane hands the just-created offering back to its
				// creator so the workspace opens on the very next request.
				Roles::grant_scope_trusted( $user->ID, 'offering', $offering_id, $role );
			}
			Audit::record(
				'submit',
				$course_id,
				0,
				array(
					'decision'    => 'course-create',
					'offering_id' => $offering_id,
				)
			);
		} finally {
			Roles::end_course_create();
		}
		self::succeed( 'course-created' );
	}

	/**
	 * Handles the review decision on a news submission or a profile proposal.
	 *
	 * Approve publishes the news record or applies the proposal fields to the
	 * person record; reject returns the item to draft with the required note.
	 */
	public static function handle_review(): void {
		$user = wp_get_current_user();
		if ( ! self::verify_nonce( 'lps_dashboard_review' ) ) {
			self::fail( 'lps_dashboard_nonce' );
		}
		$role     = Roles::policy_role( $user );
		$kind     = self::post_text( 'kind' );
		$decision = self::post_text( 'decision' );
		$note     = self::post_text( 'note' );
		if ( ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
			self::fail( 'lps_dashboard_forbidden' );
		}
		if ( 'reject' === $decision && '' === $note ) {
			self::fail( 'lps_dashboard_review_note', 'note' );
		}
		if ( 'news' === $kind ) {
			$post_id = self::post_int( 'post_id' );
			$post    = 0 < $post_id ? get_post( $post_id ) : null;
			if ( ! $post instanceof WP_Post || 'lps_news' !== $post->post_type ) {
				self::fail( 'lps_teaching_offering_invalid' );
			}
			if ( 'approve' === $decision ) {
				if ( ! Roles::current_user_can_action( 'publish', 'news' ) ) {
					self::fail( 'lps_dashboard_forbidden' );
				}
				// The decision email fires on an actual review outcome — a pending
				// item reaching a verdict — so a retried decision on an
				// already-decided record does not mail the author again.
				$awaiting = 'in_review' === Policy::scalar_string( get_post_meta( $post_id, '_lps_state', true ) );
				$result   = TeachingRecords::publish_record( $post_id );
				if ( $result instanceof WP_Error ) {
					self::fail( (string) $result->get_error_code(), self::error_field( $result ) );
				}
				self::system_meta( $post_id, '_lps_news_status', 'published' );
				self::system_meta( $post_id, self::REVIEW_NOTE_META, $note );
				if ( $awaiting ) {
					Notifications::news_decision( $post, 'approve', $note );
				}
				self::succeed( 'published' );
			}
			if ( ! SecurityPolicy::allows( $role, 'review', 'news', Roles::assigned_collections( $user->ID ) ) ) {
				self::fail( 'lps_dashboard_forbidden' );
			}
			$awaiting = 'in_review' === Policy::scalar_string( get_post_meta( $post_id, '_lps_state', true ) );
			self::system_meta( $post_id, '_lps_state', 'draft' );
			self::system_meta( $post_id, self::REVIEW_NOTE_META, $note );
			Audit::record(
				'review',
				$post_id,
				0,
				array(
					'decision' => 'reject',
					'note'     => $note,
				)
			);
			if ( $awaiting ) {
				Notifications::news_decision( $post, 'reject', $note );
			}
			self::succeed( 'reviewed' );
		}
		if ( 'proposal' === $kind ) {
			$person_id   = self::post_int( 'person_id' );
			$proposal_id = self::post_text( 'proposal_id' );
			$person      = 0 < $person_id ? get_post( $person_id ) : null;
			if ( ! $person instanceof WP_Post || 'lps_person' !== $person->post_type ) {
				self::fail( 'lps_teaching_offering_invalid' );
			}
			if ( ! SecurityPolicy::allows( $role, 'review', 'person', Roles::assigned_collections( $user->ID ) ) && ! SecurityPolicy::allows( $role, 'edit', 'person', Roles::assigned_collections( $user->ID ) ) ) {
				self::fail( 'lps_dashboard_forbidden' );
			}
			$proposals = self::proposals_for_person( $person_id );
			$found     = -1;
			foreach ( $proposals as $index => $proposal ) {
				if ( $proposal['id'] === $proposal_id && 'pending' === $proposal['state'] ) {
					$found = $index;
					break;
				}
			}
			if ( 0 > $found ) {
				self::fail( 'lps_dashboard_forbidden' );
			}
			$proposal                = $proposals[ $found ];
			$proposal['state']       = 'approve' === $decision ? 'approved' : 'rejected';
			$proposal['note']        = $note;
			$proposal['reviewed_at'] = gmdate( 'c' );
			$proposal['reviewer_id'] = $user->ID;
			$proposals[ $found ]     = $proposal;
			if ( 'approve' === $decision ) {
				foreach ( $proposal['fields'] as $key => $value ) {
					if ( 'post_excerpt' === $key || 'post_content' === $key ) {
						wp_update_post(
							array(
								'ID'           => $person_id,
								'post_excerpt' => 'post_excerpt' === $key ? $value : $person->post_excerpt,
								'post_content' => 'post_content' === $key ? $value : $person->post_content,
							),
							true
						);
						continue;
					}
					self::system_meta( $person_id, $key, $value );
				}
			}
			self::system_meta( $person_id, self::PROPOSALS_META, $proposals );
			Audit::record(
				'review',
				$person_id,
				0,
				array(
					'decision'    => 'profile-' . $decision,
					'proposal_id' => $proposal_id,
				)
			);
			self::succeed( 'approve' === $decision ? 'proposal-approved' : 'proposal-rejected' );
		}
		self::fail( 'lps_dashboard_forbidden' );
	}

	/**
	 * Reads one POST textarea field, keeping line breaks.
	 *
	 * @param string $key Field name.
	 */
	private static function post_textarea( string $key ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Sanitized as a multiline field on return.
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
	}

	/**
	 * Reads one POST rich-body field.
	 *
	 * The body is kept to the wp_kses_post vocabulary and paragraph-marked for
	 * storage, matching how the theme's `rich()` renderer treats record bodies
	 * (it kses' again at render but never wpautops).
	 *
	 * @param string $key Field name.
	 */
	private static function post_body( string $key ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Sanitized to the safe-HTML vocabulary on return.
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		$value = is_scalar( $value ) ? (string) $value : '';
		$clean = '' === trim( $value ) ? '' : wp_kses_post( $value );
		return '' === trim( $clean ) ? '' : wpautop( $clean );
	}

	/**
	 * Reads one POST integer field.
	 *
	 * @param string $key Field name.
	 */
	private static function post_int( string $key ): int {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Sanitized as an integer on return.
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : 0;
		return Policy::sanitize_integer( $value );
	}

	/**
	 * Updates one existing unit's title, summary, body, anchor, position and date.
	 *
	 * The scoped boundary resolves the unit's persisted offering and checks the
	 * account's edit grant on it; the meta keys it writes are exactly the ones
	 * the scoped field allowlist names for `lps_unit`.
	 *
	 * @param int $unit_id Unit record ID.
	 */
	private static function update_unit( int $unit_id ): void {
		$unit = get_post( $unit_id );
		if ( ! $unit instanceof WP_Post || 'lps_unit' !== $unit->post_type || 'trash' === $unit->post_status ) {
			self::fail( 'lps_teaching_unit_invalid' );
		}
		$offering_id = Roles::persisted_offering_id( $unit );
		if ( ! self::may( 'edit', 'lps_unit', $offering_id ) ) {
			self::fail( 'lps_dashboard_scope' );
		}
		$title    = self::post_text( 'title' );
		$excerpt  = self::post_text( 'excerpt' );
		$content  = self::post_body( 'content' );
		$anchor   = self::post_text( 'anchor' );
		$position = self::post_int( 'position' );
		$date     = self::post_text( 'topic_date' );
		$errors   = array();
		if ( '' === $title ) {
			$errors['title'] = 'lps_required_title';
		}
		if ( '' === $anchor ) {
			$errors['anchor'] = 'lps_required_anchor';
		}
		if ( 1 > $position ) {
			$errors['position'] = 'lps_invalid_unit_position';
		}
		if ( '' !== $date && '' === TeachingContracts::normalize_iso_date( $date ) ) {
			$errors['topic_date'] = 'lps_invalid_topic_date';
		}
		if ( array() !== $errors ) {
			self::fail( (string) reset( $errors ), (string) array_key_first( $errors ) );
		}
		$updated = wp_update_post(
			array(
				'ID'           => $unit_id,
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
				'post_content' => $content,
			),
			true
		);
		if ( $updated instanceof WP_Error ) {
			self::fail( 'lps_dashboard_forbidden' );
		}
		update_post_meta( $unit_id, '_lps_anchor', $anchor );
		update_post_meta( $unit_id, '_lps_position', $position );
		update_post_meta( $unit_id, '_lps_topic_date', TeachingContracts::normalize_iso_date( $date ) );
		Audit::record(
			'edit',
			$unit_id,
			0,
			array(
				'decision'    => 'unit-edit',
				'offering_id' => $offering_id,
			)
		);
		self::succeed( 'saved' );
	}

	/**
	 * Runs one canonical create with the scoped field guard lifted.
	 *
	 * `create_unit` and `create_offering` write their allowlisted meta inside
	 * `wp_insert_post`, before the canonical relationship row exists, so the
	 * persisted-scope check inside `protect_role_meta` cannot resolve yet. The
	 * service is the authorized boundary for those writes — the same
	 * convention `TeachingCopy::call_guarded` uses — so the guard lifts for
	 * the duration of the call and restores immediately.
	 *
	 * @param callable(): (array<string, mixed>|WP_Error) $call Boundary call.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function call_guarded( callable $call ): array|WP_Error {
		remove_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11 );
		try {
			return $call();
		} finally {
			add_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11, 5 );
		}
	}

	/**
	 * Returns whether the account may create a course through the trusted lane.
	 *
	 * Professors are trusted to mint their own course and first offering — the
	 * decision the site owner took instead of editorial review — as long as
	 * the privileged-session bar (enrolled second factor) is met. Institutional
	 * editors and administrators qualify through their collection rights.
	 *
	 * @param WP_User $user Signed-in account.
	 */
	private static function may_course( WP_User $user ): bool {
		$role = Roles::policy_role( $user );
		if ( Roles::current_user_can_action( 'create', 'teaching' ) ) {
			return true;
		}
		return 'professor' === $role
			&& 0 < self::person_for_user( $user->ID )
			&& SecurityPolicy::privileged_session_allowed( $role, MFA::is_enrolled( $user->ID ) );
	}

	/**
	 * Returns whether the account may act on one scoped record type.
	 *
	 * @param string $action      Action key.
	 * @param string $post_type   Governed record type.
	 * @param int    $offering_id Resolved offering ID (0 for the news scope).
	 */
	private static function may( string $action, string $post_type, int $offering_id ): bool {
		if ( 'lps_news' === $post_type ) {
			return Roles::current_user_can_scoped_action( $action, $post_type, 0 ) || Roles::current_user_can_action( $action, 'news' );
		}
		return Roles::current_user_can_scoped_action( $action, $post_type, $offering_id ) || Roles::current_user_can_action( $action, 'teaching' );
	}

	/**
	 * Returns whether the account may copy the addressed offering forward.
	 *
	 * @param int $offering_id Source offering record ID.
	 */
	private static function may_copy( int $offering_id ): bool {
		return Roles::current_user_can_scoped_action( 'copy-forward', 'lps_offering', $offering_id ) || Roles::current_user_can_action( 'create', 'teaching' );
	}

	/**
	 * Returns the delegate-prepared marker only when it is genuine provenance.
	 *
	 * The stamp names the draft's own author, so a marker that points anywhere
	 * else was written outside the stamp and is ignored wherever it matters.
	 *
	 * @param int $post_id Record to inspect.
	 * @return int Delegate account ID, or 0 when the marker is absent or foreign.
	 */
	private static function prepared_marker_for( int $post_id ): int {
		$marker = Policy::sanitize_integer( get_post_meta( $post_id, '_lps_prepared_by', true ) );
		return (int) get_post_field( 'post_author', $post_id ) === $marker ? $marker : 0;
	}

	/**
	 * Returns the display name of the delegate that prepared one record.
	 *
	 * @param int $user_id Delegate account ID (0 when unmarked).
	 */
	private static function prepared_name( int $user_id ): string {
		if ( 0 >= $user_id ) {
			return '';
		}
		$account = get_userdata( $user_id );
		if ( ! $account instanceof WP_User ) {
			return '';
		}
		$name = Policy::scalar_string( $account->display_name );
		return '' !== $name ? $name : Policy::scalar_string( $account->user_login );
	}

	/**
	 * Writes one system-owned meta value with the scoped guard lifted.
	 *
	 * @param int    $post_id Record ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value.
	 */
	private static function system_meta( int $post_id, string $key, mixed $value ): void {
		remove_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11 );
		update_post_meta( $post_id, $key, $value );
		add_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11, 5 );
	}

	/**
	 * Verifies the dashboard nonce for one action.
	 *
	 * @param string $action Action key.
	 */
	private static function verify_nonce( string $action ): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized as a scalar before verification.
		$raw_nonce = isset( $_POST['_lps_dashboard_nonce'] ) ? wp_unslash( $_POST['_lps_dashboard_nonce'] ) : '';
		$nonce     = is_string( $raw_nonce ) ? sanitize_text_field( $raw_nonce ) : '';
		return '' !== $nonce && wp_verify_nonce( $nonce, $action );
	}

	/**
	 * Reads one POST text field.
	 *
	 * @param string $key Field name.
	 */
	private static function post_text( string $key ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Sanitized as a scalar on return.
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Reads the uploaded file payload, or null when absent.
	 *
	 * @return array{name: string, tmp_name: string}|null
	 */
	private static function posted_file(): ?array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- The upload boundary validates the payload itself.
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : null;
		if ( null === $file ) {
			return null;
		}
		$name = Policy::scalar_string( $file['name'] ?? '' );
		$tmp  = Policy::scalar_string( $file['tmp_name'] ?? '' );
		if ( '' === $name || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return null;
		}
		return array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);
	}

	/**
	 * Reads a named upload payload with every field wp_handle_upload needs.
	 *
	 * @param string $key $_FILES subkey.
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
	 */
	private static function posted_upload( string $key ): ?array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- The upload boundary validates the payload itself.
		$file = isset( $_FILES[ $key ] ) && is_array( $_FILES[ $key ] ) ? $_FILES[ $key ] : null;
		if ( null === $file ) {
			return null;
		}
		$name = Policy::scalar_string( $file['name'] ?? '' );
		$tmp  = Policy::scalar_string( $file['tmp_name'] ?? '' );
		if ( '' === $name || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return null;
		}
		return array(
			'name'     => $name,
			'type'     => Policy::scalar_string( $file['type'] ?? '' ),
			'tmp_name' => $tmp,
			'error'    => Policy::sanitize_integer( $file['error'] ?? 0 ),
			'size'     => Policy::sanitize_integer( $file['size'] ?? 0 ),
		);
	}

	/**
	 * Moves a featured-image upload through the media boundary into an
	 * attachment the preview stage can own.
	 *
	 * The dashboard allowlist (JPG/PNG/WebP, 15 MiB) is checked before the
	 * media layer's own MIME and dimension policy runs inside
	 * `wp_handle_upload`, so a professor sees the dashboard message rather
	 * than a raw validator error. A successful upload is stamped with the
	 * provenance fields the public media contract requires — the submitting
	 * professor's person record supplies credit and rights-holder, matching
	 * how seeded institutional assets carry theirs.
	 *
	 * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file Uploaded file payload.
	 * @param string                                                                     $alt  Image description text.
	 * @param int                                                                        $owner_id Uploading account.
	 * @return array{id: int, url: string, name: string, alt: string}|WP_Error
	 */
	private static function stage_news_image( array $file, string $alt, int $owner_id ): array|WP_Error {
		$name = $file['name'];
		$tmp  = $file['tmp_name'];
		$mime = '';
		if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
			$checked = wp_check_filetype_and_ext(
				$tmp,
				$name,
				array(
					'jpg|jpeg' => 'image/jpeg',
					'png'      => 'image/png',
					'webp'     => 'image/webp',
				)
			);
			$mime    = Policy::scalar_string( $checked['type'] );
		}
		if ( ! in_array( $mime, self::NEWS_IMAGE_MIMES, true ) ) {
			return new WP_Error(
				'lps_upload_type_forbidden',
				'The file type is outside the dashboard upload allowlist.',
				array(
					'name'    => $name,
					'allowed' => self::NEWS_IMAGE_ALLOWED_LABEL,
				)
			);
		}
		$bytes = is_file( $tmp ) ? (int) filesize( $tmp ) : 0;
		if ( $bytes <= 0 || $bytes > self::NEWS_IMAGE_MAX_BYTES ) {
			return new WP_Error(
				'lps_upload_too_large',
				'The file exceeds the dashboard upload cap.',
				array(
					'name'  => $name,
					'limit' => '15 MB',
				)
			);
		}
		if ( defined( 'ABSPATH' ) ) {
			$includes = ABSPATH;
			if ( is_string( $includes ) ) {
				require_once $includes . 'wp-admin/includes/file.php';
				require_once $includes . 'wp-admin/includes/image.php';
			}
		}
		$handled = wp_handle_upload( $file, array( 'test_form' => false ) );
		$error   = Policy::scalar_string( $handled['error'] ?? '' );
		if ( '' !== $error ) {
			$code = 'lps_dashboard_upload';
			if ( 1 === preg_match( '/^\[([a-z0-9_]+)\]/', $error, $matches ) ) {
				$code = $matches[1];
			}
			return new WP_Error( $code, $error, array( 'name' => $name ) );
		}
		$path = Policy::scalar_string( $handled['file'] ?? '' );
		$url  = Policy::scalar_string( $handled['url'] ?? '' );
		if ( '' === $path || '' === $url ) {
			return new WP_Error( 'lps_dashboard_upload', 'The upload boundary returned no stored file.', array( 'name' => $name ) );
		}
		// Attachment creation and its generated meta are system writes: the
		// upload happens on the professor's behalf, so the scoped-field guard is
		// lifted for the whole staging segment the same way call_guarded lifts
		// it for record inserts.
		remove_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11 );
		try {
			$attachment_id = wp_insert_attachment(
				array(
					'post_mime_type' => '' !== Policy::scalar_string( $handled['type'] ?? '' ) ? Policy::scalar_string( $handled['type'] ?? '' ) : $mime,
					'post_title'     => sanitize_text_field( (string) pathinfo( $name, PATHINFO_FILENAME ) ),
					'post_excerpt'   => $alt,
					'post_status'    => 'inherit',
					'post_author'    => $owner_id,
				),
				$path,
				0,
				true
			);
			if ( $attachment_id instanceof WP_Error ) {
				return new WP_Error( 'lps_dashboard_upload', 'The attachment record could not be created.', array( 'name' => $name ) );
			}
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );
			$credit = self::person_display_name( $owner_id );
			// The submitter's upload is the attestation: the boundary stamps the
			// provenance the public render contract checks.
			update_post_meta( $attachment_id, '_lps_media_credit', $credit );
			update_post_meta( $attachment_id, '_lps_media_rights_holder', $credit );
			update_post_meta( $attachment_id, '_lps_media_license', 'institutional' );
			update_post_meta( $attachment_id, '_lps_media_source_url', home_url( '/' ) );
			update_post_meta( $attachment_id, '_lps_media_rights_status', 'cleared' );
			update_post_meta( $attachment_id, '_lps_media_privacy_status', 'not-required' );
			update_post_meta( $attachment_id, '_lps_media_caption_status', '' !== $alt ? 'provided' : 'not-required' );
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		} finally {
			add_filter( 'update_post_metadata', array( Plugin::class, 'protect_role_meta' ), 11, 5 );
		}
		return array(
			'id'   => (int) $attachment_id,
			'url'  => $url,
			'name' => $name,
			'alt'  => $alt,
		);
	}

	/**
	 * Stores the staged news preview, replacing any previous one.
	 *
	 * @param int                  $user_id Account ID.
	 * @param array<string, mixed> $payload Staged form values plus attachment data.
	 */
	private static function store_preview( int $user_id, array $payload ): void {
		$previous    = self::preview_payload( $user_id );
		$previous_id = Policy::sanitize_integer( $previous['attachment_id'] ?? 0 );
		$current_id  = Policy::sanitize_integer( $payload['attachment_id'] ?? 0 );
		if ( 0 < $previous_id && $previous_id !== $current_id ) {
			// An abandoned preview never orphans a staged attachment.
			wp_delete_attachment( $previous_id, true );
		}
		set_transient( self::PREVIEW_PREFIX . $user_id . '_news', $payload, self::RECALL_TTL );
	}

	/**
	 * Returns the staged news preview payload for one account.
	 *
	 * @param int $user_id Account ID.
	 * @return array<string, mixed>
	 */
	private static function preview_payload( int $user_id ): array {
		if ( ! function_exists( 'get_transient' ) ) {
			return array();
		}
		$stored = get_transient( self::PREVIEW_PREFIX . $user_id . '_news' );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $stored_key => $value ) {
			if ( is_string( $stored_key ) ) {
				$out[ $stored_key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Returns the staged news preview for the signed-in account, or empty.
	 *
	 * @return array<string, mixed>
	 */
	public static function news_preview(): array {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return array();
		}
		$user = wp_get_current_user();
		return self::preview_payload( $user->ID );
	}

	/**
	 * Discards the staged preview and its staged attachment when possible.
	 *
	 * @param int $user_id Account ID.
	 */
	private static function discard_preview( int $user_id ): void {
		$payload = self::preview_payload( $user_id );
		$id      = Policy::sanitize_integer( $payload['attachment_id'] ?? 0 );
		delete_transient( self::PREVIEW_PREFIX . $user_id . '_news' );
		if ( 0 < $id ) {
			wp_delete_attachment( $id, true );
		}
	}

	/**
	 * Sanitizes a news topic key against the fixed topic list.
	 *
	 * @param string $category Submitted topic key.
	 */
	private static function sanitize_news_category( string $category ): string {
		return in_array( $category, self::NEWS_CATEGORIES, true ) ? $category : '';
	}

	/**
	 * Returns the news topic options for the dashboard select.
	 *
	 * @return array<int, array{id: string, title: string}>
	 */
	public static function news_category_options(): array {
		$options = array();
		foreach ( self::NEWS_CATEGORIES as $key ) {
			$options[] = array(
				'id'    => $key,
				'title' => self::news_category_label( $key ),
			);
		}
		return $options;
	}

	/**
	 * Returns the pt-BR label for one news topic key.
	 *
	 * @param string $key Topic key.
	 */
	public static function news_category_label( string $key ): string {
		$labels = array(
			'pessoas'       => 'Pessoas',
			'pesquisa'      => 'Pesquisa',
			'historia'      => 'História',
			'ensino'        => 'Ensino',
			'institucional' => 'Institucional',
			'parceria'      => 'Parcerias',
		);
		return $labels[ $key ] ?? '';
	}

	/**
	 * Resolves the display name used to stamp media provenance.
	 *
	 * @param int $user_id Account ID.
	 */
	private static function person_display_name( int $user_id ): string {
		$person_id = self::person_for_user( $user_id );
		$title     = 0 < $person_id ? Policy::scalar_string( get_post_field( 'post_title', $person_id ) ) : '';
		if ( '' !== $title ) {
			return $title;
		}
		$user = get_user_by( 'id', $user_id );
		if ( $user instanceof WP_User ) {
			return '' !== $user->display_name ? $user->display_name : $user->user_login;
		}
		return 'LPS';
	}

	/**
	 * Assembles the resource create input from POST.
	 *
	 * @param int $offering_id Parent offering ID.
	 * @return array{title: string, excerpt: string, content: string, offering_id: int, unit_id: int, external_url: string, meta: array{_lps_resource_type: string, _lps_resource_language: string}}
	 */
	private static function resource_input( int $offering_id ): array {
		return array(
			'title'        => self::post_text( 'title' ),
			'excerpt'      => self::post_text( 'excerpt' ),
			'content'      => self::post_text( 'content' ),
			'offering_id'  => $offering_id,
			'unit_id'      => self::post_int( 'unit_id' ),
			'external_url' => self::post_text( 'external_url' ),
			'meta'         => array(
				'_lps_resource_type'     => self::post_text( 'resource_type' ),
				'_lps_resource_language' => self::post_text( 'resource_language' ),
			),
		);
	}

	/**
	 * Stores the submitted values for one form so a denied submit is recoverable.
	 *
	 * @param string               $key   Form key.
	 * @param array<string, mixed> $input Submitted values.
	 */
	private static function recall( string $key, array $input ): void {
		if ( ! function_exists( 'wp_get_current_user' ) || ! function_exists( 'set_transient' ) ) {
			return;
		}
		$user = wp_get_current_user();
		set_transient( self::RECALL_PREFIX . $user->ID . '_' . sanitize_key( $key ), $input, self::RECALL_TTL );
	}

	/**
	 * Returns the stored recall values for one form, or an empty array.
	 *
	 * @param string $key Form key.
	 * @return array<string, mixed>
	 */
	public static function recalled( string $key ): array {
		if ( ! function_exists( 'wp_get_current_user' ) || ! function_exists( 'get_transient' ) ) {
			return array();
		}
		$user   = wp_get_current_user();
		$stored = get_transient( self::RECALL_PREFIX . $user->ID . '_' . sanitize_key( $key ) );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $stored_key => $value ) {
			if ( is_string( $stored_key ) ) {
				$out[ $stored_key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Extracts the machine field name from a boundary error.
	 *
	 * @param WP_Error $error Boundary error.
	 */
	private static function error_field( WP_Error $error ): string {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['field'] ) ? Policy::scalar_string( $data['field'] ) : '';
	}

	/**
	 * Redirects back to the referring dashboard view with a success notice.
	 *
	 * @param string $code Notice code.
	 */
	private static function succeed( string $code ): never {
		self::redirect( array( 'lps_notice' => $code ) );
	}

	/**
	 * Redirects back to the referring dashboard view with a denial.
	 *
	 * @param string               $code   Error code.
	 * @param string               $field  Machine field name.
	 * @param array<string, mixed> $detail Optional one-shot detail (file name, limits) rendered by error_message.
	 */
	private static function fail( string $code, string $field = '', array $detail = array() ): never {
		if ( array() !== $detail && function_exists( 'set_transient' ) && function_exists( 'wp_get_current_user' ) ) {
			set_transient( self::ERROR_DETAIL_PREFIX . wp_get_current_user()->ID, $detail, self::RECALL_TTL );
		}
		$args = array( 'lps_error' => $code );
		if ( '' !== $field ) {
			$args['lps_field'] = $field;
		}
		self::redirect( $args );
	}

	/**
	 * Redirects back with an upload denial, carrying the file name through.
	 *
	 * @param WP_Error $error Boundary error; its data carries the file name.
	 * @param string   $field Machine field name.
	 */
	private static function fail_upload( WP_Error $error, string $field ): never {
		$data   = $error->get_error_data();
		$detail = array();
		if ( is_array( $data ) ) {
			foreach ( $data as $data_key => $value ) {
				if ( is_string( $data_key ) ) {
					$detail[ $data_key ] = $value;
				}
			}
		}
		self::fail( (string) $error->get_error_code(), $field, $detail );
	}

	/**
	 * Reads and consumes the one-shot error detail for the current account.
	 *
	 * @return array<string, mixed>
	 */
	private static function error_detail(): array {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'wp_get_current_user' ) ) {
			return array();
		}
		$key    = self::ERROR_DETAIL_PREFIX . wp_get_current_user()->ID;
		$detail = get_transient( $key );
		if ( false !== $detail ) {
			delete_transient( $key );
		}
		if ( ! is_array( $detail ) ) {
			return array();
		}
		$out = array();
		foreach ( $detail as $detail_key => $value ) {
			if ( is_string( $detail_key ) ) {
				$out[ $detail_key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Composes the professor-facing upload message naming the rejected file.
	 *
	 * @param string               $code    Typed error code.
	 * @param bool                 $english Whether the EN copy applies.
	 * @param array<string, mixed> $detail  One-shot detail stored by fail().
	 */
	private static function upload_message( string $code, bool $english, array $detail ): ?string {
		$name    = Policy::scalar_string( $detail['name'] ?? '' );
		$allowed = Policy::scalar_string( $detail['allowed'] ?? self::NEWS_IMAGE_ALLOWED_LABEL );
		$limit   = Policy::scalar_string( $detail['limit'] ?? '15 MB' );
		switch ( $code ) {
			case 'lps_upload_type_forbidden':
			case 'lps_media_mime_forbidden':
			case 'lps_media_executable_name_forbidden':
				return '' !== $name
					? sprintf( $english ? 'The file "%s" is not an accepted type. Allowed types: %s.' : 'O arquivo "%s" não é um tipo aceito. Tipos permitidos: %s.', $name, $allowed )
					: ( $english ? 'This file type is not accepted. Allowed types: ' . $allowed . '.' : 'Este tipo de arquivo não é aceito. Tipos permitidos: ' . $allowed . '.' );
			case 'lps_upload_too_large':
			case 'lps_media_file_too_large':
				return '' !== $name
					? sprintf( $english ? 'The file "%s" exceeds the %s limit.' : 'O arquivo "%s" excede o limite de %s.', $name, $limit )
					: ( $english ? 'The file exceeds the ' . $limit . ' limit.' : 'O arquivo excede o limite de ' . $limit . '.' );
			case 'lps_media_dimensions_too_large':
				return '' !== $name
					? sprintf( $english ? 'The image "%s" exceeds the maximum dimensions.' : 'A imagem "%s" excede as dimensões máximas.', $name )
					: ( $english ? 'The image exceeds the maximum dimensions.' : 'A imagem excede as dimensões máximas.' );
			case 'lps_media_duplicate_checksum':
				return '' !== $name
					? sprintf( $english ? 'The file "%s" is already in the media library.' : 'O arquivo "%s" já está na biblioteca de mídia.', $name )
					: ( $english ? 'This file is already in the media library.' : 'Este arquivo já está na biblioteca de mídia.' );
			case 'lps_media_active_document_forbidden':
				return '' !== $name
					? sprintf( $english ? 'The file "%s" carries content the media contract forbids.' : 'O arquivo "%s" carrega conteúdo proibido pelo contrato de mídia.', $name )
					: ( $english ? 'The file carries content the media contract forbids.' : 'O arquivo carrega conteúdo proibido pelo contrato de mídia.' );
			case 'lps_media_read_failed':
				return '' !== $name
					? sprintf( $english ? 'The file "%s" could not be read.' : 'O arquivo "%s" não pôde ser lido.', $name )
					: ( $english ? 'The uploaded file could not be read.' : 'O arquivo enviado não pôde ser lido.' );
			case 'lps_dashboard_upload':
				return '' !== $name
					? sprintf( $english ? 'The file "%s" could not be uploaded; try a valid file.' : 'O arquivo "%s" não pôde ser enviado; tente um arquivo válido.', $name )
					: null;
		}
		return null;
	}

	/**
	 * Redirects to the referring dashboard view, or the dashboard root.
	 *
	 * The referer is the dashboard URL the form rendered on, so the user
	 * lands back on the same task with the notice in the query string.
	 *
	 * @param array<string, string> $args Query arguments to add.
	 */
	private static function redirect( array $args ): never {
		$target = wp_get_referer();
		if ( ! is_string( $target ) || '' === $target ) {
			$target = home_url( '/pt-br/painel/' );
		}
		$target = remove_query_arg( array( 'lps_notice', 'lps_error', 'lps_field' ), $target );
		wp_safe_redirect( add_query_arg( $args, $target ) );
		exit;
	}
}
