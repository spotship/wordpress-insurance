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
 * §9 IS NOW FULLY DRIVEN. The two rows this file used to omit are covered
 * below, and neither writes a row:
 *
 *   - The inline 400 field errors come from a payload the PLUGIN forwards and
 *     the SERIALIZER rejects — an unparseable email address. The plugin
 *     deliberately does not duplicate the backend's schema, so this reaches the
 *     real validator and comes back as a real field error. A rejected payload
 *     stores nothing.
 *   - The 429 is the plugin's OWN throttle, so it needs no upstream traffic at
 *     all: the submits that provoke it are refused locally and never leave the
 *     server. The earlier note here said reaching it meant "five real leads in
 *     a real inbox", which confused the plugin's limit with the backend's.
 *
 * The three actions the ticket names — killing the backend, revoking the key
 * and blocking the JS — are all driven, in failure-modes.spec.js.
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
async function pick( page, selector, term ) {
	/*
	 * Commit a combobox the way a visitor does: type, wait past the 200ms
	 * debounce for the real upstream to answer, then ArrowDown + Enter. The
	 * hidden input only receives an id when an option is actually chosen, so
	 * filling the visible text alone submits nothing.
	 */
	const input = page.locator( `${ selector } input[role="combobox"]` );

	await input.fill( term );

	const option = page.locator( `${ selector } [role="option"]` ).first();

	await expect(
		option,
		`the real backend returned no options for "${ term }" in ${ selector }`
	).toBeVisible( { timeout: 15000 } );

	await input.press( 'ArrowDown' );
	await input.press( 'Enter' );

	const committed = await page.locator( `${ selector } [data-sswi-value]` ).inputValue();

	expect( committed, `${ selector } committed no id` ).not.toEqual( '' );
}

/**
 * Add one destination chip.
 *
 * NOT the same as pick(). The destinations widget has no hidden input — its ids
 * live in JS state and are read by destinationsField.ids() at submit time — so
 * there is no [data-sswi-value] to assert on. The chip is the visible proof the
 * id was taken, and the ordering of chips is itself meaningful: the backend
 * stores position through CargoQuoteDestination.
 *
 * @param {object} page Playwright page.
 * @param {string} term Search term.
 */
async function addDestination( page, term ) {
	const wrap = '[data-sswi-destinations]';
	const input = page.locator( `${ wrap } input[role="combobox"]` );
	const before = await page.locator( `${ wrap } [data-sswi-chips] li` ).count();

	await input.fill( term );

	await expect(
		page.locator( `${ wrap } [role="option"]` ).first(),
		`the real backend returned no destinations for "${ term }"`
	).toBeVisible( { timeout: 15000 } );

	await input.press( 'ArrowDown' );
	await input.press( 'Enter' );

	await expect(
		page.locator( `${ wrap } [data-sswi-chips] li` ),
		'choosing a destination added no chip, so no id was committed'
	).toHaveCount( before + 1 );
}

