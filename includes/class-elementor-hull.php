<?php
/**
 * The war hull quote form, as an Elementor widget.
 *
 * Extends an Elementor class — see class-elementor-widget.php for why this
 * file must never be read on a site without Elementor.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * War hull quote widget.
 */
final class Spotship_WI_Elementor_Hull extends Spotship_WI_Elementor_Widget {

	/**
	 * Widget handle, as stored in `_elementor_data` on every page using it.
	 *
	 * CHANGING THIS BREAKS EXISTING PAGES. Elementor persists the widget type
	 * in post meta, and a page whose stored type no longer resolves renders as
	 * a missing-widget placeholder.
	 */
	public function get_name(): string {
		return 'spotship-war-hull';
	}

	/**
	 * Panel title. Matches blocks/hull/block.json, so the two surfaces are the
	 * same thing to the person placing them.
	 */
	public function get_title(): string {
		return __( 'War Hull Quote', 'spotship-war-insurance' );
	}

	/**
	 * Which product this widget renders.
	 */
	protected function product(): string {
		return 'hull';
	}
}
