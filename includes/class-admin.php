<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings → Passkeys screen.
 */
final class Admin {

	public const PAGE_SLUG = 'riviantrackr-passkeys';

	public function __construct(
		private Settings $settings,
		private CredentialStore $store
	) {}

	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( RTPK_FILE ), [ $this, 'action_links' ] );
	}

	public function register_settings(): void {
		register_setting(
			'riviantrackr_passkeys',
			Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this->settings, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	public function add_menu(): void {
		add_options_page(
			__( 'Passkeys', 'riviantrackr-passkeys' ),
			__( 'Passkeys', 'riviantrackr-passkeys' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	public function enqueue( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'rtpk-admin', RTPK_URL . 'assets/passkeys-admin' . RTPK_ASSET_SUFFIX . '.css', [], RTPK_VERSION );
	}

	/**
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'riviantrackr-passkeys' ) . '</a>' );
		return $links;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$o        = $this->settings->all();
		$totals   = $this->store->totals();
		$is_https = str_starts_with( home_url( '/' ), 'https://' ) || 'localhost' === Helpers::default_rp_id();
		$rp_id    = $this->settings->rp_id();
		$origins  = $this->settings->allowed_origins();
		$name     = Settings::OPTION;
		?>
		<div class="wrap rtpk-settings-wrap">
			<div class="rtpk-header">
				<h1><?php esc_html_e( 'Passkeys', 'riviantrackr-passkeys' ); ?></h1>
				<p><?php esc_html_e( 'Passwordless, phishing-resistant sign-in with WebAuthn / FIDO2.', 'riviantrackr-passkeys' ); ?></p>
			</div>

			<?php settings_errors( Settings::OPTION ); ?>

			<div class="rtpk-status-card <?php echo $is_https ? 'is-active' : 'is-warning'; ?>">
				<div class="rtpk-status-icon" aria-hidden="true"><?php echo $is_https ? '&#10003;' : '&#9888;'; ?></div>
				<div class="rtpk-status-content">
					<?php if ( $is_https ) : ?>
						<h3><?php esc_html_e( 'Ready', 'riviantrackr-passkeys' ); ?></h3>
						<p>
							<?php
							printf(
								/* translators: 1: relying party ID, 2: number of passkeys, 3: number of users */
								esc_html__( 'Relying party %1$s · %2$s passkeys registered by %3$s users.', 'riviantrackr-passkeys' ),
								'<code>' . esc_html( $rp_id ) . '</code>',
								'<strong>' . (int) $totals['credentials'] . '</strong>',
								'<strong>' . (int) $totals['users'] . '</strong>'
							);
							?>
						</p>
					<?php else : ?>
						<h3><?php esc_html_e( 'HTTPS required', 'riviantrackr-passkeys' ); ?></h3>
						<p><?php esc_html_e( 'Browsers only allow passkeys on secure origins. Your site address must start with https:// for passkeys to work.', 'riviantrackr-passkeys' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'riviantrackr_passkeys' ); ?>

				<div class="rtpk-section">
					<div class="rtpk-section-header">
						<h2><?php esc_html_e( 'Relying party', 'riviantrackr-passkeys' ); ?></h2>
						<p><?php esc_html_e( 'How your site identifies itself to authenticators. Changing the ID invalidates every existing passkey.', 'riviantrackr-passkeys' ); ?></p>
					</div>
					<div class="rtpk-section-body">
						<div class="rtpk-field-row">
							<label class="rtpk-field-label" for="rtpk-rp-name"><?php esc_html_e( 'Display name', 'riviantrackr-passkeys' ); ?></label>
							<input type="text" id="rtpk-rp-name" class="rtpk-input" name="<?php echo esc_attr( $name ); ?>[rp_name]" value="<?php echo esc_attr( (string) $o['rp_name'] ); ?>">
							<p class="rtpk-field-description"><?php esc_html_e( 'Shown by the browser and password manager when a passkey is created.', 'riviantrackr-passkeys' ); ?></p>
						</div>
						<div class="rtpk-field-row">
							<label class="rtpk-field-label" for="rtpk-rp-id"><?php esc_html_e( 'Relying party ID', 'riviantrackr-passkeys' ); ?></label>
							<input type="text" id="rtpk-rp-id" class="rtpk-input" name="<?php echo esc_attr( $name ); ?>[rp_id]" value="<?php echo esc_attr( (string) $o['rp_id'] ); ?>" placeholder="<?php echo esc_attr( Helpers::default_rp_id() ); ?>">
							<p class="rtpk-field-description">
								<?php
								printf(
									/* translators: %s: default domain */
									esc_html__( 'Leave blank to use %s. Set a parent domain (for example riviantrackr.com) only if passkeys must also work on subdomains.', 'riviantrackr-passkeys' ),
									'<code>' . esc_html( Helpers::default_rp_id() ) . '</code>'
								);
								?>
							</p>
						</div>
						<div class="rtpk-field-row">
							<label class="rtpk-field-label" for="rtpk-origins"><?php esc_html_e( 'Additional allowed origins', 'riviantrackr-passkeys' ); ?></label>
							<textarea id="rtpk-origins" class="rtpk-input rtpk-textarea" rows="3" name="<?php echo esc_attr( $name ); ?>[allowed_origins]" placeholder="https://app.example.com"><?php echo esc_textarea( (string) $o['allowed_origins'] ); ?></textarea>
							<p class="rtpk-field-description">
								<?php
								printf(
									/* translators: %s: list of origins */
									esc_html__( 'One per line. Responses are accepted only from these exact origins. Currently: %s', 'riviantrackr-passkeys' ),
									'<code>' . esc_html( implode( ', ', $origins ) ) . '</code>'
								);
								?>
							</p>
						</div>
					</div>
				</div>

				<div class="rtpk-section">
					<div class="rtpk-section-header">
						<h2><?php esc_html_e( 'Sign-in experience', 'riviantrackr-passkeys' ); ?></h2>
					</div>
					<div class="rtpk-section-body">
						<div class="rtpk-field-row rtpk-field-row--toggle">
							<div>
								<span class="rtpk-field-label"><?php esc_html_e( 'Passkey button on the login form', 'riviantrackr-passkeys' ); ?></span>
								<p class="rtpk-field-description"><?php esc_html_e( 'Adds “Sign in with a passkey” to wp-login.php and to forms rendered with wp_login_form().', 'riviantrackr-passkeys' ); ?></p>
							</div>
							<label class="rtpk-toggle">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[login_button]" value="1" <?php checked( ! empty( $o['login_button'] ) ); ?>>
								<span class="rtpk-toggle__track"><span class="rtpk-toggle__thumb"></span></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Show passkey button', 'riviantrackr-passkeys' ); ?></span>
							</label>
						</div>
						<div class="rtpk-field-row rtpk-field-row--toggle">
							<div>
								<span class="rtpk-field-label"><?php esc_html_e( 'Browser autofill (conditional UI)', 'riviantrackr-passkeys' ); ?></span>
								<p class="rtpk-field-description"><?php esc_html_e( 'Offers saved passkeys directly in the username field’s autofill menu, with no button press required.', 'riviantrackr-passkeys' ); ?></p>
							</div>
							<label class="rtpk-toggle">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[conditional_ui]" value="1" <?php checked( ! empty( $o['conditional_ui'] ) ); ?>>
								<span class="rtpk-toggle__track"><span class="rtpk-toggle__thumb"></span></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Enable autofill', 'riviantrackr-passkeys' ); ?></span>
							</label>
						</div>
						<div class="rtpk-field-row">
							<label class="rtpk-field-label" for="rtpk-uv"><?php esc_html_e( 'User verification', 'riviantrackr-passkeys' ); ?></label>
							<select id="rtpk-uv" class="rtpk-input rtpk-select" name="<?php echo esc_attr( $name ); ?>[user_verification]">
								<option value="preferred" <?php selected( $o['user_verification'], 'preferred' ); ?>><?php esc_html_e( 'Preferred — ask for biometrics or PIN when available', 'riviantrackr-passkeys' ); ?></option>
								<option value="required" <?php selected( $o['user_verification'], 'required' ); ?>><?php esc_html_e( 'Required — reject passkeys that did not verify the user', 'riviantrackr-passkeys' ); ?></option>
							</select>
							<p class="rtpk-field-description"><?php esc_html_e( '“Required” is the strongest setting but blocks some older security keys without a PIN.', 'riviantrackr-passkeys' ); ?></p>
						</div>
						<div class="rtpk-field-row">
							<label class="rtpk-field-label" for="rtpk-timeout"><?php esc_html_e( 'Prompt timeout (seconds)', 'riviantrackr-passkeys' ); ?></label>
							<input type="number" id="rtpk-timeout" class="rtpk-input rtpk-input--short" min="30" max="300" step="5" name="<?php echo esc_attr( $name ); ?>[timeout]" value="<?php echo (int) $o['timeout']; ?>">
							<p class="rtpk-field-description"><?php esc_html_e( 'How long the browser waits for the user to complete the prompt. 30–300.', 'riviantrackr-passkeys' ); ?></p>
						</div>
					</div>
				</div>

				<div class="rtpk-section">
					<div class="rtpk-section-header">
						<h2><?php esc_html_e( 'Data', 'riviantrackr-passkeys' ); ?></h2>
					</div>
					<div class="rtpk-section-body">
						<div class="rtpk-field-row rtpk-field-row--toggle">
							<div>
								<span class="rtpk-field-label"><?php esc_html_e( 'Keep passkeys when the plugin is deleted', 'riviantrackr-passkeys' ); ?></span>
								<p class="rtpk-field-description"><?php esc_html_e( 'By default the credentials table and settings are removed on uninstall.', 'riviantrackr-passkeys' ); ?></p>
							</div>
							<label class="rtpk-toggle">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[preserve_data]" value="1" <?php checked( ! empty( $o['preserve_data'] ) ); ?>>
								<span class="rtpk-toggle__track"><span class="rtpk-toggle__thumb"></span></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Preserve data on uninstall', 'riviantrackr-passkeys' ); ?></span>
							</label>
						</div>
					</div>
				</div>

				<div class="rtpk-actions">
					<?php submit_button( __( 'Save changes', 'riviantrackr-passkeys' ), 'rtpk-btn rtpk-btn--primary', 'submit', false ); ?>
				</div>
			</form>

			<div class="rtpk-section">
				<div class="rtpk-section-header">
					<h2><?php esc_html_e( 'Invite members', 'riviantrackr-passkeys' ); ?></h2>
					<p><?php esc_html_e( 'Email members who have no passkey yet with a personal one-tap setup link.', 'riviantrackr-passkeys' ); ?></p>
				</div>
				<div class="rtpk-section-body">
					<div class="rtpk-field-row rtpk-field-row--actions">
						<a class="button rtpk-btn rtpk-btn--secondary" href="<?php echo esc_url( InviteAdmin::page_url() ); ?>"><?php esc_html_e( 'Open passkey invitations', 'riviantrackr-passkeys' ); ?></a>
					</div>
				</div>
			</div>

			<div class="rtpk-section rtpk-section--help">
				<div class="rtpk-section-header">
					<h2><?php esc_html_e( 'Shortcodes', 'riviantrackr-passkeys' ); ?></h2>
				</div>
				<div class="rtpk-section-body">
					<div class="rtpk-field-row">
						<code>[riviantrackr_passkeys]</code>
						<p class="rtpk-field-description"><?php esc_html_e( 'Passkey manager for the signed-in member on a front-end account page. Dark theme by default; add theme="light" for light backgrounds.', 'riviantrackr-passkeys' ); ?></p>
					</div>
					<div class="rtpk-field-row">
						<code>[riviantrackr_passkey_login redirect="/my-account/"]</code>
						<p class="rtpk-field-description"><?php esc_html_e( 'Stand-alone “Sign in with a passkey” button for custom login pages. Hidden for signed-in visitors.', 'riviantrackr-passkeys' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
