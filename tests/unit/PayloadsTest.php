<?php
/**
 * Payload mapping and the response allow-list.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * @covers Spotship_WI_Payloads
 */
final class PayloadsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The derivation must see conditionally-added keys.
	 *
	 * `add_provenance()` appends `submissionId` only when one was supplied, so a
	 * derivation probed with an EMPTY form omits it — and a test that probes the
	 * same way cannot notice, because it asserts the derivation against itself.
	 * This asserts against a HAND-WRITTEN list on purpose.
	 */
	public function test_relayable_keys_are_the_client_half_of_the_hull_payload(): void {
		$this->assertSame(
			array( 'userName', 'userEmail', 'userPhone', 'vessel', 'handmValue', 'increasedValue', 'fromPort', 'toPort', 'notes' ),
			Spotship_WI_Payloads::client_keys( 'hull' )
		);
	}

	public function test_relayable_keys_are_the_client_half_of_the_cargo_payload(): void {
		$this->assertSame(
			array( 'userName', 'userEmail', 'userPhone', 'vessel', 'cargoDescription', 'cargoWeight', 'cargoValue', 'cargoCurrency', 'riskZone', 'insuredParty', 'fromPort', 'destinations', 'notes' ),
			Spotship_WI_Payloads::client_keys( 'cargo' )
		);
	}

	/**
	 * The browser mints it, no visitor typed it, and an upstream error keyed on
	 * it could only mean the contract moved under us.
	 */
	public function test_the_client_minted_idempotency_key_is_never_relayed_back(): void {
		$this->assertNotContains( 'submissionId', Spotship_WI_Payloads::client_keys( 'hull' ) );
		$this->assertNotContains( 'submissionId', Spotship_WI_Payloads::client_keys( 'cargo' ) );

		$relayed = Spotship_WI_Payloads::trim_field_errors(
			array( 'submissionId' => array( 'Nope.' ) ),
			'hull'
		);

		$this->assertSame( array(), $relayed );
	}

	public function test_server_owned_and_unknown_keys_are_dropped(): void {
		$relayed = Spotship_WI_Payloads::trim_field_errors(
			array(
				'userEmail'      => array( 'Enter a valid email address.' ),
				'source'         => array( 'server owned' ),
				'originSiteUrl'  => array( 'server owned' ),
				'nonFieldErrors' => array( 'keyed on no field' ),
				'leadScore'      => array( 'a field that does not exist yet' ),
			),
			'hull'
		);

		$this->assertSame( array( 'userEmail' => array( 'Enter a valid email address.' ) ), $relayed );
	}

	public function test_a_nested_error_object_cannot_travel(): void {
		$relayed = Spotship_WI_Payloads::trim_field_errors(
			array( 'userEmail' => array( array( 'nested' => 'object' ) ) ),
			'hull'
		);

		$this->assertSame( array(), $relayed );
	}

	public function test_vessel_rows_are_reduced_to_the_four_rendered_keys(): void {
		$rows = Spotship_WI_Payloads::trim_vessels(
			array(
				'results' => array(
					array(
						'id'      => 7,
						'name'    => 'Ever Given',
						'imo'     => '9811000',
						'type'    => 'Container',
						'mmsi'    => '353136000',
						'flag'    => 'PA',
						'dwt'     => 199629,
						'manager' => 'A commercial manager',
					),
				),
			)
		);

		$this->assertSame(
			array( array( 'id' => 7, 'name' => 'Ever Given', 'imo' => '9811000', 'type' => 'Container' ) ),
			$rows
		);
	}

	public function test_location_rows_drop_coordinates_and_internal_ids(): void {
		$rows = Spotship_WI_Payloads::trim_locations(
			array(
				array(
					'id'              => 3,
					'name'            => 'Rotterdam',
					'country'         => 'NL',
					'locode'          => 'NLRTM',
					'latitude'        => 51.9,
					'longitude'       => 4.5,
					'sedna_region_id' => 42,
				),
			)
		);

		$this->assertSame(
			array( array( 'id' => 3, 'name' => 'Rotterdam', 'country' => 'NL', 'locode' => 'NLRTM' ) ),
			$rows
		);
	}

	public function test_a_malformed_submission_id_is_discarded_rather_than_forwarded(): void {
		$this->assertSame( '', Spotship_WI_Payloads::submission_id( array( 'submissionId' => 'not-a-uuid' ) ) );
		$this->assertSame( '', Spotship_WI_Payloads::submission_id( array() ) );
		$this->assertSame(
			'3f2504e0-4f89-41d3-9a0c-0305e82c3301',
			Spotship_WI_Payloads::submission_id( array( 'submissionId' => '3F2504E0-4F89-41D3-9A0C-0305E82C3301' ) )
		);
	}

	public function test_bounds_treat_empty_as_acceptable_because_requiredness_is_upstreams_call(): void {
		$this->assertTrue( Spotship_WI_Payloads::within( '', 3 ) );
		$this->assertTrue( Spotship_WI_Payloads::within( 'abc', 3 ) );
		$this->assertFalse( Spotship_WI_Payloads::within( 'abcd', 3 ) );
	}

	public function test_destination_counts_are_bounded_at_both_ends(): void {
		$this->assertFalse( Spotship_WI_Payloads::destinations_ok( array() ) );
		$this->assertTrue( Spotship_WI_Payloads::destinations_ok( array( 1 ) ) );
		$this->assertTrue( Spotship_WI_Payloads::destinations_ok( range( 1, Spotship_WI_Payloads::MAX_DESTINATIONS ) ) );
		$this->assertFalse( Spotship_WI_Payloads::destinations_ok( range( 1, Spotship_WI_Payloads::MAX_DESTINATIONS + 1 ) ) );
	}
}
