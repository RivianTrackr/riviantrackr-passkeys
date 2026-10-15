<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Passkey management UI on the WordPress profile screen and via the
 * [riviantrackr_passkeys] shortcode for front-end account pages.
 */
final class ProfileManager {

	private bool $assets_registered = false;

	public function __construct(
		private Settings $settings,
		private CredentialStore $store,
		private RestController $rest
	) {}

	public function register_hooks(): void {
		add_action( 'show_user_profile', [ $this, 'render_own_profile' ] );
		add_action( 'edit_user_profile', [ $this, 'render_other_profile' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
		add_shortcode( 'riviantrackr_passkeys', [ $this, 'shortcode' ] );
	}

	private function register_assets(): void {
		if ( $this->assets_registered ) {
			return;
		}
		$this->assets_registered = true;
		$suffix                  = RTPK_ASSET_SUFFIX;

		wp_register_script( 'rtpk-core', RTPK_URL . "assets/passkeys-core{$suffix}.js", [], RTPK_VERSION, true );
		wp_register_script( 'rtpk-manage', RTPK_URL . "assets/passkeys-manage{$suffix}.js", [ 'rtpk-core' ], RTPK_VERSION, true );
		wp_register_style( 'rtpk-manage', RTPK_URL . "assets/passkeys-manage{$suffix}.css", [], RTPK_VERSION );
	}

	private function enqueue(): void {
		$this->register_assets();

		if ( ! wp_script_is( 'rtpk-manage', 'enqueued' ) ) {
			wp_localize_script(
				'rtpk-manage',
				'rtpkManage',
				[
					'restUrl'     => esc_url_raw( rest_url( RTPK_REST_NAMESPACE ) ),
					'nonce'       => wp_create_nonce( 'wp_rest' ),
					'maxPasskeys' => RTPK_MAX_PASSKEYS_PER_USER,
					'i18n'        => [
						'unsupported'       => __( 'This browser does not support passkeys. Try Safari, Chrome, Edge or Firefox on a recent device.', 'riviantrackr-passkeys' ),
						'working'           => __( 'Follow the prompts on your device…', 'riviantrackr-passkeys' ),
						'added'             => __( 'Passkey added. You can now sign in without a password.', 'riviantrackr-passkeys' ),
						'removed'           => __( 'Passkey removed.', 'riviantrackr-passkeys' ),
						'renamed'           => __( 'Passkey renamed.', 'riviantrackr-passkeys' ),
						'confirmDelete'     => __( 'Remove this passkey? You will no longer be able to sign in with it.', 'riviantrackr-passkeys' ),
						'cancelled'         => __( 'The request was cancelled or timed out.', 'riviantrackr-passkeys' ),
						'alreadyRegistered' => __( 'This device already has a passkey for your account.', 'riviantrackr-passkeys' ),
						'security'          => __( 'Passkeys need a secure (HTTPS) connection.', 'riviantrackr-passkeys' ),
						'genericError'      => __( 'Something went wrong. Please try again.', 'riviantrackr-passkeys' ),
						/* translators: %s: date */
						'added_on'          => __( 'Added %s', 'riviantrackr-passkeys' ),
						/* translators: %s: date */
						'last_used'         => __( 'Last used %s', 'riviantrackr-passkeys' ),
						'never_used'        => __( 'Never used', 'riviantrackr-passkeys' ),
						'synced'            => __( 'Synced', 'riviantrackr-passkeys' ),
						'rename'            => __( 'Rename', 'riviantrackr-passkeys' ),
						'remove'            => __( 'Remove', 'riviantrackr-passkeys' ),
						'save'              => __( 'Save', 'riviantrackr-passkeys' ),
						'cancel'            => __( 'Cancel', 'riviantrackr-passkeys' ),
						'nameLabel'         => __( 'Passkey name', 'riviantrackr-passkeys' ),
					],
				]
			);
		}

		wp_enqueue_style( 'rtpk-manage' );
		wp_enqueue_script( 'rtpk-manage' );
	}

	public function enqueue_admin( string $hook ): void {
		if ( in_array( $hook, [ 'profile.php', 'user-edit.php' ], true ) ) {
			$this->enqueue();
		}
	}

	public function render_own_profile( \WP_User $user ): void {
		echo '<h2>' . esc_html__( 'Passkeys', 'riviantrackr-passkeys' ) . '</h2>';
		echo $this->markup( $user, true, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in markup().
	}

	public function render_other_profile( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Passkeys', 'riviantrackr-passkeys' ) . '</h2>';
		echo $this->markup( $user, false, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in markup().
	}

	/**
	 * [riviantrackr_passkeys theme="dark|light"]
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts = [] ): string {
		$atts = shortcode_atts( [ 'theme' => 'dark' ], is_array( $atts ) ? $atts : [], 'riviantrackr_passkeys' );

		if ( ! is_user_logged_in() ) {
			return '<div class="rtpk-manager' . ( 'light' === $atts['theme'] ? ' is-light' : '' ) . '"><p class="rtpk-empty">'
				. sprintf(
					/* translators: %s: login URL */
					wp_kses_post( __( '<a href="%s">Sign in</a> to manage your passkeys.', 'riviantrackr-passkeys' ) ),
					esc_url( wp_login_url( get_permalink() ?: home_url() ) )
				)
				. '</p></div>';
		}

		$this->enqueue();
		return $this->markup( wp_get_current_user(), true, 'light' === $atts['theme'] );
	}

	/**
	 * Render the manager.
	 *
	 * @param \WP_User            $user     Whose passkeys.
	 * @param bool                $is_owner Whether the viewer owns them (can add/rename).
	 * @param bool                $light    Light (admin) theme instead of dark.
	 * @param array<string,mixed> $args     Optional: title, desc, button, setup (bool), continue_url.
	 */
	public function markup( \WP_User $user, bool $is_owner, bool $light, array $args = [] ): string {
		$this->enqueue();

		$credentials = array_map( [ $this->rest, 'present' ], $this->store->find_for_user( $user->ID ) );
		$is_setup    = ! empty( $args['setup'] );
		$classes     = 'rtpk-manager' . ( $light ? ' is-light' : '' ) . ( $is_owner ? ' is-owner' : '' ) . ( $is_setup ? ' is-setup' : '' );
		$title       = (string) ( $args['title'] ?? __( 'Your passkeys', 'riviantrackr-passkeys' ) );
		$button      = (string) ( $args['button'] ?? __( 'Add a passkey', 'riviantrackr-passkeys' ) );
		$desc        = $args['desc'] ?? null;

		ob_start();
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" data-rtpk-manager data-user-id="<?php echo (int) $user->ID; ?>" data-owner="<?php echo $is_owner ? '1' : '0'; ?>"<?php echo $is_setup ? ' data-rtpk-setup' : ''; ?>>
			<div class="rtpk-manager__header">
				<div class="rtpk-manager__intro">
					<h3 class="rtpk-manager__title"><?php echo esc_html( $title ); ?></h3>
					<p class="rtpk-manager__desc">
						<?php
						if ( null !== $desc ) {
							echo esc_html( (string) $desc );
						} elseif ( $is_owner ) {
							esc_html_e( 'Sign in with Face ID, Touch ID, Windows Hello or a security key instead of a password. Passkeys are phishing-resistant and never leave your device.', 'riviantrackr-passkeys' );
						} else {
							esc_html_e( 'Passkeys registered to this account. You can remove them but only the account owner can add new ones.', 'riviantrackr-passkeys' );
						}
						?>
					</p>
				</div>
				<?php if ( $is_owner ) : ?>
					<button type="button" class="rtpk-btn rtpk-btn--primary" data-rtpk-add>
						<?php echo LoginForm::icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
						<span><?php echo esc_html( $button ); ?></span>
					</button>
				<?php endif; ?>
			</div>

			<p class="rtpk-manager__msg" data-rtpk-msg role="status" aria-live="polite" hidden></p>

			<ul class="rtpk-list" data-rtpk-list>
				<?php foreach ( $credentials as $credential ) : ?>
					<?php echo $this->item_markup( $credential, $is_owner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in item_markup(). ?>
				<?php endforeach; ?>
			</ul>

			<p class="rtpk-empty" data-rtpk-empty <?php echo empty( $credentials ) ? '' : 'hidden'; ?>>
				<?php esc_html_e( 'No passkeys yet.', 'riviantrackr-passkeys' ); ?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Server-side rendering of one list item. Must stay in sync with
	 * renderItem() in assets/passkeys-manage.js.
	 *
	 * @param array<string,mixed> $c        Presented credential.
	 */
	private function item_markup( array $c, bool $is_owner ): string {
		$meta = [];
		if ( ! empty( $c['createdAt'] ) ) {
			/* translators: %s: date */
			$meta[] = sprintf( __( 'Added %s', 'riviantrackr-passkeys' ), $c['createdAt'] );
		}
		if ( ! empty( $c['lastUsedAt'] ) ) {
			/* translators: %s: date */
			$meta[] = sprintf( __( 'Last used %s', 'riviantrackr-passkeys' ), $c['lastUsedAt'] );
		} else {
			$meta[] = __( 'Never used', 'riviantrackr-passkeys' );
		}

		$html  = '<li class="rtpk-item" data-rtpk-item data-id="' . (int) $c['id'] . '">';
		$html .= '<div class="rtpk-item__icon">' . LoginForm::icon() . '</div>';
		$html .= '<div class="rtpk-item__body">';
		$html .= '<div class="rtpk-item__name-row">';
		$html .= '<span class="rtpk-item__name" data-rtpk-name>' . esc_html( (string) $c['name'] ) . '</span>';
		if ( ! empty( $c['provider'] ) && $c['provider'] !== $c['name'] ) {
			$html .= '<span class="rtpk-badge">' . esc_html( (string) $c['provider'] ) . '</span>';
		}
		if ( ! empty( $c['backedUp'] ) ) {
			$html .= '<span class="rtpk-badge rtpk-badge--muted">' . esc_html__( 'Synced', 'riviantrackr-passkeys' ) . '</span>';
		}
		$html .= '</div>';
		$html .= '<div class="rtpk-item__meta" data-rtpk-meta>' . esc_html( implode( ' · ', $meta ) ) . '</div>';
		$html .= '</div>';
		$html .= '<div class="rtpk-item__actions">';
		if ( $is_owner ) {
			$html .= '<button type="button" class="rtpk-btn rtpk-btn--ghost" data-rtpk-rename>' . esc_html__( 'Rename', 'riviantrackr-passkeys' ) . '</button>';
		}
		$html .= '<button type="button" class="rtpk-btn rtpk-btn--danger" data-rtpk-delete>' . esc_html__( 'Remove', 'riviantrackr-passkeys' ) . '</button>';
		$html .= '</div>';
		$html .= '</li>';

		return $html;
	}
}
