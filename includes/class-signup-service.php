<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verify-first signup: a visitor asks for an account, gets a single-use
 * link by email, and the WordPress user is only created when the link is
 * opened. Nothing is stored for the visitor beyond a pending row.
 */
final class SignupService {

	public const OPTION = 'riviantrackr_passkeys_signup_options';
	public const ACTION = 'passkey-signup';

	public const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	public const TURNSTILE_SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

	private const FORM_TOKEN_MAX_AGE = 12 * HOUR_IN_SECONDS;
	private const NAME_MAX_LENGTH    = 60;

	/** @var array<string,mixed>|null */
	private ?array $cache = null;

	public function __construct(
		private Settings $settings,
		private InviteService $invites,
		private PendingSignupStore $store,
		private RateLimiter $limiter
	) {}

	// ---- options -------------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return [
			'enabled'              => false,
			'role'                 => 'subscriber',
			'turnstile_site_key'   => '',
			'turnstile_secret_key' => '',
			'continue_url'         => '',
			'blocked_domains'      => '',
			'expiry_hours'         => 24,
			'notify_admin'         => true,
			/* translators: %s: site name */
			'subject'              => sprintf( __( 'Confirm your %s account', 'riviantrackr-passkeys' ), (string) get_bloginfo( 'name' ) ),
			'body'                 => __(
				"Hi {name},\n\nTap the button below to confirm your email address and finish creating your {site} account.\n\nNext you'll set up a passkey, so you can sign in with Face ID, Touch ID, Windows Hello or a security key. No password to remember, nothing to phish.\n\nIf you didn't ask for an account, ignore this email and nothing will be created.",
				'riviantrackr-passkeys'
			),
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

	public function enabled(): bool {
		return (bool) $this->get( 'enabled' );
	}

	public function turnstile_enabled(): bool {
		return '' !== (string) $this->get( 'turnstile_site_key' ) && '' !== (string) $this->get( 'turnstile_secret_key' );
	}

	public function expiry_seconds(): int {
		return max( 1, (int) $this->get( 'expiry_hours' ) ) * HOUR_IN_SECONDS;
	}

	public function role(): string {
		$role = sanitize_key( (string) $this->get( 'role' ) );
		if ( '' === $role || 'administrator' === $role || ! get_role( $role ) ) {
			$role = sanitize_key( (string) get_option( 'default_role', 'subscriber' ) );
		}
		return 'administrator' === $role || '' === $role ? 'subscriber' : $role;
	}

	/**
	 * Where the member lands after passkey setup.
	 */
	public function continue_url(): string {
		$url = trim( (string) $this->get( 'continue_url' ) );
		if ( '' !== $url ) {
			return $url;
		}
		$account = $this->settings->account_page_url();
		return '' !== $account ? $account : home_url( '/' );
	}

	/**
	 * @return string[]
	 */
	public function blocked_domains(): array {
		return DisposableDomains::parse_list( (string) $this->get( 'blocked_domains' ) );
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

		$role = sanitize_key( (string) ( $input['role'] ?? $defaults['role'] ) );
		if ( 'administrator' === $role || ! get_role( $role ) ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( self::OPTION, 'rtpk_signup_role', __( 'The signup role must be an existing role other than Administrator. It was reset to Subscriber.', 'riviantrackr-passkeys' ) );
			}
			$role = 'subscriber';
		}

		$continue_raw = trim( (string) ( $input['continue_url'] ?? '' ) );
		$continue_url = '' !== $continue_raw ? esc_url_raw( $continue_raw ) : '';

		$subject = sanitize_text_field( (string) ( $input['subject'] ?? '' ) );
		$body    = trim( wp_strip_all_tags( (string) ( $input['body'] ?? '' ) ) );
		$hours   = (int) ( $input['expiry_hours'] ?? $defaults['expiry_hours'] );

		$this->flush();

