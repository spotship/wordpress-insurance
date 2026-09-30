<?php
/**
 * Rate table caching.
 *
 * Four tiers, in order: fresh transient, 7-day backup, bundled build-time table,
 * then hide. THE FORM ALWAYS RENDERS REGARDLESS — the table is decoration on a
 * lead-capture form, and a missing table must never take the form down with it.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Tiered rates cache.
 */
final class Spotship_WI_Rates_Cache {

	public const FRESH  = 'spotship_wi_rates';
	public const BACKUP = 'spotship_wi_rates_backup';
	public const LOCK   = 'spotship_wi_rates_lock';

	/**
	 * Which tier answered. Reported by diagnostics.
	 */
	public const TIER_FRESH   = 'fresh';
	public const TIER_BACKUP  = 'backup';
	public const TIER_BUNDLED = 'bundled';
	public const TIER_NONE    = 'none';

	private const BACKUP_TTL = 604800;

	/**
	 * Ten seconds: long enough to cover an 8s upstream timeout plus overhead,
	 * short enough that a crashed request does not wedge the table for long.
	 */
	private const LOCK_TTL = 10;

	/**
	 * The rate table and the tier that produced it.
	 *
	 * @return array{tier: string, rates: array<string, mixed>}
	 */
	public static function get(): array {
		$fresh = get_transient( self::FRESH );

		if ( is_array( $fresh ) && array() !== $fresh ) {
			return self::serving( self::TIER_FRESH, $fresh );
		}

		/*
		 * Stampede guard. On a busy page the transient expires once and every
		 * concurrent request would otherwise queue behind its own 8-second
		 * upstream call. The loser of the lock serves the backup immediately
		 * rather than waiting — a slightly stale table now beats a fresh one
		 * after eight seconds of blank page.
		 */
		if ( self::acquire_lock() ) {
			$result = Spotship_WI_Api_Client::request( 'GET', 'insurance/rates' );

			if ( $result['ok'] && array() !== $result['data'] ) {
				$ttl = (int) Spotship_WI_Settings::get( 'rates_cache_ttl', Spotship_WI_Settings::TTL_DEFAULT );

				set_transient( self::FRESH, $result['data'], $ttl );
				set_transient( self::BACKUP, $result['data'], self::BACKUP_TTL );
				Spotship_WI_Diagnostics::record_rates_success();
				self::release_lock();

				return self::serving( self::TIER_FRESH, $result['data'] );
			}

			self::release_lock();

			/*
			 * THE 401 RULE. A 401 is an entitlement answer, not a reachability
			 * problem, so it SKIPS THE ENTIRE CHAIN below and hides the table.
			 *
			 * The bundled tier needs no credential, so without this a customer
			 * whose key was revoked — or who churned — keeps publishing our rate
			 * table indefinitely on their public site, under a "Powered by Spot
			 * Ship" footer. Every other failure is transport and legitimately
			 * falls through to a cached copy.
			 */
			if ( Spotship_WI_Api_Client::UNAUTHORISED === $result['code'] ) {
				self::purge();

				return self::serving( self::TIER_NONE, array() );
			}
		}

		$backup = get_transient( self::BACKUP );

		if ( is_array( $backup ) && array() !== $backup ) {
			return self::serving( self::TIER_BACKUP, $backup );
		}

		$bundled = self::bundled();

		if ( array() !== $bundled ) {
			return self::serving( self::TIER_BUNDLED, $bundled );
		}

		return self::serving( self::TIER_NONE, array() );
	}

	/**
	 * The bundled build-time table.
	 *
	 * Empty in the repository by design — see includes/data/rates-fallback.php.
	 *
	 * @return array<string, mixed>
	 */
	public static function bundled(): array {
		$path = SPOTSHIP_WI_PATH . 'includes/data/rates-fallback.php';

		if ( ! is_readable( $path ) ) {
			return array();
		}

		$data = include $path;

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Take the fetch lock, ATOMICALLY.
	 *
	 * `get_transient()` followed by `set_transient()` is a read-then-write race:
	 * on a busy page every concurrent request reads "absent" before any of them
	 * writes, they all believe they won, and the stampede the lock exists to
	 * prevent happens anyway — with the added cost of N upstream calls the
	 * operator is paying a throttle budget for.
	 *
	 * `add_option()` is an INSERT against the unique index on option_name, so
	 * exactly one caller can succeed. The expiry is carried in the value rather
	 * than by the transient layer, because the atomicity is what matters and
	 * add_option has no TTL.
	 */
	private static function acquire_lock(): bool {
		$now = time();

		if ( add_option( self::LOCK, (string) $now, '', false ) ) {
			return true;
		}

		/*
		 * A lock left behind by a crashed request would otherwise wedge the table
		 * at the backup tier until someone noticed. Anything older than the TTL is
		 * treated as abandoned and taken over.
		 */
		$held = (int) get_option( self::LOCK, '0' );

		if ( $held > 0 && ( $now - $held ) > self::LOCK_TTL ) {
			self::release_lock();

			return (bool) add_option( self::LOCK, (string) $now, '', false );
		}

		return false;
	}

	/**
	 * Release the fetch lock.
	 */
	private static function release_lock(): void {
		delete_option( self::LOCK );
	}

	/**
	 * Drop every cached copy. Used by the 401 rule and by uninstall.
	 */
	public static function purge(): void {
		delete_transient( self::FRESH );
		delete_transient( self::BACKUP );
		self::release_lock();
	}

	/**
	 * Assemble the answer.
	 *
	 * The tier label and the payload whose `updatedAt` is reported are resolved
	 * HERE, together, in one place. Resolving them separately is how the bundled
	 * tier ends up labelled correctly while reporting an empty publication date:
	 * two expressions that enumerate the tiers differently drift the moment one
	 * of them gains a branch.
	 *
	 * @param string               $tier  Tier constant.
	 * @param array<string, mixed> $rates Payload.
	 * @return array{tier: string, rates: array<string, mixed>}
	 */
	private static function serving( string $tier, array $rates ): array {
		return array(
			'tier'  => $tier,
			'rates' => $rates,
		);
	}
}
