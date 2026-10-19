<?php
declare(strict_types=1);

use RivianTrackr\Passkeys\AccountService;
use RivianTrackr\Passkeys\PasswordResetPage;
use RivianTrackr\Passkeys\RateLimiter;

/**
 * Request a reset, read the branded email, post the new password to the
 * reset page, sign in with it.
 */
final class PasswordResetFlowTest extends IntegrationTestCase {

	private int $user_id;
	private int $page;
	private PasswordResetPage $reset;

	public function set_up(): void {
		parent::set_up();
		$this->user_id = (int) self::factory()->user->create(
			[
				'role'         => 'subscriber',
				'user_login'   => 'jose',
				'user_email'   => 'jose@example.org',
				'display_name' => 'Jose',
				'user_pass'    => 'old password',
			]
		);
		$this->page    = $this->page_with( '[riviantrackr_password_reset]', 'reset-password' );
		$this->set_settings(
			[
				'reset_page_id'     => $this->page,
				'redirect_wp_login' => true,
			]
		);
		$this->reset = new PasswordResetPage( $this->plugin()->settings(), new RateLimiter() );
	}

	public function test_the_reset_email_is_branded_and_carries_a_working_key(): void {
		$this->assertTrue( retrieve_password( 'jose' ) );

		$mail = $this->last_mail();
		$this->assertSame( 'jose@example.org', $mail->to[0][0] );
		$this->assertSame( 'Reset your ' . get_bloginfo( 'name' ) . ' password', $mail->subject );
		$this->assertStringContainsString( 'text/html', $mail->header );
		$this->assertStringContainsString( 'Hi Jose', $mail->body );
		$this->assertStringContainsString( 'Choose a new password', $mail->body );

		$parts = $this->reset_link_parts( $mail->body );
		$this->assertSame( 'jose', $parts['login'] );
		$this->assertInstanceOf( WP_User::class, check_password_reset_key( $parts['key'], 'jose' ) );
	}

	public function test_an_unknown_account_sends_nothing_but_reads_as_sent(): void {
		$this->assertSame( 'sent', $this->reset->request( 'nobody@example.org' ) );
		$this->assertSame( 'sent', $this->reset->request( 'no-such-user' ) );
		$this->assertSame( [], $this->sent_mail() );
	}

	public function test_the_posted_form_sets_the_password_and_spends_the_key(): void {
		retrieve_password( 'jose' );
		$key = $this->reset_link_parts( $this->last_mail()->body )['key'];

		$this->go_to( get_permalink( $this->page ) );
		$this->assertTrue( is_singular(), 'the page resolves' );

		$_SERVER['REQUEST_METHOD']                    = 'POST';
		$_POST                                        = [
			'rtpk_reset_action' => 'reset',
			'_wpnonce'          => wp_create_nonce( 'rtpk_reset_password' ),
			'pass1'             => 'correct horse battery',
			'pass2'             => 'correct horse battery',
		];
		$_REQUEST                                     = $_POST;
		$_COOKIE[ PasswordResetPage::cookie_name() ] = 'jose:' . $key;

		$location = $this->expect_redirect( fn() => $this->reset->handle() );
		$this->assertSame( add_query_arg( PasswordResetPage::QUERY, 'done', get_permalink( $this->page ) ), $location );

		$user = get_user_by( 'id', $this->user_id );
		$this->assertTrue( wp_check_password( 'correct horse battery', $user->user_pass, $this->user_id ) );
		$this->assertFalse( wp_check_password( 'old password', $user->user_pass, $this->user_id ) );
		$this->assertWPError( check_password_reset_key( $key, 'jose' ), 'the key is single use' );
		$this->assertFalse( AccountService::has_no_password( $this->user_id ) );
	}

	public function test_a_mismatch_keeps_the_key_and_reports_the_error(): void {
		retrieve_password( 'jose' );
		$key = $this->reset_link_parts( $this->last_mail()->body )['key'];

		$this->go_to( get_permalink( $this->page ) );
		$_SERVER['REQUEST_METHOD']                    = 'POST';
		$_POST                                        = [
			'rtpk_reset_action' => 'reset',
			'_wpnonce'          => wp_create_nonce( 'rtpk_reset_password' ),
			'pass1'             => 'correct horse battery',
			'pass2'             => 'correct horse staple',
		];
		$_REQUEST                                     = $_POST;
		$_COOKIE[ PasswordResetPage::cookie_name() ] = 'jose:' . $key;

		$location = $this->expect_redirect( fn() => $this->reset->handle() );
		$this->assertSame( add_query_arg( PasswordResetPage::QUERY, 'mismatch', get_permalink( $this->page ) ), $location );
		$this->assertInstanceOf( WP_User::class, check_password_reset_key( $key, 'jose' ), 'still usable' );
	}

	public function test_a_bad_nonce_or_a_spent_key_is_refused(): void {
		$this->go_to( get_permalink( $this->page ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = [
			'rtpk_reset_action' => 'reset',
			'_wpnonce'          => 'nope',
			'pass1'             => 'correct horse battery',
			'pass2'             => 'correct horse battery',
		];
		$_REQUEST                  = $_POST;
		$this->assertStringContainsString( 'rtpk_reset=retry', $this->expect_redirect( fn() => $this->reset->handle() ) );

		$_POST['_wpnonce']                            = wp_create_nonce( 'rtpk_reset_password' );
		$_REQUEST                                     = $_POST;
		$_COOKIE[ PasswordResetPage::cookie_name() ] = 'jose:not-a-real-key';
		$this->assertStringContainsString( 'rtpk_reset=expired', $this->expect_redirect( fn() => $this->reset->handle() ) );
	}

	public function test_the_request_form_posts_and_lands_on_check_your_inbox(): void {
		$this->go_to( get_permalink( $this->page ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = [
			'rtpk_reset_action' => 'request',
			'_wpnonce'          => wp_create_nonce( 'rtpk_reset_request' ),
			'user_login'        => 'jose@example.org',
		];
		$_REQUEST                  = $_POST;

		$this->assertStringContainsString( 'rtpk_reset=sent', $this->expect_redirect( fn() => $this->reset->handle() ) );
		$this->assertCount( 1, $this->sent_mail() );
	}

	public function test_reset_requests_are_limited_per_ip_wherever_they_come_from(): void {
		// The booted plugin's PasswordResetPage already guards lostpassword_post; nothing to attach.
		for ( $i = 0; $i < RTPK_RESET_RATE_LIMIT; $i++ ) {
			$this->assertTrue( retrieve_password( 'jose' ), "request {$i} goes through" );
		}
		$this->assertCount( RTPK_RESET_RATE_LIMIT, $this->sent_mail() );

		$blocked = retrieve_password( 'jose' );
		$this->assertWPError( $blocked );
		$this->assertSame( 'rtpk_rate_limited', $blocked->get_error_code(), 'core retrieve_password() itself is limited, so wp-login.php is too' );
		$this->assertCount( RTPK_RESET_RATE_LIMIT, $this->sent_mail(), 'no sixth email' );
	}
}
