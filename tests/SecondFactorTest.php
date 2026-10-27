<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivianTrackr\Passkeys\SecondFactor;

final class SecondFactorTest extends TestCase {

	public function test_really_simple_security_handler_is_named_exactly(): void {
		// has_action() matches static callbacks by "Class::method", namespace included.
		$this->assertContains(
			[ 'RSSSL\\Security\\WordPress\\Two_Fa\\Rsssl_Two_Factor', 'rsssl_wp_login' ],
			SecondFactor::known_hooks()
		);
	}
}
