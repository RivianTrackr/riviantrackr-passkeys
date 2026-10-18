<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin options with defaults, validation and derived values.
 */
final class Settings {

	public const OPTION = RTPK_OPTION_NAME;

	/** @var array<string,mixed>|null */
	private ?array $cache = null;

	/**
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return [
			'rp_name'           => (string) get_bloginfo( 'name' ),
			'rp_id'             => '',
			'user_verification' => 'preferred',
			'login_button'      => true,
			'conditional_ui'    => true,
			'timeout'           => 60,
			'allowed_origins'   => '',
			'preserve_data'     => false,
			'login_page_id'     => 0,
			'register_page_id'  => 0,
			'reset_page_id'     => 0,
			'redirect_wp_login' => false,
			'brand_wp_login'    => false,
			'account_page_id'   => 0,
			'hide_admin_bar'    => true,
			'account_menu_item' => false,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = array_merge( $this->defaults(), is_array( $stored ) ? $stored : [] );
		}
		return $this->cache;
	}

	/**
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	public function flush(): void {
		$this->cache = null;
	}

	public function rp_name(): string {
		$name = trim( (string) $this->get( 'rp_name' ) );
		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}

	public function rp_id(): string {
		$rp_id = strtolower( trim( (string) $this->get( 'rp_id' ) ) );
		return '' !== $rp_id ? $rp_id : Helpers::default_rp_id();
	}

	public function timeout(): int {
		return (int) $this->get( 'timeout' );
	}

	/**
	 * "required" or "preferred".
	 */
	public function user_verification(): string {
		return 'required' === $this->get( 'user_verification' ) ? 'required' : 'preferred';
	}

	public function requires_user_verification(): bool {
		return 'required' === $this->user_verification();
	}

	/**
	 * Exact origins allowed in clientDataJSON.origin.
	 *
	 * @return string[]
	 */
	public function allowed_origins(): array {
		$origins = [ Helpers::site_origin() ];
		foreach ( preg_split( '/[\r\n,\s]+/', (string) $this->get( 'allowed_origins' ) ) ?: [] as $line ) {
			$normalized = Helpers::normalize_origin( $line );
			if ( $normalized ) {
				$origins[] = $normalized;
			}
		}
		/**
		 * Filter the list of origins accepted during passkey ceremonies.
		 *
		 * @param string[] $origins Normalised "scheme://host[:port]" strings.
		 */
		$origins = (array) apply_filters( 'riviantrackr_passkeys_allowed_origins', $origins );
		return array_values( array_unique( array_filter( array_map( 'strval', $origins ) ) ) );
	}

	// ---- custom login pages ---------------------------------------------

	/**
	 * Permalink of the owner's sign-in page, or '' when none is chosen or
	 * the page is no longer published.
	 */
	public function login_page_url(): string {
		return $this->page_url( (int) $this->get( 'login_page_id' ) );
	}

	/**
	 * Permalink of the owner's join (signup) page, or ''.
	 */
	public function register_page_url(): string {
		return $this->page_url( (int) $this->get( 'register_page_id' ) );
	}

	/**
	 * Permalink of the owner's password reset page, or ''.
	 */
	public function reset_page_url(): string {
		return $this->page_url( (int) $this->get( 'reset_page_id' ) );
	}

	/**
	 * Permalink of the member account page, or ''.
	 */
	public function account_page_url(): string {
		return $this->page_url( (int) $this->get( 'account_page_id' ) );
	}

	/**
	 * Whether an account page is chosen (no permalink lookup, safe at plugins_loaded).
	 */
	public function has_account_page(): bool {
		return (int) $this->get( 'account_page_id' ) > 0;
	}

	/**
	 * Hide the admin bar for members without editing rights. Only meaningful
	 * while an account page is set; the toggle itself defaults to on.
	 */
	public function hides_admin_bar(): bool {
		return $this->has_account_page() && ! empty( $this->get( 'hide_admin_bar' ) );
	}

	/**
	 * Add an "Account" item to the main navigation menu for signed-in members.
	 */
	public function adds_account_menu_item(): bool {
		return $this->has_account_page() && ! empty( $this->get( 'account_menu_item' ) );
	}

	/**
	 * Whether wp-login.php's login/register/reset screens should be sent to
	 * the custom pages. Cheap enough to call at plugins_loaded: no permalink
	 * lookup, just the stored toggle and page IDs.
	 */
	public function wants_wp_login_redirect(): bool {
		return ! empty( $this->get( 'redirect_wp_login' ) ) && ( (int) $this->get( 'login_page_id' ) > 0 || (int) $this->get( 'reset_page_id' ) > 0 );
	}

