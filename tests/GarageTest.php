<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\Garage;
use RivianTrackr\Passkeys\Settings;
use RivianTrackr\Passkeys\TireGuide;
use RivianTrackr\Passkeys\Vehicles;

final class GarageTest extends TestCase {

	/** @var array<int,array<string,mixed>> */
	private array $wheels;

	protected function setUp(): void {
		rtpk_test_reset();
		$GLOBALS['wpdb'] = new RTPK_Fake_Wpdb();
		$this->wheels    = TireGuide::normalize_wheels(
			[
				[ 'id' => 3, 'name' => '22" Sport Dark', 'stock_size' => '275/50R22', 'alt_sizes' => '', 'vehicles' => 'R1T, R1S', 'source' => 'oem' ],
				[ 'id' => 4, 'name' => '20" All-Terrain', 'stock_size' => '275/60R20', 'alt_sizes' => '275/55R20, 275/60R20', 'vehicles' => 'R1T, R1S', 'source' => 'oem' ],
				[ 'id' => 7, 'name' => '21" Sport', 'stock_size' => '255/45R21', 'alt_sizes' => '', 'vehicles' => 'R2', 'source' => 'oem' ],
				[ 'id' => 9, 'name' => 'Method 703 18"', 'stock_size' => '35x12.50R18', 'alt_sizes' => '', 'vehicles' => 'R1T', 'source' => 'third_party' ],
			]
		);
	}

	private function input( array $over = [] ): array {
		return array_merge(
			[
				'model'      => 'r1s',
				'year'       => 2025,
				'generation' => '',
				'nickname'   => 'Big Blue',
				'wheels'     => [
					[ 'wheel_id' => 3, 'size' => '', 'label' => 'Summer' ],
				],
			],
			$over
		);
	}

	private function check( array $over = [] ): array {
		return Garage::validate_vehicle( $this->input( $over ), Vehicles::models(), $this->wheels, 2026 );
	}

	// ---- wheels from the tire guide ----------------------------------------

	public function test_tire_guide_wheels_are_normalized(): void {
		$this->assertSame( [ '275/60R20', '275/55R20' ], $this->wheels[1]['sizes'], 'stock size first, alternates de-duplicated' );
		$this->assertSame( [ 'R1T', 'R1S' ], $this->wheels[0]['vehicles'] );
		$this->assertTrue( $this->wheels[3]['third_party'] );
		$this->assertSame( '35x12.50R18', $this->wheels[3]['sizes'][0], 'flotation sizes keep a lower-case x' );
		$this->assertSame( [], TireGuide::normalize_wheels( [ [ 'id' => 1, 'name' => '', 'stock_size' => '275/65R18' ], [ 'id' => 2, 'name' => 'No size' ], 'junk' ] ) );
	}

	// ---- validation --------------------------------------------------------

	public function test_valid_vehicle_takes_its_generation_from_the_year_and_the_wheel_from_the_guide(): void {
		$checked = $this->check();

		$this->assertSame( [], $checked['errors'] );
		$this->assertSame( 'gen2', $checked['data']['generation'] );
		$this->assertSame(
			[
				[ 'wheel_id' => 3, 'name' => '22" Sport Dark', 'size' => '275/50R22', 'label' => 'Summer' ],
			],
			$checked['data']['wheels']
		);
	}

	public function test_a_chosen_generation_is_kept(): void {
		$this->assertSame( 'gen1', $this->check( [ 'generation' => 'gen1' ] )['data']['generation'] );
		$this->assertArrayHasKey( 'generation', $this->check( [ 'generation' => 'gen7' ] )['errors'] );
	}

	public function test_model_and_year_are_required_and_checked(): void {
		$this->assertArrayHasKey( 'model', $this->check( [ 'model' => 'cybertruck' ] )['errors'] );
		$this->assertArrayHasKey( 'year', $this->check( [ 'year' => 2019 ] )['errors'] );
		$this->assertArrayHasKey( 'year', $this->check( [ 'model' => 'r2', 'year' => 2024, 'wheels' => [] ] )['errors'], 'R2 starts in 2026' );
		$this->assertArrayHasKey( 'year', $this->check( [ 'year' => 2030 ] )['errors'], 'no further ahead than next year' );
	}

