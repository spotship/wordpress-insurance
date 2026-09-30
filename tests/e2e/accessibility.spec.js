/**
 * axe-core, with NO RULES DISABLED.
 *
 * Disabling a rule to get a green run would make this job a formality. If axe
 * reports something, it is a defect: this plugin renders public forms on
 * customers' own websites and WCAG 2.1 AA is a named requirement of the
 * milestone.
 */
'use strict';

const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

/**
 * axe over the page, minus the theme chrome.
 *
 * NO RULES ARE DISABLED. The exclusions are the theme's own header and footer
 * template parts, and they are a scope decision rather than a suppression: this
 * plugin renders a component into sites whose themes it does not control and
 * cannot fix. WordPress 6.8's default theme ships a `list` / `only-listitems`
 * violation in its navigation block, and failing this plugin's conformance
 * claim on that would make the suite a report on whatever theme wp-env
 * installed this month.
 *
 * Everything the plugin actually emits lives in the main content region and is
 * still scanned in full, in its real page context — so landmark, heading-order
 * and contrast rules still apply to it.
 *
 * @param {object} page Playwright page.
 */
function axeOf( page ) {
	return new AxeBuilder( { page } )
		.exclude( 'header' )
		.exclude( 'footer' )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] );
}

for ( const [ label, path ] of [ [ 'hull', '/insurance/' ], [ 'cargo', '/insurance-cargo/' ] ] ) {
	test( `${ label }: axe reports zero violations`, async ( { page } ) => {
		await page.goto( path );
		await page.waitForLoadState( 'networkidle' );

		const results = await axeOf( page )
			.analyze();

		expect( results.violations ).toEqual( [] );
	} );

	test( `${ label }: axe is clean with the listbox open`, async ( { page } ) => {
		await page.goto( path );

		const input = page.locator( 'input[role="combobox"]' ).first();
		await input.fill( 'rot' );
		await page.waitForTimeout( 400 );

		const results = await axeOf( page )
			.analyze();

		expect( results.violations ).toEqual( [] );
	} );

	test( `${ label }: the error summary takes focus on a failed submit`, async ( { page } ) => {
		/*
		 * NO `if ( await summary.isVisible() )`. That made the assertion
		 * conditional on the behaviour it exists to prove, so the test passed
		 * whenever the summary never appeared — and it never did: an immediate
		 * empty submit takes the CONSENT path, which produces no field errors,
		 * so `landed` stays 0 and the summary is never shown. The test asserted
		 * nothing, on either product, for its whole life.
		 *
		 * So drive a real field error instead. Bounds validation is hoisted
		 * above the anti-bot gates, so an over-length name returns a genuine
		 * 400 with `fields` whatever the form's age — no timing dependency, and
		 * no reliance on the upstream.
		 */
		await page.goto( path );

		await page.locator( '[data-sswi-consent]' ).check();

		// Past MAX_NAME (255). Set through the DOM because the input carries a
		// maxlength the keyboard would respect and the server check would not
		// then fire.
		await page.locator( '[data-sswi-field="userName"] input' ).evaluate( ( node ) => {
			node.value = 'x'.repeat( 300 );
			node.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		await page.locator( '[data-sswi-field="userEmail"] input' ).fill( 'ci+summary@spot-ship.com' );
		await page.locator( '.sswi-submit' ).click();

		const summary = page.locator( '[data-sswi-summary]' );

		await expect( summary ).toBeVisible();
		await expect( summary ).toBeFocused();
		await expect( summary ).not.toBeEmpty();

		// The summary is the signpost; the error itself must be on the field.
		await expect(
			page.locator( '[data-sswi-field="userName"] [data-sswi-error]' )
		).toBeVisible();
		await expect(
			page.locator( '[data-sswi-field="userName"] input' )
		).toHaveAttribute( 'aria-invalid', 'true' );
	} );
}

test( 'the form reflows at 320px without horizontal scrolling', async ( { page } ) => {
	await page.setViewportSize( { width: 320, height: 800 } );
	await page.goto( '/insurance/' );

	const overflows = await page.evaluate( () => {
		return document.documentElement.scrollWidth > document.documentElement.clientWidth;
	} );

	expect( overflows ).toBe( false );
} );

test( 'the form is usable at 200% zoom', async ( { page } ) => {
	await page.goto( '/insurance/' );
	await page.evaluate( () => {
		document.body.style.zoom = '200%';
	} );

	await expect( page.locator( '.sswi-submit' ) ).toBeVisible();
} );

/*
 * MULTI-INSTANCE PAGES.
 *
 * Everything above renders exactly one form per page, which is why duplicate
 * element ids survived a green axe run. Two forms on a page is the expected
 * customer layout, not an edge case.
 */
for ( const [ label, path ] of [
	[ 'hull + cargo', '/insurance-both/' ],
	[ 'the same shortcode twice', '/insurance-twice/' ],
] ) {
	test( `${ label }: axe reports zero violations`, async ( { page } ) => {
		await page.goto( path );
		await page.waitForLoadState( 'networkidle' );

		await expect( page.locator( '[data-sswi-root]' ) ).toHaveCount( 2 );

		const results = await axeOf( page )
			.analyze();

		expect( results.violations ).toEqual( [] );
	} );

	test( `${ label }: every id on the page is unique`, async ( { page } ) => {
		// Stated directly rather than left to axe's duplicate-id rules, whose
		// scope has narrowed across axe versions — duplicate-id was removed in
		// axe 4.10. Relying on it would make this coverage version-dependent.
		await page.goto( path );

		const duplicates = await page.evaluate( () => {
			const seen = new Map();

			document.querySelectorAll( '[id]' ).forEach( ( node ) => {
				seen.set( node.id, ( seen.get( node.id ) || 0 ) + 1 );
			} );

			return [ ...seen.entries() ].filter( ( [ , n ] ) => n > 1 ).map( ( [ id ] ) => id );
		} );

		expect( duplicates ).toEqual( [] );
	} );

	test( `${ label }: each label focuses an input in its own form`, async ( { page } ) => {
		/*
		 * The consequence of a collision, driven rather than inferred: clicking
		 * the SECOND form's label must focus the SECOND form's input. With
		 * duplicate ids the browser resolves to the first match in the document
		 * and focus lands in the form above.
		 */
		await page.goto( path );

		const second = page.locator( '[data-sswi-root]' ).nth( 1 );
		const label = second.locator( 'label[for]' ).first();
		const targetId = await label.getAttribute( 'for' );

		await label.click();

		const focusedId = await page.evaluate( () => document.activeElement.id );
		expect( focusedId ).toBe( targetId );

		// And that input must belong to the second form, not the first. Matched
		// on the attribute rather than a `#id` selector: ids here are generated,
		// and an attribute selector needs no escaping.
		await expect( second.locator( `[id="${ targetId }"]` ) ).toHaveCount( 1 );
	} );
}

for ( const product of [ 'hull', 'cargo' ] ) {
	test( `Elementor (${ product }): the shortcode renders and axe is clean inside a page builder`, async ( { page } ) => {
		/*
		 * Skips rather than fails when Elementor could not be installed — a
		 * wordpress.org outage is not a defect in this plugin. seed-e2e.sh warns.
		 *
		 * The status is NOT a sufficient absence check: WordPress guesses at 404
		 * permalinks and redirects to a near match with a 200, so a missing page
		 * arrives looking like a different page that happens to have no form. Test
		 * the landing URL as well.
		 */
		const response = await page.goto( `/insurance-elementor-${ product }/` );

		test.skip(
			! response || 404 === response.status() || ! page.url().includes( 'insurance-elementor' ),
			'Elementor page absent; see the warning from seed-e2e.sh'
		);

		await page.waitForLoadState( 'networkidle' );

		await expect( page.locator( '[data-sswi-root]' ) ).toHaveCount( 1 );
		await expect( page.locator( '.sswi-submit' ) ).toBeEnabled();

		const results = await axeOf( page )
			.analyze();

		expect( results.violations ).toEqual( [] );
	} );
}
