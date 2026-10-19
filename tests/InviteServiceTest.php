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

	public function test_setup_url_prefers_the_owners_setup_page(): void {
		$this->assertStringContainsString( 'wp-login.php?action=passkey-setup', $this->invites->setup_url(), 'no page chosen' );

		$GLOBALS['rtpk_test_posts'] = [ 14 => [ 'status' => 'publish', 'url' => 'https://riviantrackr.test/passkey/' ] ];
		update_option( RTPK_OPTION_NAME, [ 'setup_page_id' => 14 ] );
		$invites = new InviteService( new Settings(), new CredentialStore() );

		$this->assertSame( 'https://riviantrackr.test/passkey/', $invites->setup_url() );
		$this->assertSame( 'https://riviantrackr.test/passkey/?redirect_to=%2Faccount%2F', $invites->setup_url( '/account/' ) );
		$this->assertStringContainsString( 'wp-login.php?action=passkey-setup', InviteService::wp_login_setup_url(), 'the token landing stays on wp-login.php' );
		$this->assertStringContainsString( 'wp-login.php', $invites->invite_link( $this->user, 'abc' ), 'invitation links still carry the token to wp-login.php' );
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
		$this->assertSame( 2, substr_count( $email['html'], 'font-size:15px;line-height:1.6' ), 'blank line starts a new paragraph' );
		$this->assertStringContainsString( 'Set up my passkey', $email['html'] );
		$this->assertStringContainsString( 'href="' . htmlspecialchars( $link, ENT_QUOTES ) . '"', $email['html'] );
		$this->assertStringContainsString( '#fba919', $email['html'], 'brand gold button' );
		$this->assertStringContainsString( $link, $email['text'] );
	}

	public function test_bullet_lines_become_a_list_and_default_message_has_benefits(): void {
		$c    = $this->invites->palette( 'dark' );
		$html = $this->invites->body_to_html( "Intro here.\n\n* First perk\n- Second perk\n\nNot a list\n* mixed block", $c );

		$this->assertSame( 2, substr_count( $html, '&#8226;' ), 'two bullets' );
		$this->assertStringContainsString( '>First perk</td>', $html );
		$this->assertStringContainsString( '>Second perk</td>', $html );
		$this->assertStringContainsString( 'color:#fba919;">&#8226;', $html, 'gold bullet' );
		$this->assertStringContainsString( 'Not a list<br />', $html, 'mixed block stays a paragraph' );
		$this->assertSame( 2, substr_count( $html, '<p style=' ) );

		$default = $this->invites->render_email( $this->user, 'https://riviantrackr.test/x' )['html'];
		$this->assertStringContainsString( 'Save tires and leave or edit your tire reviews', $default );
		$this->assertSame( 4, substr_count( $default, '&#8226;' ), 'default message lists four benefits' );
	}

	public function test_email_defaults_to_dark_brand_palette_and_site_name_header(): void {
		$email = $this->invites->render_email( $this->user, 'https://riviantrackr.test/x' );

		$this->assertStringContainsString( 'background:#121418', $email['html'], 'deep background' );
		$this->assertStringContainsString( 'background:#16191e', $email['html'], 'card surface' );
		$this->assertStringContainsString( 'color:#ece9e4', $email['html'], 'body text' );
		$this->assertStringContainsString( 'color:#15130e', $email['html'], 'dark text on gold' );
		$this->assertStringNotContainsString( '<img', $email['html'], 'no logo configured' );
		$this->assertMatchesRegularExpression( '/<span[^>]*>RivianTrackr<\/span>/', $email['html'], 'site name stands in for the logo' );
	}

	public function test_email_light_theme_and_logo_overrides(): void {
		$GLOBALS['rtpk_test_attachments'][55] = 'https://riviantrackr.test/logo.png';

		$email = $this->invites->render_email(
			$this->user,
			'https://riviantrackr.test/x',
			[
				'theme'   => 'light',
				'logo_id' => 55,
			]
		);
		$this->assertStringContainsString( 'background:#ffffff', $email['html'] );
		$this->assertStringContainsString( 'color:#1d1d1f', $email['html'] );
		$this->assertStringNotContainsString( '#16191e', $email['html'] );
		$this->assertStringContainsString( '<img src="https://riviantrackr.test/logo.png"', $email['html'] );
		$this->assertStringContainsString( 'alt="RivianTrackr"', $email['html'] );
	}

	public function test_logo_falls_back_to_theme_custom_logo(): void {
		$this->assertSame( '', $this->invites->logo_url() );

		$GLOBALS['rtpk_test_theme_mods']['custom_logo'] = 9;
		$GLOBALS['rtpk_test_attachments'][9]            = 'https://riviantrackr.test/custom.png';
		$this->assertSame( 'https://riviantrackr.test/custom.png', $this->invites->logo_url() );

		update_option( InviteService::OPTION, [ 'logo_id' => 55 ] );
		$GLOBALS['rtpk_test_attachments'][55] = 'https://riviantrackr.test/chosen.png';
		$this->invites->flush();
		$this->assertSame( 'https://riviantrackr.test/chosen.png', $this->invites->logo_url(), 'explicit choice wins' );
		$this->assertSame( 'https://riviantrackr.test/custom.png', $this->invites->logo_url( 0 ), 'override 0 means default' );
	}

	public function test_send_applies_from_name_and_address_only_during_the_send(): void {
		update_option(
			InviteService::OPTION,
			[
				'from_name'  => 'RivianTrackr Team',
				'from_email' => 'hello@riviantrackr.test',
			]
		);
		$this->invites->flush();

		$this->assertTrue( $this->invites->send( $this->user ) );

		$ops = array_map( fn( $f ) => $f[0] . ':' . $f[1], $GLOBALS['rtpk_test_filters'] );
		$this->assertSame( [ 'add:wp_mail_from', 'add:wp_mail_from_name', 'remove:wp_mail_from', 'remove:wp_mail_from_name' ], $ops );
		$this->assertSame( 'hello@riviantrackr.test', $GLOBALS['rtpk_test_filters'][0][2]() );
		$this->assertSame( 'RivianTrackr Team', $GLOBALS['rtpk_test_filters'][1][2]() );
	}

	public function test_send_leaves_from_alone_when_not_configured(): void {
		$this->invites->send( $this->user );
		$this->assertSame( [], $GLOBALS['rtpk_test_filters'] );
	}

	public function test_sanitize_validates_from_email_logo_and_theme(): void {
		$out = $this->invites->sanitize(
			[
				'from_name'  => ' <i>Team</i> ',
				'from_email' => 'not-an-email',
				'logo_id'    => '-4',
				'theme'      => 'neon',
			]
		);
		$this->assertSame( 'Team', $out['from_name'] );
		$this->assertSame( '', $out['from_email'] );
		$this->assertNotEmpty( $GLOBALS['rtpk_test_errors'], 'invalid from address reports a settings error' );
		$this->assertSame( 4, $out['logo_id'] );
		$this->assertSame( 'dark', $out['theme'] );

		$GLOBALS['rtpk_test_errors'] = [];
		$out = $this->invites->sanitize(
			[
				'from_email' => 'Hello@RivianTrackr.test',
				'logo_id'    => '55',
				'theme'      => 'light',
			]
		);
		$this->assertSame( 'Hello@RivianTrackr.test', $out['from_email'] );
		$this->assertEmpty( $GLOBALS['rtpk_test_errors'] );
		$this->assertSame( 55, $out['logo_id'] );
		$this->assertSame( 'light', $out['theme'] );
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
