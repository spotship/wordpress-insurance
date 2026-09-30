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
	 * WAIT FOR THE POST TO LAND, BUT NOT FOR A PARTICULAR DESTINATION.
	 *
	 * Two things went wrong here, and both presented as "the first spec in
	 * this file fails and the rest are fine":
	 *
	 * 1. Waiting for a URL matching /wp-admin/ assumed the login lands on the
	 *    dashboard. It need not — a freshly activated Elementor claims the
	 *    first admin request for its onboarding wizard.
	 * 2. Replacing that with waitForLoadState( 'load' ) was worse: the page is
	 *    ALREADY loaded when the click is issued, so it resolved immediately,
	 *    and the goto() below then raced the in-flight login POST and
	 *    cancelled it. The session was never established, wp-admin bounced
	 *    back to the login form, and the missing admin bar looked like a
	 *    rendering fault.
	 *
	 * Waiting to LEAVE wp-login.php is the condition that actually means the
	 * credentials were accepted, and it says nothing about where WordPress or
	 * its plugins choose to go next.
	 */
	const submitted = page.waitForURL(
		( url ) => ! url.pathname.endsWith( 'wp-login.php' ),
		{ timeout: 30000 }
	);

	await page.locator( '#wp-submit' ).click();
	await submitted;

	await page.goto( '/wp-admin/options-general.php' );

	await expect(
		page.locator( '#wpadminbar' ),
		'not authenticated: wp-admin served no admin bar, which means it served the login form'
	).toBeVisible();
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
