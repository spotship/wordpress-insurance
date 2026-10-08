<?php
/**
 * Payload mapping and response trimming.
 *
 * THE TRIMMERS ARE AN ALLOW-LIST, NOT A BLOCKLIST, AND THAT IS A SECURITY
 * CONTROL rather than a tidiness preference.
 *
 * Upstream documents are richer than anything a public form needs. VesselDocument
 * carries imo, mmsi, flag, dwt and the commercial manager's name; PortDocument
 * carries lat/long, berth counts, importance scores and internal region ids.
 * Every route that hands upstream data to an anonymous browser passes it through
 * a function here that names the keys it emits.
 *
 * Stated as a rule because a blocklist fails open: a field added upstream would
 * begin streaming to visitors on deploy, with no code change here and nothing
 * for a reviewer to notice. ENG-5586 demonstrated exactly that hazard live — it
 * added six provenance fields to the quote serializer, and a relay would have
 * started echoing consentText, privacyNoticeUrl and originSiteUrl to anonymous
 * browsers the day it merged.
 *
 * SUBMIT IS NOT EXEMPT. Neither a success body nor a 400's field errors may
 * relay an upstream document unexamined.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Request payload construction and response trimming.
 */
final class Spotship_WI_Payloads {

	/**
	 * Where a submission came from. The backend records this per row.
	 */
	public const SOURCE = 'wordpress';

	/**
	 * Field bounds, mirrored to the browser by `Spotship_WI_Renderer::config()`.
	 *
	 * These are checked server-side regardless of what the browser does. They
	 * exist to reject obvious abuse cheaply and to give a visitor an inline
	 * message, not to replace upstream validation.
	 */
	public const MAX_NAME            = 255;
	public const MAX_EMAIL           = 254;
	public const MAX_PHONE           = 20;
	public const MAX_FREE_TEXT       = 5000;
	public const MAX_HANDM_VALUE     = 40;
	public const MAX_INCREASED_VALUE = 40;
	public const MAX_CARGO_WEIGHT    = 100;
	public const MAX_CURRENCY        = 3;
	public const MAX_RISK_ZONE       = 100;
	public const MAX_INSURED_PARTY   = 255;

	/**
	 * Destination bounds for cargo.
	 *
	 * MAX_DESTINATIONS is 50 and is NOT derivable from the upstream schema — it
	 * does not appear in schema.yml. It is enforced here because an unbounded
	 * ordered list is an easy way to make the backend do arbitrary work on an
	 * anonymous request.
	 */
	public const MIN_DESTINATIONS = 1;
	public const MAX_DESTINATIONS = 50;

	/**
	 * A v4 UUID, minted by the browser for idempotency.
	 */
	private const SUBMISSION_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

	/**
	 * A decimal amount. Sent as a STRING deliberately — the backend's
	 * DecimalField accepts it and JSON floats lose precision on money.
	 */
	private const CARGO_VALUE_PATTERN = '/^\d{1,16}(\.\d{1,2})?$/';

	/**
	 * A populated probe used only to ENUMERATE mapper keys.
	 *
	 * `add_provenance()` appends `submissionId` conditionally, so harvesting keys
	 * from an EMPTY form silently omits it — and a test that harvests the same
	 * way cannot notice, because it asserts the derivation against itself. The
	 * probe carries a valid value for every conditional key so the enumeration
	 * is complete and the exclusions below are decisions rather than accidents.
	 */
	private const KEY_PROBE = array(
		'submissionId' => '00000000-0000-4000-8000-000000000000',
	);

	/**
	 * Client-supplied keys that are nonetheless NEVER relayed back.
	 *
	 * `submissionId` is minted by the browser for idempotency. No visitor typed
	 * it, there is no control to attach a message to, and nothing they could fix
	 * if there were. The plugin forwards it only after it has matched
	 * SUBMISSION_ID_PATTERN, so upstream cannot reject its format; and a
	 * collision is not a 400 at all but a 200 idempotent replay. An upstream
	 * error keyed `submissionId` could therefore only mean the contract moved
	 * under us, which is precisely the case the allow-list exists to contain.
	 */
	private const NEVER_RELAYED = array( 'submissionId' );

	/**
	 * Map a hull form into the upstream payload.
	 *
	 * @param array<string, mixed> $form    Submitted values.
	 * @param array<string, mixed> $context Provenance context.
	 * @return array<string, mixed>
	 */
	public static function map_hull( array $form, array $context ): array {
		$payload = array(
			'userName'       => self::text( $form, 'userName' ),
			'userEmail'      => self::text( $form, 'userEmail' ),
			'userPhone'      => self::text( $form, 'userPhone' ),
			'vessel'         => self::vessel( $form ),
			'handmValue'     => self::text( $form, 'handmValue' ),
			'increasedValue' => self::text( $form, 'increasedValue' ),
			'fromPort'       => self::id( $form, 'fromPort' ),
			'toPort'         => self::id( $form, 'toPort' ),
			'notes'          => self::text( $form, 'notes' ),
		);

		return self::add_provenance( $payload, $form, $context );
	}

