<?php
/**
 * Elementor registration, on a site that does not have Elementor.
 *
 * THAT IS THE POINT OF THIS FILE. Almost every customer install will not have
 * Elementor, and the dangerous failure is not "the widgets are missing" — it is
 * a WHITE SCREEN. `class X extends \Elementor\Widget_Base` is resolved when PHP
 * parses the file, not when a method runs, so merely reading
 * includes/class-elementor-hull.php on a plain WordPress install is a fatal
 * error that takes the whole site down, front end and admin together.
 *
 * The protection is that those files are reached only through the autoloader,
 * from inside a callback on a hook Elementor alone fires. That is an easy
 * property to destroy with an innocent-looking `require_once` or a
 * `class_exists()` check written the wrong way round, and nothing else in the
 * suite would notice: the widgets simply would not appear, on an install
 * nobody tests, until a customer's site went white.
 *
 * The integration container has no Elementor, so these tests run in exactly the
 * configuration that must stay safe.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

/**
 * @covers Spotship_WI_Elementor
 */
final class ElementorTest extends WP_UnitTestCase {

	/**
	 * The plugin boots without Elementor, and the widget classes stay unloaded.
	 *
	 * `class_exists( ..., false )` disables the autoloader on purpose: with
	 * autoloading on, this assertion would LOAD the very file it is checking is
	 * not loaded, fatal, and take the suite with it.
	 */
	public function test_the_widget_classes_are_not_loaded_without_elementor(): void {
		$this->assertFalse(
			class_exists( '\Elementor\Widget_Base', false ),
			'Precondition: the integration container must not have Elementor.'
		);

		foreach ( array( 'Spotship_WI_Elementor_Widget', 'Spotship_WI_Elementor_Hull', 'Spotship_WI_Elementor_Cargo' ) as $class ) {
			$this->assertFalse(
				class_exists( $class, false ),
				"$class was loaded on a site without Elementor; extending a missing parent is a fatal."
			);
		}
	}

	/**
	 * The registration class itself is safe to load anywhere.
	 */
	public function test_the_registrar_loads_without_elementor(): void {
		$this->assertTrue( class_exists( 'Spotship_WI_Elementor' ) );
	}

	/**
	 * Both Elementor hooks are registered.
	 *
	 * They are added at file scope rather than from `spotship_wi_boot()`,
	 * because Elementor builds its widget registry during `init` too and losing
	 * that race means the widgets never appear.
	 */
	public function test_both_elementor_hooks_are_registered(): void {
		$this->assertNotFalse(
			has_action( 'elementor/widgets/register', array( 'Spotship_WI_Elementor', 'register_widgets' ) ),
			'Widget registration is not hooked.'
		);

		$this->assertNotFalse(
			has_action( 'elementor/elements/categories_registered', array( 'Spotship_WI_Elementor', 'register_category' ) ),
			'Panel category is not hooked.'
		);
	}

	/**
	 * A manager that cannot register is ignored rather than fatal.
	 *
	 * Elementor has renamed these hooks before — `widgets_registered` carried a
	 * different object — and a plugin that assumes the shape crashes the site
	 * it is installed on. Cheaper to check than to be right about forever.
	 *
	 * Reaching the duck-type guard must NOT construct a widget, which is why
	 * this asserts the classes are still unloaded afterwards.
	 */
	public function test_an_unusable_widgets_manager_is_ignored(): void {
		Spotship_WI_Elementor::register_widgets( new stdClass() );
		Spotship_WI_Elementor::register_widgets( 'not an object' );

		$this->assertFalse(
			class_exists( 'Spotship_WI_Elementor_Hull', false ),
			'The guard was passed and a widget was constructed.'
		);
	}

	/**
	 * Likewise for the category manager.
	 */
	public function test_an_unusable_elements_manager_is_ignored(): void {
		Spotship_WI_Elementor::register_category( new stdClass() );
		Spotship_WI_Elementor::register_category( 'not an object' );

		$this->assertTrue( true, 'Reaching here without a fatal is the assertion.' );
	}
}
