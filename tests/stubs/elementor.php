<?php
/**
 * The slice of Elementor's API this plugin extends.
 *
 * NEVER ON A CUSTOMER'S SERVER. Nothing in the shipped plugin references this
 * file, and .distignore keeps tests/ out of the zip; Elementor itself supplies
 * the real classes on a site that has it. Two things here read it, both of them
 * ours:
 *
 *   1. PHPStan, through `scanFiles` — which registers the symbols without
 *      analysing the file, which is what a stub wants.
 *   2. tests/bootstrap-unit.php, which REQUIRES it, so the unit suite runs with
 *      a parent class present. That is the only configuration in which
 *      Spotship_WI_Elementor::register_widgets() gets past its
 *      `class_exists( '\Elementor\Widget_Base' )` guard, and therefore the only
 *      one in which the duck-type check behind it can be tested at all. The
 *      integration suite deliberately has no Elementor, so there the guard
 *      short-circuits and nothing below it is ever reached.
 *
 * Loading it has a second effect worth having: PHP itself then checks that this
 * plugin's subclasses are compatible with the signatures below, rather than
 * PHPStan being the only thing that ever says so.
 *
 * WHY A LOCAL STUB RATHER THAN A PACKAGE. Elementor publishes no official stub
 * package; the ones on Packagist are community-generated dumps of the entire
 * API. This plugin extends exactly one class and duck-types two managers, so a
 * dependency whose contents nobody here has read would be a poor trade for
 * about twenty lines.
 *
 * SIGNATURES ARE COPIED, NEVER INVENTED. Elementor declares no return type on
 * most of these, and the stub must not either: PHP permits a child to add a
 * return type where the parent declares none, but not to disagree with one, so
 * a stub that guessed would let the subclasses compile against a contract
 * Elementor does not have. `is_dynamic_content()` is the one exception —
 * Elementor really does declare `: bool` there — and it is reproduced exactly,
 * for the same reason.
 *
 * @package Spotship_WI
 */

namespace Elementor;

/**
 * @see https://developers.elementor.com/docs/widgets/
 */
abstract class Widget_Base {

	/**
	 * The stored widget type. Must be unique across the install.
	 */
	public function get_name() {}

	/**
	 * Human-readable name, shown in the panel.
	 */
	public function get_title() {}

	/**
	 * Elementor icon-font class.
	 */
	public function get_icon() {}

	/**
	 * Panel categories this widget belongs to.
	 */
	public function get_categories() {}

	/**
	 * Extra search terms for the panel.
	 */
	public function get_keywords() {}

	/**
	 * Script handles to enqueue wherever this widget renders.
	 */
	public function get_script_depends() {}

	/**
	 * Style handles to enqueue wherever this widget renders, and — in the
	 * editor preview — up front for every registered widget.
	 */
	public function get_style_depends() {}

	/**
	 * Whether the element is too dynamic for Elementor's element cache.
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Emit the front-end markup.
	 */
	protected function render() {}
}
