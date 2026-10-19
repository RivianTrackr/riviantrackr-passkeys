<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts the emails WordPress sends around sign-in into the same branded
 * template as invitations and signup verification: password reset, the
 * new-member welcome, the admin's new-member notice and the account page's
 * email-change confirmation. Logo, palette and from address come from
 * Users → Passkey invitations.
 *
 * Disable with `add_filter( 'riviantrackr_passkeys_brand_core_emails', '__return_false' )`.
 */
final class BrandedEmails {

	public const FILTER_ENABLED = 'riviantrackr_passkeys_brand_core_emails';

	/** Filter the account page's email-change confirmation goes through before wp_mail(). */
	public const EMAIL_CHANGE_FILTER = 'riviantrackr_passkeys_email_change_mail';

	public function __construct( private InviteService $invites ) {}

	public function register_hooks(): void {
		add_filter( 'retrieve_password_notification_email', [ $this, 'password_reset' ], 10, 4 );
		add_filter( 'wp_new_user_notification_email', [ $this, 'new_user' ], 10, 3 );
		add_filter( 'wp_new_user_notification_email_admin', [ $this, 'new_user_admin' ], 10, 3 );
		add_filter( self::EMAIL_CHANGE_FILTER, [ $this, 'email_change' ], 10, 4 );
	}

