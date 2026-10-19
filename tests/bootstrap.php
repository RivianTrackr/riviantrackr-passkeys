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
define( 'RTPK_SIGNUP_IP_RATE_LIMIT', 5 );
define( 'RTPK_SIGNUP_EMAIL_RATE_LIMIT', 3 );
define( 'RTPK_SIGNUP_MIN_SECONDS', 3 );
define( 'RTPK_RESET_RATE_LIMIT', 5 );
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'COOKIEHASH' ) ) {
	define( 'COOKIEHASH', md5( 'https://riviantrackr.test' ) );
}

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
	$GLOBALS['rtpk_test_users']      = [];
	$GLOBALS['rtpk_test_mail']       = [];
	$GLOBALS['rtpk_test_filters']     = [];
	$GLOBALS['rtpk_test_attachments'] = [];
	$GLOBALS['rtpk_test_theme_mods']  = [];
	$GLOBALS['rtpk_test_user_emails']   = [];
	$GLOBALS['rtpk_test_usernames']     = [];
	$GLOBALS['rtpk_test_inserted']      = [];
	$GLOBALS['rtpk_test_remote']        = [];
	$GLOBALS['rtpk_test_remote_reply']  = null;
	$GLOBALS['rtpk_test_roles']         = [ 'subscriber', 'contributor', 'author', 'editor', 'administrator' ];
	$GLOBALS['rtpk_test_actions']       = [];
	$GLOBALS['rtpk_test_filter_hooks']  = [];
	$GLOBALS['rtpk_test_notifications'] = [];
	$GLOBALS['rtpk_test_mail_ok']       = true;
	$GLOBALS['rtpk_test_posts']         = [];
	$GLOBALS['rtpk_test_current_user']  = null;
	$GLOBALS['rtpk_test_reset_result']  = true;
	$GLOBALS['rtpk_test_reset_calls']   = [];
	$GLOBALS['rtpk_test_updated_users'] = [];
	$GLOBALS['rtpk_test_privacy_requests'] = [];
	$GLOBALS['rtpk_test_deleted_users']    = [];
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
function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['rtpk_test_usermeta'][ $user_id ][ $key ] );
	return true;
}

/* ------------------------------------------------------------------ users */

$GLOBALS['rtpk_test_users'] = [];
$GLOBALS['rtpk_test_mail']  = [];

function get_user_by( $field, $value ) {
	return $GLOBALS['rtpk_test_users'][ (int) $value ] ?? false;
}
function wp_mail( $to, $subject, $message, $headers = [] ) {
	$GLOBALS['rtpk_test_mail'][] = compact( 'to', 'subject', 'message', 'headers' );
	return $GLOBALS['rtpk_test_mail_ok'] ?? true;
}
function is_email( $email ) {
	return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}
function sanitize_email( $email ) {
	return (string) filter_var( trim( (string) $email ), FILTER_SANITIZE_EMAIL );
}
function absint( $value ) {
	return abs( (int) $value );
}

/* ------------------------------------------------------------ filters/media */

$GLOBALS['rtpk_test_filters']     = [];
$GLOBALS['rtpk_test_attachments'] = [];
$GLOBALS['rtpk_test_theme_mods']  = [];

function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['rtpk_test_filters'][] = [ 'add', $tag, $callback ];
	return true;
}
function remove_filter( $tag, $callback, $priority = 10 ) {
	$GLOBALS['rtpk_test_filters'][] = [ 'remove', $tag, $callback ];
	return true;
}
function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) {
	return $GLOBALS['rtpk_test_attachments'][ (int) $id ] ?? false;
}
function get_theme_mod( $name, $default = false ) {
	return $GLOBALS['rtpk_test_theme_mods'][ $name ] ?? $default;
}
function get_site_icon_url( $size = 512 ) {
	return '';
}

/* ------------------------------------------------------------- sanitizers */

function sanitize_text_field( $str ) {
	$str = (string) $str;
	$str = strip_tags( $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( (string) $str );
}
function sanitize_title( $title ) {
	$title = strtolower( strip_tags( (string) $title ) );
	$title = preg_replace( '/[^a-z0-9 _-]/', '', $title );
	return trim( preg_replace( '/[\s_-]+/', '-', $title ), '-' );
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
function wp_strip_all_tags( $str ) {
	return trim( strip_tags( (string) $str ) );
}
function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}
function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function add_query_arg( ...$args ) {
	if ( is_array( $args[0] ) ) {
		$params = $args[0];
		$url    = $args[1];
	} else {
		$params = [ $args[0] => $args[1] ];
		$url    = $args[2];
	}
	// Like WordPress, values are appended as given (callers encode them).
	$pairs = [];
	foreach ( $params as $key => $value ) {
		$pairs[] = $key . '=' . $value;
	}
	$sep = str_contains( $url, '?' ) ? '&' : '?';
	return $url . $sep . implode( '&', $pairs );
}
function site_url( $path = '', $scheme = null ) {
	return 'https://riviantrackr.test/' . ltrim( (string) $path, '/' );
}
function wp_login_url( $redirect = '' ) {
	$url = 'https://riviantrackr.test/wp-login.php';
	return '' !== $redirect ? $url . '?redirect_to=' . rawurlencode( $redirect ) : $url;
}
function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
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
	$hook = $GLOBALS['rtpk_test_filter_hooks'][ $tag ] ?? null;
	return $hook ? $hook( $value, ...$args ) : $value;
}
function do_action( $tag, ...$args ) {
	$GLOBALS['rtpk_test_actions'][] = [ $tag, $args ];
}
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['rtpk_test_errors'][] = compact( 'setting', 'code', 'message', 'type' );
}

