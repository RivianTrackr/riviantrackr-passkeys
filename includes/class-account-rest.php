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
 *   POST   account/sessions/others      sign out every other browser
 *   DELETE account/reviews/{tire_id}    delete the member's tire review (tire guide)
 *   DELETE account/favorites/{tire_id}  remove a favourite tire (tire guide)
 */
final class AccountRest {

	public function __construct( private RateLimiter $limiter ) {}

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
			'/account/sessions/others',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'sign_out_others' ],
				'permission_callback' => $auth,
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
	 * @param \WP_REST_Request $request tire_id.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_review( \WP_REST_Request $request ) {
		if ( ! TireGuide::is_active() ) {
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
		if ( ! TireGuide::is_active() ) {
			return new \WP_Error( 'rtpk_no_tire_guide', __( 'The tire guide is not available.', 'riviantrackr-passkeys' ), [ 'status' => 404 ] );
		}
		$tire_id = sanitize_text_field( (string) $request->get_param( 'tire_id' ) );
		if ( ! TireGuide::remove_favorite( $tire_id, get_current_user_id() ) ) {
			return new \WP_Error( 'rtpk_not_found', __( 'Favourite not found.', 'riviantrackr-passkeys' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response(
			[
				'success' => true,
				'message' => __( 'Removed from favourites.', 'riviantrackr-passkeys' ),
			]
		);
	}
}
