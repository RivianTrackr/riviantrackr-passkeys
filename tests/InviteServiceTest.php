<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\CredentialStore;
use RivianTrackr\Passkeys\InviteService;
use RivianTrackr\Passkeys\Settings;

final class InviteServiceTest extends TestCase {

	private InviteService $invites;
	private WP_User $user;

	protected function setUp(): void {
		rtpk_test_reset();
		$this->invites = new InviteService( new Settings(), new CredentialStore() );
		$this->user    = new WP_User( 7, 'ana', 'Ana Ruiz', 'ana@example.test' );

		$GLOBALS['rtpk_test_users'][7] = $this->user;
	}

	public function test_token_is_stored_hashed_and_validates_once(): void {
		$token = $this->invites->create_token( $this->user );

		$this->assertGreaterThanOrEqual( 43, strlen( $token ) );
		$record = $GLOBALS['rtpk_test_usermeta'][7][ InviteService::META_INVITE ];
		$this->assertSame( hash( 'sha256', $token ), $record['hash'] );
		$this->assertStringNotContainsString( $token, json_encode( $record ) );
		$this->assertSame( 1, $record['count'] );
		$this->assertGreaterThan( time(), $record['expires'] );
		$this->assertSame( time(), $GLOBALS['rtpk_test_usermeta'][7][ InviteService::META_INVITED_AT ], 'invited-at marker set' );

		$this->assertSame( 7, $this->invites->validate_token( 7, $token )->ID );

		$this->invites->consume_token( 7 );
		$this->assertNull( $this->invites->validate_token( 7, $token ), 'consumed token no longer validates' );
		$this->assertArrayHasKey( InviteService::META_INVITED_AT, $GLOBALS['rtpk_test_usermeta'][7], 'invited-at survives consumption' );
	}

	public function test_wrong_user_or_token_or_expiry_is_rejected(): void {
		$token = $this->invites->create_token( $this->user );

		$this->assertNull( $this->invites->validate_token( 8, $token ), 'other user' );
		$this->assertNull( $this->invites->validate_token( 7, $token . 'x' ), 'altered token' );
		$this->assertNull( $this->invites->validate_token( 7, '' ), 'empty token' );
		$this->assertNull( $this->invites->validate_token( 0, $token ), 'no user' );

		$GLOBALS['rtpk_test_usermeta'][7][ InviteService::META_INVITE ]['expires'] = time() - 1;
		$this->assertNull( $this->invites->validate_token( 7, $token ), 'expired' );
	}

	public function test_reissuing_replaces_the_previous_token_and_counts(): void {
		$first  = $this->invites->create_token( $this->user );
		$second = $this->invites->create_token( $this->user );

		$this->assertNotSame( $first, $second );
		$this->assertNull( $this->invites->validate_token( 7, $first ) );
		$this->assertNotNull( $this->invites->validate_token( 7, $second ) );
		$this->assertSame( 2, $GLOBALS['rtpk_test_usermeta'][7][ InviteService::META_INVITE ]['count'] );
	}

	public function test_invite_link_targets_the_setup_action(): void {
		$link = $this->invites->invite_link( $this->user, 'abc' );
		$this->assertStringContainsString( 'wp-login.php', $link );
		$this->assertStringContainsString( 'action=passkey-setup', $link );
		$this->assertStringContainsString( 'uid=7', $link );
		$this->assertStringContainsString( 'rtpk_invite=abc', $link );

		$this->assertStringContainsString( 'redirect_to=', $this->invites->setup_url( 'https://riviantrackr.test/account/' ) );
	}

