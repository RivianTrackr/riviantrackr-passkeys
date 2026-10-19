<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [riviantrackr_account]: a tabbed "My Account" page for members.
 *
 * Tabs: Overview, Profile (photo, names, email, bio), Garage (the member's
 * Rivians and their wheels), Security (passkeys, password, sessions),
 * Reviews and Saved tires (when the Rivian Tire Guide is active), Comments.
 * Other plugins add or reorder tabs through the
 * `riviantrackr_passkeys_account_tabs` filter.
 */
final class AccountPage {

	public const SHORTCODE = 'riviantrackr_account';
	public const FILTER    = 'riviantrackr_passkeys_account_tabs';

	private bool $assets_registered = false;

	public function __construct(
		private Settings $settings,
		private CredentialStore $store,
		private ProfileManager $manager
	) {}

	/** Query arg carrying the outcome of an email-change confirmation. */
	public const EMAIL_QUERY = 'rtpk_email';

	public function register_hooks(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'shortcode' ] );
		add_action( 'template_redirect', [ $this, 'maybe_confirm_email' ] );
	}

	/**
	 * Completes an email change from the emailed link when it lands on the
	 * account page (?newuseremail=hash), the way profile.php does it in
	 * wp-admin. Signed-out visitors are sent to sign in and brought back.
	 */
	public function maybe_confirm_email(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The hash in the link is the credential.
		if ( ! isset( $_GET['newuseremail'] ) || ! is_singular() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( $id <= 0 || $id !== (int) $this->settings->get( 'account_page_id' ) ) {
			return;
		}
		$hash = sanitize_text_field( wp_unslash( (string) $_GET['newuseremail'] ) );
		// phpcs:enable
		$page = $this->settings->account_page_url();

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( add_query_arg( 'newuseremail', rawurlencode( $hash ), $page ) ) );
			exit;
		}

		$changed = AccountService::confirm_email_change( get_current_user_id(), $hash );
		wp_safe_redirect( add_query_arg( self::EMAIL_QUERY, '' !== $changed ? 'changed' : 'invalid', $page ) . '#profile' );
		exit;
	}

	/**
	 * Notice for ?rtpk_email on the Profile tab. Pure.
	 *
	 * @return array{type:string,text:string}|null
	 */
	public static function email_notice( string $code ): ?array {
		switch ( $code ) {
			case 'changed':
				return [
					'type' => 'success',
					'text' => __( 'Your email address has been updated.', 'riviantrackr-passkeys' ),
				];
			case 'invalid':
				return [
					'type' => 'error',
					'text' => __( 'That confirmation link is not valid. If you still want to change your email, save the new address again to get a fresh link.', 'riviantrackr-passkeys' ),
				];
		}
		return null;
	}

	// ---- tabs ----------------------------------------------------------

	/**
	 * IDs of the built-in tabs, in order. Pure.
	 *
	 * @param bool $reviews   Whether tire reviews are available (tire guide active with a ratings source).
	 * @param bool $favorites Whether saved tires are available (the tab keeps the `favorites` id).
	 * @return string[]
	 */
	public static function default_tab_ids( bool $reviews, bool $favorites ): array {
		$ids = [ 'overview', 'profile', 'garage', 'security' ];
		if ( $reviews ) {
			$ids[] = 'reviews';
		}
		if ( $favorites ) {
			$ids[] = 'favorites';
		}
		$ids[] = 'comments';
		$ids[] = 'data';
		return $ids;
	}

	/**
	 * Validate a tab list (ours plus whatever the filter returned): every
	 * entry needs a slug id, a label and a callable render; ids are
	 * de-duplicated (first wins) and icons default to a dot. Pure.
	 *
	 * @param mixed $tabs Filter output.
	 * @return array<string,array{id:string,label:string,icon:string,render:callable}>
	 */
	public static function normalize_tabs( $tabs ): array {
		$out = [];
		foreach ( (array) $tabs as $tab ) {
			if ( ! is_array( $tab ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $tab['id'] ?? '' ) );
			if ( '' === $id || isset( $out[ $id ] ) ) {
				continue;
			}
			$label = trim( (string) ( $tab['label'] ?? '' ) );
			if ( '' === $label || ! isset( $tab['render'] ) || ! is_callable( $tab['render'] ) ) {
				continue;
			}
			$out[ $id ] = [
				'id'     => $id,
				'label'  => $label,
				'icon'   => (string) ( $tab['icon'] ?? '' ),
				'render' => $tab['render'],
			];
		}
		return $out;
	}

	/**
	 * The tabs to render for a member.
	 *
	 * @param bool|null $reviews   Override tire-review detection (tests).
	 * @param bool|null $favorites Override saved-tire detection (tests).
	 * @return array<string,array{id:string,label:string,icon:string,render:callable}>
	 */
	public function tabs( ?bool $reviews = null, ?bool $favorites = null ): array {
		$reviews   = $reviews ?? TireGuide::supports_reviews();
		$favorites = $favorites ?? TireGuide::supports_favorites();
		$labels    = [
			'overview'  => __( 'Overview', 'riviantrackr-passkeys' ),
			'profile'   => __( 'Profile', 'riviantrackr-passkeys' ),
			'garage'    => __( 'Garage', 'riviantrackr-passkeys' ),
			'security'  => __( 'Security', 'riviantrackr-passkeys' ),
			'reviews'   => __( 'Reviews', 'riviantrackr-passkeys' ),
			'favorites' => __( 'Saved tires', 'riviantrackr-passkeys' ),
			'comments'  => __( 'Comments', 'riviantrackr-passkeys' ),
			'data'      => __( 'Your data', 'riviantrackr-passkeys' ),
		];
		$icons     = [
			'overview'  => 'home',
			'profile'   => 'user',
			'garage'    => 'car',
			'security'  => 'shield',
			'reviews'   => 'star',
			'favorites' => 'heart',
			'comments'  => 'comment',
			'data'      => 'folder',
		];

		$tabs = [];
		foreach ( self::default_tab_ids( $reviews, $favorites ) as $id ) {
			$tabs[] = [
				'id'     => $id,
				'label'  => $labels[ $id ],
				'icon'   => self::icon( $icons[ $id ] ),
				'render' => [ $this, 'render_' . $id ],
			];
		}

		/**
		 * Filter the account page tabs.
		 *
		 * Each tab is an array with `id` (slug), `label`, optional `icon`
		 * (inline SVG markup) and `render`, a callable receiving the
		 * WP_User and a context array (`light`, `account_url`, `tabs`)
		 * and returning escaped HTML.
		 *
		 * @param array<int,array<string,mixed>> $tabs Tab definitions in display order.
		 * @param \WP_User|null                  $user The signed-in member.
		 */
		$tabs = apply_filters( 'riviantrackr_passkeys_account_tabs', $tabs, is_user_logged_in() ? wp_get_current_user() : null );

		return self::normalize_tabs( $tabs );
	}

	// ---- shortcode -------------------------------------------------------

	/**
	 * Register and enqueue the page's script and stylesheet once.
	 */
	private function enqueue( bool $light ): void {
		if ( ! $this->assets_registered ) {
			$this->assets_registered = true;
			$suffix                  = RTPK_ASSET_SUFFIX;
			wp_register_script( 'rtpk-core', RTPK_URL . "assets/passkeys-core{$suffix}.js", [], Helpers::asset_version( "assets/passkeys-core{$suffix}.js" ), true );
			wp_register_script( 'rtpk-account', RTPK_URL . "assets/passkeys-account{$suffix}.js", [ 'rtpk-core' ], Helpers::asset_version( "assets/passkeys-account{$suffix}.js" ), true );
			wp_register_style( 'rtpk-account', RTPK_URL . "assets/passkeys-account{$suffix}.css", [], Helpers::asset_version( "assets/passkeys-account{$suffix}.css" ) );
		}
		if ( ! wp_script_is( 'rtpk-account', 'enqueued' ) ) {
			wp_localize_script(
				'rtpk-account',
				'rtpkAccount',
				[
					'restUrl' => esc_url_raw( rest_url( RTPK_REST_NAMESPACE ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'light'   => $light,
					'avatar'  => [
						'maxBytes' => Avatars::max_bytes(),
						'types'    => array_keys( Avatars::TYPES ),
					],
					'garage'  => $this->garage_config(),
					'i18n'    => [
						'saving'               => __( 'Saving…', 'riviantrackr-passkeys' ),
						'working'              => __( 'Working…', 'riviantrackr-passkeys' ),
						'genericError'         => __( 'Something went wrong. Please try again.', 'riviantrackr-passkeys' ),
						'confirmDeleteReview'  => __( 'Delete this review? This cannot be undone.', 'riviantrackr-passkeys' ),
						'confirmRemoveFav'     => __( 'Remove this tire from your saved tires?', 'riviantrackr-passkeys' ),
						'confirmSignOutOthers' => __( 'Sign out of every other browser and device? This one stays signed in.', 'riviantrackr-passkeys' ),
						'confirmDeleteAccount' => __( 'Delete your account? This cannot be undone.', 'riviantrackr-passkeys' ),
						'uploading'            => __( 'Uploading…', 'riviantrackr-passkeys' ),
						'changePhoto'          => __( 'Change photo', 'riviantrackr-passkeys' ),
						'uploadPhoto'          => __( 'Upload a photo', 'riviantrackr-passkeys' ),
						'confirmRemovePhoto'   => __( 'Remove your photo and show your Gravatar instead?', 'riviantrackr-passkeys' ),
						'avatarTooLarge'       => __( 'That photo is too large.', 'riviantrackr-passkeys' ),
						'avatarType'           => __( 'Use a JPG, PNG, GIF or WebP image.', 'riviantrackr-passkeys' ),
						'noReviews'            => __( 'You have not reviewed any tires yet.', 'riviantrackr-passkeys' ),
						'noFavorites'          => __( 'No saved tires yet. Tap the heart on a tire in the guide to keep it here.', 'riviantrackr-passkeys' ),
						/* translators: %s: email address */
						'pendingEmail'         => __( 'A confirmation link was sent to %s. Your email changes once you click it.', 'riviantrackr-passkeys' ),
						'garageDefault'        => __( 'Default', 'riviantrackr-passkeys' ),
						'garageMakeDefault'    => __( 'Make default', 'riviantrackr-passkeys' ),
						'garageEdit'           => __( 'Edit', 'riviantrackr-passkeys' ),
						'garageRemove'         => __( 'Remove', 'riviantrackr-passkeys' ),
						'garageConfirmRemove'  => __( 'Remove this vehicle from your garage?', 'riviantrackr-passkeys' ),
						'garageAddTitle'       => __( 'Add a vehicle', 'riviantrackr-passkeys' ),
						'garageEditTitle'      => __( 'Edit vehicle', 'riviantrackr-passkeys' ),
						'garageNoWheels'       => __( 'No wheels added yet.', 'riviantrackr-passkeys' ),
						'garageChooseYear'     => __( 'Choose a year', 'riviantrackr-passkeys' ),
						'garageYearFirst'      => __( 'Pick the year first', 'riviantrackr-passkeys' ),
						'garageChooseWheel'    => __( 'Choose a wheel', 'riviantrackr-passkeys' ),
						'garageOtherWheel'     => __( 'Another wheel (type the tire size)', 'riviantrackr-passkeys' ),
						'garageRivianWheels'   => __( 'Rivian wheels', 'riviantrackr-passkeys' ),
						'garageAftermarket'    => __( 'Aftermarket', 'riviantrackr-passkeys' ),
						'garageWheel'          => __( 'Wheel', 'riviantrackr-passkeys' ),
						'garageTireSize'       => __( 'Tire size', 'riviantrackr-passkeys' ),
						'garageTireSizeHint'   => __( 'e.g. 275/65R18', 'riviantrackr-passkeys' ),
						'garageWheelName'      => __( 'Wheel name (optional)', 'riviantrackr-passkeys' ),
						'garageWheelNameHint'  => __( 'e.g. Method 703, 18 in', 'riviantrackr-passkeys' ),
						'garageWheelLabel'     => __( 'Label (optional)', 'riviantrackr-passkeys' ),
						'garageWheelLabelHint' => __( 'Summer, Winter, Off-road', 'riviantrackr-passkeys' ),
						'garageRemoveWheel'    => __( 'Remove this wheel set', 'riviantrackr-passkeys' ),
						/* translators: %d: first model year of an ongoing generation */
						'garageYearsOpen'      => __( '%d+', 'riviantrackr-passkeys' ),
					],
				]
			);
		}
		wp_enqueue_style( 'rtpk-account' );
		wp_enqueue_script( 'rtpk-account' );
	}

	/**
	 * [riviantrackr_account theme="dark|light"]
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts = [] ): string {
		$atts  = shortcode_atts( [ 'theme' => 'dark' ], is_array( $atts ) ? $atts : [], self::SHORTCODE );
		$light = 'light' === $atts['theme'];

		$this->enqueue( $light );

		if ( ! is_user_logged_in() ) {
			$login = wp_login_url( get_permalink() ?: home_url( '/' ) );
			return '<div class="rtpk-account' . ( $light ? ' is-light' : '' ) . ' is-signed-out"><div class="rtpk-account__signed-out">'
				. self::icon( 'user' )
				. '<p>' . esc_html__( 'Sign in to see your account.', 'riviantrackr-passkeys' ) . '</p>'
				. '<a class="rtpk-btn rtpk-btn--primary" href="' . esc_url( $login ) . '">' . esc_html__( 'Sign in', 'riviantrackr-passkeys' ) . '</a>'
				. self::join_line( $this->settings->register_page_url() )
				. '</div></div>';
		}

		return $this->render( wp_get_current_user(), $light );
	}

	/**
	 * Render the whole account page for a member.
	 */
	public function render( \WP_User $user, bool $light ): string {
		$tabs    = $this->tabs();
		$context = [
			'light'       => $light,
			'account_url' => $this->account_url(),
			'tabs'        => array_keys( $tabs ),
		];
		$name    = '' !== $user->display_name ? $user->display_name : $user->user_login;
		$first   = (string) array_key_first( $tabs );

		ob_start();
		?>
		<div class="rtpk-account<?php echo $light ? ' is-light' : ''; ?>" data-rtpk-account>
			<header class="rtpk-account__header">
				<div class="rtpk-account__identity">
					<?php echo get_avatar( $user->ID, 56, '', $name, [ 'class' => 'rtpk-account__avatar' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() escapes. ?>
					<div>
						<p class="rtpk-account__eyebrow"><?php esc_html_e( 'My account', 'riviantrackr-passkeys' ); ?></p>
						<h2 class="rtpk-account__name"><?php echo esc_html( $name ); ?></h2>
					</div>
				</div>
				<a class="rtpk-btn rtpk-btn--ghost rtpk-account__signout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
					<?php echo self::icon( 'logout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
					<span><?php esc_html_e( 'Sign out', 'riviantrackr-passkeys' ); ?></span>
				</a>
			</header>

			<div class="rtpk-account__layout">
				<nav class="rtpk-account__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Account sections', 'riviantrackr-passkeys' ); ?>">
					<?php foreach ( $tabs as $tab ) : ?>
						<?php $active = $tab['id'] === $first; ?>
						<a class="rtpk-account__tab<?php echo $active ? ' is-active' : ''; ?>" role="tab" id="rtpk-tab-<?php echo esc_attr( $tab['id'] ); ?>" href="#<?php echo esc_attr( $tab['id'] ); ?>" data-rtpk-tab="<?php echo esc_attr( $tab['id'] ); ?>" aria-controls="rtpk-panel-<?php echo esc_attr( $tab['id'] ); ?>" aria-selected="<?php echo $active ? 'true' : 'false'; ?>" tabindex="<?php echo $active ? '0' : '-1'; ?>">
							<span class="rtpk-account__tab-icon" aria-hidden="true"><?php echo '' !== $tab['icon'] ? $tab['icon'] : self::icon( 'dot' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inline SVG supplied by the tab definition. ?></span>
							<span><?php echo esc_html( $tab['label'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</nav>

				<div class="rtpk-account__panels">
					<p class="rtpk-account__msg" data-rtpk-msg role="status" aria-live="polite" hidden></p>
					<?php foreach ( $tabs as $tab ) : ?>
						<?php $active = $tab['id'] === $first; ?>
						<section class="rtpk-account__panel" role="tabpanel" id="rtpk-panel-<?php echo esc_attr( $tab['id'] ); ?>" data-rtpk-panel="<?php echo esc_attr( $tab['id'] ); ?>" aria-labelledby="rtpk-tab-<?php echo esc_attr( $tab['id'] ); ?>" <?php echo $active ? '' : 'hidden'; ?>>
							<?php echo (string) call_user_func( $tab['render'], $user, $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each renderer escapes its own output. ?>
						</section>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * "New here? Create a free account" under the signed-out Sign in
	 * button, or '' without a join page. Pure.
	 */
	public static function join_line( string $join_url ): string {
		if ( '' === $join_url ) {
			return '';
		}
		return '<p class="rtpk-account__join">' . esc_html__( 'New here?', 'riviantrackr-passkeys' ) . ' <a href="' . esc_url( $join_url ) . '">' . esc_html__( 'Create a free account', 'riviantrackr-passkeys' ) . '</a> ' . esc_html__( 'to save your Rivian and see what fits it.', 'riviantrackr-passkeys' ) . '</p>';
	}

	/**
	 * What the page script needs for the Garage tab: the member's garage,
	 * the models, years and generations to pick from, and the Tire Guide's
	 * wheels.
	 *
	 * @return array<string,mixed>
	 */
	private function garage_config(): array {
		$user_id = get_current_user_id();
		$wheels  = TireGuide::wheels();
		$garage  = $user_id > 0 ? Garage::for_user( $user_id, $wheels ) : [
			'vehicles'   => [],
			'default_id' => '',
		];
		return [
			'vehicles'  => $garage['vehicles'],
			'defaultId' => $garage['default_id'],
			'models'    => Vehicles::catalog(),
			'wheels'    => $wheels,
			'max'       => Garage::MAX_VEHICLES,
			'maxWheels' => Garage::MAX_WHEELS,
			'icon'      => self::icon( 'car' ),
		];
	}

	private function account_url(): string {
		$url = $this->settings->account_page_url();
		if ( '' === $url ) {
			$url = (string) ( get_permalink() ?: home_url( '/' ) );
		}
		return $url;
	}

	// ---- overview --------------------------------------------------------

	/**
	 * @param \WP_User            $user    The member.
	 * @param array<string,mixed> $context light, account_url, tabs.
	 */
	public function render_overview( \WP_User $user, array $context ): string {
		$tabs      = (array) ( $context['tabs'] ?? [] );
		$reviews   = in_array( 'reviews', $tabs, true );
		$favorites = in_array( 'favorites', $tabs, true );
		$garage    = in_array( 'garage', $tabs, true );
		$vehicles  = $garage ? count( Garage::stored( $user->ID )['vehicles'] ) : 0;
		$name      = '' !== $user->display_name ? $user->display_name : $user->user_login;
		$since     = $user->user_registered ? date_i18n( (string) get_option( 'date_format' ), strtotime( (string) $user->user_registered ) ) : '';
		$comments  = (int) get_comments(
			[
				'user_id' => $user->ID,
				'status'  => 'approve',
				'count'   => true,
			]
		);

		$stats = [
			[
				'tab'   => 'security',
				'icon'  => 'shield',
				'value' => $this->store->count_for_user( $user->ID ),
				'label' => __( 'Passkeys', 'riviantrackr-passkeys' ),
			],
		];
		if ( $garage ) {
			$stats[] = [
				'tab'   => 'garage',
				'icon'  => 'car',
				'value' => $vehicles,
				'label' => _n( 'Vehicle', 'Vehicles', $vehicles, 'riviantrackr-passkeys' ),
			];
		}
		if ( $reviews ) {
			$stats[] = [
				'tab'   => 'reviews',
				'icon'  => 'star',
				'value' => TireGuide::review_count( $user->ID ),
				'label' => __( 'Reviews', 'riviantrackr-passkeys' ),
			];
		}
		if ( $favorites ) {
			$stats[] = [
				'tab'   => 'favorites',
				'icon'  => 'heart',
				'value' => TireGuide::favorite_count( $user->ID ),
				'label' => __( 'Saved tires', 'riviantrackr-passkeys' ),
			];
		}
		$stats[] = [
			'tab'   => 'comments',
			'icon'  => 'comment',
			'value' => $comments,
			'label' => __( 'Comments', 'riviantrackr-passkeys' ),
		];

		$links = [
			'profile'  => [ __( 'Edit your profile', 'riviantrackr-passkeys' ), __( 'Name, email, website and bio.', 'riviantrackr-passkeys' ) ],
			'garage'   => [ __( 'Your garage', 'riviantrackr-passkeys' ), __( 'The Rivians you drive and the wheels they wear.', 'riviantrackr-passkeys' ) ],
			'security' => [ __( 'Passkeys and password', 'riviantrackr-passkeys' ), __( 'Add a passkey, change your password, sign out other devices.', 'riviantrackr-passkeys' ) ],
		];
		if ( $reviews ) {
			$links['reviews'] = [ __( 'Your tire reviews', 'riviantrackr-passkeys' ), __( 'See, edit or delete what you have written.', 'riviantrackr-passkeys' ) ];
		}
		if ( $favorites ) {
			$links['favorites'] = [ __( 'Saved tires', 'riviantrackr-passkeys' ), __( 'The tires you hearted in the guide, with how their prices have moved.', 'riviantrackr-passkeys' ) ];
		}
		$links['comments'] = [ __( 'Your comments', 'riviantrackr-passkeys' ), __( 'Conversations you joined.', 'riviantrackr-passkeys' ) ];

		ob_start();
		?>
		<div class="rtpk-account__profile-card">
			<?php echo get_avatar( $user->ID, 96, '', $name, [ 'class' => 'rtpk-account__avatar rtpk-account__avatar--lg' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() escapes. ?>
			<div class="rtpk-account__profile-text">
				<h3 class="rtpk-account__title"><?php echo esc_html( $name ); ?></h3>
				<p class="rtpk-account__meta"><?php echo esc_html( $user->user_email ); ?></p>
				<?php if ( '' !== $since ) : ?>
					<p class="rtpk-account__meta">
						<?php
						printf(
							/* translators: %s: date */
							esc_html__( 'Member since %s', 'riviantrackr-passkeys' ),
							esc_html( $since )
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $garage && 0 === $vehicles ) : ?>
			<div class="rtpk-account__block rtpk-account__card rtpk-account__card--row rtpk-garage__nudge" data-rtpk-garage-nudge>
				<span class="rtpk-garage__nudge-icon" aria-hidden="true"><?php echo self::icon( 'car' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></span>
				<div>
					<h4 class="rtpk-account__subtitle"><?php esc_html_e( 'Add your Rivian', 'riviantrackr-passkeys' ); ?></h4>
					<p class="rtpk-account__desc"><?php esc_html_e( 'Tell us what you drive and which wheels it wears, and we will show the tires, software updates and news that apply to it.', 'riviantrackr-passkeys' ); ?></p>
				</div>
				<a class="rtpk-btn rtpk-btn--primary" href="#garage" data-rtpk-goto="garage"><?php esc_html_e( 'Add a vehicle', 'riviantrackr-passkeys' ); ?></a>
			</div>
		<?php endif; ?>

		<div class="rtpk-account__stats">
			<?php foreach ( $stats as $stat ) : ?>
				<a class="rtpk-account__stat" href="#<?php echo esc_attr( $stat['tab'] ); ?>" data-rtpk-goto="<?php echo esc_attr( $stat['tab'] ); ?>">
					<span class="rtpk-account__stat-icon" aria-hidden="true"><?php echo self::icon( $stat['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></span>
					<span class="rtpk-account__stat-value"><?php echo esc_html( number_format_i18n( (int) $stat['value'] ) ); ?></span>
					<span class="rtpk-account__stat-label"><?php echo esc_html( $stat['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>

		<ul class="rtpk-account__links">
			<?php foreach ( $links as $id => $link ) : ?>
				<?php
				if ( ! in_array( $id, $tabs, true ) ) {
					continue;
				}
				?>
				<li>
					<a class="rtpk-account__link" href="#<?php echo esc_attr( $id ); ?>" data-rtpk-goto="<?php echo esc_attr( $id ); ?>">
						<span class="rtpk-account__link-text">
							<strong><?php echo esc_html( $link[0] ); ?></strong>
							<span><?php echo esc_html( $link[1] ); ?></span>
						</span>
						<?php echo self::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		return (string) ob_get_clean();
	}

	// ---- profile ---------------------------------------------------------

	/**
	 * @param \WP_User            $user    The member.
	 * @param array<string,mixed> $context light, account_url, tabs.
	 */
	public function render_profile( \WP_User $user, array $context ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Tab renderer signature.
		$pending = AccountService::pending_email( $user->ID );
		$fields  = [
			[ 'display_name', __( 'Display name', 'riviantrackr-passkeys' ), 'text', $user->display_name, 'nickname', __( 'Shown next to your reviews and comments.', 'riviantrackr-passkeys' ) ],
			[ 'first_name', __( 'First name', 'riviantrackr-passkeys' ), 'text', (string) $user->first_name, 'given-name', '' ],
			[ 'last_name', __( 'Last name', 'riviantrackr-passkeys' ), 'text', (string) $user->last_name, 'family-name', '' ],
			[ 'email', __( 'Email', 'riviantrackr-passkeys' ), 'email', $user->user_email, 'email', __( 'Changing it sends a confirmation link to the new address.', 'riviantrackr-passkeys' ) ],
			[ 'url', __( 'Website', 'riviantrackr-passkeys' ), 'url', (string) $user->user_url, 'url', '' ],
		];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::email_notice( isset( $_GET[ self::EMAIL_QUERY ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::EMAIL_QUERY ] ) ) : '' );
		// phpcs:enable

		ob_start();
		?>
		<h3 class="rtpk-account__title"><?php esc_html_e( 'Profile', 'riviantrackr-passkeys' ); ?></h3>
		<p class="rtpk-account__desc"><?php esc_html_e( 'How you appear on the site and how we reach you.', 'riviantrackr-passkeys' ); ?></p>

		<?php if ( null !== $notice ) : ?>
			<div class="rtpk-account__notice is-<?php echo esc_attr( $notice['type'] ); ?>" role="<?php echo 'error' === $notice['type'] ? 'alert' : 'status'; ?>"><span><?php echo esc_html( $notice['text'] ); ?></span></div>
		<?php endif; ?>

		<?php $custom = Avatars::attachment_id( $user->ID ) > 0; ?>
		<div class="rtpk-account__block rtpk-account__card rtpk-account__avatar-card" data-rtpk-avatar data-custom="<?php echo $custom ? '1' : '0'; ?>">
			<?php echo get_avatar( $user->ID, 96, '', '' !== $user->display_name ? $user->display_name : $user->user_login, [ 'class' => 'rtpk-account__avatar rtpk-account__avatar--lg' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() escapes. ?>
			<div class="rtpk-account__avatar-text">
				<h4 class="rtpk-account__subtitle"><?php esc_html_e( 'Your photo', 'riviantrackr-passkeys' ); ?></h4>
				<p class="rtpk-account__desc" data-rtpk-avatar-desc>
					<?php
					if ( $custom ) {
						esc_html_e( 'Shown next to your comments and reviews. Upload a new one to replace it, or remove it to go back to your Gravatar.', 'riviantrackr-passkeys' );
					} else {
						printf(
							/* translators: %s: email address */
							esc_html__( 'We currently show the Gravatar for %s. Upload a photo to use instead. Square photos look best; we crop to the middle.', 'riviantrackr-passkeys' ),
							'<strong>' . esc_html( $user->user_email ) . '</strong>'
						);
					}
					?>
				</p>
				<p class="rtpk-account__help">
					<?php
					printf(
						/* translators: %s: size such as "2 MB" */
						esc_html__( 'JPG, PNG, GIF or WebP, up to %s.', 'riviantrackr-passkeys' ),
						esc_html( size_format( Avatars::max_bytes() ) )
					);
					?>
				</p>
				<div class="rtpk-account__actions rtpk-account__avatar-actions">
					<label class="rtpk-btn rtpk-btn--secondary rtpk-account__upload" data-rtpk-avatar-pick>
						<input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" data-rtpk-avatar-input>
						<span><?php echo $custom ? esc_html__( 'Change photo', 'riviantrackr-passkeys' ) : esc_html__( 'Upload a photo', 'riviantrackr-passkeys' ); ?></span>
					</label>
					<button type="button" class="rtpk-btn rtpk-btn--ghost" data-rtpk-avatar-remove <?php echo $custom ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove photo', 'riviantrackr-passkeys' ); ?></button>
					<span class="rtpk-account__inline-msg" data-rtpk-avatar-msg role="status" aria-live="polite"></span>
				</div>
			</div>
		</div>

		<div class="rtpk-account__notice is-info" data-rtpk-pending-email <?php echo '' === $pending ? 'hidden' : ''; ?>>
			<span data-rtpk-pending-text>
				<?php
				if ( '' !== $pending ) {
					printf(
						/* translators: %s: email address */
						esc_html__( 'A confirmation link was sent to %s. Your email changes once you click it.', 'riviantrackr-passkeys' ),
						'<strong>' . esc_html( $pending ) . '</strong>'
					);
				}
				?>
			</span>
			<button type="button" class="rtpk-btn rtpk-btn--ghost rtpk-btn--sm" data-rtpk-cancel-email><?php esc_html_e( 'Cancel', 'riviantrackr-passkeys' ); ?></button>
		</div>

		<form class="rtpk-account__form" data-rtpk-profile-form novalidate>
			<div class="rtpk-account__grid">
				<?php foreach ( $fields as [ $key, $label, $type, $value, $autocomplete, $help ] ) : ?>
					<div class="rtpk-account__field<?php echo in_array( $key, [ 'display_name', 'email', 'url' ], true ) ? ' rtpk-account__field--wide' : ''; ?>" data-rtpk-field="<?php echo esc_attr( $key ); ?>">
						<label class="rtpk-account__label" for="rtpk-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
						<input class="rtpk-account__input" type="<?php echo esc_attr( $type ); ?>" id="rtpk-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" autocomplete="<?php echo esc_attr( $autocomplete ); ?>" <?php echo 'url' === $type ? 'placeholder="https://"' : ''; ?>>
						<?php if ( '' !== $help ) : ?>
							<p class="rtpk-account__help"><?php echo esc_html( $help ); ?></p>
						<?php endif; ?>
						<p class="rtpk-account__error" data-rtpk-error hidden></p>
					</div>
				<?php endforeach; ?>
				<div class="rtpk-account__field rtpk-account__field--wide" data-rtpk-field="description">
					<label class="rtpk-account__label" for="rtpk-description"><?php esc_html_e( 'About you', 'riviantrackr-passkeys' ); ?></label>
					<textarea class="rtpk-account__input rtpk-account__textarea" id="rtpk-description" name="description" rows="4" maxlength="<?php echo (int) AccountService::BIO_MAX; ?>"><?php echo esc_textarea( (string) $user->description ); ?></textarea>
					<p class="rtpk-account__help"><?php esc_html_e( 'A line or two about your Rivian. Plain text only.', 'riviantrackr-passkeys' ); ?></p>
					<p class="rtpk-account__error" data-rtpk-error hidden></p>
				</div>
			</div>
			<div class="rtpk-account__actions">
				<button type="submit" class="rtpk-btn rtpk-btn--primary"><?php esc_html_e( 'Save changes', 'riviantrackr-passkeys' ); ?></button>
				<span class="rtpk-account__inline-msg" data-rtpk-form-msg role="status" aria-live="polite"></span>
			</div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	// ---- garage ----------------------------------------------------------

	/**
	 * The Garage tab. The vehicle list and the wheel rows are drawn by the
	 * page script from the garage it is given, and redrawn after each save.
	 *
	 * @param \WP_User            $user    The member.
	 * @param array<string,mixed> $context light, account_url, tabs.
	 */
	public function render_garage( \WP_User $user, array $context ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Tab renderer signature.
		$models = Vehicles::catalog();

		ob_start();
		?>
		<h3 class="rtpk-account__title"><?php esc_html_e( 'Your garage', 'riviantrackr-passkeys' ); ?></h3>
		<p class="rtpk-account__desc"><?php esc_html_e( 'The Rivians you drive and the wheels they wear. We use them to show the tires, software updates and news that apply to you, starting with your default vehicle.', 'riviantrackr-passkeys' ); ?></p>

		<div class="rtpk-garage" data-rtpk-garage>
			<ul class="rtpk-account__list rtpk-garage__list" data-rtpk-garage-list></ul>
			<p class="rtpk-account__empty" data-rtpk-garage-empty hidden><?php esc_html_e( 'No vehicles yet. Add the Rivian you drive to get started.', 'riviantrackr-passkeys' ); ?></p>

			<div class="rtpk-account__actions rtpk-garage__add">
				<button type="button" class="rtpk-btn rtpk-btn--primary" data-rtpk-garage-add><?php esc_html_e( 'Add a vehicle', 'riviantrackr-passkeys' ); ?></button>
				<span class="rtpk-account__help" data-rtpk-garage-full hidden>
					<?php
					printf(
						/* translators: %d: number of vehicles */
						esc_html__( 'Your garage holds up to %d vehicles. Remove one to add another.', 'riviantrackr-passkeys' ),
						(int) Garage::MAX_VEHICLES
					);
					?>
				</span>
			</div>

			<form class="rtpk-account__form rtpk-account__card rtpk-garage__form" data-rtpk-garage-form novalidate hidden>
				<h4 class="rtpk-account__subtitle" data-rtpk-garage-form-title><?php esc_html_e( 'Add a vehicle', 'riviantrackr-passkeys' ); ?></h4>
				<div class="rtpk-account__grid">
					<div class="rtpk-account__field" data-rtpk-field="model">
						<label class="rtpk-account__label" for="rtpk-garage-model"><?php esc_html_e( 'Model', 'riviantrackr-passkeys' ); ?></label>
						<select class="rtpk-account__input" id="rtpk-garage-model" name="model" required>
							<option value=""><?php esc_html_e( 'Choose a model', 'riviantrackr-passkeys' ); ?></option>
							<?php foreach ( $models as $slug => $model ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $model['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="rtpk-account__error" data-rtpk-error hidden></p>
					</div>
					<div class="rtpk-account__field" data-rtpk-field="year">
						<label class="rtpk-account__label" for="rtpk-garage-year"><?php esc_html_e( 'Model year', 'riviantrackr-passkeys' ); ?></label>
						<select class="rtpk-account__input" id="rtpk-garage-year" name="year" required disabled>
							<option value=""><?php esc_html_e( 'Choose a year', 'riviantrackr-passkeys' ); ?></option>
						</select>
						<p class="rtpk-account__error" data-rtpk-error hidden></p>
					</div>
					<div class="rtpk-account__field" data-rtpk-field="generation">
						<label class="rtpk-account__label" for="rtpk-garage-generation"><?php esc_html_e( 'Generation', 'riviantrackr-passkeys' ); ?></label>
						<select class="rtpk-account__input" id="rtpk-garage-generation" name="generation" required disabled></select>
						<p class="rtpk-account__help"><?php esc_html_e( 'Set from the model year. Change it if yours differs.', 'riviantrackr-passkeys' ); ?></p>
						<p class="rtpk-account__error" data-rtpk-error hidden></p>
					</div>
					<div class="rtpk-account__field" data-rtpk-field="nickname">
						<label class="rtpk-account__label" for="rtpk-garage-nickname"><?php esc_html_e( 'Nickname (optional)', 'riviantrackr-passkeys' ); ?></label>
						<input class="rtpk-account__input" type="text" id="rtpk-garage-nickname" name="nickname" maxlength="<?php echo (int) Garage::NAME_MAX; ?>" autocomplete="off" placeholder="<?php esc_attr_e( 'e.g. Big Blue', 'riviantrackr-passkeys' ); ?>">
						<p class="rtpk-account__error" data-rtpk-error hidden></p>
					</div>
				</div>

				<fieldset class="rtpk-garage__wheels">
					<legend class="rtpk-account__label"><?php esc_html_e( 'Wheels', 'riviantrackr-passkeys' ); ?></legend>
					<p class="rtpk-account__help">
						<?php
						printf(
							/* translators: %d: number of wheel sets */
							esc_html__( 'Add each set you run, like summer and winter (up to %d). Pick a Rivian wheel, or choose another wheel and type the tire size.', 'riviantrackr-passkeys' ),
							(int) Garage::MAX_WHEELS
						);
						?>
					</p>
					<div class="rtpk-garage__wheel-rows" data-rtpk-garage-wheels></div>
					<div data-rtpk-field="wheels"><p class="rtpk-account__error" data-rtpk-error hidden></p></div>
					<button type="button" class="rtpk-btn rtpk-btn--secondary rtpk-btn--sm" data-rtpk-garage-add-wheel><?php esc_html_e( 'Add a wheel set', 'riviantrackr-passkeys' ); ?></button>
				</fieldset>

				<label class="rtpk-garage__check">
					<input type="checkbox" name="make_default" data-rtpk-garage-default>
					<span><?php esc_html_e( 'Make this my default vehicle', 'riviantrackr-passkeys' ); ?></span>
				</label>

				<div class="rtpk-account__actions">
					<button type="submit" class="rtpk-btn rtpk-btn--primary"><?php esc_html_e( 'Save vehicle', 'riviantrackr-passkeys' ); ?></button>
					<button type="button" class="rtpk-btn rtpk-btn--ghost" data-rtpk-garage-cancel><?php esc_html_e( 'Cancel', 'riviantrackr-passkeys' ); ?></button>
					<span class="rtpk-account__inline-msg" data-rtpk-form-msg role="status" aria-live="polite"></span>
				</div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// ---- security --------------------------------------------------------

	/**
	 * @param \WP_User            $user    The member.
	 * @param array<string,mixed> $context light, account_url, tabs.
	 */
	public function render_security( \WP_User $user, array $context ): string {
		$light   = ! empty( $context['light'] );
		$manager = $this->manager->markup(
			$user,
			true,
			$light,
			[
				'title' => __( 'Passkeys', 'riviantrackr-passkeys' ),
				'desc'  => __( 'Sign in with Face ID, Touch ID, Windows Hello or a security key instead of a password.', 'riviantrackr-passkeys' ),
			]
		);

		ob_start();
		?>
		<h3 class="rtpk-account__title"><?php esc_html_e( 'Security', 'riviantrackr-passkeys' ); ?></h3>
		<p class="rtpk-account__desc"><?php esc_html_e( 'Passkeys, your password and where you are signed in.', 'riviantrackr-passkeys' ); ?></p>

		<div class="rtpk-account__block">
			<?php echo $manager; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ProfileManager::markup(). ?>
		</div>

		<?php if ( AccountService::has_no_password( $user->ID ) ) : ?>
		<div class="rtpk-account__block rtpk-account__card rtpk-account__card--row" data-rtpk-no-password>
			<div>
				<h4 class="rtpk-account__subtitle"><?php esc_html_e( 'Set a password', 'riviantrackr-passkeys' ); ?></h4>
				<p class="rtpk-account__desc"><?php esc_html_e( 'Your account was created from an email link, so it has no password yet. Passkeys are all you need, but a password works as a backup on devices without one. We’ll email you a link to choose it.', 'riviantrackr-passkeys' ); ?></p>
			</div>
			<button type="button" class="rtpk-btn rtpk-btn--secondary" data-rtpk-password-link><?php esc_html_e( 'Email me a link', 'riviantrackr-passkeys' ); ?></button>
		</div>
		<?php else : ?>
		<div class="rtpk-account__block rtpk-account__card">
			<h4 class="rtpk-account__subtitle"><?php esc_html_e( 'Change password', 'riviantrackr-passkeys' ); ?></h4>
			<p class="rtpk-account__desc"><?php esc_html_e( 'You stay signed in here; every other device is signed out.', 'riviantrackr-passkeys' ); ?></p>
			<form class="rtpk-account__form" data-rtpk-password-form novalidate>
				<div class="rtpk-account__grid">
					<div class="rtpk-account__field rtpk-account__field--wide" data-rtpk-field="current">
						<label class="rtpk-account__label" for="rtpk-pass-current"><?php esc_html_e( 'Current password', 'riviantrackr-passkeys' ); ?></label>
						<input class="rtpk-account__input" type="password" id="rtpk-pass-current" name="current" autocomplete="current-password" required>
						<p class="rtpk-account__error" data-rtpk-error hidden></p>
					</div>
					<div class="rtpk-account__field" data-rtpk-field="new">
						<label class="rtpk-account__label" for="rtpk-pass-new"><?php esc_html_e( 'New password', 'riviantrackr-passkeys' ); ?></label>
						<input class="rtpk-account__input" type="password" id="rtpk-pass-new" name="new" autocomplete="new-password" minlength="<?php echo (int) AccountService::PASSWORD_MIN; ?>" required>
						<p class="rtpk-account__help">
							<?php
							printf(
								/* translators: %d: minimum length */
								esc_html__( 'At least %d characters.', 'riviantrackr-passkeys' ),
								(int) AccountService::PASSWORD_MIN
							);
							?>
						</p>
						<p class="rtpk-account__error" data-rtpk-error hidden></p>
					</div>
					<div class="rtpk-account__field" data-rtpk-field="confirm">
						<label class="rtpk-account__label" for="rtpk-pass-confirm"><?php esc_html_e( 'Repeat new password', 'riviantrackr-passkeys' ); ?></label>
						<input class="rtpk-account__input" type="password" id="rtpk-pass-confirm" name="confirm" autocomplete="new-password" required>
						<p class="rtpk-account__error" data-rtpk-error hidden></p>
					</div>
				</div>
				<div class="rtpk-account__actions">
					<button type="submit" class="rtpk-btn rtpk-btn--primary"><?php esc_html_e( 'Change password', 'riviantrackr-passkeys' ); ?></button>
					<span class="rtpk-account__inline-msg" data-rtpk-form-msg role="status" aria-live="polite"></span>
				</div>
			</form>
			<p class="rtpk-account__help rtpk-account__help--action">
				<?php esc_html_e( 'Forgot your current password?', 'riviantrackr-passkeys' ); ?>
				<button type="button" class="rtpk-account__textbtn" data-rtpk-password-link><?php esc_html_e( 'Email me a reset link', 'riviantrackr-passkeys' ); ?></button>
			</p>
		</div>
		<?php endif; ?>

		<div class="rtpk-account__block rtpk-account__card rtpk-account__card--row">
			<div>
				<h4 class="rtpk-account__subtitle"><?php esc_html_e( 'Sign out everywhere else', 'riviantrackr-passkeys' ); ?></h4>
				<p class="rtpk-account__desc"><?php esc_html_e( 'Lost a phone or used a shared computer? End every session except this one.', 'riviantrackr-passkeys' ); ?></p>
			</div>
			<button type="button" class="rtpk-btn rtpk-btn--secondary" data-rtpk-signout-others><?php esc_html_e( 'Sign out other devices', 'riviantrackr-passkeys' ); ?></button>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// ---- reviews ---------------------------------------------------------

	/**
	 * @param \WP_User            $user    The member.
	 * @param array<string,mixed> $context light, account_url, tabs.
	 */
	public function render_reviews( \WP_User $user, array $context ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Tab renderer signature.
		$reviews = TireGuide::reviews( $user->ID );
		$format  = (string) get_option( 'date_format' );
		$labels  = [
			'approved' => __( 'Published', 'riviantrackr-passkeys' ),
			'pending'  => __( 'Awaiting review', 'riviantrackr-passkeys' ),
			'rejected' => __( 'Not published', 'riviantrackr-passkeys' ),
		];

		ob_start();
		?>
		<h3 class="rtpk-account__title"><?php esc_html_e( 'Your tire reviews', 'riviantrackr-passkeys' ); ?></h3>
		<p class="rtpk-account__desc"><?php esc_html_e( 'Edit opens your review in the tire guide with everything you wrote filled in. Delete removes it for good.', 'riviantrackr-passkeys' ); ?></p>

		<ul class="rtpk-account__list" data-rtpk-list="reviews">
			<?php foreach ( $reviews as $review ) : ?>
				<li class="rtpk-account__item" data-rtpk-item data-tire-id="<?php echo esc_attr( $review['tire_id'] ); ?>">
					<?php if ( '' !== $review['image'] ) : ?>
						<img class="rtpk-account__thumb" src="<?php echo esc_url( $review['image'] ); ?>" alt="" loading="lazy" width="56" height="56">
					<?php else : ?>
						<span class="rtpk-account__thumb rtpk-account__thumb--empty" aria-hidden="true"><?php echo self::icon( 'tire' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></span>
					<?php endif; ?>
					<div class="rtpk-account__item-body">
						<div class="rtpk-account__item-head">
							<?php if ( '' !== $review['url'] ) : ?>
								<a class="rtpk-account__item-name" href="<?php echo esc_url( $review['url'] ); ?>"><?php echo esc_html( $review['tire'] ); ?></a>
							<?php else : ?>
								<span class="rtpk-account__item-name"><?php echo esc_html( $review['tire'] ); ?></span>
							<?php endif; ?>
							<span class="rtpk-badge<?php echo 'approved' === $review['status'] ? ' rtpk-badge--success' : ( 'rejected' === $review['status'] ? ' rtpk-badge--muted' : '' ); ?>"><?php echo esc_html( $labels[ $review['status'] ] ?? $review['status'] ); ?></span>
						</div>
						<div class="rtpk-account__stars" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: rating */ __( '%s out of 5 stars', 'riviantrackr-passkeys' ), (string) $review['rating'] ) ); ?>"><?php echo self::stars( (float) $review['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></div>
						<?php if ( '' !== $review['title'] ) : ?>
							<p class="rtpk-account__item-title"><?php echo esc_html( $review['title'] ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $review['text'] ) : ?>
							<p class="rtpk-account__item-excerpt"><?php echo esc_html( wp_trim_words( $review['text'], 32 ) ); ?></p>
						<?php endif; ?>
						<p class="rtpk-account__item-meta"><?php echo '' !== $review['date'] ? esc_html( date_i18n( $format, strtotime( $review['date'] . ' UTC' ) ) ) : ''; ?></p>
					</div>
					<div class="rtpk-account__item-actions">
						<?php if ( '' !== $review['edit_url'] ) : ?>
							<a class="rtpk-btn rtpk-btn--ghost rtpk-btn--sm" href="<?php echo esc_url( $review['edit_url'] ); ?>"><?php esc_html_e( 'Edit', 'riviantrackr-passkeys' ); ?></a>
						<?php endif; ?>
						<button type="button" class="rtpk-btn rtpk-btn--danger rtpk-btn--sm" data-rtpk-delete-review><?php esc_html_e( 'Delete', 'riviantrackr-passkeys' ); ?></button>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="rtpk-account__empty" data-rtpk-empty <?php echo empty( $reviews ) ? '' : 'hidden'; ?>><?php esc_html_e( 'You have not reviewed any tires yet.', 'riviantrackr-passkeys' ); ?></p>
		<?php
		return (string) ob_get_clean();
	}

	// ---- saved tires (the `favorites` tab) --------------------------------

	/**
	 * @param \WP_User            $user    The member.
	 * @param array<string,mixed> $context light, account_url, tabs.
	 */
	public function render_favorites( \WP_User $user, array $context ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Tab renderer signature.
		$favorites = TireGuide::favorites( $user->ID );
		$format    = (string) get_option( 'date_format' );
		$guide_url = TireGuide::saved_tires_url();

		ob_start();
		?>
		<h3 class="rtpk-account__title"><?php esc_html_e( 'Saved tires', 'riviantrackr-passkeys' ); ?></h3>
		<p class="rtpk-account__desc">
			<?php esc_html_e( 'Tap the heart on any tire in the guide and it lands here, with what it costs today and how the price has moved since you saved it.', 'riviantrackr-passkeys' ); ?>
			<?php if ( '' !== $guide_url ) : ?>
				<a href="<?php echo esc_url( $guide_url ); ?>"><?php esc_html_e( 'Open your saved tires in the guide', 'riviantrackr-passkeys' ); ?></a>
				<?php esc_html_e( 'to compare them side by side.', 'riviantrackr-passkeys' ); ?>
			<?php endif; ?>
		</p>

		<ul class="rtpk-account__list" data-rtpk-list="favorites">
			<?php foreach ( $favorites as $fav ) : ?>
				<?php
				$meta = [];
				if ( '' !== $fav['size'] ) {
					$meta[] = $fav['size'];
				}
				if ( $fav['price'] > 0 ) {
					/* translators: %s: price */
					$meta[] = sprintf( __( '$%s today', 'riviantrackr-passkeys' ), number_format_i18n( $fav['price'], 0 ) );
				}
				if ( '' !== $fav['saved_at'] ) {
					/* translators: %s: date */
					$meta[] = sprintf( __( 'Saved %s', 'riviantrackr-passkeys' ), mysql2date( $format, $fav['saved_at'] ) );
				}
				?>
				<li class="rtpk-account__item" data-rtpk-item data-tire-id="<?php echo esc_attr( $fav['tire_id'] ); ?>">
					<?php if ( '' !== $fav['image'] ) : ?>
						<img class="rtpk-account__thumb" src="<?php echo esc_url( $fav['image'] ); ?>" alt="" loading="lazy" width="56" height="56">
					<?php else : ?>
						<span class="rtpk-account__thumb rtpk-account__thumb--empty" aria-hidden="true"><?php echo self::icon( 'tire' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></span>
					<?php endif; ?>
					<div class="rtpk-account__item-body">
						<?php if ( '' !== $fav['url'] ) : ?>
							<a class="rtpk-account__item-name" href="<?php echo esc_url( $fav['url'] ); ?>"><?php echo esc_html( $fav['tire'] ); ?></a>
						<?php else : ?>
							<span class="rtpk-account__item-name"><?php echo esc_html( $fav['tire'] ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $meta ) ) : ?>
							<p class="rtpk-account__item-meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></p>
						<?php endif; ?>
						<?php if ( 0 !== $fav['price_move'] ) : ?>
							<p class="rtpk-account__item-move <?php echo $fav['price_move'] < 0 ? 'is-drop' : 'is-rise'; ?>">
								<?php
								echo esc_html(
									$fav['price_move'] < 0
										/* translators: %s: dollar amount */
										? sprintf( __( '▼ $%s since you saved it', 'riviantrackr-passkeys' ), number_format_i18n( -$fav['price_move'], 0 ) )
										/* translators: %s: dollar amount */
										: sprintf( __( '▲ $%s since you saved it', 'riviantrackr-passkeys' ), number_format_i18n( $fav['price_move'], 0 ) )
								);
								?>
							</p>
						<?php endif; ?>
					</div>
					<div class="rtpk-account__item-actions">
						<?php if ( '' !== $fav['url'] ) : ?>
							<a class="rtpk-btn rtpk-btn--ghost rtpk-btn--sm" href="<?php echo esc_url( $fav['url'] ); ?>"><?php esc_html_e( 'View', 'riviantrackr-passkeys' ); ?></a>
						<?php endif; ?>
						<button type="button" class="rtpk-btn rtpk-btn--danger rtpk-btn--sm" data-rtpk-remove-favorite><?php esc_html_e( 'Remove', 'riviantrackr-passkeys' ); ?></button>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="rtpk-account__empty" data-rtpk-empty <?php echo empty( $favorites ) ? '' : 'hidden'; ?>><?php esc_html_e( 'No saved tires yet. Tap the heart on a tire in the guide to keep it here.', 'riviantrackr-passkeys' ); ?></p>
		<?php
		return (string) ob_get_clean();
	}

	// ---- comments --------------------------------------------------------

	/**
	 * @param \WP_User            $user    The member.
	 * @param array<string,mixed> $context light, account_url, tabs.
	 */
	public function render_comments( \WP_User $user, array $context ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Tab renderer signature.
		$comments = (array) get_comments(
			[
				'user_id' => $user->ID,
				'status'  => 'approve',
				'number'  => 20,
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			]
		);
		$format   = (string) get_option( 'date_format' );

		ob_start();
		?>
		<h3 class="rtpk-account__title"><?php esc_html_e( 'Your comments', 'riviantrackr-passkeys' ); ?></h3>
		<p class="rtpk-account__desc"><?php esc_html_e( 'Your most recent published comments.', 'riviantrackr-passkeys' ); ?></p>

		<ul class="rtpk-account__list">
			<?php foreach ( $comments as $comment ) : ?>
				<?php
				if ( ! $comment instanceof \WP_Comment ) {
					continue;
				}
				$post_title = get_the_title( (int) $comment->comment_post_ID );
				?>
				<li class="rtpk-account__item rtpk-account__item--comment">
					<div class="rtpk-account__item-body">
						<a class="rtpk-account__item-name" href="<?php echo esc_url( (string) get_comment_link( $comment ) ); ?>"><?php echo esc_html( '' !== $post_title ? $post_title : __( '(no title)', 'riviantrackr-passkeys' ) ); ?></a>
						<p class="rtpk-account__item-excerpt"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( (string) $comment->comment_content ), 32 ) ); ?></p>
						<p class="rtpk-account__item-meta"><?php echo esc_html( date_i18n( $format, strtotime( (string) $comment->comment_date ) ) ); ?></p>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( empty( $comments ) ) : ?>
			<p class="rtpk-account__empty"><?php esc_html_e( 'You have not commented yet.', 'riviantrackr-passkeys' ); ?></p>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}

	// ---- your data -------------------------------------------------------

	/**
	 * @param \WP_User            $user    The member.
	 * @param array<string,mixed> $context light, account_url, tabs.
	 */
	public function render_data( \WP_User $user, array $context ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Tab renderer signature.
		$immediate = 'delete' === AccountService::deletion_mode( $user, $this->settings->allows_self_delete() );

		ob_start();
		?>
		<h3 class="rtpk-account__title"><?php esc_html_e( 'Your data', 'riviantrackr-passkeys' ); ?></h3>
		<p class="rtpk-account__desc"><?php esc_html_e( 'Get a copy of what we hold about you, or close your account.', 'riviantrackr-passkeys' ); ?></p>

		<div class="rtpk-account__block rtpk-account__card rtpk-account__card--row">
			<div>
				<h4 class="rtpk-account__subtitle"><?php esc_html_e( 'Download a copy of your data', 'riviantrackr-passkeys' ); ?></h4>
				<p class="rtpk-account__desc">
					<?php
					printf(
						/* translators: %s: email address */
						esc_html__( 'Your profile, comments and the other personal data stored here, as a file. We’ll email a download link to %s once it’s ready.', 'riviantrackr-passkeys' ),
						'<strong>' . esc_html( $user->user_email ) . '</strong>'
					);
					?>
				</p>
			</div>
			<button type="button" class="rtpk-btn rtpk-btn--secondary" data-rtpk-export-data><?php esc_html_e( 'Request my data', 'riviantrackr-passkeys' ); ?></button>
		</div>

		<div class="rtpk-account__block rtpk-account__card rtpk-account__card--danger">
			<h4 class="rtpk-account__subtitle"><?php esc_html_e( 'Delete your account', 'riviantrackr-passkeys' ); ?></h4>
			<p class="rtpk-account__desc">
				<?php
				if ( $immediate ) {
					esc_html_e( 'This removes your profile, passkeys and sign-in straight away and cannot be undone. Comments and reviews you posted stay on the site without a linked account.', 'riviantrackr-passkeys' );
				} else {
					esc_html_e( 'Because your account has publishing rights, the site owner reviews deletions. This files the request and you’ll get an email when it’s done.', 'riviantrackr-passkeys' );
				}
				?>
			</p>
			<form class="rtpk-account__form" data-rtpk-delete-form data-username="<?php echo esc_attr( $user->user_login ); ?>" novalidate>
				<div class="rtpk-account__grid">
					<div class="rtpk-account__field rtpk-account__field--wide" data-rtpk-field="confirm">
						<label class="rtpk-account__label" for="rtpk-delete-confirm">
							<?php
							printf(
								/* translators: %s: username */
								esc_html__( 'Type your username (%s) to confirm', 'riviantrackr-passkeys' ),
								'<strong>' . esc_html( $user->user_login ) . '</strong>'
							);
							?>
						</label>
						<input class="rtpk-account__input" type="text" id="rtpk-delete-confirm" name="confirm" autocomplete="off" autocapitalize="off" spellcheck="false" required>
						<p class="rtpk-account__error" data-rtpk-error hidden></p>
					</div>
				</div>
				<div class="rtpk-account__actions">
					<button type="submit" class="rtpk-btn rtpk-btn--danger" disabled><?php echo $immediate ? esc_html__( 'Delete my account', 'riviantrackr-passkeys' ) : esc_html__( 'Request deletion', 'riviantrackr-passkeys' ); ?></button>
					<span class="rtpk-account__inline-msg" data-rtpk-form-msg role="status" aria-live="polite"></span>
				</div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// ---- helpers ---------------------------------------------------------

	/**
	 * Five-star row for a rating. Static SVG, no user input.
	 */
	public static function stars( float $rating ): string {
		$html = '';
		for ( $i = 1; $i <= 5; $i++ ) {
			$fill  = $rating >= $i ? 'full' : ( $rating >= $i - 0.5 ? 'half' : 'empty' );
			$html .= '<svg class="rtpk-star is-' . $fill . '" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
				. ( 'half' === $fill ? '<defs><linearGradient id="rtpk-half-' . $i . '"><stop offset="50%" stop-color="currentColor"/><stop offset="50%" stop-color="transparent"/></linearGradient></defs>' : '' )
				. '<path d="M12 2.5l2.9 6.1 6.6.8-4.9 4.6 1.3 6.6L12 17.3l-5.9 3.3 1.3-6.6L2.5 9.4l6.6-.8z" fill="' . ( 'full' === $fill ? 'currentColor' : ( 'half' === $fill ? 'url(#rtpk-half-' . $i . ')' : 'none' ) ) . '" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>'
				. '</svg>';
		}
		return $html;
	}

	/**
	 * Inline SVG icons (24×24, stroke = currentColor). No icon-font dependency.
	 */
	public static function icon( string $name ): string {
		$paths = [
			'home'    => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v10h13V10"/><path d="M10 20v-6h4v6"/>',
			'user'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>',
			'shield'  => '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.2 7.5 9.5 4.3-1.3 7.5-4.9 7.5-9.5V6z"/><path d="m9 12 2 2 4-4"/>',
			'star'    => '<path d="M12 3.5l2.6 5.4 5.9.8-4.3 4.1 1.1 5.9L12 16.9l-5.3 2.8 1.1-5.9L3.5 9.7l5.9-.8z"/>',
			'heart'   => '<path d="M12 20.5s-7.5-4.6-7.5-10A4 4 0 0 1 12 8a4 4 0 0 1 7.5 2.5c0 5.4-7.5 10-7.5 10z"/>',
			'comment' => '<path d="M4 5.5h16v10H9l-5 4z"/>',
			'folder'  => '<path d="M3.5 6.5A1.5 1.5 0 0 1 5 5h4l2 2h8a1.5 1.5 0 0 1 1.5 1.5v9A1.5 1.5 0 0 1 19 19H5a1.5 1.5 0 0 1-1.5-1.5z"/><path d="M12 11v5"/><path d="m9.5 13.5 2.5 2.5 2.5-2.5"/>',
			'logout'  => '<path d="M10 4H5v16h5"/><path d="M14 8l4 4-4 4"/><path d="M18 12H9"/>',
			'chevron' => '<path d="m9 6 6 6-6 6"/>',
			'car'     => '<path d="M5 16.5V12l1.8-4.6A2 2 0 0 1 8.7 6h6.6a2 2 0 0 1 1.9 1.4L19 12v4.5"/><path d="M3.5 16.5h17"/><path d="M5 12h14"/><path d="M6.5 16.5V19M17.5 16.5V19"/><circle cx="8" cy="14.2" r=".7" fill="currentColor"/><circle cx="16" cy="14.2" r=".7" fill="currentColor"/>',
			'tire'    => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="3"/><path d="M12 3.5v5M12 15.5v5M3.5 12h5M15.5 12h5"/>',
			'dot'     => '<circle cx="12" cy="12" r="3" fill="currentColor"/>',
		];
		$path  = $paths[ $name ] ?? $paths['dot'];
		return '<svg class="rtpk-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}
}