/* ---------------------------------------------------------- users/signup */

$GLOBALS['rtpk_test_user_emails']   = [];
$GLOBALS['rtpk_test_usernames']     = [];
$GLOBALS['rtpk_test_inserted']      = [];
$GLOBALS['rtpk_test_remote']        = [];
$GLOBALS['rtpk_test_remote_reply']  = null;
$GLOBALS['rtpk_test_roles']         = [ 'subscriber', 'contributor', 'author', 'editor', 'administrator' ];
$GLOBALS['rtpk_test_actions']       = [];
$GLOBALS['rtpk_test_filter_hooks']  = [];
$GLOBALS['rtpk_test_notifications'] = [];
$GLOBALS['rtpk_test_mail_ok']       = true;

function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-' . $scheme;
}
function wp_hash( $data, $scheme = 'auth' ) {
	return hash_hmac( 'md5', (string) $data, wp_salt( $scheme ) );
}
function email_exists( $email ) {
	return $GLOBALS['rtpk_test_user_emails'][ strtolower( (string) $email ) ] ?? false;
}
function username_exists( $login ) {
	return $GLOBALS['rtpk_test_usernames'][ strtolower( (string) $login ) ] ?? false;
}
function sanitize_user( $username, $strict = false ) {
	$username = strip_tags( (string) $username );
	$username = preg_replace( '/[\x00-\x1f\x7f]+|%[a-f0-9]{2}/i', '', $username );
	if ( $strict ) {
		$username = preg_replace( '|[^a-z0-9 _.\-@]|i', '', $username );
	}
	return trim( preg_replace( '|\s+|', ' ', $username ) );
}
function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) );
}
function get_role( $role ) {
	return in_array( $role, $GLOBALS['rtpk_test_roles'], true ) ? (object) [ 'name' => $role ] : null;
}
function wp_insert_user( $userdata ) {
	if ( username_exists( $userdata['user_login'] ) ) {
		return new WP_Error( 'existing_user_login', 'Sorry, that username already exists!' );
	}
	if ( email_exists( $userdata['user_email'] ) ) {
		return new WP_Error( 'existing_user_email', 'Sorry, that email address is already used!' );
	}
	$id   = 100 + count( $GLOBALS['rtpk_test_inserted'] );
	$user = new WP_User( $id, $userdata['user_login'], $userdata['display_name'] ?? '', $userdata['user_email'] );

	$GLOBALS['rtpk_test_inserted'][ $id ]                                       = $userdata;
	$GLOBALS['rtpk_test_users'][ $id ]                                          = $user;
	$GLOBALS['rtpk_test_user_emails'][ strtolower( $userdata['user_email'] ) ] = $id;
	$GLOBALS['rtpk_test_usernames'][ strtolower( $userdata['user_login'] ) ]   = $id;
	do_action( 'user_register', $id, $userdata );
	return $id;
}
function wp_new_user_notification( $user_id, $deprecated = null, $notify = '' ) {
	$GLOBALS['rtpk_test_notifications'][] = [ $user_id, $notify ];
}
function wp_remote_post( $url, $args = [] ) {
	$GLOBALS['rtpk_test_remote'][] = [ $url, $args ];
	return $GLOBALS['rtpk_test_remote_reply'] ?? [ 'code' => 200, 'body' => '{"success":true}' ];
}
function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? (int) ( $response['code'] ?? 0 ) : 0;
}
function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
}

/* --------------------------------------------------------- password reset */

$GLOBALS['rtpk_test_reset_result'] = true; // What retrieve_password() returns.
$GLOBALS['rtpk_test_reset_calls']  = [];

function retrieve_password( $user_login = null ) {
	$GLOBALS['rtpk_test_reset_calls'][] = $user_login;
	return $GLOBALS['rtpk_test_reset_result'] ?? true;
}

/* ------------------------------------------------------------------ posts */

$GLOBALS['rtpk_test_posts'] = [];
$GLOBALS['wp_rewrite']      = new stdClass(); // get_permalink() guard in Settings::page_url().

function get_post_status( $post = null ) {
	return $GLOBALS['rtpk_test_posts'][ (int) $post ]['status'] ?? false;
}
function get_permalink( $post = 0 ) {
	return $GLOBALS['rtpk_test_posts'][ (int) $post ]['url'] ?? false;
}

/* ---------------------------------------------------------------- account */

