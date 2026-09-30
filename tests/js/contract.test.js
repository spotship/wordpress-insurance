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

test( 'the e2e upstream fixture emits exactly the keys the backend serializer does', () => {
	/*
	 * The shape of a genuine 201 is not ours to assert. A previous
	 * implementation hand-wrote a shape the API never emits, the anti-bot decoy
	 * was built to imitate that fixture, and the resulting tell shipped.
	 *
	 * tests/fixtures/backend-201-keys.json is DERIVED from the backend
	 * serializer by bin/derive-backend-keys.sh. This asserts the e2e fixture
	 * agrees with it, so the fixture cannot drift back into fiction without a
	 * test going red.
	 */
	const derived = JSON.parse( read( 'tests/fixtures/backend-201-keys.json' ) );
	const src = read( 'bin/e2e-upstream.js' );

	const keysOf = ( fn ) => {
		const body = new RegExp( `function ${ fn }\\( body \\) \\{([\\s\\S]*?)\\n\\}` ).exec( src );
		assert.ok( body, `${ fn }() must exist in the e2e fixture` );
		return new Set( [ ...body[ 1 ].matchAll( /^\s*([A-Za-z]+):/gm ) ].map( ( m ) => m[ 1 ] ) );
	};

	const provenance = keysOf( 'provenance' );
	const hull = new Set( [ ...keysOf( 'hullEcho' ), ...provenance ] );
	const cargo = new Set( [ ...keysOf( 'cargoEcho' ), ...provenance ] );

	assert.deepEqual(
		[ ...hull ].sort(),
		[ ...derived.hull ].sort(),
		'the hull fixture must emit exactly the serializer\'s keys'
	);

	assert.deepEqual(
		[ ...cargo ].sort(),
		[ ...derived.cargo ].sort(),
		'the cargo fixture must emit exactly the serializer\'s keys'
	);

	// The two documented absences, asserted rather than assumed.
	assert.ok( ! derived.hull.includes( 'id' ), 'the API exposes no id' );
	assert.ok( ! derived.hull.includes( 'status' ), 'the API emits no status' );
} );

test( 'the e2e upstream routes cargo to the cargo shape, not the hull one', () => {
	/*
	 * `cargo_quote_request` CONTAINS `quote_request`, so a generic substring
	 * check matches both and silently serves hull for cargo. The key-set test
	 * above cannot catch that — it checks the functions, not which one the route
	 * reaches — so this drives the routing directly.
	 */
	const { route } = require( '../../bin/e2e-upstream.js' );
	const derived = JSON.parse( read( 'tests/fixtures/backend-201-keys.json' ) );

	const hull = route( '/insurance/quote_request/', {} );
	const cargo = route( '/insurance/cargo_quote_request/', {} );

	assert.equal( hull.status, 201 );
	assert.equal( cargo.status, 201 );

	assert.deepEqual( Object.keys( hull.body ).sort(), [ ...derived.hull ].sort() );
	assert.deepEqual( Object.keys( cargo.body ).sort(), [ ...derived.cargo ].sort() );

	// Named explicitly so a regression reads as what it is.
	assert.ok(
		'cargoDescription' in cargo.body,
		'the cargo route must not be serving the hull shape'
	);
	assert.ok( ! ( 'handmValue' in cargo.body ), 'hull-only fields must not appear on cargo' );
} );

/*
 * The three single-owner invariants below were each stated in a docblock and
 * each drifted anyway — one of them after being written down. A comment cannot
 * enforce "only one place assigns this"; these tests can.
 *
 * They are deliberately structural. They read the source and count, which is
 * crude, but it is the only thing that fails when somebody adds a SECOND writer
 * in a new function that behaves correctly on its own.
 */

test( 'exactly one place assigns the committed combobox id', () => {
	const script = read( 'assets/js/war-insurance.js' );
	const writes = [ ...script.matchAll( /^\s*hidden\.value\s*=/gm ) ];

	assert.equal(
		writes.length,
		1,
		'hidden.value must be written only by commit(); a second writer is how the ' +
			'submitted id and the displayed label drifted apart last time'
	);
} );

test( 'every aria-activedescendant is derived by optionId', () => {
	const script = read( 'assets/js/war-insurance.js' );

	const sets = [ ...script.matchAll( /setAttribute\(\s*'aria-activedescendant',\s*([^)]+)\)/g ) ];

	assert.ok( sets.length >= 2, 'both listboxes set aria-activedescendant' );

	sets.forEach( ( m ) => {
		assert.match(
			m[ 1 ].trim(),
			/^optionId\(/,
			'a listbox deriving its own option ids is exactly the stale-id defect'
		);
	} );
} );

