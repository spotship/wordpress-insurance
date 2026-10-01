/**
 * A 429 from the writing half is a BUDGET, not a defect.
 *
 * backend/insurance/views.py puts the two submit endpoints behind
 * `ScopedRateThrottle` with scope `insurance_quote`, and
 * config/settings/base.py sets that scope to **20/hour**. One writing run
 * spends about ten of it:
 *
 *   4 submissions + 4 replays + the re-bind submission + the invalid-email row
 *
 * (the revoked-key submission does not count — DRF authenticates before it
 * checks throttles, so a 401 never reaches the counter).
 *
 * So TWO WRITING RUNS INSIDE ONE HOUR EXHAUST IT, and the second starts
 * refused. That happened on 2026-10-01: a run at 07:36 went 14/15, and a run
 * at 07:46 came back 8/15 with every writing test answering 429 — reported as
 * "the real backend must accept the submission", "the re-bind reported success
 * but submissions are still refused" and "the real serializer must reject this
 * address". Three different confident sentences, none of them true, all of
 * them the same exhausted budget.
 *
 * Naming it is the whole job of this file. Everything else in this suite has
 * already been through one round of a message that described a symptom as a
 * cause.
 *
 * @package Spotship_WI
 */
'use strict';

const BUDGET = [
	'',
	'HTTP 429 — the backend\'s `insurance_quote` throttle is exhausted.',
	'',
	'This is NOT a persistence, validation or re-bind failure. The scope is',
	'set to 20/hour in config/settings/base.py, and one writing run spends',
	'about ten of it (four submissions, four replays, the re-bind and the',
	'invalid-email row). A second writing run inside the same hour therefore',
	'starts refused.',
	'',
	'Wait for the window to roll, or run the read-only half instead:',
	'',
	'  git tag real-backend-$(date -u +%Y%m%d-%H%M) && git push origin --tags',
	'',
].join( '\n' );

/**
 * Fail with the budget explanation rather than the caller's assertion.
 *
 * @param {number} status What the submit answered.
 * @param {string} what   What was being attempted.
 */
function assertNotThrottled( status, what ) {
	if ( 429 === status ) {
		throw new Error( `${ what }\n${ BUDGET }` );
	}
}

/**
 * The same check, for a test that reads the rendered message instead.
 *
 * The form turns an upstream 429 into i18n.throttled — "Too many requests.
 * Please wait %d seconds and try again." — so the status is not visible to a
 * test that asserts on the page.
 *
 * @param {string} shown Lower-cased text of the result region.
 * @param {string} what  What was being attempted.
 */
function assertPageNotThrottled( shown, what ) {
	if ( shown.includes( 'too many requests' ) ) {
		throw new Error( `${ what }\n${ BUDGET }\nThe page said: "${ shown.trim() }"` );
	}
}

module.exports = { assertNotThrottled, assertPageNotThrottled, BUDGET };
