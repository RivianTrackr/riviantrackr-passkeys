<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints behind the member account page.
 *
 * Namespace: riviantrackr-passkeys/v1 (all logged in, cookie nonce)
 *   POST   account/profile              save profile fields; email change needs confirmation
 *   POST   account/email/cancel         drop a pending email change
 *   POST   account/password             change password (current password required)
 *   POST   account/password/link        email a reset link (set a password / forgot the current one)
 *   POST   account/avatar               upload a photo (multipart field "avatar")
 *   DELETE account/avatar               remove the photo, back to Gravatar
 *   POST   account/data/export          confirmed personal data export request
 *   DELETE account                      delete the account (or file an erasure request), confirm = username
 *   POST   account/sessions/others      sign out every other browser
 *   DELETE account/reviews/{tire_id}    delete the member's tire review (tire guide)
 *   DELETE account/favorites/{tire_id}  take a tire off the member's saved list (tire guide)
 *   GET    account/garage               the member's garage
 *   POST   account/garage/vehicles      add a vehicle (model, year, generation, nickname, wheels, make_default)
 *   POST   account/garage/vehicles/{id} edit a vehicle (same fields)
 *   DELETE account/garage/vehicles/{id} remove a vehicle
 *   POST   account/garage/default       make a vehicle the default (id)
 */
final class AccountRest {

	public function __construct(
		private RateLimiter $limiter,
		private ?Settings $settings = null
	) {}

