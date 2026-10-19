<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\BrandedEmails;
use RivianTrackr\Passkeys\CredentialStore;
use RivianTrackr\Passkeys\InviteService;
use RivianTrackr\Passkeys\Settings;

final class BrandedEmailsTest extends TestCase {

	private BrandedEmails $emails;

	protected function setUp(): void {
		rtpk_test_reset();
		$this->emails = new BrandedEmails( new InviteService( new Settings(), new CredentialStore() ) );
	}

	private function core_reset_mail(): array {
		return [
			'to'      => 'jose@riviantrackr.test',
			'subject' => '[RivianTrackr] Password Reset',
			'message' => "Someone has requested a password reset for the following account:\n\nhttps://riviantrackr.test/wp-login.php?action=rp&key=abc&login=jose",
			'headers' => '',
		];
	}

	public function test_password_reset_email_is_html_with_the_core_link_and_subject_of_its_own(): void {
		$user = new WP_User( 7, 'jose', 'Jose' );
		$mail = $this->emails->password_reset( $this->core_reset_mail(), 'AbC123', 'jose', $user );

		$this->assertSame( 'Reset your RivianTrackr password', $mail['subject'] );
		$this->assertStringStartsWith( '<!DOCTYPE html>', $mail['message'] );
		$this->assertStringContainsString( 'https://riviantrackr.test/wp-login.php?action=rp&amp;key=AbC123&amp;login=jose', $mail['message'], 'the link core builds, so LoginRouter can route it' );
		$this->assertStringContainsString( 'Choose a new password', $mail['message'] );
		$this->assertStringContainsString( 'Hi Jose', $mail['message'] );
		$this->assertStringContainsString( 'expires in 1 day', $mail['message'] );
		$this->assertSame( [ 'Content-Type: text/html; charset=UTF-8' ], $mail['headers'], 'no From header until a from address is configured' );
	}

	public function test_reset_link_matches_core(): void {
		$this->assertSame( 'https://riviantrackr.test/wp-login.php?action=rp&key=AbC&login=jose%20smith', BrandedEmails::reset_link( 'AbC', 'jose smith' ) );
	}

	public function test_from_address_rides_in_the_headers(): void {
		update_option(
			InviteService::OPTION,
			[
				'from_email' => 'hello@riviantrackr.test',
				'from_name'  => "Rivian\nTrackr",
			]
		);
		$this->assertSame(
			[ 'Content-Type: text/html; charset=UTF-8', 'From: RivianTrackr <hello@riviantrackr.test>' ],
			$this->emails->headers(),
			'line breaks never reach a header'
		);

		update_option( InviteService::OPTION, [ 'from_email' => 'not an address' ] );
		$this->assertSame( [ 'Content-Type: text/html; charset=UTF-8' ], ( new BrandedEmails( new InviteService( new Settings(), new CredentialStore() ) ) )->headers() );
	}

	public function test_core_mail_is_untouched_when_branding_is_disabled_or_the_user_is_missing(): void {
		$GLOBALS['rtpk_test_filter_hooks'][ BrandedEmails::FILTER_ENABLED ] = static fn() => false;
		$this->assertSame( $this->core_reset_mail(), $this->emails->password_reset( $this->core_reset_mail(), 'k', 'jose', new WP_User( 7 ) ) );
		unset( $GLOBALS['rtpk_test_filter_hooks'][ BrandedEmails::FILTER_ENABLED ] );

		$this->assertSame( $this->core_reset_mail(), $this->emails->password_reset( $this->core_reset_mail(), 'k', 'jose', false ) );
	}

	public function test_welcome_email_keeps_the_set_password_link_from_the_core_message(): void {
		$user = new WP_User( 9, 'newbie', 'Newbie' );
		$core = [
			'to'      => 'newbie@example.com',
			'subject' => '[RivianTrackr] Login Details',
			'message' => "Username: newbie\n\nTo set your password, visit the following address:\n\n<https://riviantrackr.test/wp-login.php?action=rp&key=XYZ&login=newbie>\n\nhttps://riviantrackr.test/wp-login.php",
			'headers' => '',
		];
		$mail = $this->emails->new_user( $core, $user, 'RivianTrackr' );

		$this->assertSame( 'Welcome to RivianTrackr', $mail['subject'] );
		$this->assertStringContainsString( 'key=XYZ&amp;login=newbie', $mail['message'] );
		$this->assertStringContainsString( 'Choose a password', $mail['message'] );
		$this->assertStringNotContainsString( 'wp-login.php</a></p>', $mail['message'], 'only the first (set-password) link is used' );
	}

	public function test_first_link_handles_angle_brackets_and_missing_links(): void {
		$this->assertSame( 'https://a.test/x?y=1&z=2', BrandedEmails::first_link( "see <https://a.test/x?y=1&z=2> now" ) );
		$this->assertSame( 'https://a.test/x', BrandedEmails::first_link( "https://a.test/x\nhttps://b.test" ) );
		$this->assertSame( '', BrandedEmails::first_link( 'no link here' ) );
	}

	public function test_admin_notice_and_email_change_are_branded(): void {
		$user  = new WP_User( 9, 'newbie', 'Newbie', 'newbie@example.com' );
		$admin = $this->emails->new_user_admin( [ 'to' => 'admin@riviantrackr.test', 'subject' => 'x', 'message' => 'y', 'headers' => '' ], $user, 'RivianTrackr' );
		$this->assertSame( 'New member on RivianTrackr', $admin['subject'] );
		$this->assertStringContainsString( 'newbie@example.com', $admin['message'] );
		$this->assertStringContainsString( 'user-edit.php?user_id=9', $admin['message'] );

		$change = $this->emails->email_change( [ 'to' => 'new@example.com', 'subject' => 'x', 'message' => 'y', 'headers' => [] ], $user, 'new@example.com', 'https://riviantrackr.test/account/?newuseremail=abc' );
		$this->assertSame( 'Confirm your new RivianTrackr email address', $change['subject'] );
		$this->assertStringContainsString( 'newuseremail=abc', $change['message'] );
		$this->assertStringContainsString( 'new@example.com', $change['message'] );
		$this->assertSame( [ 'Content-Type: text/html; charset=UTF-8' ], $change['headers'] );
	}
}
