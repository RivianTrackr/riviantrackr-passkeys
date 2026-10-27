<?php
/**
 * Stand-in for Really Simple Security's two-factor class: same namespace,
 * class and method name, registered the same way (array( __CLASS__, … )),
 * so the test proves SecondFactor::known_hooks() matches the real handler.
 */

declare(strict_types=1);

namespace RSSSL\Security\WordPress\Two_Fa;

final class Rsssl_Two_Factor {

	/** @var string[] Logins the handler saw. */
	public static array $seen = [];

	public static function register(): void {
		self::$seen = [];
		add_action( 'wp_login', array( __CLASS__, 'rsssl_wp_login' ), 10, 2 );
	}

	public static function unregister(): void {
		remove_action( 'wp_login', array( __CLASS__, 'rsssl_wp_login' ), 10 );
	}

	public static function rsssl_wp_login( string $user_login, \WP_User $user ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Hook signature.
		self::$seen[] = $user_login;
	}
}
