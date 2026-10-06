<?php
/**
 * The rate table, rendered through the REAL template, against the REAL payload.
 *
 * THE DEFECT THIS GUARDS AGAINST, which shipped:
 *
 * `GET /api/insurance/rates/` returns
 *   { updatedAt, hull: { columns, zones, disclaimers, footnotes }, cargo: {…} }
 * with each column an object of { key, label }, each row keyed `name`, and each
 * rate a JSON NUMBER.
 *
 * The template read a top-level `columns` list of plain strings and treated
 * `$rates[$product]` as the row list. Against the real backend that produced a
 * table with no header cells and four empty body rows — one per key of the
 * product object — no disclaimers at all, and, had the rows resolved, "0.085"
 * where "8.50%" belongs.
 *
 * Nothing caught it. The browser suite ran against a stand-in that reproduced
 * the invented shape, and its one rate-table assertion was wrapped in
 * `if ( await rates.count() )`. The unit and integration suites never rendered
 * the table at all. So this renders it, from the fixture derived out of the
 * backend by bin/derive-backend-rates.sh.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

/**
 * @covers Spotship_WI_Renderer
 */
final class RatesTableTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		update_option(
			Spotship_WI_Settings::OPTION,
			array(
				'api_base_url'       => 'https://api.example.test',
				'api_key'            => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
				'bound_site_url'     => Spotship_WI_Settings::current_site_url(),
				'privacy_notice_url' => 'https://example.test/privacy',
			)
		);

		set_transient( Spotship_WI_Rates_Cache::FRESH, $this->payload(), 3600 );
	}

	public function tear_down(): void {
		delete_transient( Spotship_WI_Rates_Cache::FRESH );
		delete_transient( Spotship_WI_Rates_Cache::BACKUP );

		parent::tear_down();
	}

	/**
	 * The body the endpoint returns, derived from the backend.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		$path = dirname( __DIR__ ) . '/fixtures/backend-rates.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture on disk, not a remote resource.
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		$body    = is_array( $decoded ) ? $decoded : array();

		unset( $body['_comment'] );

		return $body;
	}

	/**
	 * @param string $product 'hull' or 'cargo'.
	 * @return array<string, mixed>
	 */
	private function section( string $product ): array {
		$payload = $this->payload();

		return is_array( $payload[ $product ] ) ? $payload[ $product ] : array();
	}

	/**
	 * The rendered table, as a DOMXPath over the whole form.
	 *
	 * @param string $product 'hull' or 'cargo'.
	 */
	private function xpath( string $product ): DOMXPath {
		$html = Spotship_WI_Renderer::render( $product );

		$this->assertStringContainsString( 'sswi-rates', $html, 'no rate table was rendered at all' );

		$document = new DOMDocument();

		// The fragment is not a whole document and carries UTF-8 content; both
		// would otherwise produce warnings that say nothing about the markup.
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();

		return new DOMXPath( $document );
	}

	/** @return array<int, string> */
	private function texts( DOMXPath $xpath, string $query ): array {
		$out = array();

		foreach ( $xpath->query( $query ) as $node ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode's own API.
			$out[] = trim( (string) $node->textContent );
		}

		return $out;
	}

	/** @return array<string, array{0: string}> */
	public function products(): array {
		return array(
			'hull'  => array( 'hull' ),
			'cargo' => array( 'cargo' ),
		);
	}

	/**
	 * The header row carries one cell per column the endpoint declared for THIS
	 * product, labelled the way the endpoint labelled it.
	 *
	 * Reading a single top-level list gave cargo the hull columns, so every
	 * cargo rate fell through to an em dash.
	 *
	 * @dataProvider products
	 * @param string $product Product under test.
	 */
	public function test_the_header_row_is_the_products_own_columns( string $product ): void {
		$xpath   = $this->xpath( $product );
		$columns = $this->section( $product )['columns'];

		$headers = $this->texts( $xpath, '//table/thead/tr/th' );

		// The first cell is the risk-zone column, which is ours, not upstream's.
		$this->assertCount(
			count( $columns ) + 1,
			$headers,
			'one header cell per declared column, plus the risk zone'
		);

		$this->assertSame(
			array_map( static fn( array $column ): string => (string) $column['label'], $columns ),
			array_slice( $headers, 1 ),
			'the header shows each column LABEL, not the key it is looked up by'
		);
	}

	/**
	 * Every zone the endpoint served reaches the page, named by `name`.
	 *
	 * @dataProvider products
	 * @param string $product Product under test.
	 */
	public function test_every_zone_is_rendered_and_named( string $product ): void {
		$xpath = $this->xpath( $product );
		$zones = $this->section( $product )['zones'];

		$rows = $this->texts( $xpath, '//table/tbody/tr/th' );

		$this->assertSame(
			array_map( static fn( array $zone ): string => (string) $zone['name'], $zones ),
			$rows,
			'every zone, in order, labelled by its name'
		);
	}

	/**
	 * A rate is a fraction and must be published as a percentage.
	 *
	 * 0.085 rendered verbatim is not a formatting slip: it is a war-risk rate
	 * wrong by two orders of magnitude, on a commercial table on a customer's
	 * public website.
	 */
	public function test_a_rate_is_published_as_a_percentage(): void {
		$xpath = $this->xpath( 'hull' );

		$cells = $this->texts(
			$xpath,
			'//table/tbody/tr[th[normalize-space()="Hormuz Passage"]]/td'
		);

		$this->assertSame( array( '8.50%', '8.50%', '6.80%' ), $cells );
	}

	/**
	 * A REAL null is not a missing key.
	 *
	 * Cargo's Hormuz Passage row carries `netRate: null` — there is no net rate
	 * for that zone. It must read as an em dash rather than as an empty cell, a
	 * zero, or the string "null".
	 */
	public function test_an_absent_rate_reads_as_an_em_dash(): void {
		$xpath = $this->xpath( 'cargo' );

		$cells = $this->texts(
			$xpath,
			'//table/tbody/tr[th[normalize-space()="Hormuz Passage"]]/td'
		);

		$this->assertSame( array( '8.00%', '—' ), $cells );
	}

	/**
	 * THE COMPLIANCE TEXT.
	 *
	 * The disclaimers state that the figures are indicative only, not an offer
	 * or a quote, and subject to underwriting; they name Spot Ship's introducer
	 * role and the insurance partner. The table is published on a third party's
	 * public website, so omitting them is a compliance failure — and reading
	 * them from the top level, where the endpoint does not put them, omitted
	 * them on every install.
	 *
	 * @dataProvider products
	 * @param string $product Product under test.
	 */
	public function test_the_disclaimers_are_rendered( string $product ): void {
		$xpath       = $this->xpath( $product );
		$disclaimers = $this->section( $product )['disclaimers'];

		$this->assertNotEmpty( $disclaimers, 'the fixture must carry disclaimers to assert on' );

		$this->assertSame(
			array_map( 'strval', $disclaimers ),
			$this->texts( $xpath, '//div[@class="sswi-disclaimers"]/p' ),
			'every disclaimer the endpoint served must appear'
		);
	}

	/**
	 * Hull's footnotes explain the Hormuz Passage outlier — no no-claims
	 * discount, and a different Spot Ship share. Nothing rendered them.
	 */
	public function test_hull_footnotes_are_rendered(): void {
		$xpath     = $this->xpath( 'hull' );
		$footnotes = $this->section( 'hull' )['footnotes'];

		$this->assertNotEmpty( $footnotes, 'the fixture must carry footnotes to assert on' );

		$this->assertSame(
			array_map( 'strval', $footnotes ),
			$this->texts( $xpath, '//div[@class="sswi-footnotes"]/p' )
		);
	}
}
