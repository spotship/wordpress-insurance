<?php
/**
 * Elementor widget registration.
 *
 * A THIRD SURFACE, NOT A THIRD RENDERER. The shortcode, the block and these
 * widgets all end at `Spotship_WI_Renderer::render()`, for the same reason
 * class-shortcodes.php gives: one markup path means no surface can drift from
 * another.
 *
 * WHY ELEMENTOR AT ALL, when a shortcode already works inside it. Elementor's
 * panel lists its own widgets, widgets from addons extending
 * `\Elementor\Widget_Base`, and classic `WP_Widget` subclasses. It does not
 * list Gutenberg blocks. So a site builder searching that panel for "Spot
 * Ship" found nothing, and the only route was typing a shortcode into
 * Elementor's generic Shortcode widget from memory.
 *
 * NOTHING HERE TOUCHES ELEMENTOR'S CLASSES. This file is autoloadable on any
 * install, Elementor present or not. The widget subclasses are the ones that
 * extend `\Elementor\Widget_Base`, and extending a class that does not exist
 * is a fatal at DECLARATION time — not at call time — so those files must
 * never be read on a site without Elementor. The plugin's autoloader
 * (spotship-war-insurance.php) gives that for free: `Spotship_WI_Elementor_Hull`
 * resolves to includes/class-elementor-hull.php only when the class is first
 * referenced, and the only reference is inside register_widgets() below, which
 * runs on a hook Elementor alone fires.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Registers this plugin's Elementor widgets and their panel category.
 */
final class Spotship_WI_Elementor {

	/**
	 * The panel category slug both widgets are filed under.
	 */
	public const CATEGORY = 'spotship';

	/**
	 * The handle of the script that re-initialises injected forms.
	 *
	 * Registered in spotship-war-insurance.php on `init` — never from here,
	 * because wp_register_script() before `init` raises _doing_it_wrong(), which
	 * with WP_DEBUG_DISPLAY prints before headers and stops WordPress setting a
	 * cookie. Named from the widgets' get_script_depends().
	 */
	public const HANDLER_SCRIPT = 'spotship-wi-elementor';

	/**
	 * Hook into Elementor.
	 *
	 * Called at file scope rather than from `spotship_wi_boot()`, which runs on
	 * `init`. Elementor builds its widget registry during `init` too, and the
	 * order between two `init` callbacks is priority-dependent — registering the
	 * listener late enough to miss `elementor/widgets/register` would mean the
	 * widgets silently never appear. add_action() itself calls no WordPress API
	 * that warns before `init`, so there is nothing to defer.
	 *
	 * ELEMENTOR 3.5.0 IS THE FLOOR, AND IT IS NOT ENFORCED ANYWHERE. Both
	 * `elementor/widgets/register` and Widgets_Manager::register() arrived in
	 * 3.5.0, which deprecated `elementor/widgets/widgets_registered` and
	 * register_widget_type() without removing them. On an older Elementor the
	 * hook below never fires: no error, no notice, the widgets are simply absent
	 * from the panel. readme.txt states the requirement because nothing in the
	 * code can.
	 *
	 * Deliberately no fallback to the deprecated pair. 3.5.0 is from December
	 * 2021, and a second registration path would double the code that has to
	 * stay safe to load on a site with no Elementor at all — which is the
	 * property this whole file is arranged around.
	 */
	public static function register(): void {
		add_action( 'elementor/widgets/register', array( self::class, 'register_widgets' ) );
		add_action( 'elementor/elements/categories_registered', array( self::class, 'register_category' ) );
	}

	/**
	 * Register both widgets.
	 *
	 * The parameter is untyped on purpose: type-hinting
	 * `\Elementor\Widgets_Manager` would resolve the class at declaration time
	 * and defeat the whole arrangement described in the file docblock.
	 *
	 * THE PARENT CLASS IS THE CONDITION THAT MATTERS, NOT THE MANAGER'S SHAPE.
	 * What white-screens a site is not a manager that cannot register — it is
	 * `new Spotship_WI_Elementor_Hull()` autoloading a file whose `extends
	 * \Elementor\Widget_Base` is resolved at PARSE time against a class that
	 * does not exist. `register` is a generic method name, and this file's whole
	 * premise is that the object arriving on this hook may not be the one we
	 * expect; guarding only on the manager would leave the actual fatal
	 * unguarded. The manager check stays because a manager we cannot call is
	 * still a manager we must not call.
	 *
	 * Autoloading is left ON deliberately. Elementor resolves `Widget_Base`
	 * through its own autoloader, and ours returns immediately for anything not
	 * prefixed `Spotship_WI_`, so nothing of this plugin's is loaded by asking.
	 *
	 * @param object $widgets_manager Elementor's widget manager.
	 */
	public static function register_widgets( $widgets_manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		if ( ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}

		$widgets_manager->register( new Spotship_WI_Elementor_Hull() );
		$widgets_manager->register( new Spotship_WI_Elementor_Cargo() );
	}

	/**
	 * Add a "Spot Ship" category to the Elementor panel.
	 *
	 * Its own category rather than `general`, so two widgets do not land in a
	 * list of eighty and a site builder can find them by scrolling as well as
	 * by searching.
	 *
	 * `eicon-anchor` for the same reason get_icon() is not `eicon-shield`: the
	 * eicons font has no shield, and an unknown class draws nothing at all.
	 *
	 * @param object $elements_manager Elementor's elements manager.
	 */
	public static function register_category( $elements_manager ): void {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'Spot Ship', 'spotship-war-insurance' ),
				'icon'  => 'eicon-anchor',
			)
		);
	}
}
