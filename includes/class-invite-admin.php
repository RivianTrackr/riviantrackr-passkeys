<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Users → Passkey invitations screen, plus per-user actions in the Users list.
 */
final class InviteAdmin {

	public const PAGE_SLUG  = 'riviantrackr-passkey-invites';
	public const CAPABILITY = 'edit_users';

	private const BATCH_SIZE  = 20;
	private const JOB_PREFIX  = 'riviantrackr_pk_job_';
	private const JOB_TTL     = HOUR_IN_SECONDS;
	private const AJAX_NONCE  = 'rtpk_invites';
	private const BULK_ACTION = 'rtpk_invite';

	public function __construct(
		private InviteService $invites,
		private CredentialStore $store
	) {}

	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_notices', [ $this, 'notices' ] );
		add_action( 'update_option_' . InviteService::OPTION, [ $this->invites, 'flush' ] );

		add_action( 'wp_ajax_rtpk_invite_count', [ $this, 'ajax_count' ] );
		add_action( 'wp_ajax_rtpk_invite_send', [ $this, 'ajax_send' ] );
		add_action( 'wp_ajax_rtpk_invite_test', [ $this, 'ajax_test' ] );
		add_action( 'wp_ajax_rtpk_invite_preview', [ $this, 'ajax_preview' ] );

