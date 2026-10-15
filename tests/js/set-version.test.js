/**
 * bin/set-version.sh — the changelog it hands a customer.
 *
 * THIS MECHANISM HAS NOW BEEN WRONG THREE TIMES AND HAD NO TEST FOR ANY OF IT.
 *
 * It was appending a section per internal build; then it pruned only the
 * prereleases sharing the release's own base, so `= 1.0.1-dev.1 =` survived a
 * minor bump and would have shipped — it was sitting in readme.txt on `dev`
 * when this was written; and the same flag that said "this run writes a
 * heading" also switched the pruning off, so a hand-written entry kept every
 * `-dev.N` stacked underneath it.
 *
 * Each of those was found by reading the script, and each was verified by
 * running it by hand against a readme built for the occasion. The CI rehearsal
 * in ci.yaml's `release-config` job runs it once, at 99.98.97, against a
 * changelog with no prerelease sections in it at all — so it could not have
 * caught any of the three.
 *
 * These drive the real script, in a copy of the real tree, through the release
 * sequences the branch config actually produces. bin/set-version.sh calls
 * bin/assert-version.sh as its last act, so a run that failed to write one of
 * the four version strings exits non-zero and every helper below throws.
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

const ROOT = path.join( __dirname, '..', '..' );

/** Everything bin/set-version.sh, and the bin/assert-version.sh it calls, read. */
const NEEDED = [
	'bin/set-version.sh',
	'bin/assert-version.sh',
	'spotship-war-insurance.php',
	'languages/spotship-war-insurance.pot',
	'readme.txt',
	'.wp-env.json',
	'composer.json',
	'phpcs.xml.dist',
];

/**
 * A throwaway tree carrying the given changelog.
 *
 * The readme's HEADER is the real one, so assert-version.sh's six support-floor
 * comparisons hold against the real composer.json and phpcs.xml.dist. Only the
 * part from `== Changelog ==` down is a fixture, so these tests state the
 * sequence they are about and do not move every time a release lands.
 *
 * @param {string} changelog Everything after the `== Changelog ==` heading.
 * @returns {string} the directory.
 */
function sandbox( changelog ) {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'sswi-set-version-' ) );

	for ( const rel of NEEDED ) {
		const to = path.join( dir, rel );

		fs.mkdirSync( path.dirname( to ), { recursive: true } );
		fs.copyFileSync( path.join( ROOT, rel ), to );
	}

	const readme = path.join( dir, 'readme.txt' );
	const src = fs.readFileSync( readme, 'utf8' );
	const at = src.indexOf( '== Changelog ==' );

	assert.ok(
		at > -1,
		'readme.txt must still carry a literal "== Changelog ==" heading — set-version.sh anchors to it'
	);

	fs.writeFileSync( readme, src.slice( 0, at ) + '== Changelog ==\n' + changelog );

	return dir;
}

/**
 * One release, exactly as semantic-release's prepare drives it.
 *
 * @param {string} dir     A sandbox.
 * @param {string} version The version being released.
 * @param {string} [notes] `${nextRelease.notes}`, or omitted for the no-release path.
 * @returns {string} readme.txt afterwards.
 */
function release( dir, version, notes ) {
	if ( undefined !== notes ) {
		fs.writeFileSync( path.join( dir, '.sswi-release-notes.md' ), notes );
	}

	try {
		execFileSync( 'bash', [ 'bin/set-version.sh', version ], {
			cwd: dir,
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		} );
	} catch ( e ) {
		assert.fail( `bin/set-version.sh ${ version } exited ${ e.status }: ${ e.stderr }` );
	}

	return fs.readFileSync( path.join( dir, 'readme.txt' ), 'utf8' );
}

/** conventional-changelog's shape, which is what the script has to survive. */
function notesFor( version, subject ) {
	return (
		`## [${ version }](https://github.com/spotship/wordpress-insurance/compare/a...b) (2026-10-15)\n` +
		'\n### Bug Fixes\n\n' +
		`* **scope:** ${ subject } ([abc1234](https://github.com/spotship/wordpress-insurance/commit/abc1234))\n`
	);
}

