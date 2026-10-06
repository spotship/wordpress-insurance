/**
 * Signing in to wp-admin, once, for every suite that needs it.
 *
 * This existed three times and had already drifted. It is also the single
 * flakiest thing in the browser suites, and every one of its failures has
 * presented as something else — a pending database upgrade, a navigation race,
 * a missing admin bar, a rendering fault. Keeping one copy means a fix lands
 * everywhere and a diagnosis is written down once.
 *
 * @package Spotship_WI
 */
'use strict';

const { expect } = require( '@playwright/test' );

/** Created by bin/seed-e2e.sh, which must agree with this. */
const ADMIN = {
	user: 'e2e-admin',
	pass: process.env.E2E_ADMIN_PASS || 'e2e-admin-password',
};

/**
 * Authenticate, and prove it rather than assume it.
 *
 * @param {object} page Playwright page.
 */
async function login( page ) {
	await page.goto( '/wp-login.php' );

	// Already authenticated: WordPress bounces a logged-in user off wp-login.
	if ( ! ( await page.locator( '#wp-submit' ).count() ) ) {
		return;
	}

	/*
	 * WAIT FOR wp-login's OWN AUTOFOCUS BEFORE TYPING.
	 *
	 * The page focuses #user_login shortly after load. When that lands between
	 * the two fills, the password goes into the USERNAME field — observed in
	 * CI, where the failure screenshot showed "e2e-admin-password" sitting in
	 * "Username or Email Address" with the password box empty. The login is
	 * then refused, and on retry it passes, so the run is reported green with
	 * one flaky test and the cause is invisible.
	 */
	await page
		.waitForFunction(
			() => document.activeElement && 'user_login' === document.activeElement.id,
			null,
			{ timeout: 5000 }
		)
		.catch( () => {
			// Autofocus is a convenience, not a contract. If it never happens
			// the assertions below still catch a misplaced value.
		} );

	await page.locator( '#user_login' ).fill( ADMIN.user );
	await page.locator( '#user_pass' ).fill( ADMIN.pass );

	// Assert what is about to be submitted. This turns the flake above into an
	// immediate, named failure instead of a refused login three steps later.
	await expect( page.locator( '#user_login' ), 'the username field was overwritten' ).toHaveValue(
		ADMIN.user
	);
	await expect( page.locator( '#user_pass' ), 'the password never reached its field' ).toHaveValue(
		ADMIN.pass
	);

	/*
	 * Wait to LEAVE wp-login.php rather than for a particular destination: a
	 * freshly activated Elementor claims the first admin request for its
	 * onboarding wizard, and WordPress may route through an upgrade screen.
	 */
	const submitted = page
		.waitForURL( ( url ) => ! url.pathname.endsWith( 'wp-login.php' ), { timeout: 20000 } )
		.catch( async () => {
			const error = await page.locator( '#login_error' ).textContent().catch( () => null );

			throw new Error(
				`login as "${ ADMIN.user }" was refused; WordPress said: ` +
					( error ? error.trim() : '(no #login_error on the page)' )
			);
		} );

	await page.locator( '#wp-submit' ).click();
	await submitted;
}

module.exports = { ADMIN, login };
