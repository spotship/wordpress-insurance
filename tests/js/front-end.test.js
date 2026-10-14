/**
 * Behaviour of the real front-end script, driven through real DOM events.
 *
 * Each test here corresponds to a defect that reached production in a previous
 * implementation. They are regression guards first and documentation second.
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { boot, CONFIG, rootHtml } = require( './harness.js' );

const PORTS = [
	{ id: 11, name: 'Rotterdam', locode: 'NLRTM' },
	{ id: 22, name: 'Rotterdam Europoort', locode: 'NLEUR' },
];

/*
 * THE RATES BODY, DERIVED FROM THE BACKEND.
 *
 * These stubs used to hand-write `rates: { cargo: [ { zone: 'Hormuz Passage' } ] }`.
 * The endpoint returns `cargo: { columns, zones, disclaimers, footnotes }` with
 * rows keyed `name`, so the stub was not a simplification of the real body —
 * it was a different body, and the risk-zone test built on it proved the
 * opposite of what it claimed.
 *
 * Regenerate with bin/derive-backend-rates.sh.
 */
const RATES = ( () => {
	const body = Object.assign( {}, require( '../fixtures/backend-rates.json' ) );

	delete body._comment;

	return body;
} )();

const CARGO_ZONE_NAMES = RATES.cargo.zones.map( ( zone ) => zone.name );

function router( over = {} ) {
	return ( url ) => {
		if ( url.includes( '-search' ) ) {
			return over.search || { status: 200, body: { results: PORTS } };
		}

		if ( url.includes( '/rates' ) ) {
			return { status: 200, body: { tier: 'fresh', rates: RATES } };
		}

		return over.submit || {
			status: 201,
			body: { success: true, message: 'THANKS', data: { submissionId: 'x', status: 'received' } },
		};
	};
}

async function typeInto( h, selector, value ) {
	const input = h.document.querySelector( selector );
	input.value = value;
	input.dispatchEvent( new h.window.Event( 'input', { bubbles: true } ) );
	await h.settle( 260 );
	return input;
}

function key( h, input, k ) {
	input.dispatchEvent( new h.window.KeyboardEvent( 'keydown', { key: k, bubbles: true } ) );
}

test( 'the form ships inert and JavaScript is what enables it', async () => {
	const h = boot( { fetch: router() } );

	await h.ready();

	assert.equal( h.form.querySelector( '.sswi-submit' ).disabled, false );
	assert.equal( h.document.querySelector( '[data-sswi-noscript]' ), null );
} );

test( 'aria-activedescendant names the option that is actually rendered', async () => {
	const h = boot( { fetch: router() } );
	await h.ready();

	const input = await typeInto( h, '#sswi-fromPort', 'rot' );

	key( h, input, 'ArrowDown' );

	const active = input.getAttribute( 'aria-activedescendant' );
	const options = h.document.querySelectorAll( '#sswi-fromPort-list [role="option"]' );

	assert.ok( active, 'aria-activedescendant must be set once an option is active' );

	// The id must belong to a real option, and to the one marked selected.
	const target = h.document.getElementById( active );
	assert.ok( target, `aria-activedescendant "${ active }" must reference a rendered option` );
	assert.equal( target.getAttribute( 'aria-selected' ), 'true' );
	assert.equal( target, options[ 0 ] );
} );

test( 'choosing an option submits the id of the option that was displayed', async () => {
	const h = boot( { fetch: router() } );
	await h.ready();

	const input = await typeInto( h, '#sswi-fromPort', 'rot' );

	key( h, input, 'ArrowDown' );
	key( h, input, 'ArrowDown' );
	key( h, input, 'Enter' );

	const hidden = h.document.querySelector( '[data-sswi-combo="location"] [data-sswi-value]' );

	// Second option is Rotterdam Europoort / id 22. The displayed text and the
	// submitted id must refer to the same row — the stale-id defect submitted
	// one while showing the other.
	assert.equal( input.value, 'Rotterdam Europoort' );
	assert.equal( hidden.value, '22' );
} );

