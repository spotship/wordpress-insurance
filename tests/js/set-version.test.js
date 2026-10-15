/**
 * bin/set-version.sh — the changelog it hands a customer.
 *
 * THE PRUNE WAS WRONG THREE TIMES, SO THERE IS NO PRUNE ANY MORE.
 *
 * `dev` is a prerelease branch and every push to it ran a real release, so the
 * script wrote a `= 1.1.0-dev.N =` section per internal build and then had to
 * remove the previous ones again on the next run. Removing them meant deciding
 * which headings were internal builds, over text a person may have typed, and
 * that decision was wrong three times:
 *
 *   1. It matched only prereleases sharing the release's own base, so
 *      `= 1.0.1-dev.1 =` survived a minor bump — it was sitting in readme.txt
 *      on `dev` when this file was first written.
 *   2. The same flag that said "this run writes a heading" also switched the
 *      pruning off, so a hand-written entry kept every `-dev.N` under it.
 *   3. Broadened to "does the heading contain a `-`", it deleted
 *      `= 1.0.2 - 2026-10-14 =` — a RELEASED section — and exited 0.
 *
 * None of those are reachable now: a prerelease writes no section at all, so
 * there is nothing below it to clean up. The notes a customer should read are
 * the ones semantic-release generates for the real release, which aggregate
 * every commit since the last one. What remains of the invariant lives in
 * bin/assert-version.sh, which REFUSES a tree carrying a prerelease section
 * rather than editing one out — same classification, but a wrong answer stops
 * the release in front of a human instead of rewriting a changelog.
 *
 * Each of the three was found by reading the script, never by running it. The
 * CI rehearsal in ci.yaml's `release-config` job runs it once, at 99.98.97,
 * against a changelog with no prerelease sections in it at all — so it could
 * not have caught any of them.
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

/**
 * A changelog in the state the invariant says it is always in: releases only.
 *
 * It used to carry `= 1.0.1-dev.1 =`, because the script used to write one per
 * internal build and prune them again later. Nothing writes one now, so a
 * fixture that had one would be describing a state the release pipeline cannot
 * produce — and `one is refused rather than deleted` below covers the case where
 * something puts one there anyway.
 */
const SHIPPED = '\n= 1.0.0 =\n* First production release.\n';

/**
 * A run that must FAIL, and the stderr it failed with.
 *
 * bin/set-version.sh calls bin/assert-version.sh as its last act, so a refusal
 * can come from either; the caller asserts on the message, not on which.
 *
 * @param {string} dir     A sandbox.
 * @param {string} version The version being released.
 * @returns {string} stderr.
 */
function releaseFails( dir, version ) {
	try {
		execFileSync( 'bash', [ 'bin/set-version.sh', version ], {
			cwd: dir,
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		} );
	} catch ( e ) {
		return String( e.stderr );
	}

	assert.fail( `bin/set-version.sh ${ version } was expected to refuse, and exited 0` );
}

/** The `Stable tag:` the run wrote. */
function stableTag( readme ) {
	return /^Stable tag:[ \t]*(.*)$/m.exec( readme )[ 1 ].trim();
}


test( 'a prerelease writes no changelog section at all', ( t ) => {
	/*
	 * THE WHOLE REDESIGN, IN ONE ASSERTION.
	 *
	 * `dev` is a prerelease branch, so every push to it runs a real release:
	 * 1.1.0-dev.1, -dev.2, -dev.3. Each one used to write its own `= X =`
	 * section, and the readme a customer unpacks would carry one per internal
	 * build — so each run also had to prune the previous ones, which meant a
	 * predicate over human-written headings, which was wrong three times. The
	 * last of those deleted a RELEASED section.
	 *
	 * None of that is reachable if the section is never written. The version
	 * strings still move, because the zip and the update checker read those.
	 */
	const dir = sandbox( SHIPPED );

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	const readme = release( dir, '1.1.0-dev.1', notesFor( '1.1.0-dev.1', 'an internal build' ) );

	assert.deepStrictEqual( sections( readme ), [ '1.0.0' ] );
	assert.ok(
		! readme.includes( 'an internal build' ),
		"the release notes of an internal build must not reach the customer's changelog"
	);

	// NON-VACUITY: the run must still have done its actual job.
	assert.equal( stableTag( readme ), '1.1.0-dev.1', 'Stable tag must still be written' );
} );

test( 'the whole prerelease chain leaves one section, written by the release', ( t ) => {
	/*
	 * The sequence the branch config actually produces: three pushes to `dev`,
	 * then the merge to `main` that cuts 1.1.0. The three write nothing; the
	 * release writes one complete entry, because semantic-release's notes for
	 * it aggregate every commit since 1.0.0. Nothing is lost by staying quiet.
	 */
	const dir = sandbox( SHIPPED );

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	let readme;

	for ( const v of [ '1.1.0-dev.1', '1.1.0-dev.2', '1.1.0-dev.3' ] ) {
		readme = release( dir, v, notesFor( v, 'the same work, three times' ) );
		assert.deepStrictEqual( sections( readme ), [ '1.0.0' ], `${ v } must add nothing` );
	}

	readme = release( dir, '1.1.0', notesFor( '1.1.0', 'the same work, once' ) );

	assert.deepStrictEqual( sections( readme ), [ '1.1.0', '1.0.0' ] );
	assert.equal(
		[ ...readme.matchAll( /the same work, once/g ) ].length,
		1,
		'the release must leave exactly one set of bullets'
	);
	assert.ok(
		! readme.includes( 'the same work, three times' ),
		'and none of the internal builds may have left bullets behind'
	);
} );

