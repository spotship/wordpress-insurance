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
	 * Consume one unit from a bucket.
	 *
	 * @param string $bucket Logical bucket, e.g. 'search' or 'submit'.
	 * @param int    $limit  Requests permitted per window.
	 * @param int    $window Window length in seconds.
	 * @return array{allowed: bool, retry_after: int}
	 */
	public static function consume( string $bucket, int $limit, int $window ): array {
		$key   = self::key( $bucket );
		$count = get_transient( $key );

		if ( false === $count ) {
			set_transient( $key, 1, $window );

			return array(
				'allowed'     => true,
				'retry_after' => 0,
			);
		}

		$count = (int) $count;

		if ( $count >= $limit ) {
			return array(
				'allowed'     => false,
				'retry_after' => $window,
			);
		}

		/*
		 * set_transient with the remaining window is not available — WordPress
		 * does not expose a transient's remaining TTL portably. Re-setting with
		 * the full window would let a steady stream of requests extend the
		 * block indefinitely, so the counter is updated in place via the
		 * timeout-preserving path instead.
		 */
		self::increment( $key, $count + 1 );

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
	 * Update a counter without extending its expiry.
	 *
	 * @param string $key   Transient key.
	 * @param int    $value New value.
	 */
	private static function increment( string $key, int $value ): void {
		global $wpdb;

		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $value, 'transient' );

			return;
		}

		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => (string) $value ),
			array( 'option_name' => '_transient_' . $key )
		);

		wp_cache_delete( '_transient_' . $key, 'options' );
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
	 * IPv6 edges are not enumerated here; they fall through to REMOTE_ADDR,
	 * which rate-limits the edge rather than the visitor. That is the safe
	 * failure: it over-limits rather than under-limits.
	 *
	 * @param string $ip Candidate address.
	 */
	private static function is_cloudflare( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return false;
		}

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
}
