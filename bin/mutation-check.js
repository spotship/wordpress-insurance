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
 * TWO SUITES. `--suite=js` (the default) mutates source the jsdom tests cover
 * and needs nothing but Node. `--suite=php` mutates source the PHPUnit
 * integration tests cover and needs wp-env, so it runs in the integration job
 * instead. Splitting them is not cosmetic: without the php suite the
 * integration tests had no fail-before-fix evidence at all, which is the same
 * unverifiable claim this file exists to replace.
 *
 * Usage: node bin/mutation-check.js [--suite=js|php]
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const ROOT = path.join( __dirname, '..' );

const MUTATIONS = [
	{
		suite: 'js',
		what: 'the lookup reads only the body and ignores the HTTP status',
		file: 'assets/js/war-insurance.js',
		from: 'if ( ! answer.ok || ( answer.body && answer.body.unavailable ) ) {',
		to: 'if ( answer.body && answer.body.unavailable ) {',
		mustFail: [
			'a lookup refused by our own proxy blocks submission rather than reading as zero results',
		],
	},
	{
		suite: 'js',
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
		suite: 'js',
		what: 'text fields go back to a constant element id',
		file: 'templates/partials/text-field.php',
		from: "$sswi_id      = $sswi_uid . '-' . $sswi_name;",
		to: "$sswi_id      = 'sswi-' . $sswi_name;",
		mustFail: [ 'every partial derives its element ids from the instance prefix' ],
	},
	{
		suite: 'js',
		what: 'the renderer stops namespacing instances',
		file: 'includes/class-renderer.php',
		from: "'uid'       => 'sswi-' . self::$instances,",
		to: "'uid'       => 'sswi',",
		mustFail: [ 'the renderer namespaces ids per instance' ],
	},

	// --- php: driven by the PHPUnit integration suite, needs wp-env ---

	{
		suite: 'php',
		what: 'the renderer stops namespacing instances (rendered output)',
		file: 'includes/class-renderer.php',
		from: "'uid'       => 'sswi-' . self::$instances,",
		to: "'uid'       => 'sswi',",
		// The js mutation above proves the TEMPLATES read the prefix. This one
		// proves the rendered markup of two real forms on one page actually
		// comes out unique — the WCAG defect itself, not its mechanism.
		mustFail: [
			'test_hull_and_cargo_on_one_page_share_no_ids',
			'test_the_same_form_twice_shares_no_ids',
		],
	},
	{
		suite: 'php',
		what: 'the one-click re-bind goes back through the ordinary sanitizer',
		file: 'includes/class-settings.php',
		from: 'if ( self::$rebinding ) {',
		to: 'if ( false ) {',
		// The shipped defect: bind_to_current() reported success and did not
		// re-bind, on the documented cloned-site recovery path.
		mustFail: [
			'test_bind_to_current_persists_through_the_sanitizer',
			'test_rebinding_does_not_disturb_the_rest_of_the_configuration',
		],
	},
	{
		suite: 'php',
		what: 'the backend grows a response field nobody mapped',
		file: 'tests/fixtures/backend-201-keys.json',
		from: '"hull": [',
		to: '"hull": [\n    "aFieldNobodyMapped",',
		// Mutating the DERIVED FIXTURE rather than the source, because what is
		// under test here is the drift alarm itself: RestProxyTest builds its
		// genuine 201 from this file, so a backend field it has no value for
		// must stop the suite rather than silently shrink the shape the decoy
		// is measured against.
		mustFail: [
			'test_the_decoy_and_a_genuine_acceptance_share_one_body_shape',
		],
	},
];

const SUITE = ( process.argv.slice( 2 ).find( ( a ) => a.startsWith( '--suite=' ) ) || '--suite=js' )
	.slice( '--suite='.length );

if ( ! [ 'js', 'php' ].includes( SUITE ) ) {
	process.stdout.write( `unknown suite "${ SUITE }" — expected js or php\n` );
	process.exit( 1 );
}

const SELECTED = MUTATIONS.filter( ( m ) => m.suite === SUITE );

const JUNIT = 'mutation-junit.xml';