/** The `= X =` headings INSIDE `== Changelog ==`, newest first. */
function sections( readme ) {
	const from = readme.indexOf( '== Changelog ==' );
	const rest = readme.slice( from + '== Changelog =='.length );
	const next = rest.indexOf( '\n== ' );
	const log = next > -1 ? rest.slice( 0, next ) : rest;

	return [ ...log.matchAll( /^= (.+) =$/gm ) ].map( ( m ) => m[ 1 ] );
}

const SHIPPED = '\n= 1.0.1-dev.1 =\n* An internal build.\n\n= 1.0.0 =\n* First production release.\n';

test( 'a prerelease of an EARLIER base is pruned, not carried forever', ( t ) => {
	/*
	 * THE ONE THAT WAS LIVE. `dev` released v1.0.1-dev.1, then the next change
	 * to land was a feature — so the release was 1.1.0, the base was 1.1.0, and
	 * a predicate anchored to that base could not see `1.0.1-dev.1` at all.
	 * Nothing else ever removed it, so the customer zip would have carried one
	 * orphaned internal build per minor, growing without bound.
	 */
	const dir = sandbox( SHIPPED );

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	const readme = release( dir, '1.1.0', notesFor( '1.1.0', 'a feature landed' ) );

	assert.deepStrictEqual( sections( readme ), [ '1.1.0', '1.0.0' ] );
	assert.ok(
		! readme.includes( 'An internal build.' ),
		'the superseded section\'s BODY must go with its heading'
	);
} );

test( 'a prerelease is pruned even when the section for this release already exists', ( t ) => {
	/*
	 * `have` says whether this run writes a heading. It used to gate the
	 * pruning too, so hand-writing the entry — which set-version.sh's own
	 * comments invite, and which is how the 1.0.0 entry in this readme was
	 * written — left every internal build sitting underneath it, with
	 * assert-version.sh green because the heading it looks for was there.
	 */
	const dir = sandbox(
		'\n= 1.1.0 =\n* Written for a site administrator, not from commit subjects.\n' + SHIPPED
	);

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	const readme = release( dir, '1.1.0', notesFor( '1.1.0', 'a feature landed' ) );

	assert.deepStrictEqual( sections( readme ), [ '1.1.0', '1.0.0' ] );
	assert.ok(
		readme.includes( 'Written for a site administrator' ),
		'a hand-written entry is still left alone — only the internal builds go'
	);
	assert.ok(
		! readme.includes( 'a feature landed' ),
		'and it is not duplicated by a second section generated from the notes'
	);
} );

test( 'the prerelease being written survives its own run, and re-running changes nothing', ( t ) => {
	const dir = sandbox( SHIPPED );

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	const first = release( dir, '1.1.0-dev.1', notesFor( '1.1.0-dev.1', 'the internal build' ) );

	assert.deepStrictEqual( sections( first ), [ '1.1.0-dev.1', '1.0.0' ] );

	// A re-run of the same release — a retried workflow — must be a no-op, not
	// a second copy and not a pruning of the section it just wrote.
	const again = release( dir, '1.1.0-dev.1', notesFor( '1.1.0-dev.1', 'the internal build' ) );

	assert.equal( again, first, 'bin/set-version.sh must be idempotent for a given version' );
} );

test( 'the whole prerelease chain collapses to one section', ( t ) => {
	const dir = sandbox( SHIPPED );

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	let readme;

	for ( const v of [ '1.1.0-dev.1', '1.1.0-dev.2', '1.1.0-dev.3', '1.1.0' ] ) {
		readme = release( dir, v, notesFor( v, 'the same work, four times' ) );
	}

	assert.deepStrictEqual( sections( readme ), [ '1.1.0', '1.0.0' ] );
	assert.equal(
		[ ...readme.matchAll( /the same work, four times/g ) ].length,
		1,
		'four releases of one change must leave one set of bullets, not four'
	);
} );

