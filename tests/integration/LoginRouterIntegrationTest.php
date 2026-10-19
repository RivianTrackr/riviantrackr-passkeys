<?php
declare(strict_types=1);

use RivianTrackr\Passkeys\LoginRouter;

/**
 * The wp-login.php routing against real pages, permalinks and core's URL helpers.
 */
final class LoginRouterIntegrationTest extends IntegrationTestCase {

	private int $login;
	private int $join;
	private int $reset;
	private LoginRouter $router;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->login = $this->page_with( '[riviantrackr_login]', 'sign-in' );
		$this->join  = $this->page_with( '[riviantrackr_signup]', 'join' );
		$this->reset = $this->page_with( '[riviantrackr_password_reset]', 'reset-password' );
		$this->set_settings(
			[
				'login_page_id'     => $this->login,
				'register_page_id'  => $this->join,
				'reset_page_id'     => $this->reset,
				'redirect_wp_login' => true,
			]
		);
		// The plugin booted before these settings existed; attach the router now.
		$this->router = new LoginRouter( $this->plugin()->settings() );
		$this->router->register_hooks();
	}

	public function test_core_url_helpers_point_at_the_custom_pages(): void {
		$this->assertSame( get_permalink( $this->login ), wp_login_url() );
		$this->assertSame( get_permalink( $this->login ) . '?redirect_to=' . rawurlencode( home_url( '/account/' ) ), wp_login_url( home_url( '/account/' ) ) );
		$this->assertStringContainsString( 'wp-login.php', wp_login_url( '', true ), 'forced reauth stays on wp-login.php' );
		$this->assertSame( get_permalink( $this->join ), wp_registration_url() );
		$this->assertSame( get_permalink( $this->reset ), wp_lostpassword_url() );
		$this->assertSame( get_permalink( $this->reset ) . '?redirect_to=' . rawurlencode( home_url( '/x/' ) ), wp_lostpassword_url( home_url( '/x/' ) ) );
	}

	public function test_a_draft_page_switches_its_redirect_off(): void {
		wp_update_post(
			[
				'ID'          => $this->reset,
				'post_status' => 'draft',
			]
		);
		$this->plugin()->settings()->flush();
		$this->assertStringContainsString( 'wp-login.php?action=lostpassword', wp_lostpassword_url() );
		$this->assertSame( get_permalink( $this->login ), wp_login_url(), 'the others keep working' );
	}

	public function test_the_emailed_reset_link_lands_on_the_reset_page_with_its_key(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = [
			'action' => 'rp',
			'key'    => 'AbC123',
			'login'  => 'jose',
		];
		$_REQUEST                  = $_GET;

		$location = $this->expect_redirect( fn() => $this->router->maybe_redirect() );
		$this->assertSame( get_permalink( $this->reset ) . '?key=AbC123&login=jose', $location );
	}

	public function test_the_login_screen_redirects_with_its_message_and_signed_in_visitors_do_not(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = [
			'loggedout'   => 'true',
			'redirect_to' => home_url( '/news/' ),
		];
		$_REQUEST                  = $_GET;

		$location = $this->expect_redirect( fn() => $this->router->maybe_redirect() );
		$this->assertStringStartsWith( get_permalink( $this->login ), $location );
		$this->assertStringContainsString( 'loggedout=true', $location );
		$this->assertStringContainsString( 'redirect_to=' . rawurlencode( home_url( '/news/' ) ), $location );

		wp_set_current_user( (int) self::factory()->user->create() );
		$this->router->maybe_redirect();
		$this->assertTrue( true, 'no redirect for a signed-in visitor' );
	}

	public function test_posts_and_other_actions_are_left_to_wp_login(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST                  = [ 'action' => 'lostpassword' ];
		$this->router->maybe_redirect();

		$_SERVER['REQUEST_METHOD'] = 'GET';
		foreach ( [ 'logout', 'postpass', 'passkey-setup', 'passkey-signup', 'confirmaction' ] as $action ) {
			$_GET     = [ 'action' => $action ];
			$_REQUEST = $_GET;
			$this->router->maybe_redirect();
		}
		$this->assertTrue( true, 'none of those redirected (a redirect would have thrown through wp_redirect)' );
	}
}
