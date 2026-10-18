<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only bridge to the Rivian Tire Guide plugin for the member's reviews
 * and favourites.
 *
 * Every RTG_Database helper is feature-detected: older builds of the tire
 * guide lack some of them, so each call falls back to the plugin's own
 * tables (wp_rtg_ratings, wp_rtg_favorites, wp_rtg_tires) when the helper
 * is missing, and to "unsupported" when the table is missing too. Every
 * method is a no-op when the tire guide is not active.
 */
final class TireGuide {

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
	 * Whether favourite tires can be listed.
	 */
	public static function supports_favorites(): bool {
		return self::is_active() && ( self::has( 'get_user_favorites' ) || self::table_exists( 'rtg_favorites' ) );
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
	 * @return array<int,array<string,mixed>> Each: id, tire_id, tire, url, image, rating, title, text, status, date.
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
				'id'      => (int) ( $row['id'] ?? 0 ),
				'tire_id' => $tire_id,
				'tire'    => self::tire_label( $row ),
				'url'     => self::tire_url( $tire ),
				'image'   => (string) ( $row['image'] ?? '' ),
				'rating'  => (float) ( $row['rating'] ?? 0 ),
				'title'   => (string) ( $row['review_title'] ?? '' ),
				'text'    => (string) ( $row['review_text'] ?? '' ),
				'status'  => in_array( $status, [ 'approved', 'pending', 'rejected' ], true ) ? $status : 'pending',
				'date'    => (string) ( $row['updated_at'] ?? $row['created_at'] ?? '' ),
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
	 * The member's favourite tire IDs, newest first.
	 *
	 * @return string[]
	 */
	private static function favorite_ids( int $user_id ): array {
		if ( ! self::supports_favorites() ) {
			return [];
		}
		if ( self::has( 'get_user_favorites' ) ) {
			$ids = (array) \RTG_Database::get_user_favorites( $user_id );
		} else {
			global $wpdb;
			$table = $wpdb->prefix . 'rtg_favorites';
			$ids   = (array) $wpdb->get_col(
				$wpdb->prepare( "SELECT tire_id FROM {$table} WHERE user_id = %d ORDER BY created_at DESC", $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix.
			);
		}
		return array_values( array_filter( array_map( 'strval', $ids ) ) );
	}

	/**
	 * The member's favourite tires with name, brand and image.
	 *
	 * @return array<int,array<string,mixed>> Each: tire_id, tire, brand, model, size, url, image.
	 */
	public static function favorites( int $user_id ): array {
		$out = [];
		foreach ( self::favorite_ids( $user_id ) as $tire_id ) {
			$tire  = self::tire( $tire_id );
			$out[] = [
				'tire_id' => $tire_id,
				'tire'    => $tire ? self::tire_label( $tire ) : $tire_id,
				'brand'   => (string) ( $tire['brand'] ?? '' ),
				'model'   => (string) ( $tire['model'] ?? '' ),
				'size'    => (string) ( $tire['size'] ?? '' ),
				'url'     => self::tire_url( $tire ),
				'image'   => (string) ( $tire['image'] ?? '' ),
			];
		}
		return $out;
	}

	public static function favorite_count( int $user_id ): int {
		return count( self::favorite_ids( $user_id ) );
	}

	public static function remove_favorite( string $tire_id, int $user_id ): bool {
		if ( ! self::supports_favorites() ) {
			return false;
		}
		if ( self::has( 'remove_favorite' ) ) {
			return (bool) \RTG_Database::remove_favorite( $tire_id, $user_id );
		}
		global $wpdb;
		$result = $wpdb->delete(
			$wpdb->prefix . 'rtg_favorites',
			[
				'tire_id' => $tire_id,
				'user_id' => $user_id,
			],
			[ '%s', '%d' ]
		);
		return false !== $result;
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
		static $cache = [];
		if ( ! array_key_exists( $suffix, $cache ) ) {
			global $wpdb;
			$table            = $wpdb->prefix . $suffix;
			$found            = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			$cache[ $suffix ] = is_string( $found ) && strtolower( $found ) === strtolower( $table );
		}
		return $cache[ $suffix ];
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
