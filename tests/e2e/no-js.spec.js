/**
 * With JavaScript disabled the form must be visibly unusable and say so.
 *
 * The failure this guards against is a form that looks live, accepts typing and
 * silently sends nothing — the same class of lie as a false success.
 */
'use strict';

const { test, expect } = require( '@playwright/test' );

test.use( { javaScriptEnabled: false } );

test( 'the form stays inert and explains itself without JavaScript', async ( { page } ) => {
	await page.goto( '/insurance/' );

	await expect( page.locator( '[data-sswi-noscript]' ) ).toBeVisible();
	await expect( page.locator( '.sswi-submit' ) ).toBeDisabled();
	await expect( page.locator( '[data-sswi-field="userName"] input' ) ).toBeDisabled();
} );