	public function test_nickname_is_plain_text_and_short(): void {
		$this->assertSame( 'Blue', $this->check( [ 'nickname' => '<b>Blue</b>' ] )['data']['nickname'] );
		$this->assertArrayHasKey( 'nickname', $this->check( [ 'nickname' => str_repeat( 'a', 41 ) ] )['errors'] );
	}

	public function test_several_wheel_sets_including_an_alternate_size_and_a_typed_one(): void {
		$checked = $this->check(
			[
				'wheels' => [
					[ 'wheel_id' => 4, 'size' => '275/55r20', 'label' => 'Winter' ],
					[ 'wheel_id' => 0, 'size' => ' lt285 / 70r17 ', 'name' => 'Method 701', 'label' => 'Off-road' ],
					[ 'wheel_id' => 0, 'size' => '', 'name' => '', 'label' => '' ],
				],
			]
		);

		$this->assertSame( [], $checked['errors'] );
		$this->assertSame( '275/55R20', $checked['data']['wheels'][0]['size'], 'an alternate size of the guide wheel' );
		$this->assertSame( 'LT285/70R17', $checked['data']['wheels'][1]['size'] );
		$this->assertSame( 'Method 701', $checked['data']['wheels'][1]['name'] );
		$this->assertCount( 2, $checked['data']['wheels'], 'a blank row is skipped' );
	}

	public function test_wheel_errors_name_the_row_and_field(): void {
		$errors = $this->check(
			[
				'wheels' => [
					[ 'wheel_id' => 7, 'size' => '' ],
					[ 'wheel_id' => 4, 'size' => '275/65R18' ],
					[ 'wheel_id' => 0, 'size' => 'big ones' ],
					[ 'wheel_id' => 42, 'size' => '' ],
				],
			]
		)['errors'];

		$this->assertArrayHasKey( 'wheels.0.wheel', $errors, 'an R2 wheel on an R1S' );
		$this->assertArrayHasKey( 'wheels.1.size', $errors, 'not a size this wheel takes' );
		$this->assertArrayHasKey( 'wheels.2.size', $errors, 'not a tire size' );
		$this->assertArrayHasKey( 'wheels.3.wheel', $errors, 'not in the guide' );
	}

	public function test_an_aftermarket_wheel_for_another_model_is_refused_but_a_typed_size_is_fine(): void {
		$this->assertArrayHasKey( 'wheels.0.wheel', $this->check( [ 'wheels' => [ [ 'wheel_id' => 9 ] ] ] )['errors'], 'listed for the R1T only' );
		$this->assertSame( [], $this->check( [ 'model' => 'r1t', 'wheels' => [ [ 'wheel_id' => 9 ] ] ] )['errors'] );
	}

	public function test_a_wheel_listed_for_the_group_or_for_everyone_fits(): void {
		$wheels = TireGuide::normalize_wheels(
			[
				[ 'id' => 1, 'name' => 'Old entry', 'stock_size' => '275/65R20', 'vehicles' => 'R1' ],
				[ 'id' => 2, 'name' => 'Universal', 'stock_size' => '275/65R18', 'vehicles' => '' ],
			]
		);
		$checked = Garage::validate_vehicle( $this->input( [ 'wheels' => [ [ 'wheel_id' => 1 ], [ 'wheel_id' => 2 ] ] ] ), Vehicles::models(), $wheels, 2026 );
		$this->assertSame( [], $checked['errors'] );
		$this->assertFalse( Garage::wheel_fits( $wheels[0], Vehicles::model( 'r2' ) ) );
	}

	public function test_up_to_four_wheel_sets(): void {
		$set = [ 'wheel_id' => 3 ];
		$this->assertSame( [], $this->check( [ 'wheels' => array_fill( 0, 4, $set ) ] )['errors'] );
		$this->assertArrayHasKey( 'wheels', $this->check( [ 'wheels' => array_fill( 0, 5, $set ) ] )['errors'] );
	}