test( 'retyping after a choice clears the committed id rather than keeping a stale one', async () => {
	const h = boot( { fetch: router() } );
	await h.ready();

	const input = await typeInto( h, '#sswi-fromPort', 'rot' );
	key( h, input, 'ArrowDown' );
	key( h, input, 'Enter' );

	const hidden = h.document.querySelector( '[data-sswi-combo="location"] [data-sswi-value]' );
	assert.equal( hidden.value, '11' );

	await typeInto( h, '#sswi-fromPort', 'rotter' );

	assert.equal( hidden.value, '', 'a typed change must invalidate the committed id' );
} );

test( 'a second submission does not reuse the first submissionId', async () => {
	const bodies = [];

	const h = boot( {
		fetch: ( url, init ) => {
			if ( url.includes( '-search' ) ) {
				return { status: 200, body: { results: PORTS } };
			}

			bodies.push( JSON.parse( init.body ) );

			return {
				status: 201,
				body: { success: true, message: 'THANKS', data: { submissionId: 'x', status: 'received' } },
			};
		},
	} );

	await h.ready();

	h.document.querySelector( '#sswi-consent' ).checked = true;
	h.form.dispatchEvent( new h.window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await h.settle( 10 );

	h.document.querySelector( '#sswi-consent' ).checked = true;
	h.form.dispatchEvent( new h.window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await h.settle( 10 );

	assert.equal( bodies.length, 2 );
	assert.ok( bodies[ 0 ].submissionId, 'first submission carries an id' );
	assert.notEqual(
		bodies[ 0 ].submissionId,
		bodies[ 1 ].submissionId,
		'reusing the id makes the backend answer 200 replay, which the form would report as a fresh success'
	);
} );

test( 'a control changed mid-flight does not let the next submit replay the first id', async () => {
	const bodies = [];

	const h = boot( {
		fetch: ( url, init ) => {
			if ( url.includes( '-search' ) ) {
				return { status: 200, body: { results: PORTS } };
			}

			bodies.push( JSON.parse( init.body ) );

			return {
				status: 201,
				body: { success: true, message: 'THANKS', data: { submissionId: 'x', status: 'received' } },
			};
		},
	} );

	await h.ready();

	h.document.querySelector( '#sswi-consent' ).checked = true;
	h.form.dispatchEvent( new h.window.Event( 'submit', { bubbles: true, cancelable: true } ) );

	/*
	 * SYNCHRONOUSLY, while the POST is still in flight. Typing into a combobox
	 * runs commit( null ) -> forget(), which used to clear the submission lock
	 * without minting a new id — so the next submit carried the first one and
	 * the backend answered it as an idempotent replay.
	 */
	const input = h.document.querySelector( '#sswi-fromPort' );
	input.value = 'rot';
	input.dispatchEvent( new h.window.Event( 'input', { bubbles: true } ) );

	await h.settle( 300 );

	assert.notEqual(
		h.document.querySelector( '[data-sswi-result]' ).textContent,
		'THANKS',
		'the abandoned answer describes a payload the form no longer holds and must not report success'
	);

	h.document.querySelector( '#sswi-consent' ).checked = true;
	h.form.dispatchEvent( new h.window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await h.settle( 300 );

	assert.equal( bodies.length, 2, 'both submissions reached the proxy' );
	assert.notEqual(
		bodies[ 0 ].submissionId,
		bodies[ 1 ].submissionId,
		'replaying the id makes the backend answer 200 for the record it already holds, and the form reports success for an edited payload it never received'
	);
} );

test( 'consent is sent as a boolean the backend can reject when absent', async () => {
	const bodies = [];

	const h = boot( {
		fetch: ( url, init ) => {
			if ( url.includes( '-search' ) ) {
				return { status: 200, body: { results: PORTS } };
			}

			bodies.push( JSON.parse( init.body ) );

			return { status: 201, body: { success: true, message: 'ok', data: {} } };
		},
	} );

	await h.ready();

	h.document.querySelector( '#sswi-consent' ).checked = true;
	h.form.dispatchEvent( new h.window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await h.settle( 10 );

	assert.equal( bodies[ 0 ].dataConsent, true );
	assert.equal( typeof bodies[ 0 ].dataConsent, 'boolean' );
} );

test( 'one recovered lookup does not re-enable submission while another is still broken', async () => {
	let vesselBroken = true;
	let locationBroken = true;

	const h = boot( {
		fetch: ( url ) => {
			if ( url.includes( 'vessel-search' ) ) {
				return { status: 200, body: vesselBroken ? { unavailable: true } : { results: PORTS } };
			}

			if ( url.includes( 'location-search' ) ) {
				return { status: 200, body: locationBroken ? { unavailable: true } : { results: PORTS } };
			}

			return { status: 201, body: { success: true, message: 'ok', data: {} } };
		},
	} );

	await h.ready();

	await typeInto( h, '#sswi-vessel', 'ever' );
	await typeInto( h, '#sswi-fromPort', 'rot' );

	const button = h.form.querySelector( '.sswi-submit' );
	assert.equal( button.disabled, true, 'both lookups broken must block submission' );

	// One recovers.
	vesselBroken = false;
	await typeInto( h, '#sswi-vessel', 'evergreen' );

	assert.equal(
		button.disabled,
		true,
		'a boolean flag would re-enable here; the remaining broken lookup must keep it blocked'
	);

	// Now the other.
	locationBroken = false;
	await typeInto( h, '#sswi-fromPort', 'rotte' );

	assert.equal( button.disabled, false, 'with both recovered, submission is available again' );
} );

test( 'a failed submit surfaces inline field errors and moves focus to the summary', async () => {
	const h = boot( {
		fetch: ( url ) => {
			if ( url.includes( '-search' ) ) {
				return { status: 200, body: { results: PORTS } };
			}

			return {
				status: 400,
				body: { success: false, code: 'validation', fields: { userEmail: [ 'Enter a valid email address.' ] } },
			};
		},
	} );

	await h.ready();

	h.document.querySelector( '#sswi-consent' ).checked = true;
	h.form.dispatchEvent( new h.window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await h.settle( 10 );

	const slot = h.document.querySelector( '[data-sswi-field="userEmail"] [data-sswi-error]' );
	const input = h.document.querySelector( '#sswi-userEmail' );
	const summary = h.document.querySelector( '[data-sswi-summary]' );

	assert.equal( slot.hidden, false );
	assert.match( slot.textContent, /valid email/ );
	assert.equal( input.getAttribute( 'aria-invalid' ), 'true' );
	assert.equal( summary.hidden, false );
} );

test( 'an unrecognised field error falls back to the neutral sentence rather than vanishing', async () => {
	const h = boot( {
		fetch: ( url ) => {
			if ( url.includes( '-search' ) ) {
				return { status: 200, body: { results: PORTS } };
			}

			return { status: 400, body: { success: false, code: 'validation', fields: { notAControl: [ 'nope' ] } } };
		},
	} );

	await h.ready();

	h.document.querySelector( '#sswi-consent' ).checked = true;
	h.form.dispatchEvent( new h.window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await h.settle( 10 );

	const result = h.document.querySelector( '[data-sswi-result]' );

	assert.equal( result.textContent, 'UNAVAILABLE' );
} );

test( 'cargo destinations are ordered, removable and announced', async () => {
	const bodies = [];

	const h = boot( {
		product: 'cargo',
		fetch: ( url, init ) => {
			if ( url.includes( '-search' ) ) {
				return { status: 200, body: { results: PORTS } };
			}

			if ( url.includes( '/rates' ) ) {
				return { status: 200, body: { tier: 'fresh', rates: RATES } };
			}

			bodies.push( JSON.parse( init.body ) );

			return { status: 201, body: { success: true, message: 'ok', data: {} } };
		},
	} );

	await h.ready();

	const input = await typeInto( h, '#sswi-destinations', 'rot' );
	key( h, input, 'ArrowDown' );
	key( h, input, 'Enter' );

	await typeInto( h, '#sswi-destinations', 'rot' );
	key( h, input, 'ArrowDown' );
	key( h, input, 'ArrowDown' );
	key( h, input, 'Enter' );

	const chips = h.document.querySelectorAll( '[data-sswi-chips] li' );
	assert.equal( chips.length, 2 );

	// Every chip carries its own labelled remove control.
	chips.forEach( ( chip ) => {
		const button = chip.querySelector( 'button' );
		assert.ok( button, 'each chip needs a remove button' );
		assert.match( button.getAttribute( 'aria-label' ), /^Remove / );
	} );

	h.document.querySelector( '#sswi-consent' ).checked = true;
	h.form.dispatchEvent( new h.window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await h.settle( 10 );

	assert.deepEqual( bodies[ 0 ].destinations, [ 11, 22 ], 'order is meaningful — the backend stores position' );
} );

test( 'the cargo risk zone is populated from the served rate table, not a hardcoded list', async () => {
	const h = boot( {
		product: 'cargo',
		fetch: ( url ) => {
			if ( url.includes( '/rates' ) ) {
				return { status: 200, body: { tier: 'fresh', rates: RATES } };
			}

			return { status: 200, body: { results: [] } };
		},
	} );

	await h.ready();
	await h.settle( 10 );

	const options = Array.from( h.document.querySelectorAll( '#sswi-riskZone option' ) ).map( ( o ) => o.value );

	/*
	 * THE DEFECT THIS GUARDS AGAINST: the select is REQUIRED, so an empty one is
	 * not a degraded cargo form, it is a cargo form nobody can submit.
	 *
	 * `rates.cargo` is an OBJECT — { columns, zones, disclaimers, footnotes } —
	 * and the rows are keyed `name`. The script read `rates.cargo` as the row
	 * list and `row.zone` as the label, so `.forEach` threw (objects have no
	 * forEach), the promise rejected into the catch, and the select stayed at
	 * its placeholder. Silently: a genuine outage and a shape error were
	 * indistinguishable.
	 *
	 * This test existed and passed throughout, because it hand-wrote the shape
	 * the script expected instead of the one the API sends. It now drives the
	 * derived fixture, so it cannot agree with a wrong reader again.
	 */
	assert.deepEqual( options, [ '', ...CARGO_ZONE_NAMES ] );
	assert.ok( CARGO_ZONE_NAMES.length >= 6, 'the fixture must carry the real cargo zones' );
} );

test( 'the destinations listbox names the option it actually rendered', async () => {
	const h = boot( {
		product: 'cargo',
		fetch: ( url ) => {
			if ( url.includes( '/rates' ) ) {
				return { status: 200, body: { tier: 'fresh', rates: { cargo: { zones: [] } } } };
			}

			return { status: 200, body: { results: PORTS } };
		},
	} );

	await h.ready();

	const input = await typeInto( h, '#sswi-destinations', 'rot' );

	key( h, input, 'ArrowDown' );

	const active = input.getAttribute( 'aria-activedescendant' );
	const target = h.document.getElementById( active );

	/*
	 * The destinations listbox is a SECOND listbox. Mutation testing showed the
	 * ordinary combobox was covered while this one was not — which is the shape
	 * of the original stale-id defect: a fix applied to one call site while an
	 * identical site elsewhere kept the bug.
	 */
	assert.ok( active, 'aria-activedescendant must be set once an option is active' );
	assert.ok( target, `aria-activedescendant "${ active }" must reference a rendered option` );
	assert.equal( target.getAttribute( 'aria-selected' ), 'true' );
	assert.equal( target, h.document.querySelectorAll( '#sswi-destinations-list [role="option"]' )[ 0 ] );
} );

/*
 * The three tests below cover defects found by review after the suite was
 * already green. Each was invisible to every existing test for the same reason:
 * the harness modelled a Response as `{status, json}` and the script only ever
 * read the body, so an error STATUS carrying a perfectly well-formed body was
 * indistinguishable from success.
 */

test( 'a lookup refused by our own proxy blocks submission rather than reading as zero results', async () => {
	/*
	 * An upstream miss is 200 + `unavailable: true`, and was handled. These are
	 * different: the proxy's own per-IP limiter returns a real 429, and a
	 * binding failure a real 4xx, each with a body that has no `unavailable`
	 * key. Reading only the body turns both into "No matches found" with the
	 * submit button still live — the visitor is told their vessel does not
	 * exist, and can send a form whose combobox never resolved.
	 */
	const refusals = [
		{ what: 'the per-IP throttle', status: 429, body: { success: false, code: 'throttled', retry: 30 } },
		{ what: 'a binding failure', status: 403, body: { success: false, code: 'unavailable' } },
		{ what: 'an unparseable body', status: 500, body: null, invalidJson: true },
	];

	for ( const refusal of refusals ) {
		const h = boot( {
			fetch: ( url ) =>
				url.includes( '-search' )
					? refusal
					: { status: 201, body: { success: true, message: 'ok', data: {} } },
		} );

		await h.ready();
		await typeInto( h, '#sswi-vessel', 'ever' );

		assert.equal(
			h.form.querySelector( '.sswi-submit' ).disabled,
			true,
			`${ refusal.what } must block submission`
		);

		const list = h.document.querySelector( '#sswi-vessel-list' );
		assert.ok(
			! list.textContent.includes( 'NO RESULTS' ),
			`${ refusal.what } must not be reported to the visitor as an empty result set`
		);
	}
} );

test( 'a throttled submission tells the visitor how long to wait', async () => {
	/*
	 * The proxy computes the wait and sends it twice — `retry` in the body and
	 * Retry-After on the response. Showing the generic "temporarily
	 * unavailable" discards both and leaves the visitor unable to tell a
	 * seconds-long wait from an outage.
	 */
	const h = boot( {
		fetch: router( {
			submit: {
				status: 429,
				body: { success: false, code: 'throttled', retry: 45 },
				headers: { 'retry-after': '45' },
			},
		} ),
	} );

	await h.ready();
	h.form.dispatchEvent( new h.window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await h.settle( 10 );

	assert.equal(
		h.document.querySelector( '[data-sswi-result]' ).textContent,
		'WAIT 45 SECONDS',
		'the computed wait must reach the visitor'
	);
} );

test( 'a throttled submission falls back to the Retry-After header when the body carries no wait', async () => {
	// A refusal generated above our handler — by a WAF or the host — still
	// carries the header, and it is the only number available.
	const h = boot( {
		fetch: router( {
			submit: { status: 429, body: { success: false }, headers: { 'retry-after': '12' } },
		} ),
	} );

	await h.ready();
	h.form.dispatchEvent( new h.window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await h.settle( 10 );

	assert.equal(
		h.document.querySelector( '[data-sswi-result]' ).textContent,
		'WAIT 12 SECONDS',
		'the header is the fallback when the body has no retry'
	);
} );

/*
 * FORMS THAT ARRIVE AFTER LOAD.
 *
 * war-insurance.js swept the document once, on DOMContentLoaded. Elementor's
 * editor renders a widget through its own AJAX action and injects the markup
 * into a preview that loaded long ago, so the form a site builder had just
 * dragged in was never initialised: controls disabled, "needs JavaScript"
 * notice still showing. The first thing anyone saw of the Elementor surface was
 * a form announcing itself as broken.
 *
 * These drive the real injection shape — append a root to a live document, then
 * mount it the way assets/js/elementor.js does from Elementor's hook.
 */

/**
 * Append a second root to an already-loaded document.
 *
 * @param {object} h       A booted harness.
 * @param {string} product hull or cargo.
 */
function inject( h, product ) {
	const holder = h.document.createElement( 'div' );

	holder.innerHTML = rootHtml( product, Object.assign( {}, CONFIG, { product } ) );
	h.document.body.appendChild( holder );

	return holder.querySelector( '[data-sswi-root]' );
}

test( 'a form injected after load is dead until it is mounted', async () => {
	const h = boot();
	await h.ready();

	const late = inject( h, 'hull' );

	// NON-VACUITY: if this were already live, the test below would pass without
	// the mount doing anything, which is exactly how this defect stayed hidden.
	assert.equal(
		late.querySelector( '.sswi-submit' ).disabled,
		true,
		'the once-at-load sweep must not have reached an element that did not exist yet'
	);
	assert.ok(
		late.querySelector( '[data-sswi-noscript]' ),
		'and its notice must still be up'
	);
} );

test( 'mounting an injected form enables it and removes the notice, scope being the root', async () => {
	const h = boot();
	await h.ready();

	const late = inject( h, 'hull' );

	h.window.spotshipWI.mountAll( late );
	await h.settle( 0 );

	assert.equal(
		late.querySelector( '.sswi-submit' ).disabled,
		false,
		'the injected form must become submittable'
	);
	assert.equal(
		late.querySelector( '[data-sswi-noscript]' ),
		null,
		'and stop telling the visitor JavaScript is required'
	);
} );

test( 'mountAll reaches a root nested inside the scope Elementor passes', async () => {
	/*
	 * THE REAL SHAPE. Elementor hands a handler `.elementor-element...`, and our
	 * markup sits inside `.elementor-widget-container` within it — the root is a
	 * DESCENDANT of the scope, never the scope. The sibling test above covers
	 * the scope BEING the root, which is what happens when mount() is handed one
	 * directly; between them the two branches of mountAll() are both exercised.
	 */
	const h = boot();
	await h.ready();

	const scope = h.document.createElement( 'div' );
	scope.className = 'elementor-element elementor-widget-spotship-war-hull';

	const container = h.document.createElement( 'div' );
	container.className = 'elementor-widget-container';
	container.innerHTML = rootHtml( 'hull', Object.assign( {}, CONFIG, { product: 'hull' } ) );

	scope.appendChild( container );
	h.document.body.appendChild( scope );

	const late = scope.querySelector( '[data-sswi-root]' );
	assert.equal( late.querySelector( '.sswi-submit' ).disabled, true, 'non-vacuity' );

	h.window.spotshipWI.mountAll( scope );
	await h.settle( 0 );

	assert.equal(
		late.querySelector( '.sswi-submit' ).disabled,
		false,
		'a root nested in the scope must be found'
	);
} );

test( 'mounting twice does not bind a second set of listeners', async () => {
	/*
	 * Elementor re-runs element_ready on every re-render, and the editor
	 * re-renders freely. A second init() over the same root would leave two
	 * submit handlers on one form: one visitor action, two quote requests, and
	 * the backend has no way to tell them apart.
	 */
	let submits = 0;

	const h = boot( {
		fetch: ( url ) => {
			if ( url.includes( '/quote' ) || url.includes( '/submit' ) ) {
				submits += 1;
			}

			return { status: 200, body: { success: true } };
		},
	} );

	await h.ready();

	// The root booted at load is already mounted; mounting it again must be a
	// no-op rather than a second binding.
	h.window.spotshipWI.mount( h.root );
	h.window.spotshipWI.mountAll( h.document );

	h.form.dispatchEvent( new h.window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await h.settle( 10 );

	assert.ok( submits <= 1, `one submit event must produce at most one request, got ${ submits }` );
} );
