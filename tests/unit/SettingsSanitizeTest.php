<?php
/**
 * The settings sanitiser — the single trust boundary between the admin screen
 * and stored configuration.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers Spotship_WI_Settings::sanitize
 */
final class SettingsSanitizeTest extends TestCase {

	/** @var array<string, mixed> */
	private array $stored = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->stored = array();

		Functions\when( 'get_option' )->alias(
			fn( $name, $fallback = array() ) => array() === $this->stored ? $fallback : $this->stored
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->stored = $value;

				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'untrailingslashit' )->alias( fn( $s ) => rtrim( (string) $s, '/' ) );
		Functions\when( 'wp_parse_url' )->alias( fn( $u ) => parse_url( $u ) );
		Functions\when( 'add_settings_error' )->justReturn( null );
		Functions\when( 'sanitize_email' )->alias( fn( $e ) => trim( (string) $e ) );
		Functions\when( 'is_email' )->alias( fn( $e ) => (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'esc_url_raw' )->alias(
			function ( $url, $protocols = null ) {
				$url = trim( (string) $url );

				if ( is_array( $protocols ) ) {
					/*
					 * The ?? has to guard the ARRAY ACCESS, not the cast. Written as
					 * `(string) wp_parse_url( $url )['scheme'] ?? ''` the subscript is
					 * evaluated first and throws "Undefined array key" the moment a URL
					 * has no scheme — which is exactly the unconfigured-install case
					 * this file tests. CI caught it; it was the only one of 38 to fail.
					 */
					$sswi_parts = wp_parse_url( $url );
					$scheme     = strtolower( (string) ( is_array( $sswi_parts ) ? $sswi_parts['scheme'] ?? '' : '' ) );

					if ( ! in_array( $scheme, $protocols, true ) ) {
						return '';
					}
				}

				return $url;
			}
		);
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * This URL carries a bearer credential on every request. A typo'd http://
	 * base would put a customer's API key on the wire in plaintext.
	 */
	public function test_a_plaintext_base_url_is_rejected_outright(): void {
		$clean = Spotship_WI_Settings::sanitize( array( 'api_base_url' => 'http://api.example.test' ) );

		$this->assertSame( '', $clean['api_base_url'] );
	}

	public function test_an_https_base_url_is_kept_without_a_trailing_slash(): void {
		$clean = Spotship_WI_Settings::sanitize( array( 'api_base_url' => 'https://api.example.test/' ) );

		$this->assertSame( 'https://api.example.test', $clean['api_base_url'] );
	}

	/**
	 * The key field is never echoed back, so a blank submission is the NORMAL
	 * state of a masked field. Treating it as a clear wipes the credential on
	 * every unrelated save.
	 */
	public function test_an_empty_key_submission_means_unchanged_not_cleared(): void {
		$this->stored = array( 'api_key' => '3f2504e0-4f89-41d3-9a0c-0305e82c3301' );

		$clean = Spotship_WI_Settings::sanitize( array( 'api_key' => '' ) );

		$this->assertSame( '3f2504e0-4f89-41d3-9a0c-0305e82c3301', $clean['api_key'] );
	}

	public function test_a_malformed_key_keeps_the_previous_one(): void {
		$this->stored = array( 'api_key' => '3f2504e0-4f89-41d3-9a0c-0305e82c3301' );

		$clean = Spotship_WI_Settings::sanitize( array( 'api_key' => 'paste-accident' ) );

		$this->assertSame( '3f2504e0-4f89-41d3-9a0c-0305e82c3301', $clean['api_key'] );
	}

	public function test_the_cache_ttl_is_clamped_at_both_ends(): void {
		$this->assertSame( Spotship_WI_Settings::TTL_MIN, Spotship_WI_Settings::sanitize( array( 'rates_cache_ttl' => 1 ) )['rates_cache_ttl'] );
		$this->assertSame( Spotship_WI_Settings::TTL_MAX, Spotship_WI_Settings::sanitize( array( 'rates_cache_ttl' => 99999999 ) )['rates_cache_ttl'] );
		$this->assertSame( 600, Spotship_WI_Settings::sanitize( array( 'rates_cache_ttl' => 600 ) )['rates_cache_ttl'] );
	}

	/**
	 * Binding an install that cannot reach the API produces a site that PASSES
	 * the binding check and then fails at the transport layer — a 502 for the
	 * visitor, and a locally-decidable refusal below the anti-bot gates.
	 */
	public function test_an_unconfigured_install_is_not_bound(): void {
		$clean = Spotship_WI_Settings::sanitize(
			array(
				'api_base_url' => '',
				'api_key'      => '',
			)
		);

		$this->assertSame( '', $clean['bound_site_url'] );
	}

	public function test_a_configured_install_binds_to_the_current_site(): void {
		$clean = Spotship_WI_Settings::sanitize(
			array(
				'api_base_url' => 'https://api.example.test',
				'api_key'      => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
			)
		);

		$this->assertSame( 'example.test', $clean['bound_site_url'] );
	}

	/**
	 * A clone that saves settings must not thereby adopt itself. Re-binding is
	 * an explicit, separate action.
	 */
	public function test_an_existing_binding_is_never_silently_repointed(): void {
		$this->stored = array(
			'api_key'        => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
			'bound_site_url' => 'original.test',
		);

		$clean = Spotship_WI_Settings::sanitize(
			array(
				'api_base_url' => 'https://api.example.test',
				'api_key'      => '',
			)
		);

		$this->assertSame( 'original.test', $clean['bound_site_url'] );
	}

	public function test_an_invalid_support_email_is_dropped_rather_than_stored(): void {
		$this->assertSame( '', Spotship_WI_Settings::sanitize( array( 'support_email' => 'not an email' ) )['support_email'] );
		$this->assertSame( 'a@b.test', Spotship_WI_Settings::sanitize( array( 'support_email' => 'a@b.test' ) )['support_email'] );
	}
}
