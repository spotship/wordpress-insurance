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
const { execFileSync } = require( 'child_process' );

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
	'data-sswi-consent',
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

test( 'the anti-bot thresholds are never published to the browser', () => {
	/*
	 * THE INVERSE OF THE TEST ABOVE, AND IT HAS THE OPPOSITE SIGN.
	 *
	 * A BOUND must be published, because enforcing an undisclosed limit turns
	 * the hoist above the decoy gates into a probe. A TRAP must not be, because
	 * publishing it tells a bot author exactly how to avoid it.
	 *
	 * `minAgeSeconds` was published for the whole life of this file and never
	 * read by any JavaScript. It bought nothing and it printed the decoy's
	 * threshold into the page source: sleep that long and the timing trap never
	 * fires. A guard that tells you how to get past it is not a guard.
	 */
	const forbidden = [ 'minAgeSeconds', 'MIN_AGE', 'MAX_AGE', 'FAKE_SUCCESS' ];

	forbidden.forEach( ( name ) => {
		assert.ok(
			! new RegExp( `'[a-zA-Z]+'\\s*=>\\s*[^,]*${ name }` ).test( RENDERER ),
			`Renderer::config() publishes "${ name }" to the browser — an anti-bot threshold must not be readable from the page`
		);
	} );

	// Non-vacuity: the regex above must be capable of matching this file at
	// all, or the assertions pass by never looking at anything.
	assert.ok(
		/'[a-zA-Z]+'\s*=>\s*[^,]*MAX_DESTINATIONS/.test( RENDERER ),
		'the published-key pattern matched nothing, so the assertions above prove nothing'
	);
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

test( 'the rate table reads the payload the rates endpoint actually returns', () => {
	/*
	 * THE SAME FAILURE AS THE 201 BODY, ONE ENDPOINT OVER, AND IT SHIPPED.
	 *
	 * The rates body was hand-written flat — a top-level `columns` array of
	 * plain strings, a top-level row list per product, rows keyed `zone`, rates
	 * preformatted as '8.500%'. GET /api/insurance/rates/ emits none of that.
	 * It nests columns, zones, disclaimers and footnotes under EACH PRODUCT,
	 * makes every column an object of { key, label }, keys every row `name`,
	 * and sends every rate as a JSON number.
	 *
	 * Against the real backend that rendered a table with no header cells and
	 * four empty body rows — one per key of the product object — and dropped
	 * the disclaimers entirely, which the partial itself calls a compliance
	 * failure. Nothing caught it because the stand-in upstream served the
	 * invented shape back.
	 *
	 * So this asserts the TEMPLATE against the DERIVED fixture, not against the
	 * stand-in. Both would have to drift together to get past it.
	 */
	const derived = JSON.parse( read( 'tests/fixtures/backend-rates.json' ) );
	const table = read( 'templates/partials/rates-table.php' );

	for ( const product of [ 'hull', 'cargo' ] ) {
		const section = derived[ product ];

		assert.ok( Array.isArray( section.zones ), `${ product }.zones must be a list` );
		assert.ok( Array.isArray( section.columns ), `${ product }.columns must be a list` );
		assert.ok( section.disclaimers.length > 0, `${ product } must carry disclaimers` );

		for ( const column of section.columns ) {
			assert.equal( typeof column.key, 'string', 'a column carries a key' );
			assert.equal( typeof column.label, 'string', 'a column carries a label' );
		}

		for ( const zone of section.zones ) {
			assert.equal( typeof zone.name, 'string', `${ product } rows are keyed name` );
			for ( const { key } of section.columns ) {
				assert.ok( key in zone, `${ product } ${ zone.name } must carry ${ key }` );
				assert.ok(
					zone[ key ] === null || typeof zone[ key ] === 'number',
					`${ product } ${ zone.name }.${ key } is a JSON number, not a display string`
				);
			}
		}
	}

	// Hull's Hormuz Passage is the commercial outlier the footnotes explain,
	// and cargo's has no net rate at all. Both are load-bearing: a renderer
	// that cannot show an em dash for a real null is wrong about that row.
	assert.ok(
		derived.cargo.zones.some( ( z ) => z.netRate === null ),
		'cargo must still carry a null net rate'
	);
	assert.ok( derived.hull.footnotes.length > 0, 'hull must still carry footnotes' );

	// And the template must read all of it out of the product section.
	assert.ok(
		/\$sswi_section\s*\[\s*'zones'\s*\]|\$sswi_section\['zones'\]/.test( table ),
		'rows must come from the product section, not the top level'
	);
	assert.ok(
		! /\$sswi_rates\[\s*'columns'\s*\]/.test( table ),
		'there is no top-level columns list to read'
	);
	assert.ok(
		! /\$sswi_rates\[\s*'disclaimers'\s*\]/.test( table ),
		'there is no top-level disclaimers list to read'
	);
	assert.ok(
		! /\$sswi_row\[\s*'zone'\s*\]/.test( table ),
		'rows are keyed name, not zone'
	);
	assert.ok(
		/rate_percent/.test( table ),
		'a JSON number must be formatted before it is published as a rate'
	);
} );

test( 'a rate number is published as the percentage the other two surfaces show', () => {
	/*
	 * 0.085 is not "0.085". The backend's format_rate_percent() and serica's
	 * Intl.NumberFormat already agree — multiply by 100, round half-up to three
	 * decimals, pad to at least two — and this is the third surface publishing
	 * the same commercial figures, so it has to agree to the digit.
	 */
	const renderer = read( 'includes/class-renderer.php' );
	const body = /public static function rate_percent\([\s\S]*?\n\t\}/.exec( renderer );

	assert.ok( body, 'the renderer must expose rate_percent()' );
	assert.ok( /number_format\(\s*\$percent,\s*3/.test( body[ 0 ] ), 'three decimals, then trimmed' );
	assert.ok( /round\(\s*\(float\) \$rate \* 100/.test( body[ 0 ] ), 'a rate is a fraction, not a percentage' );
	assert.ok( /null === \$rate/.test( body[ 0 ] ), 'a real null renders as an em dash' );
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
		'mintSubmissionId() is the only place a submissionId may be assigned; a ' +
			'second mint site is how a reused id reported a false success'
	);

	// Both retirement paths must go through it. startAttempt() is the confirmed
	// attempt; forget() is the attempt the visitor invalidated mid-flight, which
	// used to clear the submission lock while leaving the id in place.
	[ 'startAttempt', 'forget' ].forEach( ( caller ) => {
		assert.ok(
			new RegExp( caller ).test( script ),
			`${ caller } still exists to retire an id`
		);
	} );

	assert.equal(
		[ ...script.matchAll( /mintSubmissionId\(\)/g ) ].length,
		3,
		'one declaration plus the two retirement paths that must call it'
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
	 * introducer role; and they scope the audience to commercial shipping
	 * businesses. The table is published on a third party's public website, so
	 * omitting them is a compliance failure.
	 *
	 * Serving them from the endpoint is also what makes deferring partner
	 * sign-off tolerable — a wording change becomes a backend deploy rather than
	 * a re-release to every install. That argument only holds while the plugin
	 * renders whatever it is sent, which is what this asserts.
	 *
	 * THIS TEST USED TO ENFORCE THE BUG. It required the template to read
	 * `$sswi_rates['disclaimers']` — the top level — which is precisely where
	 * the endpoint does not put them, so the one check standing guard over a
	 * compliance requirement was pinning the shape that made it fail.
	 */
	const table = read( 'templates/partials/rates-table.php' );

	assert.match( table, /\$sswi_section\[\s*'disclaimers'\s*\]/, 'disclaimers come from the product section' );
	assert.match( table, /foreach \( \$sswi_disclaimers/, 'and render each of them' );
	assert.match( table, /esc_html\( \(string\) \$sswi_disclaimer \)/, 'escaped on output' );

	// The e2e fixture must serve them, or the browser suite would pass over a
	// table that renders none. It is derived now, so this reads the fixture
	// itself rather than grepping the server for a literal.
	const derived = JSON.parse( read( 'tests/fixtures/backend-rates.json' ) );

	for ( const product of [ 'hull', 'cargo' ] ) {
		assert.ok(
			derived[ product ].disclaimers.length > 0,
			`the e2e upstream must serve ${ product } disclaimers`
		);
	}
} );

test( 'every named input can be reached by a field error', () => {
	/*
	 * fieldError() resolves `[data-sswi-field="<name>"]` and RETURNS FALSE when
	 * it finds nothing, which the caller turns into the generic "unavailable"
	 * message. So a wrapper missing the attribute does not throw or log — it
	 * silently tells the visitor the service is down when the real answer was
	 * "that note is too long".
	 *
	 * templates/partials/text-field.php sets the attribute for every input it
	 * renders. The notes textarea is hand-written in both hull.php and
	 * cargo.php, and BOTH omitted it. Counting name attributes against
	 * data-sswi-field wrappers is what makes the next hand-written field fail
	 * here rather than in front of a customer.
	 */
	const NOT_A_FIELD = new Set( [
		'website',      // the honeypot: deliberately unlabelled, never error-reported
		'_sswi_ts',     // hidden anti-replay inputs, not visitor-facing
		'_sswi_form_id',
		'_sswi_sig',
	] );

	/*
	 * Matching from the opening tag with `[^>]*` does NOT work here: every one
	 * of these tags carries `<?php echo … ?>` for its id, so the first `>` is
	 * inside the PHP block and the name attribute is never reached. That
	 * version of this test collected nothing and passed over the very defect it
	 * was written for — bin/mutation-check.js is what caught it.
	 *
	 * Fields rendered through text-field.php get the attribute programmatically
	 * and carry no literal name= here, so what remains is exactly the set of
	 * hand-written inputs, which is the set at risk.
	 */
	for ( const template of [ 'templates/hull.php', 'templates/cargo.php' ] ) {
		const src = read( template );
		const named = [ ...src.matchAll( /\bname="([A-Za-z_][\w]*)"/g ) ]
			.map( ( m ) => m[ 1 ] )
			.filter( ( name ) => ! NOT_A_FIELD.has( name ) );

		assert.ok( named.length > 0, `${ template }: matched no hand-written fields at all` );

		for ( const name of named ) {
			assert.ok(
				src.includes( `data-sswi-field="${ name }"` ),
				`${ template }: "${ name }" has no data-sswi-field wrapper, so an error for it cannot be shown`
			);
		}
	}
} );

test( 'no test class hides a method PHPUnit already declares', () => {
	/*
	 * THE DEFECT THIS GUARDS AGAINST, which cost a full CI cycle.
	 *
	 * `PHPUnit\Framework\TestCase` implements Countable, so `count()` is public
	 * on every test class. A private helper of the same name is not an override
	 * — it is a FATAL error at class-resolution time:
	 *
	 *   Access level to RateLimiterTest::count() must be public
	 *
	 * Nothing local sees it. `php -l` parses one file and this needs the parent
	 * class; PHPStan's paths are the plugin, not tests/; and the jsdom suite
	 * does not run PHP at all. The first thing to notice was five integration
	 * jobs going red at once, every one of them on a fatal that had nothing to
	 * do with what the tests assert.
	 *
	 * A name list is crude, and deliberately so: it catches the handful of
	 * short, tempting names a helper actually reaches for, at the cost of
	 * nothing.
	 */
	const RESERVED = [
		'count',        // Countable — the one that bit
		'run',
		'toString',
		'expects',
		'fail',
		'getName',
		'setName',
		'hasFailed',
		'getStatus',
		'createMock',
		'getMockBuilder',
		'addToAssertionCount',
	];

	const dirs = [ 'tests/unit', 'tests/integration' ];

	let checked = 0;

	for ( const dir of dirs ) {
		for ( const file of fs.readdirSync( path.join( ROOT, dir ) ) ) {
			if ( ! file.endsWith( '.php' ) ) {
				continue;
			}

			checked += 1;

			const src = read( `${ dir }/${ file }` );
			const declared = [ ...src.matchAll( /function\s+([A-Za-z_]\w*)\s*\(/g ) ].map( ( m ) => m[ 1 ] );

			for ( const name of RESERVED ) {
				assert.ok(
					! declared.includes( name ),
					`${ dir }/${ file }: declares ${ name }(), which PHPUnit's TestCase already defines — that is a fatal error, not an override`
				);
			}
		}
	}

	assert.ok( checked > 0, 'no PHP test files were scanned at all' );
} );

test( 'no template hard-codes an element id', () => {
	/*
	 * Element ids must be unique within a DOCUMENT, and a page may carry more
	 * than one form — hull and cargo on one "Insurance" page is the expected
	 * layout, and the same shortcode twice is legal. A hard-coded id therefore
	 * collides, and a colliding id silently re-points every later label at the
	 * FIRST form's input: clicking "Email" on the second form focuses the first.
	 *
	 * That is WCAG 1.3.1 and 4.1.2, and no axe run could have caught it, because
	 * every Playwright page rendered exactly one form. The suite was green and
	 * the defect was real.
	 *
	 * Admin markup is exempt: wp-admin renders one settings screen per request.
	 */
	const offenders = [];

	[
		'templates/hull.php',
		'templates/cargo.php',
		'templates/partials/text-field.php',
		'templates/partials/combobox.php',
		'templates/partials/consent.php',
		'templates/partials/rates-table.php',
	].forEach( ( file ) => {
		const src = read( file );
		const re = /\b(?:id|for|aria-controls|aria-describedby|aria-labelledby)="([^"<]*)"/g;
		let m;

		while ( ( m = re.exec( src ) ) !== null ) {
			// A literal value with no PHP in it is a constant id.
			if ( ! m[ 1 ].includes( '<?php' ) && '' !== m[ 1 ].trim() ) {
				offenders.push( `${ file }: ${ m[ 0 ] }` );
			}
		}
	} );

	assert.deepEqual(
		offenders,
		[],
		'these ids are constant, so a second form on the page duplicates them:\n' + offenders.join( '\n' )
	);
} );

test( 'every partial derives its element ids from the instance prefix', () => {
	/*
	 * The sibling test above scans for literal id="..." attributes, and CANNOT
	 * see this: the partials write id="<?php echo esc_attr( $sswi_id ); ?>",
	 * which is dynamic markup whatever $sswi_id holds. Reverting the assignment
	 * to a constant therefore left that test green — found by
	 * bin/mutation-check.js, which is the whole reason it exists.
	 *
	 * So assert the assignment itself.
	 */
	const partials = {
		'templates/partials/text-field.php': '$sswi_name',
		'templates/partials/combobox.php': '$sswi_combo_name',
	};

	Object.entries( partials ).forEach( ( [ file, nameVar ] ) => {
		const src = read( file );
		const assignment = /\$sswi_id\s*=\s*(.+);/.exec( src );

		assert.ok( assignment, `${ file } must assign $sswi_id` );
		assert.ok(
			assignment[ 1 ].includes( '$sswi_uid' ),
			`${ file } builds $sswi_id as ${ assignment[ 1 ] } — a constant prefix collides ` +
				'with a second form on the page'
		);
		assert.ok(
			assignment[ 1 ].includes( nameVar ),
			`${ file } must still distinguish fields by ${ nameVar }`
		);
	} );

	// The two templates that build ids inline rather than through a partial.
	[ 'templates/hull.php', 'templates/cargo.php', 'templates/partials/consent.php' ].forEach( ( file ) => {
		const src = read( file );
		const locals = [ ...src.matchAll( /^\$sswi_\w*(?:_id|_err|_list)\s*=\s*(.+);$/gm ) ];

		assert.ok( locals.length > 0, `${ file } must build its ids as named locals` );

		locals.forEach( ( m ) => {
			assert.ok(
				m[ 1 ].includes( '$sswi_uid' ) || m[ 1 ].includes( '_id .' ),
				`${ file } builds an id as ${ m[ 1 ] }, which is not instance-scoped`
			);
		} );
	} );
} );

test( 'the renderer namespaces ids per render, from the form uuid', () => {
	/*
	 * The namespace is the mechanism every template id depends on. If it goes
	 * away, every id silently becomes constant again.
	 *
	 * IT ALSO MUST NOT COME FROM A COUNTER. A per-request static restarts at
	 * zero in every PHP process, and Elementor's editor renders each widget in
	 * its own AJAX request — so a counter handed every form `sswi-1` and two
	 * widgets on a page collided on every id. The form uuid is minted per
	 * render, so it cannot depend on how often this process has been called.
	 */
	assert.match(
		RENDERER,
		/\$uid\s*=\s*'sswi-' \. substr\( str_replace\( '-', '', \$form_id \), 0, 8 \);/,
		'the id namespace must be derived from the per-render form uuid'
	);
	assert.match( RENDERER, /'uid'\s*=>\s*\$uid,/, 'and be published to the templates' );
	assert.ok(
		! /static int \$instances/.test( RENDERER ),
		'the id namespace must not return to a per-request counter: it restarts at zero ' +
			'in every process, and Elementor renders each widget in a process of its own'
	);
} );

test( 'assets are registered no earlier than init', () => {
	/*
	 * wp_register_script() raises _doing_it_wrong() when neither `init` nor an
	 * enqueue hook has fired. With WP_DEBUG_DISPLAY on — which staging sites
	 * routinely have — that notice prints BEFORE HEADERS, and WordPress can no
	 * longer set a cookie. Nobody can log in; wp-login.php answers "Cookies are
	 * blocked due to unexpected output" and hands back the form.
	 *
	 * It shipped on `plugins_loaded`. The browser suite found it only because
	 * an admin spec could not reach wp-admin, and it was misdiagnosed three
	 * times first. A structural assertion is cheap next to that.
	 */
	const plugin = read( 'spotship-war-insurance.php' );

	assert.match(
		plugin,
		/add_action\( 'init', 'spotship_wi_boot' \);/,
		'spotship_wi_boot registers assets, so it must not run before init'
	);

	assert.ok(
		! /add_action\(\s*'plugins_loaded'/.test( plugin ),
		'nothing here may hook plugins_loaded: it is before init, and registering ' +
			'a script there prints a notice that breaks cookie-setting site-wide'
	);
} );

test( 'the Elementor widgets depend on a script handle that is actually registered', () => {
	/*
	 * A HANDLE THAT IS NOT REGISTERED IS ENQUEUED AS NOTHING, SILENTLY.
	 *
	 * get_script_depends() returns handles; WordPress drops any it does not
	 * know, with no notice and no error. The symptom would be a form that works
	 * on a published page and is inert the moment Elementor injects one — which
	 * is the exact defect this script was added to fix, restored by a typo.
	 *
	 * Nothing else would catch it. PHPStan sees two strings; PHPCS sees two
	 * strings; the front-end e2e never injects anything.
	 */
	const widget = read( 'includes/class-elementor-widget.php' );
	const elementor = read( 'includes/class-elementor.php' );
	const plugin = read( 'spotship-war-insurance.php' );

	const depends = /get_script_depends\(\): array \{\s*return array\( ([^)]+) \);/.exec( widget );

	assert.ok( depends, 'the widget base must declare get_script_depends()' );
	assert.equal(
		depends[ 1 ].trim(),
		'Spotship_WI_Elementor::HANDLER_SCRIPT',
		'the handle must come from the constant, not a repeated literal'
	);

	const handle = /HANDLER_SCRIPT = '([^']+)';/.exec( elementor );

	assert.ok( handle, 'Spotship_WI_Elementor must define HANDLER_SCRIPT' );
	assert.match(
		plugin,
		new RegExp( 'wp_register_script\\(\\s*Spotship_WI_Elementor::HANDLER_SCRIPT' ),
		`${ handle[ 1 ] } is depended on but never registered`
	);

	// And the file it points at has to exist, for the same reason.
	const src = /HANDLER_SCRIPT,\s*SPOTSHIP_WI_URL \. '([^']+)'/.exec( plugin );

	assert.ok( src, 'the registration must name a file' );
	assert.ok(
		fs.existsSync( path.join( ROOT, src[ 1 ] ) ),
		`${ src[ 1 ] } is registered but not in the tree`
	);
} );

test( 'the customisable accent never lands on a text-contrast surface', () => {
	/*
	 * --spotship-wi-accent is the one colour a customer sets, and there is no
	 * CSS-only way to keep text readable over an arbitrary value of it. So the
	 * rule is structural rather than computed: the token may paint borders and
	 * focus rings, and must never be a background or a text colour.
	 *
	 * This is invisible to axe by construction. axe only ever renders the
	 * DEFAULT accent, which passes at 5.1:1 — the violation appears only on a
	 * customer's site, with a colour no CI run will ever see. It shipped as
	 * `background: var( --spotship-wi-accent )` behind `color: #fff`, and the
	 * file's own docblock licensed it by calling the button background a
	 * non-text surface.
	 */
	const css = read( 'assets/css/war-insurance.css' ).replace( /\/\*[\s\S]*?\*\//g, '' );
	const TOKEN = '--spotship-wi-accent';

	// border-color and outline-color are deliberately absent: those are the
	// non-text surfaces the token exists for.
	const TEXT_SURFACE = /^(background|background-color|background-image|color)$/;

	const offenders = [];

	for ( const declaration of css.split( ';' ) ) {
		const [ head, ...rest ] = declaration.split( ':' );
		const property = head.trim().split( /\s+/ ).pop();

		if ( TEXT_SURFACE.test( property ) && rest.join( ':' ).includes( TOKEN ) ) {
			offenders.push( declaration.trim().replace( /\s+/g, ' ' ) );
		}
	}

	assert.deepStrictEqual(
		offenders,
		[],
		`${ TOKEN } is customer-set and must not sit behind or colour text; ` +
			'use a literal that is known to meet AA against the text on it'
	);

	// Guard the guard: deleting the token altogether would also empty the list
	// above, and pass for the wrong reason.
	assert.ok(
		css.includes( `var( ${ TOKEN } )` ),
		`${ TOKEN } should still brand the non-text surfaces it is for`
	);
} );

test( 'no tracked file reaches the customer zip without a decision', () => {
	/*
	 * bin/build-dist.sh checks the BUILT zip against an allow-list, which is
	 * the right shape — a blocklist fails open. But that check runs only in
	 * the release workflow, on a tag, and the release workflow has never run.
	 *
	 * So playwright.real-backend.config.js sat in the repo unaccounted for: absent
	 * from .distignore, absent from ALLOWED. The next release tag would have
	 * failed the build, at the least convenient moment. This runs the same
	 * decision against the tracked tree on every push instead.
	 *
	 * .distignore said `playwright.config.js` — a filename, not a shape — so
	 * adding a second config silently opted it in. It is now a glob.
	 */
	const patterns = read( '.distignore' )
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.filter( ( line ) => line && ! line.startsWith( '#' ) );

	const declared = ( read( 'bin/build-dist.sh' ).match( /^ALLOWED="([^"]*)"/m ) || [] )[ 1 ];

	assert.ok( declared, 'bin/build-dist.sh must declare ALLOWED for this to mean anything' );

	const ships = new Set( declared.split( /\s+/ ).filter( Boolean ) );

	// rsync --exclude-from globs, so a pattern is not necessarily a filename.
	const excluded = ( name ) =>
		patterns.some( ( pattern ) =>
			new RegExp(
				`^${ pattern.replace( /[.+^${}()|[\]\\]/g, '\\$&' ).replace( /\*/g, '[^/]*' ) }$`
			).test( name )
		);

	const tracked = execFileSync( 'git', [ 'ls-tree', '--name-only', 'HEAD' ], {
		cwd: ROOT,
		encoding: 'utf8',
	} )
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.filter( Boolean );

	const undecided = tracked.filter( ( name ) => ! excluded( name ) && ! ships.has( name ) );

	assert.deepStrictEqual(
		undecided,
		[],
		'each of these would be unpacked onto a customer\'s public web server. ' +
			'Add it to .distignore, or to ALLOWED in bin/build-dist.sh if it genuinely ships'
	);
} );
