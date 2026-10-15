<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invites members who have no passkey yet: finds them, issues single-use
 * invitation tokens, renders and sends the email.
 */
final class InviteService {

	public const OPTION          = 'riviantrackr_passkeys_invite_options';
	public const META_INVITE     = 'riviantrackr_passkeys_invite';
	public const META_INVITED_AT = 'riviantrackr_passkeys_invited_at';
	public const SETUP_ACTION    = 'passkey-setup';

	public const MODE_NEVER = 'never';
	public const MODE_STALE = 'stale';
	public const MODE_ALL   = 'all';

	/** @var array<string,mixed>|null */
	private ?array $cache = null;

	public function __construct(
		private Settings $settings,
		private CredentialStore $store
	) {}

	// ---- options -------------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return [
			/* translators: %s: site name */
			'subject'     => sprintf( __( 'Sign in to %s faster with a passkey', 'riviantrackr-passkeys' ), (string) get_bloginfo( 'name' ) ),
			'body'        => __(
				"Hi {name},\n\n{site} now supports passkeys, so you can sign in with Face ID, Touch ID, Windows Hello or a security key instead of typing a password. Passkeys can't be phished or guessed, and there's nothing to remember.\n\nIt takes about ten seconds. Tap the button below on the device you usually use, then follow the prompt from your browser.\n\nYou can add more devices later from your profile.",
				'riviantrackr-passkeys'
			),
			'expiry_days' => 7,
			'magic_link'  => true,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function options(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = array_merge( $this->defaults(), is_array( $stored ) ? $stored : [] );
		}
		return $this->cache;
	}

	/**
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->options()[ $key ] ?? null;
	}

	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * Settings API sanitizer.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : [];
		$defaults = $this->defaults();

		$subject = sanitize_text_field( (string) ( $input['subject'] ?? '' ) );
		$body    = trim( wp_strip_all_tags( (string) ( $input['body'] ?? '' ) ) );
		$days    = (int) ( $input['expiry_days'] ?? $defaults['expiry_days'] );

		$this->flush();

		return [
			'subject'     => '' !== $subject ? $subject : $defaults['subject'],
			'body'        => '' !== $body ? $body : $defaults['body'],
			'expiry_days' => max( 1, min( 30, $days ) ),
			'magic_link'  => ! empty( $input['magic_link'] ),
		];
	}

	// ---- URLs ----------------------------------------------------------

	/**
	 * The "Set up a passkey" page (wp-login.php?action=passkey-setup).
	 */
	public function setup_url( string $continue_to = '' ): string {
		$args = [ 'action' => self::SETUP_ACTION ];
		if ( '' !== $continue_to ) {
			$args['redirect_to'] = $continue_to;
		}
		return add_query_arg( $args, wp_login_url() );
	}

	/**
	 * Personal invitation link carrying the single-use token.
	 */
	public function invite_link( \WP_User $user, string $token ): string {
		return add_query_arg(
			[
				'action'      => self::SETUP_ACTION,
				'uid'         => $user->ID,
				'rtpk_invite' => $token,
			],
			wp_login_url()
		);
	}

	// ---- tokens --------------------------------------------------------

	/**
	 * Issue a new token for the user (replacing any previous one). Only a
	 * hash is stored; the plaintext goes into the email.
	 */
	public function create_token( \WP_User $user ): string {
		$token = Helpers::base64url_encode( random_bytes( 32 ) );
		$now   = time();
		$prev  = get_user_meta( $user->ID, self::META_INVITE, true );
		$count = is_array( $prev ) ? (int) ( $prev['count'] ?? 0 ) : 0;

		update_user_meta(
			$user->ID,
			self::META_INVITE,
			[
				'hash'    => hash( 'sha256', $token ),
				'expires' => $now + $this->expiry_seconds(),
				'sent'    => $now,
				'count'   => $count + 1,
			]
		);
		update_user_meta( $user->ID, self::META_INVITED_AT, $now );

		return $token;
	}

	/**
	 * Return the user when the token matches and has not expired.
	 */
	public function validate_token( int $user_id, string $token ): ?\WP_User {
		if ( $user_id <= 0 || '' === $token || strlen( $token ) > 128 ) {
			return null;
		}
		$record = get_user_meta( $user_id, self::META_INVITE, true );
		if ( ! is_array( $record ) || empty( $record['hash'] ) ) {
			return null;
		}
		if ( (int) ( $record['expires'] ?? 0 ) < time() ) {
			return null;
		}
		if ( ! hash_equals( (string) $record['hash'], hash( 'sha256', $token ) ) ) {
			return null;
		}
		$user = get_user_by( 'id', $user_id );
		return $user instanceof \WP_User ? $user : null;
	}

	/**
	 * Invalidate the user's outstanding token (keeps the invited-at marker).
	 */
	public function consume_token( int $user_id ): void {
		delete_user_meta( $user_id, self::META_INVITE );
	}

	public function expiry_seconds(): int {
		return max( 1, (int) $this->get( 'expiry_days' ) ) * DAY_IN_SECONDS;
	}

	public function magic_link_enabled(): bool {
		return (bool) $this->get( 'magic_link' );
	}

	// ---- email ---------------------------------------------------------

	/**
	 * Fill template placeholders.
	 */
	public function fill( string $template, \WP_User $user, string $link ): string {
		$replacements = [
			'{name}'      => '' !== $user->display_name ? $user->display_name : $user->user_login,
			'{site}'      => (string) get_bloginfo( 'name' ),
			'{link}'      => $link,
			'{expires}'   => (string) (int) $this->get( 'expiry_days' ),
			'{login_url}' => wp_login_url(),
		];
		return strtr( $template, $replacements );
	}

	/**
	 * Build the email.
	 *
	 * @param \WP_User   $user     Recipient.
	 * @param string     $link     Personal invitation link.
	 * @param array|null $template Optional subject/body override (e.g. an unsaved draft).
	 * @return array{subject:string,html:string,text:string}
	 */
	public function render_email( \WP_User $user, string $link, ?array $template = null ): array {
		$subject_tpl = (string) ( $template['subject'] ?? $this->get( 'subject' ) );
		$body_tpl    = (string) ( $template['body'] ?? $this->get( 'body' ) );

		$subject = $this->fill( $subject_tpl, $user, $link );
		$body    = $this->fill( $body_tpl, $user, $link );
		$site    = (string) get_bloginfo( 'name' );
		$days    = (int) $this->get( 'expiry_days' );
		$button  = __( 'Set up my passkey', 'riviantrackr-passkeys' );
		/* translators: %d: number of days */
		$expiry = sprintf( _n( 'This link is personal to you and expires in %d day.', 'This link is personal to you and expires in %d days.', $days, 'riviantrackr-passkeys' ), $days );
		$ignore = __( 'If you didn\'t expect this email you can safely ignore it; nothing changes on your account until you create a passkey.', 'riviantrackr-passkeys' );

		$paragraphs = array_filter( array_map( 'trim', preg_split( '/\n{2,}/', $body ) ?: [] ) );
		$body_html  = '';
		foreach ( $paragraphs as $paragraph ) {
			$body_html .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#1d1d1f;">' . nl2br( esc_html( $paragraph ) ) . '</p>';
		}

		$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',sans-serif;-webkit-font-smoothing:antialiased;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f7;padding:32px 16px;"><tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border:1px solid #d2d2d7;border-radius:12px;">'
			. '<tr><td style="padding:28px 32px 8px;font-size:13px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase;color:#6e6e73;">' . esc_html( $site ) . '</td></tr>'
			. '<tr><td style="padding:0 32px 8px;font-size:22px;font-weight:700;line-height:1.3;color:#1d1d1f;">' . esc_html( $subject ) . '</td></tr>'
			. '<tr><td style="padding:8px 32px 0;">' . $body_html . '</td></tr>'
			. '<tr><td style="padding:8px 32px 24px;">'
			. '<a href="' . esc_url( $link ) . '" style="display:inline-block;padding:14px 24px;background:#fba919;color:#15130e;border-radius:8px;font-size:15px;font-weight:600;text-decoration:none;">' . esc_html( $button ) . '</a>'
			. '</td></tr>'
			. '<tr><td style="padding:0 32px 28px;font-size:13px;line-height:1.5;color:#6e6e73;">'
			. esc_html( $expiry ) . ' ' . esc_html( $ignore )
			. '<br><br>' . esc_html__( 'Button not working? Copy this link into your browser:', 'riviantrackr-passkeys' ) . '<br>'
			. '<a href="' . esc_url( $link ) . '" style="color:#0071e3;word-break:break-all;">' . esc_html( $link ) . '</a>'
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';

		$text = $body . "\n\n" . $button . ': ' . $link . "\n\n" . $expiry . ' ' . $ignore . "\n";

		return [
			'subject' => $subject,
			'html'    => $html,
			'text'    => $text,
		];
	}

	/**
	 * Issue a token and send the invitation.
	 *
	 * @param \WP_User   $user     Recipient.
	 * @param array|null $template Optional subject/body override.
	 * @param bool       $record   False for test sends, which must not count as an invitation.
	 * @return true|\WP_Error
	 */
	public function send( \WP_User $user, ?array $template = null, bool $record = true ) {
		if ( ! is_email( $user->user_email ) ) {
			return new \WP_Error( 'rtpk_no_email', __( 'This user has no valid email address.', 'riviantrackr-passkeys' ) );
		}

		$token = $this->create_token( $user );
		if ( ! $record ) {
			// Test sends must not count as an invitation.
			delete_user_meta( $user->ID, self::META_INVITED_AT );
		}

		$link  = $this->invite_link( $user, $token );
		$email = $this->render_email( $user, $link, $template );

		$mail = [
			'to'      => $user->user_email,
			'subject' => $email['subject'],
			'message' => $email['html'],
			'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
		];

		/**
		 * Filter the invitation email before it is sent.
		 *
		 * @param array<string,mixed> $mail  to, subject, message (HTML), headers.
		 * @param \WP_User            $user  Recipient.
		 * @param string              $link  Personal invitation link.
		 */
		$mail = (array) apply_filters( 'riviantrackr_passkeys_invite_email', $mail, $user, $link );

		$sent = wp_mail( (string) $mail['to'], (string) $mail['subject'], (string) $mail['message'], (array) $mail['headers'] );
		if ( ! $sent ) {
			return new \WP_Error( 'rtpk_mail_failed', __( 'WordPress could not send the email. Check your mail configuration.', 'riviantrackr-passkeys' ) );
		}

		/**
		 * Fires after an invitation email has been sent.
		 *
		 * @param \WP_User $user Recipient.
		 */
		do_action( 'riviantrackr_passkeys_invite_sent', $user );

		return true;
	}

	// ---- eligibility ---------------------------------------------------

	/**
	 * @return int[]
	 */
	public function user_ids_with_passkeys(): array {
		global $wpdb;
		$table = Installer::table_name();
		$ids   = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	public function has_passkey( int $user_id ): bool {
		return $this->store->count_for_user( $user_id ) > 0;
	}

	/**
	 * IDs of users who have no passkey and match the filters.
	 *
	 * @param string[] $roles      Role slugs; empty = any role.
	 * @param string   $mode       never | stale | all.
	 * @param int      $stale_days For "stale": re-invite if last invite is older than this.
	 * @return int[]
	 */
	public function eligible_user_ids( array $roles, string $mode = self::MODE_NEVER, int $stale_days = 14 ): array {
		$args = [
			'fields'  => 'ID',
			'number'  => -1,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'exclude' => $this->user_ids_with_passkeys(),
		];
		if ( ! empty( $roles ) ) {
			$args['role__in'] = array_values( array_map( 'sanitize_key', $roles ) );
		}

		if ( self::MODE_NEVER === $mode ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => self::META_INVITED_AT,
					'compare' => 'NOT EXISTS',
				],
			];
		} elseif ( self::MODE_STALE === $mode ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				[
					'key'     => self::META_INVITED_AT,
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => self::META_INVITED_AT,
					'value'   => time() - max( 1, $stale_days ) * DAY_IN_SECONDS,
					'compare' => '<',
					'type'    => 'NUMERIC',
				],
			];
		}

		$query = new \WP_User_Query( $args );
		return array_map( 'intval', (array) $query->get_results() );
	}

	/**
	 * @return array{total:int,with:int,without:int,invited:int,converted:int}
	 */
	public function stats(): array {
		global $wpdb;
		$with  = $this->user_ids_with_passkeys();
		$total = (int) ( count_users()['total_users'] ?? 0 );

		$invited_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", self::META_INVITED_AT )
		);
		$invited_ids = array_map( 'intval', is_array( $invited_ids ) ? $invited_ids : [] );
		$converted   = count( array_intersect( $invited_ids, $with ) );

		return [
			'total'     => $total,
			'with'      => count( $with ),
			'without'   => max( 0, $total - count( $with ) ),
			'invited'   => count( $invited_ids ) - $converted,
			'converted' => $converted,
		];
	}

	/**
	 * Last invitation timestamp for a user, or 0.
	 */
	public function invited_at( int $user_id ): int {
		return (int) get_user_meta( $user_id, self::META_INVITED_AT, true );
	}
}
