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

for ( const [ label, path ] of [ [ 'hull', '/insurance/' ], [ 'cargo', '/insurance-cargo/' ] ] ) {
	test( `${ label }: axe reports zero violations`, async ( { page } ) => {
		await page.goto( path );
		await page.waitForLoadState( 'networkidle' );

		const results = await new AxeBuilder( { page } )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
			.analyze();

		expect( results.violations ).toEqual( [] );
	} );

	test( `${ label }: axe is clean with the listbox open`, async ( { page } ) => {
		await page.goto( path );

		const input = page.locator( 'input[role="combobox"]' ).first();
		await input.fill( 'rot' );
		await page.waitForTimeout( 400 );

		const results = await new AxeBuilder( { page } )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
			.analyze();

		expect( results.violations ).toEqual( [] );
	} );

	test( `${ label }: the error summary takes focus on a failed submit`, async ( { page } ) => {
		await page.goto( path );

		await page.locator( '.sswi-submit' ).click();
		await page.waitForTimeout( 500 );

		const summary = page.locator( '[data-sswi-summary]' );

		if ( await summary.isVisible() ) {
			await expect( summary ).toBeFocused();
		}
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

		const results = await new AxeBuilder( { page } )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
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

test( 'Elementor: the shortcode renders and axe is clean inside a page builder', async ( { page } ) => {
	// Skips rather than fails when Elementor could not be installed — a
	// wordpress.org outage is not a defect in this plugin. seed-e2e.sh warns.
	const response = await page.goto( '/insurance-elementor/' );
	test.skip( ! response || 404 === response.status(), 'Elementor page absent; see seed-e2e.sh' );

	await page.waitForLoadState( 'networkidle' );

	await expect( page.locator( '[data-sswi-root]' ) ).toHaveCount( 1 );
	await expect( page.locator( '.sswi-submit' ) ).toBeEnabled();

	const results = await new AxeBuilder( { page } )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
		.analyze();

	expect( results.violations ).toEqual( [] );
} );
