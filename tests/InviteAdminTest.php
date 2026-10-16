<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\InviteAdmin;

final class InviteAdminTest extends TestCase {

	/**
	 * Regression: job IDs used to come from wp_generate_password(), which is
	 * mixed case; the follow-up request sanitised them with sanitize_key()
	 * (lowercase), so the transient was never found and every send reported
	 * "This send job has expired".
	 */
	public function test_job_ids_survive_sanitize_key(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			$id = InviteAdmin::new_job_id();
			$this->assertSame( $id, sanitize_key( $id ) );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{16}$/', $id );
		}
		$this->assertNotSame( InviteAdmin::new_job_id(), InviteAdmin::new_job_id() );
	}
}
