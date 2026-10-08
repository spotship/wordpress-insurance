<?php
/**
 * Support diagnostics.
 *
 * We cannot see the customer's server. This class is the difference between a
 * support thread and guesswork, so it records the things an administrator
 * cannot otherwise discover: which cache tier is actually serving, what the
 * last upstream failures were, whether the install is bound to this site, and
 * whether outbound HTTPS works at all.
 *
 * NOTHING HERE IS EVER SHOWN TO A VISITOR. It is admin-only by construction —
 * the public routes deliberately return one neutral sentence for every failure,
 * and the truth lives here instead.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostics ledger and support blob.
 */
final class Spotship_WI_Diagnostics {

	private const LEDGER     = 'spotship_wi_ledger';
	private const LAST_OK    = 'spotship_wi_last_ok';
	private const LEDGER_TTL = 604800;
	private const LEDGER_MAX = 20;

	/**
	 * Installation states an administrator may need explained.
	 *
	 * `record()` routes through the same code-keyed guard as `record_state()`,
	 * so a call site added later obeys the cap and the dedup automatically
	 * rather than depending on whoever writes it remembering to.
	 */
	public const STATE_UNBOUND      = 'unbound';
	public const STATE_RATE_LIMITED = 'rate_limited';
	public const STATE_UNAVAILABLE  = 'unavailable';
	public const STATE_UNAUTHORISED = 'unauthorised';

	/**
	 * Every state code this plugin can record.
	 *
	 * @var array<int, string>
	 */
	private const STATE_CODES = array(
		self::STATE_UNBOUND,
		self::STATE_RATE_LIMITED,
		self::STATE_UNAVAILABLE,
		self::STATE_UNAUTHORISED,
	);

	/**
	 * Record an upstream failure against an endpoint.
	 *
	 * @param string $endpoint Upstream endpoint.
	 * @param int    $status   HTTP status, 0 for transport failures.
	 * @param string $code     Normalised client code.
	 */
	public static function record( string $endpoint, int $status, string $code ): void {
		self::append(
			array(
				'at'       => gmdate( 'c' ),
				'endpoint' => $endpoint,
				'status'   => $status,
				'code'     => self::normalise_code( $code ),
			)
		);
	}

	/**
	 * Record an installation state with no upstream call behind it.
	 *
	 * @param string $code One of the STATE_* constants.
	 */
	public static function record_state( string $code ): void {
		self::append(
			array(
				'at'       => gmdate( 'c' ),
				'endpoint' => '',
				'status'   => 0,
				'code'     => self::normalise_code( $code ),
			)
		);
	}

	/**
	 * Note a successful rates fetch.
	 */
	public static function record_rates_success(): void {
		set_transient( self::LAST_OK, gmdate( 'c' ), self::LEDGER_TTL );
	}

	/**
	 * The support blob.
	 *
	 * @return array<string, mixed>
	 */
	public static function blob(): array {
		$serving = Spotship_WI_Rates_Cache::get();

		return array(
			'plugin_version'   => SPOTSHIP_WI_VERSION,
			'php_version'      => PHP_VERSION,
			'wp_version'       => get_bloginfo( 'version' ),
			'api_base_url'     => (string) Spotship_WI_Settings::get( 'api_base_url', '' ),
			'api_key_present'  => '' !== Spotship_WI_Settings::api_key(),
			'api_key_source'   => Spotship_WI_Settings::key_is_constant() ? 'wp-config' : 'database',
			'api_key_masked'   => self::mask( Spotship_WI_Settings::api_key() ),
			'bound_site_url'   => Spotship_WI_Settings::bound_site_url(),
			'current_site_url' => Spotship_WI_Settings::current_site_url(),
			'binding_ok'       => ! Spotship_WI_Settings::binding_mismatch(),
			'rates_tier'       => $serving['tier'],
			'rates_updated_at' => self::published_at( $serving ),
			'rates_last_ok'    => self::as_text( get_transient( self::LAST_OK ) ),
			'outbound_https'   => self::outbound_https_works(),
			'recent_errors'    => self::ledger(),
		);
	}

	/**
	 * The publication date of the table actually being served.
	 *
	 * Taken from the SAME resolution that produced the tier label, not from a
	 * second expression that re-derives which tier is serving. Two expressions
	 * enumerating the tiers independently drift the moment one of them gains a
	 * branch — which is how a correctly-labelled bundled tier ends up reporting
	 * an empty date.
	 *
	 * @param array{tier: string, rates: array<string, mixed>} $serving Resolved tier.
	 */
	private static function published_at( array $serving ): string {
		$rates = $serving['rates'];

		return isset( $rates['updatedAt'] ) && is_scalar( $rates['updatedAt'] )
			? (string) $rates['updatedAt']
			: '';
	}

