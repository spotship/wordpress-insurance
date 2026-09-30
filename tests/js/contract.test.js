/**
 * Harness-to-template contract.
 *
 * The jsdom harness builds its own DOM. That is a fixture, and a fixture that
 * drifts from the real markup is how a suite goes green over a broken product —
 * the exact failure that let a previous implementation ship a response shape the
 * backend never emits.
 *
 * So: every selector the SCRIPT depends on must exist in the real templates.
 * If a template is renamed or an attribute dropped, this fails even though the
 * behaviour tests keep passing against the harness.
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.join( __dirname, '..', '..' );

function read( ...parts ) {
	return fs.readFileSync( path.join( ROOT, ...parts ), 'utf8' );
}

const TEMPLATES = [
	'templates/hull.php',
	'templates/cargo.php',
	'templates/partials/text-field.php',
	'templates/partials/combobox.php',
	'templates/partials/consent.php',
	'templates/partials/rates-table.php',
].map( ( p ) => read( p ) ).join( '\n' );

const RENDERER = read( 'includes/class-renderer.php' );

/** Hooks the script queries for. Every one must exist in real markup. */
const HOOKS = [
	'data-sswi-root',
	'data-sswi-config',
	'data-sswi-form',
	'data-sswi-noscript',
	'data-sswi-result',
	'data-sswi-summary',
	'data-sswi-combo',
	'data-sswi-value',
	'data-sswi-field',
	'data-sswi-error',
	'data-sswi-destinations',
	'data-sswi-chips',
	'data-sswi-chip-live',
	'data-sswi-risk-zone',
	'sswi-consent',
	'sswi-submit',
	'role="combobox"',
	'role="listbox"',
];

test( 'every DOM hook the script relies on exists in the templates or renderer', () => {
	const haystack = TEMPLATES + RENDERER;

	HOOKS.forEach( ( hook ) => {
		assert.ok(
			haystack.includes( hook ),
			`"${ hook }" is queried by war-insurance.js but appears in no template`
		);
	} );
} );

test( 'the script queries nothing that the templates do not provide', () => {
	const script = read( 'assets/js/war-insurance.js' );
	const haystack = TEMPLATES + RENDERER;

	const queried = new Set();
	const re = /\[(data-sswi-[a-z-]+)/g;
	let m;

	while ( ( m = re.exec( script ) ) !== null ) {
		queried.add( m[ 1 ] );
	}

	assert.ok( queried.size > 0, 'the extraction itself must find something' );

	queried.forEach( ( attr ) => {
		assert.ok( haystack.includes( attr ), `script queries [${ attr }] which no template emits` );
	} );
} );

test( 'the honeypot is rendered, labelled, and named the way the proxy reads it', () => {
	// The proxy reads $form['sswi_website']. A rename on either side silently
	// disables the honeypot — the form keeps working and the decoy never fires.
	assert.ok( RENDERER.includes( 'sswi_website' ), 'renderer must emit the honeypot field' );
	assert.ok( RENDERER.includes( 'Leave this field empty' ), 'honeypot must be labelled for assistive tech' );

	const proxy = read( 'includes/class-rest-proxy.php' );
	assert.ok( proxy.includes( "'sswi_website'" ), 'proxy must read the same field name' );
} );

test( 'every bound the proxy validates is published to the browser', () => {
	// This is what makes hoisting bounds validation above the anti-bot gates
	// leak nothing. If a bound is enforced but not published, the hoist becomes
	// a probe for an undisclosed limit.
	const proxy = read( 'includes/class-rest-proxy.php' );

	const enforced = new Set();
	const re = /'([a-zA-Z]+)'\s*=>\s*Spotship_WI_Payloads::MAX_/g;
	let m;

	while ( ( m = re.exec( proxy ) ) !== null ) {
		enforced.add( m[ 1 ] );
	}

	assert.ok( enforced.size >= 10, `expected the full bounds map, found ${ enforced.size }` );

	enforced.forEach( ( field ) => {
		assert.ok(
			new RegExp( `'${ field }'\\s*=>\\s*Spotship_WI_Payloads::MAX_` ).test( RENDERER ),
			`bound "${ field }" is enforced by the proxy but not published by Renderer::config()`
		);
	} );
} );
