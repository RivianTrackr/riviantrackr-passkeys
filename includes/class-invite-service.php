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
				"Hi {name},\n\n{site} now supports passkeys, so you can sign in with Face ID, Touch ID, Windows Hello or a security key instead of typing a password. Passkeys can't be phished or guessed, and there's nothing to remember.\n\nSigning in gets you the member side of {site}:\n\n* Comment on posts without the extra steps\n* Favorite tires and leave or edit your tire reviews\n* Get notified about new articles, editorials and features\n* More coming soon\n\nIt takes about ten seconds. Tap the button below on the device you usually use, then follow the prompt from your browser.\n\nYou can add more devices later from your profile.",
				'riviantrackr-passkeys'
			),
			'expiry_days' => 7,
			'magic_link'  => true,
			'from_name'   => '',
			'from_email'  => '',
			'logo_id'     => 0,
			'theme'       => 'dark',
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
		$theme   = (string) ( $input['theme'] ?? 'dark' );

		$from_raw   = trim( (string) ( $input['from_email'] ?? '' ) );
		$from_email = '' !== $from_raw ? sanitize_email( $from_raw ) : '';
		if ( '' !== $from_raw && ! is_email( $from_email ) ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( self::OPTION, 'rtpk_from_email', __( 'The “from” address is not a valid email address and was cleared.', 'riviantrackr-passkeys' ) );
			}
			$from_email = '';
		}

		$this->flush();

		return [
			'subject'     => '' !== $subject ? $subject : $defaults['subject'],
			'body'        => '' !== $body ? $body : $defaults['body'],
			'expiry_days' => max( 1, min( 30, $days ) ),
			'magic_link'  => ! empty( $input['magic_link'] ),
			'from_name'   => sanitize_text_field( (string) ( $input['from_name'] ?? '' ) ),
			'from_email'  => $from_email,
			'logo_id'     => absint( $input['logo_id'] ?? 0 ),
			'theme'       => in_array( $theme, [ 'dark', 'light' ], true ) ? $theme : 'dark',
		];
	}

	// ---- branding -----------------------------------------------------

	/**
	 * Logo for the email header: the chosen image, else the theme's custom
	 * logo, else the site icon, else nothing (site name is shown instead).
	 */
	public function logo_url( int $override_id = -1 ): string {
		$id = $override_id >= 0 ? $override_id : absint( $this->get( 'logo_id' ) );
		if ( $id > 0 ) {
			$url = wp_get_attachment_image_url( $id, 'medium' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		$custom = absint( get_theme_mod( 'custom_logo', 0 ) );
		if ( $custom > 0 ) {
			$url = wp_get_attachment_image_url( $custom, 'medium' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		$icon = (string) get_site_icon_url( 192 );
		return $icon;
	}

	/**
	 * Email colours. Dark is the site's "ink & brass" palette.
	 *
	 * @return array<string,string>
	 */
	public function palette( string $theme = '' ): array {
		$theme = '' !== $theme ? $theme : (string) $this->get( 'theme' );
		if ( 'light' === $theme ) {
			return [
				'bg'        => '#f5f5f7',
				'card'      => '#ffffff',
				'border'    => '#d2d2d7',
				'heading'   => '#1d1d1f',
				'text'      => '#1d1d1f',
				'secondary' => '#6e6e73',
				'muted'     => '#86868b',
				'accent'    => '#fba919',
				'on_accent' => '#15130e',
				'link'      => '#0071e3',
			];
		}
		return [
			'bg'        => '#121418',
			'card'      => '#16191e',
			'border'    => '#3a3e45',
			'heading'   => '#f6f4f0',
			'text'      => '#ece9e4',
			'secondary' => '#a6a39c',
			'muted'     => '#79766f',
			'accent'    => '#fba919',
			'on_accent' => '#15130e',
			'link'      => '#fba919',
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
	 * Plain text → email HTML. Blank lines separate blocks; a block whose
	 * lines all start with "* " or "- " becomes a bulleted list.
	 *
	 * @param string               $body Filled-in message.
	 * @param array<string,string> $c    Palette.
	 */
	public function body_to_html( string $body, array $c ): string {
		$blocks = array_filter( array_map( 'trim', preg_split( '/\n{2,}/', str_replace( "\r\n", "\n", $body ) ) ?: [] ) );
		$html   = '';

		foreach ( $blocks as $block ) {
			$lines = array_map( 'trim', explode( "\n", $block ) );
			$items = [];
			foreach ( $lines as $line ) {
				if ( preg_match( '/^[*\-\x{2022}]\s+(.+)$/u', $line, $m ) ) {
					$items[] = $m[1];
				} else {
					$items = [];
					break;
				}
			}

			if ( ! empty( $items ) ) {
				$html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;">';
				foreach ( $items as $item ) {
					$html .= '<tr>'
						. '<td valign="top" style="padding:0 10px 6px 2px;font-size:15px;line-height:1.6;color:' . $c['accent'] . ';">&#8226;</td>'
						. '<td valign="top" style="padding:0 0 6px;font-size:15px;line-height:1.6;color:' . $c['text'] . ';">' . esc_html( $item ) . '</td>'
						. '</tr>';
				}
				$html .= '</table>';
				continue;
			}

			$html .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:' . $c['text'] . ';">' . nl2br( esc_html( $block ) ) . '</p>';
		}

		return $html;
	}

	/**
	 * Build the invitation email.
	 *
	 * @param \WP_User   $user     Recipient.
	 * @param string     $link     Personal invitation link.
	 * @param array|null $template Optional overrides: subject, body, logo_id, theme (e.g. an unsaved draft).
	 * @return array{subject:string,html:string,text:string}
	 */
	public function render_email( \WP_User $user, string $link, ?array $template = null ): array {
		$subject_tpl = (string) ( $template['subject'] ?? $this->get( 'subject' ) );
		$body_tpl    = (string) ( $template['body'] ?? $this->get( 'body' ) );
		$days        = (int) $this->get( 'expiry_days' );

		return $this->render_branded(
			[
				'subject'  => $this->fill( $subject_tpl, $user, $link ),
				'body'     => $this->fill( $body_tpl, $user, $link ),
				'link'     => $link,
				'button'   => __( 'Set up my passkey', 'riviantrackr-passkeys' ),
				/* translators: %d: number of days */
				'footnote' => sprintf( _n( 'This link is personal to you and expires in %d day.', 'This link is personal to you and expires in %d days.', $days, 'riviantrackr-passkeys' ), $days )
					. ' ' . __( 'If you didn\'t expect this email you can safely ignore it; nothing changes on your account until you create a passkey.', 'riviantrackr-passkeys' ),
			],
			$template
		);
	}

	/**
	 * Render any message in the branded HTML template (logo, palette, gold
	 * button, fallback link). Shared by invitations and signup verification.
	 *
	 * @param array<string,string> $args     subject, body (plain text), link, button, footnote.
	 * @param array|null           $template Optional overrides: logo_id, theme.
	 * @return array{subject:string,html:string,text:string}
	 */
	public function render_branded( array $args, ?array $template = null ): array {
		$logo = $this->logo_url( isset( $template['logo_id'] ) ? absint( $template['logo_id'] ) : -1 );
		$c    = $this->palette( (string) ( $template['theme'] ?? '' ) );

		$subject  = (string) ( $args['subject'] ?? '' );
		$body     = (string) ( $args['body'] ?? '' );
		$link     = (string) ( $args['link'] ?? '' );
		$button   = (string) ( $args['button'] ?? '' );
		$footnote = (string) ( $args['footnote'] ?? '' );
		$site     = (string) get_bloginfo( 'name' );
		$home     = home_url( '/' );
		$font     = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";

		$body_html = $this->body_to_html( $body, $c );

		$header = '' !== $logo
			? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $site ) . '" height="48" style="display:block;height:48px;width:auto;max-width:220px;border:0;">'
			: '<span style="font-size:18px;font-weight:700;letter-spacing:-0.2px;color:' . $c['heading'] . ';">' . esc_html( $site ) . '</span>';

		$button_html = '';
		if ( '' !== $link && '' !== $button ) {
			$button_html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 20px;"><tr><td style="border-radius:8px;background:' . $c['accent'] . ';">'
				. '<a href="' . esc_url( $link ) . '" style="display:inline-block;padding:14px 26px;font-size:15px;font-weight:600;line-height:1.2;color:' . $c['on_accent'] . ';text-decoration:none;border-radius:8px;">' . esc_html( $button ) . '</a>'
				. '</td></tr></table>';
		}

		$fallback_html = '';
		if ( '' !== $link ) {
			$fallback_html = '<p style="margin:0;font-size:12px;line-height:1.5;color:' . $c['muted'] . ';">' . esc_html__( 'Button not working? Copy this link into your browser:', 'riviantrackr-passkeys' ) . '<br>'
				. '<a href="' . esc_url( $link ) . '" style="color:' . $c['link'] . ';word-break:break-all;">' . esc_html( $link ) . '</a></p>';
		}

		$footnote_html = '' !== $footnote
			? '<p style="margin:0 0 10px;font-size:13px;line-height:1.5;color:' . $c['secondary'] . ';">' . esc_html( $footnote ) . '</p>'
			: '';

		$html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
			. '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
			. '<title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:' . $c['bg'] . ';font-family:' . $font . ';-webkit-font-smoothing:antialiased;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $c['bg'] . ';"><tr><td align="center" style="padding:32px 16px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;">'
			. '<tr><td align="center" style="padding:0 0 20px;">' . $header . '</td></tr>'
			. '<tr><td style="background:' . $c['card'] . ';border:1px solid ' . $c['border'] . ';border-radius:12px;padding:32px;">'
			. '<h1 style="margin:0 0 16px;font-size:22px;font-weight:700;line-height:1.3;letter-spacing:-0.2px;color:' . $c['heading'] . ';">' . esc_html( $subject ) . '</h1>'
			. $body_html
			. $button_html
			. $footnote_html
			. $fallback_html
			. '</td></tr>'
			. '<tr><td align="center" style="padding:20px 8px 0;font-size:12px;line-height:1.5;color:' . $c['muted'] . ';">'
			. '<a href="' . esc_url( $home ) . '" style="color:' . $c['muted'] . ';text-decoration:none;">' . esc_html( $site ) . '</a>'
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';

		$text = $body;
		if ( '' !== $link ) {
			$text .= "\n\n" . ( '' !== $button ? $button . ': ' : '' ) . $link;
		}
		if ( '' !== $footnote ) {
			$text .= "\n\n" . $footnote;
		}
		$text .= "\n";

		return [
			'subject' => $subject,
			'html'    => $html,
			'text'    => $text,
		];
	}

	/**
	 * Send an HTML email through wp_mail() with the configured from name and
	 * address applied for the duration of the call only.
	 *
	 * @param array<string,mixed> $mail to, subject, message (HTML), headers.
	 */
	public function deliver( array $mail ): bool {
		$headers = (array) ( $mail['headers'] ?? [ 'Content-Type: text/html; charset=UTF-8' ] );

		$from_email = (string) $this->get( 'from_email' );
		$from_name  = (string) $this->get( 'from_name' );
		$from_cb    = static fn(): string => $from_email;
		$name_cb    = static fn(): string => $from_name;
		if ( '' !== $from_email ) {
			add_filter( 'wp_mail_from', $from_cb, 20 );
		}
		if ( '' !== $from_name ) {
			add_filter( 'wp_mail_from_name', $name_cb, 20 );
		}

		$sent = wp_mail( (string) ( $mail['to'] ?? '' ), (string) ( $mail['subject'] ?? '' ), (string) ( $mail['message'] ?? '' ), $headers );

		if ( '' !== $from_email ) {
			remove_filter( 'wp_mail_from', $from_cb, 20 );
		}
		if ( '' !== $from_name ) {
			remove_filter( 'wp_mail_from_name', $name_cb, 20 );
		}

		return (bool) $sent;
	}

	/**
	 * Issue a token and send the invitation.
	 *
	 * @param \WP_User   $user     Recipient.
	 * @param array|null $template Optional overrides: subject, body, logo_id, theme.
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

		$sent = $this->deliver( $mail );

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
