/**
 * Spot Ship forms inside a page builder that injects them.
 *
 * THE DEFECT THIS EXISTS FOR. war-insurance.js sweeps `[data-sswi-root]` once,
 * at load. Elementor renders a widget through its own AJAX action and drops the
 * markup into a preview document that loaded long ago, so a form the site
 * builder has just dragged in is never initialised: the controls stay disabled
 * and the "needs JavaScript" notice stays up. The first thing anyone sees of
 * this feature is a form telling them it is broken.
 *
 * ONE HOOK, AND IT IS THE GENERIC ONE. Elementor fires
 * `frontend/element_ready/<widget-type>.default` per widget and
 * `frontend/element_ready/global` for every element. The global hook is used
 * here on purpose:
 *
 *   1. It does not encode our widget type names, so renaming a widget or
 *      giving it a skin cannot silently stop this working — and silence is the
 *      whole failure mode, since an inert form still renders.
 *   2. It also covers a form placed through Elementor's generic Shortcode
 *      widget, which is the route this plugin documented before it had widgets
 *      of its own and which has exactly the same problem.
 *
 * The cost is one querySelectorAll over a small subtree per element, which
 * finds nothing almost every time.
 *
 * @package Spotship_WI
 */
( function () {
	'use strict';

	var wired = false;

	/**
	 * Mount any Spot Ship roots inside one element's scope.
	 *
	 * @param {object} scope Elementor passes a jQuery object, not an element.
	 */
	function onElementReady( scope ) {
		/*
		 * Unwrapped defensively rather than assumed. Elementor has changed the
		 * shape of what it passes handlers before, and reading `[0]` off
		 * something that is already an element yields undefined — which would
		 * mount nothing, quietly, which is the bug this file is fixing.
		 */
		var node = scope && scope.nodeType ? scope : ( scope && scope[ 0 ] );

		if ( ! node || ! window.spotshipWI ) {
			return;
		}

		window.spotshipWI.mountAll( node );
	}

	function wire() {
		var frontend = window.elementorFrontend;

		if ( wired || ! frontend || ! frontend.hooks ) {
			return;
		}

		if ( typeof frontend.hooks.addAction !== 'function' ) {
			return;
		}

		wired = true;
		frontend.hooks.addAction( 'frontend/element_ready/global', onElementReady );
	}

	/*
	 * BOTH, NOT ONE OR THE OTHER. This script is deferred and so is Elementor's,
	 * and the order between them is not guaranteed. `elementor/frontend/init` is
	 * a plain window event: it does not replay, so arriving after it has fired
	 * means never hearing it. Checking for the object covers that case, and
	 * `wired` makes running both harmless.
	 */
	window.addEventListener( 'elementor/frontend/init', wire );
	wire();
} )();
