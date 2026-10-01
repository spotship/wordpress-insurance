/**
 * The §9 failure matrix, driven against the REAL dev backend.
 *
 * ⚠️ NOT YET EXECUTED. The `dev` secrets now exist and the workflow is
 * reachable without a merge (a `real-backend-*` tag), so what remains is a
 * decision rather than a blocker — see persistence.spec.js and the header of
 * .github/workflows/real-backend-e2e.yaml. Everything here except the re-bind
 * recovery test is read-only against the real deployment; that one is tagged
 * `@writes` and excluded unless a run asks for it.
 *
 * tests/e2e/ already covers these against bin/e2e-upstream.js, and that stand-in
 * is honest about response SHAPE because its keys are derived from the
 * serializer. What it cannot reproduce is a real deployment's behaviour: TLS, a
 * real 401 from real authentication, real rate data, real latency, and a real
 * DNS failure. Each row below is the same scenario re-driven where those are
 * genuine rather than simulated.
 *
 * These run serially: several mutate plugin configuration.
 */
'use strict';

const { test, expect } = require( '@playwright/test' );
const { login } = require( '../support/wp-admin.js' );
const { watchSearches, explain } = require( '../support/search-diagnostics.js' );
const { fillRequiredHullFields } = require( '../support/combobox.js' );
const { assertPageNotThrottled } = require( '../support/quote-budget.js' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

test.describe.configure( { mode: 'serial' } );
test.setTimeout( 120000 );

// Reserved by RFC 2606: guaranteed never to resolve. A real DNS failure inside
// the same wp_safe_remote_post() a real outage would hit.
const UNREACHABLE = 'https://spotship-backend-outage.invalid';

/**
 * @param {object} page   Playwright page.
 * @param {object} params Control parameters for the e2e mu-plugin.
 */
async function configure( page, params ) {
	await login( page );

	const query = new URLSearchParams( params ).toString();
	await page.goto( `/wp-admin/?${ query }` );
}

/**
 * Put the installation back exactly as the seed left it.
 *
 * Via the mu-plugin's stash rather than by re-sending the original values: a
 * live api_key in a query string lands in the access log, in Playwright's trace
 * and in the CI job log.
 */
async function restore( page ) {
	await configure( page, { spotship_e2e_restore: '1' } );
}

test.afterEach( async ( { page } ) => {
	await restore( page );
} );

test( 'autocomplete resolves against real data and commits the id it displayed', async ( {
	page,
} ) => {
	/*
	 * The stand-in returns two hand-written locations. This drives the real
	 * search: real records, real ordering, real latency past the 200ms debounce.
	 * The invariant is the one that shipped broken in a previous implementation
	 * — the id submitted must be the id of the option the visitor SAW.
	 */
	await page.goto( '/insurance/' );

	// Before the requests below, never inside a failure path — the listener
	// cannot record a response it was attached after. See search-diagnostics.
	watchSearches( page );

	const input = page.locator( '[data-sswi-field="fromPort"] input[role="combobox"]' );

	await input.fill( 'rotterdam' );
	await page.waitForTimeout( 900 );

	const options = page.locator( '[data-sswi-field="fromPort"] [role="option"]' );

	try {
		await expect( options.first() ).toBeVisible();
	} catch ( error ) {
		explain( page, error, 'locations for "rotterdam"' );
	}

	const shownText = ( await options.first().textContent() ).trim();

	await input.press( 'ArrowDown' );
	await input.press( 'Enter' );

	const committed = await page
		.locator( '[data-sswi-field="fromPort"] [data-sswi-value]' )
		.inputValue();

	expect( committed, 'nothing was committed' ).not.toEqual( '' );
	expect( await input.inputValue() ).toContain( shownText.split( '\n' )[ 0 ].trim().slice( 0, 6 ) );

	// And the vessel lookup, which is a different upstream endpoint.
	const vessel = page.locator( '[data-sswi-field="vessel"] input[role="combobox"]' );

	await vessel.fill( 'ever' );
	await page.waitForTimeout( 900 );

	try {
		await expect(
			page.locator( '[data-sswi-field="vessel"] [role="option"]' ).first()
		).toBeVisible();
	} catch ( error ) {
		explain( page, error, 'vessels for "ever"' );
	}
} );

test( 'axe is clean on the real rate table', async ( { page } ) => {
	/*
	 * Worth running twice. The stand-in serves ONE hull row; the real backend
	 * serves the full commercially-approved table — 15 hull zones, 6 cargo —
	 * plus the real disclaimers. That is a materially different DOM: more rows,
	 * longer strings, real wrapping. Contrast and reflow are exactly the rules
	 * that behave differently on it.
	 */
	for ( const path of [ '/insurance/', '/insurance-cargo/' ] ) {
		await page.goto( path );
		await page.waitForLoadState( 'networkidle' );

		await expect( page.locator( '.sswi-rates' ), `${ path } served no rate table` ).toBeVisible();

		const results = await new AxeBuilder( { page } )
			.exclude( 'header' )
			.exclude( 'footer' )
			.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
			.analyze();

		expect( results.violations, `axe violations on ${ path }` ).toEqual( [] );
	}
} );

test( 'an unreachable backend refuses rather than reporting a submission as sent', async ( {
	page,
} ) => {
	await configure( page, { spotship_e2e_base: UNREACHABLE } );

	await page.goto( '/insurance/' );
	await page.locator( '[data-sswi-field="userName"] input' ).fill( 'CI outage' );
	await page.locator( '[data-sswi-field="userEmail"] input' ).fill( 'ci+outage@spot-ship.com' );
	await page.locator( '[data-sswi-consent]' ).check();

	// Out-wait MIN_AGE or the decoy answers, which would report a success that
	// never left the server — the precise failure this test exists to exclude.
	await page.waitForTimeout( 3500 );

	await page.locator( '.sswi-submit' ).click();
	await page.waitForTimeout( 2000 );

	const shown = ( await page.locator( '[data-sswi-result]' ).textContent() ).toLowerCase();

	expect( shown ).not.toContain( 'thank' );
	expect( shown ).not.toContain( 'received' );
	expect( shown ).toMatch( /unavailable|try again/ );
} );

test( 'an unreachable backend blocks submission rather than showing an empty search', async ( {
	page,
} ) => {
	await configure( page, { spotship_e2e_base: UNREACHABLE } );

	await page.goto( '/insurance/' );
	await page.locator( '[data-sswi-field="fromPort"] input[role="combobox"]' ).fill( 'rot' );
	await page.waitForTimeout( 1200 );

	await expect( page.locator( '.sswi-submit' ) ).toBeDisabled();
} );

test( 'a site bound elsewhere cannot submit to the real backend', async ( { page } ) => {
	await configure( page, { spotship_e2e_bind: 'https://cloned-to-staging.example.test' } );

	await page.goto( '/insurance/' );
	await page.locator( '[data-sswi-field="userName"] input' ).fill( 'CI clone' );
	await page.locator( '[data-sswi-field="userEmail"] input' ).fill( 'ci+clone@spot-ship.com' );
	await page.locator( '[data-sswi-consent]' ).check();
	await page.waitForTimeout( 3500 );

	await page.locator( '.sswi-submit' ).click();
	await page.waitForTimeout( 1500 );

	const shown = ( await page.locator( '[data-sswi-result]' ).textContent() ).toLowerCase();

	expect( shown ).toMatch( /unavailable|try again/ );
	expect( shown ).not.toContain( 'thank' );
} );

test( 'one click rebinds against the real backend and submission recovers @writes', async ( { page } ) => {
	await configure( page, { spotship_e2e_bind: 'https://cloned-to-staging.example.test' } );

	await page.goto( '/wp-admin/options-general.php?page=spotship-war-insurance' );

	const notice = page.locator( '.notice-error' ).filter( { hasText: /bound/i } );

	await expect( notice ).toBeVisible();
	await expect( notice ).toContainText( 'cloned-to-staging.example.test' );

	await notice.locator( 'a' ).first().click();
	await page.waitForLoadState( 'networkidle' );

	await expect(
		page.locator( '.notice-error' ).filter( { hasText: /bound/i } )
	).toHaveCount( 0 );

	// The recovery must restore the whole configuration, not just the binding:
	// a re-bind that blanks the base url leaves the install bound and serving
	// 502 while reporting success. That shipped once.
	await page.goto( '/insurance/' );
	await page.locator( '[data-sswi-field="userName"] input' ).fill( 'CI rebound' );
	await page.locator( '[data-sswi-field="userEmail"] input' ).fill( 'ci+rebound@spot-ship.com' );

	/*
	 * EVERY REQUIRED FIELD, OR THIS ASSERTS NOTHING.
	 *
	 * This filled name, email and consent and stopped. QuoteRequest requires
	 * vessel, from_port and to_port, so the serializer answered 400 with
	 * `fields` — and handle submit() EMPTIES the result region when field
	 * errors land, showing the error summary instead. The assertion below then
	 * read "" and reported "submissions are still refused", blaming the re-bind
	 * for a payload this test never made valid. Being @writes it had never run
	 * to say so; the 2026-10-01 run is the first time it did.
	 */
	await fillRequiredHullFields( page );

	await page.locator( '[data-sswi-consent]' ).check();

	await page.locator( '.sswi-submit' ).click();

	/*
	 * Wait for an ANSWER, not for a duration, and NOT MERELY FOR NON-EMPTY.
	 *
	 * A fixed 2500ms read the region before a slow deployment had replied —
	 * the upstream timeout is 8s — and could not tell "refused" from "not
	 * finished yet". Polling for non-empty replaced that with a subtler
	 * version of the same mistake: handle submit() writes i18n.sending
	 * ("Sending your request…") into the result region the instant the click
	 * lands, so "non-empty" was true immediately and the assertion read the
	 * placeholder. It reported "submissions are still refused" with the
	 * received string "sending your request…", which is neither a refusal nor
	 * a success.
	 *
	 * The answer is whatever REPLACES that text, or an error summary.
	 */
	const result = page.locator( '[data-sswi-result]' );
	const summary = page.locator( '[data-sswi-summary]' );
	const PENDING = 'sending your request';

	await expect
		.poll(
			async () => {
				const r = ( ( await result.textContent() ) || '' ).trim().toLowerCase();
				const s = ( ( await summary.textContent() ) || '' ).trim();

				if ( s.length > 0 ) {
					return 'summary';
				}

				return r.length > 0 && ! r.startsWith( PENDING ) ? 'answered' : 'pending';
			},
			{
				timeout: 30000,
				message:
					'the submission never resolved — the result region still shows the pending message and no error summary appeared',
			}
		)
		.not.toEqual( 'pending' );

	const shown = ( await result.textContent() ).toLowerCase();

	assertPageNotThrottled( shown, 'the re-bound submission was never attempted upstream.' );

	// Name the other outcome rather than blaming the re-bind for it: field
	// errors mean the payload was rejected, which is this test's own fault and
	// not evidence about the binding.
	const fieldErrors = await page.locator( '[data-sswi-error]:not(:empty)' ).allTextContents();

	expect(
		fieldErrors,
		`the submission was rejected on its fields, so this says nothing about the re-bind: ${ fieldErrors.join(
			'; '
		) }`
	).toEqual( [] );

	// The success sentence, not just the absence of the refusal — an empty
	// result or a validation error satisfies `not unavailable` too, and this is
	// the assertion that decides whether the recovery actually recovered.
	expect( shown, 'the re-bind reported success but submissions are still refused' ).toContain(
		'thank'
	);
	expect( shown ).not.toMatch( /unavailable/ );
} );

test.describe( 'with JavaScript blocked', () => {
	test.use( { javaScriptEnabled: false } );

	test( 'the form stays inert and says why, on a real deployment', async ( { page } ) => {
		/*
		 * Same assertion as tests/e2e/no-js.spec.js, re-driven here because the
		 * rate table on the dev deployment is server-rendered from real data: a visitor
		 * without JavaScript still sees the real commercial figures, and must
		 * still be told plainly that the form cannot be used.
		 */
		await page.goto( '/insurance/' );

		await expect( page.locator( '[data-sswi-noscript]' ) ).toBeVisible();
		await expect( page.locator( '.sswi-submit' ) ).toBeDisabled();
		await expect( page.locator( '[data-sswi-field="userName"] input' ) ).toBeDisabled();
		await expect( page.locator( '.sswi-rates' ) ).toBeVisible();
	} );
} );
