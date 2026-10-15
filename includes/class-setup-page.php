<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The focused "Set up a passkey" page at wp-login.php?action=passkey-setup.
 *
 * Invitation links land here. A valid token signs the member in (when the
 * magic-link option is on) so the whole flow is: open email → tap → create.
 */
final class SetupPage {

	public function __construct(
		private InviteService $invites,
		private ProfileManager $manager,
		private CredentialStore $store
	) {}

	public function register_hooks(): void {
		add_action( 'login_form_' . InviteService::SETUP_ACTION, [ $this, 'handle' ] );
		add_filter( 'login_message', [ $this, 'login_message' ] );
		add_filter( 'login_body_class', [ $this, 'body_class' ] );
	}

	/**
	 * Runs inside wp-login.php before any output for our action.
	 */
	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Token-authenticated link; no form submission.
		$uid         = isset( $_GET['uid'] ) ? absint( wp_unslash( $_GET['uid'] ) ) : 0;
		$token       = isset( $_GET['rtpk_invite'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['rtpk_invite'] ) ) : '';
		$continue_to = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( (string) $_GET['redirect_to'] ) ), '' ) : '';
		// phpcs:enable

		if ( ! is_user_logged_in() ) {
			if ( '' !== $token && $uid > 0 ) {
				$user = $this->invites->magic_link_enabled() ? $this->invites->validate_token( $uid, $token ) : null;

				if ( $user instanceof \WP_User ) {
					$this->invites->consume_token( $user->ID );
					wp_set_current_user( $user->ID );
					wp_set_auth_cookie( $user->ID, false, is_ssl() );
					/** This action is documented in wp-includes/user.php */
					do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

					/**
					 * Fires when a member signs in through an invitation link.
					 *
					 * @param \WP_User $user The member.
					 */
					do_action( 'riviantrackr_passkeys_invite_login', $user );

					wp_safe_redirect( $this->invites->setup_url( $continue_to ) );
					exit;
				}

				// Expired or already used: fall through to the login form with a hint.
				$login_url = add_query_arg( 'rtpk_invite', 'expired', wp_login_url( $this->invites->setup_url( $continue_to ) ) );
				wp_safe_redirect( $login_url );
				exit;
			}

			wp_safe_redirect( wp_login_url( $this->invites->setup_url( $continue_to ) ) );
			exit;
		}

		$user = wp_get_current_user();

		// A signed-in member arriving with their own token: retire it.
		if ( '' !== $token && $uid === $user->ID ) {
			$this->invites->consume_token( $user->ID );
		}

		$this->render( $user, $this->continue_url( $user, $continue_to ) );
		exit;
	}

	/**
	 * Hint on the normal login form when the member was sent here.
	 *
	 * @param string $message Existing message HTML.
	 */
	public function login_message( $message ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$expired     = isset( $_GET['rtpk_invite'] ) && 'expired' === $_GET['rtpk_invite'];
		$redirect_to = isset( $_GET['redirect_to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['redirect_to'] ) ) : '';
		// phpcs:enable

		if ( $expired ) {
			return (string) $message . '<p class="message">' . esc_html__( 'That invitation link has expired or was already used. Sign in and we will take you straight to passkey setup.', 'riviantrackr-passkeys' ) . '</p>';
		}
		if ( '' !== $redirect_to && str_contains( $redirect_to, 'action=' . InviteService::SETUP_ACTION ) ) {
			return (string) $message . '<p class="message">' . esc_html__( 'Sign in once with your password to set up your passkey.', 'riviantrackr-passkeys' ) . '</p>';
		}
		return (string) $message;
	}

	/**
	 * @param string[] $classes Login body classes.
	 * @return string[]
	 */
	public function body_class( $classes ): array {
		$classes = (array) $classes;
		if ( isset( $_GET['action'] ) && InviteService::SETUP_ACTION === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$classes[] = 'rtpk-setup-page';
		}
		return $classes;
	}

	private function continue_url( \WP_User $user, string $requested ): string {
		if ( '' !== $requested ) {
			return $requested;
		}
		return $user->has_cap( 'edit_posts' ) ? admin_url() : home_url( '/' );
	}

	private function render( \WP_User $user, string $continue_to ): void {
		$count = $this->store->count_for_user( $user->ID );

		wp_enqueue_style( 'rtpk-setup', RTPK_URL . 'assets/passkeys-setup' . RTPK_ASSET_SUFFIX . '.css', [], Helpers::asset_version( 'assets/passkeys-setup' . RTPK_ASSET_SUFFIX . '.css' ) );
		$manager = $this->manager->markup(
			$user,
			true,
			true,
			[
				'title'        => $count > 0 ? __( 'Your passkeys', 'riviantrackr-passkeys' ) : __( 'Create your passkey', 'riviantrackr-passkeys' ),
				'desc'         => __( 'Tap the button and follow the prompt from your browser. It takes about ten seconds.', 'riviantrackr-passkeys' ),
				'button'       => $count > 0 ? __( 'Add another device', 'riviantrackr-passkeys' ) : __( 'Create a passkey', 'riviantrackr-passkeys' ),
				'setup'        => true,
				'continue_url' => $continue_to,
			]
		);

		login_header( __( 'Set up a passkey', 'riviantrackr-passkeys' ), '', new \WP_Error() );
		?>
		<div class="rtpk-setup">
			<h2 class="rtpk-setup__title"><?php esc_html_e( 'Sign in faster, without a password', 'riviantrackr-passkeys' ); ?></h2>
			<p class="rtpk-setup__lede">
				<?php
				printf(
					/* translators: %s: display name */
					esc_html__( 'Hi %s. A passkey lets you sign in with Face ID, Touch ID, Windows Hello or a security key.', 'riviantrackr-passkeys' ),
					esc_html( '' !== $user->display_name ? $user->display_name : $user->user_login )
				);
				?>
			</p>
			<ul class="rtpk-setup__benefits">
				<li><?php esc_html_e( 'Nothing to remember or type', 'riviantrackr-passkeys' ); ?></li>
				<li><?php esc_html_e( 'Can\'t be phished, leaked or guessed', 'riviantrackr-passkeys' ); ?></li>
				<li><?php esc_html_e( 'Your password keeps working as a backup', 'riviantrackr-passkeys' ); ?></li>
			</ul>

			<?php echo $manager; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ProfileManager::markup(). ?>

			<div class="rtpk-setup__done" data-rtpk-done <?php echo $count > 0 ? '' : 'hidden'; ?>>
				<p class="rtpk-setup__done-text">
					<?php
					echo $count > 0
						? esc_html__( 'You can sign in with a passkey on this account. Add more devices any time from your profile.', 'riviantrackr-passkeys' )
						: esc_html__( 'All set. Next time, choose "Sign in with a passkey" on the login page.', 'riviantrackr-passkeys' );
					?>
				</p>
				<a class="rtpk-btn rtpk-btn--primary rtpk-setup__continue" href="<?php echo esc_url( $continue_to ); ?>">
					<?php
					printf(
						/* translators: %s: site name */
						esc_html__( 'Continue to %s', 'riviantrackr-passkeys' ),
						esc_html( (string) get_bloginfo( 'name' ) )
					);
					?>
				</a>
			</div>

			<p class="rtpk-setup__skip" data-rtpk-skip <?php echo $count > 0 ? 'hidden' : ''; ?>>
				<a href="<?php echo esc_url( $continue_to ); ?>"><?php esc_html_e( 'Skip for now', 'riviantrackr-passkeys' ); ?></a>
			</p>
		</div>
		<?php
		login_footer();
	}
}
