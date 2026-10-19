<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only bridge to the Rivian Tire Guide plugin for the member's reviews
 * and saved tires, and the guide's wheels for the garage.
 *
 * Saved tires are the guide's heart (tire guide 2.14.0+, `wp_rtg_saved_tires`).
 * The plugin's earlier "favorites" list (`wp_rtg_favorites`) was retired by
 * the guide in 1.90.0 and is not read any more; the account page keeps the
 * `favorites` tab id and REST path for the filter and the page script.
 *
 * Every RTG_Database helper is feature-detected: older builds of the tire
 * guide lack some of them, so each call falls back to the plugin's own
 * tables (wp_rtg_ratings, wp_rtg_saved_tires, wp_rtg_tires, wp_rtg_wheels)
 * when the helper is missing, and to "unsupported" when the table is missing
 * too. Every method is a no-op when the tire guide is not active.
 */
final class TireGuide {

	/** @var array<string,bool> Which of the guide's tables exist, checked once per request. */
	private static array $tables = [];

	public static function is_active(): bool {
		return class_exists( 'RTG_Database' );
	}

	/**
	 * Whether the member's tire reviews can be listed.
	 */
	public static function supports_reviews(): bool {
		return self::is_active() && ( self::has( 'get_user_reviews' ) || self::table_exists( 'rtg_ratings' ) );
	}

	/**
	 * Whether the member's saved tires can be listed.
	 */
	public static function supports_favorites(): bool {
		return self::is_active() && ( self::has( 'get_saved_tires' ) || self::table_exists( 'rtg_saved_tires' ) );
	}

	/**
	 * Canonical tire page URL, or '' when the tire is unknown.
	 *
	 * @param array<string,mixed>|null $tire Tire row (needs 'slug').
	 */
	public static function tire_url( ?array $tire ): string {
		if ( ! $tire || empty( $tire['slug'] ) ) {
			return '';
		}
		if ( class_exists( 'RTG_Tire_Page' ) && method_exists( 'RTG_Tire_Page', 'tire_url' ) ) {
			return (string) \RTG_Tire_Page::tire_url( (string) $tire['slug'] );
		}
		return home_url( '/tires/' . rawurlencode( (string) $tire['slug'] ) . '/' );
	}

	/**
	 * The tire guide's review page with the member's own review of a tire
	 * loaded for editing, or '' when there is nothing to link to.
	 *
	 * Tire guide 2.14.1 builds it (`RTG_Tire_Review::url()`); an older
	 * build gets the same URL assembled here, where `edit=1` is simply
	 * ignored and the tire is preselected.
	 */
	public static function review_edit_url( string $tire_id ): string {
		if ( '' === $tire_id || ! self::supports_reviews() ) {
			return '';
		}
		if ( class_exists( 'RTG_Tire_Review' ) && method_exists( 'RTG_Tire_Review', 'url' ) ) {
			return (string) \RTG_Tire_Review::url( $tire_id, true );
		}
		$settings = get_option( 'rtg_settings', [] );
		$slug     = is_array( $settings ) ? sanitize_title( (string) ( $settings['tire_review_slug'] ?? '' ) ) : '';
		return add_query_arg(
			[
				'tire' => rawurlencode( $tire_id ),
				'edit' => '1',
			],
			home_url( '/' . ( '' !== $slug ? $slug : 'tire-review' ) . '/' )
		);
	}

	/**
	 * "Brand Model" label for a tire row, falling back to the tire ID.
	 *
	 * @param array<string,mixed> $row Row with brand/model (and tire_id).
	 */
	public static function tire_label( array $row ): string {
		$label = trim( (string) ( $row['brand'] ?? '' ) . ' ' . (string) ( $row['model'] ?? '' ) );
		return '' !== $label ? $label : (string) ( $row['tire_id'] ?? '' );
	}

