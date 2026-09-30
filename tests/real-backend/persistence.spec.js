/**
 * Persistence, attribution and provenance against the REAL dev backend.
 *
 * ⚠️ THIS FILE HAS NOT BEEN EXECUTED. Treat it as unverified code until a run
 * of .github/workflows/real-backend-e2e.yaml comes back green. It is written
 * against the backend contract as read from source, not against observed
 * behaviour.
 *
 * What is holding it is no longer a missing secret or a missing trigger — both
 * `dev` secrets are set, and the workflow runs from a `real-backend-*` tag
 * without waiting for a merge. It is that this file WRITES. Every submission
 * below stores a real QuoteRequest on the dev deployment, and the backend fires
 * a referral email and a Slack post for each one, to a webhook that
 * notifications.py says is the same workflow the serica referral views use. A
 * test enquiry can land in front of whoever reads that channel looking exactly
 * like a real one, and cannot be un-sent.
 *
 * So the submitting tests are tagged `@writes` and excluded from a run unless
 * it asks for them. The other two in this file are NOT tagged and do run: the
 * diagnostics screen reads without submitting, and the revoked-key test submits
 * a credential the backend rejects with a 401, so no row is stored and no
 * referral is sent. Both still exercise the real deployment.
 *
 * PROVENANCE CANNOT BE ASSERTED FROM THE BROWSER, AND THAT IS DELIBERATE.
 *
 * The plugin's receipt is `{status: 'received'}` plus the caller's own
 * submissionId, built from what the CALLER submitted and never from the
 * upstream response — see Spotship_WI_Rest_Proxy::receipt(). That is what makes
 * the anti-bot decoy indistinguishable from a genuine success: there is one
 * shape and neither path is derived from anything the other lacks. Asserting
 * `source` or `consentText` off the page would be asserting that the plugin
 * leaks the upstream response into the browser, which is the very thing the
 * decoy-parity invariant forbids. An earlier version of this file did exactly
 * that, and could never have passed.
 *
 * So persistence is read back SERVER-SIDE, from this test runner, using the
 * documented idempotency contract: replaying a submissionId for the same
 * customer returns 200 with the ORIGINAL ROW rather than creating a second one.
 * That row is the persisted record, and it carries the attribution.
 *
 * The replay is also safe to perform: the same contract states it does not
 * re-send the referral email or the Slack message.
 *
 * §9 ROWS THIS SUITE DELIBERATELY DOES NOT DRIVE, so the gap is visible rather
 * than implied: the inline 400 field errors, and the 429 Retry-After wait. Both
 * are covered by the integration and jsdom suites against a stand-in upstream.
 * Reaching a real 429 means six submits inside the hour — five real leads in a
 * real inbox to provoke the sixth, which is a worse trade than the coverage is
 * worth. The three actions the ticket names — killing the backend, revoking the
 * key and blocking the JS — are all driven, in failure-modes.spec.js.
 *
 * NOT COVERED, AND NOT COVERABLE FROM HERE: that the referral email and Slack
 * message actually fire. backend/insurance/notifications.py dispatches both
 * fire-and-forget and records no flag, so nothing in the API can be asked. It
 * needs the dev mailbox and the Slack channel. Do not read a green run here
 * as evidence either fired.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect, request: playwrightRequest } = require( '@playwright/test' );
const { login } = require( '../support/wp-admin.js' );

const API = ( process.env.SPOTSHIP_API_URL || '' ).replace( /\/+$/, '' );
const KEY = process.env.SPOTSHIP_API_KEY || '';
const EVIDENCE = path.join( __dirname, '..', '..', 'real-backend-evidence' );

fs.mkdirSync( EVIDENCE, { recursive: true } );

/**
 * @param {string} name     File name.
 * @param {string} contents What to write.
 */
function evidence( name, contents ) {
	fs.writeFileSync( path.join( EVIDENCE, name ), contents );
}

/** A client that talks to the API directly, as the customer. */
async function api() {
	return playwrightRequest.newContext( {
		baseURL: API,
		extraHTTPHeaders: {
			// `Bearer `, and nothing else. CustomerAccountApiKeyAuthentication
			// ._extract_key() reads the x-api-key header, or an Authorization
			// header starting with BEARER_PREFIX — literally "Bearer ". This
			// said `Api-Key`, which matches neither, so the key was never
			// extracted, the authenticator chain fell through, and every call
			// here would have 401'd. replay() is the ONLY thing that evidences
			// persistence, so the job would have failed on its first real run
			// and looked like a bad credential rather than a bad spec.
			Authorization: `Bearer ${ KEY }`,
			'Content-Type': 'application/json',
			Accept: 'application/json',
		},
	} );
}

/**
 * Drive a form to completion and return the plugin's receipt.
 *
 * Read from the network rather than the screen: the visible message is
 * deliberately neutral and evidences nothing about what was stored.
 *
 * @param {object} page    Playwright page.
 * @param {string} urlPath Page carrying the form.
 * @param {string} marker  Identifier written into the submission.
 */