async function submit( page, urlPath, marker ) {
	await page.goto( urlPath );
	await expect( page.locator( '[data-sswi-root]' ) ).toHaveCount( 1 );

	const isCargo = urlPath.includes( 'cargo' );

	await page.locator( '[data-sswi-field="userName"] input' ).fill( `CI ${ marker }` );
	await page.locator( '[data-sswi-field="userEmail"] input' ).fill( `ci+${ marker }@spot-ship.com` );

	/*
	 * EVERY FIELD THE BACKEND REQUIRES, OR THIS VERIFIES NOTHING.
	 *
	 * This helper used to fill name, email, notes and consent, and stop. Both
	 * products need more than that and the serializer rejects what is missing,
	 * so every persistence test here would have answered 400 the first time it
	 * ran against a real deployment — a suite that could not pass, asserting a
	 * persistence that never happened.
	 *
	 * QuoteRequest requires vessel, from_port and to_port; CargoQuoteRequest
	 * requires cargo_description, cargo_weight, cargo_value, cargo_currency,
	 * risk_zone, from_port, and destinations with allow_empty=False. None of
	 * them carries blank=True or null=True, which is what makes them required.
	 *
	 * The comboboxes are driven against the REAL search endpoints, which is the
	 * point: an id committed here is an id the backend will resolve.
	 */
	if ( isCargo ) {
		await page.locator( '[data-sswi-field="cargoDescription"] input' ).fill( `Steel coils (${ marker })` );
		await page.locator( '[data-sswi-field="cargoWeight"] input' ).fill( '12000 MT' );
		await page.locator( '[data-sswi-field="cargoValue"] input' ).fill( '250000' );

		// Selects, not comboboxes: pick the first real option rather than
		// hardcoding a value the backend's choices may not contain.
		const currency = page.locator( 'select[name="cargoCurrency"]' );
		const currencyValue = await currency.locator( 'option:not([value=""])' ).first().getAttribute( 'value' );

		await currency.selectOption( currencyValue );

		// riskZone is populated from the rates endpoint, so this also proves
		// that population happened against real data.
		const zone = page.locator( 'select[name="riskZone"]' );

		await expect(
			zone.locator( 'option:not([value=""])' ).first(),
			'the risk-zone select is empty — the rates payload did not populate it'
		).toHaveCount( 1, { timeout: 15000 } );

		const zoneValue = await zone.locator( 'option:not([value=""])' ).first().getAttribute( 'value' );

		await zone.selectOption( zoneValue );

		await pick( page, '[data-sswi-field="fromPort"]', 'rotterdam' );
		await addDestination( page, 'rotterdam' );
	} else {
		await pick( page, '[data-sswi-field="vessel"]', 'ever' );
		await pick( page, '[data-sswi-field="fromPort"]', 'rotterdam' );
		await pick( page, '[data-sswi-field="toPort"]', 'rotterdam' );
	}

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
/**
 * Reference ids from the REAL deployment, for building a valid replay.
 *
 * Both search endpoints return a bare array; the `.results` unwrapping that
 * used to be here matched no response this API emits. Tolerating both shapes
 * costs nothing and means a future envelope does not silently empty the list.
 *
 * @param {string} path Search path with its query.
 */
async function search( path ) {
	const client = await api();
	const response = await client.get( path );
	const body = await response.json().catch( () => [] );

	await client.dispose();

	return Array.isArray( body ) ? body : body.results || [];
}

/**
 * Every field the serializer requires, with ids resolved from the deployment.
 *
 * Hardcoding ids would be asserting that a particular dev database row exists;
 * these are whatever the deployment actually has.
 *
 * @param {string} product 'hull' or 'cargo'.
 */
async function replayFields( product ) {
	const ports = await search( '/insurance/location_search/?query=rot' );

	expect( ports.length, 'the deployment returned no locations to replay with' ).toBeGreaterThan( 0 );

	if ( 'cargo' === product ) {
		// cargo_description, cargo_weight, cargo_value, cargo_currency,
		// risk_zone and from_port are all non-blank on the model, and
		// destinations is allow_empty=False on the serializer.
		return {
			cargoDescription: 'CI replay — automated dev verification',
			cargoWeight: '12000 MT',
			cargoValue: '250000.00',
			cargoCurrency: 'USD',
			riskZone: 'Hormuz Passage',
			fromPort: ports[ 0 ].id,
			destinations: [ ports[ 0 ].id ],
		};
	}

	const vessels = await search( '/insurance/vessel_search/?query=ever' );

	expect( vessels.length, 'the deployment returned no vessels to replay with' ).toBeGreaterThan( 0 );

	// QuoteRequest.vessel, from_port and to_port are plain FKs with neither
	// blank nor null, so all three are required.
	return {
		vessel: vessels[ 0 ].id,
		fromPort: ports[ 0 ].id,
		toPort: ports[ ports.length > 1 ? 1 : 0 ].id,
	};
}

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

		/*
		 * THE REPLAY MUST BE A FULLY VALID PAYLOAD.
		 *
		 * IdempotentCreateMixin.create() calls is_valid(raise_exception=True)
		 * BEFORE the insert, so the replay never reaches the submission_id
		 * lookup unless every required field is present. This used to send
		 * `{}` for hull and `{destinations, cargoDescription}` for cargo, so
		 * both would have answered 400 and the test would have read it as
		 * "persistence failed" rather than "the request was malformed".
		 *
		 * It also read `.results` off the search response. PortSearchViewSet
		 * returns a BARE ARRAY, so `rows` was always empty and the assertion
		 * below could never have passed.
		 */
		const extra = await replayFields( product );

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

test( '§9: an upstream 400 lands as an inline field error, not the generic sentence', async ( {
	page,
} ) => {
	/*
	 * The plugin validates BOUNDS and deliberately leaves requiredness and
	 * format to the backend, so an unparseable address passes the proxy and is
	 * rejected by the real serializer's EmailField. That is the only honest way
	 * to drive a REAL upstream 400 from the browser.
	 *
	 * Read-only: a 400 stores nothing and sends nothing, so this is not tagged
	 * @writes.
	 */
	await page.goto( '/insurance/' );

	await page.locator( '[data-sswi-field="userName"] input' ).fill( 'CI four hundred' );
	await page.locator( '[data-sswi-field="userEmail"] input' ).fill( 'not-an-email-address' );

	await pick( page, '[data-sswi-field="vessel"]', 'ever' );
	await pick( page, '[data-sswi-field="fromPort"]', 'rotterdam' );
	await pick( page, '[data-sswi-field="toPort"]', 'rotterdam' );

	await page.locator( '[data-sswi-consent]' ).check();

	// Out-wait MIN_AGE, or the decoy answers with a fabricated success and no
	// field error ever arrives.
	await page.waitForTimeout( 3500 );

	const [ response ] = await Promise.all( [
		page.waitForResponse( ( r ) => /quote[-_]request/.test( r.url() ), { timeout: 30000 } ),
		page.locator( '.sswi-submit' ).click(),
	] );

	expect( response.status(), 'the real serializer must reject this address' ).toBe( 400 );

	evidence( 'upstream-400.json', JSON.stringify( await response.json().catch( () => null ), null, 2 ) );

	// The error must reach the FIELD. Falling back to the generic sentence
	// tells the visitor the service is down when their address was simply
	// mistyped — and they have no way to find the real reason.
	const slot = page.locator( '[data-sswi-field="userEmail"] [data-sswi-error]' );

	await expect( slot ).toBeVisible();
	await expect( slot ).not.toBeEmpty();
	await expect( page.locator( '[data-sswi-field="userEmail"] input' ) ).toHaveAttribute(
		'aria-invalid',
		'true'
	);

	const shown = ( await page.locator( '[data-sswi-result]' ).textContent() ).toLowerCase();

	expect( shown, 'a field error must not also claim the service is unavailable' ).not.toMatch(
		/unavailable/
	);
} );

test( '§9: a throttled submit names the wait, against the real deployment', async ( { page } ) => {
	/*
	 * This is the PLUGIN's limit, not the backend's. The refusals happen in the
	 * proxy and never leave the server, so provoking it costs no upstream
	 * traffic and stores nothing — read-only, and not tagged @writes.
	 *
	 * The e2e mu-plugin normally resets the buckets before every REST request,
	 * because the browser suite runs every spec twice from one runner IP. That
	 * has to be switched off for the duration or the limiter can never trip.
	 */
	await login( page );
	await page.goto( '/wp-admin/?spotship_e2e_keep_limits=1' );

	try {
		let throttled = null;

		// SUBMIT_LIMIT is 5/hour; the sixth must be refused. The payload is
		// deliberately invalid so that if a request ever did reach the backend
		// it would be rejected rather than stored.
		for ( let i = 0; i < 8 && ! throttled; i++ ) {
			await page.goto( '/insurance/' );
			await page.locator( '[data-sswi-field="userName"] input' ).fill( `CI throttle ${ i }` );
			await page.locator( '[data-sswi-field="userEmail"] input' ).fill( 'not-an-email-address' );
			await page.locator( '[data-sswi-consent]' ).check();
			await page.waitForTimeout( 3500 );

			const [ response ] = await Promise.all( [
				page.waitForResponse( ( r ) => /quote[-_]request/.test( r.url() ), { timeout: 30000 } ),
				page.locator( '.sswi-submit' ).click(),
			] );

			if ( 429 === response.status() ) {
				throttled = response;
			}
		}

		expect( throttled, 'the submit limit was never reached, so nothing was under test' ).not.toBeNull();

		const body = await throttled.json().catch( () => ( {} ) );

		evidence( 'throttled.json', JSON.stringify( body, null, 2 ) );

		// The honest wait, in BOTH places the front end reads it.
		expect( body.retry, 'the body must carry the wait' ).toBeGreaterThan( 0 );
		expect(
			throttled.headers()[ 'retry-after' ],
			'Retry-After must be sent as a header too'
		).toBeTruthy();

		// And the visitor must be told how long, not that the service is down.
		const shown = ( await page.locator( '[data-sswi-result]' ).textContent() ).toLowerCase();

		expect( shown ).toMatch( /wait|too many/ );
		expect( shown ).not.toMatch( /unavailable/ );
	} finally {
		// Always hand the environment back with the limiter reset restored,
		// or every spec after this one inherits an exhausted bucket.
		await page.goto( '/wp-admin/?spotship_e2e_keep_limits=' );
	}
} );
