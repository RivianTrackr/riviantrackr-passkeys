<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSR-4-style autoloader for the plugin's own classes and the bundled
 * lbuchs\WebAuthn library.
 *
 * RivianTrackr\Passkeys\CredentialStore → includes/class-credential-store.php
 * lbuchs\WebAuthn\Binary\ByteBuffer      → lib/lbuchs-webauthn/Binary/ByteBuffer.php
 */
final class Autoloader {

	private static string $includes_dir = '';
	private static string $lib_dir      = '';

	public static function register( string $includes_dir, string $lib_dir ): void {
		self::$includes_dir = rtrim( $includes_dir, '/' ) . '/';
		self::$lib_dir      = rtrim( $lib_dir, '/' ) . '/';
		spl_autoload_register( [ self::class, 'autoload' ] );
	}

	public static function autoload( string $class_name ): void {
		$plugin_prefix = 'RivianTrackr\\Passkeys\\';
		$lib_prefix    = 'lbuchs\\WebAuthn\\';

		if ( str_starts_with( $class_name, $plugin_prefix ) ) {
			$relative  = substr( $class_name, strlen( $plugin_prefix ) );
			$file_name = strtolower( (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $relative ) );
			$file      = self::$includes_dir . 'class-' . str_replace( '\\', '/', $file_name ) . '.php';
		} elseif ( str_starts_with( $class_name, $lib_prefix ) ) {
			$relative = substr( $class_name, strlen( $lib_prefix ) );
			$file     = self::$lib_dir . str_replace( '\\', '/', $relative ) . '.php';
		} else {
			return;
		}

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}
