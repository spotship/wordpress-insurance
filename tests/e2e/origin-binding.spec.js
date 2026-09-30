/**
 * Origin binding: refusing a cloned site, and recovering from it.
 *
 * The plugin binds to the site URL it was configured on, so a copy of the site
 * carrying a copy of the key cannot submit. That is the whole mitigation for
 * the key sitting unencrypted in each customer's WordPress database — a stolen
 * or cloned database is worth nothing without a matching origin — and it is
 * also the most likely way a legitimate customer breaks their own install, by
 * copying production to staging.
 *
 * So both halves matter and both are driven here: the refusal, and the
 * one-click recovery an administrator actually clicks.
 *
 * These specs are serial. They mutate one global option, and running them
 * alongside each other would have one test's rebind satisfy another's refusal.
 */
'use strict';

const { test, expect } = require( '@playwright/test' );
const { login } = require( '../support/wp-admin.js' );

test.describe.configure( { mode: 'serial' } );

// Each spec logs in, navigates the admin, and then deliberately waits out
// Request_Guard::MIN_AGE before submitting. The 30s default is not enough room
// for that, and running out of it reads as a hang rather than as a timeout.
test.setTimeout( 90000 );

/**
 * @param {object} page Playwright page.
 * @param {string} url  Binding to record; '' rebinds to this site.
 */
async function bindTo( page, url ) {
	await login( page );
	await page.goto( `/wp-admin/?spotship_e2e_bind=${ encodeURIComponent( url ) }` );
}

async function submitForm( page ) {
	await page.goto( '/insurance/' );

	await page.locator( '[data-sswi-field="userName"] input' ).fill( 'A Visitor' );
	await page.locator( '[data-sswi-field="userEmail"] input' ).fill( 'visitor@example.test' );
	await page.locator( '[data-sswi-consent]' ).check();

	// Out-wait Request_Guard::MIN_AGE, or the decoy answers instead and the
	// assertion is about the anti-bot path rather than the binding.
	await page.waitForTimeout( 3500 );

	await page.locator( '.sswi-submit' ).click();
	await page.waitForTimeout( 900 );

	return ( await page.locator( '[data-sswi-result]' ).textContent() ).trim();
}

test.afterAll( async ( { browser } ) => {
	// Leave the environment as it was found, whatever happened above. This logs
	// in from scratch, so it needs the same room a test does — the default 30s
	// hook timeout fired during an already-failing run and buried the real
	// error under a second one.
	test.setTimeout( 90000 );

	const page = await browser.newPage();

	try {
		await bindTo( page, '' );
	} finally {
		await page.close();
	}
} );

test( 'a site bound elsewhere cannot submit', async ( { page } ) => {
	await bindTo( page, 'https://cloned-to-staging.example.test' );

	const message = ( await submitForm( page ) ).toLowerCase();

	expect( message ).not.toEqual( '' );
	expect( message ).not.toContain( 'thank' );
	expect( message ).toMatch( /unavailable|try again/ );
} );

test( 'the mismatch is surfaced to the administrator, naming the bound site', async ( { page } ) => {
	await bindTo( page, 'https://cloned-to-staging.example.test' );
	await page.goto( '/wp-admin/options-general.php?page=spotship-war-insurance' );

	const notice = page.locator( '.notice-error' ).filter( { hasText: /bound/i } );

	await expect( notice ).toBeVisible();
	await expect( notice ).toContainText( 'cloned-to-staging.example.test' );
	await expect( notice.locator( 'a' ) ).toBeVisible();
} );

test( 'one click rebinds, and submission recovers', async ( { page } ) => {
	await bindTo( page, 'https://cloned-to-staging.example.test' );
	await page.goto( '/wp-admin/options-general.php?page=spotship-war-insurance' );

	// The real notice, the real nonced link, the real admin-post handler.
	await page.locator( '.notice-error' ).filter( { hasText: /bound/i } ).locator( 'a' ).first().click();
	await page.waitForLoadState( 'networkidle' );

	await expect(
		page.locator( '.notice-error' ).filter( { hasText: /bound/i } )
	).toHaveCount( 0, { timeout: 5000 } );

	const message = ( await submitForm( page ) ).toLowerCase();

	/*
	 * The stand-in upstream accepts, so recovery is visible as a real success
	 * rather than merely the absence of the refusal.
	 *
	 * THE COMMENT ABOVE USED TO BE THE ONLY PLACE THAT WAS TRUE. The assertion
	 * under it was `not.toMatch( /unavailable/ )`, which an empty result, a
	 * validation error or a blank page all satisfy — so a re-bind that left the
	 * install unable to submit would have passed the test written to catch
	 * exactly that. Assert the success sentence itself.
	 */
	expect( message ).toContain( 'thank' );
	expect( message ).toContain( 'insurance desk' );
	expect( message ).not.toMatch( /unavailable/ );
} );
