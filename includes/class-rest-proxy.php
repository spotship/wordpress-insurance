<?php
/**
 * The spotship/v1 proxy routes.
 *
 * THE PIPELINE IS ORDERED, AND THE ORDER IS THE POINT. The rule it keeps:
 *
 *   a flagged client's answer must be the answer the SAME payload would have
 *   received had the client not been flagged — for EVERY refusal this plugin
 *   can decide locally.
 *
 * The anti-bot decoy below is worth having only while that holds. ANY
 * locally-decidable refusal placed BELOW the decoy gates becomes a one-request
 * oracle: a bot flips the flag on one unchanged payload and reads the status
 * line. Read the rule, not the branch list, when adding anything here.
 *
 * Stages, in execution order:
 *   1. binding_failure()  -> 503   flag-independent
 *   2. throttle()         -> 429   flag-independent
 *   3. consent gate       -> 400   LOCAL refusal
 *   4. validate()         -> 400   LOCAL refusal
 *   5. verify() EXPIRED   -> 400   LOCAL refusal
 *   6. honeypot           -> fake 200
 *   7. verify() FAKE      -> fake 200
 *   8. upstream POST
 *
 * WHAT NEVER LEAVES THIS FILE. No upstream body reaches the browser. Search
 * routes hand back allow-listed rows built by class-payloads.php; submit hands
 * back a receipt this plugin owns; a 400's field errors are allow-listed too. A
 * 401 from the API means the customer's key is wrong, revoked or unbound — the
 * visitor sees a neutral "temporarily unavailable" and the administrator sees
 * the truth in diagnostics. Leaking key state to an anonymous form gives an
 * attacker a free oracle for probing which installations hold a live credential.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Public proxy routes.
 */
final class Spotship_WI_Rest_Proxy {

	public const NAMESPACE_V1 = 'spotship/v1';

	private const SEARCH_ENDPOINTS = array(
		'vessel'   => 'insurance/vessel_search',
		'location' => 'insurance/location_search',
	);

	private const SUBMIT_ENDPOINTS = array(
		'hull'  => 'insurance/quote_request',
		'cargo' => 'insurance/cargo_quote_request',
	);

	private const SEARCH_LIMIT  = 30;
	private const SEARCH_WINDOW = 60;
	private const SUBMIT_LIMIT  = 5;
	private const SUBMIT_WINDOW = 3600;
	private const MIN_QUERY_LEN = 2;

	/**
	 * Register routes.
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/rates',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_rates' ),
				'permission_callback' => array( self::class, 'same_origin' ),
			)
		);

		foreach ( array_keys( self::SEARCH_ENDPOINTS ) as $kind ) {
			register_rest_route(
				self::NAMESPACE_V1,
				'/' . $kind . '-search',
				array(
					'methods'             => 'GET',
					'callback'            => static fn( WP_REST_Request $r ) => self::handle_search( $r, $kind ),
					'permission_callback' => array( self::class, 'same_origin' ),
				)
			);
		}

		foreach ( array_keys( self::SUBMIT_ENDPOINTS ) as $product ) {
			register_rest_route(
				self::NAMESPACE_V1,
				'/' . ( 'hull' === $product ? 'quote-request' : 'cargo-quote-request' ),
				array(
					'methods'             => 'POST',
					'callback'            => static fn( WP_REST_Request $r ) => self::handle_submit( $r, $product ),
					'permission_callback' => array( self::class, 'same_origin' ),
				)
			);
		}
	}

	/**
	 * Same-host Origin/Referer only. NEVER `__return_true`.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public static function same_origin( WP_REST_Request $request ): bool {
		$origin = (string) $request->get_header( 'origin' );

		if ( '' === $origin ) {
			$origin = (string) $request->get_header( 'referer' );
		}

		if ( '' === $origin ) {
			return false;
		}

		/*
		 * HOSTS, not normalised URLs. `Origin` is scheme+host, but `Referer` is a
		 * FULL URL including the path — so comparing whole URLs matches on Origin
		 * and never on Referer, which silently disables the fallback this check
		 * depends on for browsers and proxies that strip Origin.
		 */
		$host = Spotship_WI_Settings::host_of( $origin );

