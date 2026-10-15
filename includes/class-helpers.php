<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small static helpers shared across the plugin.
 */
final class Helpers {

	/**
	 * RFC 4648 §5 base64url encoding without padding.
	 */
	public static function base64url_encode( string $binary ): string {
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Strict base64url decoding. Returns null on malformed input.
	 */
	public static function base64url_decode( string $value ): ?string {
		if ( '' === $value || ! preg_match( '/^[A-Za-z0-9_-]+=*$/', $value ) ) {
			return null;
		}
		$value = rtrim( $value, '=' );
		$value = strtr( $value, '-_', '+/' );
		$pad   = strlen( $value ) % 4;
		if ( 1 === $pad ) {
			return null;
		}
		if ( $pad > 0 ) {
			$value .= str_repeat( '=', 4 - $pad );
		}
		$decoded = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return false === $decoded ? null : $decoded;
	}

	/**
	 * Client IP used for rate limiting. Only REMOTE_ADDR is trusted by default;
	 * sites behind a proxy can override via the filter.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filter the client IP used for passkey rate limiting.
		 *
		 * @param string $ip Detected IP address.
		 */
		$ip = (string) apply_filters( 'riviantrackr_passkeys_client_ip', $ip );
		return '' !== $ip ? $ip : '0.0.0.0';
	}

	/**
	 * Normalise a URL or origin to "scheme://host[:port]" in lowercase.
	 * Returns null if the value is not a usable origin.
	 */
	public static function normalize_origin( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return null;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		$origin = $scheme . '://' . $host;
		if ( ! empty( $parts['port'] ) ) {
			$default = 'https' === $scheme ? 443 : 80;
			if ( (int) $parts['port'] !== $default ) {
				$origin .= ':' . (int) $parts['port'];
			}
		}
		return $origin;
	}

	/**
	 * The site's own origin, derived from the home URL.
	 */
	public static function site_origin(): string {
		return self::normalize_origin( home_url( '/' ) ) ?? '';
	}

	/**
	 * Default relying party ID: the site host without port.
	 */
	public static function default_rp_id(): string {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Validate a candidate relying party ID against the site host.
	 * A valid RP ID is the site host itself or a registrable parent domain.
	 */
	public static function is_valid_rp_id( string $rp_id, string $site_host ): bool {
		$rp_id     = strtolower( trim( $rp_id ) );
		$site_host = strtolower( trim( $site_host ) );
		if ( '' === $rp_id || '' === $site_host ) {
			return false;
		}
		if ( ! preg_match( '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $rp_id ) ) {
			return false;
		}
		if ( $rp_id === $site_host ) {
			return true;
		}
		// Must be a parent domain with at least two labels (no bare TLDs).
		return str_ends_with( $site_host, '.' . $rp_id ) && substr_count( $rp_id, '.' ) >= 1;
	}

	/**
	 * Current time in MySQL format (UTC).
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Best-effort authenticator label from an AAGUID (well-known passkey providers).
	 */
	public static function provider_from_aaguid( ?string $aaguid ): string {
		$known = [
			'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => 'iCloud Keychain',
			'dd4ec289-e01d-41c9-bb89-70fa845d4bf2' => 'iCloud Keychain',
			'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => 'Google Password Manager',
			'adce0002-35bc-c60a-648b-0b25f1f05503' => 'Chrome on Mac',
			'08987058-cadc-4b81-b6e1-30de50dcbe96' => 'Windows Hello',
			'9ddd1817-af5a-4672-a2b9-3e3dd95000a9' => 'Windows Hello',
			'6028b017-b1d4-4c02-b4b3-afcdafc96bb2' => 'Windows Hello',
			'bada5566-a7aa-401f-bd96-45619a55120d' => '1Password',
			'd548826e-79b4-db40-a3d8-11116f7e8349' => 'Bitwarden',
			'b84e4048-15dc-4dd0-8640-f4f60813c8af' => 'NordPass',
			'0ea242b4-43c4-4a1b-8b17-dd6d0b6baec6' => 'Keeper',
			'f3809540-7f14-49c1-a8b3-8f813b225541' => 'Enpass',
			'531126d6-e717-415c-9320-3d9aa6981239' => 'Dashlane',
			'53414d53-554e-4700-0000-000000000000' => 'Samsung Pass',
			'771b48fd-d3d4-4f74-9232-fc157ab0507a' => 'Edge on Mac',
			'cb69481e-8ff7-4039-93ec-0a2729a154a8' => 'YubiKey 5',
			'ee882879-721c-4913-9775-3dfcce97072a' => 'YubiKey 5',
			'fa2b99dc-9e39-4257-8f92-4a30d23c4118' => 'YubiKey 5 NFC',
			'2fc0579f-8113-47ea-b116-bb5a8db9202a' => 'YubiKey 5 NFC',
			'c5ef55ff-ad9a-4b9f-b580-adebafe026d0' => 'YubiKey 5Ci',
			'd8522d9f-575b-4866-88a9-ba99fa02f35b' => 'YubiKey Bio',
		];
		if ( ! $aaguid ) {
			return '';
		}
		return $known[ strtolower( $aaguid ) ] ?? '';
	}
}
