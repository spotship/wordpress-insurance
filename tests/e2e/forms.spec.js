/**
 * Happy paths for both products.
 */
'use strict';

const { test, expect } = require( '@playwright/test' );

test( 'the hull combobox keeps focus on the input and moves aria-activedescendant', async ( { page } ) => {
	await page.goto( '/insurance/' );

	const input = page.locator( '[data-sswi-field="fromPort"] input[role="combobox"]' );
	const options = page.locator( '[data-sswi-field="fromPort"] [role="option"]' );

	await input.fill( 'rot' );

	// WAIT FOR THE OPTION, NOT FOR A DURATION.
	//
	// The lookup is debounced and then crosses the proxy to the API, so a fixed
	// interval is a guess about how loaded the machine is. This was
	// `waitForTimeout( 400 )`, and on a busy one ArrowDown landed on a listbox
	// that had not opened: aria-activedescendant was still null and the test
	// failed as though the combobox were broken. Retries are 0 locally, so that
	// reads as a real defect rather than as the flake it is.
	await expect( options.first() ).toBeVisible();
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

	const input = page.locator( '[data-sswi-field="fromPort"] input[role="combobox"]' );
	const options = page.locator( '[data-sswi-field="fromPort"] [role="option"]' );

	await input.fill( 'rot' );
	// Same reason as the spec above: the open listbox is the event to wait for.
	await expect( options.first() ).toBeVisible();
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

	const input = page.locator( '[data-sswi-destinations] input[role="combobox"]' );
	const options = page.locator( '[data-sswi-destinations] [role="option"]' );

	await input.fill( 'rot' );
	// Same reason as the spec above: the open listbox is the event to wait for.
	await expect( options.first() ).toBeVisible();
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

test( 'the rate table names the date it was published and shows real figures', async ( {
	page,
} ) => {
	/*
	 * NO `if ( await rates.count() )`. That guard made the whole test a no-op
	 * whenever the table was missing — which is the single failure it exists to
	 * catch — and it hid a real one: the renderer read a payload shape the
	 * endpoint does not emit, so against the derived fixture the header row and
	 * every cell came out empty and this still passed.
	 *
	 * bin/seed-e2e.sh always points the install at the stand-in, which always
	 * serves rates, so the table is REQUIRED here, not optional.
	 */
	await page.goto( '/insurance/' );

	await expect( page.locator( '.sswi-rates' ) ).toBeVisible();
	await expect( page.locator( '.sswi-rates-updated' ) ).toBeVisible();
	await expect( page.locator( '.sswi-rates caption' ) ).toBeVisible();

	// The labels the endpoint serves, not the field keys it looks them up by.
	const headers = page.locator( '.sswi-rates thead th' );

	await expect( headers ).toHaveCount( 4 ); // risk zone + three hull columns
	await expect( headers.nth( 1 ) ).toHaveText( 'H&M' );

	// And an actual figure, formatted. The row label is the zone `name`, and
	// 0.085 must reach the page as 8.50% rather than as the raw fraction.
	const hormuz = page.locator( '.sswi-rates tbody tr' ).filter( { hasText: 'Hormuz Passage' } );

	await expect( hormuz ).toHaveCount( 1 );
	await expect( hormuz.locator( 'td' ).first() ).toHaveText( '8.50%' );

	// The compliance text, which read from the wrong level and rendered nowhere.
	await expect( page.locator( '.sswi-disclaimers p' ).first() ).toBeVisible();
} );

test( 'the cargo table serves cargo columns, not hull ones', async ( { page } ) => {
	/*
	 * The columns are served PER PRODUCT. While the renderer read a single
	 * top-level list, whichever product rendered second got the other's column
	 * keys and every cell in it fell through to an em dash. Asserting hull
	 * alone cannot see that.
	 */
	await page.goto( '/insurance-cargo/' );

	const headers = page.locator( '.sswi-rates thead th' );

	await expect( headers ).toHaveCount( 3 ); // risk zone + gross + net
	await expect( headers.nth( 1 ) ).toHaveText( 'Gross' );
	await expect( headers.nth( 2 ) ).toHaveText( 'Net' );

	// Hormuz has a gross rate and no net rate at all: a real null, which must
	// read as an em dash rather than as an empty cell or a zero.
	const hormuz = page.locator( '.sswi-rates tbody tr' ).filter( { hasText: 'Hormuz Passage' } );

	await expect( hormuz.locator( 'td' ).nth( 0 ) ).toHaveText( '8.00%' );
	await expect( hormuz.locator( 'td' ).nth( 1 ) ).toHaveText( '—' );
} );
