<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\TireGuide;

/**
 * An old tire guide build: RTG_Database exists but only offers get_tire().
 * The bridge must fall back to the tables instead of fataling.
 */
class RTG_Database {
	public static function get_tire( $tire_id ) {
		return 'tire001' === $tire_id ? [ 'tire_id' => 'tire001', 'slug' => 'pirelli-scorpion', 'brand' => 'Pirelli', 'model' => 'Scorpion', 'size' => '275/65R20', 'image' => '' ] : null;
	}
}

final class TireGuideTest extends TestCase {

	/** @var string[] */
	private array $tables = [];

	protected function setUp(): void {
		rtpk_test_reset();
		$test            = $this;
		$GLOBALS['wpdb'] = new class( $test ) extends RTPK_Fake_Wpdb {
			public array $queries = [];
			public function __construct( private TireGuideTest $test ) {}
			public function prepare( $query, ...$args ) {
				return vsprintf( str_replace( '%d', '%s', (string) $query ), array_map( 'strval', $args ) );
			}
			public function get_var( $query ) {
				$this->queries[] = $query;
				$query           = stripslashes( (string) $query ); // esc_like() escapes underscores.
				foreach ( $this->test->tables() as $table ) {
					if ( str_contains( $query, 'SHOW TABLES' ) && str_contains( $query, $table ) ) {
						return 'wp_' . $table;
					}
				}
				return null;
			}
			public function get_col( $query ) {
				$this->queries[] = $query;
				return str_contains( $query, 'rtg_favorites' ) ? [ 'tire001', 'tire999' ] : [];
			}
			public function get_results( $query, $output = null ) {
				$this->queries[] = $query;
				return str_contains( $query, 'rtg_ratings' ) ? [ [ 'id' => 1, 'tire_id' => 'tire001', 'rating' => 4, 'review_title' => 'Good', 'review_text' => 'Quiet.', 'review_status' => 'approved', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00', 'brand' => 'Pirelli', 'model' => 'Scorpion', 'image' => '' ] ] : [];
			}
		};
	}

	/** @return string[] */
	public function tables(): array {
		return $this->tables;
	}

	public function test_missing_helpers_fall_back_to_the_tables(): void {
		$this->tables = [ 'rtg_ratings', 'rtg_favorites', 'rtg_tires' ];

		$this->assertTrue( TireGuide::supports_reviews() );
		$this->assertTrue( TireGuide::supports_favorites() );

		$favorites = TireGuide::favorites( 7 );
		$this->assertSame( [ 'tire001', 'tire999' ], array_column( $favorites, 'tire_id' ) );
		$this->assertSame( 'Pirelli Scorpion', $favorites[0]['tire'] );
		$this->assertSame( 'https://riviantrackr.test/tires/pirelli-scorpion/', $favorites[0]['url'] );
		$this->assertSame( 'tire999', $favorites[1]['tire'], 'unknown tire keeps its id as the label' );
		$this->assertSame( '', $favorites[1]['url'] );
		$this->assertSame( 2, TireGuide::favorite_count( 7 ) );

		$reviews = TireGuide::reviews( 7 );
		$this->assertCount( 1, $reviews );
		$this->assertSame( 'approved', $reviews[0]['status'] );
		$this->assertSame( 'Good', $reviews[0]['title'] );
		$this->assertSame( 1, TireGuide::review_count( 7 ) );

		$this->assertTrue( TireGuide::remove_favorite( 'tire001', 7 ) );
		$this->assertTrue( TireGuide::delete_review( 'tire001', 7 ) );
	}

	/**
	 * The account page's Edit button opens the tire guide's review page with
	 * the member's review loaded. Without RTG_Tire_Review::url() (older
	 * builds, and this suite) the URL is assembled from the slug setting.
	 */
	public function test_edit_links_open_the_review_page_with_the_review_loaded(): void {
		$this->tables = [ 'rtg_ratings', 'rtg_tires' ];

		$this->assertSame( 'https://riviantrackr.test/tire-review/?tire=tire001&edit=1', TireGuide::review_edit_url( 'tire001' ) );
		$this->assertSame( '', TireGuide::review_edit_url( '' ), 'no tire, no link' );

		update_option( 'rtg_settings', [ 'tire_review_slug' => 'Review a Tire' ] );
		$this->assertSame( 'https://riviantrackr.test/review-a-tire/?tire=tire001&edit=1', TireGuide::review_edit_url( 'tire001' ), 'the slug setting is honored' );

		$reviews = TireGuide::reviews( 7 );
		$this->assertSame( 'https://riviantrackr.test/review-a-tire/?tire=tire001&edit=1', $reviews[0]['edit_url'] );
	}
}