	/**
	 * Whether the remaining wp-login.php screens get the ink & brass skin.
	 */
	public function brands_wp_login(): bool {
		return ! empty( $this->get( 'brand_wp_login' ) );
	}

	private function page_url( int $page_id ): string {
		// get_permalink() needs $wp_rewrite, which does not exist yet at plugins_loaded.
		if ( $page_id <= 0 || empty( $GLOBALS['wp_rewrite'] ) || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}
		$url = get_permalink( $page_id );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * A submitted page ID is kept only if it points at a published page.
	 */
	private function published_page_id( $raw, string $code, string $label ): int {
		$id = absint( $raw );
		if ( 0 === $id ) {
			return 0;
		}
		if ( 'publish' !== get_post_status( $id ) ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					self::OPTION,
					$code,
					sprintf(
						/* translators: %s: setting label */
						__( 'The %s must be a published page. The selection was cleared.', 'riviantrackr-passkeys' ),
						$label
					)
				);
			}
			return 0;
		}
		return $id;
	}

	/**
	 * Settings API sanitizer.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : [];
		$defaults = $this->defaults();
		$out      = [];

		$out['rp_name'] = sanitize_text_field( (string) ( $input['rp_name'] ?? '' ) );
		if ( '' === $out['rp_name'] ) {
			$out['rp_name'] = $defaults['rp_name'];
		}

		$rp_id = strtolower( sanitize_text_field( (string) ( $input['rp_id'] ?? '' ) ) );
		if ( '' !== $rp_id && ! Helpers::is_valid_rp_id( $rp_id, Helpers::default_rp_id() ) ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					self::OPTION,
					'rtpk_rp_id',
					__( 'The relying party ID must be this site\'s domain or one of its parent domains. It was reset to the default.', 'riviantrackr-passkeys' )
				);
			}
			$rp_id = '';
		}
		$out['rp_id'] = $rp_id;

		$uv                       = (string) ( $input['user_verification'] ?? 'preferred' );
		$out['user_verification'] = in_array( $uv, [ 'required', 'preferred' ], true ) ? $uv : 'preferred';

		$out['login_button']   = ! empty( $input['login_button'] );
		$out['conditional_ui'] = ! empty( $input['conditional_ui'] );
		$out['preserve_data']  = ! empty( $input['preserve_data'] );

		$timeout        = (int) ( $input['timeout'] ?? $defaults['timeout'] );
		$out['timeout'] = max( 30, min( 300, $timeout ) );

		$origins = [];
		foreach ( preg_split( '/[\r\n,\s]+/', (string) ( $input['allowed_origins'] ?? '' ) ) ?: [] as $line ) {
			$normalized = Helpers::normalize_origin( sanitize_text_field( $line ) );
			if ( $normalized ) {
				$origins[] = $normalized;
			}
		}
		$out['allowed_origins'] = implode( "\n", array_unique( $origins ) );

		$out['login_page_id']     = $this->published_page_id( $input['login_page_id'] ?? 0, 'rtpk_login_page', __( 'sign-in page', 'riviantrackr-passkeys' ) );
		$out['register_page_id']  = $this->published_page_id( $input['register_page_id'] ?? 0, 'rtpk_register_page', __( 'join page', 'riviantrackr-passkeys' ) );
		$out['reset_page_id']     = $this->published_page_id( $input['reset_page_id'] ?? 0, 'rtpk_reset_page', __( 'password reset page', 'riviantrackr-passkeys' ) );
		$out['redirect_wp_login'] = ! empty( $input['redirect_wp_login'] );
		$out['brand_wp_login']    = ! empty( $input['brand_wp_login'] );
		$out['account_page_id']   = $this->published_page_id( $input['account_page_id'] ?? 0, 'rtpk_account_page', __( 'account page', 'riviantrackr-passkeys' ) );
		$out['hide_admin_bar']    = ! empty( $input['hide_admin_bar'] );
		$out['account_menu_item'] = ! empty( $input['account_menu_item'] );

		if ( $out['redirect_wp_login'] && 0 === $out['login_page_id'] && 0 === $out['reset_page_id'] && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION,
				'rtpk_redirect_needs_page',
				__( 'Choose a published sign-in page for “Send wp-login.php visitors to these pages” to take effect. Until then wp-login.php keeps showing the standard form.', 'riviantrackr-passkeys' ),
				'warning'
			);
		}

		$this->flush();
		return $out;
	}
}
