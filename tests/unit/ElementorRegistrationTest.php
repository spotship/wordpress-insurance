<?php
/**
 * Registration on a site that DOES have Elementor.
 *
 * THE OTHER HALF OF tests/integration/ElementorTest.php, AND THE HALF NOTHING
 * COULD REACH. That file runs on a container with no Elementor, which is the
 * configuration that matters most — a widget class parsed there is a white
 * screen. But it means every call to `Spotship_WI_Elementor::register_widgets()`
 * returns at the `class_exists( '\Elementor\Widget_Base' )` guard before any
 * other line runs, so the duck-type check behind it, and the registration it
 * protects, were asserted about by test names and exercised by nothing.
 *
 * Deleting that check left the whole suite green while
 * `$widgets_manager->register( ... )` on an object without the method is an
 * `Error` — a fatal, on the Elementor-renamed-the-hook scenario the guard was
 * written for.
 *
 * The unit bootstrap declares the parent class (tests/stubs/elementor.php), so
 * these run past the guard. The unit suite is a separate PHPUnit process from
 * the integration one, which is why that declaration cannot leak into the file
 * whose precondition is its absence.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * @covers Spotship_WI_Elementor
 */
final class ElementorRegistrationTest extends TestCase {

	/**
	 * A manager of an unexpected shape is refused rather than called.
	 *
	 * Elementor has renamed this hook before and passed a different object to
	 * it; `register` is a generic enough method name that assuming it is there
	 * is assuming something about somebody else's release notes. Calling a
	 * method that does not exist is an `Error`, which on a real site is a white
	 * screen on every page, front end and admin together.
	 *
	 * The pre-3.5.0 manager is the concrete case: it carried
	 * `register_widget_type()` and no `register()`.
	 *
	 * @dataProvider wrong_shapes
	 *
	 * @param mixed $manager What arrived on the hook.
	 */
	public function test_a_manager_of_the_wrong_shape_is_refused_rather_than_fatal( $manager ): void {
		try {
			Spotship_WI_Elementor::register_widgets( $manager );
		} catch ( \Throwable $e ) {
			$this->fail(
				'register_widgets() called through to a manager it cannot call: ' .
					get_class( $e ) . ' — ' . $e->getMessage()
			);
		}

		$this->assertTrue(
			class_exists( '\Elementor\Widget_Base', false ),
			'precondition: this suite must run WITH a parent class, or the guard under test is unreachable'
		);
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function wrong_shapes(): array {
		return array(
			'no register() at all'        => array( new stdClass() ),
			'the pre-3.5.0 manager'       => array(
				new class() {

					/**
					 * The 3.4 spelling, deprecated in 3.5.0 and still shipped.
					 *
					 * @param object $widget The widget to register.
					 */
					public function register_widget_type( $widget ): void {
					}
				},
			),
			'not an object'               => array( 'elementor/widgets/register' ),
			'null, as a dropped argument' => array( null ),
		);
	}

	/**
	 * Both widgets reach a manager that can take them, under the names pages store.
	 *
	 * The happy path had no test either, for the same reason: on a container
	 * without Elementor it cannot run. `get_name()` is asserted rather than the
	 * object count because Elementor persists that string in `_elementor_data`
	 * on every page using the widget — changing one breaks existing pages, and
	 * nothing else in the suite would notice.
	 */
	public function test_both_widgets_are_registered_under_the_names_pages_store(): void {
		$manager = new class() {

			/**
			 * @var array<int, object>
			 */
			public array $registered = array();

			/**
			 * @param object $widget The widget to register.
			 */
			public function register( $widget ): void {
				$this->registered[] = $widget;
			}
		};

		Spotship_WI_Elementor::register_widgets( $manager );

		$this->assertSame(
			array( 'spotship-war-hull', 'spotship-war-cargo' ),
			array_map( static fn( $widget ) => $widget->get_name(), $manager->registered )
		);
	}
}
