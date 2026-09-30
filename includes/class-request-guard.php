<?php
/**
 * Form integrity: HMAC signature, timing trap, honeypot.
 *
 * THIS CLASS RETURNS VERDICTS, NEVER RESPONSES. That separation is deliberate
 * and load-bearing: the order in which verdicts are acted on is a security
 * property of the proxy (see `class-rest-proxy.php`), and a guard that answered
 * requests itself would scatter that ordering across two files.
 *
 * WHY NOT A WP NONCE. For a logged-out visitor a nonce adds no CSRF value —
 * there is no session to ride. Worse, under a Cloudflare "cache everything"
 * rule a nonce baked into cached HTML goes stale within its 12-hour tick and
 * EVERY submit 403s, on a form that looks perfectly fine. The replacement is
 * deterministic and therefore cache-safe: an HMAC over a timestamp and form id,
 * salted with `wp_salt('nonce')`, which any page copy can carry because it does
 * not depend on who is reading it.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Request integrity verdicts.
 */
final class Spotship_WI_Request_Guard {

	/**
	 * Verdicts.
	 *
	 * FAKE_SUCCESS means "answer as though it worked, send nothing upstream".
	 * Telling a bot that it failed teaches it what to fix; a cheerful 200 gives
	 * it nothing to tune against.
	 */
	public const VALID        = 'valid';
	public const FAKE_SUCCESS = 'fake_success';
	public const EXPIRED      = 'expired';

	/**
	 * A human cannot complete either of these forms in under three seconds.
	 * Anything faster is automation, and is answered with a decoy.
	 */
	public const MIN_AGE = 3;

	/**
	 * A day. Beyond this the form is treated as stale and the visitor is told to
	 * reload — honestly, because this is the one guard branch a REAL person hits:
	 * a page left open overnight, or served from a long-lived CDN cache.
	 */
	public const MAX_AGE = 86400;

	/**
	 * Sign a form.
	 *
	 * @param int    $timestamp Issue time.
	 * @param string $form_id   Per-render form identifier.
	 */
	public static function sign( int $timestamp, string $form_id ): string {
		return hash_hmac( 'sha256', $timestamp . '|' . $form_id, wp_salt( 'nonce' ) );
	}

	/**
	 * Verify a submitted signature and timestamp.
	 *
	 * @param int    $timestamp Submitted issue time.
	 * @param string $form_id   Submitted form identifier.
	 * @param string $signature Submitted signature.
	 * @param int    $now       Current time, injected for testability.
	 */
	public static function verify( int $timestamp, string $form_id, string $signature, int $now ): string {
		$expected = self::sign( $timestamp, $form_id );

		/*
		 * hash_equals, not ===. A timing-variant comparison on an HMAC is the
		 * textbook way to leak it a byte at a time, and this endpoint is public.
		 */
		if ( ! hash_equals( $expected, $signature ) ) {
			return self::FAKE_SUCCESS;
		}

		$age = $now - $timestamp;

		/*
		 * A negative age means a timestamp from the future — a tampered or
		 * replayed field, not a clock skew we should accommodate. Treated as
		 * automation.
		 */
		if ( $age < self::MIN_AGE ) {
			return self::FAKE_SUCCESS;
		}

		if ( $age > self::MAX_AGE ) {
			return self::EXPIRED;
		}

		return self::VALID;
	}

	/**
	 * Whether the honeypot was filled.
	 *
	 * The field is visually hidden and labelled for assistive technology as one
	 * to leave blank, so a screen-reader user is not trapped by it. Any value at
	 * all is automation.
	 *
	 * @param string $value Submitted honeypot value.
	 */
	public static function honeypot_tripped( string $value ): bool {
		return '' !== trim( $value );
	}
}