test( 'exactly one place mints a submissionId after the first', () => {
	const script = read( 'assets/js/war-insurance.js' );
	const mints = [ ...script.matchAll( /^\s*submissionId\s*=\s*uuid\(\)/gm ) ];

	assert.equal(
		mints.length,
		1,
		'startAttempt() is the only place a submissionId may be minted; a second ' +
			'mint site is how a reused id reported a false success'
	);
} );

test( 'the translation catalogue covers every translatable string in the source', () => {
	/*
	 * A POT goes stale silently: nothing breaks, the plugin keeps working, and
	 * the omission only surfaces when a translator asks why a string is missing —
	 * by which point it has usually been missing for months.
	 *
	 * This uses the SAME extractor as bin/refresh-pot.sh, so the thing that
	 * generates the catalogue and the thing that checks it cannot disagree about
	 * what counts as a string, which is the usual way a freshness check passes
	 * over a stale file.
	 */
	const { extract } = require( '../../bin/extract-strings.js' );

	const found = extract( path.join( __dirname, '..', '..' ) );
	const pot = read( 'languages/spotship-war-insurance.pot' );

	assert.ok( found.length > 50, `the extractor must find the catalogue's worth of strings, got ${ found.length }` );

	const missing = found.filter( ( s ) => {
		const escaped = s.replace( /\\'/g, "'" ).replace( /"/g, '\\"' );
		return ! pot.includes( `msgid "${ escaped }"` );
	} );

	assert.deepEqual(
		missing,
		[],
		'these strings are translatable in the source but absent from the POT — run bin/refresh-pot.sh'
	);
} );

test( 'the scrollable rate table is reachable by keyboard', () => {
	/*
	 * At 320px the table overflows horizontally, and a scroll container that
	 * cannot be focused is pointer-only — a keyboard visitor cannot read the
	 * columns past the fold. axe caught this as scrollable-region-focusable
	 * (WCAG 2.1.1 and 2.1.3) in the narrow project, and only there: the desktop
	 * project was green on the same markup.
	 */
	const table = read( 'templates/partials/rates-table.php' );
	const css = read( 'assets/css/war-insurance.css' );

	assert.match( table, /class="sswi-rates-scroll"[^>]*tabindex="0"/, 'the scroll container must be focusable' );
	assert.match( table, /role="region"/, 'and be a landmark' );
	assert.match( table, /aria-label="<\?php esc_attr_e\( 'Indicative war risk rates'/, 'with an accessible name' );

	// The overflow must live on the focusable element, not on an ancestor —
	// moving it back up is what recreates the violation.
	assert.match( css, /\.sswi-rates-scroll \{\s*overflow-x: auto;/, 'overflow belongs to the focusable container' );
	assert.ok(
		! /\.spotship-wi \.sswi-rates \{[^}]*overflow-x/.test( css ),
		'.sswi-rates must not scroll: it also holds the date, the stale warning and the disclaimers'
	);
	assert.match( css, /\.sswi-rates-scroll:focus-visible/, 'a focusable element needs a visible focus ring' );
} );

test( 'the rate table renders the disclaimers the endpoint serves', () => {
	/*
	 * These are not decoration. They state the figures are indicative only, not
	 * an offer or a quote, and subject to underwriting; they name Spot Ship's
	 * introducer role and the insurance partner; and they scope the audience to
	 * commercial shipping businesses. The table is published on a third party's
	 * public website, so omitting them is a compliance failure.
	 *
	 * Serving them from the endpoint is also what makes deferring partner
	 * sign-off tolerable — a wording change becomes a backend deploy rather than
	 * a re-release to every install. That argument only holds while the plugin
	 * renders whatever it is sent, which is what this asserts.
	 */
	const table = read( 'templates/partials/rates-table.php' );

	assert.match( table, /\$sswi_rates\[\s*'disclaimers'\s*\]/, 'the template must read the disclaimers' );
	assert.match( table, /foreach \( \$sswi_disclaimers/, 'and render each of them' );
	assert.match( table, /esc_html\( \(string\) \$sswi_disclaimer \)/, 'escaped on output' );

	// The e2e fixture must serve them, or the browser suite would pass over a
	// table that renders none.
	const fixture = read( 'bin/e2e-upstream.js' );
	assert.match( fixture, /disclaimers:/, 'the e2e upstream must serve disclaimers' );
} );
