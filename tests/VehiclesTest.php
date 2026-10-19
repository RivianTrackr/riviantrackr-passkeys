<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\Vehicles;

final class VehiclesTest extends TestCase {

	protected function setUp(): void {
		rtpk_test_reset();
	}

	public function test_built_in_models_map_onto_the_sibling_plugins_slugs(): void {
		$models = Vehicles::models();

		$this->assertSame( [ 'r1t', 'r1s', 'r2' ], array_keys( $models ) );
		$this->assertSame( 'R1S', $models['r1s']['label'], 'the Tire Guide review vehicle' );
		$this->assertSame( 'r1', $models['r1s']['platform'], 'the Software Updates vehicle' );
		$this->assertSame( 'R1', $models['r1t']['group'], 'the Tire Guide filter' );
		$this->assertSame( [ 'gen1', 'gen2' ], array_keys( $models['r1t']['generations'] ), 'Software Updates generation slugs' );
		$this->assertSame( [ 'gen1' ], array_keys( $models['r2']['generations'] ) );
	}

	public function test_generation_follows_the_model_year(): void {
		$r1 = Vehicles::model( 'r1t' );
		$this->assertSame( 'gen1', Vehicles::generation_for_year( $r1, 2022 ) );
		$this->assertSame( 'gen1', Vehicles::generation_for_year( $r1, 2024 ) );
		$this->assertSame( 'gen2', Vehicles::generation_for_year( $r1, 2025 ) );
		$this->assertSame( 'gen2', Vehicles::generation_for_year( $r1, 2031 ), 'the current generation has no end year' );
		$this->assertSame( '', Vehicles::generation_for_year( $r1, 2019 ) );
		$this->assertSame( '', Vehicles::generation_for_year( Vehicles::model( 'r2' ), 2025 ) );
	}

	public function test_years_run_newest_first_up_to_next_year(): void {
		$this->assertSame( [ 2027, 2026, 2025, 2024, 2023, 2022 ], Vehicles::years( Vehicles::model( 'r1s' ), 2026 ) );
		$this->assertSame( [ 2027, 2026 ], Vehicles::years( Vehicles::model( 'r2' ), 2026 ) );
		$this->assertSame( [ 2026 ], Vehicles::years( Vehicles::model( 'r2' ), 2024 ), 'never before the first year' );

		$retired = [ 'generations' => [ 'gen1' => [ 2022, 2024 ] ] ];
		$this->assertSame( [ 2024, 2023, 2022 ], Vehicles::years( $retired, 2026 ), 'a retired model stops at its last year' );
	}

	public function test_generation_labels(): void {
		$this->assertSame( 'Gen 1', Vehicles::generation_label( 'gen1' ) );
		$this->assertSame( 'Gen 12', Vehicles::generation_label( 'gen12' ) );
		$this->assertSame( 'launch', Vehicles::generation_label( 'launch' ) );
	}

	public function test_filter_adds_models_and_bad_entries_are_dropped(): void {
		$GLOBALS['rtpk_test_filter_hooks'][ Vehicles::FILTER ] = static function ( array $models ): array {
			$models['r3']     = [
				'label'       => 'R3',
				'platform'    => 'r3',
				'group'       => 'R3',
				'generations' => [ 'gen1' => [ 2027, 0 ] ],
			];
			$models['nolabel'] = [
				'platform'    => 'x',
				'group'       => 'X',
				'generations' => [ 'gen1' => [ 2027, 0 ] ],
			];
			$models['nogens']  = [
				'label'       => 'No gens',
				'platform'    => 'x',
				'group'       => 'X',
				'generations' => [ 'gen1' => [ 2027, 2020 ] ],
			];
			$models['r2']['generations']['gen0'] = [ 1800, 0 ];
			return $models;
		};

		$models = Vehicles::models();
		$this->assertSame( [ 'r1t', 'r1s', 'r2', 'r3' ], array_keys( $models ) );
		$this->assertSame( [ 'gen1' ], array_keys( $models['r2']['generations'] ), 'an implausible year range is dropped' );
	}

	public function test_catalog_lists_years_and_generation_ranges(): void {
		$catalog = Vehicles::catalog( 2026 );

		$this->assertSame( 'R1T', $catalog['r1t']['label'] );
		$this->assertSame( 'R1', $catalog['r1t']['group'] );
		$this->assertSame( 2027, $catalog['r1t']['years'][0] );
		$this->assertSame(
			[
				[ 'slug' => 'gen1', 'label' => 'Gen 1', 'from' => 2022, 'to' => 2024 ],
				[ 'slug' => 'gen2', 'label' => 'Gen 2', 'from' => 2025, 'to' => 0 ],
			],
			$catalog['r1s']['generations']
		);
	}
}