	public function test_tire_sizes(): void {
		foreach ( [ '275/65R18', 'LT285/70R17', 'P265/70R17', '305/40ZR22', '35x12.50R17', '33x12.5R18LT', '275/65R18LT' ] as $size ) {
			$this->assertTrue( Garage::is_tire_size( Garage::normalize_size( $size ) ), $size );
		}
		foreach ( [ '', '275/65', 'R18', '275-65R18', '22 inch', '2756518' ] as $size ) {
			$this->assertFalse( Garage::is_tire_size( Garage::normalize_size( $size ) ), $size );
		}
		$this->assertSame( '35x12.50R17', Garage::normalize_size( '35 X 12.50 r17' ) );
	}

	// ---- storage -------------------------------------------------------------

	public function test_first_vehicle_becomes_the_default_and_others_can_take_over(): void {
		$first  = Garage::add( 7, $this->check()['data'] );
		$second = Garage::add( 7, $this->check( [ 'model' => 'r2', 'year' => 2026, 'nickname' => '', 'wheels' => [ [ 'wheel_id' => 7 ] ] ] )['data'] );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{8}$/', $first );
		$garage = Garage::for_user( 7, $this->wheels );
		$this->assertSame( $first, $garage['default_id'] );
		$this->assertSame( [ $first, $second ], array_column( $garage['vehicles'], 'id' ) );

		$this->assertTrue( Garage::set_default( 7, $second ) );
		$this->assertSame( $second, Garage::for_user( 7, $this->wheels )['default']['id'] );
		$this->assertFalse( Garage::set_default( 7, 'deadbeef' ) );

		$third = Garage::add( 7, $this->check()['data'], true );
		$this->assertSame( $third, Garage::for_user( 7, $this->wheels )['default_id'], 'added with make_default' );
	}

	public function test_described_vehicle_carries_every_plugins_vocabulary(): void {
		Garage::add( 7, $this->check( [ 'wheels' => [ [ 'wheel_id' => 3, 'label' => 'Summer' ], [ 'wheel_id' => 0, 'size' => '275/65r18', 'name' => 'Steelies' ] ] ] )['data'] );
		$vehicle = Garage::for_user( 7, $this->wheels )['default'];

		$this->assertSame( 'r1s', $vehicle['model'] );
		$this->assertSame( 'R1S', $vehicle['model_label'] );
		$this->assertSame( '2025 R1S', $vehicle['label'] );
		$this->assertSame( 'gen2', $vehicle['generation'] );
		$this->assertSame( 'Gen 2', $vehicle['generation_label'] );
		$this->assertSame( 'r1', $vehicle['platform'] );
		$this->assertSame( 'R1', $vehicle['group'] );
		$this->assertSame( 'Big Blue', $vehicle['nickname'] );
		$this->assertSame( [ '275/50R22', '275/65R18' ], $vehicle['sizes'] );
		$this->assertFalse( $vehicle['wheels'][0]['third_party'] );
		$this->assertSame( 'Steelies', $vehicle['wheels'][1]['name'] );
		$this->assertTrue( $vehicle['is_default'] );
	}

	public function test_guide_wheels_are_read_fresh_and_a_dropped_size_falls_back_to_stock(): void {
		Garage::add( 7, $this->check( [ 'wheels' => [ [ 'wheel_id' => 4, 'size' => '275/55R20' ] ] ] )['data'] );

		$renamed = TireGuide::normalize_wheels( [ [ 'id' => 4, 'name' => '20" Dark All-Terrain', 'stock_size' => '275/60R20', 'vehicles' => 'R1S' ] ] );
		$wheel   = Garage::for_user( 7, $renamed )['default']['wheels'][0];
		$this->assertSame( '20" Dark All-Terrain', $wheel['name'] );
		$this->assertSame( '275/60R20', $wheel['size'] );

		$gone = Garage::for_user( 7, [] )['default']['wheels'][0];
		$this->assertSame( '20" All-Terrain', $gone['name'], 'the saved name when the guide is gone' );
		$this->assertSame( 0, $gone['wheel_id'] );
		$this->assertSame( '275/55R20', $gone['size'] );
	}

