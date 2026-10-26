<?php
declare(strict_types=1);

use RivianTrackr\Passkeys\CommentPrompt;
use RivianTrackr\Passkeys\Settings;
use RivianTrackr\Passkeys\SignupService;

/**
 * The comment form card through core's comment_form().
 */
final class CommentPromptIntegrationTest extends IntegrationTestCase {

	private int $post;
	private int $join_page;

	public function set_up(): void {
		parent::set_up();
		update_option( SignupService::OPTION, [ 'enabled' => true ] );
		$this->plugin()->signup()->flush();
		$this->join_page = $this->page_with( '[riviantrackr_signup]', 'join' );
		$this->set_settings( [ 'register_page_id' => $this->join_page ] );
		$this->post = self::factory()->post->create( [ 'comment_status' => 'open' ] );
		$this->go_to( get_permalink( $this->post ) );
		$GLOBALS['post'] = get_post( $this->post ); // Inside the loop, as single.php calls comment_form().
	}

	private function comment_form(): string {
		ob_start();
		comment_form( [], $this->post );
		return (string) ob_get_clean();
	}

	public function test_signed_out_visitors_get_the_card_above_the_fields(): void {
		$html = $this->comment_form();
		$back = rawurlencode( get_permalink( $this->post ) . '#respond' );

		$this->assertStringContainsString( 'Join the conversation', $html );
		$this->assertStringContainsString( esc_url( add_query_arg( 'redirect_to', $back, get_permalink( $this->join_page ) ) ), $html, 'join, then back to the comments' );
		$this->assertStringContainsString( esc_url( wp_login_url( get_permalink( $this->post ) . '#respond' ) ), $html, 'sign in, then back to the comments' );
		$this->assertLessThan( strpos( $html, 'name="comment"' ), strpos( $html, 'rtpk-comment-cta' ), 'above the fields' );
		$this->assertTrue( wp_style_is( CommentPrompt::STYLE, 'enqueued' ) );
	}

	public function test_members_see_the_plain_form(): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->assertStringNotContainsString( 'rtpk-comment-cta', $this->comment_form() );
	}

	public function test_members_only_comments_get_the_card_instead_of_the_login_line(): void {
		update_option( 'comment_registration', 1 );
		$html = $this->comment_form();

		$this->assertStringContainsString( 'Sign in to comment', $html );
		$this->assertStringNotContainsString( 'must be logged in', $html );
		$this->assertSame( 1, substr_count( $html, 'rtpk-comment-cta"' ), 'once, not twice' );
	}

	public function test_without_a_join_page_only_sign_in_is_offered(): void {
		$this->set_settings( [ 'register_page_id' => 0 ] );
		update_option( 'users_can_register', 0 );
		$html = $this->comment_form();

		$this->assertStringContainsString( 'rtpk-comment-cta', $html );
		$this->assertStringNotContainsString( 'Create a free account', $html );
	}

	public function test_the_owner_can_hide_it_per_post(): void {
		add_filter( 'riviantrackr_passkeys_comment_prompt', '__return_empty_string' );
		$this->assertStringNotContainsString( 'rtpk-comment-cta', $this->comment_form() );
		remove_filter( 'riviantrackr_passkeys_comment_prompt', '__return_empty_string' );
	}

	public function test_turning_the_setting_off_registers_nothing(): void {
		update_option( Settings::OPTION, [ 'comment_prompt' => false ] );
		$settings = new Settings();
		$prompt   = new CommentPrompt( $settings, $this->plugin()->signup() );
		$prompt->register_hooks();

		$this->assertFalse( has_action( 'comment_form_top', [ $prompt, 'render_top' ] ) );
		$this->assertFalse( has_filter( 'comment_form_defaults', [ $prompt, 'filter_defaults' ] ) );
	}
}
