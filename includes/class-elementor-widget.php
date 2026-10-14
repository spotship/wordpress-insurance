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
	 * NOT `eicon-shield`, BECAUSE THERE IS NO SUCH ICON. The two blocks use the
	 * `shield` DASHICON in the Gutenberg inserter, but Elementor's panel draws
	 * from its own eicons font, and that font has no shield in any of its 517
	 * classes. An unrecognised class is not an error — eicons draws glyphs
	 * through `:before` content, so an unknown name simply draws nothing and the
	 * panel entry sits there with an empty box beside it.
	 *
	 * `eicon-form-horizontal` is the icon Elementor's own Form widget uses, so
	 * the entry reads as what it is: a form.
	 */
	public function get_icon(): string {
		return 'eicon-form-horizontal';
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
	 * This widget's markup must never be cached, and that is not the default it
	 * looks like.
	 *
	 * ELEMENTOR'S ELEMENT CACHE IS ON unless somebody turns it off.
	 * `elementor_element_cache_ttl` ships unset, and core/base/document.php reads
	 * anything other than the literal `disable` as active, falling back to 24
	 * hours. A cached element is one whose rendered HTML is stored in post meta
	 * and replayed verbatim.
	 *
	 * Spotship_WI_Renderer::render() stamps a timestamp and an HMAC signature
	 * into every form, and Spotship_WI_Request_Guard::MAX_AGE refuses anything
	 * older than 86400 seconds — the same 24 hours. A cached form would therefore
	 * go unsubmittable before its own cache entry expired, with nothing in the
	 * response to explain why. The per-instance `uid` would freeze too, so two
	 * cached forms on one page would hand out identical element ids, which is the
	 * failure RenderInstancesTest exists to prevent.
	 *
	 * Element_Base::is_dynamic_content() already returns true, and that
	 * inherited default is the only thing keeping this widget out of the cache:
	 * Elementor replaces a dynamic element with an `[elementor-element]`
	 * placeholder and re-renders it on every request. Stated here rather than
	 * inherited because it is load-bearing, invisible, and sits right next to a
	 * plausible-sounding optimisation — the widget has no controls and no
	 * settings, which makes it look static.
	 *
	 * Both the method and the cache it governs arrived in Elementor 3.22.0, so
	 * between the 3.5.0 floor and 3.22.0 this override is simply never called,
	 * and there is no element cache for it to matter to.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
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
