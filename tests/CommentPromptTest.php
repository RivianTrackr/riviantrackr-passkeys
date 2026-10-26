<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\CommentPrompt;
use RivianTrackr\Passkeys\Settings;

final class CommentPromptTest extends TestCase {

	private const POST = 'https://riviantrackr.test/news/epa-range/#respond';

	protected function setUp(): void {
		rtpk_test_reset();
	}

	public function test_join_link_carries_the_return_address_to_the_join_page(): void {
		$this->assertSame(
			'https://riviantrackr.test/join/?redirect_to=' . rawurlencode( self::POST ),
			CommentPrompt::join_link( 'https://riviantrackr.test/join/', true, '', self::POST )
		);
		$this->assertSame( 'https://riviantrackr.test/join/', CommentPrompt::join_link( 'https://riviantrackr.test/join/', true, '', '' ), 'no post, no return address' );
	}

	public function test_join_link_falls_back_to_core_registration_or_nothing(): void {
		$core = 'https://riviantrackr.test/wp-login.php?action=register';
		$this->assertSame( $core, CommentPrompt::join_link( 'https://riviantrackr.test/join/', false, $core, self::POST ), 'signups off' );
		$this->assertSame( $core, CommentPrompt::join_link( '', true, $core, self::POST ), 'no join page' );
		$this->assertSame( '', CommentPrompt::join_link( '', false, '', self::POST ), 'nobody can join' );
	}

	public function test_card_asks_signed_out_visitors_to_join_or_sign_in(): void {
		$html = CommentPrompt::card( 'https://riviantrackr.test/sign-in/?redirect_to=x', 'https://riviantrackr.test/join/?redirect_to=x', false, false );

		$this->assertStringContainsString( 'Join the conversation', $html );
		$this->assertStringContainsString( 'Comment under your own name and photo, skip the fields below, and sign in with Face ID or Touch ID instead of a password.', $html );
		$this->assertStringContainsString( 'class="rtpk-comment-cta__btn is-primary" href="https://riviantrackr.test/join/?redirect_to=x">Create a free account</a>', $html );
		$this->assertStringContainsString( 'class="rtpk-comment-cta__btn" href="https://riviantrackr.test/sign-in/?redirect_to=x">Sign in</a>', $html );
		$this->assertLessThan( strpos( $html, 'Sign in</a>' ), strpos( $html, 'Create a free account' ), 'joining comes first' );
		$this->assertStringNotContainsString( 'is-light', $html );
		$this->assertStringStartsWith( '<aside class="rtpk-comment-cta">', $html );
	}

	public function test_card_without_a_way_to_join_makes_sign_in_the_primary_button(): void {
		$html = CommentPrompt::card( 'https://riviantrackr.test/sign-in/', '', false, true );

		$this->assertStringNotContainsString( 'Create a free account', $html );
		$this->assertStringContainsString( 'class="rtpk-comment-cta__btn is-primary" href="https://riviantrackr.test/sign-in/">Sign in</a>', $html );
		$this->assertStringContainsString( 'skip the name and email fields', $html );
		$this->assertStringContainsString( 'rtpk-comment-cta is-light', $html );
	}

	public function test_card_for_members_only_comments(): void {
		$html = CommentPrompt::card( 'https://riviantrackr.test/sign-in/', 'https://riviantrackr.test/join/', true, false );
		$this->assertStringContainsString( 'Sign in to comment', $html );
		$this->assertStringContainsString( 'Comments are open to members', $html );
		$this->assertStringContainsString( 'Create a free account', $html );

		$this->assertStringContainsString( 'Sign in to join the conversation', CommentPrompt::card( 'https://riviantrackr.test/sign-in/', '', true, false ) );
	}

	public function test_card_escapes_urls(): void {
		$html = CommentPrompt::card( 'https://riviantrackr.test/sign-in/?a="><script>', '', false, false );
		$this->assertStringNotContainsString( '"><script>', $html );
	}

	public function test_setting_is_on_by_default_and_can_be_turned_off(): void {
		$this->assertTrue( ( new Settings() )->prompts_commenters(), 'on before the settings are ever saved' );

		$this->assertTrue( ( new Settings() )->sanitize( [ 'comment_prompt' => '1' ] )['comment_prompt'] );
		$off = ( new Settings() )->sanitize( [] );
		$this->assertFalse( $off['comment_prompt'], 'an unchecked toggle saves as off' );

		update_option( RTPK_OPTION_NAME, $off );
		$this->assertFalse( ( new Settings() )->prompts_commenters() );
	}
}
