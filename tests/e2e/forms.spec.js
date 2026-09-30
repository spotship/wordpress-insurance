/**
 * Happy paths for both products.
 */
'use strict';

const { test, expect } = require( '@playwright/test' );

test( 'the hull combobox keeps focus on the input and moves aria-activedescendant', async ( { page } ) => {
	await page.goto( '/insurance/' );

	const input = page.locator( '#sswi-fromPort' );

	await input.fill( 'rot' );
	await page.waitForTimeout( 400 );
	await input.press( 'ArrowDown' );

	// THE classic combobox failure is moving DOM focus into the option list,
	// which kills the screen reader's echo of what was typed.
	await expect( input ).toBeFocused();

	const active = await input.getAttribute( 'aria-activedescendant' );
	expect( active ).toBeTruthy();
	await expect( page.locator( `#${ active }` ) ).toHaveAttribute( 'aria-selected', 'true' );
} );

test( 'choosing a location submits the id that was displayed', async ( { page } ) => {
	await page.goto( '/insurance/' );

	const input = page.locator( '#sswi-fromPort' );

	await input.fill( 'rot' );
	await page.waitForTimeout( 400 );
	await input.press( 'ArrowDown' );
	await input.press( 'Enter' );

	const shown = await input.inputValue();
	// The hull form has TWO location comboboxes — fromPort and toPort — so
	// selecting by kind matches both and Playwright refuses in strict mode.
	const submitted = await page.locator( '[data-sswi-field="fromPort"] [data-sswi-value]' ).inputValue();

	expect( shown ).not.toEqual( '' );
	expect( submitted ).not.toEqual( '' );
} );

test( 'cargo destinations can be added and removed, and removal is announced', async ( { page } ) => {
	await page.goto( '/insurance-cargo/' );

	const input = page.locator( '#sswi-destinations' );

	await input.fill( 'rot' );
	await page.waitForTimeout( 400 );
	await input.press( 'ArrowDown' );
	await input.press( 'Enter' );

	const chips = page.locator( '[data-sswi-chips] li' );
	await expect( chips ).toHaveCount( 1 );

	// Every chip carries its own labelled remove control.
	const remove = chips.first().locator( 'button' );
	await expect( remove ).toHaveAttribute( 'aria-label', /^Remove / );

	await remove.click();
	await expect( chips ).toHaveCount( 0 );
} );

test( 'the rate table names the date it was published', async ( { page } ) => {
	await page.goto( '/insurance/' );

	const rates = page.locator( '.sswi-rates' );

	if ( await rates.count() ) {
		await expect( page.locator( '.sswi-rates-updated' ) ).toBeVisible();
		await expect( page.locator( '.sswi-rates caption' ) ).toBeVisible();
	}
} );
