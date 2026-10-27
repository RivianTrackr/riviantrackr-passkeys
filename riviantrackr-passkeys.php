<?php
/**
 * Plugin Name: RivianTrackr Passkeys
 * Plugin URI: https://riviantrackr.com
 * Description: Passwordless sign-in with passkeys (WebAuthn / FIDO2). Members can register Face ID, Touch ID, Windows Hello, Android or hardware security keys and log in without typing a password.
 * Version: 1.8.2
 * Author: RivianTrackr
 * Author URI: https://github.com/RivianTrackr/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: riviantrackr-passkeys
 * Domain Path: /languages
 *
 * @package RivianTrackr_Passkeys
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RTPK_VERSION', '1.8.2' );
define( 'RTPK_FILE', __FILE__ );
define( 'RTPK_DIR', plugin_dir_path( __FILE__ ) );
define( 'RTPK_URL', plugin_dir_url( __FILE__ ) );
define( 'RTPK_ASSET_SUFFIX', defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min' );
define( 'RTPK_DB_VERSION', '3' );
define( 'RTPK_OPTION_NAME', 'riviantrackr_passkeys_options' );
define( 'RTPK_REST_NAMESPACE', 'riviantrackr-passkeys/v1' );

// Security tunables.
define( 'RTPK_CHALLENGE_TTL', 300 );          // Seconds a challenge stays valid.
define( 'RTPK_MAX_PASSKEYS_PER_USER', 10 );   // Hard cap per account.
define( 'RTPK_LOGIN_RATE_LIMIT', 10 );        // Login verifications per minute per IP.
define( 'RTPK_OPTIONS_RATE_LIMIT', 30 );      // Challenge requests per minute per IP.
define( 'RTPK_REGISTER_RATE_LIMIT', 10 );     // Registrations per minute per user.
define( 'RTPK_NAME_MAX_LENGTH', 60 );
define( 'RTPK_SIGNUP_IP_RATE_LIMIT', 5 );    // Signup requests per hour per IP.
define( 'RTPK_SIGNUP_EMAIL_RATE_LIMIT', 3 ); // Signup requests per hour per email address.
define( 'RTPK_SIGNUP_MIN_SECONDS', 3 );      // Minimum time between form render and submit.
define( 'RTPK_RESET_RATE_LIMIT', 5 );        // Password reset emails per hour per IP.

require_once RTPK_DIR . 'includes/class-autoloader.php';
RivianTrackr\Passkeys\Autoloader::register( RTPK_DIR . 'includes', RTPK_DIR . 'lib/lbuchs-webauthn' );

register_activation_hook( __FILE__, [ RivianTrackr\Passkeys\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ RivianTrackr\Passkeys\Installer::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		RivianTrackr\Passkeys\Plugin::instance()->boot();
	}
);
