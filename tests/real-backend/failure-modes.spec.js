/**
 * The §9 failure matrix, driven against the REAL dev backend.
 *
 * ⚠️ NEVER EXECUTED — see real-backend.spec.js. The secrets do not exist.
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

	const input = page.locator( '[data-sswi-field="fromPort"] input[role="combobox"]' );

	await input.fill( 'rotterdam' );
	await page.waitForTimeout( 900 );

	const options = page.locator( '[data-sswi-field="fromPort"] [role="option"]' );

	await expect( options.first(), 'the real backend returned no locations for "rotterdam"' ).toBeVisible();

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

	await expect(
		page.locator( '[data-sswi-field="vessel"] [role="option"]' ).first(),
		'the real vessel search returned nothing for "ever"'
	).toBeVisible();
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

test( 'one click rebinds against the real backend and submission recovers', async ( { page } ) => {
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
	await page.locator( '[data-sswi-consent]' ).check();
	await page.waitForTimeout( 3500 );

	await page.locator( '.sswi-submit' ).click();
	await page.waitForTimeout( 2500 );

	const shown = ( await page.locator( '[data-sswi-result]' ).textContent() ).toLowerCase();

	expect( shown, 'the re-bind reported success but submissions are still refused' ).not.toMatch(
		/unavailable/
	);
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
