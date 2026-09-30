<?php
/**
 * REST proxy behaviour against a real WordPress bootstrap.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

/**
 * @covers Spotship_WI_Rest_Proxy
 */
final class RestProxyTest extends WP_UnitTestCase {

	/** @var array<int, array<string, mixed>> */
	private array $upstream = array();

	public function set_up(): void {
		parent::set_up();

		update_option(
			Spotship_WI_Settings::OPTION,
			array(
				'api_base_url'   => 'https://api.example.test',
				'api_key'        => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
				'bound_site_url' => Spotship_WI_Settings::current_site_url(),
			)
		);

		do_action( 'rest_api_init' );

		$this->upstream = array();

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		parent::tear_down();
	}

	/**
	 * Record and answer upstream calls. Default is a genuine 201.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 * @return array<string, mixed>
	 */
	public function intercept( $pre, $args, $url ) {
		unset( $pre );

		$this->upstream[] = array( 'url' => $url, 'args' => $args );

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $this->genuine_201() ),
			'response' => array( 'code' => 201, 'message' => 'Created' ),
		);
	}

	/**
	 * A genuine acceptance, as the backend really shapes it.
	 *
	 * Sixteen camelCase keys on hull, containing NEITHER `id` NOR `status`.
	 * Hand-writing a different shape here is how a previous implementation built
	 * an anti-bot decoy that imitated a response the API never emits.
	 *
	 * @return array<string, mixed>
	 */
	private function genuine_201(): array {
		return array(
			'userName'         => 'A Person',
			'userEmail'        => 'a@example.test',
			'userPhone'        => '',
			'vessel'           => null,
			'handmValue'       => '',
			'increasedValue'   => '',
			'fromPort'         => 1,
			'toPort'           => 2,
			'notes'            => '',
			'dataConsent'      => true,
			'source'           => 'wordpress',
			'submissionId'     => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
			'consentGivenAt'   => '2026-09-30T00:00:00Z',
			'consentText'      => 'Consent text',
			'privacyNoticeUrl' => 'https://example.test/privacy/',
			'originSiteUrl'    => 'https://example.test',
		);
	}

	/**
	 * Dispatch a submit.
	 *
	 * @param string               $product 'hull' or 'cargo'.
	 * @param array<string, mixed> $body    Payload.
	 * @return WP_REST_Response
	 */
	private function submit( string $product, array $body ): WP_REST_Response {
		// The submit bucket is 5/hour. Without this reset a table of rows
		// exhausts it partway through and every later row PASSES, because a 429
		// looks identical whatever the flag state — the table would silently
		// become a test of the rate limiter.
		Spotship_WI_Rate_Limiter::reset( 'submit' );

		$route = 'hull' === $product ? '/spotship/v1/quote-request' : '/spotship/v1/cargo-quote-request';

		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'origin', home_url() );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A payload that would otherwise be accepted.
	 *
	 * @return array<string, mixed>
	 */
	private function good( string $product ): array {
		$base = array(
			'userName'     => 'A Person',
			'userEmail'    => 'a@example.test',
			'dataConsent'  => true,
			'submissionId' => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
			'_sswi_ts'     => time() - 10,
			'_sswi_form_id' => 'form-1',
		);

		$base['_sswi_sig'] = Spotship_WI_Request_Guard::sign( $base['_sswi_ts'], 'form-1' );

		if ( 'cargo' === $product ) {
			$base['destinations'] = array( 1 );
		}

		return $base;
	}

	/**
	 * THE PARITY TABLE.
	 *
	 * The invariant: a flagged client's answer must be the answer the SAME
	 * payload would have received had the client not been flagged, for every
	 * refusal the plugin decides locally.
	 *
	 * Each row runs TWICE — honeypot empty and honeypot filled — and both status
	 * and body must be identical. Adding a row is how a new local refusal gets
	 * covered; a refusal added below the decoy gates fails here.
	 *
	 * The `residual` rows are the anti-vacuity guard: a table of parity rows
	 * alone would still pass if unrelated breakage made every answer identical.
	 * They assert the paths that legitimately DO differ.
	 */
	public function test_a_flagged_client_is_answered_identically_on_every_local_refusal(): void {
		$rows = array(
			array( 'hull / over-long userName', 'hull', array( 'userName' => str_repeat( 'a', 300 ) ), 'local', 400 ),
			array( 'hull / over-long notes', 'hull', array( 'notes' => str_repeat( 'a', 6000 ) ), 'local', 400 ),
			array( 'hull / absent consent', 'hull', array( '__unset' => 'dataConsent' ), 'local', 400 ),
			array( 'hull / consent false', 'hull', array( 'dataConsent' => false ), 'local', 400 ),
			array( 'hull / expired token', 'hull', array( '__expired' => true ), 'local', 400 ),
			array( 'cargo / over-long riskZone', 'cargo', array( 'riskZone' => str_repeat( 'z', 200 ) ), 'local', 400 ),
			array( 'cargo / over-long cargoCurrency', 'cargo', array( 'cargoCurrency' => 'POUNDS' ), 'local', 400 ),
			array( 'cargo / empty destinations', 'cargo', array( 'destinations' => array() ), 'local', 400 ),
			array( 'cargo / too many destinations', 'cargo', array( 'destinations' => range( 1, 60 ) ), 'local', 400 ),
			array( 'cargo / expired token', 'cargo', array( '__expired' => true ), 'local', 400 ),
			array( 'hull / well formed', 'hull', array(), 'residual', 201 ),
			array( 'cargo / well formed', 'cargo', array(), 'residual', 201 ),
		);

		foreach ( $rows as $row ) {
			list( $label, $product, $overrides, $class, $expected ) = $row;

			$answers = array();

			foreach ( array( false, true ) as $flagged ) {
				$payload = $this->good( $product );

				if ( isset( $overrides['__unset'] ) ) {
					unset( $payload[ $overrides['__unset'] ] );
				} elseif ( isset( $overrides['__expired'] ) ) {
					$payload['_sswi_ts']  = time() - ( Spotship_WI_Request_Guard::MAX_AGE + 60 );
					$payload['_sswi_sig'] = Spotship_WI_Request_Guard::sign( $payload['_sswi_ts'], 'form-1' );
				} else {
					$payload = array_merge( $payload, $overrides );
				}

				if ( $flagged ) {
					$payload['sswi_website'] = 'a bot filled this';
				}

				$this->upstream = array();

				$response = $this->submit( $product, $payload );

				$answers[] = array(
					'status' => $response->get_status(),
					'body'   => $response->get_data(),
					'sent'   => count( $this->upstream ),
				);
			}

			if ( 'local' === $class ) {
				$this->assertSame(
					$answers[0],
					$answers[1],
					$this->parity_failure( $label, $answers )
				);

				$this->assertSame( $expected, $answers[0]['status'], "row \"{$label}\" expected HTTP {$expected}" );
				$this->assertSame( 0, $answers[0]['sent'], "row \"{$label}\" is a LOCAL refusal and must send nothing upstream" );
				continue;
			}

			// residual: the unflagged path reaches upstream, the flagged one does not.
			$this->assertSame( $expected, $answers[0]['status'], "row \"{$label}\" expected HTTP {$expected} unflagged" );
			$this->assertSame( 1, $answers[0]['sent'], "row \"{$label}\" should have reached upstream unflagged" );
			$this->assertSame( 0, $answers[1]['sent'], "row \"{$label}\" must send nothing upstream when flagged" );
			$this->assertSame( 200, $answers[1]['status'], "row \"{$label}\" flagged answer is the decoy 200" );
		}
	}

	/**
	 * @param string                        $label   Row label.
	 * @param array<int, array<string, mixed>> $answers Both answers.
	 */
	private function parity_failure( string $label, array $answers ): string {
		return sprintf(
			"ROW \"%s\" DIVERGES. A bot flips the honeypot on this one unchanged payload and reads the difference:\n"
			. "    honeypot empty  -> HTTP %d %s\n"
			. "    honeypot filled -> HTTP %d %s\n"
			. "Either the refusal belongs ABOVE the decoy gates in handle_submit(), or this payload is not a\n"
			. 'local refusal and the row belongs in the table as `residual`.',
			$label,
			$answers[0]['status'],
			(string) wp_json_encode( $answers[0]['body'] ),
			$answers[1]['status'],
			(string) wp_json_encode( $answers[1]['body'] )
		);
	}

	/**
	 * An ABSENT key is a refusal, not a default. A gate reading `! empty(...)`
	 * passes this test only if the key is always present — which is how 161
	 * green tests once sat over exactly this hole.
	 */
	public function test_consent_is_refused_when_the_key_is_absent_entirely(): void {
		$payload = $this->good( 'hull' );
		unset( $payload['dataConsent'] );

		$response = $this->submit( 'hull', $payload );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'consent', $response->get_data()['code'] );
		$this->assertCount( 0, $this->upstream, 'a refused consent must never reach upstream' );
	}

	public function test_a_genuine_acceptance_does_not_relay_the_upstream_body(): void {
		$response = $this->submit( 'hull', $this->good( 'hull' ) );
		$body     = $response->get_data();

		$this->assertTrue( $body['success'] );
		$this->assertSame( array( 'submissionId', 'status' ), array_keys( $body['data'] ) );

		// A value only the upstream sent must not appear anywhere in the answer.
		$this->assertStringNotContainsString(
			'https://example.test/privacy/',
			(string) wp_json_encode( $body ),
			'the upstream body must not reach the browser'
		);
	}

	public function test_the_decoy_and_a_genuine_acceptance_share_one_body_shape(): void {
		$genuine = $this->submit( 'hull', $this->good( 'hull' ) )->get_data();

		$flagged                 = $this->good( 'hull' );
		$flagged['sswi_website'] = 'bot';

		$decoy = $this->submit( 'hull', $flagged )->get_data();

		$this->assertSame( array_keys( $genuine ), array_keys( $decoy ) );
		$this->assertSame( array_keys( $genuine['data'] ), array_keys( $decoy['data'] ) );
		$this->assertSame( $genuine['data']['submissionId'], $decoy['data']['submissionId'] );
	}

	public function test_an_unauthorised_upstream_never_leaks_credential_state(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array(),
				'body'     => (string) wp_json_encode( array( 'detail' => 'Invalid API key' ) ),
				'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
			),
			10
		);

		$response = $this->submit( 'hull', $this->good( 'hull' ) );
		$encoded  = (string) wp_json_encode( $response->get_data() );

		$this->assertSame( 502, $response->get_status() );
		$this->assertStringNotContainsString( 'key', strtolower( $encoded ) );
		$this->assertStringNotContainsString( 'Invalid API key', $encoded );
		$this->assertStringNotContainsString( '401', $encoded );
	}

	/**
	 * `add_query_arg()` encodes its own values. Pre-encoding produces a double
	 * encoding, so a search for "New York" leaves as `New%2520York`, the backend
	 * searches for the literal string "New%20York", and the visitor gets an
	 * empty autocomplete for a location that plainly exists.
	 *
	 * It fails silently and ONLY for terms containing a space or a special
	 * character — the shape of bug that survives every smoke test.
	 */
	public function test_a_search_term_with_a_space_is_encoded_exactly_once(): void {
		Spotship_WI_Rate_Limiter::reset( 'search' );

		$request = new WP_REST_Request( 'GET', '/spotship/v1/location-search' );
		$request->set_header( 'origin', home_url() );
		$request->set_param( 'q', 'New York' );

		rest_get_server()->dispatch( $request );

		$this->assertCount( 1, $this->upstream );

		$url = $this->upstream[0]['url'];

		$this->assertStringContainsString( 'query=New%20York', $url );
		$this->assertStringNotContainsString( '%2520', $url );
	}

	/**
	 * The upstream parameter is `query`, not `q`. Our own route uses `q` because
	 * that is what the front-end sends; conflating them yields an UNFILTERED
	 * search, which returns the whole index to an anonymous caller.
	 */
	public function test_the_upstream_search_parameter_is_query_not_q(): void {
		Spotship_WI_Rate_Limiter::reset( 'search' );

		$request = new WP_REST_Request( 'GET', '/spotship/v1/vessel-search' );
		$request->set_header( 'origin', home_url() );
		$request->set_param( 'q', 'ever' );

		rest_get_server()->dispatch( $request );

		$url = $this->upstream[0]['url'];

		$this->assertStringContainsString( 'query=ever', $url );
		$this->assertDoesNotMatchRegularExpression( '/[?&]q=/', $url );
	}

	public function test_a_short_search_term_never_reaches_upstream(): void {
		Spotship_WI_Rate_Limiter::reset( 'search' );

		$request = new WP_REST_Request( 'GET', '/spotship/v1/location-search' );
		$request->set_header( 'origin', home_url() );
		$request->set_param( 'q', 'a' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['results'] );
		$this->assertCount( 0, $this->upstream, 'a one-character term must not cost an upstream call' );
	}

	/**
	 * Trimming is a security control, not tidiness: VesselDocument carries mmsi,
	 * flag, dwt and the commercial manager's name.
	 */
	public function test_search_results_are_trimmed_before_the_browser_sees_them(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array(),
				'body'     => (string) wp_json_encode(
					array(
						'results' => array(
							array(
								'id'      => 7,
								'name'    => 'Ever Given',
								'imo'     => '9811000',
								'type'    => 'Container',
								'mmsi'    => '353136000',
								'flag'    => 'PA',
								'manager' => 'A commercial manager',
							),
						),
					)
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			),
			10
		);

		Spotship_WI_Rate_Limiter::reset( 'search' );

		$request = new WP_REST_Request( 'GET', '/spotship/v1/vessel-search' );
		$request->set_header( 'origin', home_url() );
		$request->set_param( 'q', 'ever' );

		$body = rest_get_server()->dispatch( $request )->get_data();
		$row  = $body['results'][0];

		$this->assertSame( array( 'id', 'name', 'imo', 'type' ), array_keys( $row ) );
		$this->assertStringNotContainsString( 'commercial manager', (string) wp_json_encode( $body ) );
		$this->assertStringNotContainsString( '353136000', (string) wp_json_encode( $body ) );
	}

	public function test_a_cross_origin_request_is_refused(): void {
		$request = new WP_REST_Request( 'POST', '/spotship/v1/quote-request' );
		$request->set_header( 'origin', 'https://attacker.test' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $this->good( 'hull' ) ) );

		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * A Referer is a full URL with a path. Comparing whole URLs matches on
	 * Origin and never on Referer, which silently disables this fallback.
	 */
	public function test_a_same_host_referer_is_accepted_when_origin_is_absent(): void {
		Spotship_WI_Rate_Limiter::reset( 'submit' );

		$request = new WP_REST_Request( 'POST', '/spotship/v1/quote-request' );
		$request->set_header( 'referer', home_url( '/insurance/?utm=x' ) );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $this->good( 'hull' ) ) );

		$this->assertNotSame( 401, rest_get_server()->dispatch( $request )->get_status() );
	}
}