	/**
	 * Whether outbound HTTPS works at all.
	 *
	 * Some locked-down shared hosts block it entirely, which presents as every
	 * upstream call failing for no visible reason. Worth distinguishing from a
	 * wrong key before anyone starts rotating credentials.
	 */
	private static function outbound_https_works(): bool {
		$response = wp_safe_remote_head(
			'https://api.github.com/',
			array(
				'timeout'     => 5,
				'redirection' => 0,
			)
		);

		return ! is_wp_error( $response );
	}

	/**
	 * Mask a credential for display.
	 *
	 * @param string $value Credential.
	 */
	private static function mask( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		return substr( $value, 0, 4 ) . str_repeat( '*', max( 0, strlen( $value ) - 8 ) ) . substr( $value, -4 );
	}

	/**
	 * Constrain a code to the known set.
	 *
	 * An unrecognised code is recorded as `unavailable` rather than stored
	 * verbatim, so a future call site cannot put arbitrary upstream text into a
	 * blob an administrator will paste into a support ticket.
	 *
	 * @param string $code Candidate code.
	 */
	private static function normalise_code( string $code ): string {
		if ( in_array( $code, self::STATE_CODES, true ) ) {
			return $code;
		}

		/*
		 * THROTTLED HAS ITS OWN STATE, AND IT HAD BEEN COLLAPSING INTO
		 * "UNAVAILABLE".
		 *
		 * Everything this function did not recognise fell through to
		 * UNAVAILABLE, and `throttled` is not a STATE_* constant, so an upstream
		 * 429 was filed as "the backend could not be reached". STATE_RATE_LIMITED
		 * was defined, listed in STATE_CODES, and never written by anything.
		 *
		 * The blob is what an administrator sends to support, so the distinction
		 * is the whole point of recording it: "unavailable" sends someone
		 * hunting a network fault or an outage, when the actual answer is that
		 * the install is over a limit and the remedy is to wait or to raise it.
		 */
		if ( Spotship_WI_Api_Client::THROTTLED === $code ) {
			return self::STATE_RATE_LIMITED;
		}

		return Spotship_WI_Api_Client::UNAUTHORISED === $code
			? self::STATE_UNAUTHORISED
			: self::STATE_UNAVAILABLE;
	}

	/**
	 * Append to the ledger, newest first, capped.
	 *
	 * @param array<string, mixed> $entry Ledger entry.
	 */
	private static function append( array $entry ): void {
		$ledger = self::ledger();

		/*
		 * COLLAPSE A REPEAT INSTEAD OF STACKING IT.
		 *
		 * The class docblock has always claimed this ledger had "the cap and the
		 * dedup". The cap was real; the dedup was not, and nothing had noticed
		 * because the only call sites fired rarely.
		 *
		 * It stops being harmless the moment a throttle is recorded. A scraper
		 * that trips the search limit produces one entry per request, so twenty
		 * identical `rate_limited` rows evict every other thing that happened —
		 * the 401 that preceded them, the binding failure, the last successful
		 * fetch — and the blob an administrator sends to support says only that
		 * they were rate limited, twenty times, with no history.
		 *
		 * So a repeat of the newest entry updates its timestamp and counts
		 * rather than pushing a row off the end. `first_at` keeps the start of
		 * the run, which is what makes "47 times since 09:12" readable.
		 */
		$newest = $ledger[0] ?? null;

		if ( is_array( $newest )
			&& ( $newest['code'] ?? null ) === $entry['code']
			&& ( $newest['endpoint'] ?? null ) === $entry['endpoint']
			&& ( $newest['status'] ?? null ) === $entry['status']
		) {
			$newest['count']    = ( isset( $newest['count'] ) ? (int) $newest['count'] : 1 ) + 1;
			$newest['first_at'] = $newest['first_at'] ?? $newest['at'];
			$newest['at']       = $entry['at'];
			$ledger[0]          = $newest;

			set_transient( self::LEDGER, $ledger, self::LEDGER_TTL );

			return;
		}

		array_unshift( $ledger, $entry );

		set_transient( self::LEDGER, array_slice( $ledger, 0, self::LEDGER_MAX ), self::LEDGER_TTL );
	}

	/**
	 * A transient value as a string, or '' when it is absent.
	 *
	 * `get_transient()` returns false when the key is missing, and a short
	 * ternary over that is both banned by the ruleset and genuinely ambiguous —
	 * it hides whether '' means "never fetched" or "fetched, empty".
	 *
	 * @param mixed $value Raw transient value.
	 */
	private static function as_text( $value ): string {
		return is_string( $value ) ? $value : '';
	}

	/**
	 * The current ledger.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function ledger(): array {
		$ledger = get_transient( self::LEDGER );

		return is_array( $ledger ) ? $ledger : array();
	}
}
