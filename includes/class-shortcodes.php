<?php
/**
 * Shortcode and block registration.
 *
 * Both surfaces delegate to `Spotship_WI_Renderer`, so there is exactly one
 * markup path and no way for a block to drift from a shortcode.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes and blocks.
 */
final class Spotship_WI_Shortcodes {

	/**
	 * Register both shortcodes.
	 */
	public static function register(): void {
		add_shortcode( 'spotship_war_hull', static fn() => Spotship_WI_Renderer::render( 'hull' ) );
		add_shortcode( 'spotship_war_cargo', static fn() => Spotship_WI_Renderer::render( 'cargo' ) );
	}

	/**
	 * Register both blocks.
	 *
	 * Server-rendered via the same renderer. `block.json` carries no `editorScript`
	 * because there is nothing to configure: the block has no attributes, and a
	 * settings panel that does nothing is worse than none.
	 */
	public static function register_blocks(): void {
		foreach ( array( 'hull', 'cargo' ) as $product ) {
			$dir = SPOTSHIP_WI_PATH . 'blocks/' . $product;

			if ( ! is_readable( $dir . '/block.json' ) ) {
				continue;
			}

			register_block_type(
				$dir,
				array(
					'render_callback' => static fn() => Spotship_WI_Renderer::render( $product ),
				)
			);
		}
	}
}
