<?php
declare(strict_types=1);

use RivianTrackr\Passkeys\Garage;

/**
 * The garage routes, the shared filter, the page-wide script and the privacy
 * exporter inside WordPress.
 */
final class GarageIntegrationTest extends IntegrationTestCase {

	private int $member;

	public function set_up(): void {
		parent::set_up();
		$this->member = (int) self::factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_login' => 'jose',
				'user_email' => 'jose@example.org',
			]
		);
		wp_set_current_user( $this->member );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function r1s( array $over = [] ): array {
		return array_merge(
			[
				'model'    => 'r1s',
				'year'     => 2025,
				'nickname' => 'Big Blue',
				'wheels'   => [
					[
						'wheel_id' => 0,
						'size'     => '275/65r18',
						'name'     => 'Steelies',
						'label'    => 'Winter',
					],
				],
			],
			$over
		);
	}

	public function test_routes_need_a_signed_in_member(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'GET', 'account/garage' )->get_status() );
		$this->assertSame( 401, $this->rest( 'POST', 'account/garage/vehicles', $this->r1s() )->get_status() );
	}

	public function test_add_edit_default_and_remove_through_rest(): void {
		$added = $this->rest( 'POST', 'account/garage/vehicles', $this->r1s() );
		$this->assertSame( 200, $added->get_status(), wp_json_encode( $added->get_data() ) );
		$first = $added->get_data()['id'];
		$this->assertSame( 'gen2', $added->get_data()['garage']['default']['generation'] );
		$this->assertSame( [ '275/65R18' ], $added->get_data()['garage']['default']['sizes'] );

		$second = $this->rest( 'POST', 'account/garage/vehicles', $this->r1s( [ 'model' => 'r1t', 'year' => 2023, 'nickname' => '', 'make_default' => true ] ) )->get_data()['id'];
		$this->assertSame( $second, Garage::for_user( $this->member )['default_id'] );

		$edited = $this->rest( 'POST', 'account/garage/vehicles/' . $first, $this->r1s( [ 'nickname' => 'Blue' ] ) );
		$this->assertSame( 200, $edited->get_status() );
		$this->assertSame( 'Blue', $edited->get_data()['garage']['vehicles'][0]['nickname'] );

		$this->assertSame( 200, $this->rest( 'POST', 'account/garage/default', [ 'id' => $first ] )->get_status() );
		$this->assertSame( $first, Garage::for_user( $this->member )['default_id'] );

		$this->assertSame( 200, $this->rest( 'DELETE', 'account/garage/vehicles/' . $first )->get_status() );
		$this->assertSame( $second, $this->rest( 'GET', 'account/garage' )->get_data()['garage']['default_id'] );
		$this->assertSame( 404, $this->rest( 'DELETE', 'account/garage/vehicles/' . $first )->get_status() );
	}

	public function test_invalid_vehicle_answers_with_field_errors(): void {
		$response = $this->rest( 'POST', 'account/garage/vehicles', $this->r1s( [ 'year' => 2019, 'wheels' => [ [ 'wheel_id' => 0, 'size' => 'big' ] ] ] ) );
		$this->assertSame( 400, $response->get_status() );
		$fields = $response->get_data()['data']['fields'];
		$this->assertArrayHasKey( 'year', $fields );
		$this->assertArrayHasKey( 'wheels.0.size', $fields );
		$this->assertSame( [], Garage::stored( $this->member )['vehicles'] );
	}

	public function test_other_plugins_read_the_garage_through_the_filter(): void {
		$this->rest( 'POST', 'account/garage/vehicles', $this->r1s() );

		$garage = apply_filters( 'riviantrackr_garage', null, get_current_user_id() );
		$this->assertSame( 'r1', $garage['default']['platform'] );
		$this->assertSame( 'R1', $garage['default']['group'] );

		wp_set_current_user( 0 );
		$this->assertSame( [], apply_filters( 'riviantrackr_garage', null )['vehicles'] );
	}

	public function test_the_garage_is_printed_for_front_end_scripts(): void {
		$this->rest( 'POST', 'account/garage/vehicles', $this->r1s() );
		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_script_is( Garage::SCRIPT, 'enqueued' ) );
		$inline = implode( '', (array) wp_scripts()->get_data( Garage::SCRIPT, 'before' ) );
		$this->assertStringContainsString( 'window.riviantrackrGarage = ', $inline );
		$this->assertStringContainsString( '"signedIn":true', $inline );
		$this->assertStringContainsString( '"platform":"r1"', $inline );
	}

	public function test_privacy_export_lists_the_vehicles_and_deleting_the_account_drops_them(): void {
		$this->rest( 'POST', 'account/garage/vehicles', $this->r1s() );

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );
		$this->assertArrayHasKey( 'riviantrackr-garage', $exporters );
		$export = call_user_func( $exporters['riviantrackr-garage']['callback'], 'jose@example.org', 1 );
		$this->assertSame( '2025 R1S Gen 2', $export['data'][0]['data'][0]['value'] );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->member );
		$this->assertSame( '', get_user_meta( $this->member, Garage::META, true ) );
	}
}