		add_filter( 'manage_users_columns', [ $this, 'users_column' ] );
		add_filter( 'manage_users_custom_column', [ $this, 'users_column_value' ], 10, 3 );
		add_filter( 'user_row_actions', [ $this, 'row_action' ], 10, 2 );
		add_action( 'admin_post_rtpk_invite_user', [ $this, 'handle_row_action' ] );
		add_filter( 'bulk_actions-users', [ $this, 'bulk_action' ] );
		add_filter( 'handle_bulk_actions-users', [ $this, 'handle_bulk_action' ], 10, 3 );
	}

	/**
	 * Settings API registration for the email template options.
	 */
	public function register_settings(): void {
		register_setting(
			'riviantrackr_passkeys_invites',
			InviteService::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this->invites, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	public function add_menu(): void {
		add_users_page(
			__( 'Passkey invitations', 'riviantrackr-passkeys' ),
			__( 'Passkey invitations', 'riviantrackr-passkeys' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	public static function page_url(): string {
		return admin_url( 'users.php?page=' . self::PAGE_SLUG );
	}

	public function enqueue( string $hook ): void {
		if ( 'users_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		$suffix = RTPK_ASSET_SUFFIX;
		wp_enqueue_media();
		wp_enqueue_style( 'rtpk-admin', RTPK_URL . "assets/passkeys-admin{$suffix}.css", [], Helpers::asset_version( "assets/passkeys-admin{$suffix}.css" ) );
		wp_enqueue_script( 'rtpk-invites', RTPK_URL . "assets/passkeys-invites{$suffix}.js", [], Helpers::asset_version( "assets/passkeys-invites{$suffix}.js" ), true );
		wp_localize_script(
			'rtpk-invites',
			'rtpkInvites',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::AJAX_NONCE ),
				'i18n'    => [
					'counting'     => __( 'Counting…', 'riviantrackr-passkeys' ),
					/* translators: %d: number of users */
					'willEmail'    => __( 'This will email %d member(s).', 'riviantrackr-passkeys' ),
					'nobody'       => __( 'No members match these filters.', 'riviantrackr-passkeys' ),
					/* translators: %d: number of users */
					'confirm'      => __( 'Send passkey invitations to %d member(s) now?', 'riviantrackr-passkeys' ),
					'sending'      => __( 'Sending…', 'riviantrackr-passkeys' ),
					/* translators: 1: sent so far, 2: total */
					'progress'     => __( 'Sent %1$d of %2$d', 'riviantrackr-passkeys' ),
					/* translators: 1: sent, 2: failed */
					'done'         => __( 'Finished: %1$d sent, %2$d failed.', 'riviantrackr-passkeys' ),
					'testSent'     => __( 'Test email sent to you. Check your inbox.', 'riviantrackr-passkeys' ),
					'genericError' => __( 'Something went wrong. Please try again.', 'riviantrackr-passkeys' ),
					'chooseLogo'   => __( 'Choose a logo', 'riviantrackr-passkeys' ),
					'useLogo'      => __( 'Use this logo', 'riviantrackr-passkeys' ),
					'previewing'   => __( 'Building preview…', 'riviantrackr-passkeys' ),
				],
			]
		);
	}

	/**
	 * Users → Passkey invitations screen.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$o     = $this->invites->options();
		$stats = $this->invites->stats();
		$roles = get_editable_roles();
		$name  = InviteService::OPTION;
		?>
		<div class="wrap rtpk-settings-wrap rtpk-invites" data-rtpk-invites>
			<div class="rtpk-header">
				<h1><?php esc_html_e( 'Passkey invitations', 'riviantrackr-passkeys' ); ?></h1>
				<p><?php esc_html_e( 'Email members who have not set up a passkey yet. Each gets a personal link that takes them straight to a one-tap setup page.', 'riviantrackr-passkeys' ); ?></p>
			</div>

			<?php settings_errors( InviteService::OPTION ); ?>

			<div class="rtpk-stats">
				<div class="rtpk-stat"><span class="rtpk-stat__value"><?php echo (int) $stats['total']; ?></span><span class="rtpk-stat__label"><?php esc_html_e( 'Members', 'riviantrackr-passkeys' ); ?></span></div>
				<div class="rtpk-stat"><span class="rtpk-stat__value"><?php echo (int) $stats['with']; ?></span><span class="rtpk-stat__label"><?php esc_html_e( 'Have a passkey', 'riviantrackr-passkeys' ); ?></span></div>
				<div class="rtpk-stat"><span class="rtpk-stat__value"><?php echo (int) $stats['without']; ?></span><span class="rtpk-stat__label"><?php esc_html_e( 'Without a passkey', 'riviantrackr-passkeys' ); ?></span></div>
				<div class="rtpk-stat"><span class="rtpk-stat__value"><?php echo (int) $stats['invited']; ?></span><span class="rtpk-stat__label"><?php esc_html_e( 'Invited, not yet set up', 'riviantrackr-passkeys' ); ?></span></div>
				<div class="rtpk-stat"><span class="rtpk-stat__value"><?php echo (int) $stats['converted']; ?></span><span class="rtpk-stat__label"><?php esc_html_e( 'Set up after invite', 'riviantrackr-passkeys' ); ?></span></div>
			</div>

			<form method="post" action="options.php" data-rtpk-template-form>
				<?php settings_fields( 'riviantrackr_passkeys_invites' ); ?>
				<div class="rtpk-section">
					<div class="rtpk-section-header">
						<h2><?php esc_html_e( 'Email', 'riviantrackr-passkeys' ); ?></h2>
						<p><?php esc_html_e( 'Write the message in plain text; the plugin adds your logo, the setup button and the fallback link.', 'riviantrackr-passkeys' ); ?></p>
					</div>
					<div class="rtpk-section-body">
						<div class="rtpk-field-row">
							<label class="rtpk-field-label" for="rtpk-invite-subject"><?php esc_html_e( 'Subject', 'riviantrackr-passkeys' ); ?></label>
							<input type="text" id="rtpk-invite-subject" class="rtpk-input rtpk-input--wide" name="<?php echo esc_attr( $name ); ?>[subject]" value="<?php echo esc_attr( (string) $o['subject'] ); ?>" data-rtpk-subject>
						</div>
						<div class="rtpk-field-row">
							<label class="rtpk-field-label" for="rtpk-invite-body"><?php esc_html_e( 'Message', 'riviantrackr-passkeys' ); ?></label>
							<textarea id="rtpk-invite-body" class="rtpk-input rtpk-input--wide rtpk-textarea rtpk-textarea--tall" name="<?php echo esc_attr( $name ); ?>[body]" rows="9" data-rtpk-body><?php echo esc_textarea( (string) $o['body'] ); ?></textarea>
							<p class="rtpk-field-description">
								<?php esc_html_e( 'Blank lines start a new paragraph; lines starting with * become bullets. Placeholders:', 'riviantrackr-passkeys' ); ?>
								<code>{name}</code> <code>{site}</code> <code>{link}</code> <code>{expires}</code> <code>{login_url}</code>
							</p>
						</div>
						<div class="rtpk-field-row">
							<div class="rtpk-field-grid">
								<div>
									<label class="rtpk-field-label" for="rtpk-invite-from-name"><?php esc_html_e( 'From name', 'riviantrackr-passkeys' ); ?></label>
									<input type="text" id="rtpk-invite-from-name" class="rtpk-input" name="<?php echo esc_attr( $name ); ?>[from_name]" value="<?php echo esc_attr( (string) $o['from_name'] ); ?>" placeholder="<?php echo esc_attr( (string) get_bloginfo( 'name' ) ); ?>">
								</div>
								<div>
									<label class="rtpk-field-label" for="rtpk-invite-from-email"><?php esc_html_e( 'From email', 'riviantrackr-passkeys' ); ?></label>
									<input type="email" id="rtpk-invite-from-email" class="rtpk-input" name="<?php echo esc_attr( $name ); ?>[from_email]" value="<?php echo esc_attr( (string) $o['from_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">
								</div>
							</div>
							<p class="rtpk-field-description"><?php esc_html_e( 'Leave blank to use the WordPress default. If your SMTP plugin is set to force a from address, its setting wins.', 'riviantrackr-passkeys' ); ?></p>
						</div>
						<div class="rtpk-field-row">
							<span class="rtpk-field-label"><?php esc_html_e( 'Logo', 'riviantrackr-passkeys' ); ?></span>
							<?php
							$logo_id  = absint( $o['logo_id'] );
							$logo_url = $this->invites->logo_url();
							?>
							<div class="rtpk-logo" data-rtpk-logo>
								<input type="hidden" name="<?php echo esc_attr( $name ); ?>[logo_id]" value="<?php echo (int) $logo_id; ?>" data-rtpk-logo-id>
								<div class="rtpk-logo__preview <?php echo 'light' === $o['theme'] ? 'is-light' : ''; ?>" data-rtpk-logo-preview>
									<img src="<?php echo esc_url( $logo_url ); ?>" alt="" data-rtpk-logo-img <?php echo '' === $logo_url ? 'hidden' : ''; ?>>
									<span class="rtpk-logo__empty" data-rtpk-logo-empty <?php echo '' !== $logo_url ? 'hidden' : ''; ?>><?php esc_html_e( 'No logo — the site name is shown instead', 'riviantrackr-passkeys' ); ?></span>
								</div>
								<div class="rtpk-logo__actions">
									<button type="button" class="button rtpk-btn rtpk-btn--secondary" data-rtpk-logo-choose><?php esc_html_e( 'Choose image', 'riviantrackr-passkeys' ); ?></button>
									<button type="button" class="button rtpk-btn rtpk-btn--ghost" data-rtpk-logo-remove <?php echo $logo_id > 0 ? '' : 'hidden'; ?>><?php esc_html_e( 'Use default', 'riviantrackr-passkeys' ); ?></button>
								</div>
							</div>
							<p class="rtpk-field-description"><?php esc_html_e( 'Defaults to your theme’s custom logo, or the site icon. A wide, transparent PNG or SVG around 48px tall looks best.', 'riviantrackr-passkeys' ); ?></p>
						</div>
						<div class="rtpk-field-row">
							<div class="rtpk-field-grid">
								<div>
									<label class="rtpk-field-label" for="rtpk-invite-theme"><?php esc_html_e( 'Colours', 'riviantrackr-passkeys' ); ?></label>
									<select id="rtpk-invite-theme" class="rtpk-input rtpk-select" name="<?php echo esc_attr( $name ); ?>[theme]" data-rtpk-theme>
										<option value="dark" <?php selected( $o['theme'], 'dark' ); ?>><?php esc_html_e( 'Dark — matches the site (ink & brass)', 'riviantrackr-passkeys' ); ?></option>
										<option value="light" <?php selected( $o['theme'], 'light' ); ?>><?php esc_html_e( 'Light — white card, gold button', 'riviantrackr-passkeys' ); ?></option>
									</select>
								</div>
								<div>
									<label class="rtpk-field-label" for="rtpk-invite-expiry"><?php esc_html_e( 'Link expires after', 'riviantrackr-passkeys' ); ?></label>
									<div class="rtpk-input-suffix">
										<input type="number" id="rtpk-invite-expiry" class="rtpk-input rtpk-input--short" min="1" max="30" name="<?php echo esc_attr( $name ); ?>[expiry_days]" value="<?php echo (int) $o['expiry_days']; ?>">
										<span><?php esc_html_e( 'days', 'riviantrackr-passkeys' ); ?></span>
									</div>
								</div>
							</div>
						</div>
						<div class="rtpk-field-row rtpk-field-row--toggle">
							<div>
								<span class="rtpk-field-label"><?php esc_html_e( 'Link signs the member in', 'riviantrackr-passkeys' ); ?></span>
								<p class="rtpk-field-description"><?php esc_html_e( 'The personal link signs the member in and opens passkey setup directly, like a password-reset email. Single use, expires as above. Turn off to require a password sign-in first.', 'riviantrackr-passkeys' ); ?></p>
							</div>
							<label class="rtpk-toggle">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[magic_link]" value="1" <?php checked( ! empty( $o['magic_link'] ) ); ?>>
								<span class="rtpk-toggle__track"><span class="rtpk-toggle__thumb"></span></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Link signs the member in', 'riviantrackr-passkeys' ); ?></span>
							</label>
						</div>
						<div class="rtpk-field-row rtpk-field-row--actions">
							<?php submit_button( __( 'Save email', 'riviantrackr-passkeys' ), 'rtpk-btn rtpk-btn--primary', 'submit', false ); ?>
							<button type="button" class="button rtpk-btn rtpk-btn--secondary" data-rtpk-preview><?php esc_html_e( 'Preview', 'riviantrackr-passkeys' ); ?></button>
							<button type="button" class="button rtpk-btn rtpk-btn--secondary" data-rtpk-test><?php esc_html_e( 'Send me a test', 'riviantrackr-passkeys' ); ?></button>
							<span class="rtpk-inline-msg" data-rtpk-test-msg role="status" aria-live="polite"></span>
						</div>
						<div class="rtpk-field-row rtpk-preview" data-rtpk-preview-panel hidden>
							<iframe class="rtpk-preview__frame" title="<?php esc_attr_e( 'Email preview', 'riviantrackr-passkeys' ); ?>" sandbox="" data-rtpk-preview-frame></iframe>
						</div>
					</div>
				</div>
			</form>

			<div class="rtpk-section">
				<div class="rtpk-section-header">
					<h2><?php esc_html_e( 'Who to invite', 'riviantrackr-passkeys' ); ?></h2>
					<p><?php esc_html_e( 'Only members without a passkey are ever emailed.', 'riviantrackr-passkeys' ); ?></p>
				</div>
				<div class="rtpk-section-body">
					<div class="rtpk-field-row">
						<span class="rtpk-field-label"><?php esc_html_e( 'Roles', 'riviantrackr-passkeys' ); ?></span>
						<div class="rtpk-checks" data-rtpk-roles>
							<?php foreach ( $roles as $slug => $role ) : ?>
								<label class="rtpk-check">
									<input type="checkbox" name="roles[]" value="<?php echo esc_attr( (string) $slug ); ?>" checked>
									<span><?php echo esc_html( translate_user_role( (string) $role['name'] ) ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="rtpk-field-row">
						<span class="rtpk-field-label"><?php esc_html_e( 'Previous invitations', 'riviantrackr-passkeys' ); ?></span>
						<div class="rtpk-radios" data-rtpk-mode>
							<label class="rtpk-check"><input type="radio" name="mode" value="never" checked> <span><?php esc_html_e( 'Only members never invited', 'riviantrackr-passkeys' ); ?></span></label>
							<label class="rtpk-check"><input type="radio" name="mode" value="stale"> <span><?php esc_html_e( 'Also re-invite members last invited more than', 'riviantrackr-passkeys' ); ?></span>
								<input type="number" class="rtpk-input rtpk-input--tiny" name="stale_days" value="14" min="1" max="365" data-rtpk-stale> <span><?php esc_html_e( 'days ago', 'riviantrackr-passkeys' ); ?></span></label>
							<label class="rtpk-check"><input type="radio" name="mode" value="all"> <span><?php esc_html_e( 'Everyone without a passkey, even if invited recently', 'riviantrackr-passkeys' ); ?></span></label>
						</div>
					</div>
					<div class="rtpk-field-row rtpk-field-row--actions">
						<button type="button" class="button rtpk-btn rtpk-btn--primary" data-rtpk-send disabled><?php esc_html_e( 'Send invitations', 'riviantrackr-passkeys' ); ?></button>
						<span class="rtpk-inline-msg" data-rtpk-count role="status" aria-live="polite"><?php esc_html_e( 'Counting…', 'riviantrackr-passkeys' ); ?></span>
					</div>
					<div class="rtpk-field-row rtpk-progress-row" data-rtpk-progress-row hidden>
						<div class="rtpk-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-rtpk-progress><span class="rtpk-progress__bar" data-rtpk-progress-bar></span></div>
						<p class="rtpk-field-description" data-rtpk-progress-text></p>
						<ul class="rtpk-log" data-rtpk-log></ul>
					</div>
				</div>
			</div>

			<p class="rtpk-field-description">
				<?php
				printf(
					/* translators: %s: link to the Users list */
					esc_html__( 'To invite one person, use the “Send passkey invite” action on the %s list, or select several and choose the bulk action.', 'riviantrackr-passkeys' ),
					'<a href="' . esc_url( admin_url( 'users.php' ) ) . '">' . esc_html__( 'Users', 'riviantrackr-passkeys' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * @return array{roles:string[],mode:string,stale_days:int}
	 */
	private function read_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by check_ajax_referer() in the caller.
		$roles = isset( $_POST['roles'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['roles'] ) ) : [];
		$mode  = isset( $_POST['mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['mode'] ) ) : InviteService::MODE_NEVER;
		$stale = isset( $_POST['stale_days'] ) ? (int) $_POST['stale_days'] : 14;
		// phpcs:enable
		if ( ! in_array( $mode, [ InviteService::MODE_NEVER, InviteService::MODE_STALE, InviteService::MODE_ALL ], true ) ) {
			$mode = InviteService::MODE_NEVER;
		}
		return [
			'roles'      => array_values( array_filter( $roles ) ),
			'mode'       => $mode,
			'stale_days' => max( 1, min( 365, $stale ) ),
		];
	}

	private function guard(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do that.', 'riviantrackr-passkeys' ) ], 403 );
		}
	}

	public function ajax_count(): void {
		$this->guard();
		$f   = $this->read_filters();
		$ids = empty( $f['roles'] ) ? [] : $this->invites->eligible_user_ids( $f['roles'], $f['mode'], $f['stale_days'] );

		$sample = [];
		foreach ( array_slice( $ids, 0, 5 ) as $id ) {
			$user = get_user_by( 'id', $id );
			if ( $user ) {
				$sample[] = $user->display_name;
			}
		}
		wp_send_json_success(
			[
				'count'  => count( $ids ),
				'sample' => $sample,
			]
		);
	}

	/**
	 * Unsaved draft values posted alongside test/preview requests.
	 *
	 * @return array<string,mixed>
	 */
	private function read_draft(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$draft = [
			'subject' => sanitize_text_field( (string) wp_unslash( $_POST['subject'] ?? '' ) ),
			'body'    => trim( wp_strip_all_tags( (string) wp_unslash( $_POST['body'] ?? '' ) ) ),
			'theme'   => sanitize_key( (string) wp_unslash( $_POST['theme'] ?? '' ) ),
		];
		if ( isset( $_POST['logo_id'] ) ) {
			$draft['logo_id'] = absint( wp_unslash( $_POST['logo_id'] ) );
		}
		// phpcs:enable
		foreach ( [ 'subject', 'body', 'theme' ] as $key ) {
			if ( '' === $draft[ $key ] ) {
				unset( $draft[ $key ] );
			}
		}
		return $draft;
	}

	public function ajax_preview(): void {
		$this->guard();
		$user  = wp_get_current_user();
		$email = $this->invites->render_email( $user, $this->invites->setup_url(), $this->read_draft() );
		wp_send_json_success(
			[
				'subject' => $email['subject'],
				'html'    => $email['html'],
			]
		);
	}

	public function ajax_test(): void {
		$this->guard();
		$user     = wp_get_current_user();
		$template = $this->read_draft();

		$result = $this->invites->send( $user, $template ?: null, false );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success();
	}

	/**
	 * Batched send. First call (no job) builds the recipient list; each
	 * following call sends BATCH_SIZE emails and reports progress.
	 */
	public function ajax_send(): void {
		$this->guard();

		$job_id = isset( $_POST['job'] ) ? sanitize_key( (string) wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $job_id ) {
			$f   = $this->read_filters();
			$ids = empty( $f['roles'] ) ? [] : $this->invites->eligible_user_ids( $f['roles'], $f['mode'], $f['stale_days'] );
			if ( empty( $ids ) ) {
				wp_send_json_error( [ 'message' => __( 'No members match these filters.', 'riviantrackr-passkeys' ) ] );
			}
			$job_id = self::new_job_id();
			$job    = [
				'ids'    => $ids,
				'total'  => count( $ids ),
				'sent'   => 0,
				'failed' => 0,
				'log'    => [],
			];
			set_transient( self::JOB_PREFIX . $job_id, $job, self::JOB_TTL );
			wp_send_json_success( $this->job_payload( $job_id, $job ) );
		}

		$job = get_transient( self::JOB_PREFIX . $job_id );
		if ( ! is_array( $job ) ) {
			wp_send_json_error( [ 'message' => __( 'This send job has expired. Start again.', 'riviantrackr-passkeys' ) ] );
		}

		$batch = array_splice( $job['ids'], 0, self::BATCH_SIZE );
		$log   = [];
		foreach ( $batch as $id ) {
			$user = get_user_by( 'id', (int) $id );
			if ( ! $user ) {
				continue;
			}
			if ( $this->invites->has_passkey( $user->ID ) ) {
				continue; // Registered since the list was built.
			}
			$result = $this->invites->send( $user );
			if ( is_wp_error( $result ) ) {
				++$job['failed'];
				$log[] = [
					'ok'   => false,
					'name' => $user->display_name,
					'note' => $result->get_error_message(),
				];
			} else {
				++$job['sent'];
				$log[] = [
					'ok'   => true,
					'name' => $user->display_name,
					'note' => $user->user_email,
				];
			}
		}

		$done = empty( $job['ids'] );
		if ( $done ) {
			delete_transient( self::JOB_PREFIX . $job_id );
		} else {
			set_transient( self::JOB_PREFIX . $job_id, $job, self::JOB_TTL );
		}

		$payload        = $this->job_payload( $job_id, $job );
		$payload['log'] = $log;
		wp_send_json_success( $payload );
	}

	/**
	 * Job identifiers round-trip through sanitize_key() on every request,
	 * which lowercases, so they must be lowercase to begin with.
	 */
	public static function new_job_id(): string {
		return bin2hex( random_bytes( 8 ) );
	}

	/**
	 * @param string              $job_id Job identifier.
	 * @param array<string,mixed> $job    Job state.
	 * @return array<string,mixed>
	 */
	private function job_payload( string $job_id, array $job ): array {
		return [
			'job'    => $job_id,
			'total'  => (int) $job['total'],
			'sent'   => (int) $job['sent'],
			'failed' => (int) $job['failed'],
			'done'   => empty( $job['ids'] ),
		];
	}

	/**
	 * @param array<string,string> $columns Users list columns.
	 * @return array<string,string>
	 */
	public function users_column( $columns ): array {
		$columns                  = (array) $columns;
		$columns['rtpk_passkeys'] = __( 'Passkeys', 'riviantrackr-passkeys' );
		return $columns;
	}

	/**
	 * @param string $output      Existing output.
	 * @param string $column_name Column key.
	 * @param int    $user_id     User.
	 */
	public function users_column_value( $output, $column_name, $user_id ): string {
		if ( 'rtpk_passkeys' !== $column_name ) {
			return (string) $output;
		}
		$count = $this->store->count_for_user( (int) $user_id );
		if ( $count > 0 ) {
			return '<span class="rtpk-col-count">' . (int) $count . '</span>';
		}
		$invited = $this->invites->invited_at( (int) $user_id );
		if ( $invited > 0 ) {
			return '<span class="rtpk-col-invited">'
				/* translators: %s: human-readable time difference */
				. esc_html( sprintf( __( 'Invited %s ago', 'riviantrackr-passkeys' ), human_time_diff( $invited ) ) )
				. '</span>';
		}
		return '<span class="rtpk-col-none">' . esc_html__( 'None', 'riviantrackr-passkeys' ) . '</span>';
	}

	/**
	 * @param string[] $actions Existing row actions.
	 * @param \WP_User $user    The row's user.
	 * @return string[]
	 */
	public function row_action( $actions, \WP_User $user ): array {
		$actions = (array) $actions;
		if ( ! current_user_can( self::CAPABILITY ) || $this->invites->has_passkey( $user->ID ) ) {
			return $actions;
		}
		$url                    = wp_nonce_url(
			add_query_arg(
				[
					'action' => 'rtpk_invite_user',
					'user'   => $user->ID,
				],
				admin_url( 'admin-post.php' )
			),
			'rtpk_invite_user_' . $user->ID
		);
		$actions['rtpk_invite'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Send passkey invite', 'riviantrackr-passkeys' ) . '</a>';
		return $actions;
	}

	public function handle_row_action(): void {
		$user_id = isset( $_GET['user'] ) ? absint( wp_unslash( $_GET['user'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below.
		check_admin_referer( 'rtpk_invite_user_' . $user_id );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'riviantrackr-passkeys' ) );
		}

		$user = get_user_by( 'id', $user_id );
		$back = wp_get_referer() ?: admin_url( 'users.php' );

		if ( ! $user ) {
			wp_safe_redirect( add_query_arg( 'rtpk_invite_error', rawurlencode( __( 'User not found.', 'riviantrackr-passkeys' ) ), $back ) );
			exit;
		}

		$result = $this->invites->send( $user );
		$back   = remove_query_arg( [ 'rtpk_invited', 'rtpk_skipped', 'rtpk_invite_error' ], $back );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'rtpk_invite_error', rawurlencode( $result->get_error_message() ), $back ) );
		} else {
			wp_safe_redirect( add_query_arg( 'rtpk_invited', 1, $back ) );
		}
		exit;
	}

	/**
	 * @param array<string,string> $actions Existing bulk actions.
	 * @return array<string,string>
	 */
	public function bulk_action( $actions ): array {
		$actions = (array) $actions;
		if ( current_user_can( self::CAPABILITY ) ) {
			$actions[ self::BULK_ACTION ] = __( 'Send passkey invitation', 'riviantrackr-passkeys' );
		}
		return $actions;
	}

	/**
	 * @param string $redirect Redirect URL.
	 * @param string $action   Bulk action key.
	 * @param int[]  $user_ids Selected users.
	 */
	public function handle_bulk_action( $redirect, $action, $user_ids ): string {
		if ( self::BULK_ACTION !== $action || ! current_user_can( self::CAPABILITY ) ) {
			return (string) $redirect;
		}
		$sent    = 0;
		$skipped = 0;
		foreach ( (array) $user_ids as $id ) {
			$user = get_user_by( 'id', (int) $id );
			if ( ! $user || $this->invites->has_passkey( $user->ID ) ) {
				++$skipped;
				continue;
			}
			if ( is_wp_error( $this->invites->send( $user ) ) ) {
				++$skipped;
			} else {
				++$sent;
			}
		}
		$redirect = remove_query_arg( [ 'rtpk_invited', 'rtpk_skipped', 'rtpk_invite_error' ], (string) $redirect );
		return add_query_arg(
			[
				'rtpk_invited' => $sent,
				'rtpk_skipped' => $skipped,
			],
			$redirect
		);
	}

	public function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only flags set by our own redirects.
		if ( isset( $_GET['rtpk_invite_error'] ) ) {
			$message = sanitize_text_field( wp_unslash( (string) $_GET['rtpk_invite_error'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			return;
		}
		if ( isset( $_GET['rtpk_invited'] ) ) {
			$sent    = absint( wp_unslash( $_GET['rtpk_invited'] ) );
			$skipped = isset( $_GET['rtpk_skipped'] ) ? absint( wp_unslash( $_GET['rtpk_skipped'] ) ) : 0;
			// phpcs:enable
			/* translators: %d: number of invitations */
			$text = sprintf( _n( '%d passkey invitation sent.', '%d passkey invitations sent.', $sent, 'riviantrackr-passkeys' ), $sent );
			if ( $skipped > 0 ) {
				/* translators: %d: number of users skipped */
				$text .= ' ' . sprintf( _n( '%d user skipped (already has a passkey or no email).', '%d users skipped (already have a passkey or no email).', $skipped, 'riviantrackr-passkeys' ), $skipped );
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
		}
	}
}