function admin_url( $path = '' ) {
	return 'https://riviantrackr.test/wp-admin/' . ltrim( (string) $path, '/' );
}
function wp_check_password( $password, $hash, $user_id = 0 ) {
	return hash_equals( (string) $hash, 'hashed:' . $password );
}
function is_user_logged_in() {
	return ! empty( $GLOBALS['rtpk_test_current_user'] );
}
function wp_get_current_user() {
	return $GLOBALS['rtpk_test_current_user'] ?? new WP_User( 0, '', '', '' );
}
function get_current_user_id() {
	return isset( $GLOBALS['rtpk_test_current_user'] ) ? (int) $GLOBALS['rtpk_test_current_user']->ID : 0;
}

/* --------------------------------------------------------- email change */

$GLOBALS['rtpk_test_updated_users'] = [];

function wp_update_user( $userdata ) {
	$userdata = (array) $userdata;
	$id       = (int) ( $userdata['ID'] ?? 0 );
	$GLOBALS['rtpk_test_updated_users'][] = $userdata;
	if ( isset( $GLOBALS['rtpk_test_users'][ $id ] ) ) {
		foreach ( $userdata as $key => $value ) {
			if ( 'ID' !== $key && property_exists( $GLOBALS['rtpk_test_users'][ $id ], $key ) ) {
				$GLOBALS['rtpk_test_users'][ $id ]->$key = $value;
			}
		}
	}
	return $id;
}
function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) {
	return html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function wp_rand( $min = 0, $max = 0 ) {
	return random_int( (int) $min, $max > 0 ? (int) $max : PHP_INT_MAX );
}
function network_site_url( $path = '', $scheme = null ) {
	return 'https://riviantrackr.test/' . ltrim( (string) $path, '/' );
}
function human_time_diff( $from, $to = 0 ) {
	$diff = abs( (int) $to - (int) $from );
	if ( $diff >= DAY_IN_SECONDS ) {
		$n = (int) round( $diff / DAY_IN_SECONDS );
		return $n . ( 1 === $n ? ' day' : ' days' );
	}
	$n = max( 1, (int) round( $diff / HOUR_IN_SECONDS ) );
	return $n . ( 1 === $n ? ' hour' : ' hours' );
}

/* ----------------------------------------------------------- privacy */

$GLOBALS['rtpk_test_privacy_requests'] = [];
$GLOBALS['rtpk_test_deleted_users']    = [];

function wp_create_user_request( $email, $action, $data = [], $status = 'pending' ) {
	foreach ( $GLOBALS['rtpk_test_privacy_requests'] as $r ) {
		if ( $r['email'] === $email && $r['action'] === $action ) {
			return new WP_Error( 'duplicate_request', 'An incomplete personal data request for this email address already exists.' );
		}
	}
	$GLOBALS['rtpk_test_privacy_requests'][] = compact( 'email', 'action', 'status' );
	return 500 + count( $GLOBALS['rtpk_test_privacy_requests'] );
}
function wp_delete_user( $id, $reassign = null ) {
	$GLOBALS['rtpk_test_deleted_users'][] = (int) $id;
	do_action( 'deleted_user', (int) $id, $reassign, $GLOBALS['rtpk_test_users'][ (int) $id ] ?? null );
	unset( $GLOBALS['rtpk_test_users'][ (int) $id ] );
	return true;
}
function wp_logout() {
	$GLOBALS['rtpk_test_current_user'] = null;
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
	public string $user_email;
	public string $user_pass = '';
	public string $user_url = '';
	public string $user_registered = '2026-01-01 00:00:00';
	public string $first_name = '';
	public string $last_name = '';
	public string $description = '';
	/** @var string[] */
	public array $caps = [];

	public function __construct( int $id, string $login = 'jose', string $display = 'Jose', string $email = 'jose@riviantrackr.test' ) {
		$this->ID           = $id;
		$this->user_login   = $login;
		$this->display_name = $display;
		$this->user_email   = $email;
	}

	public function has_cap( string $cap ): bool {
		return in_array( $cap, $this->caps, true );
	}
}

class WP_Error {
	/** @var array<string,string[]> */
	public array $errors = [];
	/** @var array<string,mixed> */
	public array $error_data = [];

	public function __construct( string $code = '', string $message = '', $data = null ) {
		if ( '' !== $code ) {
			$this->add( $code, $message, $data );
		}
	}
	public function add( string $code, string $message, $data = null ): void {
		$this->errors[ $code ][] = $message;
		if ( null !== $data ) {
			$this->error_data[ $code ] = $data;
		}
	}
	public function has_errors(): bool {
		return [] !== $this->errors;
	}
	public function get_error_codes(): array {
		return array_keys( $this->errors );
	}
	public function get_error_code(): string {
		return (string) ( array_key_first( $this->errors ) ?? '' );
	}
	public function get_error_message( string $code = '' ): string {
		if ( '' === $code ) {
			$code = $this->get_error_code();
		}
		return (string) ( $this->errors[ $code ][0] ?? '' );
	}
	public function get_error_data( string $code = '' ) {
		if ( '' === $code ) {
			$code = $this->get_error_code();
		}
		return $this->error_data[ $code ] ?? null;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
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
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
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
require_once __DIR__ . '/FakePendingSignupStore.php';