	/**
	 * All of the member's reviews (approved, pending, rejected), newest first.
	 *
	 * @return array<int,array<string,mixed>> Each: id, tire_id, tire, url, edit_url, image, rating, title, text, status, date.
	 */
	public static function reviews( int $user_id, int $limit = 50 ): array {
		if ( ! self::supports_reviews() ) {
			return [];
		}
		global $wpdb;

		$rows = [];
		if ( self::has( 'get_user_reviews' ) ) {
			foreach ( (array) \RTG_Database::get_user_reviews( $user_id, $limit, 0 ) as $row ) {
				if ( is_array( $row ) ) {
					$row['review_status'] = 'approved';
					$rows[]               = $row;
				}
			}
		}

		if ( self::table_exists( 'rtg_ratings' ) ) {
			$ratings = $wpdb->prefix . 'rtg_ratings';
			$tires   = $wpdb->prefix . 'rtg_tires';
			$join    = self::table_exists( 'rtg_tires' ) ? "LEFT JOIN {$tires} t ON r.tire_id = t.tire_id" : '';
			$select  = '' !== $join ? 't.brand, t.model, t.image' : "'' AS brand, '' AS model, '' AS image";
			// When the helper is missing, approved reviews come from here too.
			$where = self::has( 'get_user_reviews' ) ? "AND r.review_status <> 'approved'" : '';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb->prefix; fragments are static.
			$others = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.id, r.tire_id, r.rating, r.review_title, r.review_text, r.review_status, r.created_at, r.updated_at, {$select}
					 FROM {$ratings} r {$join}
					 WHERE r.user_id = %d {$where} AND ( r.review_text <> '' OR r.review_title <> '' )
					 ORDER BY r.updated_at DESC LIMIT %d",
					$user_id,
					$limit
				),
				ARRAY_A
			);
			// phpcs:enable
			$rows = array_merge( (array) $others, $rows );
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$tire_id = (string) ( $row['tire_id'] ?? '' );
			$tire    = self::tire( $tire_id );
			$status  = (string) ( $row['review_status'] ?? 'approved' );
			$out[]   = [
				'id'       => (int) ( $row['id'] ?? 0 ),
				'tire_id'  => $tire_id,
				'tire'     => self::tire_label( $row ),
				'url'      => self::tire_url( $tire ),
				'edit_url' => self::review_edit_url( $tire_id ),
				'image'    => (string) ( $row['image'] ?? '' ),
				'rating'   => (float) ( $row['rating'] ?? 0 ),
				'title'    => (string) ( $row['review_title'] ?? '' ),
				'text'     => (string) ( $row['review_text'] ?? '' ),
				'status'   => in_array( $status, [ 'approved', 'pending', 'rejected' ], true ) ? $status : 'pending',
				'date'     => (string) ( $row['updated_at'] ?? $row['created_at'] ?? '' ),
			];
		}
		usort( $out, static fn( array $a, array $b ): int => strcmp( $b['date'], $a['date'] ) );
		return $out;
	}

	public static function review_count( int $user_id ): int {
		return count( self::reviews( $user_id ) );
	}

	/**
	 * Delete the member's own review of a tire.
	 */
	public static function delete_review( string $tire_id, int $user_id ): bool {
		if ( ! self::supports_reviews() ) {
			return false;
		}
		if ( self::has( 'delete_user_rating' ) ) {
			$result = \RTG_Database::delete_user_rating( $tire_id, $user_id );
			return false !== $result && (int) $result > 0;
		}
		global $wpdb;
		$result = $wpdb->delete(
			$wpdb->prefix . 'rtg_ratings',
			[
				'tire_id' => $tire_id,
				'user_id' => $user_id,
			],
			[ '%s', '%d' ]
		);
		return false !== $result && (int) $result > 0;
	}

	/**
	 * The member's saved tires, newest first: the tire guide's own rows
	 * (`RTG_Database::get_saved_tires()`, tire columns plus `saved_at` and
	 * `price_at_save`), or the same shape read from `wp_rtg_saved_tires`
	 * joined to `wp_rtg_tires` on a build without the helper.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function saved_rows( int $user_id ): array {
		if ( ! self::supports_favorites() ) {
			return [];
		}
		if ( self::has( 'get_saved_tires' ) ) {
			return array_values( array_filter( (array) \RTG_Database::get_saved_tires( $user_id ), 'is_array' ) );
		}
		global $wpdb;
		$saved  = $wpdb->prefix . 'rtg_saved_tires';
		$tires  = $wpdb->prefix . 'rtg_tires';
		$join   = self::table_exists( 'rtg_tires' ) ? "INNER JOIN {$tires} t ON t.tire_id = s.tire_id" : '';
		$select = '' !== $join ? 't.*,' : '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb->prefix; fragments are static.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$select} s.tire_id, s.created_at AS saved_at, s.price_at_save
				 FROM {$saved} s {$join}
				 WHERE s.user_id = %d
				 ORDER BY s.created_at DESC, s.id DESC",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable
		return array_values( array_filter( (array) $rows, 'is_array' ) );
	}

	/**
	 * The member's saved tires with name, size, image, today's price and how
	 * it has moved since the save (the tire guide's rule: a whole-dollar
	 * difference, 0 when either price is unknown).
	 *
	 * @return array<int,array<string,mixed>> Each: tire_id, tire, brand, model, size, url, image, price, price_at_save, price_move, saved_at.
	 */
	public static function favorites( int $user_id ): array {
		$out = [];
		foreach ( self::saved_rows( $user_id ) as $row ) {
			$tire_id = (string) ( $row['tire_id'] ?? '' );
			if ( '' === $tire_id ) {
				continue;
			}
			$tire  = ! empty( $row['slug'] ) ? $row : self::tire( $tire_id );
			$now   = (float) ( $row['price'] ?? $tire['price'] ?? 0 );
			$then  = (float) ( $row['price_at_save'] ?? 0 );
			$out[] = [
				'tire_id'       => $tire_id,
				'tire'          => self::tire_label( $tire ?? $row ),
				'brand'         => (string) ( $tire['brand'] ?? $row['brand'] ?? '' ),
				'model'         => (string) ( $tire['model'] ?? $row['model'] ?? '' ),
				'size'          => (string) ( $tire['size'] ?? $row['size'] ?? '' ),
				'url'           => self::tire_url( $tire ),
				'image'         => (string) ( $tire['image'] ?? $row['image'] ?? '' ),
				'price'         => $now,
				'price_at_save' => $then,
				'price_move'    => ( $now > 0 && $then > 0 ) ? (int) round( $now - $then ) : 0,
				'saved_at'      => (string) ( $row['saved_at'] ?? '' ),
			];
		}
		return $out;
	}

	public static function favorite_count( int $user_id ): int {
		if ( ! self::supports_favorites() ) {
			return 0;
		}
		if ( self::has( 'get_saved_count' ) ) {
			return (int) \RTG_Database::get_saved_count( $user_id );
		}
		return count( self::favorites( $user_id ) );
	}

	/**
	 * Take a tire off the member's saved list.
	 */
	public static function remove_favorite( string $tire_id, int $user_id ): bool {
		if ( ! self::supports_favorites() ) {
			return false;
		}
		if ( self::has( 'unsave_tire' ) ) {
			return (bool) \RTG_Database::unsave_tire( $user_id, $tire_id );
		}
		global $wpdb;
		$result = $wpdb->delete(
			$wpdb->prefix . 'rtg_saved_tires',
			[
				'tire_id' => $tire_id,
				'user_id' => $user_id,
			],
			[ '%s', '%d' ]
		);
		return false !== $result && (int) $result > 0;
	}

	/**
	 * The tire guide's saved tires page (prices, Compare, Remove), or '' when
	 * saved tires are not available.
	 */
	public static function saved_tires_url(): string {
		if ( ! self::supports_favorites() ) {
			return '';
		}
		if ( class_exists( 'RTG_Saved_Tires' ) && method_exists( 'RTG_Saved_Tires', 'url' ) ) {
			return (string) \RTG_Saved_Tires::url();
		}
		$settings = get_option( 'rtg_settings', [] );
		$slug     = is_array( $settings ) ? sanitize_title( (string) ( $settings['saved_tires_slug'] ?? '' ) ) : '';
		return home_url( '/' . ( '' !== $slug ? $slug : 'saved-tires' ) . '/' );
	}

	/**
	 * The guide's wheels for the garage's wheel picker, in the guide's order:
	 * `RTG_Database::get_all_wheels()`, or `wp_rtg_wheels` read directly on a
	 * build without it. Empty when the guide is not active.
	 *
	 * @return array<int,array{id:int,name:string,sizes:string[],vehicles:string[],third_party:bool}>
	 */
	public static function wheels(): array {
		if ( ! self::is_active() ) {
			return [];
		}
		$rows = [];
		if ( self::has( 'get_all_wheels' ) ) {
			$rows = (array) \RTG_Database::get_all_wheels();
		} elseif ( self::table_exists( 'rtg_wheels' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'rtg_wheels';
			$rows  = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name from $wpdb->prefix, no input.
		}
		return self::normalize_wheels( $rows );
	}

	/**
	 * Wheel rows as the garage uses them: the stock size first, then the
	 * alternates, de-duplicated; the vehicles the guide lists (`R1T`, `R1S`,
	 * `R2`); and whether it is an aftermarket setup (the guide's own reading
	 * of `source`). Rows without a name or a size are dropped. Pure.
	 *
	 * @param array<int,mixed> $rows Wheel rows.
	 * @return array<int,array{id:int,name:string,sizes:string[],vehicles:string[],third_party:bool}>
	 */
	public static function normalize_wheels( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id    = (int) ( $row['id'] ?? 0 );
			$name  = trim( (string) ( $row['name'] ?? '' ) );
			$sizes = [];
			foreach ( explode( ',', (string) ( $row['stock_size'] ?? '' ) . ',' . (string) ( $row['alt_sizes'] ?? '' ) ) as $size ) {
				$size = Garage::normalize_size( $size );
				if ( '' !== $size && ! in_array( $size, $sizes, true ) ) {
					$sizes[] = $size;
				}
			}
			if ( $id <= 0 || '' === $name || [] === $sizes ) {
				continue;
			}
			$source = strtolower( trim( (string) ( $row['source'] ?? '' ) ) );
			$out[]  = [
				'id'          => $id,
				'name'        => $name,
				'sizes'       => $sizes,
				'vehicles'    => array_values( array_filter( array_map( 'trim', explode( ',', strtoupper( (string) ( $row['vehicles'] ?? '' ) ) ) ) ) ),
				'third_party' => in_array( $source, [ 'third_party', 'aftermarket', '3rd_party', 'third-party' ], true ),
			];
		}
		return $out;
	}

	// ---- internals -------------------------------------------------------

	/**
	 * Whether this build of the tire guide offers an RTG_Database helper.
	 */
	private static function has( string $method ): bool {
		return self::is_active() && method_exists( 'RTG_Database', $method );
	}

	/**
	 * Whether one of the tire guide's tables exists (checked once per request).
	 */
	private static function table_exists( string $suffix ): bool {
		if ( ! array_key_exists( $suffix, self::$tables ) ) {
			global $wpdb;
			$table                   = $wpdb->prefix . $suffix;
			$found                   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			self::$tables[ $suffix ] = is_string( $found ) && strtolower( $found ) === strtolower( $table );
		}
		return self::$tables[ $suffix ];
	}

	/**
	 * Forget which tables were found, so the next call looks again (tests).
	 */
	public static function forget_tables(): void {
		self::$tables = [];
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function tire( string $tire_id ): ?array {
		static $cache = [];
		if ( '' === $tire_id ) {
			return null;
		}
		if ( ! array_key_exists( $tire_id, $cache ) ) {
			$row = null;
			if ( self::has( 'get_tire' ) ) {
				$row = \RTG_Database::get_tire( $tire_id );
			} elseif ( self::table_exists( 'rtg_tires' ) ) {
				global $wpdb;
				$table = $wpdb->prefix . 'rtg_tires';
				$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE tire_id = %s", $tire_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
			}
			$cache[ $tire_id ] = is_array( $row ) ? $row : null;
		}
		return $cache[ $tire_id ];
	}
}
