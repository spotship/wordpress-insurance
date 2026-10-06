<?php
/**
 * The diagnostics ledger.
 *
 * This is the blob an administrator copies into a support request, so what it
 * says is the whole product here. A wrong label does not break anything — it
 * sends the person reading it to the wrong place, which is worse than an empty
 * field because it looks like an answer.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

/**
 * @covers Spotship_WI_Diagnostics
 */
final class DiagnosticsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		do_action( 'rest_api_init' );

		update_option(
			Spotship_WI_Settings::OPTION,
			array(
				'api_base_url'   => 'https://api.example.test',
				'api_key'        => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
				'bound_site_url' => Spotship_WI_Settings::current_site_url(),
			)
		);

		delete_transient( 'spotship_wi_ledger' );
		Spotship_WI_Rate_Limiter::reset( 'search' );
		Spotship_WI_Rate_Limiter::reset( 'submit' );
	}

	public function tear_down(): void {
		delete_transient( 'spotship_wi_ledger' );
		Spotship_WI_Rate_Limiter::reset( 'search' );
		Spotship_WI_Rate_Limiter::reset( 'submit' );

		parent::tear_down();
	}

	/** @return array<int, array<string, mixed>> */
	private function ledger(): array {
		return Spotship_WI_Diagnostics::ledger();
	}

	/**
	 * THE DEFECT THIS GUARDS AGAINST: an upstream 429 filed as "unavailable".
	 *
	 * normalise_code() sent everything it did not recognise to
	 * STATE_UNAVAILABLE, and `throttled` is not one of the STATE_* constants —
	 * so STATE_RATE_LIMITED was defined, listed in STATE_CODES, and written by
	 * nothing at all.
	 *
	 * "Unavailable" sends whoever reads the blob hunting a network fault or an
	 * outage. The real answer is that the install is over a limit, and the
	 * remedy — wait, or raise the ceiling — has nothing in common with that.
	 */
	public function test_an_upstream_429_is_recorded_as_rate_limited(): void {
		Spotship_WI_Diagnostics::record(
			'insurance/location_search',
			429,
			Spotship_WI_Api_Client::THROTTLED
		);

		$ledger = $this->ledger();

		$this->assertCount( 1, $ledger );
		$this->assertSame(
			Spotship_WI_Diagnostics::STATE_RATE_LIMITED,
			$ledger[0]['code'],
			'a 429 is a limit, not an outage'
		);
		$this->assertSame( 429, $ledger[0]['status'] );
		$this->assertSame( 'insurance/location_search', $ledger[0]['endpoint'] );
	}

	/**
	 * A 401 and a transport failure must keep their own labels — this is the
	 * mapping, not a rename of everything to the newest state.
	 */
	public function test_the_other_codes_keep_their_own_labels(): void {
		Spotship_WI_Diagnostics::record( 'insurance/rates', 401, Spotship_WI_Api_Client::UNAUTHORISED );
		Spotship_WI_Diagnostics::record( 'insurance/rates', 0, Spotship_WI_Api_Client::TRANSPORT );

		$ledger = $this->ledger();

		$this->assertSame( Spotship_WI_Diagnostics::STATE_UNAVAILABLE, $ledger[0]['code'] );
		$this->assertSame( Spotship_WI_Diagnostics::STATE_UNAUTHORISED, $ledger[1]['code'] );
	}

	/**
	 * THE DEFECT THIS GUARDS AGAINST: a local throttle leaving no trace.
	 *
	 * Spotship_WI_Rest_Proxy::throttle() answers 429 without any upstream call,
	 * so it never reached record() and wrote nothing. An install quietly turning
	 * visitors away at its own limit looked perfectly healthy in the one place
	 * somebody would look to find out why enquiries had stopped arriving.
	 */
	public function test_a_local_throttle_is_recorded_with_no_endpoint(): void {
		// The search ceiling is 30/minute; 31 requests guarantees a refusal
		// whatever the first few did.
		$throttled = false;

		for ( $i = 0; $i < 31; $i++ ) {
			$request = new WP_REST_Request( 'GET', '/spotship/v1/location-search' );
			$request->set_header( 'origin', home_url() );
			$request->set_param( 'q', 'rotterdam' );

			if ( 429 === rest_get_server()->dispatch( $request )->get_status() ) {
				$throttled = true;
				break;
			}
		}

		$this->assertTrue( $throttled, 'the search limit was never reached, so nothing was under test' );

		$codes = array_column( $this->ledger(), 'code' );

		$this->assertContains(
			Spotship_WI_Diagnostics::STATE_RATE_LIMITED,
			$codes,
			'a local refusal must appear in the blob an administrator sends to support'
		);

		foreach ( $this->ledger() as $entry ) {
			if ( Spotship_WI_Diagnostics::STATE_RATE_LIMITED === $entry['code'] ) {
				$this->assertSame( '', $entry['endpoint'], 'there is no upstream request behind a local refusal' );
				$this->assertSame( 0, $entry['status'] );
				break;
			}
		}
	}

	/**
	 * THE DEFECT THIS GUARDS AGAINST: a repeat evicting the history around it.
	 *
	 * The class docblock claimed this ledger had "the cap and the dedup". The
	 * cap was real; the dedup was not, and nothing noticed because the only call
	 * sites fired rarely. Recording throttles changes that — a scraper tripping
	 * the search limit produces one entry per request, and twenty identical rows
	 * would push out the 401 that preceded them and the binding failure before
	 * that, leaving a blob that says only "rate limited, twenty times".
	 */
	public function test_a_repeat_collapses_rather_than_evicting_history(): void {
		Spotship_WI_Diagnostics::record( 'insurance/rates', 401, Spotship_WI_Api_Client::UNAUTHORISED );

		for ( $i = 0; $i < 40; $i++ ) {
			Spotship_WI_Diagnostics::record_state( Spotship_WI_Diagnostics::STATE_RATE_LIMITED );
		}

		$ledger = $this->ledger();

		$this->assertCount( 2, $ledger, 'forty repeats are one row, not forty' );
		$this->assertSame( Spotship_WI_Diagnostics::STATE_RATE_LIMITED, $ledger[0]['code'] );
		$this->assertSame( 40, $ledger[0]['count'], 'the repetition is still visible' );
		$this->assertArrayHasKey( 'first_at', $ledger[0], 'and so is when the run started' );

		$this->assertSame(
			Spotship_WI_Diagnostics::STATE_UNAUTHORISED,
			$ledger[1]['code'],
			'the entry before the run must survive it'
		);
	}

	/**
	 * Collapsing must not swallow a DIFFERENT event that happens to follow a
	 * run of repeats — that would hide the thing most worth seeing.
	 */
	public function test_a_different_event_after_a_run_is_its_own_row(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			Spotship_WI_Diagnostics::record_state( Spotship_WI_Diagnostics::STATE_RATE_LIMITED );
		}

		Spotship_WI_Diagnostics::record_state( Spotship_WI_Diagnostics::STATE_UNBOUND );

		$ledger = $this->ledger();

		$this->assertCount( 2, $ledger );
		$this->assertSame( Spotship_WI_Diagnostics::STATE_UNBOUND, $ledger[0]['code'] );
		$this->assertSame( 5, $ledger[1]['count'] );
	}
}
