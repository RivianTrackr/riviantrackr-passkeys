<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\CredentialStore;
use RivianTrackr\Passkeys\DisposableDomains;
use RivianTrackr\Passkeys\InviteService;
use RivianTrackr\Passkeys\RateLimiter;
use RivianTrackr\Passkeys\Settings;
use RivianTrackr\Passkeys\SignupService;

final class SignupServiceTest extends TestCase {

	private SignupService $signup;
	private FakePendingSignupStore $store;

	protected function setUp(): void {
		rtpk_test_reset();
		update_option( SignupService::OPTION, [ 'enabled' => true ] );
		$this->store  = new FakePendingSignupStore();
		$this->signup = new SignupService( new Settings(), new InviteService( new Settings(), new CredentialStore() ), $this->store, new RateLimiter() );
	}

	/**
	 * A form token old enough to pass the minimum-time check.
	 *
	 * @return array<string,string>
	 */
	private function valid_input( array $overrides = [] ): array {
		$ts = (string) ( time() - 10 );
		return array_merge(
			[
				'email'   => 'New.Member@Example.com',
				'name'    => 'New Member',
				'website' => '',
				'ts'      => $ts,
				'sig'     => wp_hash( 'rtpk-signup|' . $ts ),
			],
			$overrides
		);
	}

	private function mailed_token(): string {
		$this->assertNotEmpty( $GLOBALS['rtpk_test_mail'], 'an email was sent' );
		preg_match( '/token=([A-Za-z0-9_-]+)/', end( $GLOBALS['rtpk_test_mail'] )['message'], $m );
		$this->assertNotEmpty( $m[1] ?? '', 'email carries the plaintext token' );
		return $m[1];
	}

	// ---- request → pending row, no user ---------------------------------

	public function test_request_stores_a_hashed_pending_row_and_creates_no_user(): void {
		$result = $this->signup->handle_request( $this->valid_input(), '203.0.113.9' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Check your inbox', $result['message'] );

		$this->assertSame( [], $GLOBALS['rtpk_test_inserted'], 'no WordPress user yet' );
		$this->assertCount( 1, $this->store->rows );
		$row = reset( $this->store->rows );
		$this->assertSame( 'new.member@example.com', $row['email'], 'email is normalised to lowercase' );
		$this->assertSame( 'New Member', $row['display_name'] );

		$token = $this->mailed_token();
		$this->assertSame( hash( 'sha256', $token ), $row['token_hash'] );
		$this->assertStringNotContainsString( $token, json_encode( $this->store->rows ), 'only the hash is stored' );
		$this->assertStringNotContainsString( '203.0.113.9', json_encode( $this->store->rows ), 'IP is hashed' );
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, strtotime( $row['expires_at'] . ' UTC' ), 3, 'default expiry is 24 hours' );

		$mail = $GLOBALS['rtpk_test_mail'][0];
		$this->assertSame( 'new.member@example.com', $mail['to'] );
		$this->assertSame( 'Confirm your RivianTrackr account', $mail['subject'] );
		$this->assertStringContainsString( 'Hi New Member', $mail['message'] );
		$this->assertStringContainsString( 'Confirm and set up my passkey', $mail['message'] );
		$this->assertStringContainsString( 'action=passkey-signup', $mail['message'] );
		$this->assertStringContainsString( '#fba919', $mail['message'], 'branded gold button' );
		$this->assertStringContainsString( 'expires in 24 hours', $mail['message'] );
		$this->assertContains( 'riviantrackr_passkeys_signup_requested', array_column( $GLOBALS['rtpk_test_actions'], 0 ) );
	}

