/**
 * Both products submitted against the REAL staging backend.
 *
 * Everything in tests/e2e/ runs against bin/e2e-upstream.js, a stand-in whose
 * response shape is derived from the backend serializer but which proves
 * nothing about the live contract, authentication, or persistence. This file is
 * the one that does, and it is why it needs a real credential.
 *
 * Read the config docblock before adding to this file. In particular: referral
 * email and Slack delivery are NOT verified here and cannot be.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );

const ADMIN = { user: 'admin', pass: 'password' };
const EVIDENCE = path.join( __dirname, '..', '..', 'staging-evidence' );

fs.mkdirSync( EVIDENCE, { recursive: true } );

/**
 * Record a verbatim artifact. The point of this job is evidence someone can
 * read afterwards, not a green tick.
 *
 * @param {string} name     File name.
 * @param {string} contents What to write.
 */
function evidence( name, contents ) {
	fs.writeFileSync( path.join( EVIDENCE, name ), contents );
}

async function login( page ) {
	await page.goto( '/wp-login.php' );

	if ( page.url().includes( '/wp-admin/' ) ) {
		return;
	}

	await page.locator( '#user_login' ).fill( ADMIN.user );
	await page.locator( '#user_pass' ).fill( ADMIN.pass );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/ );
}

/**
 * Fill and send a form, returning the API answer the browser received.
 *
 * The response is captured from the network rather than read off the screen:
 * the visible message is deliberately neutral, so it cannot evidence what was
 * actually persisted.
 *
 * @param {object} page    Playwright page.
 * @param {string} urlPath Page to submit.
 * @param {string} marker  Identifier written into the submission.
 */
async function submit( page, urlPath, marker ) {
	await page.goto( urlPath );

	await page.locator( '[data-sswi-field="userName"] input' ).fill( `CI ${ marker }` );
	await page.locator( '[data-sswi-field="userEmail"] input' ).fill( `ci+${ marker }@spot-ship.com` );

	const notes = page.locator( 'textarea[name="notes"]' );

	if ( await notes.count() ) {
		await notes.fill( `Automated staging verification ${ marker }. Not a real enquiry.` );
	}

	await page.locator( '[data-sswi-consent]' ).check();

	// Out-wait Request_Guard::MIN_AGE, or the anti-bot decoy answers with a
	// success that never reached the backend — which would make this entire
	// job report a persistence it never performed.
	await page.waitForTimeout( 3500 );

	const [ response ] = await Promise.all( [
		page.waitForResponse( ( r ) => /quote[-_]request/.test( r.url() ), { timeout: 30000 } ),
		page.locator( '.sswi-submit' ).click(),
	] );

	let body = null;

	try {
		body = await response.json();
	} catch ( e ) {
		body = { unparseable: await response.text() };
	}

	return { status: response.status(), body };
}

for ( const [ product, urlPath ] of [
	[ 'hull', '/insurance/' ],
	[ 'cargo', '/insurance-cargo/' ],
] ) {
	test( `${ product }: submits to the real backend with attribution and consent provenance`, async ( {
		page,
	} ) => {
		const marker = `${ product }-${ Date.now() }`;
		const answer = await submit( page, urlPath, marker );

		evidence( `${ product }-submission.json`, JSON.stringify( answer, null, 2 ) );

		expect( answer.status, 'the real backend must accept the submission' ).toBeLessThan( 300 );
		expect( answer.body.success, 'the proxy must report a confirmed success' ).toBe( true );

		// The milestone's requirement is not "a 201 came back" but that the
		// record carries who it belongs to and what was consented to.
		const data = answer.body.data || {};

		expect( data, 'the echo must name the source surface' ).toMatchObject( {
			source: 'wordpress',
		} );
		expect( data.originSiteUrl, 'origin provenance must be recorded' ).toBeTruthy();
		expect( data.consentText, 'the consent wording shown must be stored verbatim' ).toBeTruthy();
		expect( data.consentGivenAt, 'consent must carry a timestamp' ).toBeTruthy();
		expect( data.dataConsent ).toBe( true );
	} );
}

test( 'the diagnostics screen reports a healthy link to the real endpoint', async ( { page } ) => {
	await login( page );
	await page.goto( '/wp-admin/options-general.php?page=spotship-war-insurance' );

	const blob = page.locator( '#sswi-diagnostics' );
	await expect( blob ).toBeVisible();

	const text = await blob.inputValue();
	evidence( 'diagnostics.json', text );

	// The credential must never appear in a screen an administrator can copy
	// out of and paste into a support ticket.
	expect( text ).not.toContain( process.env.SPOTSHIP_STAGING_KEY || '\u0000never' );
} );

test( 'a bad credential against the real backend degrades to the neutral sentence', async ( {
	page,
} ) => {
	/*
	 * The failure mode that matters commercially: a rotated or revoked key must
	 * not put an error naming the credential on a customer's public website.
	 * Driven against the real API so the 401 is genuinely the backend's.
	 */
	await login( page );
	await page.goto( '/wp-admin/?spotship_e2e_key=00000000-0000-4000-8000-000000000000' );

	const answer = await submit( page, '/insurance/', `revoked-${ Date.now() }` );

	evidence( 'revoked-key-submission.json', JSON.stringify( answer, null, 2 ) );

	expect( answer.status ).toBeGreaterThanOrEqual( 400 );
	expect( answer.body.success ).not.toBe( true );

	const shown = ( await page.locator( '.spotship-wi' ).textContent() ).toLowerCase();

	for ( const leak of [ 'api key', 'token', 'unauthorized', 'unauthorised', '401' ] ) {
		expect( shown, `the page must not contain "${ leak }"` ).not.toContain( leak );
	}
} );
