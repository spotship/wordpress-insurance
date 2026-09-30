<?php
/**
 * Two forms on one page.
 *
 * Element ids must be unique within a DOCUMENT. A page may legitimately carry
 * hull and cargo together — that is the expected "Insurance" page layout — or
 * the same shortcode twice. Every id was constant, so the second form's labels
 * silently resolved to the first form's inputs: clicking "Email" under the cargo
 * form focused the hull form's email box, and a screen reader announced the
 * wrong field.
 *
 * This is WCAG 1.3.1 and 4.1.2. The axe suite could not have caught it, because
 * every Playwright page rendered exactly one form; the suite was green and the
 * defect was real. This renders through the REAL templates rather than asserting
 * on source text, so it fails if the namespacing is right in the partials but
 * dropped at a call site.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

/**
 * @covers Spotship_WI_Renderer
 */
final class RenderInstancesTest extends WP_UnitTestCase {

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
	}

	/**
	 * Every id in a fragment, in document order.
	 *
	 * @param string $html Rendered markup.
	 * @return list<string>
	 */
	private function ids( string $html ): array {
		preg_match_all( '/\sid="([^"]+)"/', $html, $matches );

		return $matches[1];
	}

	/**
	 * @param string $html Rendered markup.
	 * @return list<string>
	 */
	private function duplicates( string $html ): array {
		$counts = array_count_values( $this->ids( $html ) );

		return array_keys( array_filter( $counts, static fn( $n ) => $n > 1 ) );
	}

	public function test_hull_and_cargo_on_one_page_share_no_ids(): void {
		$page = Spotship_WI_Renderer::render( 'hull' ) . Spotship_WI_Renderer::render( 'cargo' );

		$this->assertNotEmpty( $this->ids( $page ), 'the fixture must actually contain ids' );
		$this->assertSame(
			array(),
			$this->duplicates( $page ),
			'hull and cargo on one page must not collide'
		);
	}

	public function test_the_same_form_twice_shares_no_ids(): void {
		$page = Spotship_WI_Renderer::render( 'hull' ) . Spotship_WI_Renderer::render( 'hull' );

		$this->assertSame(
			array(),
			$this->duplicates( $page ),
			'the same shortcode twice must not collide'
		);
	}

	public function test_every_label_resolves_within_its_own_form(): void {
		/*
		 * The consequence, asserted directly rather than inferred from id
		 * uniqueness: a `for` must name an id that exists in the SAME rendered
		 * instance. Unique ids with a mismatched `for` would pass the tests
		 * above and still leave the label pointing at nothing.
		 */
		foreach ( array( 'hull', 'cargo' ) as $product ) {
			$html = Spotship_WI_Renderer::render( $product );

			preg_match_all( '/\sfor="([^"]+)"/', $html, $labels );
			$ids = $this->ids( $html );

			$this->assertNotEmpty( $labels[1], "$product must contain labels" );

			foreach ( $labels[1] as $target ) {
				$this->assertContains(
					$target,
					$ids,
					"label for=\"$target\" names no input in the same $product form"
				);
			}
		}
	}

	public function test_the_honeypot_is_namespaced_too(): void {
		// It is rendered by the renderer rather than a template, so it is the
		// one field that a template-only fix would miss.
		$page = Spotship_WI_Renderer::render( 'hull' ) . Spotship_WI_Renderer::render( 'cargo' );

		$this->assertSame( 2, substr_count( $page, 'name="sswi_website"' ), 'both forms carry the honeypot' );
		$this->assertNotContains( 'sswi_website', $this->duplicates( $page ), 'but not the same id twice' );
	}
}
