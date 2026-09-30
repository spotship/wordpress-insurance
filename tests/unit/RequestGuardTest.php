<?php
/**
 * Form integrity verdicts.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers Spotship_WI_Request_Guard
 */
final class RequestGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_salt' )->justReturn( 'a-test-salt' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_valid_signature_of_believable_age_passes(): void {
		$ts  = 1000;
		$sig = Spotship_WI_Request_Guard::sign( $ts, 'form-1' );

		$this->assertSame(
			Spotship_WI_Request_Guard::VALID,
			Spotship_WI_Request_Guard::verify( $ts, 'form-1', $sig, $ts + 10 )
		);
	}

	public function test_a_tampered_signature_gets_a_decoy_not_an_error(): void {
		$this->assertSame(
			Spotship_WI_Request_Guard::FAKE_SUCCESS,
			Spotship_WI_Request_Guard::verify( 1000, 'form-1', 'wrong', 1010 )
		);
	}

	public function test_a_submission_faster_than_a_human_gets_a_decoy(): void {
		$sig = Spotship_WI_Request_Guard::sign( 1000, 'form-1' );

		$this->assertSame(
			Spotship_WI_Request_Guard::FAKE_SUCCESS,
			Spotship_WI_Request_Guard::verify( 1000, 'form-1', $sig, 1001 )
		);
	}

	/**
	 * A timestamp from the future is a tampered field, not clock skew to
	 * accommodate. Accommodating it would hand a bot a way to skip the timing
	 * trap by sending a later time.
	 */
	public function test_a_future_timestamp_is_treated_as_automation(): void {
		$sig = Spotship_WI_Request_Guard::sign( 2000, 'form-1' );

		$this->assertSame(
			Spotship_WI_Request_Guard::FAKE_SUCCESS,
			Spotship_WI_Request_Guard::verify( 2000, 'form-1', $sig, 1000 )
		);
	}

	/**
	 * The one guard branch a real person reaches. They must be told to reload,
	 * not handed a cheerful fake success for a request that was never sent.
	 */
	public function test_a_day_old_form_is_expired_rather_than_decoyed(): void {
		$sig = Spotship_WI_Request_Guard::sign( 1000, 'form-1' );

		$this->assertSame(
			Spotship_WI_Request_Guard::EXPIRED,
			Spotship_WI_Request_Guard::verify( 1000, 'form-1', $sig, 1000 + Spotship_WI_Request_Guard::MAX_AGE + 1 )
		);
	}

	public function test_a_signature_for_a_different_form_does_not_verify(): void {
		$sig = Spotship_WI_Request_Guard::sign( 1000, 'form-1' );

		$this->assertSame(
			Spotship_WI_Request_Guard::FAKE_SUCCESS,
			Spotship_WI_Request_Guard::verify( 1000, 'form-2', $sig, 1010 )
		);
	}

	public function test_any_honeypot_content_trips_it(): void {
		$this->assertFalse( Spotship_WI_Request_Guard::honeypot_tripped( '' ) );
		$this->assertFalse( Spotship_WI_Request_Guard::honeypot_tripped( '   ' ) );
		$this->assertTrue( Spotship_WI_Request_Guard::honeypot_tripped( 'x' ) );
	}
}