test( 'released versions are never touched', ( t ) => {
	const dir = sandbox(
		'\n= 1.0.1-dev.1 =\n* An internal build.\n\n= 1.0.0 =\n* First production release.\n' +
			'\n= 0.2.0 =\n* Second.\n\n= 0.1.0 =\n* Initial release.\n'
	);

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	const readme = release( dir, '1.1.0', notesFor( '1.1.0', 'a feature landed' ) );

	assert.deepStrictEqual( sections( readme ), [ '1.1.0', '1.0.0', '0.2.0', '0.1.0' ] );
} );

test( 'a `= X =` heading outside the changelog is out of reach', ( t ) => {
	/*
	 * The predicate matches any heading that looks like a prerelease, so the
	 * `inlog` bound is what stops it reaching prose. `= … =` is legal in a
	 * WordPress readme's Description, FAQ and Upgrade Notice sections, and
	 * rewriting someone's documentation while passing every version check is
	 * the failure this guards.
	 */
	const dir = sandbox(
		SHIPPED + '\n== Upgrade Notice ==\n\n= 1.0.0-rc.1 =\n* Not a changelog entry.\n'
	);

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	const readme = release( dir, '1.1.0', notesFor( '1.1.0', 'a feature landed' ) );

	assert.deepStrictEqual( sections( readme ), [ '1.1.0', '1.0.0' ] );
	assert.ok(
		readme.includes( '= 1.0.0-rc.1 =' ) && readme.includes( 'Not a changelog entry.' ),
		'the Upgrade Notice section must survive untouched'
	);
} );

test( 'a heading elsewhere in the readme does not stand in for the changelog entry', ( t ) => {
	/*
	 * THE PRUNE WAS BOUNDED TO THE CHANGELOG AND THE TWO LOOKUPS WERE NOT.
	 *
	 * `== Upgrade Notice ==` files its entries under headings that are release
	 * versions — that is the section's documented shape, and the sibling test
	 * above already puts one in the fixture. Both the "has this release got a
	 * section?" test and the read-back that proves one was written used to grep
	 * the whole file, so an Upgrade Notice heading for the version being
	 * released answered yes to both: no heading was inserted, the read-back was
	 * satisfied by the Upgrade Notice, assert-version.sh agreed, and the
	 * customer's changelog skipped the release entirely.
	 *
	 * The superseded prerelease is still pruned, which is what makes the
	 * shipped state so quiet: the section count goes DOWN by one and nothing
	 * anywhere exits non-zero.
	 */
	const dir = sandbox(
		SHIPPED + '\n== Upgrade Notice ==\n\n= 1.1.0 =\n* Re-save your settings after upgrading.\n'
	);

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	const readme = release( dir, '1.1.0', notesFor( '1.1.0', 'a feature landed' ) );

	assert.deepStrictEqual( sections( readme ), [ '1.1.0', '1.0.0' ] );
	assert.ok(
		readme.includes( 'a feature landed' ),
		'the release notes must reach the changelog, not be suppressed by a heading in another section'
	);
	assert.ok(
		readme.includes( '= 1.1.0 =\n* Re-save your settings after upgrading.' ),
		'and the Upgrade Notice entry it collided with must survive untouched'
	);
} );

test( 'every link conventional-changelog adds is stripped from a bullet', ( t ) => {
	/*
	 * readme.txt is plain text on a customer's server, so a 60-character commit
	 * URL is noise. Kept here because the stripping and the pruning read the
	 * same bullets, and the awk that does both is one program.
	 */
	const dir = sandbox( SHIPPED );

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	const readme = release(
		dir,
		'1.1.0',
		'## [1.1.0](https://github.com/spotship/wordpress-insurance/compare/a...b) (2026-10-15)\n' +
			'\n### Bug Fixes\n\n' +
			'* **release:** keep the tag with the version ([#5](https://github.com/spotship/wordpress-insurance/issues/5))' +
			' ([2c18c5e](https://github.com/spotship/wordpress-insurance/commit/2c18c5e)),' +
			' closes [hi#severity](https://github.com/hi/issues/severity)\n'
	);

	assert.ok( readme.includes( '* **release:** keep the tag with the version\n' ) );
	assert.ok(
		! readme.slice( readme.indexOf( '= 1.1.0 =' ) ).includes( 'github.com' ),
		'no URL may reach the shipped changelog'
	);
} );