	/**
	 * Map a cargo form into the upstream payload.
	 *
	 * @param array<string, mixed> $form    Submitted values.
	 * @param array<string, mixed> $context Provenance context.
	 * @return array<string, mixed>
	 */
	public static function map_cargo( array $form, array $context ): array {
		$payload = array(
			'userName'         => self::text( $form, 'userName' ),
			'userEmail'        => self::text( $form, 'userEmail' ),
			'userPhone'        => self::text( $form, 'userPhone' ),
			'vessel'           => self::vessel( $form ),
			'cargoDescription' => self::text( $form, 'cargoDescription' ),
			'cargoWeight'      => self::text( $form, 'cargoWeight' ),
			'cargoValue'       => self::decimal( $form['cargoValue'] ?? '' ),
			'cargoCurrency'    => self::text( $form, 'cargoCurrency' ),
			'riskZone'         => self::text( $form, 'riskZone' ),
			'insuredParty'     => self::text( $form, 'insuredParty' ),
			'fromPort'         => self::id( $form, 'fromPort' ),
			'destinations'     => self::ids( $form['destinations'] ?? array() ),
			'notes'            => self::text( $form, 'notes' ),
		);

		return self::add_provenance( $payload, $form, $context );
	}

	/**
	 * Consent and origin provenance, identical on both products.
	 *
	 * `dataConsent` is hardcoded true because the proxy refuses the submission
	 * outright when consent was not given — see the consent gate. It is never
	 * read from the form here, so a form that omits the key cannot smuggle a
	 * consenting payload past this layer.
	 *
	 * @param array<string, mixed> $context Provenance context.
	 * @return array<string, mixed>
	 */
	private static function provenance( array $context ): array {
		return array(
			'dataConsent'      => true,
			'source'           => self::SOURCE,
			'consentText'      => self::as_string( $context['consent_text'] ?? '' ),
			'consentGivenAt'   => self::as_string( $context['consent_given_at'] ?? '' ),
			'privacyNoticeUrl' => self::as_string( $context['privacy_notice_url'] ?? '' ),
			'originSiteUrl'    => self::as_string( $context['origin_site_url'] ?? '' ),
		);
	}

	/**
	 * Merge provenance and the optional idempotency key.
	 *
	 * @param array<string, mixed> $payload Mapped form payload.
	 * @param array<string, mixed> $form    Submitted values.
	 * @param array<string, mixed> $context Provenance context.
	 * @return array<string, mixed>
	 */
	private static function add_provenance( array $payload, array $form, array $context ): array {
		$payload = array_merge( $payload, self::provenance( $context ) );

		$submission_id = self::submission_id( $form );

		/*
		 * Absent rather than empty: the column is a UUIDField, and DRF reads a
		 * missing optional key as "not supplied" but an empty string as invalid.
		 * A visitor whose browser mangled the key still gets their quote through;
		 * they merely lose de-duplication on a double submit.
		 */
		if ( '' !== $submission_id ) {
			$payload['submissionId'] = $submission_id;
		}

		return $payload;
	}

	/**
	 * The browser-minted idempotency key, or '' when absent or malformed.
	 *
	 * @param array<string, mixed> $form Submitted values.
	 */
	public static function submission_id( array $form ): string {
		$candidate = trim( self::as_string( $form['submissionId'] ?? '' ) );

		return 1 === preg_match( self::SUBMISSION_ID_PATTERN, $candidate ) ? strtolower( $candidate ) : '';
	}

	/**
	 * Trim a vessel search result to what the form renders.
	 *
	 * @param mixed $raw Upstream payload.
	 * @return array<int, array<string, mixed>>
	 */
	public static function trim_vessels( $raw ): array {
		return self::trim_rows( $raw, array( 'id', 'name', 'imo', 'type' ) );
	}

	/**
	 * Trim a location search result to what the form renders.
	 *
	 * @param mixed $raw Upstream payload.
	 * @return array<int, array<string, mixed>>
	 */
	public static function trim_locations( $raw ): array {
		return self::trim_rows( $raw, array( 'id', 'name', 'country', 'locode' ) );
	}

	/**
	 * Allow-list upstream field errors before a browser sees them.
	 *
	 * The key list is DERIVED from the mapper — the client-supplied half of the
	 * payload we just sent — so it cannot drift from what we actually submit and
	 * can only widen if somebody adds a key to a mapper deliberately. Server-owned
	 * keys and unknown keys are dropped; values are flattened to a list of
	 * strings so a nested serializer object cannot travel.
	 *
	 * Dropping everything yields an empty array, which the front-end already
	 * handles by falling back to its neutral sentence.
	 *
	 * @param mixed  $raw     Upstream 400 body.
	 * @param string $product 'hull' or 'cargo'.
	 * @return array<string, array<int, string>>
	 */
	public static function trim_field_errors( $raw, string $product ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$errors = array();

		foreach ( self::client_keys( $product ) as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}

			$messages = self::messages( $raw[ $key ] );

			if ( array() !== $messages ) {
				$errors[ $key ] = $messages;
			}
		}

