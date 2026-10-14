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
	 * Hook into Elementor.
	 *
	 * Called at file scope rather than from `spotship_wi_boot()`, which runs on
	 * `init`. Elementor builds its widget registry during `init` too, and the
	 * order between two `init` callbacks is priority-dependent — registering the
	 * listener late enough to miss `elementor/widgets/register` would mean the
	 * widgets silently never appear. add_action() itself calls no WordPress API
	 * that warns before `init`, so there is nothing to defer.
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
	 * @param object $widgets_manager Elementor's widget manager.
	 */
	public static function register_widgets( $widgets_manager ): void {
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
				'icon'  => 'eicon-shield',
			)
		);
	}
}
