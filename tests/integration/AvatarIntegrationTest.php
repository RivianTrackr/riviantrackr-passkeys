<?php
declare(strict_types=1);

use RivianTrackr\Passkeys\Avatars;

/**
 * Upload a real image through the REST route, see it served as the member's
 * avatar everywhere, replace it, remove it, and watch it go with the account.
 */
final class AvatarIntegrationTest extends IntegrationTestCase {

	private int $member;

	public function set_up(): void {
		parent::set_up();
		$this->member = (int) self::factory()->user->create(
			[
				'role'         => 'subscriber',
				'user_login'   => 'jose',
				'user_email'   => 'jose@example.org',
				'display_name' => 'Jose',
			]
		);
		wp_set_current_user( $this->member );
	}

	public function tear_down(): void {
		// Attachments live on disk outside the transaction; clean up what we uploaded.
		foreach ( get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'author' => $this->member ] ) as $attachment ) {
			wp_delete_attachment( $attachment->ID, true );
		}
		parent::tear_down();
	}

	/**
	 * A REST file param for one of the test library's sample images.
	 *
	 * @return array<string,mixed>
	 */
	private function sample( string $name = 'canola.jpg', string $type = 'image/jpeg' ): array {
		$source = DIR_TESTDATA . '/images/' . $name;
		$this->assertFileExists( $source );
		$tmp = wp_tempnam( $name );
		copy( $source, $tmp );
		return [
			'name'     => $name,
			'type'     => $type,
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => (int) filesize( $tmp ),
		];
	}

	private function upload( array $file ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . RTPK_REST_NAMESPACE . '/account/avatar' );
		$request->set_file_params( [ 'avatar' => $file ] );
		return rest_do_request( $request );
	}

	public function test_upload_stores_a_square_attachment_and_serves_it_as_the_avatar(): void {
		$this->assertStringContainsString( 'gravatar.com', (string) get_avatar_url( $this->member ), 'Gravatar before' );

		$response = $this->upload( $this->sample() );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertTrue( $data['custom'] );
		$this->assertStringContainsString( '/uploads/', $data['url'] );

		$id = Avatars::attachment_id( $this->member );
		$this->assertGreaterThan( 0, $id );
		$attachment = get_post( $id );
		$this->assertSame( $this->member, (int) $attachment->post_author, 'owned by the member' );
		$this->assertSame( 'private', $attachment->post_status );
		$meta = wp_get_attachment_metadata( $id );
		$this->assertSame( 480, (int) $meta['width'], 'the 640×480 sample is cropped to its shorter side' );
		$this->assertSame( 480, (int) $meta['height'] );

		$this->assertSame( $data['url'], get_avatar_url( $this->member ), 'get_avatar_url() by ID' );
		$this->assertSame( $data['url'], get_avatar_url( 'jose@example.org' ), 'by email' );
		$this->assertStringContainsString( esc_url( $data['url'] ), get_avatar( $this->member, 96 ), 'get_avatar() markup' );

		$comment = get_comment( self::factory()->comment->create( [ 'user_id' => $this->member, 'comment_author_email' => 'jose@example.org' ] ) );
		$this->assertSame( $data['url'], get_avatar_url( $comment ), 'comments by the member' );
		$this->assertStringContainsString( 'gravatar.com', (string) get_avatar_url( 'someone@example.org' ), 'everyone else keeps Gravatar' );
	}

	public function test_a_new_upload_replaces_the_old_attachment(): void {
		$this->upload( $this->sample() );
		$first = Avatars::attachment_id( $this->member );

		$response = $this->upload( $this->sample( '33772.jpg' ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$second = Avatars::attachment_id( $this->member );

		$this->assertNotSame( $first, $second );
		$this->assertNull( get_post( $first ), 'the previous photo is deleted' );
		$this->assertInstanceOf( WP_Post::class, get_post( $second ) );
		$meta = wp_get_attachment_metadata( $second );
		$this->assertSame( 512, (int) $meta['width'], 'a 1920×1080 photo is cropped square and scaled down to 512' );
		$this->assertSame( 512, (int) $meta['height'] );
	}

	public function test_a_tiny_image_is_refused(): void {
		$response = $this->upload( $this->sample( 'test-image.png', 'image/png' ) );
		$this->assertSame( 400, $response->get_status(), 'the 50×50 sample is under the 64px floor' );
		$this->assertStringContainsString( 'too small', $response->get_data()['message'] );
	}

	public function test_bad_uploads_are_refused_without_touching_the_library(): void {
		$text = wp_tempnam( 'notes.txt' );
		file_put_contents( $text, 'not an image' );
		$response = $this->upload(
			[
				'name'     => 'notes.txt',
				'type'     => 'text/plain',
				'tmp_name' => $text,
				'error'    => UPLOAD_ERR_OK,
				'size'     => 12,
			]
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rtpk_invalid_avatar', $response->get_data()['code'] );

		$renamed = $this->sample( 'canola.jpg' );
		$renamed['name'] = 'canola.php';
		$this->assertSame( 400, $this->upload( $renamed )->get_status(), 'a mismatched extension is refused' );

		$this->assertSame( 0, Avatars::attachment_id( $this->member ) );
		$this->assertSame( [], get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'author' => $this->member ] ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->upload( $this->sample() )->get_status() );
	}

	public function test_remove_goes_back_to_gravatar(): void {
		$this->upload( $this->sample() );
		$id = Avatars::attachment_id( $this->member );

		$response = $this->rest( 'DELETE', 'account/avatar' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['custom'] );
		$this->assertStringContainsString( 'gravatar.com', $response->get_data()['url'] );

		$this->assertSame( 0, Avatars::attachment_id( $this->member ) );
		$this->assertNull( get_post( $id ), 'attachment deleted' );
		$this->assertStringContainsString( 'gravatar.com', (string) get_avatar_url( $this->member ) );
	}

	public function test_deleting_the_account_deletes_the_photo(): void {
		$this->upload( $this->sample() );
		$id = Avatars::attachment_id( $this->member );

		$response = $this->rest( 'DELETE', 'account', [ 'confirm' => 'jose' ] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertNull( get_post( $id ), 'wp_delete_user() takes the member\'s attachments with it' );
	}
}
