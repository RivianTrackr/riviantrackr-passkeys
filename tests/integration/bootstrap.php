<?php
/**
 * Integration suite bootstrap: boots WordPress's PHPUnit test library with
 * this plugin loaded as a must-use plugin, then creates its tables.
 *
 * Install the library with bin/install-wp-tests.sh; point WP_TESTS_DIR at it
 * when it is not in /tmp/wordpress-tests-lib.
 */

declare(strict_types=1);

$rtpk_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $rtpk_tests_dir ) {
	$rtpk_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}
if ( ! file_exists( $rtpk_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test library not found at {$rtpk_tests_dir}. Run bin/install-wp-tests.sh or set WP_TESTS_DIR.\n";
	exit( 1 );
}

$rtpk_polyfills = __DIR__ . '/vendor/yoast/phpunit-polyfills';
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && is_dir( $rtpk_polyfills ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $rtpk_polyfills );
}

require_once $rtpk_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/riviantrackr-passkeys.php';
	}
);

require $rtpk_tests_dir . '/includes/bootstrap.php';

// The activation hook never runs for a must-use plugin: create the tables
// and the cron event the way activation would.
RivianTrackr\Passkeys\Installer::activate();

require_once dirname( __DIR__ ) . '/FakeAuthenticator.php';
require_once __DIR__ . '/IntegrationTestCase.php';
