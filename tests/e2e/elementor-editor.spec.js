/**
 * The Elementor EDITOR, which is where this feature is actually used.
 *
 * THE DEFECT THIS GUARDS AGAINST. assets/js/war-insurance.js swept the document
 * for forms once, at load. Elementor renders a widget through its own AJAX
 * action and injects the markup into a preview that loaded long ago, so a form
 * the site builder had just dragged in was never initialised: every control
 * stayed disabled and the "needs JavaScript" notice stayed up. The first thing
 * anyone saw of the Elementor surface was a form announcing itself as broken.
 *
 * Nothing caught it, for the same reason blocks.spec.js exists: every other
 * spec in this suite drives the front end, where the page renders in one pass
 * and the sweep is enough. A surface that works when published and is dead in
 * the editor is invisible to a front-end test.
 *
 * DRIVEN THROUGH ELEMENTOR'S OWN COMMAND API, not drag-and-drop. `$e.run(
 * 'document/elements/create' )` is the same path the panel takes, so it
 * exercises the real AJAX render and injection, and it does not depend on panel
 * markup that changes between Elementor releases. Dragging would test the
 * drag.
 *
 * These run serially — they share one editor session.
 */
'use strict';

const { test, expect } = require( '@playwright/test' );
const { login } = require( '../support/wp-admin.js' );

test.describe.configure( { mode: 'serial' } );
test.setTimeout( 180000 );

const WIDGETS = [
	{ type: 'spotship-war-hull', product: 'hull' },
	{ type: 'spotship-war-cargo', product: 'cargo' },
];

/**
 * The id of a seeded page, or null when seeding skipped Elementor.
 *
 * Asked of the REST API rather than scraped from a body class: the class
 * depends on the theme, and this suite does not control which theme wp-env
 * installed this month.
 *
 * @param {object} page Playwright page.
 * @param {string} slug The page slug.
 */
async function pageIdBySlug( page, slug ) {
	const answer = await page.request.get( `/wp-json/wp/v2/pages?slug=${ slug }` );

	if ( ! answer.ok() ) {
		return null;
	}

	const found = await answer.json();

	return Array.isArray( found ) && found.length ? found[ 0 ].id : null;
}

for ( const widget of WIDGETS ) {
	test( `Elementor editor (${ widget.product }): a freshly inserted widget is live, not inert`, async ( {
		page,
	} ) => {
		await login( page );

		const id = await pageIdBySlug( page, `insurance-elementor-widget-${ widget.product }` );

		test.skip( null === id, 'Elementor page absent; see the warning from seed-e2e.sh' );

		await page.goto( `/wp-admin/post.php?post=${ id }&action=elementor` );

		/*
		 * The editor boots in stages and the command API is the last part to
		 * arrive. Waiting on $e.run specifically, rather than on a selector,
		 * because a visible panel does not mean the document is ready to accept
		 * a command.
		 */
		await page.waitForFunction(
			() =>
				window.$e &&
				'function' === typeof window.$e.run &&
				window.elementor &&
				'function' === typeof window.elementor.getPreviewContainer &&
				window.elementor.getPreviewContainer(),
			null,
			{ timeout: 120000 }
		);

		const preview = page.frameLocator( '#elementor-preview-iframe' );

		/*
		 * NON-VACUITY, AND THE WAIT FOR THE PREVIEW IN ONE STEP.
		 *
		 * The page already carries one widget, rendered with the document on
		 * the initial preview load. If the assertion further down passed
		 * against THAT one it would prove nothing about injection, so establish
		 * the baseline first.
		 *
		 * toHaveCount rather than .count(): a bare count does not retry, and
		 * the preview iframe is still rendering when the command API becomes
		 * available. Counting too early would read zero and fail for a reason
		 * that has nothing to do with this plugin.
		 */
		await expect( preview.locator( '[data-sswi-root]' ) ).toHaveCount( 1, { timeout: 120000 } );

		await page.evaluate( ( type ) => {
			window.$e.run( 'document/elements/create', {
				container: window.elementor.getPreviewContainer(),
				model: { elType: 'widget', widgetType: type },
			} );
		}, widget.type );

		// Two roots means the command resolved the widget type and Elementor
		// injected its markup. A type that did not resolve draws a
		// missing-widget placeholder instead, with no error anywhere.
		await expect( preview.locator( '[data-sswi-root]' ) ).toHaveCount( 2, { timeout: 60000 } );

		const injected = preview.locator( '[data-sswi-root]' ).last();

		/*
		 * THE ASSERTION THE WHOLE FILE IS FOR. The submit button ships
		 * `disabled` and only init() enables it, so an enabled button proves
		 * assets/js/elementor.js loaded, heard Elementor's element_ready hook,
		 * and mounted a root that arrived after the preview had finished
		 * loading. Before that script existed this stayed disabled forever.
		 */
		await expect( injected.locator( '.sswi-submit' ) ).toBeEnabled( { timeout: 60000 } );

		// And the notice init() removes must be gone, which is the thing a site
		// builder would actually have reported as a bug.
		await expect( injected.locator( '[data-sswi-noscript]' ) ).toHaveCount( 0 );

		// The right product, not just any form: the command carries the widget
		// type, and a wrong get_name() would silently give the other one.
		await expect( injected.locator( `[data-sswi-form="${ widget.product }"]` ) ).toHaveCount( 1 );
	} );
}
