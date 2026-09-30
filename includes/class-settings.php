<?php
/**
 * Settings storage, sanitisation and origin binding.
 *
 * ONE option array, ONE sanitize_callback. Everything the administrator can set
 * lives in `spotship_wi_settings`, so there is exactly one place where untrusted
 * admin input becomes stored configuration, and exactly one function to audit.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings.
 */
final class Spotship_WI_Settings {

	public const OPTION = 'spotship_wi_settings';

	/**
	 * Cache TTL bounds, in seconds.
	 *
	 * Floor of 60 because anything lower turns every page view into an upstream
	 * request under the 30/hour rates throttle. Ceiling of 24h because war-risk
	 * rates move fast and a day-old table is the most staleness we will serve
	 * without the operator having said so.
	 */
	public const TTL_MIN     = 60;
	public const TTL_MAX     = 86400;
	public const TTL_DEFAULT = 3600;

	/**
	 * A v4 UUID, which is the shape of a Customer.api_key.
	 *
	 * Validated here so a paste accident is caught at the settings screen rather
	 * than becoming an opaque 401 on a public form.
	 */
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

	/**
	 * Defaults for every key. `all()` merges over these, so a key added in a
	 * later version reads as its default on an install that predates it rather
	 * than as null.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'api_base_url'       => '',
			'api_key'            => '',
			'rates_cache_ttl'    => self::TTL_DEFAULT,
			'privacy_notice_url' => '',
			'support_email'      => '',
			'bound_site_url'     => '',
		);
	}

	/**
	 * All settings, defaults merged in.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Returned when the key is absent or empty.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = '' ) {
		$all = self::all();

		return array_key_exists( $key, $all ) && '' !== $all[ $key ] ? $all[ $key ] : $fallback;
	}

	/**
	 * The API key, preferring the wp-config constant.
	 *
	 * A constant keeps the credential out of the database entirely, which is the
	 * recommended install. When it is set the settings field is read-only, so
	 * the DB copy can never silently shadow it.
	 */
	public static function api_key(): string {
		if ( defined( 'SPOTSHIP_WI_API_KEY' ) && is_string( SPOTSHIP_WI_API_KEY ) && '' !== SPOTSHIP_WI_API_KEY ) {
			return SPOTSHIP_WI_API_KEY;
		}

		return (string) self::get( 'api_key', '' );
	}

	/**
	 * Whether the key comes from wp-config rather than the database.
	 */
	public static function key_is_constant(): bool {
		return defined( 'SPOTSHIP_WI_API_KEY' ) && is_string( SPOTSHIP_WI_API_KEY ) && '' !== SPOTSHIP_WI_API_KEY;
	}

	/**
	 * Whether this install has enough configuration to reach the API at all.
	 *
	 * Both halves are required. See `sanitize()` for why this matters beyond
	 * being a convenience.
	 */
	public static function is_configured(): bool {
		return '' !== (string) self::get( 'api_base_url', '' ) && '' !== self::api_key();
	}

	/**
	 * Sanitise submitted settings.
	 *
	 * The single trust boundary between the settings screen and stored config.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$existing = self::all();
		$clean    = self::defaults();

		/*
		 * https only, and never downgraded to http by a typo. This carries a
		 * bearer credential on every request; a plaintext base URL would put a
		 * customer's API key on the wire.
		 */
		$base = isset( $input['api_base_url'] ) ? esc_url_raw( trim( (string) $input['api_base_url'] ), array( 'https' ) ) : '';
		$clean['api_base_url'] = str_starts_with( $base, 'https://' ) ? untrailingslashit( $base ) : '';

		/*
		 * The key is never echoed back into the form, so an empty submission
		 * means "unchanged", not "clear it". Clearing is done with the explicit
		 * control, not by submitting a blank field — otherwise every save from a
		 * screen that masks the field would wipe the credential.
		 */
		if ( self::key_is_constant() ) {
			$clean['api_key'] = '';
		} else {
			$submitted = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';

			if ( '' === $submitted ) {
				$clean['api_key'] = (string) ( $existing['api_key'] ?? '' );
			} elseif ( 1 === preg_match( self::UUID_PATTERN, $submitted ) ) {
				$clean['api_key'] = strtolower( $submitted );
			} else {
				$clean['api_key'] = (string) ( $existing['api_key'] ?? '' );
				add_settings_error(
					self::OPTION,
					'spotship_wi_api_key',
					__( 'The API key must be a UUID. The previous key was kept.', 'spotship-war-insurance' )
				);
			}
		}

