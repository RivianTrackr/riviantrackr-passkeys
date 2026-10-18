<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only bridge to the Rivian Tire Guide plugin (RTG_Database) for the
 * member's reviews and favourites. Every method is a no-op when the tire
 * guide is not active.
 */
final class TireGuide {

	public static function is_active(): bool {
		return class_exists( 'RTG_Database' );
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
	 * @return array<string,mixed>|null
	 */
	private static function tire( string $tire_id ): ?array {
		static $cache = [];
		if ( ! array_key_exists( $tire_id, $cache ) ) {
			$row               = self::is_active() ? \RTG_Database::get_tire( $tire_id ) : null;
			$cache[ $tire_id ] = is_array( $row ) ? $row : null;
		}
		return $cache[ $tire_id ];
	}

	/**
	 * All of the member's reviews: approved ones through the tire guide's
	 * own helper, pending and rejected ones straight from the ratings table.
	 *
	 * @return array<int,array<string,mixed>> Each: id, tire_id, tire, url, image, rating, title, text, status, date.
	 */
	public static function reviews( int $user_id, int $limit = 50 ): array {
		if ( ! self::is_active() ) {
			return [];
		}
		global $wpdb;

		$rows = (array) \RTG_Database::get_user_reviews( $user_id, $limit, 0 );
		foreach ( $rows as &$row ) {
			$row['review_status'] = 'approved';
		}
		unset( $row );

		$ratings = $wpdb->prefix . 'rtg_ratings';
		$tires   = $wpdb->prefix . 'rtg_tires';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from $wpdb->prefix.
		$others = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.tire_id, r.rating, r.review_title, r.review_text, r.review_status, r.created_at, r.updated_at, t.brand, t.model, t.image
				 FROM {$ratings} r LEFT JOIN {$tires} t ON r.tire_id = t.tire_id
				 WHERE r.user_id = %d AND r.review_status <> 'approved' AND ( r.review_text <> '' OR r.review_title <> '' )
				 ORDER BY r.updated_at DESC LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable
		$rows = array_merge( (array) $others, $rows );

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
		if ( ! self::is_active() ) {
			return false;
		}
		$result = \RTG_Database::delete_user_rating( $tire_id, $user_id );
		return false !== $result && (int) $result > 0;
	}

	/**
	 * The member's favourite tires with name, brand and image.
	 *
	 * @return array<int,array<string,mixed>> Each: tire_id, tire, brand, model, size, url, image.
	 */
	public static function favorites( int $user_id ): array {
		if ( ! self::is_active() ) {
			return [];
		}
		$out = [];
		foreach ( (array) \RTG_Database::get_user_favorites( $user_id ) as $tire_id ) {
			$tire_id = (string) $tire_id;
			$tire    = self::tire( $tire_id );
			$out[]   = [
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
		return self::is_active() ? count( (array) \RTG_Database::get_user_favorites( $user_id ) ) : 0;
	}

	public static function remove_favorite( string $tire_id, int $user_id ): bool {
		return self::is_active() && (bool) \RTG_Database::remove_favorite( $tire_id, $user_id );
	}
}
