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

test.describe.configure( { mode: 'serial' } );

// Each spec logs in, navigates the admin, and then deliberately waits out
// Request_Guard::MIN_AGE before submitting. The 30s default is not enough room
// for that, and running out of it reads as a hang rather than as a timeout.
test.setTimeout( 90000 );

const ADMIN = { user: 'admin', pass: 'password' };

async function login( page ) {
	await page.goto( '/wp-login.php' );

	// Already authenticated: WordPress bounces a logged-in user off wp-login.
	if ( ! ( await page.locator( '#wp-submit' ).count() ) ) {
		return;
	}

	await page.locator( '#user_login' ).fill( ADMIN.user );
	await page.locator( '#user_pass' ).fill( ADMIN.pass );

	/*
	 * DO NOT WAIT FOR A PARTICULAR URL HERE. The first admin login after a
	 * fresh activation does not land on the dashboard: WordPress may route
	 * through its database-upgrade screen, and Elementor claims the first
	 * admin request for its onboarding wizard. Waiting for /wp-admin/ timed
	 * out on the first spec in this file and passed on the two after it,
	 * which reads exactly like a product bug and was not one.
	 *
	 * Assert the outcome instead — an authenticated session — and let the
	 * redirect go wherever this WordPress and its plugins want it to.
	 */
	await Promise.all( [
		page.waitForLoadState( 'load' ),
		page.locator( '#wp-submit' ).click(),
	] );

	await page.goto( '/wp-admin/options-general.php' );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}

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
	// Leave the environment as it was found, whatever happened above.
	const page = await browser.newPage();
	await bindTo( page, '' );
	await page.close();
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

	// The stand-in upstream accepts, so recovery is visible as a real success
	// rather than merely the absence of the refusal.
	expect( message ).not.toMatch( /unavailable/ );
} );
