<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The focused "Set up a passkey" screen.
 *
 * Invitation links land on wp-login.php?action=passkey-setup, where a valid
 * token signs the member in (when the magic-link option is on) so the whole
 * flow is: open email → tap → create. The screen itself renders either there
 * or, when the owner picked a passkey setup page under Settings → Passkeys →
 * Pages, on that page through the [riviantrackr_passkey_setup] shortcode in
 * the sign-in page's card.
 */
final class SetupPage {

	public const SHORTCODE = 'riviantrackr_passkey_setup';

	private bool $assets_registered = false;

	public function __construct(
		private InviteService $invites,
		private ProfileManager $manager,
		private CredentialStore $store,
		private Settings $settings
	) {}

	public function register_hooks(): void {
		add_action( 'login_form_' . InviteService::SETUP_ACTION, [ $this, 'handle' ] );
		add_filter( 'login_message', [ $this, 'login_message' ] );
		add_filter( 'login_body_class', [ $this, 'body_class' ] );
		add_shortcode( self::SHORTCODE, [ $this, 'shortcode' ] );
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

		// The owner's own setup page takes over from the wp-login.php screen.
		if ( '' !== $this->settings->setup_page_url() ) {
			wp_safe_redirect( $this->invites->setup_url( $continue_to ) );
			exit;
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
			return (string) $message . '<p class="message">' . esc_html( self::expired_hint() ) . '</p>';
		}
		if ( self::leads_to_setup( $redirect_to ) ) {
			return (string) $message . '<p class="message">' . esc_html( self::setup_hint() ) . '</p>';
		}
		return (string) $message;
	}

	/** Shown when an invitation link was expired or already used. */
	public static function expired_hint(): string {
		return __( 'That invitation link has expired or was already used. Sign in and we will take you straight to passkey setup.', 'riviantrackr-passkeys' );
	}

	/** Shown when the visitor will land on passkey setup after signing in. */
	public static function setup_hint(): string {
		return __( 'Sign in once with your password to set up your passkey.', 'riviantrackr-passkeys' );
	}

	/**
	 * Whether a redirect_to value points at passkey setup: the wp-login.php
	 * screen, or the owner's setup page when one is chosen.
	 *
	 * @param string $redirect_to Validated redirect_to value.
	 * @param string $setup_page  URL of the setup page, or ''.
	 */
	public static function leads_to_setup( string $redirect_to, string $setup_page = '' ): bool {
		if ( '' === $redirect_to ) {
			return false;
		}
		if ( str_contains( $redirect_to, 'action=' . InviteService::SETUP_ACTION ) ) {
			return true;
		}
		if ( '' === $setup_page ) {
			return false;
		}
		$page_path = rtrim( (string) wp_parse_url( $setup_page, PHP_URL_PATH ), '/' );
		$to_path   = rtrim( (string) wp_parse_url( $redirect_to, PHP_URL_PATH ), '/' );
		if ( '' !== $page_path ) {
			return $page_path === $to_path;
		}
		// Plain permalinks (?page_id=N): compare the query's page_id.
		parse_str( (string) wp_parse_url( $setup_page, PHP_URL_QUERY ), $page_query );
		parse_str( (string) wp_parse_url( $redirect_to, PHP_URL_QUERY ), $to_query );
		return isset( $page_query['page_id'] ) && isset( $to_query['page_id'] ) && (string) $page_query['page_id'] === (string) $to_query['page_id'];
	}

	// ---- front-end page --------------------------------------------------

	/**
	 * Stylesheet for the shortcode's card (the manager's own comes with it).
	 */
	public function enqueue(): void {
		if ( ! $this->assets_registered ) {
			$this->assets_registered = true;
			$file                    = 'assets/passkeys-setup-page' . RTPK_ASSET_SUFFIX . '.css';
			wp_register_style( 'rtpk-setup-page', RTPK_URL . $file, [ 'rtpk-manage' ], Helpers::asset_version( $file ) );
		}
		wp_enqueue_style( 'rtpk-setup-page' );
	}

