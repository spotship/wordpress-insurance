/**
 * The inert -> live transition.
 *
 * AC: the form renders disabled and JavaScript enables it. If initialisation
 * never happens the visitor is told, rather than shown a form that looks fine
 * and silently is not.
 */
'use strict';

const { test, expect } = require( '@playwright/test' );

test( 'the form is usable once the script has run', async ( { page } ) => {
	await page.goto( '/insurance/' );

	await expect( page.locator( '[data-sswi-root]' ) ).toBeVisible();
	await expect( page.locator( '.sswi-submit' ) ).toBeEnabled();
	await expect( page.locator( '[data-sswi-noscript]' ) ).toHaveCount( 0 );
} );

test( 'the plugin sets no cookies', async ( { page, context } ) => {
	await page.goto( '/insurance/' );
	await page.waitForLoadState( 'networkidle' );

	const ours = ( await context.cookies() ).filter( ( c ) => c.name.toLowerCase().includes( 'sswi' ) );

	// The privacy claim in readme.txt depends on this being true, so it is
	// asserted rather than assumed.
	expect( ours ).toEqual( [] );
} );
