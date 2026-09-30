<?php
/**
 * Per-IP rate limiting, backed by transients.
 *
 * These limits are not the backend's limits. The upstream search ceiling is
 * 120/min per CUSTOMER and is deliberately not lowered, which means one
 * compromised or scraped install could consume it entirely. The per-IP limits
 * here, together with the response trimming in `class-payloads.php`, are what
 * stand between a casual scraper and the vessel database.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Transient-backed per-IP limiter.
 */
final class Spotship_WI_Rate_Limiter {

	private const PREFIX = 'spotship_wi_rl_';

	/**
	 * Cloudflare's published edge ranges.
	 *
	 * `CF-Connecting-IP` is honoured ONLY when REMOTE_ADDR is one of these.
	 * Trusting that header unconditionally would let any caller set their own
	 * rate-limit identity by sending a header, which turns the limiter off.
	 *
	 * Refresh from https://www.cloudflare.com/ips/ when Cloudflare publishes
	 * changes; a stale list fails CLOSED (we fall back to REMOTE_ADDR and rate
	 * limit the edge itself), which is the safe direction.
	 *
	 * @var array<int, string>
	 */
	private const CLOUDFLARE_V4 = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
	);

	/**
	 * Cloudflare's published IPv6 ranges — https://www.cloudflare.com/ips/
	 *
	 * NOT ENUMERATING THESE WAS NOT THE SAFE FAILURE IT CLAIMED TO BE.
	 *
	 * The comment on is_cloudflare() used to argue that letting IPv6 fall through
	 * to REMOTE_ADDR "over-limits rather than under-limits", and treated that as
	 * the conservative choice. It is not conservative for the visitor: every
	 * IPv6 request arrives from the EDGE address, so every IPv6 visitor on that
	 * edge shares one bucket. Five submissions from anybody and the whole edge is
	 * blocked for an hour — and IPv6 is a large and growing share of consumer
	 * traffic, so that is an outage for real customers rather than a stricter
	 * limit on abusers.
	 */
	private const CLOUDFLARE_V6 = array(
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Consume one unit from a bucket.
	 *
	 * THE WINDOW'S EXPIRY IS CARRIED IN THE VALUE, not delegated to the storage
	 * layer's TTL. That is deliberate and it is what makes this correct on every
	 * install rather than only on some:
	 *
	 *   - WordPress exposes no portable way to read a transient's REMAINING TTL,
	 *     so re-setting with the full window on each request would let a steady
	 *     stream of requests extend the block indefinitely. A one-minute limit
	 *     would become permanent for anyone who keeps trying — including a
	 *     legitimate visitor retrying a form.
	 *
	 *   - Writing the counter straight into the cache to avoid that is worse: on
	 *     an install with a PERSISTENT object cache (Redis, Memcached) a value
	 *     written without a TTL never expires at all, so the block becomes
	 *     genuinely permanent and only a cache flush clears it.
	 *
	 * Holding `expires` in the value sidesteps both. The stored TTL is a cleanup
	 * hint; the window ends when the timestamp says so, whatever the storage did.
	 *
	 * @param string $bucket Logical bucket, e.g. 'search' or 'submit'.
	 * @param int    $limit  Requests permitted per window.
	 * @param int    $window Window length in seconds.
	 * @return array{allowed: bool, retry_after: int}
	 */
	public static function consume( string $bucket, int $limit, int $window ): array {
		$key   = self::key( $bucket );
		$now   = time();
		$state = get_transient( $key );

		$expires = is_array( $state ) && isset( $state['expires'] ) ? (int) $state['expires'] : 0;

		// No window, or the previous one has ended.
		if ( $expires <= $now ) {
			set_transient(
				$key,
				array(
					'count'   => 1,
					'expires' => $now + $window,
				),
				$window
			);

			return array(
				'allowed'     => true,
				'retry_after' => 0,
			);
		}

		$count     = isset( $state['count'] ) ? (int) $state['count'] : 0;
		$remaining = max( 1, $expires - $now );

		if ( $count >= $limit ) {
			// The honest wait, not the full window — telling a visitor to wait an
			// hour when the window ends in ten seconds loses a real enquiry.
			return array(
				'allowed'     => false,
				'retry_after' => $remaining,
			);
		}

		set_transient(
			$key,
			array(
				'count'   => $count + 1,
				'expires' => $expires,
			),
			$remaining
		);

		return array(
			'allowed'     => true,
			'retry_after' => 0,
		);
	}

	/**
	 * Reset a bucket. Used by tests, which would otherwise exhaust the limit
	 * partway through a table and silently start measuring the limiter instead
	 * of the thing under test.
	 *
	 * @param string $bucket Logical bucket.
	 */
	public static function reset( string $bucket ): void {
		delete_transient( self::key( $bucket ) );
	}

	/**
	 * The transient key for a bucket and the current client.
	 *
	 * @param string $bucket Logical bucket.
	 */
	private static function key( string $bucket ): string {
		return self::PREFIX . $bucket . '_' . md5( self::client_ip() );
	}

	/**
	 * The client address.
	 *
	 * `CF-Connecting-IP` is read ONLY when the connecting address is a published
	 * Cloudflare edge. Anywhere else, a caller could set their own limiter
	 * identity with a header and never be limited at all.
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( '' === $remote || ! self::is_cloudflare( $remote ) ) {
			return $remote;
		}

		$forwarded = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) )
			: '';

		return '' !== $forwarded && false !== filter_var( $forwarded, FILTER_VALIDATE_IP ) ? $forwarded : $remote;
	}

	/**
	 * Whether an address is inside a published Cloudflare range.
	 *
	 * BOTH FAMILIES. An IPv6 peer that is a genuine Cloudflare edge must be
	 * recognised as one, or every visitor behind it is limited as a single
	 * client — see the note on CLOUDFLARE_V6.
	 *
	 * @param string $ip Candidate address.
	 */
	private static function is_cloudflare( string $ip ): bool {
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return self::in_v4_range( $ip );
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return self::in_v6_range( $ip );
		}

		return false;
	}

	/**
	 * Whether an IPv4 address is inside a published Cloudflare v4 range.
	 *
	 * @param string $ip Candidate address, already validated as IPv4.
	 */
	private static function in_v4_range( string $ip ): bool {
		$addr = ip2long( $ip );

		if ( false === $addr ) {
			return false;
		}

		foreach ( self::CLOUDFLARE_V4 as $range ) {
			list( $subnet, $bits ) = explode( '/', $range );

			$subnet_long = ip2long( $subnet );

			if ( false === $subnet_long ) {
				continue;
			}

			$mask = -1 << ( 32 - (int) $bits );

			if ( ( $addr & $mask ) === ( $subnet_long & $mask ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an IPv6 address is inside a published Cloudflare v6 range.
	 *
	 * Compared as the 16 RAW BYTES `inet_pton()` returns, not as text. A string
	 * prefix match on the printable form is wrong in both directions: `2400:cb00::1`
	 * and `2400:CB00::1` are the same address written differently, and an
	 * abbreviated form hides where one field ends and the next begins.
	 *
	 * The prefix length is applied whole bytes first, then the remaining bits of
	 * the boundary byte — a /29 is three bytes and five bits, and comparing only
	 * the whole bytes would accept a /24's worth of the internet.
	 *
	 * @param string $ip Candidate address, already validated as IPv6.
	 */
	private static function in_v6_range( string $ip ): bool {
		$addr = inet_pton( $ip );

		if ( false === $addr ) {
			return false;
		}

		foreach ( self::CLOUDFLARE_V6 as $range ) {
			list( $subnet, $bits ) = explode( '/', $range );

			$subnet_packed = inet_pton( $subnet );

			if ( false === $subnet_packed ) {
				continue;
			}

			$bits  = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			$rest  = $bits % 8;

			if ( 0 !== $bytes && 0 !== substr_compare( $addr, substr( $subnet_packed, 0, $bytes ), 0, $bytes ) ) {
				continue;
			}

			if ( 0 === $rest ) {
				return true;
			}

			$mask = ~( ( 1 << ( 8 - $rest ) ) - 1 ) & 0xFF;

			if ( ( ord( $addr[ $bytes ] ) & $mask ) === ( ord( $subnet_packed[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}

		return false;
	}
}