	public function test_update_keeps_the_id_and_remove_hands_the_default_on(): void {
		$first  = Garage::add( 7, $this->check()['data'] );
		$second = Garage::add( 7, $this->check( [ 'nickname' => 'Truck', 'model' => 'r1t' ] )['data'] );

		$this->assertTrue( Garage::update( 7, $second, $this->check( [ 'model' => 'r1t', 'year' => 2023, 'nickname' => 'Old truck' ] )['data'] ) );
		$vehicle = Garage::for_user( 7, $this->wheels )['vehicles'][1];
		$this->assertSame( $second, $vehicle['id'] );
		$this->assertSame( 'gen1', $vehicle['generation'] );
		$this->assertSame( 'Old truck', $vehicle['nickname'] );
		$this->assertFalse( Garage::update( 7, 'deadbeef', $this->check()['data'] ) );

		$this->assertTrue( Garage::remove( 7, $first ) );
		$this->assertSame( $second, Garage::for_user( 7, $this->wheels )['default_id'] );
		$this->assertFalse( Garage::remove( 7, $first ) );

		$this->assertTrue( Garage::remove( 7, $second ) );
		$this->assertSame( '', get_user_meta( 7, Garage::META, true ), 'an empty garage leaves no meta behind' );
		$this->assertNull( Garage::for_user( 7, $this->wheels )['default'] );
	}

	public function test_garage_holds_five_vehicles(): void {
		for ( $i = 0; $i < Garage::MAX_VEHICLES; $i++ ) {
			$this->assertIsString( Garage::add( 7, $this->check()['data'] ) );
		}
		$full = Garage::add( 7, $this->check()['data'] );
		$this->assertInstanceOf( WP_Error::class, $full );
		$this->assertSame( 'rtpk_garage_full', $full->get_error_code() );
	}

	public function test_every_change_announces_the_new_garage(): void {
		$id = Garage::add( 7, $this->check()['data'] );
		Garage::remove( 7, $id );

		$fired = array_values( array_filter( $GLOBALS['rtpk_test_actions'], static fn( array $a ): bool => Garage::UPDATED === $a[0] ) );
		$this->assertCount( 2, $fired );
		$this->assertSame( 7, $fired[0][1][0] );
		$this->assertSame( $id, $fired[0][1][1]['default_id'] );
		$this->assertSame( [], $fired[1][1][1]['vehicles'] );
	}

	public function test_stored_garage_is_tidied(): void {
		update_user_meta(
			7,
			Garage::META,
			[
				'vehicles' => [
					'junk',
					[ 'id' => '', 'model' => 'r1s' ],
					[ 'id' => 'aaaa1111', 'model' => 'r1s', 'year' => '2025', 'generation' => 'gen2', 'wheels' => [ [ 'size' => '' ], [ 'size' => '275/65R18', 'name' => 'Steelies' ] ] ],
				],
				'default'  => 'gone0000',
			]
		);
		$stored = Garage::stored( 7 );

		$this->assertCount( 1, $stored['vehicles'] );
		$this->assertSame( 2025, $stored['vehicles'][0]['year'] );
		$this->assertCount( 1, $stored['vehicles'][0]['wheels'] );
		$this->assertSame( 'aaaa1111', $stored['default'], 'a missing default falls back to the first vehicle' );
	}

	// ---- for other plugins ---------------------------------------------------

	public function test_filter_answers_for_the_signed_in_member_or_with_an_empty_garage(): void {
		$garage = new Garage( new Settings() );
		$this->assertSame( [], $garage->filter_garage( null )['vehicles'], 'signed out' );

		Garage::add( 7, $this->check()['data'] );
		$GLOBALS['rtpk_test_current_user'] = new WP_User( 7 );
		$this->assertCount( 1, $garage->filter_garage( null )['vehicles'] );
		$this->assertCount( 1, $garage->filter_garage( null, 7 )['vehicles'] );
		$this->assertCount( 0, $garage->filter_garage( null, 8 )['vehicles'] );
	}

