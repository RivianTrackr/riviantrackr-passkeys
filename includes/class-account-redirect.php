<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends members without editing rights to the account page instead of the
 * wp-admin dashboard after signing in, optionally hides the admin bar for
 * them and adds an "Account" item to the main navigation menu.
 */
final class AccountRedirect {

	public function __construct( private Settings $settings ) {}

	public function register_hooks(): void {
		if ( ! $this->settings->has_account_page() ) {
			return;
		}
		add_filter( 'login_redirect', [ $this, 'filter_login_redirect' ], 10, 3 );
		if ( $this->settings->hides_admin_bar() ) {
			add_filter( 'show_admin_bar', [ $this, 'filter_show_admin_bar' ], 20 );
		}
		if ( $this->settings->adds_account_menu_item() ) {
			add_filter( 'wp_nav_menu_items', [ $this, 'filter_menu_items' ], 10, 2 );
		}
	}

	// ---- decision --------------------------------------------------------

	/**
	 * Where a member lands after signing in. Pure, so it can be tested.
	 *
	 * Members who can edit posts keep WordPress's behaviour. Everyone else
	 * is sent to the account page whenever the destination would otherwise
	 * be the admin area (no redirect requested, the dashboard, or any
	 * wp-admin URL); an explicit front-end redirect_to is honoured.
	 *
	 * @param string $redirect_to    Destination so far (after earlier filters).
	 * @param bool   $can_edit_posts Whether the user has the edit_posts capability.
	 * @param string $account_url    Account page URL, or '' when none is set.
	 * @param string $admin_url      The site's wp-admin URL (admin_url()).
	 */
	public static function target( string $redirect_to, bool $can_edit_posts, string $account_url, string $admin_url ): string {
		$redirect_to = trim( $redirect_to );
		if ( '' === $account_url || $can_edit_posts ) {
			return $redirect_to;
		}
		if ( '' === $redirect_to || 'wp-admin/' === $redirect_to || self::is_admin_url( $redirect_to, $admin_url ) ) {
			return $account_url;
		}
		return $redirect_to;
	}

	/**
	 * Whether a URL points into the admin area (absolute or site-relative).
	 */
	public static function is_admin_url( string $url, string $admin_url ): bool {
		$url       = strtolower( $url );
		$admin_url = strtolower( rtrim( $admin_url, '/' ) );
		if ( '' !== $admin_url && str_starts_with( $url, $admin_url ) ) {
			return true;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return str_starts_with( $path, '/wp-admin' ) || str_starts_with( ltrim( $path, '/' ), 'wp-admin' );
	}

	/**
	 * Whether the "Account" item belongs in this menu: the first registered
	 * theme location (the main menu on nearly every theme), or any menu
	 * when the theme registers none.
	 *
	 * @param string               $location   The menu's theme_location.
	 * @param array<string,string> $registered get_registered_nav_menus().
	 */
	public static function is_main_menu( string $location, array $registered ): bool {
		if ( [] === $registered ) {
			return true;
		}
		return (string) array_key_first( $registered ) === $location;
	}

	// ---- hooks -----------------------------------------------------------

	/**
	 * @param string                  $redirect_to Destination so far.
	 * @param string                  $requested   Requested redirect_to.
	 * @param \WP_User|\WP_Error|null $user        The user, or an error on failure.
	 */
	public function filter_login_redirect( $redirect_to, $requested = '', $user = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Hook signature.
		if ( ! $user instanceof \WP_User ) {
			return (string) $redirect_to;
		}
		$account = $this->settings->account_page_url();
		return self::target( (string) $redirect_to, $user->has_cap( 'edit_posts' ), $account, admin_url() );
	}

	/**
	 * @param bool $show Whether to show the admin bar.
	 */
	public function filter_show_admin_bar( $show ): bool {
		if ( ! $show || ! is_user_logged_in() || current_user_can( 'edit_posts' ) ) {
			return (bool) $show;
		}
		return false;
	}

	/**
	 * @param string    $items Menu HTML.
	 * @param \stdClass $args  wp_nav_menu() arguments.
	 */
	public function filter_menu_items( $items, $args = null ): string {
		if ( ! is_user_logged_in() ) {
			return (string) $items;
		}
		$location = is_object( $args ) && isset( $args->theme_location ) ? (string) $args->theme_location : '';
		if ( ! self::is_main_menu( $location, (array) get_registered_nav_menus() ) ) {
			return (string) $items;
		}
		$url = $this->settings->account_page_url();
		if ( '' === $url ) {
			return (string) $items;
		}
		$current = is_page( (int) $this->settings->get( 'account_page_id' ) );
		$classes = 'menu-item menu-item-type-post_type menu-item-object-page rtpk-menu-account' . ( $current ? ' current-menu-item' : '' );
		return (string) $items . '<li class="' . esc_attr( $classes ) . '"><a href="' . esc_url( $url ) . '"' . ( $current ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Account', 'riviantrackr-passkeys' ) . '</a></li>';
	}
}