	public function test_verify_creates_user_signs_them_in_once_and_consumes_token(): void {
		$this->signup->handle_request( $this->valid_input(), '203.0.113.9' );
		$token = $this->mailed_token();

		$row = $this->signup->validate_token( $token );
		$this->assertIsArray( $row );
		$this->assertNull( $this->signup->validate_token( $token . 'x' ), 'altered token' );
		$this->assertNull( $this->signup->validate_token( '' ), 'empty token' );
		$this->assertNull( $this->signup->validate_token( 'not base64url!' ), 'malformed token' );

		$user = $this->signup->complete( $row );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( 'new.member@example.com', $user->user_email );
		$this->assertSame( 'new.member', $user->user_login );
		$this->assertSame( 'New Member', $user->display_name );

		$inserted = reset( $GLOBALS['rtpk_test_inserted'] );
		$this->assertSame( 'subscriber', $inserted['role'] );
		$this->assertGreaterThanOrEqual( 32, strlen( $inserted['user_pass'] ), 'random password' );
		$this->assertTrue( RivianTrackr\Passkeys\AccountService::has_no_password( $user->ID ), 'flagged so the account page offers "Set a password"' );

		$actions = array_column( $GLOBALS['rtpk_test_actions'], 0 );
		$this->assertContains( 'user_register', $actions, 'core registration hook fired' );
		$this->assertContains( 'riviantrackr_passkeys_signup_verified', $actions );
		$this->assertSame( [ [ $user->ID, 'admin' ] ], $GLOBALS['rtpk_test_notifications'], 'admin notified by default' );

		$this->assertSame( [], $this->store->rows, 'pending row consumed' );
		$this->assertNull( $this->signup->validate_token( $token ), 'token is single use' );
	}

