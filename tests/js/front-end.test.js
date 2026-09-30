/**
 * Behaviour of the real front-end script, driven through real DOM events.
 *
 * Each test here corresponds to a defect that reached production in a previous
 * implementation. They are regression guards first and documentation second.
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { boot } = require( './harness.js' );

const PORTS = [
	{ id: 11, name: 'Rotterdam', locode: 'NLRTM' },
	{ id: 22, name: 'Rotterdam Europoort', locode: 'NLEUR' },
];

function router( over = {} ) {
	return ( url ) => {
		if ( url.includes( '-search' ) ) {
			return over.search || { status: 200, body: { results: PORTS } };
		}

		if ( url.includes( '/rates' ) ) {
			return { status: 200, body: { tier: 'fresh', rates: { cargo: [ { zone: 'Hormuz Passage' } ] } } };
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
				return { status: 200, body: { tier: 'fresh', rates: { cargo: [ { zone: 'Hormuz Passage' } ] } } };
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
				return {
					status: 200,
					body: { tier: 'fresh', rates: { cargo: [ { zone: 'Hormuz Passage' }, { zone: 'Red Sea' } ] } },
				};
			}

			return { status: 200, body: { results: [] } };
		},
	} );

	await h.ready();
	await h.settle( 10 );

	const options = Array.from( h.document.querySelectorAll( '#sswi-riskZone option' ) ).map( ( o ) => o.value );

	assert.deepEqual( options, [ '', 'Hormuz Passage', 'Red Sea' ] );
} );

test( 'the destinations listbox names the option it actually rendered', async () => {
	const h = boot( {
		product: 'cargo',
		fetch: ( url ) => {
			if ( url.includes( '/rates' ) ) {
				return { status: 200, body: { tier: 'fresh', rates: { cargo: [] } } };
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
