<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shared vehicle vocabulary for RivianTrackr: which Rivians a member can
 * put in their garage, the model years and generations each one comes in,
 * and how each maps onto the slugs the sibling plugins already use.
 *
 * A model carries:
 *   label        what members see ("R1S")
 *   platform     the Software Updates vehicle slug (`r1`, `r2`), whose
 *                generation slugs (`gen1`, `gen2`) are reused as-is
 *   group        the Tire Guide's vehicle filter (`R1`, `R2`); its reviews
 *                and wheels use the label (`R1T`, `R1S`, `R2`)
 *   generations  slug => [ first model year, last model year or 0 for current ]
 *
 * Add a model (R3 once it ships) or a generation through the
 * `riviantrackr_garage_models` filter; entries missing any of the above are
 * dropped.
 */
final class Vehicles {

	public const FILTER = 'riviantrackr_garage_models';

	/** First model year a picker offers when a model forgets to say. */
	private const EARLIEST_YEAR = 2022;

	/**
	 * The built-in models. Pure.
	 *
	 * @return array<string,array{label:string,platform:string,group:string,generations:array<string,array{0:int,1:int}>}>
	 */
	public static function defaults(): array {
		$r1 = [
			'gen1' => [ 2022, 2024 ],
			'gen2' => [ 2025, 0 ],
		];
		return [
			'r1t' => [
				'label'       => 'R1T',
				'platform'    => 'r1',
				'group'       => 'R1',
				'generations' => $r1,
			],
			'r1s' => [
				'label'       => 'R1S',
				'platform'    => 'r1',
				'group'       => 'R1',
				'generations' => $r1,
			],
			'r2'  => [
				'label'       => 'R2',
				'platform'    => 'r2',
				'group'       => 'R2',
				'generations' => [ 'gen1' => [ 2026, 0 ] ],
			],
		];
	}

	/**
	 * Every model a member can pick, after the filter.
	 *
	 * @return array<string,array{label:string,platform:string,group:string,generations:array<string,array{0:int,1:int}>}>
	 */
	public static function models(): array {
		/**
		 * Filter the vehicles a member can add to their garage.
		 *
		 * @param array<string,array<string,mixed>> $models Model slug => label, platform, group, generations.
		 */
		return self::normalize( apply_filters( 'riviantrackr_garage_models', self::defaults() ) );
	}

	/**
	 * Validate a model list: slug keys, a label, platform and group, and at
	 * least one generation with a sensible year range. Pure.
	 *
	 * @param mixed $models Filter output.
	 * @return array<string,array{label:string,platform:string,group:string,generations:array<string,array{0:int,1:int}>}>
	 */
	public static function normalize( $models ): array {
		$out = [];
		foreach ( (array) $models as $slug => $model ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || ! is_array( $model ) ) {
				continue;
			}
			$label    = trim( (string) ( $model['label'] ?? '' ) );
			$platform = sanitize_key( (string) ( $model['platform'] ?? '' ) );
			$group    = trim( (string) ( $model['group'] ?? '' ) );
			if ( '' === $label || '' === $platform || '' === $group ) {
				continue;
			}
			$generations = [];
			foreach ( (array) ( $model['generations'] ?? [] ) as $gen => $range ) {
				$gen   = sanitize_key( (string) $gen );
				$range = array_values( (array) $range );
				$from  = (int) ( $range[0] ?? 0 );
				$to    = (int) ( $range[1] ?? 0 );
				if ( '' === $gen || $from < 1990 || ( 0 !== $to && $to < $from ) ) {
					continue;
				}
				$generations[ $gen ] = [ $from, $to ];
			}
			if ( [] === $generations ) {
				continue;
			}
			$out[ $slug ] = [
				'label'       => $label,
				'platform'    => $platform,
				'group'       => $group,
				'generations' => $generations,
			];
		}
		return $out;
	}

	/**
	 * One model, or null when it is not offered.
	 *
	 * @return array{label:string,platform:string,group:string,generations:array<string,array{0:int,1:int}>}|null
	 */
	public static function model( string $slug ): ?array {
		return self::models()[ $slug ] ?? null;
	}

	/**
	 * Model years a picker offers for a model, newest first: from its first
	 * generation's first year to next year (model years run ahead of the
	 * calendar), or to the last generation's end when the model is retired.
	 *
	 * @param array<string,mixed> $model     A normalized model.
	 * @param int|null            $this_year Override the current year (tests).
	 * @return int[]
	 */
	public static function years( array $model, ?int $this_year = null ): array {
		$this_year = $this_year ?? (int) gmdate( 'Y' );
		$first     = PHP_INT_MAX;
		$last      = 0;
		$open      = false;
		foreach ( (array) ( $model['generations'] ?? [] ) as [ $from, $to ] ) {
			$first = min( $first, (int) $from );
			if ( 0 === (int) $to ) {
				$open = true;
			}
			$last = max( $last, (int) $to );
		}
		if ( PHP_INT_MAX === $first ) {
			$first = self::EARLIEST_YEAR;
		}
		$newest = $open ? max( $first, $this_year + 1 ) : max( $first, $last );
		return range( $newest, $first );
	}

	/**
	 * The generation a model year belongs to, or '' when no generation
	 * covers it. Pure.
	 *
	 * @param array<string,mixed> $model A normalized model.
	 */
	public static function generation_for_year( array $model, int $year ): string {
		foreach ( (array) ( $model['generations'] ?? [] ) as $gen => [ $from, $to ] ) {
			if ( $year >= (int) $from && ( 0 === (int) $to || $year <= (int) $to ) ) {
				return (string) $gen;
			}
		}
		return '';
	}

	/**
	 * "Gen 2" for `gen2`; anything else is shown as stored. Pure.
	 */
	public static function generation_label( string $gen ): string {
		if ( preg_match( '/^gen(\d+)$/', $gen, $m ) ) {
			/* translators: %d: generation number */
			return sprintf( __( 'Gen %d', 'riviantrackr-passkeys' ), (int) $m[1] );
		}
		return $gen;
	}

	/**
	 * The garage form's picker data: each model's label and Tire Guide
	 * group, years (newest first) and generations with their year ranges.
	 *
	 * @param int|null $this_year Override the current year (tests).
	 * @return array<string,array{label:string,group:string,years:int[],generations:array<int,array{slug:string,label:string,from:int,to:int}>}>
	 */
	public static function catalog( ?int $this_year = null ): array {
		$out = [];
		foreach ( self::models() as $slug => $model ) {
			$generations = [];
			foreach ( $model['generations'] as $gen => [ $from, $to ] ) {
				$generations[] = [
					'slug'  => $gen,
					'label' => self::generation_label( $gen ),
					'from'  => $from,
					'to'    => $to,
				];
			}
			$out[ $slug ] = [
				'label'       => $model['label'],
				'group'       => $model['group'],
				'years'       => self::years( $model, $this_year ),
				'generations' => $generations,
			];
		}
		return $out;
	}
}
