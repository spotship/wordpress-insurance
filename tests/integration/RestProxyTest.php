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

		$this->upstream[] = array(
			'url'  => $url,
			'args' => $args,
		);

		// PER PRODUCT. A single hull-shaped answer meant every cargo row in the
		// parity table below was compared against a response the cargo endpoint
		// does not emit. Check cargo first: 'cargo_quote_request' contains
		// 'quote_request' as a substring.
		$product = str_contains( $url, 'cargo_quote_request' ) ? 'cargo' : 'hull';

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $this->genuine_201( $product ) ),
			'response' => array(
				'code'    => 201,
				'message' => 'Created',
			),
		);
	}

	/**
	 * Representative values for every key a genuine acceptance can carry.
	 *
	 * The VALUES are ours to choose; the KEY SET is not, and is read from
	 * tests/fixtures/backend-201-keys.json instead — see genuine_201().
	 *
	 * @var array<string, mixed>
	 */
	private const GENUINE_VALUES = array(
		'userName'         => 'A Person',
		'userEmail'        => 'a@example.test',
		'userPhone'        => '',
		'vessel'           => null,
		'handmValue'       => '',
		'increasedValue'   => '',
		'fromPort'         => 1,
		'toPort'           => 2,
		'cargoDescription' => 'Steel coils',
		'cargoWeight'      => '1000',
		'cargoValue'       => '1.00',
		'cargoCurrency'    => 'USD',
		'riskZone'         => 'Black Sea',
		'insuredParty'     => '',
		'destinations'     => array( 2 ),
		'notes'            => '',
		'dataConsent'      => true,
		'source'           => 'wordpress',
		'submissionId'     => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
		'consentGivenAt'   => '2026-09-30T00:00:00Z',
		'consentText'      => 'Consent text',
		'privacyNoticeUrl' => 'https://example.test/privacy/',
		'originSiteUrl'    => 'https://example.test',
	);

	/**
	 * A genuine acceptance, as the backend really shapes it.
	 *
	 * THE KEY SET IS NOT OURS TO ASSERT, so it is not written here. It is read
	 * from tests/fixtures/backend-201-keys.json, which bin/derive-backend-keys.sh
	 * generates from the backend serializer. Hand-writing a shape the API never
	 * emits is exactly how a previous implementation built an anti-bot decoy that
	 * imitated the fixture rather than the backend, and shipped the resulting
	 * tell. tests/js/contract.test.js already reads the derived list; this side
	 * did not, so the two could drift apart silently.
	 *
	 * Note it contains NEITHER `id` NOR `status`.
	 *
	 * @param string $product 'hull' or 'cargo'.
	 * @return array<string, mixed>
	 */
	private function genuine_201( string $product ): array {
		$path = dirname( __DIR__ ) . '/fixtures/backend-201-keys.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture on disk, not a remote resource.
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		$derived = is_array( $decoded ) ? $decoded : array();

		// Guarded rather than asserted-and-narrowed: PHPStan runs at level 6
		// without the PHPUnit extension, so an assertIsArray() would not narrow
		// json_decode()'s mixed and the offset read below would not type-check.
		$this->assertNotSame(
			array(),
			$derived,
			"{$path} is missing, empty or not JSON — run bin/derive-backend-keys.sh"
		);

		$keys = isset( $derived[ $product ] ) && is_array( $derived[ $product ] )
			? $derived[ $product ]
			: null;

		$this->assertNotNull( $keys, "backend-201-keys.json has no derived key list for '{$product}'" );

		$body = array();

		foreach ( (array) $keys as $key ) {
			$name = (string) $key;

			$this->assertArrayHasKey(
				$name,
				self::GENUINE_VALUES,
				"backend-201-keys.json carries '{$name}' and this fixture has no value for it.\n"
				. "The backend response shape has CHANGED. Add a representative value to\n"
				. "GENUINE_VALUES. Do not drop the key: the decoy is built to match this\n"
				. 'shape, and a key it omits is precisely the tell these tests exist to stop.'
			);

			$body[ $name ] = self::GENUINE_VALUES[ $name ];
		}

		return $body;
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
			'userName'      => 'A Person',
			'userEmail'     => 'a@example.test',
			'dataConsent'   => true,
			'submissionId'  => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
			'_sswi_ts'      => time() - 10,
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
		$sswi_keys = array_keys( $body['data'] );
		sort( $sswi_keys );

		$this->assertSame( array( 'status', 'submissionId' ), $sswi_keys );

		// A value only the upstream sent must not appear anywhere in the answer.
		$this->assertStringNotContainsString(
			'https://example.test/privacy/',
			(string) wp_json_encode( $body ),
			'the upstream body must not reach the browser'
		);
	}

	/**
	 * BOTH PRODUCTS. Hull-only left the cargo decoy unasserted, and cargo is the
	 * shape with the extra fields — the one where a divergence is likelier.
	 *
	 * @dataProvider products
	 * @param string $product 'hull' or 'cargo'.
	 */
	public function test_the_decoy_and_a_genuine_acceptance_share_one_body_shape( string $product ): void {
		$genuine = $this->submit( $product, $this->good( $product ) )->get_data();

		$flagged                 = $this->good( $product );
		$flagged['sswi_website'] = 'bot';

		$decoy = $this->submit( $product, $flagged )->get_data();

		$this->assertSame( array_keys( $genuine ), array_keys( $decoy ), "{$product}: envelope keys" );
		$this->assertSame( array_keys( $genuine['data'] ), array_keys( $decoy['data'] ), "{$product}: receipt keys" );
		$this->assertSame( $genuine['data']['submissionId'], $decoy['data']['submissionId'], "{$product}: submissionId" );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function products(): array {
		return array(
			'hull'  => array( 'hull' ),
			'cargo' => array( 'cargo' ),
		);
	}

	public function test_an_unauthorised_upstream_never_leaks_credential_state(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );

		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array(),
				'body'     => (string) wp_json_encode( array( 'detail' => 'Invalid API key' ) ),
				'response' => array(
					'code'    => 401,
					'message' => 'Unauthorized',
				),
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
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
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

	/**
	 * AN ORIGIN IS SCHEME + HOST + PORT, and all three are compared.
	 *
	 * The scheme half is compared in ONE direction only. A plaintext origin for
	 * an https site is a downgrade and is refused; an https origin for a site
	 * whose home_url() says http is the reverse-proxy misconfiguration — TLS
	 * terminated upstream without X-Forwarded-Proto — and is accepted, because
	 * serving https on that hostname already requires controlling it.
	 *
	 * These routes carry no nonce by design, so this comparison is the whole of
	 * the cross-origin defence.
	 *
	 * @dataProvider origins
	 * @param string $home     What home_url() reports.
	 * @param string $header   'origin' or 'referer'.
	 * @param string $value    The header value, '' to send none.
	 * @param bool   $expected Whether the request is same-origin.
	 * @param string $why      What this row is pinning down.
	 */
	public function test_same_origin_compares_scheme_host_and_port( string $home, string $header, string $value, bool $expected, string $why ): void {
		// No parameter: this replaces home_url()'s value outright rather than
		// filtering it, and an unused $url would only invite someone to use it.
		$filter = static fn() => $home;

		add_filter( 'home_url', $filter );

		$request = new WP_REST_Request( 'POST', '/spotship/v1/quote-request' );

		if ( '' !== $value ) {
			$request->set_header( $header, $value );
		}

		$actual = Spotship_WI_Rest_Proxy::same_origin( $request );

		remove_filter( 'home_url', $filter );

		$this->assertSame( $expected, $actual, $why );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: bool, 4: string}>
	 */
	public static function origins(): array {
		return array(
			'the site itself'           => array( 'https://example.test', 'origin', 'https://example.test', true, 'the ordinary same-origin case must still pass' ),
			'a referer carrying a path' => array( 'https://example.test', 'referer', 'https://example.test/insurance/?utm=x', true, 'the Referer fallback must survive the path' ),
			'a plaintext downgrade'     => array( 'https://example.test', 'origin', 'http://example.test', false, 'http is not the origin of an https site' ),
			'the https port over http'  => array( 'https://example.test', 'origin', 'http://example.test:443', false, 'naming the https port does not make a plaintext origin the site' ),
			// Same scheme both sides, so the downgrade rule above cannot be what
			// rejects this one — it is the port, and 443 is not http's default.
			'443 on a plaintext site'   => array( 'http://example.test', 'origin', 'http://example.test:443', false, '443 is not the default port for http, so this is a different origin' ),
			'another host'              => array( 'https://example.test', 'origin', 'https://evil.test', false, 'a different host is a different origin' ),
			'another port'              => array( 'https://example.test', 'origin', 'https://example.test:8080', false, 'a dev server on the same hostname is a different origin' ),
			'tls terminated at a proxy' => array( 'http://example.test', 'origin', 'https://example.test', true, 'home_url() says http only because X-Forwarded-Proto was dropped' ),
			'no origin and no referer'  => array( 'https://example.test', 'origin', '', false, 'a request naming no origin must not pass' ),
			'an unparseable origin'     => array( 'https://example.test', 'origin', 'not a url', false, 'garbage must not match' ),
		);
	}
}
