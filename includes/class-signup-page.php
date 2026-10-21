<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front end of verify-first signup: the [riviantrackr_signup] form, the
 * REST endpoint it posts to, and the wp-login.php?action=passkey-signup
 * landing page that turns a clicked link into an account.
 */
final class SignupPage {

	private bool $assets_registered = false;
	private int $instance           = 0;

	public function __construct(
		private SignupService $signup,
		private InviteService $invites,
		private Settings $settings
	) {}

	public function register_hooks(): void {
		add_shortcode( 'riviantrackr_signup', [ $this, 'shortcode' ] );
		add_action( 'login_form_' . SignupService::ACTION, [ $this, 'handle' ] );
		add_filter( 'login_message', [ $this, 'login_message' ] );
		add_filter( 'login_body_class', [ $this, 'body_class' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			RTPK_REST_NAMESPACE,
			'/signup/request',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_request' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'email'     => [
						'type'     => 'string',
						'required' => true,
					],
					'name'      => [
						'type'     => 'string',
						'required' => false,
					],
					'website'   => [
						'type'     => 'string',
						'required' => false,
					],
					'ts'        => [
						'type'     => 'string',
						'required' => false,
					],
					'sig'       => [
						'type'     => 'string',
						'required' => false,
					],
					'turnstile' => [
						'type'     => 'string',
						'required' => false,
					],
				],
			]
		);
		register_rest_route(
			RTPK_REST_NAMESPACE,
			'/signup/token',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_token' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * GET signup/token
	 *
	 * A fresh form token. The form markup carries one too, but a full-page
	 * cache (Cloudflare APO, WP Rocket…) can serve that markup for days,
	 * past FORM_TOKEN_MAX_AGE, so the script swaps in one from here.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_token() {
		if ( ! $this->signup->enabled() ) {
			return new \WP_Error( 'rtpk_signup_disabled', __( 'Signups are closed right now.', 'riviantrackr-passkeys' ), [ 'status' => 403 ] );
		}
		$response = rest_ensure_response( $this->signup->form_token() );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		return $response;
	}

	/**
	 * POST signup/request
	 *
	 * @param \WP_REST_Request $request Form fields.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_request( \WP_REST_Request $request ) {
		$input = [];
		foreach ( [ 'email', 'name', 'website', 'ts', 'sig', 'turnstile' ] as $key ) {
			$input[ $key ] = (string) ( $request->get_param( $key ) ?? '' );
		}
		$result = $this->signup->handle_request( $input, Helpers::client_ip() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	// ---- assets --------------------------------------------------------

	/**
	 * Register (once) the form script/style, the shared core helpers and
	 * Cloudflare's Turnstile loader.
	 */
	private function register_assets(): void {
		if ( $this->assets_registered ) {
			return;
		}
		$this->assets_registered = true;
		$suffix                  = RTPK_ASSET_SUFFIX;

		wp_register_script( 'rtpk-core', RTPK_URL . "assets/passkeys-core{$suffix}.js", [], Helpers::asset_version( "assets/passkeys-core{$suffix}.js" ), true );
		wp_register_script( 'rtpk-signup', RTPK_URL . "assets/passkeys-signup{$suffix}.js", [ 'rtpk-core' ], Helpers::asset_version( "assets/passkeys-signup{$suffix}.js" ), true );
		wp_register_style( 'rtpk-signup', RTPK_URL . "assets/passkeys-signup{$suffix}.css", [], Helpers::asset_version( "assets/passkeys-signup{$suffix}.css" ) );
		wp_register_script( 'rtpk-turnstile', SignupService::TURNSTILE_SCRIPT_URL, [], null, [ 'strategy' => 'defer' ] ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Third-party script; Cloudflare versions it.
	}

	public function enqueue(): void {
		$this->register_assets();

		if ( ! wp_script_is( 'rtpk-signup', 'enqueued' ) ) {
			wp_localize_script(
				'rtpk-signup',
				'rtpkSignup',
				[
					'restUrl' => esc_url_raw( rest_url( RTPK_REST_NAMESPACE ) ),
					'i18n'    => [
						'sending'      => __( 'Sending your link…', 'riviantrackr-passkeys' ),
						'needEmail'    => __( 'Please enter your email address.', 'riviantrackr-passkeys' ),
						'needCaptcha'  => __( 'Please complete the check above first.', 'riviantrackr-passkeys' ),
						'genericError' => __( 'Something went wrong. Please try again.', 'riviantrackr-passkeys' ),
					],
				]
			);
		}

		wp_enqueue_style( 'rtpk-signup' );
		wp_enqueue_script( 'rtpk-signup' );
		if ( $this->signup->turnstile_enabled() ) {
			wp_enqueue_script( 'rtpk-turnstile' );
		}
	}

	// ---- shortcode -----------------------------------------------------

	/**
	 * [riviantrackr_signup theme="dark|light" redirect=""]
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts = [] ): string {
		$atts  = shortcode_atts(
			[
				'theme'    => 'dark',
				'redirect' => '',
			],
			is_array( $atts ) ? $atts : [],
			'riviantrackr_signup'
		);
		$light = 'light' === $atts['theme'];

		if ( ! $this->signup->enabled() ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<div class="rtpk-signup' . ( $light ? ' is-light' : '' ) . '"><p class="rtpk-signup__note">'
					. esc_html__( 'Signups are turned off. Enable them under Settings → Passkeys → Open signup. Only administrators see this notice.', 'riviantrackr-passkeys' )
					. '</p></div>';
			}
			return '';
		}

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$to   = '' !== $atts['redirect'] ? esc_url_raw( (string) $atts['redirect'] ) : $this->signup->continue_url();
			return '<div class="rtpk-signup' . ( $light ? ' is-light' : '' ) . '"><p class="rtpk-signup__note">'
				. sprintf(
					/* translators: 1: display name, 2: continue URL */
					wp_kses_post( __( 'You are already signed in as %1$s. <a href="%2$s">Continue</a>', 'riviantrackr-passkeys' ) ),
					esc_html( '' !== $user->display_name ? $user->display_name : $user->user_login ),
					esc_url( $to )
				)
				. '</p></div>';
		}

		$this->enqueue();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$code = isset( $_GET['rtpk_signup'] ) ? sanitize_key( wp_unslash( (string) $_GET['rtpk_signup'] ) ) : '';
		// phpcs:enable
		$notice = self::notice( $code, (int) $this->signup->get( 'expiry_hours' ) );
		if ( null === $notice ) {
			return $this->form_markup( $light );
		}
		return $this->form_markup(
			$light,
			[
				'notice' => $notice,
				'button' => __( 'Send me a new link', 'riviantrackr-passkeys' ),
			]
		);
	}

	/**
	 * Notice for ?rtpk_signup on the join page: the wp-login.php landing
	 * page sends expired and failed links back here. Pure.
	 *
	 * @param string $code  Query value.
	 * @param int    $hours Link lifetime from the settings.
	 * @return array{type:string,text:string}|null
	 */
	public static function notice( string $code, int $hours ): ?array {
		switch ( $code ) {
			case 'expired':
				return [
					'type' => 'info',
					'text' => sprintf(
						/* translators: %d: number of hours */
						_n( 'That sign-up link has expired or was already used. Links work once and expire after %d hour. Enter your email again and we’ll send a fresh one.', 'That sign-up link has expired or was already used. Links work once and expire after %d hours. Enter your email again and we’ll send a fresh one.', $hours, 'riviantrackr-passkeys' ),
						$hours
					),
				];
			case 'failed':
				return [
					'type' => 'error',
					'text' => __( 'We couldn’t create your account from that link. Enter your email again to get a new one.', 'riviantrackr-passkeys' ),
				];
		}
		return null;
	}

	/**
	 * The signup form. Also embedded on the expired-link page.
	 *
	 * @param bool                $light Light theme.
	 * @param array<string,mixed> $args  Optional: title, desc, button, show_login (bool), notice ({type,text}).
	 */
	public function form_markup( bool $light, array $args = [] ): string {
		++$this->instance;
		$id     = 'rtpk-signup-' . $this->instance;
		$token  = $this->signup->form_token();
		$title  = (string) ( $args['title'] ?? __( 'Create your account', 'riviantrackr-passkeys' ) );
		$desc   = (string) ( $args['desc'] ?? __( 'Enter your email and we’ll send you a link. Click it to confirm, then create a passkey so you can sign in with Face ID, Touch ID, Windows Hello or a security key. No password to remember.', 'riviantrackr-passkeys' ) );
		$button = (string) ( $args['button'] ?? __( 'Send me a sign-up link', 'riviantrackr-passkeys' ) );
		$login  = ! isset( $args['show_login'] ) || ! empty( $args['show_login'] );
		$turn   = $this->signup->turnstile_enabled();

		ob_start();
		?>
		<div class="rtpk-signup<?php echo $light ? ' is-light' : ''; ?>" data-rtpk-signup data-turnstile="<?php echo $turn ? '1' : '0'; ?>">
			<?php if ( ! empty( $args['notice']['text'] ) ) : ?>
				<div class="rtpk-signup__notice is-<?php echo esc_attr( (string) ( $args['notice']['type'] ?? 'info' ) ); ?>" role="<?php echo 'error' === ( $args['notice']['type'] ?? '' ) ? 'alert' : 'status'; ?>">
					<?php echo esc_html( (string) $args['notice']['text'] ); ?>
				</div>
			<?php endif; ?>
			<form class="rtpk-signup__form" data-rtpk-signup-form method="post" action="" novalidate>
				<?php if ( '' !== $title ) : ?>
					<h3 class="rtpk-signup__title"><?php echo esc_html( $title ); ?></h3>
				<?php endif; ?>
				<?php if ( '' !== $desc ) : ?>
					<p class="rtpk-signup__desc"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>

				<div class="rtpk-signup__field">
					<label class="rtpk-signup__label" for="<?php echo esc_attr( $id ); ?>-email"><?php esc_html_e( 'Email address', 'riviantrackr-passkeys' ); ?></label>
					<input type="email" id="<?php echo esc_attr( $id ); ?>-email" class="rtpk-signup__input" name="email" required autocomplete="email" inputmode="email" spellcheck="false" maxlength="100" placeholder="you@example.com">
				</div>
				<div class="rtpk-signup__field">
					<label class="rtpk-signup__label" for="<?php echo esc_attr( $id ); ?>-name"><?php esc_html_e( 'Name', 'riviantrackr-passkeys' ); ?> <span class="rtpk-signup__optional"><?php esc_html_e( 'optional', 'riviantrackr-passkeys' ); ?></span></label>
					<input type="text" id="<?php echo esc_attr( $id ); ?>-name" class="rtpk-signup__input" name="name" autocomplete="name" maxlength="60" placeholder="<?php esc_attr_e( 'How should we call you?', 'riviantrackr-passkeys' ); ?>">
				</div>

				<div class="rtpk-signup__hp" aria-hidden="true">
					<label for="<?php echo esc_attr( $id ); ?>-website"><?php esc_html_e( 'Website', 'riviantrackr-passkeys' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $id ); ?>-website" name="website" tabindex="-1" autocomplete="off" value="">
				</div>
				<input type="hidden" name="ts" value="<?php echo (int) $token['ts']; ?>">
				<input type="hidden" name="sig" value="<?php echo esc_attr( $token['sig'] ); ?>">

				<?php if ( $turn ) : ?>
					<div class="rtpk-signup__turnstile cf-turnstile" data-sitekey="<?php echo esc_attr( (string) $this->signup->get( 'turnstile_site_key' ) ); ?>" data-theme="<?php echo $light ? 'light' : 'dark'; ?>" data-size="flexible" data-rtpk-turnstile></div>
				<?php endif; ?>

				<button type="submit" class="rtpk-btn rtpk-btn--primary rtpk-signup__submit" data-rtpk-signup-submit>
					<?php echo LoginForm::icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
					<span><?php echo esc_html( $button ); ?></span>
				</button>

				<p class="rtpk-signup__msg" data-rtpk-signup-msg role="status" aria-live="polite" hidden></p>

				<?php if ( $login ) : ?>
					<p class="rtpk-signup__alt">
						<?php
						printf(
							/* translators: %s: login URL */
							wp_kses_post( __( 'Already a member? <a href="%s">Sign in</a>', 'riviantrackr-passkeys' ) ),
							esc_url( wp_login_url() )
						);
						?>
					</p>
				<?php endif; ?>
				<noscript><p class="rtpk-signup__msg is-error"><?php esc_html_e( 'JavaScript is required to sign up.', 'riviantrackr-passkeys' ); ?></p></noscript>
			</form>

			<div class="rtpk-signup__sent" data-rtpk-signup-sent hidden>
				<div class="rtpk-signup__sent-icon" aria-hidden="true">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M3 7.5v9A2.5 2.5 0 0 0 5.5 19h13a2.5 2.5 0 0 0 2.5-2.5v-9M3 7.5A2.5 2.5 0 0 1 5.5 5h13A2.5 2.5 0 0 1 21 7.5M3 7.5l9 6 9-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</div>
				<h3 class="rtpk-signup__title"><?php esc_html_e( 'Check your inbox', 'riviantrackr-passkeys' ); ?></h3>
				<p class="rtpk-signup__desc" data-rtpk-signup-sent-text></p>
				<p class="rtpk-signup__fine">
					<?php esc_html_e( 'Nothing after a few minutes? Look in spam, or', 'riviantrackr-passkeys' ); ?>
					<button type="button" class="rtpk-signup__link" data-rtpk-signup-again><?php esc_html_e( 'try a different address', 'riviantrackr-passkeys' ); ?></button>.
				</p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// ---- landing page --------------------------------------------------

	/**
	 * Landing page for wp-login.php?action=passkey-signup&token=…: creates
	 * the account, signs the member in and hands over to passkey setup.
	 */
	public function handle(): void {
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token-authenticated link.

		if ( is_user_logged_in() ) {
			wp_safe_redirect( $this->invites->setup_url( $this->signup->continue_url() ) );
			exit;
		}

		if ( ! $this->signup->enabled() ) {
			$this->render_page(
				__( 'Signups are closed', 'riviantrackr-passkeys' ),
				__( 'Signups are closed', 'riviantrackr-passkeys' ),
				__( 'New accounts are not being accepted right now. If you already have one, sign in below.', 'riviantrackr-passkeys' ),
				false
			);
			exit;
		}

		$join = $this->settings->register_page_url();

		$row = '' !== $token ? $this->signup->validate_token( $token ) : null;
		if ( null === $row ) {
			if ( '' !== $join ) {
				wp_safe_redirect( add_query_arg( 'rtpk_signup', 'expired', $join ) );
				exit;
			}
			$hours = (int) $this->signup->get( 'expiry_hours' );
			$this->render_page(
				__( 'Sign-up link expired', 'riviantrackr-passkeys' ),
				__( 'This link has expired', 'riviantrackr-passkeys' ),
				sprintf(
					/* translators: %d: number of hours */
					_n( 'Sign-up links work once and expire after %d hour. Enter your email again and we’ll send a fresh one.', 'Sign-up links work once and expire after %d hours. Enter your email again and we’ll send a fresh one.', $hours, 'riviantrackr-passkeys' ),
					$hours
				),
				true
			);
			exit;
		}

		$user = $this->signup->complete( $row );

		if ( is_wp_error( $user ) ) {
			if ( 'rtpk_signup_exists' === $user->get_error_code() ) {
				wp_safe_redirect( add_query_arg( 'rtpk_signup', 'exists', wp_login_url( $this->invites->setup_url( $this->signup->continue_url() ) ) ) );
				exit;
			}
			if ( '' !== $join ) {
				wp_safe_redirect( add_query_arg( 'rtpk_signup', 'failed', $join ) );
				exit;
			}
			$this->render_page(
				__( 'Something went wrong', 'riviantrackr-passkeys' ),
				__( 'We couldn’t create your account', 'riviantrackr-passkeys' ),
				$user->get_error_message() . ' ' . __( 'Enter your email again to get a new link.', 'riviantrackr-passkeys' ),
				true
			);
			exit;
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		/**
		 * Fires when a newly verified member is signed in from their signup link.
		 *
		 * @param \WP_User $user The new member.
		 */
		do_action( 'riviantrackr_passkeys_signup_login', $user );

		wp_safe_redirect( $this->invites->setup_url( $this->signup->continue_url() ) );
		exit;
	}

	/**
	 * Hint on the normal login form when a verified address already had an account.
	 *
	 * @param string $message Existing message HTML.
	 */
	public function login_message( $message ): string {
		if ( isset( $_GET['rtpk_signup'] ) && 'exists' === $_GET['rtpk_signup'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return (string) $message . '<p class="message">' . esc_html( self::exists_hint() ) . '</p>';
		}
		return (string) $message;
	}

	/** Shown when a verified signup address already had an account. */
	public static function exists_hint(): string {
		return __( 'You already have an account with that email address. Sign in to continue, or use “Lost your password?” if you need to reset it.', 'riviantrackr-passkeys' );
	}

	/**
	 * @param string[] $classes Login body classes.
	 * @return string[]
	 */
	public function body_class( $classes ): array {
		$classes = (array) $classes;
		if ( isset( $_GET['action'] ) && SignupService::ACTION === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$classes[] = 'rtpk-setup-page';
			$classes[] = 'rtpk-signup-page';
		}
		return $classes;
	}

	/**
	 * Friendly page inside the wp-login.php chrome, optionally with the
	 * "request a new link" form.
	 */
	private function render_page( string $title, string $heading, string $text, bool $with_form ): void {
		wp_enqueue_style( 'rtpk-setup', RTPK_URL . 'assets/passkeys-setup' . RTPK_ASSET_SUFFIX . '.css', [], Helpers::asset_version( 'assets/passkeys-setup' . RTPK_ASSET_SUFFIX . '.css' ) );
		$form = '';
		if ( $with_form ) {
			$this->enqueue();
			$form = $this->form_markup(
				true,
				[
					'title'      => '',
					'desc'       => '',
					'button'     => __( 'Send me a new link', 'riviantrackr-passkeys' ),
					'show_login' => false,
				]
			);
		}

		login_header( $title, '', new \WP_Error() );
		?>
		<div class="rtpk-setup rtpk-setup--signup">
			<h2 class="rtpk-setup__title"><?php echo esc_html( $heading ); ?></h2>
			<p class="rtpk-setup__lede"><?php echo esc_html( $text ); ?></p>
			<?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in form_markup(). ?>
			<p class="rtpk-setup__skip">
				<a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Already a member? Sign in', 'riviantrackr-passkeys' ); ?></a>
			</p>
		</div>
		<?php
		login_footer();
	}
}
