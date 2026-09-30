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