/**
 * How to run the integration suite. Overridable because the wp-env invocation
 * is the CI job's business, not this file's.
 *
 * @returns {string} shell command.
 */
function phpunitCommand() {
	return (
		process.env.MUTATION_PHPUNIT_CMD ||
		`npx wp-env run tests-cli --env-cwd=wp-content/plugins/${ path.basename( ROOT ) } ` +
			`vendor/bin/phpunit -c phpunit-integration.xml.dist --log-junit ${ JUNIT }`
	);
}

/**
 * Run the jsdom suite and return the set of failing test names.
 *
 * @returns {Set<string>} names reported as `not ok`.
 */
function failingJs() {
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

/**
 * Run the PHPUnit integration suite and return the set of failing test names.
 *
 * Read from the JUnit report rather than the console output: PHPUnit's
 * human-readable format prints a dot per test and names a failure only in a
 * summary block, so scraping it is guesswork. The report is a contract.
 *
 * @returns {Set<string>|null} names, or null if the run produced no report.
 */
function failingPhp() {
	const report = path.join( ROOT, JUNIT );

	if ( fs.existsSync( report ) ) {
		fs.unlinkSync( report );
	}

	spawnSync( phpunitCommand(), {
		cwd: ROOT,
		shell: true,
		encoding: 'utf8',
		stdio: [ 'ignore', 'ignore', 'inherit' ],
		maxBuffer: 32 * 1024 * 1024,
	} );

	// NO REPORT IS NOT THE SAME AS NO FAILURES. A fatal, a container that never
	// came up, or a mutation that broke the parse all land here, and returning
	// an empty set would read as "the suite was green".
	if ( ! fs.existsSync( report ) ) {
		return null;
	}

	const xml = fs.readFileSync( report, 'utf8' );
	const failing = new Set();

	// Split on the element boundary so each chunk ends where the next testcase
	// begins; that keeps a self-closing <testcase … /> from swallowing a later
	// sibling's <failure>.
	for ( const chunk of xml.split( '<testcase' ).slice( 1 ) ) {
		const name = ( chunk.match( /^[^>]*\bname="([^"]*)"/ ) || [] )[ 1 ];
		const body = chunk.split( '</testcase>' )[ 0 ];

		if ( name && ( body.includes( '<failure' ) || body.includes( '<error' ) ) ) {
			failing.add( name );
		}
	}

	return failing;
}

/**
 * @returns {Set<string>|null} failing test names for the selected suite.
 */
function failingTests() {
	return 'php' === SUITE ? failingPhp() : failingJs();
}

/**
 * PHPUnit appends the data-set label to a parameterised test's name, so an
 * exact comparison would miss `… with data set "cargo"`.
 *
 * @param {Set<string>} failed Names reported as failing.
 * @param {string}      want   The name the mutation must make red.
 * @returns {boolean} whether `want` is among them.
 */
function isRed( failed, want ) {
	return failed.has( want ) || [ ...failed ].some( ( n ) => n.startsWith( `${ want } ` ) );
}

let broken = 0;

// Fail loudly if the suite is not green to begin with: every result below would
// otherwise be meaningless.
const baseline = failingTests();

if ( null === baseline ) {
	process.stdout.write( `the ${ SUITE } suite produced no report at all; cannot mutate\n` );
	process.exit( 1 );
}

if ( baseline.size > 0 ) {
	process.stdout.write(
		'the suite must be green before mutating; currently failing:\n  ' +
			[ ...baseline ].join( '\n  ' ) +
			'\n'
	);
	process.exit( 1 );
}

process.stdout.write( `baseline: green (${ SUITE } suite, ${ SELECTED.length } mutation(s))\n\n` );

for ( const mutation of SELECTED ) {
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

	if ( null === failed ) {
		process.stdout.write(
			`NO REPORT  ${ mutation.what }\n           the suite produced no report under this mutation.\n\n`
		);
		broken += 1;
		continue;
	}

	const undetected = mutation.mustFail.filter( ( name ) => ! isRed( failed, name ) );

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

process.stdout.write( `all ${ SELECTED.length } ${ SUITE } mutation(s) detected.\n` );
