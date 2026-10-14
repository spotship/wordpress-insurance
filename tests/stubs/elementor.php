<?php
/**
 * The slice of Elementor's API this plugin extends, for static analysis only.
 *
 * NOT LOADED AT RUNTIME, EVER. PHPStan reads it via `scanFiles`, which
 * registers the symbols without analysing them. Elementor itself supplies the
 * real classes on a site that has it.
 *
 * WHY A LOCAL STUB RATHER THAN A PACKAGE. Elementor publishes no official stub
 * package; the ones on Packagist are community-generated dumps of the entire
 * API. This plugin extends exactly one class and duck-types two managers, so a
 * dependency whose contents nobody here has read would be a poor trade for
 * about twenty lines.
 *
 * SIGNATURES ARE DELIBERATELY BARE. Elementor declares no return types on
 * these methods. Adding them here would let a subclass compile against a
 * contract Elementor does not actually have — PHP permits a child to add a
 * return type where the parent declares none, but not to disagree with one.
 * Keeping the stub faithful is what makes the check meaningful.
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
	 * Emit the front-end markup.
	 */
	protected function render() {}
}
