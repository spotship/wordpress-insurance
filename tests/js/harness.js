/**
 * jsdom harness: runs the REAL, UNMODIFIED assets/js/war-insurance.js.
 *
 * The script is never edited, stubbed or re-implemented here. It is loaded from
 * disk and driven through real DOM events, because a test against a
 * reimplementation proves only that the reimplementation works.
 *
 * The DOM built below mirrors the templates. That mirroring is itself checked —
 * see contract.test.js, which asserts every selector this script depends on
 * actually exists in templates/. A harness that drifts from the templates is
 * the "fixture that matched no real response" failure in another costume.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { JSDOM } = require( 'jsdom' );

const SCRIPT = path.join( __dirname, '..', '..', 'assets', 'js', 'war-insurance.js' );

const CONFIG = {
	root: 'https://example.test/wp-json/spotship/v1',
	product: 'hull',
	minAgeSeconds: 3,
	minQueryLength: 2,
	maxDestinations: 50,
	bounds: { userName: 255, userEmail: 254 },
	i18n: {
		unavailable: 'UNAVAILABLE',
		sending: 'SENDING',
		noResults: 'NO RESULTS',
		lookupFailed: 'LOOKUP FAILED',
		errorSummary: 'ERROR SUMMARY',
		removeChip: 'Remove %s',
		resultCount: '%d results available.',
		expired: 'EXPIRED',
	},
};

function field( name, id ) {
	return `
		<div class="sswi-field" data-sswi-field="${ name }">
			<label for="${ id }">${ name }</label>
			<input type="text" id="${ id }" name="${ name }" aria-describedby="${ id }-error" disabled>
			<p class="sswi-error" id="${ id }-error" data-sswi-error hidden></p>
		</div>`;
}

function combo( name, kind ) {
	const id = 'sswi-' + name;

	return `
		<div class="sswi-field sswi-combo" data-sswi-combo="${ kind }" data-sswi-field="${ name }">
			<label for="${ id }">${ name }</label>
			<input type="text" id="${ id }" role="combobox" aria-expanded="false"
				aria-controls="${ id }-list" aria-autocomplete="list" autocomplete="off" disabled>
			<ul class="sswi-listbox" id="${ id }-list" role="listbox" hidden></ul>
			<input type="hidden" name="${ name }" value="" data-sswi-value>
			<p class="sswi-sr" id="${ id }-live" role="status" aria-live="polite"></p>
			<p class="sswi-error" id="${ id }-error" data-sswi-error hidden></p>
		</div>`;
}

function markup( product, config ) {
	const cargoOnly = `
		<div class="sswi-field sswi-destinations" data-sswi-destinations data-sswi-field="destinations">
			<label for="sswi-destinations">Destinations</label>
			<ul class="sswi-chips" data-sswi-chips></ul>
			<input type="text" id="sswi-destinations" role="combobox" aria-expanded="false"
				aria-controls="sswi-destinations-list" aria-autocomplete="list" autocomplete="off" disabled>
			<ul class="sswi-listbox" id="sswi-destinations-list" role="listbox" hidden></ul>
			<p class="sswi-sr" role="status" aria-live="polite" data-sswi-chip-live></p>
			<p class="sswi-error" id="sswi-destinations-error" data-sswi-error hidden></p>
		</div>
		<div class="sswi-field" data-sswi-field="riskZone">
			<label for="sswi-riskZone">Risk zone</label>
			<select id="sswi-riskZone" name="riskZone" data-sswi-risk-zone required disabled>
				<option value="">Choose</option>
			</select>
			<p class="sswi-error" id="sswi-riskZone-error" data-sswi-error hidden></p>
		</div>`;

	return `<!doctype html><html><body>
	<div class="spotship-wi" data-sswi-root data-sswi-config='${ JSON.stringify( config ) }'>
		<p class="sswi-noscript" data-sswi-noscript>Needs JavaScript</p>
		<form class="sswi-form" data-sswi-form="${ product }" novalidate>
			<div class="sswi-summary" data-sswi-summary tabindex="-1" hidden></div>
			${ field( 'userName', 'sswi-userName' ) }
			${ field( 'userEmail', 'sswi-userEmail' ) }
			${ combo( 'vessel', 'vessel' ) }
			${ combo( 'fromPort', 'location' ) }
			${ 'cargo' === product ? cargoOnly : '' }
			<div class="sswi-field sswi-consent" data-sswi-field="dataConsent">
				<label for="sswi-consent">
					<input type="checkbox" id="sswi-consent" name="dataConsent" value="1" required disabled>
					<span>Consent</span>
				</label>
				<p class="sswi-error" id="sswi-consent-error" data-sswi-error hidden></p>
			</div>
			<div class="sswi-hp" aria-hidden="false">
				<label for="sswi_website">Leave this field empty</label>
				<input type="text" id="sswi_website" name="sswi_website" value="" autocomplete="off" tabindex="-1">
			</div>
			<input type="hidden" name="_sswi_ts" value="1000">
			<input type="hidden" name="_sswi_form_id" value="form-1">
			<input type="hidden" name="_sswi_sig" value="sig-1">
			<button type="submit" class="sswi-submit" disabled>Request a quote</button>
			<p class="sswi-result" data-sswi-result role="status" aria-live="polite"></p>
		</form>
	</div>
	</body></html>`;
}

/**
 * Boot the real script against a fresh DOM.
 *
 * @param {object} options product, config overrides, and a fetch router.
 */
function boot( options = {} ) {
	const product = options.product || 'hull';
	const config = Object.assign( {}, CONFIG, { product }, options.config || {} );

	const dom = new JSDOM( markup( product, config ), {
		url: 'https://example.test/insurance/',
		runScripts: 'outside-only',
	} );

	const calls = [];

	dom.window.fetch = function ( url, init ) {
		calls.push( { url: String( url ), init: init || {} } );

		const handler = options.fetch || ( () => ( { status: 200, body: {} } ) );
		const answer = handler( String( url ), init || {} );

		return Promise.resolve( {
			status: answer.status,
			json: () => Promise.resolve( answer.body ),
		} );
	};

	dom.window.eval( fs.readFileSync( SCRIPT, 'utf8' ) );

	/*
	 * jsdom reports readyState 'loading' when the script is evaluated, so the
	 * script registers a DOMContentLoaded listener and initialises ASYNCHRONOUSLY
	 * — exactly as it does in a browser under `defer`. Tests must await ready()
	 * before asserting anything, or they assert against an uninitialised form and
	 * pass for the wrong reason.
	 */
	const ready = new Promise( ( resolve ) => {
		if ( 'loading' !== dom.window.document.readyState ) {
			resolve();
			return;
		}

		dom.window.document.addEventListener( 'DOMContentLoaded', () => resolve() );
	} );

	return {
		ready: () => ready.then( () => new Promise( ( r ) => setTimeout( r, 0 ) ) ),
		dom,
		window: dom.window,
		document: dom.window.document,
		calls,
		root: dom.window.document.querySelector( '[data-sswi-root]' ),
		form: dom.window.document.querySelector( '[data-sswi-form]' ),
		// Let queued promise callbacks and the 200ms debounce run.
		settle: ( ms = 0 ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) ),
	};
}

module.exports = { boot, CONFIG, SCRIPT };
