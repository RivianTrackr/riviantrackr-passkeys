<?php
/**
 * PHPUnit bootstrap: minimal WordPress stubs so the plugin classes can be
 * exercised without a WordPress install.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

define( 'RTPK_VERSION', '1.0.0-test' );
define( 'RTPK_FILE', dirname( __DIR__ ) . '/riviantrackr-passkeys.php' );
define( 'RTPK_DIR', dirname( __DIR__ ) . '/' );
define( 'RTPK_URL', 'https://riviantrackr.test/wp-content/plugins/riviantrackr-passkeys/' );
define( 'RTPK_ASSET_SUFFIX', '' );
define( 'RTPK_DB_VERSION', '1' );
define( 'RTPK_OPTION_NAME', 'riviantrackr_passkeys_options' );
define( 'RTPK_REST_NAMESPACE', 'riviantrackr-passkeys/v1' );
define( 'RTPK_CHALLENGE_TTL', 300 );
define( 'RTPK_MAX_PASSKEYS_PER_USER', 10 );
define( 'RTPK_LOGIN_RATE_LIMIT', 10 );
define( 'RTPK_OPTIONS_RATE_LIMIT', 30 );
define( 'RTPK_REGISTER_RATE_LIMIT', 10 );
define( 'RTPK_NAME_MAX_LENGTH', 60 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';
RivianTrackr\Passkeys\Autoloader::register( dirname( __DIR__ ) . '/includes', dirname( __DIR__ ) . '/lib/lbuchs-webauthn' );

/* ------------------------------------------------------------------ state */

$GLOBALS['rtpk_test_options']    = [];
$GLOBALS['rtpk_test_transients'] = [];
$GLOBALS['rtpk_test_usermeta']   = [];
$GLOBALS['rtpk_test_errors']     = [];
$GLOBALS['rtpk_test_now']        = null; // Override time() via rtpk_test_time().

function rtpk_test_reset(): void {
	$GLOBALS['rtpk_test_options']    = [];
	$GLOBALS['rtpk_test_transients'] = [];
	$GLOBALS['rtpk_test_usermeta']   = [];
	$GLOBALS['rtpk_test_errors']     = [];
}

/* ---------------------------------------------------------------- options */

function get_option( $name, $default = false ) {
	return $GLOBALS['rtpk_test_options'][ $name ] ?? $default;
}
function update_option( $name, $value ) {
	$GLOBALS['rtpk_test_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['rtpk_test_options'][ $name ] );
	return true;
}

/* ------------------------------------------------------------- transients */

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['rtpk_test_transients'][ $key ] = [ 'value' => $value, 'expires' => $ttl > 0 ? time() + $ttl : 0 ];
	return true;
}
function get_transient( $key ) {
	$row = $GLOBALS['rtpk_test_transients'][ $key ] ?? null;
	if ( null === $row ) {
		return false;
	}
	if ( $row['expires'] && $row['expires'] <= time() ) {
		unset( $GLOBALS['rtpk_test_transients'][ $key ] );
		return false;
	}
	return $row['value'];
}
function delete_transient( $key ) {
	unset( $GLOBALS['rtpk_test_transients'][ $key ] );
	return true;
}

/* -------------------------------------------------------------- user meta */

function get_user_meta( $user_id, $key, $single = false ) {
	return $GLOBALS['rtpk_test_usermeta'][ $user_id ][ $key ] ?? '';
}
function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['rtpk_test_usermeta'][ $user_id ][ $key ] = $value;
	return true;
}

/* ------------------------------------------------------------- sanitizers */

function sanitize_text_field( $str ) {
	$str = (string) $str;
	$str = strip_tags( $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( (string) $str );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function esc_url_raw( $url ) {
	return (string) $url;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}
function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}

/* ------------------------------------------------------------------ i18n */

function __( $text, $domain = null ) {
	return $text;
}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/* ------------------------------------------------------------------ hooks */

function apply_filters( $tag, $value, ...$args ) {
	return $value;
}
function do_action( $tag, ...$args ) {}
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['rtpk_test_errors'][] = compact( 'setting', 'code', 'message', 'type' );
}

/* -------------------------------------------------------------- site info */

function home_url( $path = '' ) {
	return 'https://riviantrackr.test' . $path;
}
function get_bloginfo( $show = '' ) {
	return 'name' === $show ? 'RivianTrackr' : '';
}

/* --------------------------------------------------------------- classes */

class WP_User {
	public int $ID;
	public string $user_login;
	public string $display_name;

	public function __construct( int $id, string $login = 'jose', string $display = 'Jose' ) {
		$this->ID           = $id;
		$this->user_login   = $login;
		$this->display_name = $display;
	}
}

/**
 * Minimal $wpdb stand-in: the credential store is not exercised against a
 * real database here, but WebAuthnService::registration_options() needs an
 * empty credential list for the exclude set.
 */
class RTPK_Fake_Wpdb {
	public string $prefix = 'wp_';
	public int $insert_id = 0;

	public function prepare( $query, ...$args ) {
		return $query;
	}
	public function get_row( $query, $output = null ) {
		return null;
	}
	public function get_results( $query, $output = null ) {
		return [];
	}
	public function get_var( $query ) {
		return 0;
	}
	public function insert( $table, $data, $format = null ) {
		$this->insert_id++;
		return 1;
	}
	public function update( ...$args ) {
		return 1;
	}
	public function delete( ...$args ) {
		return 1;
	}
}
$GLOBALS['wpdb'] = new RTPK_Fake_Wpdb();

require_once __DIR__ . '/FakeAuthenticator.php';
