<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\Avatars;

final class AvatarsTest extends TestCase {

	/** A 1×1 PNG, the smallest valid image we can write without GD. */
	private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	/** @var string[] */
	private array $temp = [];

	protected function setUp(): void {
		rtpk_test_reset();
	}

	protected function tearDown(): void {
		foreach ( $this->temp as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}

	private function temp_file( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'rtpk' );
		file_put_contents( $path, $contents );
		$this->temp[] = $path;
		return $path;
	}

	/**
	 * A PNG of the given size, built by scaling the 1px image's header only
	 * (getimagesize() reads dimensions from the IHDR chunk).
	 */
	private function png( int $width, int $height ): string {
		$png = base64_decode( self::PNG_1PX );
		return substr( $png, 0, 16 ) . pack( 'N', $width ) . pack( 'N', $height ) . substr( $png, 24 );
	}

	private function upload( string $contents, string $name = 'me.png', int $error = UPLOAD_ERR_OK, ?int $size = null ): array {
		$tmp = $this->temp_file( $contents );
		return [
			'name'     => $name,
			'type'     => 'image/png',
			'tmp_name' => $tmp,
			'error'    => $error,
			'size'     => $size ?? strlen( $contents ),
		];
	}

	// ---- resolve_user_id() -------------------------------------------------

	public function test_resolves_every_shape_get_avatar_accepts(): void {
		$this->assertSame( 7, Avatars::resolve_user_id( 7 ) );
		$this->assertSame( 7, Avatars::resolve_user_id( '7' ) );
		$this->assertSame( 7, Avatars::resolve_user_id( new WP_User( 7 ) ) );

		$GLOBALS['rtpk_test_users'][7]                                 = new WP_User( 7, 'jose', 'Jose', 'jose@riviantrackr.test' );
		$GLOBALS['rtpk_test_user_emails']['jose@riviantrackr.test']    = 7;
		$this->assertSame( 0, Avatars::resolve_user_id( 'nobody@riviantrackr.test' ), 'unknown address: Gravatar' );
		$this->assertSame( 0, Avatars::resolve_user_id( 'not an email' ) );
		$this->assertSame( 0, Avatars::resolve_user_id( null ) );
	}

	// ---- url() / filter ----------------------------------------------------

	public function test_filter_serves_the_square_size_and_falls_back_to_full(): void {
		$avatars = new Avatars();
		$args    = [ 'url' => 'https://gravatar/x', 'found_avatar' => false ];

		$this->assertSame( $args, $avatars->filter_avatar_data( $args, 7 ), 'no upload: untouched' );

		update_user_meta( 7, Avatars::META, 55 );
		$this->assertSame( $args, $avatars->filter_avatar_data( $args, 7 ), 'a meta pointing at nothing is ignored' );

		$GLOBALS['rtpk_test_posts_types'][55]     = 'attachment';
		$GLOBALS['rtpk_test_attachment_urls'][55] = [ 'full' => 'https://riviantrackr.test/u/me.png' ];
		$out                                      = $avatars->filter_avatar_data( $args, 7 );
		$this->assertSame( 'https://riviantrackr.test/u/me.png', $out['url'], 'small upload: full image' );
		$this->assertTrue( $out['found_avatar'] );

		$GLOBALS['rtpk_test_attachment_urls'][55][ Avatars::IMAGE_SIZE ] = 'https://riviantrackr.test/u/me-512x512.png';
		$this->assertSame( 'https://riviantrackr.test/u/me-512x512.png', $avatars->filter_avatar_data( $args, 7 )['url'] );
		$this->assertSame( 'https://riviantrackr.test/u/me-512x512.png', Avatars::url( 7 ) );
		$this->assertSame( 55, Avatars::attachment_id( 7 ) );

		$this->assertSame( $args, $avatars->filter_avatar_data( $args, 'guest@example.com' ), 'guests keep Gravatar' );
	}

	// ---- validate() --------------------------------------------------------

	public function test_a_good_png_passes(): void {
		$this->assertNull( Avatars::validate( $this->upload( $this->png( 400, 400 ) ) ) );
	}

	public function test_missing_or_failed_uploads_are_explained(): void {
		$this->assertStringContainsString( 'Choose a photo', (string) Avatars::validate( [] ) );
		$this->assertStringContainsString( 'Choose a photo', (string) Avatars::validate( [ 'error' => UPLOAD_ERR_NO_FILE ] ) );
		$this->assertStringContainsString( 'too large', (string) Avatars::validate( $this->upload( $this->png( 400, 400 ), 'me.png', UPLOAD_ERR_INI_SIZE ) ) );
		$this->assertStringContainsString( 'did not complete', (string) Avatars::validate( $this->upload( $this->png( 400, 400 ), 'me.png', UPLOAD_ERR_PARTIAL ) ) );
	}

	public function test_size_type_and_dimensions_are_checked(): void {
		$this->assertStringContainsString( 'too large', (string) Avatars::validate( $this->upload( $this->png( 400, 400 ), 'me.png', UPLOAD_ERR_OK, Avatars::max_bytes() + 1 ) ) );
		$this->assertStringContainsString( 'JPG, PNG, GIF or WebP', (string) Avatars::validate( $this->upload( 'just text', 'notes.txt' ) ) );
		$this->assertStringContainsString( 'JPG, PNG, GIF or WebP', (string) Avatars::validate( $this->upload( 'just text', 'fake.png' ) ), 'the bytes decide, not the name' );
		$this->assertStringContainsString( 'too small', (string) Avatars::validate( $this->upload( $this->png( 32, 32 ) ) ) );
	}

	public function test_max_bytes_is_filterable_with_a_floor(): void {
		$this->assertSame( 2 * 1024 * 1024, Avatars::max_bytes() );
		$GLOBALS['rtpk_test_filter_hooks']['riviantrackr_passkeys_avatar_max_bytes'] = static fn() => 5 * 1024 * 1024;
		$this->assertSame( 5 * 1024 * 1024, Avatars::max_bytes() );
		$GLOBALS['rtpk_test_filter_hooks']['riviantrackr_passkeys_avatar_max_bytes'] = static fn() => 1;
		$this->assertSame( 100 * 1024, Avatars::max_bytes(), 'never below 100 KB' );
	}
}
