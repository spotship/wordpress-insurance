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
	 * Faster than this since the form RENDERED is treated as automation and
	 * answered with a decoy.
	 *
	 * THIS WAS THREE SECONDS, AND THREE SECONDS COST REAL ENQUIRIES.
	 *
	 * The decoy is byte-identical to a success and sends nothing upstream —
	 * that is the whole point, so a bot cannot tell it was caught. The price is
	 * that a HUMAN cannot tell either: they see "thank you", the enquiry is
	 * gone, and nothing anywhere records it. Nobody will ever report it.
	 *
	 * The old comment said "a human cannot complete either of these forms in
	 * under three seconds", which is true and beside the point. Nothing
	 * requires the form to be COMPLETE to submit it: an incomplete payload
	 * reaches the serializer and comes back as field errors telling the visitor
	 * what is missing. So the realistic victim was never a fast typist — it was
	 * someone who clicked submit within three seconds of the page rendering
	 * with a half-filled form, and got a fabricated thank-you instead of the
	 * help they needed.
	 *
	 * One second still catches an instant machine submit, and is short enough
	 * that an impatient or accidental click is very unlikely to reach it. It is
	 * also the weakest of the three signals: the honeypot and a signature
	 * mismatch are unambiguous automation, while "fast" is a guess that any bot
	 * defeats with a four-second sleep. Weighted against a lead-capture form on
	 * a customer's public site, silently destroying a real enquiry is the worse
	 * error.
	 */
	public const MIN_AGE = 1;

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