		return '' !== $host && $host === Spotship_WI_Settings::current_host();
	}

	/**
	 * GET rates.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 */
	public static function handle_rates( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$serving = Spotship_WI_Rates_Cache::get();

		return self::respond(
			200,
			array(
				'tier'  => $serving['tier'],
				'rates' => $serving['rates'],
			),
			'public, max-age=300'
		);
	}

	/**
	 * GET vessel-search / location-search.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @param string                                $kind    'vessel' or 'location'.
	 */
	public static function handle_search( WP_REST_Request $request, string $kind ): WP_REST_Response {
		$endpoint = self::SEARCH_ENDPOINTS[ $kind ];

		$blocked = self::binding_failure();

		if ( null !== $blocked ) {
			return $blocked;
		}

		$throttled = self::throttle( 'search' );

		if ( null !== $throttled ) {
			return $throttled;
		}

		$query = trim( (string) $request->get_param( 'q' ) );

		if ( mb_strlen( $query ) < self::MIN_QUERY_LEN ) {
			return self::respond( 200, array( 'results' => array() ), 'no-store' );
		}

		/*
		 * The upstream parameter is `query`, NOT `q`. Our own route uses `q`
		 * because that is what the front-end sends; the two are unrelated and
		 * conflating them yields an unfiltered search.
		 */
		$result = Spotship_WI_Api_Client::request( 'GET', $endpoint, array( 'query' => array( 'query' => $query ) ) );

		if ( ! $result['ok'] ) {
			Spotship_WI_Diagnostics::record( $endpoint, $result['status'], $result['code'] );

			return self::respond( 200, array( 'results' => array(), 'unavailable' => true ), 'no-store' );
		}

		$rows = 'vessel' === $kind
			? Spotship_WI_Payloads::trim_vessels( $result['data'] )
			: Spotship_WI_Payloads::trim_locations( $result['data'] );

		return self::respond( 200, array( 'results' => $rows ), 'no-store' );
	}

	/**
	 * POST quote-request / cargo-quote-request.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @param string                                $product 'hull' or 'cargo'.
	 */
	public static function handle_submit( WP_REST_Request $request, string $product ): WP_REST_Response {
		$blocked = self::binding_failure();

		if ( null !== $blocked ) {
			return $blocked;
		}

		$throttled = self::throttle( 'submit' );

		if ( null !== $throttled ) {
			return $throttled;
		}

		$form = self::form_values( $request );

		/*
		 * EVERY LOCAL REFUSAL IS DECIDED HERE, BEFORE THE DECOY GATES.
		 *
		 * See the rule in the class docblock. These three branches — consent,
		 * bounds, expiry — can all be decided without touching the network, so a
		 * flagged and an unflagged client must reach identical answers for an
		 * identical payload. Moving any of them below the gates turns it into a
		 * one-request detection oracle.
		 *
		 * All three are safe to decide first: none performs an upstream call,
		 * writes a transient, or records a ledger row, so none gives an
		 * anonymous caller anything to cause. They also teach a bot nothing —
		 * every bound is already published to the browser by
		 * Spotship_WI_Renderer::config().
		 */

		// 3. Consent. `absent` and `false` are BOTH refusals — see consent_given().
		if ( ! self::consent_given( $form ) ) {
			return self::respond(
				400,
				array(
					'success' => false,
					'code'    => 'consent',
				),
				'no-store'
			);
		}

		// 4. Bounds.
		$fields = self::validate( $product, $form );

		if ( array() !== $fields ) {
			return self::respond(
				400,
				array(
					'success' => false,
					'code'    => 'validation',
					'fields'  => $fields,
				),
				'no-store'
			);
		}

		// 5. Expiry. Computed once here and reused at stage 7.
		$verdict = Spotship_WI_Request_Guard::verify(
			(int) self::scalar( $form['_sswi_ts'] ?? '0' ),
			self::scalar( $form['_sswi_form_id'] ?? '' ),
			self::scalar( $form['_sswi_sig'] ?? '' ),
			time()
		);

		if ( Spotship_WI_Request_Guard::EXPIRED === $verdict ) {
			/*
			 * A REAL human on a page open, or CDN-cached, for more than a day.
			 * They are told plainly to reload, because a cheerful fake 200 would
			 * tell somebody who genuinely wants insurance that their request is
			 * on its way when nothing was sent.
			 *
			 * It is ALSO a local refusal, and it is reachable while flagged — a
			 * valid-but-stale signature is public, baked into whatever HTML a CDN
			 * is still serving, and the honeypot flags independently of it. Below
			 * the gates this branch would answer 200 with the hidden field filled
			 * and 400 with it empty, on one unchanged payload.
			 */
			return self::respond(
				400,
				array(
					'success' => false,
					'code'    => 'expired',
					'message' => __( 'This form has been open too long. Please reload the page and try again.', 'spotship-war-insurance' ),
				),
				'no-store'
			);
		}

		/*
		 * THESE TWO ARE LAST ON PURPOSE. Below this point the only answers left
		 * are the decoy and the genuine upstream one, so nothing that can refuse
		 * a payload locally may be added after them. Anything that could belongs
		 * above.
		 */
		if ( Spotship_WI_Request_Guard::honeypot_tripped( self::scalar( $form['sswi_website'] ?? '' ) ) ) {
			return self::fake_success( $form );
		}

		if ( Spotship_WI_Request_Guard::FAKE_SUCCESS === $verdict ) {
			return self::fake_success( $form );
		}

		$payload = 'cargo' === $product
			? Spotship_WI_Payloads::map_cargo( $form, self::context() )
			: Spotship_WI_Payloads::map_hull( $form, self::context() );

		$result = Spotship_WI_Api_Client::request( 'POST', self::SUBMIT_ENDPOINTS[ $product ], array( 'body' => $payload ) );

		return self::shape_submit_result( $product, $result, $form );
	}

	/**
	 * Whether consent was given.
	 *
	 * An ABSENT key is a refusal, not a default. A gate that reads
	 * `! empty( $form['dataConsent'] )` looks identical but is bypassable by
	 * simply omitting the field, and a fixture that always sets the key cannot
	 * tell the two apart — which is how 161 green tests once sat over exactly
	 * this hole.
	 *
	 * @param array<string, mixed> $form Submitted values.
	 */
	private static function consent_given( array $form ): bool {
		if ( ! array_key_exists( 'dataConsent', $form ) ) {
			return false;
		}

		$value = $form['dataConsent'];

		return true === $value || '1' === $value || 1 === $value || 'true' === $value;
	}

	/**
	 * Bounds validation. Pure: no upstream call, no transient, no ledger row.
	 *
	 * Deliberately NOT a requiredness check — empty values pass, because
	 * requiredness is the backend's call and duplicating its schema here is a
	 * second definition to drift.
	 *
	 * @param string               $product 'hull' or 'cargo'.
	 * @param array<string, mixed> $form    Submitted values.
	 * @return array<string, array<int, string>>
	 */
	private static function validate( string $product, array $form ): array {
		$bounds = array(
			'userName'       => Spotship_WI_Payloads::MAX_NAME,
			'userEmail'      => Spotship_WI_Payloads::MAX_EMAIL,
			'userPhone'      => Spotship_WI_Payloads::MAX_PHONE,
			'notes'          => Spotship_WI_Payloads::MAX_FREE_TEXT,
			'handmValue'     => Spotship_WI_Payloads::MAX_HANDM_VALUE,
			'increasedValue' => Spotship_WI_Payloads::MAX_INCREASED_VALUE,
		);

		if ( 'cargo' === $product ) {
			$bounds = array_merge(
				$bounds,
				array(
					'cargoDescription' => Spotship_WI_Payloads::MAX_FREE_TEXT,
					'cargoWeight'      => Spotship_WI_Payloads::MAX_CARGO_WEIGHT,
					'cargoCurrency'    => Spotship_WI_Payloads::MAX_CURRENCY,
					'riskZone'         => Spotship_WI_Payloads::MAX_RISK_ZONE,
					'insuredParty'     => Spotship_WI_Payloads::MAX_INSURED_PARTY,
				)
			);
		}

		$errors = array();

		foreach ( $bounds as $field => $max ) {
			$value = self::scalar( $form[ $field ] ?? '' );

			if ( ! Spotship_WI_Payloads::within( $value, $max ) ) {
				$errors[ $field ] = array(
					sprintf(
						/* translators: %d: maximum number of characters. */
						__( 'Please use %d characters or fewer.', 'spotship-war-insurance' ),
						$max
					),
				);
			}
		}

		if ( 'cargo' === $product && ! Spotship_WI_Payloads::destinations_ok( $form['destinations'] ?? array() ) ) {
			$errors['destinations'] = array(
				sprintf(
					/* translators: %d: maximum number of destinations. */
					__( 'Choose between one and %d destinations.', 'spotship-war-insurance' ),
					Spotship_WI_Payloads::MAX_DESTINATIONS
				),
			);
		}

		return $errors;
	}

	/**
	 * Turn a normalised upstream result into the visitor's answer.
	 *
	 * @param string                                                                                   $product 'hull' or 'cargo'.
	 * @param array{ok: bool, status: int, data: array<array-key, mixed>, code: string, retry_after: int|null} $result  Normalised result.
	 * @param array<string, mixed>                                                                     $form    Submitted values.
	 */
	private static function shape_submit_result( string $product, array $result, array $form ): WP_REST_Response {
		$endpoint = self::SUBMIT_ENDPOINTS[ $product ];
		$status   = (int) $result['status'];
		$code     = (string) $result['code'];

		if ( true === $result['ok'] ) {
			/*
			 * 201 is a new referral, 200 an idempotent replay of one already
			 * held. BOTH mean the backend has the request. Reporting a 200 as an
			 * error would tell a visitor whose submit was retried that it failed,
			 * and they would send it again.
			 *
			 * THE UPSTREAM BODY IS NOT RELAYED — see receipt().
			 */
			return self::respond(
				$status,
				array(
					'success' => true,
					'message' => self::success_sentence(),
					'data'    => self::receipt( $form ),
				),
				'no-store'
			);
		}

		Spotship_WI_Diagnostics::record( $endpoint, $status, $code );

		if ( Spotship_WI_Api_Client::VALIDATION === $code ) {
			return self::respond(
				400,
				array(
					'success' => false,
					'code'    => 'validation',
					'fields'  => Spotship_WI_Payloads::trim_field_errors( $result['data'], $product ),
				),
				'no-store'
			);
		}

		if ( Spotship_WI_Api_Client::THROTTLED === $code ) {
			$retry = is_int( $result['retry_after'] ) ? $result['retry_after'] : 60;

			return self::respond(
				429,
				array(
					'success' => false,
					'code'    => 'throttled',
					'retry'   => $retry,
				),
				'no-store',
				array( 'Retry-After' => (string) $retry )
			);
		}

		/*
		 * Everything else, INCLUDING 401, is one neutral answer. Neither the
		 * status, nor the upstream's prose, nor the word "key" may appear here.
		 */
		return self::respond(
			502,
			array(
				'success' => false,
				'code'    => 'unavailable',
				'message' => self::unavailable_sentence(),
			),
			'no-store'
		);
	}

	/**
	 * The cheerful 200 shown to a bot.
	 *
	 * Identical to a real success, not merely similar: the body is built by the
	 * same receipt() call from the same submitted form. Nothing is sent
	 * upstream and nothing is recorded.
	 *
	 * Residuals this does NOT cover, written down rather than left to be
	 * rediscovered:
	 *   1. A payload that is locally valid but upstream-invalid diverges. This is
	 *      WIDER than it looks: validate() is a bounds check that skips empty
	 *      values, so an empty hull form reaches it with no attacker knowledge.
	 *      Irreducible — closing it needs the decoy to call upstream, destroying
	 *      its purpose, or a private copy of the backend's schema here.
	 *   2. Response timing: the decoy returns with no round trip.
	 *   3. The 201-vs-200 status line: a decoy is always 200, a first genuine
	 *      acceptance is 201. AC 24 mandates the 200; it does not adjudicate
	 *      detectability.
	 *   4. A bound-but-unconfigured install answers 502 unflagged and 200
	 *      flagged. Settings::sanitize() refuses to create that state, so this is
	 *      reachable only by editing the option directly.
	 *
	 * What the decoy still buys, so it is not read as "undetectable": no flagged
	 * submission ever becomes a referral or an attacker-paced ledger row, and no
	 * LOCAL refusal is readable through it.
	 *
	 * @param array<string, mixed> $form Submitted values.
	 */
	private static function fake_success( array $form ): WP_REST_Response {
		return self::respond(
			200,
			array(
				'success' => true,
				'message' => self::success_sentence(),
				'data'    => self::receipt( $form ),
			),
			'no-store'
		);
	}

	/**
	 * The success body, owned by this plugin.
	 *
	 * Built from what the caller submitted, never from the upstream response, so
	 * the genuine and decoy paths cannot diverge — there is only one shape and
	 * neither is derived from anything the other lacks.
	 *
	 * Invents nothing. `submissionId` is the caller's own UUID, so a human who
	 * trips the honeypot by accident and quotes it gets an honest "no such
	 * submission" rather than a fabricated reference. No `id` is minted; the
	 * serializer exposes none.
	 *
	 * @param array<string, mixed> $form Submitted values.
	 * @return array<string, string>
	 */
	private static function receipt( array $form ): array {
		$submission_id = Spotship_WI_Payloads::submission_id( $form );

		return array(
			'submissionId' => '' !== $submission_id ? $submission_id : wp_generate_uuid4(),
			'status'       => 'received',
		);
	}

	/**
	 * 503 when the install is unbound, misbound or unconfigured.
	 */
	private static function binding_failure(): ?WP_REST_Response {
		if ( ! Spotship_WI_Settings::is_configured() || Spotship_WI_Settings::binding_mismatch() ) {
			Spotship_WI_Diagnostics::record_state( Spotship_WI_Diagnostics::STATE_UNBOUND );

			return self::respond(
				503,
				array(
					'success' => false,
					'code'    => 'unavailable',
					'message' => self::unavailable_sentence(),
				),
				'no-store'
			);
		}

		return null;
	}

	/**
	 * 429 when the per-IP bucket is exhausted.
	 *
	 * @param string $bucket 'search' or 'submit'.
	 */
	private static function throttle( string $bucket ): ?WP_REST_Response {
		$limit  = 'submit' === $bucket ? self::SUBMIT_LIMIT : self::SEARCH_LIMIT;
		$window = 'submit' === $bucket ? self::SUBMIT_WINDOW : self::SEARCH_WINDOW;

		$verdict = Spotship_WI_Rate_Limiter::consume( $bucket, $limit, $window );

		if ( $verdict['allowed'] ) {
			return null;
		}

		return self::respond(
			429,
			array(
				'success' => false,
				'code'    => 'throttled',
				'retry'   => $verdict['retry_after'],
			),
			'no-store',
			array( 'Retry-After' => (string) $verdict['retry_after'] )
		);
	}

	/**
	 * Consent and origin provenance for this request.
	 *
	 * @return array<string, string>
	 */
	private static function context(): array {
		return array(
			'consent_text'       => Spotship_WI_Renderer::consent_text(),
			'consent_given_at'   => gmdate( 'c' ),
			'privacy_notice_url' => (string) Spotship_WI_Settings::get( 'privacy_notice_url', '' ),
			'origin_site_url'    => home_url(),
		);
	}

	/**
	 * Flatten submitted values.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array<string, mixed>
	 */
	private static function form_values( WP_REST_Request $request ): array {
		$params = $request->get_json_params();

		return is_array( $params ) ? $params : array();
	}

	/**
	 * Coerce a submitted value to a string.
	 *
	 * @param mixed $value Candidate.
	 */
	private static function scalar( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The sentence shown on success.
	 */
	private static function success_sentence(): string {
		return __( 'Thank you — your request has been sent to our insurance desk.', 'spotship-war-insurance' );
	}

	/**
	 * The sentence shown when we cannot serve the request.
	 */
	private static function unavailable_sentence(): string {
		$email = (string) Spotship_WI_Settings::get( 'support_email', '' );

		if ( '' !== $email ) {
			return sprintf(
				/* translators: %s: support email address. */
				__( 'This form is temporarily unavailable. Please contact %s.', 'spotship-war-insurance' ),
				$email
			);
		}

		return __( 'This form is temporarily unavailable. Please try again shortly.', 'spotship-war-insurance' );
	}

	/**
	 * Build a response.
	 *
	 * @param int                   $status  HTTP status.
	 * @param array<string, mixed>  $body    Response body.
	 * @param string                $cache   Cache-Control value.
	 * @param array<string, string> $headers Extra headers.
	 */
	private static function respond( int $status, array $body, string $cache, array $headers = array() ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );

		$response->header( 'Cache-Control', $cache );

		foreach ( $headers as $name => $value ) {
			$response->header( $name, $value );
		}

		return $response;
	}
}
