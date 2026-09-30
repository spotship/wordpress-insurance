<?php
/**
 * HTTP client for the Spot Ship insurance API.
 *
 * THE CREDENTIAL NEVER LEAVES THIS FILE. The bearer token is read here and
 * attached here; nothing above this layer sees it, and no response body from
 * this layer is handed to a browser unexamined (see `class-payloads.php`).
 *
 * Every response is normalised to one shape, so callers branch on a `code`
 * this plugin owns rather than on whatever the upstream happened to return.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Upstream HTTP client.
 */
final class Spotship_WI_Api_Client {

	/**
	 * Eight seconds.
	 *
	 * PHP's default socket timeout would hold a page render open far longer
	 * than a visitor will wait. The rates path has a cache chain behind it, so a
	 * timeout degrades to a stale table rather than a blank one; the submit path
	 * reports an honest error. Neither benefits from waiting longer.
	 */
	private const TIMEOUT = 8;

	/**
	 * Normalised result codes. Callers branch on these, never on a raw status.
	 */
	public const OK           = 'ok';
	public const TRANSPORT    = 'transport';
	public const UNAUTHORISED = 'unauthorised';
	public const THROTTLED    = 'throttled';
	public const VALIDATION   = 'validation';
	public const SERVER       = 'server';
	public const MISCONFIGURED = 'misconfigured';

	/**
	 * Perform a request.
	 *
	 * @param string               $method   GET or POST.
	 * @param string               $endpoint Endpoint path, e.g. 'insurance/rates'.
	 * @param array<string, mixed> $args     Optional 'body' (array) and 'query' (array).
	 * @return array{ok: bool, status: int, data: array<array-key, mixed>, code: string, retry_after: int|null}
	 */
	public static function request( string $method, string $endpoint, array $args = array() ): array {
		$url = self::build_url( $endpoint, isset( $args['query'] ) && is_array( $args['query'] ) ? $args['query'] : array() );

		/*
		 * An unconfigured install is refused HERE, explicitly, rather than being
		 * allowed to build a relative URL that wp_safe_remote_post() rejects at
		 * the transport layer.
		 *
		 * The difference matters: a relative URL produces a generic transport
		 * failure that reads like a network problem, so the diagnostics panel
		 * tells an administrator to check their firewall when the real answer is
		 * that they never entered a base URL. `Spotship_WI_Settings::sanitize()`
		 * already refuses to bind an unconfigured install, so this is defence in
		 * depth for the case where the option is edited directly.
		 */
		if ( '' === $url ) {
			return self::result( false, 0, array(), self::MISCONFIGURED, null );
		}

		$key = Spotship_WI_Settings::api_key();

		if ( '' === $key ) {
			return self::result( false, 0, array(), self::MISCONFIGURED, null );
		}

		$request = array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array(
				'Authorization'    => 'Bearer ' . $key,
				'Accept'           => 'application/json',
				'X-Plugin-Version' => SPOTSHIP_WI_VERSION,
			),
		);

		if ( 'POST' === strtoupper( $method ) ) {
			$request['headers']['Content-Type'] = 'application/json';
			$request['body']                    = wp_json_encode( isset( $args['body'] ) ? $args['body'] : array() );

			$response = wp_safe_remote_post( $url, $request );
		} else {
			$response = wp_safe_remote_get( $url, $request );
		}

		return self::normalise( $response );
	}

	/**
	 * Build an absolute endpoint URL.
	 *
	 * Returns '' when the base is not configured, which the caller treats as
	 * MISCONFIGURED rather than attempting the request.
	 *
	 * TRAILING SLASHES ARE MANDATORY. Django's APPEND_SLASH issues a redirect
	 * for a slashless path, and a redirected POST loses its body — so a missing
	 * slash is not a 404 you would notice, it is a silent empty submission.
	 * `redirection => 0` above makes that failure loud rather than silent.
	 *
	 * @param string               $endpoint Endpoint path.
	 * @param array<string, mixed> $query    Query parameters.
	 */
	public static function build_url( string $endpoint, array $query = array() ): string {
		$base = (string) Spotship_WI_Settings::get( 'api_base_url', '' );

		if ( '' === $base ) {
			return '';
		}

		$url = trailingslashit( $base ) . trailingslashit( ltrim( $endpoint, '/' ) );

		if ( array() !== $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}

		return $url;
	}

	/**
	 * Turn a WP_Error or raw response into the normalised shape.
	 *
	 * @param array<string, mixed>|WP_Error $response Raw wp_remote_* return.
	 * @return array{ok: bool, status: int, data: array<array-key, mixed>, code: string, retry_after: int|null}
	 */
	private static function normalise( $response ): array {
		if ( is_wp_error( $response ) ) {
			return self::result( false, 0, array(), self::TRANSPORT, null );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$parsed = json_decode( $body, true );
		$data   = is_array( $parsed ) ? $parsed : array();

		$retry_header = wp_remote_retrieve_header( $response, 'retry-after' );
		$retry_after  = is_string( $retry_header ) && ctype_digit( $retry_header ) ? (int) $retry_header : null;

		if ( $status >= 200 && $status < 300 ) {
			return self::result( true, $status, $data, self::OK, null );
		}

		if ( 401 === $status || 403 === $status ) {
			return self::result( false, $status, array(), self::UNAUTHORISED, null );
		}

		if ( 429 === $status ) {
			return self::result( false, $status, array(), self::THROTTLED, $retry_after );
		}

		if ( 400 === $status ) {
			return self::result( false, $status, $data, self::VALIDATION, null );
		}

		return self::result( false, $status, array(), self::SERVER, $retry_after );
	}

	/**
	 * Assemble the normalised shape.
	 *
	 * @param bool                       $ok          Whether the call succeeded.
	 * @param int                        $status      HTTP status, 0 for transport failures.
	 * @param array<array-key, mixed>    $data        Decoded body.
	 * @param string                     $code        One of the class constants.
	 * @param int|null                   $retry_after Seconds, when the upstream said so.
	 * @return array{ok: bool, status: int, data: array<array-key, mixed>, code: string, retry_after: int|null}
	 */
	private static function result( bool $ok, int $status, array $data, string $code, ?int $retry_after ): array {
		return array(
			'ok'          => $ok,
			'status'      => $status,
			'data'        => $data,
			'code'        => $code,
			'retry_after' => $retry_after,
		);
	}
}