	public function register_routes(): void {
		$ns   = RTPK_REST_NAMESPACE;
		$auth = [ $this, 'can_manage_own' ];

		register_rest_route(
			$ns,
			'/account/profile',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_profile' ],
				'permission_callback' => $auth,
			]
		);
		register_rest_route(
			$ns,
			'/account/email/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel_email' ],
				'permission_callback' => $auth,
			]
		);
		register_rest_route(
			$ns,
			'/account/password',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'change_password' ],
				'permission_callback' => $auth,
				'args'                => [
					'current' => [
						'type'     => 'string',
						'required' => true,
					],
					'new'     => [
						'type'     => 'string',
						'required' => true,
					],
					'confirm' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
		register_rest_route(
			$ns,
			'/account/password/link',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send_password_link' ],
				'permission_callback' => $auth,
			]
		);
		register_rest_route(
			$ns,
			'/account/sessions/others',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'sign_out_others' ],
				'permission_callback' => $auth,
			]
		);
		register_rest_route(
			$ns,
			'/account/avatar',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'upload_avatar' ],
					'permission_callback' => $auth,
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'remove_avatar' ],
					'permission_callback' => $auth,
				],
			]
		);
		register_rest_route(
			$ns,
			'/account/data/export',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'request_export' ],
				'permission_callback' => $auth,
			]
		);
		register_rest_route(
			$ns,
			'/account',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_account' ],
				'permission_callback' => $auth,
				'args'                => [
					'confirm' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
		register_rest_route(
			$ns,
			'/account/reviews/(?P<tire_id>[A-Za-z0-9_-]+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_review' ],
				'permission_callback' => $auth,
			]
		);
		register_rest_route(
			$ns,
			'/account/favorites/(?P<tire_id>[A-Za-z0-9_-]+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_favorite' ],
				'permission_callback' => $auth,
			]
		);
		register_rest_route(
			$ns,
			'/account/garage',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_garage' ],
				'permission_callback' => $auth,
			]
		);
		register_rest_route(
			$ns,
			'/account/garage/vehicles',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_vehicle' ],
				'permission_callback' => $auth,
			]
		);
		register_rest_route(
			$ns,
			'/account/garage/vehicles/(?P<id>[a-f0-9]{8})',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'update_vehicle' ],
					'permission_callback' => $auth,
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'remove_vehicle' ],
					'permission_callback' => $auth,
				],
			]
		);
		register_rest_route(
			$ns,
			'/account/garage/default',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'set_default_vehicle' ],
				'permission_callback' => $auth,
				'args'                => [
					'id' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * @return bool|\WP_Error
	 */
	public function can_manage_own() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rtpk_unauthorized', __( 'You must be signed in.', 'riviantrackr-passkeys' ), [ 'status' => 401 ] );
		}
		return true;
	}

	/**
	 * @param \WP_REST_Request $request display_name, first_name, last_name, email, url, description.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_profile( \WP_REST_Request $request ) {
		$user  = wp_get_current_user();
		$input = [];
		foreach ( [ 'display_name', 'first_name', 'last_name', 'email', 'url', 'description' ] as $key ) {
			$input[ $key ] = (string) ( $request->get_param( $key ) ?? '' );
		}

		$checked = AccountService::validate_profile( $input, $user->ID );
		if ( [] !== $checked['errors'] ) {
			return new \WP_Error(
				'rtpk_invalid_profile',
				__( 'Please fix the highlighted fields.', 'riviantrackr-passkeys' ),
				[
					'status' => 400,
					'fields' => $checked['errors'],
				]
			);
		}

		$saved = AccountService::save_profile( $user, $checked['data'] );
		if ( is_wp_error( $saved ) ) {
			return new \WP_Error( 'rtpk_profile_failed', $saved->get_error_message(), [ 'status' => 400 ] );
		}

		/**
		 * Fires after a member saved their profile from the account page.
		 *
		 * @param \WP_User             $user The member.
		 * @param array<string,string> $data Saved fields.
		 */
		do_action( 'riviantrackr_passkeys_profile_updated', $saved['user'], $checked['data'] );

		$message = __( 'Profile saved.', 'riviantrackr-passkeys' );
		if ( '' !== $saved['pending_email'] ) {
			$message = sprintf(
				/* translators: %s: email address */
				__( 'Profile saved. We sent a confirmation link to %s. Your email changes once you click it.', 'riviantrackr-passkeys' ),
				$saved['pending_email']
			);
		}

		return rest_ensure_response(
			[
				'success'      => true,
				'message'      => $message,
				'pendingEmail' => $saved['pending_email'],
				'profile'      => [
					'displayName' => $saved['user']->display_name,
					'email'       => $saved['user']->user_email,
				],
			]
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function cancel_email() {
		AccountService::cancel_email_change( get_current_user_id() );
		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Email change cancelled.', 'riviantrackr-passkeys' ),
			]
		);
	}

	/**
	 * @param \WP_REST_Request $request current, new, confirm.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function change_password( \WP_REST_Request $request ) {
		$user = wp_get_current_user();

		if ( $this->limiter->is_limited( 'account-password', (string) $user->ID, 5 ) ) {
			return new \WP_Error( 'rtpk_rate_limited', __( 'Too many attempts. Please wait a minute and try again.', 'riviantrackr-passkeys' ), [ 'status' => 429 ] );
		}

		$errors = AccountService::validate_password(
			(string) $request->get_param( 'current' ),
			(string) $request->get_param( 'new' ),
			(string) $request->get_param( 'confirm' ),
			(string) $user->user_pass,
			$user->ID
		);
		if ( [] !== $errors ) {
			return new \WP_Error(
				'rtpk_invalid_password',
				__( 'Please fix the highlighted fields.', 'riviantrackr-passkeys' ),
				[
					'status' => 400,
					'fields' => $errors,
				]
			);
		}

		AccountService::change_password( $user, (string) $request->get_param( 'new' ) );

		/**
		 * Fires after a member changed their password from the account page.
		 *
		 * @param \WP_User $user The member.
		 */
		do_action( 'riviantrackr_passkeys_password_changed', $user );

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Password changed. Other devices have been signed out.', 'riviantrackr-passkeys' ),
			]
		);
	}

	/**
	 * Email the signed-in member a password reset link: "Set a password" for
	 * open-signup accounts that never had one, "Forgot your current
	 * password?" for everyone else. The per-IP limit inside
	 * retrieve_password() applies; this adds a per-user one.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function send_password_link() {
		$user = wp_get_current_user();

		if ( $this->limiter->is_limited( 'account-password-link', (string) $user->ID, 3, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'rtpk_rate_limited', __( 'We already sent you a link. Check your inbox, or try again in an hour.', 'riviantrackr-passkeys' ), [ 'status' => 429 ] );
		}

		$code = AccountService::send_password_link( $user );
		if ( 'sent' !== $code ) {
			$notice = PasswordResetPage::notice( $code );
			return new \WP_Error( 'rtpk_password_link_failed', $notice ? $notice['text'] : __( 'Something went wrong. Please try again.', 'riviantrackr-passkeys' ), [ 'status' => 'limited' === $code ? 429 : 500 ] );
		}

		return rest_ensure_response(
			[
				'success' => true,
				'message' => sprintf(
					/* translators: %s: email address */
					__( 'We emailed a link to %s. Open it to choose your password.', 'riviantrackr-passkeys' ),
					$user->user_email
				),
			]
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public function sign_out_others() {
		AccountService::sign_out_elsewhere( get_current_user_id() );
		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Signed out everywhere else. This browser stays signed in.', 'riviantrackr-passkeys' ),
			]
		);
	}

	/**
	 * POST account/avatar (multipart, field "avatar"): store the photo and
	 * answer with the URL the page should show.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload_avatar( \WP_REST_Request $request ) {
		$user = wp_get_current_user();

		if ( $this->limiter->is_limited( 'account-avatar', (string) $user->ID, 10, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'rtpk_rate_limited', __( 'Too many uploads. Please try again in an hour.', 'riviantrackr-passkeys' ), [ 'status' => 429 ] );
		}

		$files = $request->get_file_params();
		$file  = isset( $files['avatar'] ) && is_array( $files['avatar'] ) ? $files['avatar'] : [];
		$error = Avatars::validate( $file );
		if ( null !== $error ) {
			return new \WP_Error( 'rtpk_invalid_avatar', $error, [ 'status' => 400 ] );
		}

		$stored = Avatars::store( $user, $file );
		if ( is_wp_error( $stored ) ) {
			return new \WP_Error( 'rtpk_avatar_failed', $stored->get_error_message(), [ 'status' => 500 ] );
		}

		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Photo updated.', 'riviantrackr-passkeys' ),
				'url'     => Avatars::url( $user->ID ),
				'custom'  => true,
			]
		);
	}

	/**
	 * DELETE account/avatar: back to Gravatar.
	 *
	 * @return \WP_REST_Response
	 */
	public function remove_avatar() {
		$user = wp_get_current_user();
		Avatars::remove( $user->ID );
		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Photo removed. Your Gravatar shows instead.', 'riviantrackr-passkeys' ),
				'url'     => (string) get_avatar_url( $user->ID, [ 'size' => 192 ] ),
				'custom'  => false,
			]
		);
	}

	/**
	 * POST account/data/export: a confirmed export request for the owner to
	 * fulfil under Tools → Export Personal Data.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function request_export() {
		$user   = wp_get_current_user();
		$result = AccountService::privacy_request( $user, 'export_personal_data' );
		if ( is_wp_error( $result ) ) {
			$message = 'duplicate_request' === $result->get_error_code()
				? __( 'A copy of your data has already been requested. You will get an email when it is ready.', 'riviantrackr-passkeys' )
				: $result->get_error_message();
			return new \WP_Error( 'rtpk_export_failed', $message, [ 'status' => 409 ] );
		}
		return rest_ensure_response(
			[
				'success' => true,
				'message' => sprintf(
					/* translators: %s: email address */
					__( 'Request received. We will email a download link to %s once your export is ready, usually within a few days.', 'riviantrackr-passkeys' ),
					$user->user_email
				),
			]
		);
	}

	/**
	 * DELETE account: remove the account now, or file an erasure request,
	 * depending on the member's role and the setting. The member types their
	 * username to confirm.
	 *
	 * @param \WP_REST_Request $request confirm.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_account( \WP_REST_Request $request ) {
		$user    = wp_get_current_user();
		$confirm = trim( (string) $request->get_param( 'confirm' ) );
		if ( '' === $confirm || 0 !== strcasecmp( $confirm, $user->user_login ) ) {
			return new \WP_Error(
				'rtpk_confirm_mismatch',
				__( 'Type your username exactly to confirm.', 'riviantrackr-passkeys' ),
				[
					'status' => 400,
					'fields' => [ 'confirm' => __( 'That is not your username.', 'riviantrackr-passkeys' ) ],
				]
			);
		}

		$settings = $this->settings ?? new Settings();
		if ( 'request' === AccountService::deletion_mode( $user, $settings->allows_self_delete() ) ) {
			$result = AccountService::privacy_request( $user, 'remove_personal_data' );
			if ( is_wp_error( $result ) && 'duplicate_request' !== $result->get_error_code() ) {
				return new \WP_Error( 'rtpk_erasure_failed', $result->get_error_message(), [ 'status' => 500 ] );
			}
			return rest_ensure_response(
				[
					'success' => true,
					'deleted' => false,
					'message' => __( 'Request received. Because your account has publishing rights, the site owner reviews the deletion first. You will get an email when it is done.', 'riviantrackr-passkeys' ),
				]
			);
		}

		if ( ! AccountService::delete_account( $user ) ) {
			return new \WP_Error( 'rtpk_delete_failed', __( 'Your account could not be deleted. Please contact us.', 'riviantrackr-passkeys' ), [ 'status' => 500 ] );
		}
		return rest_ensure_response(
			[
				'success'  => true,
				'deleted'  => true,
				'message'  => __( 'Your account has been deleted.', 'riviantrackr-passkeys' ),
				'redirect' => home_url( '/' ),
			]
		);
	}

	/**
	 * @param \WP_REST_Request $request tire_id.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_review( \WP_REST_Request $request ) {
		if ( ! TireGuide::supports_reviews() ) {
			return new \WP_Error( 'rtpk_no_tire_guide', __( 'The tire guide is not available.', 'riviantrackr-passkeys' ), [ 'status' => 404 ] );
		}
		$tire_id = sanitize_text_field( (string) $request->get_param( 'tire_id' ) );
		if ( ! TireGuide::delete_review( $tire_id, get_current_user_id() ) ) {
			return new \WP_Error( 'rtpk_not_found', __( 'Review not found.', 'riviantrackr-passkeys' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Review deleted.', 'riviantrackr-passkeys' ),
			]
		);
	}

	/**
	 * @param \WP_REST_Request $request tire_id.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_favorite( \WP_REST_Request $request ) {
		if ( ! TireGuide::supports_favorites() ) {
			return new \WP_Error( 'rtpk_no_tire_guide', __( 'The tire guide is not available.', 'riviantrackr-passkeys' ), [ 'status' => 404 ] );
		}
		$tire_id = sanitize_text_field( (string) $request->get_param( 'tire_id' ) );
		if ( ! TireGuide::remove_favorite( $tire_id, get_current_user_id() ) ) {
			return new \WP_Error( 'rtpk_not_found', __( 'That tire is not on your saved list.', 'riviantrackr-passkeys' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Removed from your saved tires.', 'riviantrackr-passkeys' ),
			]
		);
	}

	// ---- garage ----------------------------------------------------------

	/**
	 * GET account/garage.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_garage() {
		return rest_ensure_response(
			[
				'success' => true,
				'garage'  => Garage::for_user( get_current_user_id() ),
			]
		);
	}

	/**
	 * POST account/garage/vehicles.
	 *
	 * @param \WP_REST_Request $request model, year, generation, nickname, wheels, make_default.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_vehicle( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$checked = $this->check_vehicle( $request, $user_id );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$id = Garage::add( $user_id, $checked, (bool) $request->get_param( 'make_default' ) );
		if ( is_wp_error( $id ) ) {
			return new \WP_Error( $id->get_error_code(), $id->get_error_message(), [ 'status' => 409 ] );
		}
		return $this->garage_response( $user_id, __( 'Added to your garage.', 'riviantrackr-passkeys' ), $id );
	}

	/**
	 * POST account/garage/vehicles/{id}.
	 *
	 * @param \WP_REST_Request $request id, model, year, generation, nickname, wheels, make_default.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_vehicle( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$checked = $this->check_vehicle( $request, $user_id );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$id = (string) $request->get_param( 'id' );
		if ( ! Garage::update( $user_id, $id, $checked, (bool) $request->get_param( 'make_default' ) ) ) {
			return new \WP_Error( 'rtpk_not_found', __( 'That vehicle is not in your garage.', 'riviantrackr-passkeys' ), [ 'status' => 404 ] );
		}
		return $this->garage_response( $user_id, __( 'Vehicle saved.', 'riviantrackr-passkeys' ), $id );
	}

	/**
	 * DELETE account/garage/vehicles/{id}.
	 *
	 * @param \WP_REST_Request $request id.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_vehicle( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( $this->limiter->is_limited( 'account-garage', (string) $user_id, 30 ) ) {
			return $this->garage_limited();
		}
		if ( ! Garage::remove( $user_id, (string) $request->get_param( 'id' ) ) ) {
			return new \WP_Error( 'rtpk_not_found', __( 'That vehicle is not in your garage.', 'riviantrackr-passkeys' ), [ 'status' => 404 ] );
		}
		return $this->garage_response( $user_id, __( 'Removed from your garage.', 'riviantrackr-passkeys' ) );
	}

	/**
	 * POST account/garage/default.
	 *
	 * @param \WP_REST_Request $request id.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_default_vehicle( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( $this->limiter->is_limited( 'account-garage', (string) $user_id, 30 ) ) {
			return $this->garage_limited();
		}
		$id = (string) $request->get_param( 'id' );
		if ( ! Garage::set_default( $user_id, $id ) ) {
			return new \WP_Error( 'rtpk_not_found', __( 'That vehicle is not in your garage.', 'riviantrackr-passkeys' ), [ 'status' => 404 ] );
		}
		return $this->garage_response( $user_id, __( 'Default vehicle updated.', 'riviantrackr-passkeys' ), $id );
	}

	/**
	 * Rate limit and validate the garage form.
	 *
	 * @return array<string,mixed>|\WP_Error The vehicle to store.
	 */
	private function check_vehicle( \WP_REST_Request $request, int $user_id ) {
		if ( $this->limiter->is_limited( 'account-garage', (string) $user_id, 30 ) ) {
			return $this->garage_limited();
		}
		$input = [];
		foreach ( [ 'model', 'year', 'generation', 'nickname', 'wheels' ] as $key ) {
			$input[ $key ] = $request->get_param( $key );
		}
		$input['wheels'] = is_array( $input['wheels'] ) ? $input['wheels'] : [];
		$checked         = Garage::validate_vehicle( $input, Vehicles::models(), TireGuide::wheels() );
		if ( [] !== $checked['errors'] ) {
			return new \WP_Error(
				'rtpk_invalid_vehicle',
				__( 'Please fix the highlighted fields.', 'riviantrackr-passkeys' ),
				[
					'status' => 400,
					'fields' => $checked['errors'],
				]
			);
		}
		return $checked['data'];
	}

	private function garage_limited(): \WP_Error {
		return new \WP_Error( 'rtpk_rate_limited', __( 'Too many changes. Please wait a minute and try again.', 'riviantrackr-passkeys' ), [ 'status' => 429 ] );
	}

	/**
	 * @return \WP_REST_Response
	 */
	private function garage_response( int $user_id, string $message, string $id = '' ) {
		return rest_ensure_response(
			[
				'success' => true,
				'message' => $message,
				'id'      => $id,
				'garage'  => Garage::for_user( $user_id ),
			]
		);
	}
}