		return $errors;
	}

	/**
	 * The client-supplied keys for a product, derived from its mapper.
	 *
	 * Probed with KEY_PROBE rather than an empty form so conditionally-added
	 * keys are enumerated, then reduced by the two explicit exclusion lists.
	 *
	 * @param string $product 'hull' or 'cargo'.
	 * @return array<int, string>
	 */
	public static function client_keys( string $product ): array {
		$payload = 'cargo' === $product
			? self::map_cargo( self::KEY_PROBE, array() )
			: self::map_hull( self::KEY_PROBE, array() );

		$excluded = array_merge( array_keys( self::provenance( array() ) ), self::NEVER_RELAYED );

		return array_values( array_diff( array_keys( $payload ), $excluded ) );
	}

	/**
	 * Reduce upstream rows to an allow-listed set of keys.
	 *
	 * @param mixed             $raw     Upstream payload.
	 * @param array<int,string> $allowed Keys to emit.
	 * @return array<int, array<string, mixed>>
	 */
	private static function trim_rows( $raw, array $allowed ): array {
		$rows = is_array( $raw ) && isset( $raw['results'] ) && is_array( $raw['results'] ) ? $raw['results'] : $raw;

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$trimmed = array();

			foreach ( $allowed as $key ) {
				if ( array_key_exists( $key, $row ) && ( is_scalar( $row[ $key ] ) || null === $row[ $key ] ) ) {
					$trimmed[ $key ] = $row[ $key ];
				}
			}

			if ( array() !== $trimmed ) {
				$out[] = $trimmed;
			}
		}

		return $out;
	}

	/**
	 * Flatten an error value into a list of strings.
	 *
	 * Anything non-scalar collapses to nothing, so a nested list-serializer
	 * object cannot reach the browser through this path.
	 *
	 * @param mixed $value Upstream error value.
	 * @return array<int, string>
	 */
	private static function messages( $value ): array {
		$messages = array();
		$items    = is_array( $value ) ? $value : array( $value );

		foreach ( $items as $item ) {
			$text = trim( self::as_string( $item ) );

			if ( '' !== $text ) {
				$messages[] = $text;
			}
		}

		return $messages;
	}

	/**
	 * Whether a value is within a length bound. Empty passes — requiredness is
	 * upstream's call, not ours.
	 *
	 * @param string $value Candidate.
	 * @param int    $max   Maximum length.
	 */
	public static function within( string $value, int $max ): bool {
		return '' === $value || mb_strlen( $value ) <= $max;
	}

	/**
	 * Whether a destination list is an acceptable size.
	 *
	 * @param mixed $value Candidate list.
	 */
	public static function destinations_ok( $value ): bool {
		$ids = self::ids( $value );

		return count( $ids ) >= self::MIN_DESTINATIONS && count( $ids ) <= self::MAX_DESTINATIONS;
	}

	/**
	 * A trimmed string field.
	 *
	 * @param array<string, mixed> $form Submitted values.
	 * @param string               $key  Field name.
	 */
	private static function text( array $form, string $key ): string {
		return trim( self::as_string( $form[ $key ] ?? '' ) );
	}

	/**
	 * A positive integer id, or 0.
	 *
	 * @param array<string, mixed> $form Submitted values.
	 * @param string               $key  Field name.
	 */
	private static function id( array $form, string $key ): int {
		$value = self::as_string( $form[ $key ] ?? '' );

		return ctype_digit( $value ) && (int) $value > 0 ? (int) $value : 0;
	}

	/**
	 * A list of positive integer ids, order preserved, duplicates removed.
	 *
	 * @param mixed $value Candidate list.
	 * @return array<int, int>
	 */
	private static function ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();

		foreach ( $value as $item ) {
			$text = self::as_string( $item );

			if ( ctype_digit( $text ) && (int) $text > 0 && ! in_array( (int) $text, $ids, true ) ) {
				$ids[] = (int) $text;
			}
		}

		return $ids;
	}

	/**
	 * The vessel field, null when absent — it is optional on cargo.
	 *
	 * @param array<string, mixed> $form Submitted values.
	 */
	private static function vessel( array $form ): ?string {
		$vessel = self::text( $form, 'vessel' );

		return '' === $vessel ? null : $vessel;
	}

	/**
	 * A decimal amount as a STRING, or '' when malformed.
	 *
	 * Deliberately a string: JSON floats lose precision on money, and the
	 * backend's DecimalField accepts the string form.
	 *
	 * @param mixed $value Candidate.
	 */
	private static function decimal( $value ): string {
		$candidate = trim( self::as_string( $value ) );

		return 1 === preg_match( self::CARGO_VALUE_PATTERN, $candidate ) ? $candidate : '';
	}

	/**
	 * Total string coercion. Arrays, objects and null become ''.
	 *
	 * @param mixed $value Candidate.
	 */
	private static function as_string( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}

		return '';
	}
}
