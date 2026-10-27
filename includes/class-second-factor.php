<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a verified passkey count as the second factor.
 *
 * Two-factor plugins hook wp_login, end the session the password check
 * created and ask for a code. A passkey sign-in where the device confirmed
 * the person (Face ID, Touch ID, Windows Hello, a security key PIN) is
 * already two factors, so for those the known two-factor wp_login handlers
 * are stepped around while wp_login fires and put straight back. Everything
 * else on wp_login still runs, and a passkey without user verification goes
 * through the two-factor plugin as before.
 */
final class SecondFactor {

	/** Filter: whether this passkey sign-in stands in for two-factor. */
	public const FILTER = 'riviantrackr_passkeys_passkey_is_second_factor';

	/** Filter: the wp_login callbacks a verified passkey steps around. */
	public const HOOKS_FILTER = 'riviantrackr_passkeys_two_factor_login_hooks';

	/**
	 * The wp_login handlers of two-factor plugins that end the session to ask
	 * for a code. Really Simple Security 9.x registers its handler as
	 * array( __CLASS__, 'rsssl_wp_login' ) from a namespaced class.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function known_hooks(): array {
		return [
			[ 'RSSSL\\Security\\WordPress\\Two_Fa\\Rsssl_Two_Factor', 'rsssl_wp_login' ],
		];
	}

	/**
	 * Fire wp_login for a passkey sign-in.
	 *
	 * @param \WP_User            $user          The member being signed in.
	 * @param bool                $user_verified Whether the authenticator set the UV flag.
	 * @param array<string,mixed> $row           Credential row.
	 */
	public static function fire_wp_login( \WP_User $user, bool $user_verified, array $row ): void {
		/**
		 * Whether this passkey sign-in counts as the second factor, so
		 * two-factor plugins' wp_login prompts are skipped. Defaults to
		 * whether the device verified the person.
		 *
		 * @param bool                $is_second_factor Default: the UV flag.
		 * @param \WP_User            $user             The member.
		 * @param array<string,mixed> $row              Credential row.
		 */
		$skip = (bool) apply_filters( 'riviantrackr_passkeys_passkey_is_second_factor', $user_verified, $user, $row );

		$removed = [];
		if ( $skip ) {
			/**
			 * The wp_login callbacks a verified passkey steps around.
			 *
			 * @param array<int,callable> $hooks Callbacks as registered with add_action().
			 */
			$hooks = (array) apply_filters( 'riviantrackr_passkeys_two_factor_login_hooks', self::known_hooks() );
			foreach ( $hooks as $hook ) {
				$priority = has_action( 'wp_login', $hook );
				if ( false !== $priority ) {
					remove_action( 'wp_login', $hook, (int) $priority );
					$removed[] = [ $hook, (int) $priority ];
				}
			}
		}

		try {
			/** This action is documented in wp-includes/user.php */
			do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		} finally {
			foreach ( $removed as [ $hook, $priority ] ) {
				add_action( 'wp_login', $hook, $priority, 2 );
			}
		}
	}
}