	public function test_bootstrap_data_for_members_and_visitors(): void {
		Garage::add( 7, $this->check()['data'] );
		$urls = [
			'account' => 'https://riviantrackr.test/account/',
			'join'    => 'https://riviantrackr.test/join/',
			'signIn'  => 'https://riviantrackr.test/sign-in/',
		];

		$member = Garage::bootstrap_data( Garage::for_user( 7, $this->wheels ), $urls );
		$this->assertTrue( $member['signedIn'] );
		$this->assertCount( 1, $member['vehicles'] );
		$this->assertSame( $member['vehicles'][0]['id'], $member['defaultId'] );
		$this->assertSame( 'https://riviantrackr.test/account/#garage', $member['garageUrl'] );

		$visitor = Garage::bootstrap_data( null, $urls );
		$this->assertFalse( $visitor['signedIn'] );
		$this->assertSame( [], $visitor['vehicles'] );
		$this->assertSame( 'https://riviantrackr.test/join/', $visitor['joinUrl'] );
		$this->assertSame( '', Garage::bootstrap_data( null, [] )['garageUrl'] );
	}

	public function test_call_to_action_depends_on_who_is_looking(): void {
		$visitor = Garage::cta( false, 0, '/account/', '/join/', '/sign-in/', false );
		$this->assertStringContainsString( 'Save your Rivian', $visitor );
		$this->assertStringContainsString( 'href="/join/"', $visitor );
		$this->assertStringContainsString( 'href="/sign-in/"', $visitor );

		$no_join = Garage::cta( false, 0, '/account/', '', '/sign-in/', true );
		$this->assertStringNotContainsString( 'Create a free account', $no_join );
		$this->assertStringContainsString( 'is-light', $no_join );
		$this->assertStringContainsString( 'rtpk-garage-cta__btn is-primary" href="/sign-in/"', $no_join, 'sign in becomes the main button' );

		$empty = Garage::cta( true, 0, '/account/', '/join/', '/sign-in/', false );
		$this->assertStringContainsString( 'Add your Rivian', $empty );
		$this->assertStringContainsString( 'href="/account/#garage"', $empty );

		$this->assertSame( '', Garage::cta( true, 1, '/account/', '/join/', '/sign-in/', false ), 'a member with a vehicle' );
		$this->assertSame( '', Garage::cta( true, 0, '', '/join/', '/sign-in/', false ), 'nowhere to send them' );
	}

	public function test_privacy_export_and_erase(): void {
		$GLOBALS['rtpk_test_users'][7] = new WP_User( 7, 'jose', 'Jose', 'jose@riviantrackr.test' );
		Garage::add( 7, $this->check( [ 'wheels' => [ [ 'wheel_id' => 3, 'label' => 'Summer' ] ] ] )['data'] );

		$export = Garage::export( 'jose@riviantrackr.test' );
		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$values = array_column( $export['data'][0]['data'], 'value', 'name' );
		$this->assertSame( '2025 R1S Gen 2', $values['Vehicle'] );
		$this->assertSame( 'Big Blue', $values['Nickname'] );
		$this->assertSame( 'Summer · 22" Sport Dark · 275/50R22', $values['Wheels'] );
		$this->assertSame( [], Garage::export( 'nobody@riviantrackr.test' )['data'] );

		$this->assertTrue( Garage::erase( 'jose@riviantrackr.test' )['items_removed'] );
		$this->assertSame( [], Garage::stored( 7 )['vehicles'] );
		$this->assertFalse( Garage::erase( 'jose@riviantrackr.test' )['items_removed'], 'nothing left' );

		$garage = new Garage( new Settings() );
		$this->assertArrayHasKey( 'riviantrackr-garage', $garage->register_exporter( [] ) );
		$this->assertArrayHasKey( 'riviantrackr-garage', $garage->register_eraser( [] ) );
	}
}