	/**
	 * [riviantrackr_passkey_setup theme="dark|light"]
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts = [] ): string {
		$atts  = shortcode_atts( [ 'theme' => 'dark' ], is_array( $atts ) ? $atts : [], self::SHORTCODE );
		$light = 'light' === $atts['theme'];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$requested = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( (string) $_GET['redirect_to'] ) ), '' ) : '';
		// phpcs:enable

		if ( ! is_user_logged_in() ) {
			$page = is_singular() ? (string) get_permalink( get_queried_object_id() ) : '';
			if ( '' === $page ) {
				$page = $this->settings->setup_page_url();
			}
			$back = '' !== $requested && '' !== $page ? add_query_arg( 'redirect_to', rawurlencode( $requested ), $page ) : $page;
			ob_start();
			?>
			<div class="rtpk-setup-page<?php echo $light ? ' is-light' : ''; ?> is-signed-out" data-rtpk-setup-page>
				<div class="rtpk-setup-page__state">
					<h2 class="rtpk-setup-page__title"><?php esc_html_e( 'Set up a passkey', 'riviantrackr-passkeys' ); ?></h2>
					<p class="rtpk-setup-page__lede"><?php esc_html_e( 'Sign in once with your password and we’ll bring you straight back here to create it.', 'riviantrackr-passkeys' ); ?></p>
					<a class="rtpk-btn rtpk-btn--primary" href="<?php echo esc_url( wp_login_url( $back ) ); ?>"><?php esc_html_e( 'Sign in', 'riviantrackr-passkeys' ); ?></a>
				</div>
			</div>
			<?php
			$this->enqueue();
			return (string) ob_get_clean();
		}

		$user = wp_get_current_user();
		$html = $this->card_markup( $user, $this->continue_url( $user, $requested ), $light );
		$this->enqueue(); // After the manager registered its stylesheet, which ours depends on.
		return $html;
	}

	/**
	 * The setup card: pitch, embedded manager, done panel, skip link. Shared
	 * by the shortcode and (in the wp-login.php chrome) render().
	 */
	private function card_markup( \WP_User $user, string $continue_to, bool $light ): string {
		$count   = $this->store->count_for_user( $user->ID );
		$manager = $this->manager->markup(
			$user,
			true,
			$light,
			[
				'title'        => $count > 0 ? __( 'Your passkeys', 'riviantrackr-passkeys' ) : __( 'Create your passkey', 'riviantrackr-passkeys' ),
				'desc'         => __( 'Tap the button and follow the prompt from your browser. It takes about ten seconds.', 'riviantrackr-passkeys' ),
				'button'       => $count > 0 ? __( 'Add another device', 'riviantrackr-passkeys' ) : __( 'Create a passkey', 'riviantrackr-passkeys' ),
				'setup'        => true,
				'continue_url' => $continue_to,
			]
		);

		ob_start();
		?>
		<div class="rtpk-setup-page<?php echo $light ? ' is-light' : ''; ?>" data-rtpk-setup-page>
			<h2 class="rtpk-setup-page__title"><?php esc_html_e( 'Sign in faster, without a password', 'riviantrackr-passkeys' ); ?></h2>
			<p class="rtpk-setup-page__lede">
				<?php
				printf(
					/* translators: %s: display name */
					esc_html__( 'Hi %s. A passkey lets you sign in with Face ID, Touch ID, Windows Hello or a security key.', 'riviantrackr-passkeys' ),
					esc_html( '' !== $user->display_name ? $user->display_name : $user->user_login )
				);
				?>
			</p>
			<ul class="rtpk-setup-page__benefits">
				<li><?php esc_html_e( 'Nothing to remember or type', 'riviantrackr-passkeys' ); ?></li>
				<li><?php esc_html_e( 'Can\'t be phished, leaked or guessed', 'riviantrackr-passkeys' ); ?></li>
				<li><?php esc_html_e( 'Your password keeps working as a backup', 'riviantrackr-passkeys' ); ?></li>
			</ul>

			<?php echo $manager; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ProfileManager::markup(). ?>

			<div class="rtpk-setup-page__done" data-rtpk-done <?php echo $count > 0 ? '' : 'hidden'; ?>>
				<p class="rtpk-setup-page__done-text">
					<?php
					echo $count > 0
						? esc_html__( 'You can sign in with a passkey on this account. Add more devices any time from your account page.', 'riviantrackr-passkeys' )
						: esc_html__( 'All set. Next time, choose "Sign in with a passkey" on the sign-in page.', 'riviantrackr-passkeys' );
					?>
				</p>
				<a class="rtpk-btn rtpk-btn--primary rtpk-setup-page__continue" href="<?php echo esc_url( $continue_to ); ?>">
					<?php
					printf(
						/* translators: %s: site name */
						esc_html__( 'Continue to %s', 'riviantrackr-passkeys' ),
						esc_html( (string) get_bloginfo( 'name' ) )
					);
					?>
				</a>
			</div>

			<p class="rtpk-setup-page__skip" data-rtpk-skip <?php echo $count > 0 ? 'hidden' : ''; ?>>
				<a href="<?php echo esc_url( $continue_to ); ?>"><?php esc_html_e( 'Skip for now', 'riviantrackr-passkeys' ); ?></a>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
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
		if ( $user->has_cap( 'edit_posts' ) ) {
			return admin_url();
		}
		$account = $this->settings->account_page_url();
		return '' !== $account ? $account : home_url( '/' );
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
