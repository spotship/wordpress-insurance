<?php
/**
 * Rates cache tiering and the 401 rule.
 *
 * These live in the integration suite rather than the unit suite because the
 * behaviour under test is the interaction between the transient layer and the
 * HTTP layer. Faking that in a unit test would mean adding an injection point to
 * production code purely for testability — a second definition of how the cache
 * reaches the network, which is the sort of thing that drifts and then lies.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

/**
 * @covers Spotship_WI_Rates_Cache
 */
final class RatesCacheTest extends WP_UnitTestCase {

	/** @var int */
	private int $calls = 0;

	/** @var array<string, mixed> */
	/**
	 * A transport failure is a WP_Error, not an array, so this cannot be typed
	 * `array` — doing so made two tests die in setUp with a TypeError before
	 * they reached an assertion.
	 *
	 * @var array<string, mixed>|WP_Error
	 */
	private $answer = array();

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

		Spotship_WI_Rates_Cache::purge();

		$this->calls  = 0;
		$this->answer = $this->ok_response();

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		Spotship_WI_Rates_Cache::purge();
		parent::tear_down();
	}

	/**
	 * @param mixed $pre  Short-circuit.
	 * @param array $args Args.
	 * @param string $url URL.
	 * @return array<string, mixed>
	 */
	public function intercept( $pre, $args, $url ) {
		unset( $pre, $args, $url );
		++$this->calls;

		return $this->answer;
	}

	/** @return array<string, mixed> */
	private function ok_response(): array {
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array(
					'updatedAt' => '2026-09-11',
					'columns'   => array( 'hmRate' ),
					'hull'      => array(
						array(
							'zone'   => 'Hormuz Passage',
							'hmRate' => '8.500%',
						),
					),
					'cargo'     => array(
						array(
							'zone'    => 'Hormuz Passage',
							'netRate' => null,
						),
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	/** @return array<string, mixed> */
	private function unauthorised(): array {
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( array( 'detail' => 'Invalid API key' ) ),
			'response' => array(
				'code'    => 401,
				'message' => 'Unauthorized',
			),
		);
	}

	public function test_a_successful_fetch_serves_fresh_and_is_not_refetched(): void {
		$first = Spotship_WI_Rates_Cache::get();

		$this->assertSame( Spotship_WI_Rates_Cache::TIER_FRESH, $first['tier'] );
		$this->assertSame( '2026-09-11', $first['rates']['updatedAt'] );
		$this->assertSame( 1, $this->calls );

		$second = Spotship_WI_Rates_Cache::get();

		$this->assertSame( Spotship_WI_Rates_Cache::TIER_FRESH, $second['tier'] );
		$this->assertSame( 1, $this->calls, 'the second call must come from the transient' );
	}

	public function test_a_transport_failure_falls_back_to_the_backup_copy(): void {
		Spotship_WI_Rates_Cache::get();
		delete_transient( Spotship_WI_Rates_Cache::FRESH );

		$this->answer = new WP_Error( 'http_request_failed', 'unreachable' );

		$serving = Spotship_WI_Rates_Cache::get();

		$this->assertSame( Spotship_WI_Rates_Cache::TIER_BACKUP, $serving['tier'] );
		$this->assertSame( '2026-09-11', $serving['rates']['updatedAt'] );
	}

	/**
	 * THE 401 RULE, and the half a naive test would miss: it is not enough that
	 * the table hides. The BACKUP MUST NOT BE CONSULTED — a 401 is an
	 * entitlement answer, and a churned customer whose backup is still warm
	 * would otherwise keep publishing our rate table on a public site.
	 */
	public function test_a_401_hides_the_table_and_skips_the_whole_fallback_chain(): void {
		// Warm every tier first, so a fallback would visibly succeed.
		Spotship_WI_Rates_Cache::get();
		$this->assertNotFalse( get_transient( Spotship_WI_Rates_Cache::BACKUP ) );

		delete_transient( Spotship_WI_Rates_Cache::FRESH );
		$this->answer = $this->unauthorised();

		$serving = Spotship_WI_Rates_Cache::get();

		$this->assertSame( Spotship_WI_Rates_Cache::TIER_NONE, $serving['tier'] );
		$this->assertSame( array(), $serving['rates'] );
		$this->assertFalse(
			get_transient( Spotship_WI_Rates_Cache::BACKUP ),
			'a 401 must purge the backup, not leave a warm copy to be served next request'
		);
	}

	public function test_the_bundled_tier_is_empty_in_the_repository_by_design(): void {
		// Shipping invented figures on a customer's public site is worse than
		// showing no table. The release build populates this.
		$this->assertSame( array(), Spotship_WI_Rates_Cache::bundled() );
	}

	public function test_with_nothing_cached_and_upstream_down_the_table_hides(): void {
		$this->answer = new WP_Error( 'http_request_failed', 'unreachable' );

		$serving = Spotship_WI_Rates_Cache::get();

		$this->assertSame( Spotship_WI_Rates_Cache::TIER_NONE, $serving['tier'] );
	}

	/**
	 * The stampede guard: the loser of the lock serves the backup immediately
	 * rather than queueing behind its own 8-second upstream call.
	 */
	public function test_a_held_lock_serves_the_backup_without_calling_upstream(): void {
		Spotship_WI_Rates_Cache::get();
		delete_transient( Spotship_WI_Rates_Cache::FRESH );

		/*
		 * The lock is a plain OPTION, not a transient — it is taken with
		 * add_option() because that is atomic against the unique index on
		 * option_name, which get/set transient is not. A test that seeds a
		 * transient here would not block acquire_lock() at all, and would pass or
		 * fail for reasons unrelated to the lock.
		 */
		add_option( Spotship_WI_Rates_Cache::LOCK, (string) time(), '', false );
		$before = $this->calls;

		$serving = Spotship_WI_Rates_Cache::get();

		$this->assertSame( Spotship_WI_Rates_Cache::TIER_BACKUP, $serving['tier'] );
		$this->assertSame( $before, $this->calls, 'the lock holder is already fetching; the loser must not fetch too' );
	}

	/**
	 * A lock left behind by a crashed request must not wedge the table at the
	 * backup tier indefinitely.
	 */
	public function test_an_abandoned_lock_is_taken_over_after_its_ttl(): void {
		Spotship_WI_Rates_Cache::get();
		delete_transient( Spotship_WI_Rates_Cache::FRESH );

		// A lock older than the TTL, as a crashed request would leave.
		add_option( Spotship_WI_Rates_Cache::LOCK, (string) ( time() - 3600 ), '', false );
		$before = $this->calls;

		$serving = Spotship_WI_Rates_Cache::get();

		$this->assertSame( Spotship_WI_Rates_Cache::TIER_FRESH, $serving['tier'] );
		$this->assertSame( $before + 1, $this->calls, 'an abandoned lock must be taken over, not waited on' );
	}

	public function test_the_rates_route_reports_the_serving_tier(): void {
		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( 'GET', '/spotship/v1/rates' );
		$request->set_header( 'origin', home_url() );

		$response = rest_get_server()->dispatch( $request );
		$body     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Spotship_WI_Rates_Cache::TIER_FRESH, $body['tier'] );
		$this->assertSame( '2026-09-11', $body['rates']['updatedAt'] );
	}
}