		return [
			'enabled'              => ! empty( $input['enabled'] ),
			'role'                 => $role,
			'turnstile_site_key'   => sanitize_text_field( (string) ( $input['turnstile_site_key'] ?? '' ) ),
			'turnstile_secret_key' => sanitize_text_field( (string) ( $input['turnstile_secret_key'] ?? '' ) ),
			'continue_url'         => $continue_url,
			'blocked_domains'      => implode( "\n", DisposableDomains::parse_list( (string) ( $input['blocked_domains'] ?? '' ) ) ),
			'expiry_hours'         => max( 1, min( 168, $hours ) ),
			'notify_admin'         => ! empty( $input['notify_admin'] ),
			'subject'              => '' !== $subject ? $subject : $defaults['subject'],
			'body'                 => '' !== $body ? $body : $defaults['body'],
		];
	}

	// ---- form token (minimum-time check) ------------------------------

	/**
	 * Values embedded in the form: when it was rendered and a signature so
	 * the timestamp cannot be forged.
	 *
	 * @return array{ts:int,sig:string}
	 */
	public function form_token(): array {
		$ts = time();
		return [
			'ts'  => $ts,
			'sig' => $this->sign_timestamp( $ts ),
		];
	}

	/**
	 * Whether the form was rendered by us, at least RTPK_SIGNUP_MIN_SECONDS
	 * ago and not so long ago that the page is stale.
	 */
	public function verify_form_token( string $ts, string $sig ): bool {
		if ( ! preg_match( '/^\d{1,12}$/', $ts ) || '' === $sig ) {
			return false;
		}
		if ( ! hash_equals( $this->sign_timestamp( (int) $ts ), $sig ) ) {
			return false;
		}
		$age = time() - (int) $ts;
		return $age >= RTPK_SIGNUP_MIN_SECONDS && $age <= self::FORM_TOKEN_MAX_AGE;
	}

	private function sign_timestamp( int $ts ): string {
		return (string) wp_hash( 'rtpk-signup|' . $ts );
	}

	// ---- request -------------------------------------------------------

	/**
	 * Handle a signup request. Never creates a user. Returns the same
	 * success payload whether or not an email was actually sent, so the
	 * response does not reveal which addresses have accounts.
	 *
	 * @param array<string,mixed> $input email, name, website (honeypot), ts, sig, turnstile.
	 * @param string              $ip    Client IP for rate limiting.
	 * @return array{success:bool,message:string}|\WP_Error
	 */
	public function handle_request( array $input, string $ip ) {
		if ( ! $this->enabled() ) {
			return new \WP_Error( 'rtpk_signup_disabled', __( 'Signups are closed right now.', 'riviantrackr-passkeys' ), [ 'status' => 403 ] );
		}
		if ( $this->limiter->is_limited( 'signup-ip', $ip, RTPK_SIGNUP_IP_RATE_LIMIT, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'rtpk_rate_limited', __( 'Too many signup attempts from your network. Please try again in an hour.', 'riviantrackr-passkeys' ), [ 'status' => 429 ] );
		}

		$success = [
			'success' => true,
			'message' => __( 'Check your inbox. If that address can be used here, we sent you a link to finish creating your account.', 'riviantrackr-passkeys' ),
		];

		// Honeypot: real browsers leave it empty; bots fill every field.
		if ( '' !== trim( (string) ( $input['website'] ?? '' ) ) ) {
			return $success;
		}

		if ( ! $this->verify_form_token( (string) ( $input['ts'] ?? '' ), (string) ( $input['sig'] ?? '' ) ) ) {
			return new \WP_Error( 'rtpk_signup_form', __( 'That was quick. Please wait a moment and try again, or reload the page if it has been open for a while.', 'riviantrackr-passkeys' ), [ 'status' => 400 ] );
		}

		$email = sanitize_email( trim( (string) ( $input['email'] ?? '' ) ) );
		if ( '' === $email || ! is_email( $email ) || strlen( $email ) > 100 ) {
			return new \WP_Error( 'rtpk_signup_email', __( 'Please enter a valid email address.', 'riviantrackr-passkeys' ), [ 'status' => 400 ] );
		}
		$email = strtolower( $email );

		$name = $this->sanitize_name( (string) ( $input['name'] ?? '' ) );

		if ( $this->turnstile_enabled() && ! $this->verify_turnstile( (string) ( $input['turnstile'] ?? '' ), $ip ) ) {
			return new \WP_Error( 'rtpk_signup_captcha', __( 'We could not confirm you are human. Please try again.', 'riviantrackr-passkeys' ), [ 'status' => 400 ] );
		}

		if ( DisposableDomains::is_disposable( $email, $this->blocked_domains() ) ) {
			return new \WP_Error( 'rtpk_signup_domain', __( 'Please use a permanent email address. Temporary inboxes cannot be used to sign up.', 'riviantrackr-passkeys' ), [ 'status' => 400 ] );
		}

		/**
		 * Allow or deny a signup request before anything is stored or sent.
		 *
		 * Return false or a WP_Error to deny.
		 *
		 * @param bool|\WP_Error      $allowed Whether the request may proceed.
		 * @param string              $email   Normalised email address.
		 * @param array<string,mixed> $input   Raw request fields.
		 */
		$allowed = apply_filters( 'riviantrackr_passkeys_signup_allowed', true, $email, $input );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! $allowed ) {
			return new \WP_Error( 'rtpk_signup_denied', __( 'Signups are not available for this address.', 'riviantrackr-passkeys' ), [ 'status' => 403 ] );
		}

		if ( $this->limiter->is_limited( 'signup-email', $email, RTPK_SIGNUP_EMAIL_RATE_LIMIT, HOUR_IN_SECONDS ) ) {
			return $success;
		}

		if ( email_exists( $email ) ) {
			// Tell the owner of the inbox, not the requester.
			$this->send_existing_account_notice( $email );
			return $success;
		}

		$this->store->purge_expired();
		$this->store->delete_for_email( $email );

		$token = Helpers::base64url_encode( random_bytes( 32 ) );
		$id    = $this->store->insert(
			[
				'token_hash'   => hash( 'sha256', $token ),
				'email'        => $email,
				'display_name' => $name,
				'ip_hash'      => hash_hmac( 'sha256', $ip, (string) wp_salt() ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + $this->expiry_seconds() ),
			]
		);
		if ( $id <= 0 ) {
			return new \WP_Error( 'rtpk_db', __( 'Something went wrong on our side. Please try again.', 'riviantrackr-passkeys' ), [ 'status' => 500 ] );
		}

		if ( ! $this->send_verification( $email, $name, $this->signup_link( $token ) ) ) {
			$this->store->delete( $id );
			return new \WP_Error( 'rtpk_mail_failed', __( 'We could not send the email. Please try again later.', 'riviantrackr-passkeys' ), [ 'status' => 500 ] );
		}

		/**
		 * Fires after a verification email has been sent for a new signup.
		 *
		 * @param string $email Address the link was sent to.
		 */
		do_action( 'riviantrackr_passkeys_signup_requested', $email );

		return $success;
	}

	/**
	 * Server-side Cloudflare Turnstile check.
	 */
	public function verify_turnstile( string $token, string $ip ): bool {
		if ( '' === $token || strlen( $token ) > 2048 ) {
			return false;
		}
		$response = wp_remote_post(
			self::TURNSTILE_VERIFY_URL,
			[
				'timeout' => 10,
				'body'    => [
					'secret'   => (string) $this->get( 'turnstile_secret_key' ),
					'response' => $token,
					'remoteip' => $ip,
				],
			]
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) && ! empty( $data['success'] );
	}

	// ---- verification link --------------------------------------------

	/**
	 * The link in the email: wp-login.php?action=passkey-signup&token=…
	 */
	public function signup_link( string $token ): string {
		return add_query_arg(
			[
				'action' => self::ACTION,
				'token'  => $token,
			],
			site_url( 'wp-login.php', 'login' )
		);
	}

	/**
	 * Return the pending row when the token matches and has not expired.
	 *
	 * @return array<string,mixed>|null
	 */
	public function validate_token( string $token ): ?array {
		if ( '' === $token || strlen( $token ) > 128 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $token ) ) {
			return null;
		}
		$row = $this->store->find_by_hash( hash( 'sha256', $token ) );
		if ( null === $row ) {
			return null;
		}
		if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			$this->store->delete( (int) $row['id'] );
			return null;
		}
		return $row;
	}

	/**
	 * Create the account for a validated pending row and retire the row.
	 *
	 * @param array<string,mixed> $row Pending signup (from validate_token()).
	 * @return \WP_User|\WP_Error
	 */
	public function complete( array $row ) {
		$email = (string) $row['email'];

		if ( email_exists( $email ) ) {
			$this->store->delete( (int) $row['id'] );
			return new \WP_Error( 'rtpk_signup_exists', __( 'An account with this email address already exists. Sign in instead.', 'riviantrackr-passkeys' ) );
		}

		$username = $this->derive_username( $email );
		$name     = (string) $row['display_name'];
		if ( '' === $name ) {
			$name = $username;
		}

		$user_id = wp_insert_user(
			[
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => $name,
				'nickname'     => $name,
				'role'         => $this->role(),
			]
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$this->store->delete( (int) $row['id'] );

		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'rtpk_signup_failed', __( 'The account could not be created. Please try again.', 'riviantrackr-passkeys' ) );
		}

		if ( $this->get( 'notify_admin' ) && function_exists( 'wp_new_user_notification' ) ) {
			wp_new_user_notification( $user->ID, null, 'admin' );
		}

		/**
		 * Fires after a verified signup has created its user account.
		 *
		 * @param \WP_User            $user New member.
		 * @param array<string,mixed> $row  The pending signup that was verified.
		 */
		do_action( 'riviantrackr_passkeys_signup_verified', $user, $row );

		return $user;
	}

	/**
	 * Username from the local part of the address, cleaned to
	 * [a-z0-9_.-] and de-duplicated with a numeric suffix.
	 *
	 * @param string        $email  Email address.
	 * @param callable|null $exists Returns truthy when a username is taken (defaults to username_exists()).
	 */
	public function derive_username( string $email, ?callable $exists = null ): string {
		$exists ??= static fn( string $login ) => (bool) username_exists( $login );

		$at    = strrpos( $email, '@' );
		$local = false === $at ? $email : substr( $email, 0, $at );
		$base  = strtolower( sanitize_user( $local, true ) );
		$base  = (string) preg_replace( '/[^a-z0-9_.-]+/', '', $base );
		$base  = trim( $base, '._-' );
		if ( '' === $base ) {
			$base = 'member';
		}
		$base = substr( $base, 0, 40 );

		$candidate = $base;
		$n         = 2;
		while ( $exists( $candidate ) ) {
			if ( $n > 25 ) {
				$candidate = $base . random_int( 1000, 99999 );
				continue;
			}
			$candidate = $base . $n;
			++$n;
		}
		return $candidate;
	}

	// ---- email ---------------------------------------------------------

	/**
	 * Fill template placeholders.
	 */
	public function fill( string $template, string $email, string $name, string $link ): string {
		if ( '' === $name ) {
			$at   = strrpos( $email, '@' );
			$name = false === $at ? $email : substr( $email, 0, $at );
		}
		return strtr(
			$template,
			[
				'{name}'      => $name,
				'{site}'      => (string) get_bloginfo( 'name' ),
				'{link}'      => $link,
				'{expires}'   => (string) (int) $this->get( 'expiry_hours' ),
				'{login_url}' => wp_login_url(),
			]
		);
	}

	/**
	 * @return array{subject:string,html:string,text:string}
	 */
	public function render_email( string $email, string $name, string $link ): array {
		$hours = (int) $this->get( 'expiry_hours' );
		return $this->invites->render_branded(
			[
				'subject'  => $this->fill( (string) $this->get( 'subject' ), $email, $name, $link ),
				'body'     => $this->fill( (string) $this->get( 'body' ), $email, $name, $link ),
				'link'     => $link,
				'button'   => __( 'Confirm and set up my passkey', 'riviantrackr-passkeys' ),
				/* translators: %d: number of hours */
				'footnote' => sprintf( _n( 'This link is single-use and expires in %d hour.', 'This link is single-use and expires in %d hours.', $hours, 'riviantrackr-passkeys' ), $hours ),
			]
		);
	}

	private function send_verification( string $email, string $name, string $link ): bool {
		$rendered = $this->render_email( $email, $name, $link );
		$mail     = [
			'to'      => $email,
			'subject' => $rendered['subject'],
			'message' => $rendered['html'],
			'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
		];

		/**
		 * Filter the signup verification email before it is sent.
		 *
		 * @param array<string,mixed> $mail  to, subject, message (HTML), headers.
		 * @param string              $email Recipient.
		 * @param string              $link  Verification link.
		 */
		$mail = (array) apply_filters( 'riviantrackr_passkeys_signup_email', $mail, $email, $link );

		return $this->invites->deliver( $mail );
	}

	/**
	 * Someone asked to sign up with an address that already has an account:
	 * tell the inbox owner, with a sign-in link, instead of the requester.
	 */
	private function send_existing_account_notice( string $email ): void {
		$site     = (string) get_bloginfo( 'name' );
		$rendered = $this->invites->render_branded(
			[
				/* translators: %s: site name */
				'subject'  => sprintf( __( 'You already have a %s account', 'riviantrackr-passkeys' ), $site ),
				'body'     => sprintf(
					/* translators: %s: site name */
					__( "Someone just tried to sign up for %s with this email address, but you already have an account.\n\nIf that was you, just sign in. If you've forgotten your password, use \"Lost your password?\" on the sign-in page, or sign in with your passkey.\n\nIf this wasn't you, you can ignore this email. Nothing has changed on your account.", 'riviantrackr-passkeys' ),
					$site
				),
				'link'     => wp_login_url(),
				'button'   => __( 'Sign in', 'riviantrackr-passkeys' ),
				'footnote' => '',
			]
		);
		$this->invites->deliver(
			[
				'to'      => $email,
				'subject' => $rendered['subject'],
				'message' => $rendered['html'],
				'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
			]
		);
	}

	private function sanitize_name( string $name ): string {
		$name = sanitize_text_field( $name );
		$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::NAME_MAX_LENGTH ) : substr( $name, 0, self::NAME_MAX_LENGTH );
		return trim( $name );
	}

	public function pending_count(): int {
		return $this->store->count_active();
	}
}
