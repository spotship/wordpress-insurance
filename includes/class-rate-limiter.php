<?php
/**
 * Per-IP rate limiting.
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
 * Per-IP limiter, counted atomically in the options table.
 *
 * See consume() for why the storage is a plain option rather than a transient,
 * and why every state change is one conditional statement.
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
	 * THE COUNT IS INCREMENTED BY THE DATABASE, NOT BY PHP.
	 *
	 * This used to be `get_transient()`, add one, `set_transient()`. That is a
	 * read-then-write race, and it is the abuse control's own limit that it
	 * loses: N concurrent requests all read the same count, all write count + 1,
	 * and the bucket advances by one instead of N. Concurrency is the one thing
	 * an abuser controls for free, so the limit could be exceeded by as much as
	 * they cared to arrange — which is the opposite of what a limiter is for.
	 *
	 * Every state change below is therefore a SINGLE conditional statement whose
	 * WHERE clause is the decision. MySQL takes a row lock for the duration, so
	 * concurrent callers serialise, and `affected_rows` reports whether THIS
	 * caller was the one allowed through. Nothing is decided in PHP between a
	 * read and a write, because there is no such gap.
	 *
	 * WHY PLAIN OPTIONS RATHER THAN TRANSIENTS. The atomicity has to happen in
	 * the database, and a transient on an install with a persistent object cache
	 * never reaches the database at all. Storing the counter as an option with
	 * `autoload = false` is what makes one implementation correct on every
	 * install. The cost is a database write per check where an object-cache
	 * install previously did a cache write; at this plugin's ceilings — 30
	 * searches a minute and 5 submissions an hour, per IP — that is not a load
	 * anyone will notice, and it buys a limit that actually holds.
	 *
	 * THE WINDOW'S EXPIRY IS STILL CARRIED IN THE VALUE, for the reason it
	 * always was: re-setting a TTL on each request would let a steady stream of
	 * requests push the window out in front of itself, and a one-minute limit
	 * would become permanent for anyone who keeps trying, including a legitimate
	 * visitor retrying a form. The value is `<expires>:<count>`.
	 *
	 * @param string $bucket Logical bucket, e.g. 'search' or 'submit'.
	 * @param int    $limit  Requests permitted per window.
	 * @param int    $window Window length in seconds.
	 * @return array{allowed: bool, retry_after: int}
	 */
	public static function consume( string $bucket, int $limit, int $window ): array {
		global $wpdb;

		$key = self::key( $bucket );
		$now = time();

		/*
		 * 1. No row at all. add_option() is an INSERT against the unique index on
		 *    option_name, so exactly one concurrent caller can succeed; the rest
		 *    fall through and are counted by step 2 or 3.
		 */
		if ( add_option( $key, ( $now + $window ) . ':1', '', false ) ) {
			self::collect_garbage( $now );

			return self::allowed();
		}

		/*
		 * 2. A row whose window has already ended. Restarting it is conditional on
		 *    it still being expired, so of N callers that all see an expired
		 *    window exactly one restarts it and the others go on to step 3 and are
		 *    counted against the window it just opened.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the atomicity IS the point; see the method docblock. The cache entry is dropped by forget() immediately below.
		$restarted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->options is a table name, not input.
				"UPDATE {$wpdb->options} SET option_value = %s
				 WHERE option_name = %s
				   AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) <= %d",
				( $now + $window ) . ':1',
				$key,
				$now
			)
		);

		self::forget( $key );

		if ( $restarted ) {
			return self::allowed();
		}

		/*
		 * 3. A live window. The increment happens inside the statement and only
		 *    while the stored count is under the limit, so the (N + 1)th caller
		 *    changes no row and is refused — however many arrive together.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above: a cached read is exactly the bug being removed.
		$counted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->options is a table name, not input.
				"UPDATE {$wpdb->options}
				 SET option_value = CONCAT(
				     SUBSTRING_INDEX(option_value, ':', 1),
				     ':',
				     CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) + 1
				 )
				 WHERE option_name = %s
				   AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) > %d
				   AND CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) < %d",
				$key,
				$now,
				$limit
			)
		);

		self::forget( $key );

		if ( $counted ) {
			return self::allowed();
		}

		// Refused. The honest wait, not the full window — telling a visitor to
		// wait an hour when the window ends in ten seconds loses a real enquiry.
		return array(
			'allowed'     => false,
			'retry_after' => max( 1, self::expires_at( $key ) - $now ),
		);
	}

	/**
	 * The permitted verdict.
	 *
	 * @return array{allowed: bool, retry_after: int}
	 */
	private static function allowed(): array {
		return array(
			'allowed'     => true,
			'retry_after' => 0,
		);
	}

	/**
	 * Drop the option cache entry for a key we wrote behind get_option()'s back.
	 *
	 * The statements above are deliberately direct SQL, so nothing invalidated
	 * the cached value. Leaving it would make a later read return a count from
	 * before the increment — which is the same stale-read bug in a new place.
	 *
	 * @param string $key Option name.
	 */
	private static function forget( string $key ): void {
		wp_cache_delete( $key, 'options' );
	}

	/**
	 * When the current window ends, read past the cache.
	 *
	 * @param string $key Option name.
	 */
	private static function expires_at( string $key ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must read past the cache: the statements above wrote behind get_option()'s back.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->options is a table name, not input.
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$key
			)
		);

		return (int) strtok( (string) $value, ':' );
	}

	/**
	 * Delete counters whose windows ended.
	 *
	 * An option has no TTL, so unlike the transients this replaced, nothing
	 * expires these rows. One row per IP per bucket accumulating forever is a
	 * real cost on a site with any traffic, so a first-request-in-a-window
	 * caller occasionally sweeps the dead ones — the same opportunistic pattern
	 * WordPress core uses for expired transients.
	 *
	 * Only on roughly one in fifty, because the sweep is a table scan and the
	 * work is the same whoever does it.
	 *
	 * @param int $now Current time.
	 */
	private static function collect_garbage( int $now ): void {
		global $wpdb;

		if ( 0 !== wp_rand( 0, 49 ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- housekeeping on rows being deleted; caching them would be warming what is going away.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->options is a table name, not input.
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				   AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) <= %d",
				$wpdb->esc_like( self::PREFIX ) . '%',
				$now
			)
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
		delete_option( self::key( $bucket ) );
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