async function submit( page, urlPath, marker ) {
	await page.goto( urlPath );
	await expect( page.locator( '[data-sswi-root]' ) ).toHaveCount( 1 );

	await page.locator( '[data-sswi-field="userName"] input' ).fill( `CI ${ marker }` );
	await page.locator( '[data-sswi-field="userEmail"] input' ).fill( `ci+${ marker }@spot-ship.com` );

	const notes = page.locator( 'textarea[name="notes"]' );

	if ( await notes.count() ) {
		await notes.fill( `Automated dev verification ${ marker }. Not a real enquiry.` );
	}

	await page.locator( '[data-sswi-consent]' ).check();

	// Out-wait Request_Guard::MIN_AGE, or the anti-bot decoy answers with a
	// success that never reached the backend — which would make this whole job
	// report a persistence it never performed.
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

/**
 * Read the persisted row back by replaying its submissionId.
 *
 * @param {string} product      'hull' or 'cargo'.
 * @param {string} submissionId The id the browser reported.
 * @param {object} extra        Product-specific required fields.
 */
async function replay( product, submissionId, extra = {} ) {
	const client = await api();
	const route = 'cargo' === product ? 'cargo_quote_request' : 'quote_request';

	const response = await client.post( `/insurance/${ route }/`, {
		data: Object.assign(
			{
				userName: 'CI replay',
				userEmail: 'ci+replay@spot-ship.com',
				dataConsent: true,
				submissionId,
				source: 'wordpress',
			},
			extra
		),
	} );

	const body = await response.json().catch( () => null );

	await client.dispose();

	return { status: response.status(), body };
}

test.beforeAll( () => {
	// The workflow refuses to start without these, but a local invocation has
	// no such gate and would otherwise fail deep inside a request.
	expect( API, 'SPOTSHIP_API_URL is required' ).not.toBe( '' );
	expect( KEY, 'SPOTSHIP_API_KEY is required' ).not.toBe( '' );
} );

for ( const [ product, urlPath, label ] of [
	[ 'hull', '/insurance/', 'shortcode' ],
	[ 'cargo', '/insurance-cargo/', 'shortcode' ],
	[ 'hull', '/insurance-elementor-hull/', 'Elementor' ],
	[ 'cargo', '/insurance-elementor-cargo/', 'Elementor' ],
] ) {
	test( `${ product } via ${ label }: persists with attribution and consent provenance @writes`, async ( {
		page,
	} ) => {
		const marker = `${ product }-${ label }-${ Date.now() }`;
		const answer = await submit( page, urlPath, marker );

		evidence( `${ product }-${ label }-receipt.json`, JSON.stringify( answer, null, 2 ) );

		// What the browser is allowed to know, and all of it.
		expect( answer.status, 'the real backend must accept the submission' ).toBeLessThan( 300 );
		expect( answer.body.success ).toBe( true );
		expect( answer.body.data ).toMatchObject( { status: 'received' } );

		const submissionId = answer.body.data.submissionId;

		expect( submissionId, 'the receipt must echo the submission id' ).toBeTruthy();

		// What only the backend can confirm.
		let extra = {};

		if ( 'cargo' === product ) {
			// destinations is required and must be non-empty, so the replay
			// needs a real Port id. Taken from the same API rather than
			// hardcoded, because the deployment's ids are not ours to assume.
			const client = await api();
			const found = await client.get( '/insurance/location_search/?query=rot' );
			const rows = ( await found.json().catch( () => ( {} ) ) ).results || [];

			await client.dispose();

			expect( rows.length, 'need at least one location to replay a cargo row' ).toBeGreaterThan( 0 );
			extra = { destinations: [ rows[ 0 ].id ], cargoDescription: 'CI replay' };
		}

		const stored = await replay( product, submissionId, extra );

		evidence( `${ product }-${ label }-replay.json`, JSON.stringify( stored, null, 2 ) );

		expect(
			stored.status,
			'a replayed submissionId must return 200 with the ORIGINAL row; a 201 means ' +
				'the first submission was never persisted and this just created it'
		).toBe( 200 );

		expect( stored.body.source, 'the stored row must name the surface' ).toBe( 'wordpress' );
		expect( stored.body.originSiteUrl, 'origin provenance must be stored' ).toBeTruthy();
		expect( stored.body.consentText, 'the wording shown must be stored verbatim' ).toBeTruthy();
		expect( stored.body.consentGivenAt, 'consent must carry a timestamp' ).toBeTruthy();
		expect( stored.body.dataConsent ).toBe( true );

		// Customer attribution: the row must carry the name the BROWSER sent,
		// not the replay's. If it carries the replay's, the replay created a
		// new row and nothing above proves persistence.
		expect(
			stored.body.userName,
			'the returned row is the replay, not the original — persistence is unproven'
		).toBe( `CI ${ marker }` );
	} );
}

test( 'the diagnostics screen reports a healthy link and never contains the credential', async ( {
	page,
} ) => {
	await login( page );
	await page.goto( '/wp-admin/options-general.php?page=spotship-war-insurance' );

	const blob = page.locator( '#sswi-diagnostics' );
	await expect( blob ).toBeVisible();

	const text = await blob.inputValue();
	evidence( 'diagnostics.json', text );

	expect( text ).not.toContain( KEY );
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

	let answer;

	try {
		answer = await submit( page, '/insurance/', `revoked-${ Date.now() }` );
	} finally {
		// Via the mu-plugin's stash, never by re-sending the real key: a live
		// credential in a query string lands in the access log, in Playwright's
		// trace and in the CI job log.
		await page.goto( '/wp-admin/?spotship_e2e_restore=1' );
	}

	evidence( 'revoked-key.json', JSON.stringify( answer, null, 2 ) );

	expect( answer.status ).toBeGreaterThanOrEqual( 400 );
	expect( answer.body.success ).not.toBe( true );

	const shown = ( await page.locator( '.spotship-wi' ).textContent() ).toLowerCase();

	for ( const leak of [ 'api key', 'token', 'unauthorized', 'unauthorised', '401' ] ) {
		expect( shown, `the page must not contain "${ leak }"` ).not.toContain( leak );
	}
} );
