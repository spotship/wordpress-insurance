<?php
/**
 * Shared base for this plugin's Elementor widgets.
 *
 * THIS FILE EXTENDS AN ELEMENTOR CLASS, SO IT MUST NOT BE READ ON A SITE
 * WITHOUT ELEMENTOR. `class Foo extends \Elementor\Widget_Base` is resolved
 * when the file is parsed, not when a method is called, so a stray
 * `require_once` here on a plain WordPress install is a white screen. Reached
 * only through the autoloader, from Spotship_WI_Elementor::register_widgets(),
 * on a hook Elementor alone fires. See class-elementor.php.
 *
 * NO CONTROLS. register_controls() is deliberately not implemented. The blocks
 * declare no attributes and the shortcodes accept none, because every setting
 * these forms have comes from the plugin's own settings screen — an API key, a
 * base URL, a binding. A per-instance control here would be a fourth place for
 * configuration to disagree with itself.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Base widget: everything except which product it renders.
 */
abstract class Spotship_WI_Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Which product this widget renders.
	 *
	 * @return string 'hull' or 'cargo'.
	 */
	abstract protected function product(): string;

	/**
	 * Panel icon.
	 *
	 * Elementor ships its own icon font; `eicon-shield` matches the `shield`
	 * dashicon the two blocks already use in the Gutenberg inserter.
	 */
	public function get_icon(): string {
		return 'eicon-shield';
	}

	/**
	 * Panel category.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return array( Spotship_WI_Elementor::CATEGORY );
	}

	/**
	 * Words a site builder might search the panel for.
	 *
	 * "insurance" and "quote" matter more than the product names: someone
	 * laying out a page thinks in terms of the form they want, not in terms of
	 * this plugin's vocabulary.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return array( 'spot ship', 'spotship', 'insurance', 'war', 'quote', 'form', $this->product() );
	}

	/**
	 * Render the form.
	 *
	 * Straight to the shared renderer, which also enqueues the script and
	 * stylesheet — so a page without one of these widgets loads neither.
	 */
	protected function render(): void {
		/*
		 * Already escaped. Spotship_WI_Renderer::render() returns markup built
		 * by the templates, every interpolation of which goes through esc_*().
		 * Escaping it again here would print the form as visible HTML source.
		 */
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Spotship_WI_Renderer::render( $this->product() );
	}
}
