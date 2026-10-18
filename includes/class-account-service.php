<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profile, email, password and session changes for the member account page.
 * The validation is pure so it can be tested without WordPress.
 */
final class AccountService {

	public const DISPLAY_NAME_MAX = 100;
	public const NAME_MAX         = 100;
	public const BIO_MAX          = 1000;
	public const PASSWORD_MIN     = 8;

	/** User meta key WordPress uses for a pending email change (wp-admin/user-edit.php). */
	public const NEW_EMAIL_META = '_new_email';

	/**
	 * Sanitise and validate submitted profile fields.
	 *
	 * @param array<string,mixed> $input   display_name, first_name, last_name, email, url, description.
	 * @param int                 $user_id The member being edited.
	 * @return array{data:array<string,string>,errors:array<string,string>} Clean values and per-field errors.
	 */
	public static function validate_profile( array $input, int $user_id ): array {
		$errors = [];
		$data   = [];

		$display = self::clip( sanitize_text_field( (string) ( $input['display_name'] ?? '' ) ), self::DISPLAY_NAME_MAX );
		if ( '' === $display ) {
			$errors['display_name'] = __( 'Please enter a display name.', 'riviantrackr-passkeys' );
		}
		$data['display_name'] = $display;

		$data['first_name'] = self::clip( sanitize_text_field( (string) ( $input['first_name'] ?? '' ) ), self::NAME_MAX );
		$data['last_name']  = self::clip( sanitize_text_field( (string) ( $input['last_name'] ?? '' ) ), self::NAME_MAX );

		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'riviantrackr-passkeys' );
		} else {
			$owner = email_exists( $email );
			if ( $owner && (int) $owner !== $user_id ) {
				$errors['email'] = __( 'That email address is already used by another account.', 'riviantrackr-passkeys' );
			}
		}
		$data['email'] = $email;

		$url = trim( (string) ( $input['url'] ?? '' ) );
		if ( '' !== $url ) {
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				$url = 'https://' . $url;
			}
			$url = esc_url_raw( $url );
			if ( '' === $url || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
				$errors['url'] = __( 'Please enter a valid website address.', 'riviantrackr-passkeys' );
			}
		}
		$data['url'] = self::clip( $url, 200 );

		$data['description'] = self::clip( trim( wp_strip_all_tags( (string) ( $input['description'] ?? '' ) ) ), self::BIO_MAX );

		return [
			'data'   => $data,
			'errors' => $errors,
		];
	}

	/**
	 * Validate a password change. Pure apart from wp_check_password().
	 *
	 * @param string $current  Current password as typed.
	 * @param string $new_password New password.
	 * @param string $confirm  Repeated new password.
	 * @param string $hash     The user's stored password hash.
	 * @param int    $user_id  The user.
	 * @return array<string,string> Per-field errors; empty when valid.
	 */
	public static function validate_password( string $current, string $new_password, string $confirm, string $hash, int $user_id ): array {
		$errors = [];
		if ( '' === $current ) {
			$errors['current'] = __( 'Enter your current password.', 'riviantrackr-passkeys' );
		} elseif ( ! wp_check_password( $current, $hash, $user_id ) ) {
			$errors['current'] = __( 'Your current password is incorrect.', 'riviantrackr-passkeys' );
		}
		if ( strlen( $new_password ) < self::PASSWORD_MIN ) {
			$errors['new'] = sprintf(
				/* translators: %d: minimum length */
				__( 'Use at least %d characters.', 'riviantrackr-passkeys' ),
				self::PASSWORD_MIN
			);
		} elseif ( '' !== $current && hash_equals( $current, $new_password ) ) {
			$errors['new'] = __( 'Choose a password you have not used here before.', 'riviantrackr-passkeys' );
		}
		if ( $new_password !== $confirm ) {
			$errors['confirm'] = __( 'The passwords do not match.', 'riviantrackr-passkeys' );
		}
		return $errors;
	}

	/**
	 * Persist validated profile fields. An email change is not applied
	 * directly: like the WordPress profile screen, a confirmation link is
	 * emailed to the new address and the change completes when it is
	 * clicked (wp-admin/profile.php?newuseremail=…).
	 *
	 * @param \WP_User             $user The member.
	 * @param array<string,string> $data Output of validate_profile().
	 * @return array{user:\WP_User,pending_email:string}|\WP_Error
	 */
	public static function save_profile( \WP_User $user, array $data ) {
		$result = wp_update_user(
			[
				'ID'           => $user->ID,
				'display_name' => $data['display_name'],
				'first_name'   => $data['first_name'],
				'last_name'    => $data['last_name'],
				'user_url'     => $data['url'],
				'description'  => $data['description'],
			]
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$pending = '';
		if ( strtolower( $data['email'] ) !== strtolower( $user->user_email ) ) {
			$pending = self::request_email_change( $user, $data['email'] );
		}

		$fresh = get_user_by( 'id', $user->ID );
		return [
			'user'          => $fresh instanceof \WP_User ? $fresh : $user,
			'pending_email' => $pending,
		];
	}

	/**
	 * Store the pending address the way wp-admin/user-edit.php expects and
	 * email the confirmation link. Returns the pending address.
	 */
	public static function request_email_change( \WP_User $user, string $new_email ): string {
		$hash = md5( $new_email . time() . wp_rand() );
		update_user_meta(
			$user->ID,
			self::NEW_EMAIL_META,
			[
				'hash'     => $hash,
				'newemail' => $new_email,
			]
		);

		$sitename = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$link     = esc_url_raw( add_query_arg( 'newuseremail', $hash, admin_url( 'profile.php' ) ) );

		/* translators: Do not translate USERNAME, ADMIN_URL, EMAIL, SITENAME, SITEURL: those are placeholders. */
		$content = __(
			'Howdy ###USERNAME###,

You recently requested to have the email address on your account changed.

If this is correct, please click on the following link to change it:
###ADMIN_URL###

You can safely ignore and delete this email if you do not want to
take this action.

This email has been sent to ###EMAIL###

Regards,
All at ###SITENAME###
###SITEURL###',
			'riviantrackr-passkeys'
		);

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		/** This filter is documented in wp-includes/user.php */
		$content = (string) apply_filters(
			'new_user_email_content',
			$content,
			[
				'hash'     => $hash,
				'newemail' => $new_email,
			]
		);

		$content = str_replace( '###USERNAME###', $user->user_login, $content );
		// phpcs:enable
		$content = str_replace( '###ADMIN_URL###', $link, $content );
		$content = str_replace( '###EMAIL###', $new_email, $content );
		$content = str_replace( '###SITENAME###', $sitename, $content );
		$content = str_replace( '###SITEURL###', home_url(), $content );

		wp_mail(
			$new_email,
			sprintf(
				/* translators: %s: site name */
				__( '[%s] Email Change Request', 'riviantrackr-passkeys' ),
				$sitename
			),
			$content
		);

		return $new_email;
	}

	/**
	 * The address awaiting confirmation, or ''.
	 */
	public static function pending_email( int $user_id ): string {
		$meta = get_user_meta( $user_id, self::NEW_EMAIL_META, true );
		return is_array( $meta ) && ! empty( $meta['newemail'] ) ? (string) $meta['newemail'] : '';
	}

	public static function cancel_email_change( int $user_id ): void {
		delete_user_meta( $user_id, self::NEW_EMAIL_META );
	}

	/**
	 * Set a new password, drop every session (the old cookies are invalid
	 * anyway once the hash changes) and re-issue the current browser's
	 * cookie so the member stays signed in.
	 */
	public static function change_password( \WP_User $user, string $new_password ): void {
		wp_set_password( $new_password, $user->ID );

		$remember = false;
		$cookie   = wp_parse_auth_cookie( '', 'logged_in' );
		if ( is_array( $cookie ) && ! empty( $cookie['expiration'] ) ) {
			$remember = ( (int) $cookie['expiration'] - time() ) > ( 2 * DAY_IN_SECONDS );
		}

		\WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
	}

	/**
	 * Sign out every other browser/device. Returns how many sessions remain (1).
	 */
	public static function sign_out_elsewhere( int $user_id ): void {
		$token = wp_get_session_token();
		if ( '' !== $token ) {
			\WP_Session_Tokens::get_instance( $user_id )->destroy_others( $token );
		}
	}

	private static function clip( string $value, int $max ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return trim( mb_substr( $value, 0, $max ) );
		}
		return trim( substr( $value, 0, $max ) );
	}
}
