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

	/**
	 * THE NAMESPACE MUST NOT BE DERIVED FROM RENDER ORDER.
	 *
	 * The sibling tests above prove two forms in ONE process differ, and a
	 * counter satisfies that perfectly — which is why it survived here for so
	 * long. The shape it does not survive is Elementor's editor, which renders
	 * each widget in its own `render_widget` AJAX request: a per-request static
	 * restarts at zero every time, so every widget came out as `sswi-1` and two
	 * on a page collided on every id.
	 *
	 * A test cannot easily span two PHP processes, so assert the property that
	 * would make it safe if it could: the prefix is not a position. `sswi-1`
	 * followed by `sswi-2` is the signature of a counter, and nothing else this
	 * renderer could legitimately produce looks like that.
	 */
	public function test_the_id_namespace_is_not_derived_from_render_order(): void {
		$first  = Spotship_WI_Renderer::render( 'hull' );
		$second = Spotship_WI_Renderer::render( 'hull' );

		foreach ( array( $first, $second ) as $index => $html ) {
			$this->assertSame(
				0,
				preg_match( '/id="sswi-\d+-/', $html ),
				sprintf(
					'render %d namespaced its ids with a number, which restarts at zero in every ' .
						'process and collides across Elementor\'s per-widget AJAX renders',
					$index + 1
				)
			);
		}
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
		//
		// THIS TEST USED TO BE A TAUTOLOGY. It asked whether 'sswi_website'
		// appeared in duplicates(), which returns ID values — but that string
		// is the NAME attribute, with an underscore, and no id is ever spelled
		// that way. The assertion could not fail. bin/mutation-check.js found
		// it: un-namespacing the renderer left it green.
		$page = Spotship_WI_Renderer::render( 'hull' ) . Spotship_WI_Renderer::render( 'cargo' );

		// The NAME must stay identical in both forms — the proxy reads the
		// honeypot by name, and a per-instance name would silently disarm it.
		$this->assertSame( 2, substr_count( $page, 'name="sswi_website"' ), 'both forms carry the honeypot' );

		// The ID must differ, which is the opposite requirement and the one
		// that was going unchecked.
		$honeypots = array_values(
			array_filter( $this->ids( $page ), static fn( $id ) => str_ends_with( $id, '-website' ) )
		);

		$this->assertCount( 2, $honeypots, 'each form renders exactly one honeypot id' );
		$this->assertSame( $honeypots, array_unique( $honeypots ), 'and the two ids are not the same' );
	}

	/**
	 * A REQUIRED COMBOBOX MUST SAY SO.
	 *
	 * The text fields have carried `required` and a visible marker from the
	 * start; the comboboxes carried neither, so a screen reader announced the
	 * hull vessel and both locations as optional and the only correction was the
	 * server rejecting the submission.
	 *
	 * It is `aria-required` rather than the native `required` used by
	 * text-field.php because the control that gets submitted is the hidden input
	 * beside the search box — see templates/partials/combobox.php.
	 */
	public function test_a_required_combobox_announces_that_it_is_required(): void {
		$hull  = Spotship_WI_Renderer::render( 'hull' );
		$cargo = Spotship_WI_Renderer::render( 'cargo' );

		foreach ( array( 'vessel', 'fromPort', 'toPort' ) as $name ) {
			$this->assertStringContainsString(
				'aria-required="true"',
				$this->field_block( $hull, $name ),
				"hull's {$name} is required and must say so"
			);
		}

		foreach ( array( 'fromPort', 'destinations' ) as $name ) {
			$this->assertStringContainsString(
				'aria-required="true"',
				$this->field_block( $cargo, $name ),
				"cargo's {$name} is required and must say so"
			);
		}

		// NON-VACUITY, and a requirement in its own right: vessel is optional on
		// cargo — Spotship_WI_Payloads::vessel() returns null for an empty one —
		// so a blanket aria-required would be a lie in the other direction.
		$this->assertStringNotContainsString(
			'aria-required="true"',
			$this->field_block( $cargo, 'vessel' ),
			"cargo's vessel is optional and must not claim otherwise"
		);

		// Programmatic is not enough on its own; the requirement is conveyed to
		// sighted users too.
		$this->assertStringContainsString(
			'(required)',
			$this->field_block( $hull, 'vessel' ),
			'the requirement is stated in text, not only in an ARIA attribute'
		);
	}

	/**
	 * The markup of one field, from its own wrapper up to the next field's.
	 *
	 * @param string $html Rendered form.
	 * @param string $name The data-sswi-field value to isolate.
	 */
	private function field_block( string $html, string $name ): string {
		$start = strpos( $html, 'data-sswi-field="' . $name . '"' );

		$this->assertNotFalse( $start, "the {$name} field is rendered at all" );

		$start = (int) $start;
		$next  = strpos( $html, '<div class="sswi-field', $start );

		return false === $next ? substr( $html, $start ) : substr( $html, $start, $next - $start );
	}
}
