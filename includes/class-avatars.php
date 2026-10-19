<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Member-uploaded avatars for the account page.
 *
 * A member picks a photo on the Profile tab; it is cropped to a centred
 * square of at most 512px and stored in the media library (attachment owned
 * by the member), its ID kept in user meta, and served everywhere WordPress asks
 * for that member's avatar through the pre_get_avatar_data filter (the account
 * page header, comments, author boxes, wp-admin). Removing the photo falls
 * back to Gravatar. Deleting the account deletes the attachment with the
 * member's other posts.
 */
final class Avatars {

	public const META       = 'riviantrackr_passkeys_avatar';
	public const IMAGE_SIZE = 'rtpk-avatar';
	public const PIXELS     = 512;

	/** Accepted upload types (MIME => extensions as wp_check_filetype_and_ext() reports them). */
	public const TYPES = [
		'image/jpeg' => 'jpg|jpeg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
	];

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_size' ] );
		add_filter( 'pre_get_avatar_data', [ $this, 'filter_avatar_data' ], 10, 2 );
	}

	public function register_size(): void {
		add_image_size( self::IMAGE_SIZE, self::PIXELS, self::PIXELS, true );
	}

	/**
	 * Largest upload accepted, in bytes (default 2 MB).
	 */
	public static function max_bytes(): int {
		/**
		 * Filter the largest avatar upload accepted on the account page.
		 *
		 * @param int $bytes Default 2 MB.
		 */
		return max( 100 * 1024, (int) apply_filters( 'riviantrackr_passkeys_avatar_max_bytes', 2 * 1024 * 1024 ) );
	}

	// ---- lookup ----------------------------------------------------------

	/**
	 * The member's uploaded avatar attachment, or 0.
	 */
	public static function attachment_id( int $user_id ): int {
		$id = (int) get_user_meta( $user_id, self::META, true );
		return $id > 0 && 'attachment' === get_post_type( $id ) ? $id : 0;
	}

	/**
	 * URL of the member's uploaded avatar (the square size, else the full
	 * image when the upload was smaller than 512px), or ''.
	 */
	public static function url( int $user_id ): string {
		$id = self::attachment_id( $user_id );
		if ( 0 === $id ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $id, self::IMAGE_SIZE );
		if ( ! is_string( $url ) || '' === $url ) {
			$url = wp_get_attachment_image_url( $id, 'full' );
		}
		return is_string( $url ) ? $url : '';
	}

	/**
	 * The user behind whatever get_avatar() was handed: an ID, an email, a
	 * WP_User, a WP_Post (its author) or a WP_Comment (its user, when the
	 * commenter was signed in). Pure apart from the lookups. Returns 0 for
	 * guests and unknown addresses.
	 *
	 * @param mixed $id_or_email As passed to get_avatar().
	 */
	public static function resolve_user_id( $id_or_email ): int {
		if ( $id_or_email instanceof \WP_User ) {
			return (int) $id_or_email->ID;
		}
		if ( $id_or_email instanceof \WP_Post ) {
			return (int) $id_or_email->post_author;
		}
		if ( $id_or_email instanceof \WP_Comment ) {
			return (int) $id_or_email->user_id;
		}
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user instanceof \WP_User ? (int) $user->ID : 0;
		}
		return 0;
	}

	/**
	 * Filters pre_get_avatar_data: serve the uploaded photo for members who
	 * have one, leave everyone else to Gravatar.
	 *
	 * @param array<string,mixed> $args        Avatar arguments.
	 * @param mixed               $id_or_email As passed to get_avatar().
	 * @return array<string,mixed>
	 */
	public function filter_avatar_data( $args, $id_or_email ): array {
		$args    = (array) $args;
		$user_id = self::resolve_user_id( $id_or_email );
		if ( $user_id <= 0 ) {
			return $args;
		}
		$url = self::url( $user_id );
		if ( '' === $url ) {
			return $args;
		}
		$args['url']          = $url;
		$args['found_avatar'] = true;
		return $args;
	}

	// ---- upload ----------------------------------------------------------

	/**
	 * Check an uploaded file before anything touches the media library.
	 * Returns the problem as a sentence, or null when the file is fine.
	 *
	 * @param array<string,mixed> $file One entry of $_FILES / a REST file param.
	 */
	public static function validate( array $file ): ?string {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_NO_FILE === $error || empty( $file['tmp_name'] ) ) {
			return __( 'Choose a photo to upload.', 'riviantrackr-passkeys' );
		}
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return self::too_large();
		}
		if ( UPLOAD_ERR_OK !== $error ) {
			return __( 'The upload did not complete. Please try again.', 'riviantrackr-passkeys' );
		}
		if ( (int) ( $file['size'] ?? 0 ) > self::max_bytes() ) {
			return self::too_large();
		}
		if ( ! is_readable( (string) $file['tmp_name'] ) ) {
			return __( 'The upload did not complete. Please try again.', 'riviantrackr-passkeys' );
		}

		$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) ( $file['name'] ?? '' ), self::mime_map() );
		$type  = (string) ( $check['type'] ?? '' );
		if ( '' === $type || ! isset( self::TYPES[ $type ] ) ) {
			return __( 'Use a JPG, PNG, GIF or WebP image.', 'riviantrackr-passkeys' );
		}
		$dimensions = @getimagesize( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A non-image returns false, which is the answer.
		if ( ! is_array( $dimensions ) || (int) $dimensions[0] < 64 || (int) $dimensions[1] < 64 ) {
			return __( 'That image is too small. Use one at least 64 pixels on each side.', 'riviantrackr-passkeys' );
		}
		return null;
	}

	/**
	 * Store an upload as the member's avatar: media library attachment owned
	 * by the member (the square size is generated with the other sizes), the
	 * previous photo removed. Returns the new attachment ID.
	 *
	 * @param \WP_User            $user The member.
	 * @param array<string,mixed> $file One entry of $_FILES / a REST file param (already validated).
	 * @return int|\WP_Error
	 */
	public static function store( \WP_User $user, array $file ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$name  = sanitize_file_name( (string) ( $file['name'] ?? 'avatar' ) );
		$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], $name, self::mime_map() );
		if ( ! empty( $check['proper_filename'] ) ) {
			$name = (string) $check['proper_filename'];
		}
		if ( '' === pathinfo( $name, PATHINFO_EXTENSION ) && ! empty( $check['ext'] ) ) {
			$name .= '.' . $check['ext'];
		}

		$tmp = self::square( (string) $file['tmp_name'] );

		$file_array = [
			'name'     => 'avatar-' . $user->ID . '-' . $name,
			'tmp_name' => $tmp,
			'type'     => (string) ( $check['type'] ?? '' ),
			'size'     => (int) ( filesize( $tmp ) ?: ( $file['size'] ?? 0 ) ),
			'error'    => 0,
		];

		// Members usually lack upload_files; the checks above stand in for the capability.
		$allow = static fn(): array => self::mime_map();
		add_filter( 'upload_mimes', $allow, 99 );

		$attachment_id = media_handle_sideload(
			$file_array,
			0,
			sprintf(
				/* translators: %s: display name */
				__( 'Avatar for %s', 'riviantrackr-passkeys' ),
				'' !== $user->display_name ? $user->display_name : $user->user_login
			),
			[
				'post_author' => $user->ID,
				'post_status' => 'private',
			]
		);
		remove_filter( 'upload_mimes', $allow, 99 );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		$attachment_id = (int) $attachment_id;

		$previous = self::attachment_id( $user->ID );
		update_user_meta( $user->ID, self::META, $attachment_id );
		if ( $previous > 0 && $previous !== $attachment_id ) {
			self::delete_attachment( $previous, $user->ID );
		}

		/**
		 * Fires after a member uploaded a new avatar from the account page.
		 *
		 * @param \WP_User $user          The member.
		 * @param int      $attachment_id The new attachment.
		 */
		do_action( 'riviantrackr_passkeys_avatar_updated', $user, $attachment_id );

		return $attachment_id;
	}

	/**
	 * Crop the upload to a centred square no larger than 512px and return
	 * the path of the file to store. WordPress never upscales, so a
	 * registered 512×512 size alone would leave a 640×480 photo as 512×480;
	 * cropping first keeps every avatar square. The editor writes the result
	 * next to the upload with a proper extension (PHP's temp names have
	 * none), so the caller gets that path and the original is removed. Best
	 * effort: without an image editor the original is kept as uploaded.
	 */
	private static function square( string $path ): string {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return $path;
		}
		$size   = $editor->get_size();
		$width  = (int) ( $size['width'] ?? 0 );
		$height = (int) ( $size['height'] ?? 0 );
		if ( $width <= 0 || $height <= 0 ) {
			return $path;
		}
		$side = min( $width, $height );
		if ( $width === $height && $side <= self::PIXELS ) {
			return $path;
		}
		if ( $width !== $height ) {
			$editor->crop( (int) floor( ( $width - $side ) / 2 ), (int) floor( ( $height - $side ) / 2 ), $side, $side );
		}
		if ( $side > self::PIXELS ) {
			$editor->resize( self::PIXELS, self::PIXELS, true );
		}
		$saved = $editor->save( $path );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_readable( (string) $saved['path'] ) ) {
			return $path;
		}
		$cropped = (string) $saved['path'];
		if ( $cropped !== $path ) {
			wp_delete_file( $path );
		}
		return $cropped;
	}

	/**
	 * Drop the member's uploaded avatar (back to Gravatar).
	 */
	public static function remove( int $user_id ): void {
		$id = self::attachment_id( $user_id );
		delete_user_meta( $user_id, self::META );
		if ( $id > 0 ) {
			self::delete_attachment( $id, $user_id );
		}
	}

	/**
	 * Delete an avatar attachment, but only one that belongs to the member.
	 */
	private static function delete_attachment( int $attachment_id, int $user_id ): void {
		$post = get_post( $attachment_id );
		if ( $post instanceof \WP_Post && 'attachment' === $post->post_type && (int) $post->post_author === $user_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * @return array<string,string> Extension pattern => MIME, as upload_mimes expects.
	 */
	private static function mime_map(): array {
		return array_flip( self::TYPES );
	}

	private static function too_large(): string {
		return sprintf(
			/* translators: %s: size such as "2 MB" */
			__( 'That photo is too large. Keep it under %s.', 'riviantrackr-passkeys' ),
			size_format( self::max_bytes() )
		);
	}
}