		$ttl                      = isset( $input['rates_cache_ttl'] ) ? (int) $input['rates_cache_ttl'] : self::TTL_DEFAULT;
		$clean['rates_cache_ttl'] = max( self::TTL_MIN, min( self::TTL_MAX, $ttl ) );

		$privacy                     = isset( $input['privacy_notice_url'] ) ? esc_url_raw( trim( (string) $input['privacy_notice_url'] ) ) : '';
		$clean['privacy_notice_url'] = $privacy;

		$email                  = isset( $input['support_email'] ) ? sanitize_email( (string) $input['support_email'] ) : '';
		$clean['support_email'] = is_email( $email ) ? $email : '';

		/*
		 * ORIGIN BINDING — and the reason this is not simply
		 * `$clean['bound_site_url'] = self::current_site_url()`.
		 *
		 * Binding an install that cannot reach the API produces a site that
		 * PASSES the binding check and then fails at the transport layer: the
		 * proxy builds a URL from an empty base, wp_safe_remote_post() refuses it
		 * without a packet leaving the machine, and the visitor is shown a 502.
		 * Worse, that is a locally-decidable refusal sitting BELOW the anti-bot
		 * gates, so a flagged client gets a cheerful 200 on the same payload and
		 * can read the difference.
		 *
		 * So: bind only when the install is actually configured. An unconfigured
		 * install stays unbound and reports itself as unbound, which is the
		 * honest state and the one the diagnostics panel can explain.
		 *
		 * An existing binding is preserved rather than silently re-pointed — a
		 * clone that saves settings must not thereby adopt itself. Re-binding is
		 * an explicit, separate action.
		 */
		$existing_binding = (string) ( $existing['bound_site_url'] ?? '' );

		if ( '' !== $existing_binding ) {
			$clean['bound_site_url'] = $existing_binding;
		} elseif ( '' !== $clean['api_base_url'] && '' !== ( self::key_is_constant() ? self::api_key() : $clean['api_key'] ) ) {
			$clean['bound_site_url'] = self::current_site_url();
		} else {
			$clean['bound_site_url'] = '';
		}

		return $clean;
	}

	/**
	 * This site's canonical URL, normalised for comparison.
	 *
	 * Scheme and trailing slash are stripped so that an http->https migration or
	 * a trailing-slash change does not read as a different site and take a live
	 * form down.
	 */
	public static function current_site_url(): string {
		return self::normalise_site_url( home_url() );
	}

	/**
	 * Normalise a site URL for comparison.
	 *
	 * @param string $url Raw URL.
	 */
	public static function normalise_site_url( string $url ): string {
		$url = strtolower( untrailingslashit( trim( $url ) ) );

		return (string) preg_replace( '#^https?://#', '', $url );
	}

	/**
	 * The host of a URL, lowercased, with a non-default port kept.
	 *
	 * Used for same-origin checks, where ONLY the host may be compared. An
	 * `Origin` header is scheme+host, but a `Referer` is a full URL including the
	 * path — so comparing normalised URLs matches on Origin and never matches on
	 * Referer, silently disabling the fallback the check depends on.
	 *
	 * The port is retained because host:8080 is a different origin from host:443,
	 * and dropping it would let a dev server on the same hostname pass.
	 *
	 * @param string $url Candidate URL.
	 */
	public static function host_of( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

		if ( 0 !== $port && 80 !== $port && 443 !== $port ) {
			$host .= ':' . $port;
		}

		return $host;
	}

	/**
	 * This site's host, for same-origin comparison.
	 */
	public static function current_host(): string {
		return self::host_of( home_url() );
	}

	/**
	 * The URL this install is bound to, or '' when unbound.
	 */
	public static function bound_site_url(): string {
		return (string) self::get( 'bound_site_url', '' );
	}

	/**
	 * Whether the current site differs from the bound one.
	 *
	 * An UNBOUND install is not a mismatch — it is unconfigured, which is a
	 * different state with a different remedy. Callers must distinguish them.
	 */
	public static function binding_mismatch(): bool {
		$bound = self::bound_site_url();

		return '' !== $bound && self::current_site_url() !== $bound;
	}

	/**
	 * Point the binding at the current site.
	 *
	 * The one-click recovery from a clone. Deliberately does not touch the key:
	 * the administrator should not have to re-enter a credential to fix a URL.
	 */
	public static function bind_to_current(): void {
		$all                   = self::all();
		$all['bound_site_url'] = self::current_site_url();

		update_option( self::OPTION, $all );
	}
}
