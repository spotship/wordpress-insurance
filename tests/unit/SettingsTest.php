<?php
/**
 * Settings sanitisation, origin binding and host comparison.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers Spotship_WI_Settings
 */
final class SettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this mock IS wp_parse_url; it cannot call itself.
		Functions\when( 'wp_parse_url' )->alias( static fn( $url ) => parse_url( $url ) );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'untrailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * An Origin header is scheme+host; a Referer is a FULL URL with a path.
	 * Comparing whole URLs matches on Origin and never on Referer, which
	 * silently disables the fallback the same-origin check depends on.
	 */
	public function test_host_comparison_ignores_the_path_so_a_referer_matches(): void {
		$this->assertSame( 'example.test', Spotship_WI_Settings::host_of( 'https://example.test' ) );
		$this->assertSame( 'example.test', Spotship_WI_Settings::host_of( 'https://example.test/insurance/' ) );
		$this->assertSame( 'example.test', Spotship_WI_Settings::host_of( 'http://EXAMPLE.test/a/b?c=d' ) );
	}

	public function test_a_non_default_port_is_a_different_origin(): void {
		$this->assertSame( 'example.test:8080', Spotship_WI_Settings::host_of( 'https://example.test:8080/x' ) );
		$this->assertSame( 'example.test', Spotship_WI_Settings::host_of( 'https://example.test:443/x' ) );
	}

	public function test_a_garbage_origin_yields_no_host_rather_than_a_false_match(): void {
		$this->assertSame( '', Spotship_WI_Settings::host_of( 'not a url' ) );
		$this->assertSame( '', Spotship_WI_Settings::host_of( '' ) );
	}

	/**
	 * 443 is the default port for https and NOT for http.
	 *
	 * Stripping both 80 and 443 regardless of scheme made
	 * `http://example.test:443` normalise to the same string as the site's own
	 * `https://example.test`, so a plaintext origin naming the https port
	 * passed the same-origin check.
	 */
	public function test_which_port_is_default_depends_on_the_scheme(): void {
		$this->assertSame( 'example.test', Spotship_WI_Settings::host_of( 'https://example.test:443/x' ) );
		$this->assertSame( 'example.test:443', Spotship_WI_Settings::host_of( 'http://example.test:443/x' ) );
		$this->assertSame( 'example.test', Spotship_WI_Settings::host_of( 'http://example.test:80/x' ) );
		$this->assertSame( 'example.test:80', Spotship_WI_Settings::host_of( 'https://example.test:80/x' ) );
	}

	/**
	 * With no scheme there is no default port to strip, so nothing is guessed.
	 */
	public function test_a_schemeless_url_keeps_its_port(): void {
		$this->assertSame( 'example.test:443', Spotship_WI_Settings::host_of( '//example.test:443/x' ) );
	}

	public function test_a_scheme_is_read_and_lowercased(): void {
		$this->assertSame( 'https', Spotship_WI_Settings::scheme_of( 'https://example.test/x' ) );
		$this->assertSame( 'http', Spotship_WI_Settings::scheme_of( 'HTTP://example.test' ) );
		$this->assertSame( '', Spotship_WI_Settings::scheme_of( 'not a url' ) );
		$this->assertSame( '', Spotship_WI_Settings::scheme_of( '' ) );
	}

	public function test_the_current_scheme_comes_from_home_url(): void {
		$this->assertSame( 'https', Spotship_WI_Settings::current_scheme() );
		$this->assertSame( 'example.test', Spotship_WI_Settings::current_host() );
	}
}