	public function test_email_fills_placeholders_escapes_html_and_includes_button(): void {
		$link  = 'https://riviantrackr.test/wp-login.php?action=passkey-setup&uid=7&rtpk_invite=t';
		$email = $this->invites->render_email(
			$this->user,
			$link,
			[
				'subject' => 'Hey {name}, {site} has passkeys',
				'body'    => "Hi {name} <script>alert(1)</script>\n\nSecond paragraph expires in {expires} days.",
			]
		);

		$this->assertSame( 'Hey Ana Ruiz, RivianTrackr has passkeys', $email['subject'] );
		$this->assertStringContainsString( 'Hi Ana Ruiz &lt;script&gt;', $email['html'] );
		$this->assertStringNotContainsString( '<script>', $email['html'] );
		$this->assertStringContainsString( 'expires in 7 days', $email['html'] );
		$this->assertSame( 2, substr_count( $email['html'], '<p style=' ), 'blank line starts a new paragraph' );
		$this->assertStringContainsString( 'Set up my passkey', $email['html'] );
		$this->assertStringContainsString( 'href="' . htmlspecialchars( $link, ENT_QUOTES ) . '"', $email['html'] );
		$this->assertStringContainsString( '#fba919', $email['html'], 'brand gold button' );
		$this->assertStringContainsString( $link, $email['text'] );
	}

	public function test_send_issues_token_mails_html_and_records_invitation(): void {
		$result = $this->invites->send( $this->user );

		$this->assertTrue( $result );
		$this->assertCount( 1, $GLOBALS['rtpk_test_mail'] );
		$mail = $GLOBALS['rtpk_test_mail'][0];
		$this->assertSame( 'ana@example.test', $mail['to'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $mail['headers'] );
		$this->assertStringContainsString( 'Sign in to RivianTrackr faster with a passkey', $mail['subject'] );

		preg_match( '/rtpk_invite=([A-Za-z0-9_-]+)/', $mail['message'], $m );
		$this->assertNotEmpty( $m[1] ?? '', 'email carries the plaintext token' );
		$this->assertNotNull( $this->invites->validate_token( 7, $m[1] ), 'the mailed token validates' );
		$this->assertGreaterThan( 0, $this->invites->invited_at( 7 ) );
	}

	public function test_test_send_does_not_count_as_an_invitation(): void {
		$this->invites->send( $this->user, [ 'subject' => 'Draft subject' ], false );

		$this->assertSame( 'Draft subject', $GLOBALS['rtpk_test_mail'][0]['subject'] );
		$this->assertSame( 0, $this->invites->invited_at( 7 ) );
	}

	public function test_send_rejects_users_without_email(): void {
		$user   = new WP_User( 9, 'noemail', 'No Email', '' );
		$result = $this->invites->send( $user );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'rtpk_no_email', $result->get_error_code() );
		$this->assertCount( 0, $GLOBALS['rtpk_test_mail'] );
	}

	public function test_sanitize_strips_tags_clamps_expiry_and_falls_back_to_defaults(): void {
		$out = $this->invites->sanitize(
			[
				'subject'     => '<b>Hello</b>',
				'body'        => "  <p>Line</p>\n\n<img src=x onerror=1>More  ",
				'expiry_days' => 99,
				'magic_link'  => '1',
			]
		);
		$this->assertSame( 'Hello', $out['subject'] );
		$this->assertSame( "Line\n\nMore", $out['body'] );
		$this->assertSame( 30, $out['expiry_days'] );
		$this->assertTrue( $out['magic_link'] );

		$out = $this->invites->sanitize( [ 'expiry_days' => 0 ] );
		$this->assertSame( $this->invites->defaults()['subject'], $out['subject'] );
		$this->assertSame( $this->invites->defaults()['body'], $out['body'] );
		$this->assertSame( 1, $out['expiry_days'] );
		$this->assertFalse( $out['magic_link'] );
	}

	public function test_expiry_setting_drives_token_lifetime(): void {
		update_option( InviteService::OPTION, [ 'expiry_days' => 2 ] );
		$this->invites->flush();

		$this->invites->create_token( $this->user );
		$expires = $GLOBALS['rtpk_test_usermeta'][7][ InviteService::META_INVITE ]['expires'];
		$this->assertEqualsWithDelta( time() + 2 * DAY_IN_SECONDS, $expires, 2 );
		$this->assertTrue( $this->invites->magic_link_enabled(), 'omitted keys fall back to defaults' );

		update_option( InviteService::OPTION, [ 'magic_link' => false ] );
		$this->invites->flush();
		$this->assertFalse( $this->invites->magic_link_enabled() );
	}
}