	public function test_expired_token_is_rejected_and_purged(): void {
		$this->signup->handle_request( $this->valid_input(), '203.0.113.9' );
		$token = $this->mailed_token();

		foreach ( $this->store->rows as &$row ) {
			$row['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
		}
		unset( $row );

		$this->assertNull( $this->signup->validate_token( $token ) );
		$this->assertSame( [], $this->store->rows, 'expired row removed on lookup' );
	}

	public function test_expiry_setting_drives_link_lifetime(): void {
		update_option( SignupService::OPTION, [ 'enabled' => true, 'expiry_hours' => 2 ] );
		$this->signup->flush();

		$this->signup->handle_request( $this->valid_input(), '203.0.113.9' );
		$row = reset( $this->store->rows );
		$this->assertEqualsWithDelta( time() + 2 * HOUR_IN_SECONDS, strtotime( $row['expires_at'] . ' UTC' ), 3 );
		$this->assertStringContainsString( 'expires in 2 hours', $GLOBALS['rtpk_test_mail'][0]['message'] );
	}

	public function test_new_request_replaces_the_previous_pending_link(): void {
		$this->signup->handle_request( $this->valid_input(), '203.0.113.9' );
		$first = $this->mailed_token();
		$this->signup->handle_request( $this->valid_input(), '203.0.113.9' );
		$second = $this->mailed_token();

		$this->assertNotSame( $first, $second );
		$this->assertCount( 1, $this->store->rows );
		$this->assertNull( $this->signup->validate_token( $first ) );
		$this->assertNotNull( $this->signup->validate_token( $second ) );
	}

	// ---- the response never reveals whether an address is registered ----

	public function test_existing_email_gets_the_same_response_and_no_pending_row(): void {
		$GLOBALS['rtpk_test_user_emails']['new.member@example.com'] = 7;

		$result = $this->signup->handle_request( $this->valid_input(), '203.0.113.9' );
		$fresh  = $this->signup->handle_request( $this->valid_input( [ 'email' => 'other@example.com' ] ), '203.0.113.10' );

		$this->assertSame( $fresh, $result, 'identical payload for registered and unregistered addresses' );
		$this->assertCount( 1, $this->store->rows, 'only the unregistered address got a pending row' );
		$this->assertSame( 'other@example.com', reset( $this->store->rows )['email'] );

		$this->assertCount( 2, $GLOBALS['rtpk_test_mail'] );
		$notice = $GLOBALS['rtpk_test_mail'][0];
		$this->assertSame( 'new.member@example.com', $notice['to'] );
		$this->assertSame( 'You already have a RivianTrackr account', $notice['subject'] );
		$this->assertStringNotContainsString( 'passkey-signup', $notice['message'], 'no signup token for existing accounts' );
	}

	public function test_race_where_account_appears_before_click_is_handled(): void {
		$this->signup->handle_request( $this->valid_input(), '203.0.113.9' );
		$row = $this->signup->validate_token( $this->mailed_token() );

		$GLOBALS['rtpk_test_user_emails']['new.member@example.com'] = 7;
		$result = $this->signup->complete( $row );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rtpk_signup_exists', $result->get_error_code() );
		$this->assertSame( [], $GLOBALS['rtpk_test_inserted'] );
		$this->assertSame( [], $this->store->rows, 'row consumed anyway' );
	}

	// ---- spam defences ----------------------------------------------------

	public function test_honeypot_pretends_to_succeed_without_storing_or_sending(): void {
		$result = $this->signup->handle_request( $this->valid_input( [ 'website' => 'http://spam.example' ] ), '203.0.113.9' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( [], $this->store->rows );
		$this->assertSame( [], $GLOBALS['rtpk_test_mail'] );
	}

	public function test_minimum_time_and_signature_are_enforced(): void {
		$now = (string) time();

		$fast = $this->signup->handle_request( $this->valid_input( [ 'ts' => $now, 'sig' => wp_hash( 'rtpk-signup|' . $now ) ] ), '203.0.113.9' );
		$this->assertSame( 'rtpk_signup_form', $fast->get_error_code(), 'submitted too quickly' );

		$forged_ts = (string) ( time() - 100 );
		$forged    = $this->signup->handle_request( $this->valid_input( [ 'ts' => $forged_ts, 'sig' => 'deadbeef' ] ), '203.0.113.9' );
		$this->assertSame( 'rtpk_signup_form', $forged->get_error_code(), 'bad signature' );

		$old_ts = (string) ( time() - 2 * DAY_IN_SECONDS );
		$stale  = $this->signup->handle_request( $this->valid_input( [ 'ts' => $old_ts, 'sig' => wp_hash( 'rtpk-signup|' . $old_ts ) ] ), '203.0.113.9' );
		$this->assertSame( 'rtpk_signup_form', $stale->get_error_code(), 'stale page' );

		$missing = $this->signup->handle_request( $this->valid_input( [ 'ts' => '', 'sig' => '' ] ), '203.0.113.9' );
		$this->assertSame( 'rtpk_signup_form', $missing->get_error_code() );

		$this->assertSame( [], $this->store->rows );

		$token = $this->signup->form_token();
		$this->assertFalse( $this->signup->verify_form_token( (string) $token['ts'], $token['sig'] ), 'fresh token is too young' );
		$this->assertTrue( $this->signup->verify_form_token( (string) ( $token['ts'] - 5 ), wp_hash( 'rtpk-signup|' . ( $token['ts'] - 5 ) ) ) );
	}

	public function test_invalid_email_is_rejected(): void {
		foreach ( [ '', 'nope', 'a@b', 'user@', str_repeat( 'a', 95 ) . '@example.com' ] as $email ) {
			$result = $this->signup->handle_request( $this->valid_input( [ 'email' => $email ] ), '203.0.113.9' );
			$this->assertTrue( is_wp_error( $result ), $email );
			$this->assertSame( 'rtpk_signup_email', $result->get_error_code(), $email );
		}
		$this->assertSame( [], $this->store->rows );
	}

	public function test_disposable_domains_are_refused_including_admin_extras_and_subdomains(): void {
		$result = $this->signup->handle_request( $this->valid_input( [ 'email' => 'x@mailinator.com' ] ), '203.0.113.9' );
		$this->assertSame( 'rtpk_signup_domain', $result->get_error_code() );

		$result = $this->signup->handle_request( $this->valid_input( [ 'email' => 'x@foo.Mailinator.COM' ] ), '203.0.113.9' );
		$this->assertSame( 'rtpk_signup_domain', $result->get_error_code(), 'subdomain of a blocked domain' );

		update_option( SignupService::OPTION, [ 'enabled' => true, 'blocked_domains' => "spammy.example\n@Another.Example, bad.test" ] );
		$this->signup->flush();
		$this->assertSame( [ 'spammy.example', 'another.example', 'bad.test' ], $this->signup->blocked_domains() );

		$result = $this->signup->handle_request( $this->valid_input( [ 'email' => 'x@another.example' ] ), '203.0.113.9' );
		$this->assertSame( 'rtpk_signup_domain', $result->get_error_code(), 'admin-added domain' );

		$result = $this->signup->handle_request( $this->valid_input( [ 'email' => 'x@notmailinator.com' ] ), '203.0.113.9' );
		$this->assertIsArray( $result, 'a different domain that merely ends the same way is fine' );
		$this->assertSame( [ 'x@notmailinator.com' ], array_column( $this->store->rows, 'email' ), 'only the legitimate address got a pending row' );
	}

	public function test_disposable_domain_helpers(): void {
		$this->assertTrue( DisposableDomains::is_disposable( 'a@yopmail.com' ) );
		$this->assertTrue( DisposableDomains::is_disposable( 'a@sub.guerrillamail.com' ) );
		$this->assertFalse( DisposableDomains::is_disposable( 'a@gmail.com' ) );
		$this->assertFalse( DisposableDomains::is_disposable( 'no-at-sign' ) );
		$this->assertTrue( DisposableDomains::is_disposable( 'a@custom.test', [ 'custom.test' ] ) );
		$this->assertSame( 'example.com', DisposableDomains::domain_of( 'Someone@Example.COM.' ) );
		$this->assertSame( [ 'a.com', 'b.org' ], DisposableDomains::parse_list( "A.com\n\n@b.org\nnot a domain\n a.com " ) );
		$this->assertGreaterThan( 100, DisposableDomains::built_in_count() );
	}

	public function test_signup_allowed_filter_can_deny(): void {
		$GLOBALS['rtpk_test_filter_hooks']['riviantrackr_passkeys_signup_allowed'] = static function ( $allowed, string $email ) {
			return str_ends_with( $email, '@example.com' ) ? new WP_Error( 'rtpk_test_no', 'Not here.' ) : $allowed;
		};
		$result = $this->signup->handle_request( $this->valid_input(), '203.0.113.9' );
		$this->assertSame( 'rtpk_test_no', $result->get_error_code() );

		$GLOBALS['rtpk_test_filter_hooks']['riviantrackr_passkeys_signup_allowed'] = static fn() => false;
		$result = $this->signup->handle_request( $this->valid_input(), '203.0.113.9' );
		$this->assertSame( 'rtpk_signup_denied', $result->get_error_code() );
		$this->assertSame( [], $this->store->rows );
	}

	public function test_ip_rate_limit_returns_429_and_email_rate_limit_goes_quiet(): void {
		for ( $i = 0; $i < RTPK_SIGNUP_IP_RATE_LIMIT; $i++ ) {
			$this->signup->handle_request( $this->valid_input( [ 'email' => "m{$i}@example.com" ] ), '198.51.100.1' );
		}
		$limited = $this->signup->handle_request( $this->valid_input( [ 'email' => 'm9@example.com' ] ), '198.51.100.1' );
		$this->assertSame( 'rtpk_rate_limited', $limited->get_error_code() );

		$mails_before = count( $GLOBALS['rtpk_test_mail'] );
		for ( $i = 0; $i < RTPK_SIGNUP_EMAIL_RATE_LIMIT + 2; $i++ ) {
			$result = $this->signup->handle_request( $this->valid_input( [ 'email' => 'same@example.com' ] ), '198.51.100.' . ( 10 + $i ) );
			$this->assertIsArray( $result, 'per-email limit never errors' );
		}
		$this->assertSame( $mails_before + RTPK_SIGNUP_EMAIL_RATE_LIMIT, count( $GLOBALS['rtpk_test_mail'] ), 'extra requests for one address send nothing' );
	}

	public function test_disabled_signups_are_refused(): void {
		update_option( SignupService::OPTION, [] );
		$this->signup->flush();
		$this->assertFalse( $this->signup->enabled(), 'off by default' );

		$result = $this->signup->handle_request( $this->valid_input(), '203.0.113.9' );
		$this->assertSame( 'rtpk_signup_disabled', $result->get_error_code() );
	}

	public function test_mail_failure_rolls_back_the_pending_row(): void {
		$GLOBALS['rtpk_test_mail_ok'] = false;
		$result                       = $this->signup->handle_request( $this->valid_input(), '203.0.113.9' );
		$this->assertSame( 'rtpk_mail_failed', $result->get_error_code() );
		$this->assertSame( [], $this->store->rows );
	}

	// ---- Turnstile ------------------------------------------------------

	public function test_turnstile_is_skipped_without_keys_and_verified_with_them(): void {
		$this->signup->handle_request( $this->valid_input(), '203.0.113.9' );
		$this->assertSame( [], $GLOBALS['rtpk_test_remote'], 'no siteverify call without keys' );

		update_option( SignupService::OPTION, [ 'enabled' => true, 'turnstile_site_key' => '1x000', 'turnstile_secret_key' => '2x000' ] );
		$this->signup->flush();
		$this->assertTrue( $this->signup->turnstile_enabled() );

		$missing = $this->signup->handle_request( $this->valid_input( [ 'email' => 'b@example.com' ] ), '203.0.113.9' );
		$this->assertSame( 'rtpk_signup_captcha', $missing->get_error_code(), 'no token' );
		$this->assertSame( [], $GLOBALS['rtpk_test_remote'], 'empty token is not sent to Cloudflare' );

		$GLOBALS['rtpk_test_remote_reply'] = [ 'code' => 200, 'body' => '{"success":false,"error-codes":["invalid-input-response"]}' ];
		$bad                               = $this->signup->handle_request( $this->valid_input( [ 'email' => 'b@example.com', 'turnstile' => 'tok' ] ), '203.0.113.9' );
		$this->assertSame( 'rtpk_signup_captcha', $bad->get_error_code() );

		$GLOBALS['rtpk_test_remote_reply'] = [ 'code' => 200, 'body' => '{"success":true}' ];
		$ok                                = $this->signup->handle_request( $this->valid_input( [ 'email' => 'b@example.com', 'turnstile' => 'tok' ] ), '203.0.113.9' );
		$this->assertIsArray( $ok );

		[ $url, $args ] = end( $GLOBALS['rtpk_test_remote'] );
		$this->assertSame( SignupService::TURNSTILE_VERIFY_URL, $url );
		$this->assertSame( [ 'secret' => '2x000', 'response' => 'tok', 'remoteip' => '203.0.113.9' ], $args['body'] );
	}

	// ---- usernames ------------------------------------------------------

	public function test_username_derivation_cleans_and_deduplicates(): void {
		$taken  = [ 'jose', 'jose2', 'jose3' ];
		$exists = static fn( string $login ) => in_array( $login, $taken, true );

		$this->assertSame( 'jose4', $this->signup->derive_username( 'Jose@riviantrackr.com', $exists ) );
		$this->assertSame( 'ana.ruiz', $this->signup->derive_username( 'Ana.Ruiz@example.com', $exists ) );
		$this->assertSame( 'ana-ruiz_99', $this->signup->derive_username( 'ana-ruiz_99@example.com', $exists ) );
		$this->assertSame( 'firstlast', $this->signup->derive_username( 'first+last@example.com', $exists ), 'plus tag folded in' );
		$this->assertSame( 'jos', $this->signup->derive_username( 'josé@example.com', $exists ), 'non-ascii dropped' );
		$this->assertSame( 'member', $this->signup->derive_username( '...@example.com', $exists ), 'fallback when nothing is left' );
		$this->assertSame( 40, strlen( $this->signup->derive_username( str_repeat( 'a', 70 ) . '@example.com', $exists ) ) );

		$GLOBALS['rtpk_test_usernames']['member'] = 1;
		$this->assertSame( 'member2', $this->signup->derive_username( '@example.com' ), 'defaults to username_exists()' );
	}

	public function test_display_name_falls_back_to_username(): void {
		$this->signup->handle_request( $this->valid_input( [ 'name' => '' ] ), '203.0.113.9' );
		$user = $this->signup->complete( $this->signup->validate_token( $this->mailed_token() ) );
		$this->assertSame( 'new.member', $user->display_name );
	}

	// ---- settings -------------------------------------------------------

	public function test_sanitize_validates_role_clamps_expiry_and_normalises_domains(): void {
		$out = $this->signup->sanitize(
			[
				'enabled'              => '1',
				'role'                 => 'administrator',
				'expiry_hours'         => 999,
				'continue_url'         => ' https://riviantrackr.test/welcome/ ',
				'blocked_domains'      => "Foo.com, bar.org\nnot a domain",
				'turnstile_site_key'   => ' key ',
				'turnstile_secret_key' => ' <b>secret</b> ',
				'subject'              => '',
				'body'                 => '',
			]
		);
		$this->assertTrue( $out['enabled'] );
		$this->assertSame( 'subscriber', $out['role'], 'administrator is never allowed' );
		$this->assertNotEmpty( $GLOBALS['rtpk_test_errors'] );
		$this->assertSame( 168, $out['expiry_hours'] );
		$this->assertSame( 'https://riviantrackr.test/welcome/', $out['continue_url'] );
		$this->assertSame( "foo.com\nbar.org", $out['blocked_domains'] );
		$this->assertSame( 'key', $out['turnstile_site_key'] );
		$this->assertSame( 'secret', $out['turnstile_secret_key'] );
		$this->assertSame( $this->signup->defaults()['subject'], $out['subject'] );
		$this->assertFalse( $out['notify_admin'] );

		$GLOBALS['rtpk_test_errors'] = [];
		$out                         = $this->signup->sanitize( [ 'role' => 'nonexistent', 'expiry_hours' => 0 ] );
		$this->assertSame( 'subscriber', $out['role'] );
		$this->assertSame( 1, $out['expiry_hours'] );

		$GLOBALS['rtpk_test_errors'] = [];
		$out                         = $this->signup->sanitize( [ 'role' => 'editor' ] );
		$this->assertSame( 'editor', $out['role'] );
		$this->assertEmpty( $GLOBALS['rtpk_test_errors'] );
	}

	public function test_role_setting_is_applied_but_never_administrator(): void {
		update_option( SignupService::OPTION, [ 'enabled' => true, 'role' => 'author' ] );
		$this->signup->flush();
		$this->assertSame( 'author', $this->signup->role() );

		update_option( SignupService::OPTION, [ 'enabled' => true, 'role' => 'administrator' ] );
		$this->signup->flush();
		$this->assertSame( 'subscriber', $this->signup->role() );
	}

	public function test_signup_link_and_continue_url(): void {
		$link = $this->signup->signup_link( 'abc' );
		$this->assertStringContainsString( 'wp-login.php', $link );
		$this->assertStringContainsString( 'action=passkey-signup', $link );
		$this->assertStringContainsString( 'token=abc', $link );

		$this->assertSame( 'https://riviantrackr.test/', $this->signup->continue_url(), 'home by default' );
		update_option( SignupService::OPTION, [ 'continue_url' => 'https://riviantrackr.test/welcome/' ] );
		$this->signup->flush();
		$this->assertSame( 'https://riviantrackr.test/welcome/', $this->signup->continue_url() );
	}
}
