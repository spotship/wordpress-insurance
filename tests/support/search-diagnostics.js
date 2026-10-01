/**
 * Why is the combobox empty?
 *
 * AN EMPTY LISTBOX HAS THREE CAUSES AND ONE APPEARANCE.
 *
 * Spotship_WI_Rest_Proxy::handle_search() converts an upstream failure into
 * `200 { results: [], unavailable: true }` — deliberately, so a visitor never
 * meets a stack trace on a customer's marketing site. The cost is paid here:
 * "the index holds nothing for this term", "the key has no search entitlement"
 * and "the deployment is answering 500" all reach the browser as the same
 * empty listbox.
 *
 * Both real-backend specs asserted the FIRST of those as fact. On 2026-10-01
 * the writing run failed seven tests saying "the real backend returned no
 * options for rotterdam" while dev was serving 36KB Django tracebacks to every
 * request. A diagnostic that confidently names the wrong cause is worse than
 * none: it is how two contradictory conclusions about dev's health were
 * written into this repository's docs before anyone spotted the flapping.
 *
 * `unavailable` is the plugin's own verdict on THE REQUEST THAT ACTUALLY
 * FAILED. Reading it off the response the page received beats re-asking the
 * upstream afterwards, which samples a different moment of a service known to
 * flap — and would make the message a coin toss in its own right.
 *
 * One copy, for the same reason wp-admin.js is one copy: this existed twice
 * already, in two spellings, and neither of them checked anything.
 *
 * @package Spotship_WI
 */
'use strict';

/** Where the per-page recorder is parked. */
const SEARCH_LOG = Symbol( 'spotship-wi search log' );

/**
 * Start recording what the plugin's search routes answer on this page.
 *
 * Idempotent: call it from any helper that is about to drive a combobox. The
 * listener must be attached BEFORE the request it is meant to observe, so a
 * helper that only installs it inside its own failure path records nothing.
 *
 * @param {object} page Playwright page.
 * @return {Array} The recorder, newest search last.
 */
function watchSearches( page ) {
	if ( ! page[ SEARCH_LOG ] ) {
		const seen = [];

		page.on( 'response', async ( response ) => {
			if ( ! /\/spotship\/v1\/(vessel|location)-search/.test( response.url() ) ) {
				return;
			}

			try {
				const body = await response.json();

				seen.push( {
					status: response.status(),
					count: Array.isArray( body.results ) ? body.results.length : null,
					unavailable: true === body.unavailable,
				} );
			} catch ( error ) {
				// A body that will not parse is itself worth reporting.
				seen.push( { status: response.status(), unparseable: error.message } );
			}
		} );

		page[ SEARCH_LOG ] = seen;
	}

	return page[ SEARCH_LOG ];
}

/**
 * One line naming what the most recent search actually did.
 *
 * @param {object} page Playwright page.
 * @return {string} Verdict, suitable for appending to a failure message.
 */
function lastSearchVerdict( page ) {
	const last = watchSearches( page ).slice( -1 )[ 0 ];

	if ( ! last ) {
		return 'the plugin issued no search request at all — the 200ms debounce never fired, or the field is not wired to the proxy';
	}

	if ( last.unparseable ) {
		return `the proxy answered HTTP ${ last.status } with a body that would not parse: ${ last.unparseable }`;
	}

	if ( last.unavailable ) {
		return 'the proxy reported `unavailable` — THE UPSTREAM FAILED. A backend fault, not an empty index and not a plugin bug';
	}

	return `the proxy answered HTTP ${ last.status } with ${ last.count } result(s) — the upstream was reachable and matched nothing`;
}

/**
 * Re-throw a combobox timeout with the cause attached.
 *
 * Playwright's own call log is kept: it says what was waited for and for how
 * long, which is still the first thing worth reading. The verdict goes above
 * it because it is the thing that decides who fixes it.
 *
 * @param {object} page    Playwright page.
 * @param {Error}  error   The assertion failure.
 * @param {string} what    What failed to appear, e.g. 'options for "rotterdam"'.
 */
function explain( page, error, what ) {
	error.message = `no ${ what } appeared.\n\nWHY: ${ lastSearchVerdict( page ) }\n\n${ error.message }`;

	throw error;
}

module.exports = { watchSearches, lastSearchVerdict, explain };
