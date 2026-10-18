<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\AccountPage;
use RivianTrackr\Passkeys\ChallengeStore;
use RivianTrackr\Passkeys\CredentialStore;
use RivianTrackr\Passkeys\ProfileManager;
use RivianTrackr\Passkeys\RateLimiter;
use RivianTrackr\Passkeys\RestController;
use RivianTrackr\Passkeys\Settings;
use RivianTrackr\Passkeys\WebAuthnService;

final class AccountPageTest extends TestCase {

	private AccountPage $page;

	protected function setUp(): void {
		rtpk_test_reset();
		$settings   = new Settings();
		$store      = new CredentialStore();
		$rest       = new RestController( $settings, $store, new ChallengeStore(), new RateLimiter(), new WebAuthnService( $settings, $store ) );
		$this->page = new AccountPage( $settings, $store, new ProfileManager( $settings, $store, $rest ) );
	}

	public function test_default_tabs_depend_on_what_the_tire_guide_offers(): void {
		$this->assertSame( [ 'overview', 'profile', 'security', 'reviews', 'favorites', 'comments' ], AccountPage::default_tab_ids( true, true ) );
		$this->assertSame( [ 'overview', 'profile', 'security', 'reviews', 'comments' ], AccountPage::default_tab_ids( true, false ), 'an older tire guide without favourites' );
		$this->assertSame( [ 'overview', 'profile', 'security', 'favorites', 'comments' ], AccountPage::default_tab_ids( false, true ) );
		$this->assertSame( [ 'overview', 'profile', 'security', 'comments' ], AccountPage::default_tab_ids( false, false ) );
	}

	public function test_tabs_are_built_from_the_defaults(): void {
		$with    = $this->page->tabs( true, true );
		$without = $this->page->tabs( false, false );

		$this->assertSame( AccountPage::default_tab_ids( true, true ), array_keys( $with ) );
		$this->assertSame( AccountPage::default_tab_ids( false, false ), array_keys( $without ) );
		$this->assertSame( 'Reviews', $with['reviews']['label'] );
		$this->assertStringContainsString( '<svg', $with['security']['icon'] );
		$this->assertTrue( is_callable( $with['profile']['render'] ) );
	}

	public function test_filter_can_add_remove_and_reorder_tabs(): void {
		$GLOBALS['rtpk_test_filter_hooks'][ AccountPage::FILTER ] = static function ( array $tabs ): array {
			$tabs = array_values( array_filter( $tabs, static fn( array $t ): bool => 'comments' !== $t['id'] ) );
			array_splice(
				$tabs,
				1,
				0,
				[
					[
						'id'     => 'Orders',
						'label'  => 'Orders',
						'render' => static fn(): string => '<p>orders</p>',
					],
				]
			);
			return $tabs;
		};

		$tabs = $this->page->tabs( false, false );
		$this->assertSame( [ 'overview', 'orders', 'profile', 'security' ], array_keys( $tabs ), 'id is slugified, comments removed, orders inserted' );
		$this->assertSame( '', $tabs['orders']['icon'] );
		$this->assertSame( '<p>orders</p>', call_user_func( $tabs['orders']['render'] ) );
	}

	public function test_normalize_drops_invalid_entries_and_duplicates(): void {
		$render = static fn(): string => '';
		$out    = AccountPage::normalize_tabs(
			[
				'not-an-array',
				[ 'id' => '', 'label' => 'No id', 'render' => $render ],
				[ 'id' => 'nolabel', 'label' => '  ', 'render' => $render ],
				[ 'id' => 'norender', 'label' => 'No render' ],
				[ 'id' => 'badrender', 'label' => 'Bad', 'render' => 'not_a_function_xyz' ],
				[ 'id' => 'ok', 'label' => 'OK', 'render' => $render, 'icon' => '<svg/>' ],
				[ 'id' => 'ok', 'label' => 'Duplicate', 'render' => $render ],
			]
		);
		$this->assertSame( [ 'ok' ], array_keys( $out ) );
		$this->assertSame( 'OK', $out['ok']['label'] );
		$this->assertSame( '<svg/>', $out['ok']['icon'] );
	}

	public function test_icons_and_stars_are_inline_svg(): void {
		$this->assertStringStartsWith( '<svg', AccountPage::icon( 'heart' ) );
		$this->assertStringContainsString( 'aria-hidden="true"', AccountPage::icon( 'unknown-name' ) );

		$stars = AccountPage::stars( 3.5 );
		$this->assertSame( 3, substr_count( $stars, 'is-full' ) );
		$this->assertSame( 1, substr_count( $stars, 'is-half' ) );
		$this->assertSame( 1, substr_count( $stars, 'is-empty' ) );
	}
}
