<?php
/**
 * Per-IP rate limiting.
 *
 * Integration rather than unit because the behaviour under test is how the
 * counter survives the transient layer, and faking that layer would test the
 * fake.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

/**
 * @covers Spotship_WI_Rate_Limiter
 */
final class RateLimiterTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Spotship_WI_Rate_Limiter::reset( 'test' );
	}

	public function tear_down(): void {
		Spotship_WI_Rate_Limiter::reset( 'test' );
		parent::tear_down();
	}

	public function test_requests_are_allowed_up_to_the_limit_then_refused(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue(
				Spotship_WI_Rate_Limiter::consume( 'test', 3, 60 )['allowed'],
				"request {$i} should be allowed"
			);
		}

		$this->assertFalse( Spotship_WI_Rate_Limiter::consume( 'test', 3, 60 )['allowed'] );
	}

	/**
	 * THE DEFECT THIS GUARDS AGAINST: a limiter that re-sets the full window on
	 * every request, or writes to a persistent object cache without a TTL, turns
	 * a one-minute limit into a permanent block for anyone who keeps trying —
	 * including a legitimate visitor retrying a form.
	 *
	 * The window is therefore held in the VALUE, and a consumed window does not
	 * move its own expiry.
	 */
	public function test_consuming_does_not_extend_the_window(): void {
		Spotship_WI_Rate_Limiter::consume( 'test', 10, 60 );

		$key   = 'spotship_wi_rl_test_' . md5( Spotship_WI_Rate_Limiter::client_ip() );
		$first = get_transient( $key );

		$this->assertIsArray( $first );
		$this->assertArrayHasKey( 'expires', $first );

		$expiry_after_one = (int) $first['expires'];

		for ( $i = 0; $i < 5; $i++ ) {
			Spotship_WI_Rate_Limiter::consume( 'test', 10, 60 );
		}

		$later = get_transient( $key );

		$this->assertSame(
			$expiry_after_one,
			(int) $later['expires'],
			'a steady stream of requests must not push the window out in front of itself'
		);
		$this->assertSame( 6, (int) $later['count'] );
	}

	/**
	 * A window whose timestamp has passed starts a new one, even if the storage
	 * layer still holds the row — which is exactly what happens on a persistent
	 * object cache that ignored the TTL.
	 */
	public function test_an_elapsed_window_starts_again_even_if_the_row_survives(): void {
		$key = 'spotship_wi_rl_test_' . md5( Spotship_WI_Rate_Limiter::client_ip() );

		// A full, EXPIRED window left behind by a storage layer that did not
		// honour the TTL.
		set_transient(
			$key,
			array(
				'count'   => 999,
				'expires' => time() - 1,
			),
			3600
		);

		$verdict = Spotship_WI_Rate_Limiter::consume( 'test', 3, 60 );

		$this->assertTrue(
			$verdict['allowed'],
			'an elapsed window must not block, however the storage layer behaved'
		);
	}

	/**
	 * Telling a visitor to wait an hour when the window ends in ten seconds
	 * loses a real enquiry.
	 */
	public function test_retry_after_is_the_remaining_wait_not_the_whole_window(): void {
		$key = 'spotship_wi_rl_test_' . md5( Spotship_WI_Rate_Limiter::client_ip() );

		set_transient(
			$key,
			array(
				'count'   => 5,
				'expires' => time() + 10,
			),
			3600
		);

		$verdict = Spotship_WI_Rate_Limiter::consume( 'test', 5, 3600 );

		$this->assertFalse( $verdict['allowed'] );
		$this->assertLessThanOrEqual( 10, $verdict['retry_after'] );
		$this->assertGreaterThan( 0, $verdict['retry_after'] );
	}

	/**
	 * Trusting CF-Connecting-IP unconditionally does not weaken the limiter so
	 * much as switch it off: any caller could pick their own identity.
	 */
	public function test_a_forwarded_ip_is_ignored_when_the_peer_is_not_a_cloudflare_edge(): void {
		$_SERVER['REMOTE_ADDR']           = '203.0.113.9';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';

		$this->assertSame( '203.0.113.9', Spotship_WI_Rate_Limiter::client_ip() );

		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
	}

	public function test_a_forwarded_ip_is_honoured_from_a_published_cloudflare_edge(): void {
		// 104.16.0.0/13
		$_SERVER['REMOTE_ADDR']           = '104.16.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';

		$this->assertSame( '198.51.100.7', Spotship_WI_Rate_Limiter::client_ip() );

		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
	}

	/**
	 * Boundary addresses, because an off-by-one in the subnet arithmetic either
	 * trusts a header it should not or stops trusting the real edge.
	 */
	public function test_the_cloudflare_range_boundaries_are_right(): void {
		$cases = array(
			// address        => inside a published range?
			'104.15.255.255' => false,
			'104.16.0.0'     => true,
			'104.23.255.255' => true,
			'104.24.0.0'     => true,
			'131.0.72.0'     => true,
			'131.0.76.0'     => false,
			'8.8.8.8'        => false,
		);

		foreach ( $cases as $ip => $expected ) {
			$_SERVER['REMOTE_ADDR']           = $ip;
			$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';

			$honoured = '198.51.100.7' === Spotship_WI_Rate_Limiter::client_ip();

			$this->assertSame( $expected, $honoured, "boundary case {$ip}" );
		}

		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
	}

	/**
	 * THE DEFECT THIS GUARDS AGAINST: an IPv6 edge that is not recognised as
	 * Cloudflare means CF-Connecting-IP is ignored and REMOTE_ADDR — the edge —
	 * becomes the limiter identity for EVERY visitor behind it. Five submissions
	 * from anybody and the whole edge is blocked for an hour.
	 *
	 * This was previously argued as the safe failure on the grounds that it
	 * over-limits rather than under-limits. Over-limiting here is an outage for
	 * real customers, not a stricter rule for abusers.
	 */
	public function test_a_forwarded_ip_is_honoured_from_an_ipv6_cloudflare_edge(): void {
		$_SERVER['REMOTE_ADDR']           = '2606:4700:3033::ac43:a1cd';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';

		$this->assertSame( '198.51.100.7', Spotship_WI_Rate_Limiter::client_ip() );

		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
	}

	/**
	 * The v6 boundaries, and specifically the /29.
	 *
	 * 2a06:98c0::/29 does not end on a byte, so a prefix comparison that only
	 * walks whole bytes trusts 2a06:98c8:: through 2a06:98ff:: as well — eight
	 * times the range Cloudflare publishes, every address in it able to name its
	 * own limiter identity with a header.
	 */
	public function test_the_cloudflare_ipv6_range_boundaries_are_right(): void {
		$cases = array(
			/* address => inside a published range? */
			'2400:cb00::1'            => true,
			'2400:CB00:0:0:0:0:0:1'   => true,  // Same address, written differently.
			'2606:4700:3033::ac43:a1' => true,
			'2a06:98c0::1'            => true,
			'2a06:98c7:ffff::1'       => true,  // Last address of the /29.
			'2a06:98c8::1'            => false, // First outside it.
			'2a06:98bf:ffff::1'       => false, // Last below it.
			'2400:cb01::1'            => false, // Adjacent prefix.
			'2001:4860:4860::8888'    => false, // Google, not Cloudflare.
		);

		foreach ( $cases as $ip => $expected ) {
			$_SERVER['REMOTE_ADDR']           = $ip;
			$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';

			$honoured = '198.51.100.7' === Spotship_WI_Rate_Limiter::client_ip();

			$this->assertSame( $expected, $honoured, "v6 boundary case {$ip}" );
		}

		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
	}
}
