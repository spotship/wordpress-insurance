#!/usr/bin/env node
/**
 * Proves the regression tests actually detect the defects they describe.
 *
 * A test is only worth its runtime if it fails when the bug is reintroduced.
 * Claiming "proven red first" in a commit message is unverifiable a week later,
 * and a test that silently stopped detecting anything looks exactly like a test
 * that passes. So the proof runs in CI, every time.
 *
 * Each mutation below reintroduces one shipped defect by editing the source the
 * way it actually read before the fix. The named tests MUST go red. If a
 * mutation leaves the suite green, the test has stopped earning its place and
 * this exits non-zero.
 *
 * Usage: node bin/mutation-check.js
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const ROOT = path.join( __dirname, '..' );

const MUTATIONS = [
	{
		what: 'the lookup reads only the body and ignores the HTTP status',
		file: 'assets/js/war-insurance.js',
		from: 'if ( ! answer.ok || ( answer.body && answer.body.unavailable ) ) {',
		to: 'if ( answer.body && answer.body.unavailable ) {',
		mustFail: [
			'a lookup refused by our own proxy blocks submission rather than reading as zero results',
		],
	},
	{
		what: 'a throttled submit falls through to the generic sentence',
		file: 'assets/js/war-insurance.js',
		from: '} else if ( 429 === answer.status ) {',
		to: '} else if ( false ) {',
		mustFail: [
			'a throttled submission tells the visitor how long to wait',
			'a throttled submission falls back to the Retry-After header when the body carries no wait',
		],
	},
	{
		what: 'text fields go back to a constant element id',
		file: 'templates/partials/text-field.php',
		from: "$sswi_id      = $sswi_uid . '-' . $sswi_name;",
		to: "$sswi_id      = 'sswi-' . $sswi_name;",
		mustFail: [ 'every partial derives its element ids from the instance prefix' ],
	},
	{
		what: 'the renderer stops namespacing instances',
		file: 'includes/class-renderer.php',
		from: "'uid'       => 'sswi-' . self::$instances,",
		to: "'uid'       => 'sswi',",
		mustFail: [ 'the renderer namespaces ids per instance' ],
	},
];

/**
 * Run the jsdom suite and return the set of failing test names.
 *
 * @returns {Set<string>} names reported as `not ok`.
 */
function failingTests() {
	const result = spawnSync(
		process.execPath,
		[ '--test', 'tests/js/contract.test.js', 'tests/js/front-end.test.js' ],
		{ cwd: ROOT, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 }
	);

	const out = ( result.stdout || '' ) + ( result.stderr || '' );

	return new Set(
		[ ...out.matchAll( /^not ok \d+ - (.+)$/gm ) ].map( ( m ) => m[ 1 ].trim() )
	);
}

let broken = 0;

// Fail loudly if the suite is not green to begin with: every result below would
// otherwise be meaningless.
const baseline = failingTests();

if ( baseline.size > 0 ) {
	process.stdout.write(
		'the suite must be green before mutating; currently failing:\n  ' +
			[ ...baseline ].join( '\n  ' ) +
			'\n'
	);
	process.exit( 1 );
}

process.stdout.write( 'baseline: green\n\n' );

for ( const mutation of MUTATIONS ) {
	const file = path.join( ROOT, mutation.file );
	const original = fs.readFileSync( file, 'utf8' );

	if ( ! original.includes( mutation.from ) ) {
		process.stdout.write(
			`STALE  ${ mutation.what }\n       ${ mutation.file } no longer contains the line this mutation edits.\n` +
				'       Update bin/mutation-check.js alongside the refactor.\n\n'
		);
		broken += 1;
		continue;
	}

	fs.writeFileSync( file, original.replace( mutation.from, mutation.to ) );

	let failed;

	try {
		failed = failingTests();
	} finally {
		fs.writeFileSync( file, original );
	}

	const undetected = mutation.mustFail.filter( ( name ) => ! failed.has( name ) );

	if ( undetected.length > 0 ) {
		process.stdout.write(
			`UNDETECTED  ${ mutation.what }\n` +
				undetected.map( ( n ) => `            "${ n }" stayed green\n` ).join( '' ) +
				'\n'
		);
		broken += 1;
	} else {
		process.stdout.write(
			`detected    ${ mutation.what }\n` +
				mutation.mustFail.map( ( n ) => `            red: ${ n }\n` ).join( '' ) +
				'\n'
		);
	}
}

if ( broken > 0 ) {
	process.stdout.write( `${ broken } mutation(s) went undetected.\n` );
	process.exit( 1 );
}

process.stdout.write( `all ${ MUTATIONS.length } mutations detected.\n` );
