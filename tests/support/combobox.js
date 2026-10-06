/**
 * Committing a combobox, once, for every suite that drives one.
 *
 * These lived in persistence.spec.js, and failure-modes.spec.js carried a
 * third hand-rolled spelling of the same thing. That cost a real defect: the
 * re-bind test submitted a hull form with no vessel and no ports, because
 * reaching for pick() would have meant importing from a spec file, so the test
 * simply did not fill them. It asserted "thank" against a payload the
 * serializer rejects, and being @writes it had never run to say so.
 *
 * @package Spotship_WI
 */
'use strict';

const { expect } = require( '@playwright/test' );
const { watchSearches, explain } = require( './search-diagnostics.js' );

/**
 * Commit one combobox.
 *
 * Drive it the way a visitor does: type, wait past the 200ms debounce for the
 * upstream to answer, then ArrowDown + Enter. The hidden input only receives
 * an id when an option is actually CHOSEN, so filling the visible text alone
 * commits nothing and submits nothing.
 *
 * @param {object} page     Playwright page.
 * @param {string} selector Field wrapper, e.g. '[data-sswi-field="vessel"]'.
 * @param {string} term     What to type.
 */
async function pick( page, selector, term ) {
	const input = page.locator( `${ selector } input[role="combobox"]` );

	// Before the request, not inside the failure path — the listener cannot
	// record a response it was attached after.
	watchSearches( page );

	await input.fill( term );

	const option = page.locator( `${ selector } [role="option"]` ).first();

	try {
		await expect( option ).toBeVisible( { timeout: 15000 } );
	} catch ( error ) {
		explain( page, error, `options for "${ term }" in ${ selector }` );
	}

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

	watchSearches( page );

	await input.fill( term );

	try {
		await expect(
			page.locator( `${ wrap } [role="option"]` ).first()
		).toBeVisible( { timeout: 15000 } );
	} catch ( error ) {
		explain( page, error, `destinations for "${ term }"` );
	}

	await input.press( 'ArrowDown' );
	await input.press( 'Enter' );

	await expect(
		page.locator( `${ wrap } [data-sswi-chips] li` ),
		'choosing a destination added no chip, so no id was committed'
	).toHaveCount( before + 1 );
}

/**
 * Every field QuoteRequest requires, committed against the real search.
 *
 * vessel, from_port and to_port carry neither blank=True nor null=True, which
 * is what makes them required. A hull submission missing any of them comes
 * back 400 with `fields`, and the form then EMPTIES the result region and
 * shows the error summary instead — so a test asserting on the result region
 * sees "", not a refusal and not a success. That is precisely how the re-bind
 * test read as "submissions are still refused" when the truth was "this test
 * never sent a valid payload".
 *
 * @param {object} page Playwright page.
 */
async function fillRequiredHullFields( page ) {
	await pick( page, '[data-sswi-field="vessel"]', 'ever' );
	await pick( page, '[data-sswi-field="fromPort"]', 'rotterdam' );
	await pick( page, '[data-sswi-field="toPort"]', 'rotterdam' );
}

module.exports = { pick, addDestination, fillRequiredHullFields };
