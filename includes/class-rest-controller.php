<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

use lbuchs\WebAuthn\WebAuthnException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints for registration, login and credential management.
 *
 * Namespace: riviantrackr-passkeys/v1
 *   POST   register/options     (logged in)
 *   POST   register/verify      (logged in)
 *   POST   login/options        (public, rate limited)
 *   POST   login/verify         (public, rate limited)
 *   GET    credentials          (logged in)
 *   POST   credentials/{id}     (logged in — rename)
 *   DELETE credentials/{id}     (logged in — own, or edit_user capability)
 */
final class RestController {

	private const TRANSPORTS = [ 'usb', 'nfc', 'ble', 'hybrid', 'internal', 'smart-card', 'cable' ];

	public function __construct(
		private Settings $settings,
		private CredentialStore $store,
		private ChallengeStore $challenges,
		private RateLimiter $limiter,
		private WebAuthnService $service
	) {}

	public function register_routes(): void {
		$ns = RTPK_REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/register/options',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'register_options' ],
				'permission_callback' => [ $this, 'can_manage_own' ],
			]
		);
		register_rest_route(
			$ns,
			'/register/verify',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'register_verify' ],
				'permission_callback' => [ $this, 'can_manage_own' ],
				'args'                => [
					'challengeId' => [
						'type'     => 'string',
						'required' => true,
					],
					'credential'  => [
						'type'     => 'object',
						'required' => true,
					],
					'name'        => [
						'type'     => 'string',
						'required' => false,
					],
				],
			]
		);
		register_rest_route(
			$ns,
			'/login/options',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'login_options' ],
				'permission_callback' => '__return_true',
			]
		);
		register_rest_route(
			$ns,
			'/login/verify',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'login_verify' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'challengeId' => [
						'type'     => 'string',
						'required' => true,
					],
					'credential'  => [
						'type'     => 'object',
						'required' => true,
					],
					'redirectTo'  => [
						'type'     => 'string',
						'required' => false,
					],
					'remember'    => [
						'type'     => 'boolean',
						'required' => false,
					],
				],
			]
		);
		register_rest_route(
			$ns,
			'/credentials',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_credentials' ],
				'permission_callback' => [ $this, 'can_manage_own' ],
			]
		);
		register_rest_route(
			$ns,
			'/credentials/(?P<id>\d+)',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'rename_credential' ],
					'permission_callback' => [ $this, 'can_manage_own' ],
					'args'                => [
						'name' => [
							'type'     => 'string',
							'required' => true,
						],
					],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_credential' ],
					'permission_callback' => [ $this, 'can_manage_own' ],
				],
			]
		);
	}

	/**
	 * Cookie-authenticated (nonce-checked) logged-in user.
	 *
	 * @return bool|\WP_Error
	 */
	public function can_manage_own() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rtpk_unauthorized', __( 'You must be signed in to manage passkeys.', 'riviantrackr-passkeys' ), [ 'status' => 401 ] );
		}
		return true;
	}

	/**
	 * Registration step 1: challenge + PublicKeyCredentialCreationOptions.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function register_options() {
		$user = wp_get_current_user();

		if ( $this->limiter->is_limited( 'register', (string) $user->ID, RTPK_REGISTER_RATE_LIMIT ) ) {
			return $this->error( 'rtpk_rate_limited', __( 'Too many attempts. Please wait a minute and try again.', 'riviantrackr-passkeys' ), 429 );
		}
		if ( $this->store->count_for_user( $user->ID ) >= RTPK_MAX_PASSKEYS_PER_USER ) {
			return $this->error(
				'rtpk_limit_reached',
				sprintf(
					/* translators: %d: maximum number of passkeys */
					__( 'You can register up to %d passkeys. Remove one to add another.', 'riviantrackr-passkeys' ),
					RTPK_MAX_PASSKEYS_PER_USER
				),
				400
			);
		}

		$built = $this->service->registration_options( $user );
		$token = $this->challenges->create( ChallengeStore::TYPE_REGISTER, $built['challenge'], $user->ID );

		return rest_ensure_response(
			[
				'challengeId' => $token,
				'publicKey'   => $built['options']['publicKey'] ?? [],
			]
		);
	}

	/**
	 * Registration step 2: verify the attestation and store the credential.
	 *
	 * @param \WP_REST_Request $request Request with challengeId, credential and optional name.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function register_verify( \WP_REST_Request $request ) {
		$user       = wp_get_current_user();
		$credential = $request->get_param( 'credential' );
		$token      = (string) $request->get_param( 'challengeId' );

		$challenge = $this->challenges->consume( $token, ChallengeStore::TYPE_REGISTER, $user->ID );
		if ( null === $challenge ) {
			return $this->error( 'rtpk_challenge', __( 'This request has expired. Please try again.', 'riviantrackr-passkeys' ), 400 );
		}
		if ( $this->store->count_for_user( $user->ID ) >= RTPK_MAX_PASSKEYS_PER_USER ) {
			return $this->error( 'rtpk_limit_reached', __( 'You have reached the passkey limit for this account.', 'riviantrackr-passkeys' ), 400 );
		}

		$response           = is_array( $credential ) && is_array( $credential['response'] ?? null ) ? $credential['response'] : [];
		$client_data_json   = Helpers::base64url_decode( (string) ( $response['clientDataJSON'] ?? '' ) );
		$attestation_object = Helpers::base64url_decode( (string) ( $response['attestationObject'] ?? '' ) );

		if ( null === $client_data_json || null === $attestation_object ) {
			return $this->error( 'rtpk_invalid', __( 'The passkey response was malformed.', 'riviantrackr-passkeys' ), 400 );
		}

		try {
			$verified = $this->service->verify_registration( $client_data_json, $attestation_object, $challenge );
		} catch ( WebAuthnException $e ) {
			return $this->error( 'rtpk_verify_failed', WebAuthnService::describe_error( $e ), 400 );
		} catch ( \Throwable $e ) {
			return $this->error( 'rtpk_verify_failed', __( 'The passkey response could not be verified.', 'riviantrackr-passkeys' ), 400 );
		}

		if ( $this->store->find_by_credential_id( $verified['credential_id_binary'] ) ) {
			return $this->error( 'rtpk_duplicate', __( 'This passkey is already registered.', 'riviantrackr-passkeys' ), 409 );
		}

		$transports = [];
		foreach ( (array) ( $response['transports'] ?? [] ) as $transport ) {
			$transport = sanitize_key( (string) $transport );
			if ( in_array( $transport, self::TRANSPORTS, true ) ) {
				$transports[] = $transport;
			}
		}

		$name = $this->sanitize_name( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			$name = Helpers::provider_from_aaguid( $verified['aaguid'] );
		}
		if ( '' === $name ) {
			$name = __( 'Passkey', 'riviantrackr-passkeys' );
		}

		$row = $this->store->insert(
			[
				'user_id'              => $user->ID,
				'credential_id_binary' => $verified['credential_id_binary'],
				'public_key'           => $verified['public_key'],
				'sign_count'           => $verified['sign_count'],
				'aaguid'               => $verified['aaguid'],
				'transports'           => $transports,
				'name'                 => $name,
				'attestation_format'   => $verified['attestation_format'],
				'backup_eligible'      => $verified['backup_eligible'],
				'backed_up'            => $verified['backed_up'],
			]
		);

		if ( null === $row ) {
			return $this->error( 'rtpk_db', __( 'The passkey could not be saved. Please try again.', 'riviantrackr-passkeys' ), 500 );
		}

		/**
		 * Fires after a passkey has been registered.
		 *
		 * @param array<string,mixed> $row  Stored credential row.
		 * @param \WP_User            $user Owner.
		 */
		do_action( 'riviantrackr_passkeys_registered', $row, $user );

		return rest_ensure_response(
			[
				'success'    => true,
				'credential' => $this->present( $row ),
			]
		);
	}

	/**
	 * Login step 1: challenge + PublicKeyCredentialRequestOptions.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function login_options() {
		$ip = Helpers::client_ip();
		if ( $this->limiter->is_limited( 'login-options', $ip, RTPK_OPTIONS_RATE_LIMIT ) ) {
			return $this->error( 'rtpk_rate_limited', __( 'Too many attempts. Please wait a minute and try again.', 'riviantrackr-passkeys' ), 429 );
		}

		$built = $this->service->login_options();
		$token = $this->challenges->create( ChallengeStore::TYPE_LOGIN, $built['challenge'] );

		return rest_ensure_response(
			[
				'challengeId' => $token,
				'publicKey'   => $built['options']['publicKey'] ?? [],
			]
		);
	}

	/**
	 * Login step 2: verify the assertion and set the auth cookie.
	 *
	 * @param \WP_REST_Request $request Request with challengeId, credential, redirectTo, remember.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function login_verify( \WP_REST_Request $request ) {
		$ip = Helpers::client_ip();
		if ( $this->limiter->is_limited( 'login-verify', $ip, RTPK_LOGIN_RATE_LIMIT ) ) {
			return $this->error( 'rtpk_rate_limited', __( 'Too many sign-in attempts. Please wait a minute and try again.', 'riviantrackr-passkeys' ), 429 );
		}

		$token     = (string) $request->get_param( 'challengeId' );
		$challenge = $this->challenges->consume( $token, ChallengeStore::TYPE_LOGIN );
		if ( null === $challenge ) {
			return $this->error( 'rtpk_challenge', __( 'This sign-in request has expired. Please try again.', 'riviantrackr-passkeys' ), 400 );
		}

		$credential = $request->get_param( 'credential' );
		$response   = is_array( $credential ) && is_array( $credential['response'] ?? null ) ? $credential['response'] : [];

		$raw_id             = Helpers::base64url_decode( (string) ( $credential['rawId'] ?? '' ) );
		$client_data_json   = Helpers::base64url_decode( (string) ( $response['clientDataJSON'] ?? '' ) );
		$authenticator_data = Helpers::base64url_decode( (string) ( $response['authenticatorData'] ?? '' ) );
		$signature          = Helpers::base64url_decode( (string) ( $response['signature'] ?? '' ) );
		$user_handle_raw    = (string) ( $response['userHandle'] ?? '' );
		$user_handle        = '' !== $user_handle_raw ? Helpers::base64url_decode( $user_handle_raw ) : null;

		if ( null === $raw_id || null === $client_data_json || null === $authenticator_data || null === $signature ) {
			return $this->error( 'rtpk_invalid', __( 'The passkey response was malformed.', 'riviantrackr-passkeys' ), 400 );
		}

		$generic = __( 'That passkey is not recognised. Try another passkey or sign in with your password.', 'riviantrackr-passkeys' );

		$row = $this->store->find_by_credential_id( $raw_id );
		if ( null === $row ) {
			return $this->error( 'rtpk_unknown', $generic, 400 );
		}

		$user = get_user_by( 'id', $row['user_id'] );
		if ( ! $user instanceof \WP_User ) {
			return $this->error( 'rtpk_unknown', $generic, 400 );
		}

		// If the authenticator reports a user handle it must be ours.
		if ( null !== $user_handle && '' !== $user_handle && ! hash_equals( $this->service->user_handle( $user ), $user_handle ) ) {
			return $this->error( 'rtpk_unknown', $generic, 400 );
		}

		try {
			$result = $this->service->verify_login(
				$client_data_json,
				$authenticator_data,
				$signature,
				(string) $row['public_key'],
				$challenge,
				(int) $row['sign_count']
			);
		} catch ( WebAuthnException $e ) {
			if ( WebAuthnException::SIGNATURE_COUNTER === $e->getCode() ) {
				/**
				 * Fires when an assertion's signature counter did not increase —
				 * a possible sign of a cloned authenticator.
				 *
				 * @param array<string,mixed> $row  Credential row.
				 * @param \WP_User            $user Owner.
				 */
				do_action( 'riviantrackr_passkeys_counter_mismatch', $row, $user );
			}
			return $this->error( 'rtpk_verify_failed', WebAuthnService::describe_error( $e ), 400 );
		} catch ( \Throwable $e ) {
			return $this->error( 'rtpk_verify_failed', $generic, 400 );
		}

		/**
		 * Allow or deny a passkey login after cryptographic verification.
		 *
		 * Return false or a WP_Error to deny.
		 *
		 * @param bool|\WP_Error      $allowed Whether login may proceed.
		 * @param \WP_User            $user    The user about to be signed in.
		 * @param array<string,mixed> $row     Credential row.
		 */
		$allowed = apply_filters( 'riviantrackr_passkeys_pre_login', true, $user, $row );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! $allowed ) {
			return $this->error( 'rtpk_denied', __( 'Sign-in with a passkey is not allowed for this account.', 'riviantrackr-passkeys' ), 403 );
		}

		$remember = (bool) $request->get_param( 'remember' );

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );

		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$this->store->touch( (int) $row['id'], (int) $result['sign_count'], (bool) $result['backed_up'] );

		/**
		 * Fires after a successful passkey login.
		 *
		 * @param \WP_User            $user Signed-in user.
		 * @param array<string,mixed> $row  Credential row.
		 */
		do_action( 'riviantrackr_passkeys_login', $user, $row );

		return rest_ensure_response(
			[
				'success'  => true,
				'redirect' => $this->resolve_redirect( (string) $request->get_param( 'redirectTo' ), $user ),
			]
		);
	}

	/**
	 * List the current user's passkeys (or another user's with edit_user).
	 *
	 * @param \WP_REST_Request $request Request with optional user_id.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_credentials( \WP_REST_Request $request ) {
		$user_id = (int) ( $request->get_param( 'user_id' ) ?: get_current_user_id() );
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_user', $user_id ) ) {
			return $this->error( 'rtpk_forbidden', __( 'You cannot view passkeys for that user.', 'riviantrackr-passkeys' ), 403 );
		}
		return rest_ensure_response(
			[
				'credentials' => array_map( [ $this, 'present' ], $this->store->find_for_user( $user_id ) ),
			]
		);
	}

	/**
	 * Rename one of the current user's passkeys.
	 *
	 * @param \WP_REST_Request $request Request with id and name.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rename_credential( \WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'id' );
		$row = $this->store->find( $id );
		if ( null === $row || get_current_user_id() !== $row['user_id'] ) {
			return $this->error( 'rtpk_not_found', __( 'Passkey not found.', 'riviantrackr-passkeys' ), 404 );
		}
		$name = $this->sanitize_name( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return $this->error( 'rtpk_invalid_name', __( 'Please enter a name for this passkey.', 'riviantrackr-passkeys' ), 400 );
		}
		$this->store->rename( $id, get_current_user_id(), $name );
		$row['name'] = $name;
		return rest_ensure_response(
			[
				'success'    => true,
				'credential' => $this->present( $row ),
			]
		);
	}

	/**
	 * Remove a passkey (own, or any with the edit_user capability).
	 *
	 * @param \WP_REST_Request $request Request with id.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_credential( \WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'id' );
		$row = $this->store->find( $id );
		if ( null === $row ) {
			return $this->error( 'rtpk_not_found', __( 'Passkey not found.', 'riviantrackr-passkeys' ), 404 );
		}
		if ( get_current_user_id() !== $row['user_id'] && ! current_user_can( 'edit_user', $row['user_id'] ) ) {
			return $this->error( 'rtpk_forbidden', __( 'You cannot remove that passkey.', 'riviantrackr-passkeys' ), 403 );
		}
		$this->store->delete( $id );

		/**
		 * Fires after a passkey has been removed.
		 *
		 * @param array<string,mixed> $row Deleted credential row.
		 */
		do_action( 'riviantrackr_passkeys_deleted', $row );

		return rest_ensure_response( [ 'success' => true ] );
	}

	/**
	 * Shape a credential row for the browser (never exposes the public key).
	 *
	 * @param array<string,mixed> $row Credential row from the store.
	 * @return array<string,mixed>
	 */
	public function present( array $row ): array {
		$format = (string) get_option( 'date_format' );
		return [
			'id'         => (int) $row['id'],
			'name'       => (string) $row['name'],
			'provider'   => Helpers::provider_from_aaguid( $row['aaguid'] ?? null ),
			'transports' => (array) $row['transports'],
			'backedUp'   => (bool) $row['backed_up'],
			'createdAt'  => $row['created_at'] ? date_i18n( $format, strtotime( (string) $row['created_at'] . ' UTC' ) ) : '',
			'lastUsedAt' => $row['last_used_at'] ? date_i18n( $format, strtotime( (string) $row['last_used_at'] . ' UTC' ) ) : '',
		];
	}

	private function sanitize_name( string $name ): string {
		$name = sanitize_text_field( $name );
		if ( function_exists( 'mb_substr' ) ) {
			return trim( mb_substr( $name, 0, RTPK_NAME_MAX_LENGTH ) );
		}
		return trim( substr( $name, 0, RTPK_NAME_MAX_LENGTH ) );
	}

	/**
	 * Mirror wp-login.php's post-login redirect rules.
	 */
	private function resolve_redirect( string $requested, \WP_User $user ): string {
		$requested   = trim( $requested );
		$redirect_to = '' !== $requested ? $requested : admin_url();

		/** This filter is documented in wp-login.php */
		$redirect_to = (string) apply_filters( 'login_redirect', $redirect_to, $requested, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		// Members without editing rights land on the account page (same rule as the login_redirect hook).
		$redirect_to = AccountRedirect::target( $redirect_to, $user->has_cap( 'edit_posts' ), $this->settings->account_page_url(), admin_url() );

		if ( '' === $redirect_to || 'wp-admin/' === $redirect_to || admin_url() === $redirect_to ) {
			if ( is_multisite() && ! get_active_blog_for_user( $user->ID ) && ! is_super_admin( $user->ID ) ) {
				$redirect_to = user_admin_url();
			} elseif ( is_multisite() && ! $user->has_cap( 'read' ) ) {
				$redirect_to = get_dashboard_url( $user->ID );
			} elseif ( ! $user->has_cap( 'edit_posts' ) ) {
				$redirect_to = $user->has_cap( 'read' ) ? admin_url( 'profile.php' ) : home_url();
			}
		}

		return wp_validate_redirect( $redirect_to, home_url() );
	}

	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
