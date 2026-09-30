/**
 * What the visitor sees when the backend is gone or the key is dead.
 *
 * The milestone's governing rule is "never claim success the backend did not
 * confirm". These specs drive the two ways that promise gets broken in
 * production — the API being unreachable, and the customer's key being revoked
 * or rotated — and assert the form refuses rather than pretends.
 *
 * Both faults are injected at the UPSTREAM, not with Playwright's network
 * interception. The proxy is server-side: the browser never contacts the API,
 * so there is no request in the page to intercept, and a browser-level fake
 * would prove nothing about the PHP that actually handles the failure.
 */
'use strict';

const { test, expect } = require( '@playwright/test' );

const UPSTREAM = process.env.SPOTSHIP_UPSTREAM_HOST || 'http://127.0.0.1:8080';

async function fault( request, mode ) {
	const response = await request.get( `${ UPSTREAM }/__fault?mode=${ mode }` );
	expect( response.ok() ).toBeTruthy();
}

test.afterEach( async ( { request } ) => {
	await fault( request, '' );
} );

test( 'with the backend down, a submission is refused rather than reported as sent', async ( {
	page,
	request,
} ) => {
	await fault( request, 'down' );
	await page.goto( '/insurance/' );

	await page.locator( '[data-sswi-field="userName"] input' ).fill( 'A Visitor' );
	await page.locator( '[data-sswi-field="userEmail"] input' ).fill( 'visitor@example.test' );
	await page.locator( '[data-sswi-consent]' ).check();

	/*
	 * WAIT OUT THE MINIMUM FORM AGE FIRST. A submission younger than
	 * Request_Guard::MIN_AGE is treated as a bot and answered with the decoy —
	 * a 200 built by the same receipt() call as a real success, byte-identical
	 * to one, that sends nothing upstream. Submitting immediately would make
	 * these specs assert against the decoy and pass or fail for reasons that
	 * have nothing to do with the injected fault.
	 */
	await page.waitForTimeout( 3500 );

	await page.locator( '.sswi-submit' ).click();
	await page.waitForTimeout( 800 );

	const result = page.locator( '[data-sswi-result]' );
	await expect( result ).toBeVisible();

	const text = ( await result.textContent() ).toLowerCase();

	// The promise: no success wording may appear when nothing was stored.
	expect( text ).not.toContain( 'thank' );
	expect( text ).not.toContain( 'received' );
	expect( text ).toMatch( /unavailable|try again/ );
} );

test( 'with the backend down, a broken search blocks submission instead of reading as no matches', async ( {
	page,
	request,
} ) => {
	await fault( request, 'down' );
	await page.goto( '/insurance/' );

	await page.locator( '[data-sswi-field="fromPort"] input[role="combobox"]' ).fill( 'rot' );
	await page.waitForTimeout( 700 );

	await expect( page.locator( '.sswi-submit' ) ).toBeDisabled();
} );

test( 'a revoked key produces the neutral sentence and never names the credential', async ( {
	page,
	request,
} ) => {
	/*
	 * A 401 from upstream must be indistinguishable to the visitor from any
	 * other outage. Leaking "key", "token", "401" or "unauthorized" onto a
	 * customer's public website both confuses the visitor and tells a prober
	 * exactly what state the credential is in.
	 */
	await fault( request, 'revoked' );
	await page.goto( '/insurance/' );

	await page.locator( '[data-sswi-field="userName"] input' ).fill( 'A Visitor' );
	await page.locator( '[data-sswi-field="userEmail"] input' ).fill( 'visitor@example.test' );
	await page.locator( '[data-sswi-consent]' ).check();

	/*
	 * WAIT OUT THE MINIMUM FORM AGE FIRST. A submission younger than
	 * Request_Guard::MIN_AGE is treated as a bot and answered with the decoy —
	 * a 200 built by the same receipt() call as a real success, byte-identical
	 * to one, that sends nothing upstream. Submitting immediately would make
	 * these specs assert against the decoy and pass or fail for reasons that
	 * have nothing to do with the injected fault.
	 */
	await page.waitForTimeout( 3500 );

	await page.locator( '.sswi-submit' ).click();
	await page.waitForTimeout( 800 );

	const body = ( await page.locator( '.spotship-wi' ).textContent() ).toLowerCase();

	for ( const leak of [ 'api key', 'token', 'unauthorized', 'unauthorised', '401', 'invalid token' ] ) {
		expect( body, `the page must not contain "${ leak }"` ).not.toContain( leak );
	}

	await expect( page.locator( '[data-sswi-result]' ) ).toContainText( /unavailable|try again/i );
} );