	private function enabled(): bool {
		/**
		 * Whether the plugin restyles WordPress's own emails (password reset,
		 * new-user notices, email-change confirmation) in the branded template.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'riviantrackr_passkeys_brand_core_emails', true );
	}

	/**
	 * Headers for a branded mail: HTML, plus the invitations from address when set.
	 *
	 * @return string[]
	 */
	public function headers(): array {
		$headers    = [ 'Content-Type: text/html; charset=UTF-8' ];
		$from_email = trim( (string) $this->invites->get( 'from_email' ) );
		$from_name  = trim( (string) $this->invites->get( 'from_name' ) );
		if ( '' !== $from_email && is_email( $from_email ) ) {
			$headers[] = '' !== $from_name
				? sprintf( 'From: %s <%s>', str_replace( [ "\r", "\n" ], '', $from_name ), $from_email )
				: 'From: ' . $from_email;
		}
		return $headers;
	}

	/**
	 * The password reset link exactly as core builds it, so LoginRouter can
	 * send it to the reset page.
	 */
	public static function reset_link( string $key, string $user_login ): string {
		return network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user_login ), 'login' );
	}

	/**
	 * Filters retrieve_password_notification_email.
	 *
	 * @param array<string,mixed> $mail       to, subject, message, headers.
	 * @param string              $key        Reset key.
	 * @param string              $user_login Username.
	 * @param \WP_User|mixed      $user_data  The user.
	 * @return array<string,mixed>
	 */
	public function password_reset( $mail, $key, $user_login, $user_data ): array {
		$mail = (array) $mail;
		if ( ! $this->enabled() || ! $user_data instanceof \WP_User ) {
			return $mail;
		}
		$site = (string) get_bloginfo( 'name' );
		$name = '' !== $user_data->display_name ? $user_data->display_name : $user_data->user_login;
		/** This filter is documented in wp-includes/user.php */
		$expires = (int) apply_filters( 'password_reset_expiration', DAY_IN_SECONDS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.

		$rendered = $this->invites->render_branded(
			[
				/* translators: %s: site name */
				'subject'  => sprintf( __( 'Reset your %s password', 'riviantrackr-passkeys' ), $site ),
				'body'     => sprintf(
					/* translators: 1: display name, 2: site name, 3: username */
					__( "Hi %1\$s,\n\nSomeone asked to reset the password for your %2\$s account (%3\$s). If that was you, use the button below to choose a new one.\n\nIf it wasn't you, ignore this email and nothing will change. Your current password keeps working, and so do your passkeys.", 'riviantrackr-passkeys' ),
					$name,
					$site,
					$user_data->user_login
				),
				'link'     => self::reset_link( (string) $key, (string) $user_login ),
				'button'   => __( 'Choose a new password', 'riviantrackr-passkeys' ),
				'footnote' => sprintf(
					/* translators: 1: how long the link lasts, 2: IP address */
					__( 'The link works once and expires in %1$s. This request came from the IP address %2$s.', 'riviantrackr-passkeys' ),
					human_time_diff( 0, $expires ),
					Helpers::client_ip()
				),
			]
		);

		$mail['subject'] = $rendered['subject'];
		$mail['message'] = $rendered['html'];
		$mail['headers'] = $this->headers();
		return $mail;
	}

	/**
	 * Filters wp_new_user_notification_email (the member's welcome, which
	 * carries a set-password link).
	 *
	 * @param array<string,mixed> $mail     to, subject, message, headers.
	 * @param \WP_User|mixed      $user     The new user.
	 * @param string              $blogname Site name.
	 * @return array<string,mixed>
	 */
	public function new_user( $mail, $user, $blogname ): array {
		$mail = (array) $mail;
		if ( ! $this->enabled() || ! $user instanceof \WP_User ) {
			return $mail;
		}
		$link = self::first_link( (string) ( $mail['message'] ?? '' ) );
		$name = '' !== $user->display_name ? $user->display_name : $user->user_login;

		$rendered = $this->invites->render_branded(
			[
				/* translators: %s: site name */
				'subject'  => sprintf( __( 'Welcome to %s', 'riviantrackr-passkeys' ), (string) $blogname ),
				'body'     => sprintf(
					/* translators: 1: display name, 2: site name, 3: username */
					__( "Hi %1\$s,\n\nYour %2\$s account is ready. Your username is %3\$s.\n\nUse the button below to choose a password, then add a passkey from your account page so you can sign in with Face ID, Touch ID, Windows Hello or a security key next time.", 'riviantrackr-passkeys' ),
					$name,
					(string) $blogname,
					$user->user_login
				),
				'link'     => $link,
				'button'   => '' !== $link ? __( 'Choose a password', 'riviantrackr-passkeys' ) : '',
				'footnote' => '' !== $link ? __( 'The link works once. If it has expired, use “Lost your password?” on the sign-in page to get a new one.', 'riviantrackr-passkeys' ) : '',
			]
		);

		$mail['subject'] = $rendered['subject'];
		$mail['message'] = $rendered['html'];
		$mail['headers'] = $this->headers();
		return $mail;
	}

	/**
	 * Filters wp_new_user_notification_email_admin.
	 *
	 * @param array<string,mixed> $mail     to, subject, message, headers.
	 * @param \WP_User|mixed      $user     The new user.
	 * @param string              $blogname Site name.
	 * @return array<string,mixed>
	 */
	public function new_user_admin( $mail, $user, $blogname ): array {
		$mail = (array) $mail;
		if ( ! $this->enabled() || ! $user instanceof \WP_User ) {
			return $mail;
		}
		$rendered = $this->invites->render_branded(
			[
				/* translators: %s: site name */
				'subject'  => sprintf( __( 'New member on %s', 'riviantrackr-passkeys' ), (string) $blogname ),
				'body'     => sprintf(
					/* translators: 1: username, 2: email address */
					__( "A new member just joined.\n\n* Username: %1\$s\n* Email: %2\$s", 'riviantrackr-passkeys' ),
					$user->user_login,
					$user->user_email
				),
				'link'     => admin_url( 'user-edit.php?user_id=' . (int) $user->ID ),
				'button'   => __( 'View member', 'riviantrackr-passkeys' ),
				'footnote' => '',
			]
		);

		$mail['subject'] = $rendered['subject'];
		$mail['message'] = $rendered['html'];
		$mail['headers'] = $this->headers();
		return $mail;
	}

	/**
	 * Filters the account page's email-change confirmation.
	 *
	 * @param array<string,mixed> $mail      to, subject, message, headers.
	 * @param \WP_User|mixed      $user      The member.
	 * @param string              $new_email The address awaiting confirmation.
	 * @param string              $link      Confirmation link.
	 * @return array<string,mixed>
	 */
	public function email_change( $mail, $user, $new_email, $link ): array {
		$mail = (array) $mail;
		if ( ! $this->enabled() || ! $user instanceof \WP_User ) {
			return $mail;
		}
		$site = (string) get_bloginfo( 'name' );
		$name = '' !== $user->display_name ? $user->display_name : $user->user_login;

		$rendered = $this->invites->render_branded(
			[
				/* translators: %s: site name */
				'subject'  => sprintf( __( 'Confirm your new %s email address', 'riviantrackr-passkeys' ), $site ),
				'body'     => sprintf(
					/* translators: 1: display name, 2: site name, 3: new email address */
					__( "Hi %1\$s,\n\nYou asked to change the email address on your %2\$s account to %3\$s. Confirm it with the button below and we'll switch over.\n\nIf you didn't request this, ignore this email and your address stays as it is.", 'riviantrackr-passkeys' ),
					$name,
					$site,
					(string) $new_email
				),
				'link'     => (string) $link,
				'button'   => __( 'Confirm new email', 'riviantrackr-passkeys' ),
				'footnote' => __( 'You can cancel the change any time from your account page.', 'riviantrackr-passkeys' ),
			]
		);

		$mail['subject'] = $rendered['subject'];
		$mail['message'] = $rendered['html'];
		$mail['headers'] = $this->headers();
		return $mail;
	}

	/**
	 * The first URL in a plain-text message (core's welcome email carries the
	 * set-password link this way).
	 */
	public static function first_link( string $message ): string {
		if ( preg_match( '~<?(https?://[^\s<>]+)>?~i', $message, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
