<?php
/**
 * Payload mapping. A `handmValue` typo here is a silent upstream 400.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * @covers Spotship_WI_Payloads::map_hull
 * @covers Spotship_WI_Payloads::map_cargo
 */
final class PayloadsMappingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	private function context(): array {
		return array(
			'consent_text'       => 'I consent.',
			'consent_given_at'   => '2026-09-30T00:00:00+00:00',
			'privacy_notice_url' => 'https://example.test/privacy/',
			'origin_site_url'    => 'https://example.test',
		);
	}

	/**
	 * The wire format is camelCase and `handm_value` becomes `handmValue`, NOT
	 * `hAndMValue`. Asserted explicitly because it is the kind of thing that
	 * looks right in review and 400s in production.
	 */
	public function test_the_hull_payload_uses_the_agreed_camel_case_keys(): void {
		$payload = Spotship_WI_Payloads::map_hull(
			array(
				'userName'       => 'A',
				'handmValue'     => '1000',
				'increasedValue' => '50',
			),
			$this->context()
		);

		$this->assertArrayHasKey( 'handmValue', $payload );
		$this->assertArrayNotHasKey( 'hAndMValue', $payload );
		$this->assertArrayNotHasKey( 'handm_value', $payload );
		$this->assertSame( '1000', $payload['handmValue'] );
	}

	public function test_consent_is_asserted_by_the_mapper_not_read_from_the_form(): void {
		// The proxy's consent gate decides; a form that omits or falsifies the
		// key cannot smuggle a consenting payload past this layer.
		$payload = Spotship_WI_Payloads::map_hull( array( 'dataConsent' => false ), $this->context() );

		$this->assertTrue( $payload['dataConsent'] );
		$this->assertSame( Spotship_WI_Payloads::SOURCE, $payload['source'] );
	}

	public function test_an_absent_submission_id_is_omitted_rather_than_sent_empty(): void {
		$payload = Spotship_WI_Payloads::map_hull( array(), $this->context() );

		// DRF reads a missing optional key as "not supplied" and an empty string
		// as invalid, so an omission keeps the quote working.
		$this->assertArrayNotHasKey( 'submissionId', $payload );
	}

	public function test_a_valid_submission_id_is_forwarded_lowercased(): void {
		$payload = Spotship_WI_Payloads::map_hull(
			array( 'submissionId' => '3F2504E0-4F89-41D3-9A0C-0305E82C3301' ),
			$this->context()
		);

		$this->assertSame( '3f2504e0-4f89-41d3-9a0c-0305e82c3301', $payload['submissionId'] );
	}

	public function test_a_non_numeric_port_becomes_zero_rather_than_travelling(): void {
		$payload = Spotship_WI_Payloads::map_hull( array( 'fromPort' => '../../etc/passwd' ), $this->context() );

		$this->assertSame( 0, $payload['fromPort'] );
	}

	public function test_cargo_value_is_a_string_and_a_malformed_one_is_dropped(): void {
		// JSON floats lose precision on money, so this travels as a string.
		$ok = Spotship_WI_Payloads::map_cargo( array( 'cargoValue' => '1000.50' ), $this->context() );
		$this->assertSame( '1000.50', $ok['cargoValue'] );

		$bad = Spotship_WI_Payloads::map_cargo( array( 'cargoValue' => 'USD 1000' ), $this->context() );
		$this->assertSame( '', $bad['cargoValue'] );
	}

	public function test_destination_order_is_preserved_and_duplicates_removed(): void {
		$payload = Spotship_WI_Payloads::map_cargo(
			array( 'destinations' => array( '22', '11', '22', 'x', '33' ) ),
			$this->context()
		);

		// The backend stores position, so this is a list, not a set.
		$this->assertSame( array( 22, 11, 33 ), $payload['destinations'] );
	}

	public function test_an_absent_vessel_is_null_because_cargo_makes_it_optional(): void {
		$payload = Spotship_WI_Payloads::map_cargo( array(), $this->context() );

		$this->assertNull( $payload['vessel'] );
	}

	public function test_provenance_travels_on_both_products(): void {
		foreach ( array( 'map_hull', 'map_cargo' ) as $mapper ) {
			$payload = Spotship_WI_Payloads::$mapper( array(), $this->context() );

			$this->assertSame( 'I consent.', $payload['consentText'], $mapper );
			$this->assertSame( '2026-09-30T00:00:00+00:00', $payload['consentGivenAt'], $mapper );
			$this->assertSame( 'https://example.test/privacy/', $payload['privacyNoticeUrl'], $mapper );
			$this->assertSame( 'https://example.test', $payload['originSiteUrl'], $mapper );
		}
	}
}