test( 'a prerelease section is refused, not deleted, wherever it came from', ( t ) => {
	/*
	 * THE INVARIANT MOVED FROM AN EDIT TO AN ASSERTION, AND THAT IS THE POINT.
	 *
	 * Something still has to notice a `-dev.N` heading that reaches readme.txt
	 * by another route — a hand edit, a revert, a long-lived branch. The old
	 * answer silently deleted whatever its predicate matched, and its predicate
	 * matched a released section. bin/assert-version.sh now refuses the tree
	 * instead: same classification, but a wrong answer stops the release in
	 * front of a human rather than quietly rewriting the changelog.
	 */
	const dir = sandbox( '\n= 1.1.0-dev.2 =\n* Should never have been written.\n' + SHIPPED );

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	const stderr = releaseFails( dir, '1.1.0' );

	assert.match( stderr, /prerelease changelog section '= 1\.1\.0-dev\.2 ='/ );

	// AND IT MUST STILL BE THERE. A refusal that deleted the thing it objected
	// to would be the original defect wearing a different hat.
	const readme = fs.readFileSync( path.join( dir, 'readme.txt' ), 'utf8' );

	assert.ok(
		readme.includes( 'Should never have been written.' ),
		'the offending section must be left for a human to deal with'
	);
} );

test( 'a released heading carrying a date is left alone', ( t ) => {
	/*
	 * THE DEFECT THAT ENDED THE PRUNE. Its predicate asked only whether a
	 * heading held a `-`, which is true of `= 1.0.2 - 2026-10-14 =` — a
	 * RELEASED section in the shape nearly every other changelog format writes,
	 * and set-version.sh's own comments invite hand-written entries. The
	 * heading and its bullets were deleted, the script exited 0, and
	 * assert-version.sh agreed, because nothing inspects the sections it did
	 * not write.
	 *
	 * Kept after the prune was deleted, because it is the regression that
	 * would come back with any future attempt to classify these headings for
	 * removal. The matching assertion in assert-version.sh must not match it
	 * either, which is what makes this more than a test of nothing.
	 */
	const dir = sandbox(
		'\n= 1.0.2 - 2026-10-14 =\n* A hand-written entry, dated the way people date them.\n' +
			SHIPPED
	);

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	const readme = release( dir, '1.1.0', notesFor( '1.1.0', 'a feature landed' ) );

	assert.deepStrictEqual( sections( readme ), [ '1.1.0', '1.0.2 - 2026-10-14', '1.0.0' ] );
	assert.ok(
		readme.includes( 'dated the way people date them' ),
		"the dated section's BODY must survive with its heading"
	);
} );

test( 'released versions are never touched', ( t ) => {
	const dir = sandbox(
		'\n= 1.0.0 =\n* First production release.\n\n= 0.2.0 =\n* Second.\n\n= 0.1.0 =\n* Initial release.\n'
	);

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	const readme = release( dir, '1.1.0', notesFor( '1.1.0', 'a feature landed' ) );

	assert.deepStrictEqual( sections( readme ), [ '1.1.0', '1.0.0', '0.2.0', '0.1.0' ] );
} );

test( 'a hand-written entry is left alone and not duplicated', ( t ) => {
	/*
	 * set-version.sh's own comments invite writing the entry by hand before the
	 * release — the `= 1.0.0 =` in this readme is one. A run that found one and
	 * appended its own generated copy underneath would publish the same release
	 * twice.
	 */
	const dir = sandbox(
		'\n= 1.1.0 =\n* Written for a site administrator, not from commit subjects.\n' + SHIPPED
	);

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	const readme = release( dir, '1.1.0', notesFor( '1.1.0', 'a feature landed' ) );

	assert.deepStrictEqual( sections( readme ), [ '1.1.0', '1.0.0' ] );
	assert.ok( readme.includes( 'Written for a site administrator' ) );
	assert.ok(
		! readme.includes( 'a feature landed' ),
		'and it is not duplicated by a second section generated from the notes'
	);
} );

test( 'bin/set-version.sh is idempotent, for a release and for a prerelease', ( t ) => {
	const dir = sandbox( SHIPPED );

	t.after( () => fs.rmSync( dir, { recursive: true, force: true } ) );

	// A retried workflow must not append a second copy.
	const first = release( dir, '1.1.0', notesFor( '1.1.0', 'a feature landed' ) );
	const again = release( dir, '1.1.0', notesFor( '1.1.0', 'a feature landed' ) );

	assert.equal( again, first, 'a release re-run must change nothing' );

	const pre = release( dir, '1.2.0-dev.1', notesFor( '1.2.0-dev.1', 'an internal build' ) );
	const preAgain = release( dir, '1.2.0-dev.1', notesFor( '1.2.0-dev.1', 'an internal build' ) );

	assert.equal( preAgain, pre, 'a prerelease re-run must change nothing either' );
	assert.deepStrictEqual( sections( preAgain ), [ '1.1.0', '1.0.0' ] );
} );

test( 'a `= X =` heading outside the changelog is out of reach', ( t ) => {
	/*
	 * `= … =` is legal prose in a WordPress readme's Description, FAQ and
	 * Upgrade Notice. Both the lookup in set-version.sh and the prerelease
	 * assertion in assert-version.sh are bounded to `== Changelog ==`, and a
	 * release candidate parked in an Upgrade Notice is not an internal build of
	 * ours — unbounded, that fixture alone would abort every release.
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
	 * `== Upgrade Notice ==` files its entries under headings that are release
	 * versions. Both the "has this release got a section?" test and the
	 * read-back that proves one was written used to grep the whole file, so an
	 * Upgrade Notice heading for the version being released answered yes to
	 * both: no heading was inserted, the read-back was satisfied by the Upgrade
	 * Notice, assert-version.sh agreed, and the customer's changelog skipped the
	 * release entirely.
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
	 * URL is noise.
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
