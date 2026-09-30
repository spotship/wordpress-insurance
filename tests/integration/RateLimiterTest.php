<?php
/**
 * Per-IP rate limiting.
 *
 * Integration rather than unit because the behaviour under test is the counter
 * itself — incremented by a conditional SQL statement rather than by PHP — and
 * faking the database would test the fake.
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

	/** The option the limiter counts in. */
	private function key(): string {
		return 'spotship_wi_rl_test_' . md5( Spotship_WI_Rate_Limiter::client_ip() );
	}

	/**
	 * Put the bucket into a given state directly.
	 *
	 * @param int $expires When the window ends.
	 * @param int $count   Requests already counted.
	 */
	private function store( int $expires, int $count ): void {
		update_option( $this->key(), $expires . ':' . $count, false );
	}

	/** When the current window ends. */
	private function expires(): int {
		return (int) strtok( (string) get_option( $this->key(), '0:0' ), ':' );
	}

	/** How many requests the current window has counted. */
	private function count(): int {
		$parts = explode( ':', (string) get_option( $this->key(), '0:0' ) );

		return (int) end( $parts );
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

		$expiry_after_one = $this->expires();

		$this->assertGreaterThan( 0, $expiry_after_one, 'the first request must open a window' );

		for ( $i = 0; $i < 5; $i++ ) {
			Spotship_WI_Rate_Limiter::consume( 'test', 10, 60 );
		}

		$this->assertSame(
			$expiry_after_one,
			$this->expires(),
			'a steady stream of requests must not push the window out in front of itself'
		);
		$this->assertSame( 6, $this->count() );
	}

	/**
	 * A window whose timestamp has passed starts a new one, even if the storage
	 * layer still holds the row — which is exactly what happens on a persistent
	 * object cache that ignored the TTL.
	 */
	public function test_an_elapsed_window_starts_again_even_if_the_row_survives(): void {
		// A full, EXPIRED window. An option has no TTL, so a row whose window
		// has ended is the normal resting state rather than an anomaly.
		$this->store( time() - 1, 999 );

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
		$this->store( time() + 10, 5 );

		$verdict = Spotship_WI_Rate_Limiter::consume( 'test', 5, 3600 );

		$this->assertFalse( $verdict['allowed'] );
		$this->assertLessThanOrEqual( 10, $verdict['retry_after'] );
		$this->assertGreaterThan( 0, $verdict['retry_after'] );
	}

	/**
	 * THE DEFECT THIS GUARDS AGAINST: a count incremented in PHP rather than by
	 * the database.
	 *
	 * The old implementation read the counter, added one, and wrote it back. N
	 * concurrent requests all read the same value and all write the same
	 * increment, so the bucket advances by one instead of N and the limit is
	 * exceeded by as much as an abuser cares to arrange — concurrency being the
	 * one thing they get for free.
	 *
	 * A PHPUnit process cannot issue two genuinely simultaneous requests, so
	 * this reproduces what the race PRODUCES rather than its timing: a caller
	 * deciding from a count it read earlier. Every consume() below is handed a
	 * bucket whose stored count is already at the limit, which is exactly the
	 * state a loser of the race holds a stale reading of. Under the old code the
	 * write was unconditional and went through; here the limit lives in the
	 * statement's WHERE clause, so no row changes and the caller is refused.
	 */
	public function test_the_count_cannot_be_advanced_from_a_stale_read(): void {
		$expires = time() + 60;

		$this->store( $expires, 3 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse(
				Spotship_WI_Rate_Limiter::consume( 'test', 3, 60 )['allowed'],
				"a full bucket must refuse request {$i}, however many arrive together"
			);
		}

		$this->assertSame( 3, $this->count(), 'a refused request must not advance the count' );
		$this->assertSame( $expires, $this->expires(), 'nor move the window' );
	}

	/**
	 * The boundary the WHERE clause encodes: the limit-th request is allowed and
	 * the one after it is not.
	 *
	 * Worth its own test because `<` and `<=` are one character apart and the
	 * difference is an off-by-one in an abuse control, in whichever direction.
	 */
	public function test_the_last_permitted_request_is_the_limit_th(): void {
		$this->store( time() + 60, 2 );

		$this->assertTrue(
			Spotship_WI_Rate_Limiter::consume( 'test', 3, 60 )['allowed'],
			'the third of three must be allowed'
		);
		$this->assertSame( 3, $this->count() );

		$this->assertFalse(
			Spotship_WI_Rate_Limiter::consume( 'test', 3, 60 )['allowed'],
			'the fourth must not'
		);
		$this->assertSame( 3, $this->count() );
	}

	/**
	 * The counter must not autoload.
	 *
	 * There is one row per IP per bucket. Autoloading them would put every
	 * visitor who has ever searched into the alloptions blob, which is read on
	 * every single request — a slow, quiet, cumulative regression that would
	 * present as "the site got slower" long after the cause shipped.
	 */
	public function test_the_counter_does_not_autoload(): void {
		global $wpdb;

		Spotship_WI_Rate_Limiter::consume( 'test', 3, 60 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- `autoload` is a column no WordPress API exposes.
		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				$this->key()
			)
		);

		$this->assertNotSame( 'yes', $autoload, 'per-IP counters must never autoload' );
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
