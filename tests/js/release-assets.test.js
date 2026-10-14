/**
 * bin/check-release-assets.js — the check that stops a release discarding a
 * file bin/set-version.sh just wrote.
 *
 * IT IS TESTED BECAUSE TWO EARLIER VERSIONS OF IT WERE WRONG IN OPPOSITE
 * DIRECTIONS: a shell loop that passed while readme.txt was missing from the
 * list, and then a `grep -vxF` that would have failed a perfectly valid list
 * the moment it used a glob. The false pass is the dangerous one, so every
 * assertion below that matters is a negative case.
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );

const {
	assetsOf,
	covered,
	patterns,
	uncovered,
} = require( '../../bin/check-release-assets.js' );

const WRITTEN = [
	'spotship-war-insurance.php',
	'readme.txt',
	'languages/spotship-war-insurance.pot',
];

test( 'the committed assets list covers every file set-version.sh writes', () => {
	const pkg = require( '../../package.json' );

	assert.deepEqual( uncovered( WRITTEN, assetsOf( pkg ) ), [] );
} );

test( 'a file missing from the list is named', () => {
	const assets = [ 'spotship-war-insurance.php', 'readme.txt' ];

	assert.deepEqual( uncovered( WRITTEN, assets ), [
		'languages/spotship-war-insurance.pot',
	] );
} );

test( 'readme.txt missing from the list is caught — the original defect', () => {
	const assets = [
		'spotship-war-insurance.php',
		'languages/spotship-war-insurance.pot',
	];

	assert.deepEqual( uncovered( WRITTEN, assets ), [ 'readme.txt' ] );
} );

test( 'an empty list leaves everything uncovered', () => {
	assert.deepEqual( uncovered( WRITTEN, [] ), WRITTEN );
} );

test( 'a glob covers what it matches', () => {
	const assets = [
		'spotship-war-insurance.php',
		'readme.txt',
		'languages/*.pot',
	];

	assert.deepEqual( uncovered( WRITTEN, assets ), [] );
} );

test( 'a glob does not cover what it does not match', () => {
	assert.deepEqual( uncovered( [ 'languages/a/b.pot' ], [ 'languages/*.pot' ] ), [
		'languages/a/b.pot',
	] );

	assert.deepEqual( uncovered( [ 'languages/x.po' ], [ 'languages/*.pot' ] ), [
		'languages/x.po',
	] );
} );

test( '** crosses separators, * does not', () => {
	assert.deepEqual( uncovered( [ 'languages/a/b.pot' ], [ 'languages/**/*.pot' ] ), [] );
	assert.deepEqual( uncovered( [ 'languages/b.pot' ], [ 'languages/**/*.pot' ] ), [] );
} );

test( 'the dot in a glob is a literal, not any character', () => {
	// `languages/*.pot` as an unescaped regex would match this.
	assert.deepEqual( uncovered( [ 'languages/xxpot' ], [ 'languages/*.pot' ] ), [
		'languages/xxpot',
	] );
} );

test( 'the object form is read, not stringified', () => {
	const assets = [
		{ path: 'spotship-war-insurance.php' },
		{ path: [ 'readme.txt', 'languages/*.pot' ] },
	];

	assert.deepEqual( patterns( assets ), [
		'spotship-war-insurance.php',
		'readme.txt',
		'languages/*.pot',
	] );
	assert.deepEqual( uncovered( WRITTEN, assets ), [] );
} );

test( 'a later negation excludes an earlier match', () => {
	assert.equal( covered( 'readme.txt', [ '*.txt', '!readme.txt' ] ), false );
	assert.equal( covered( 'readme.txt', [ '!readme.txt', '*.txt' ] ), true );
} );

test( 'an entry that is neither a string nor { path } is refused, not guessed', () => {
	assert.throws( () => patterns( [ { glob: 'readme.txt' } ] ), /neither a string/ );
} );

test( 'a missing assets list is distinguishable from an empty one', () => {
	assert.equal( assetsOf( { release: { plugins: [] } } ), undefined );
	assert.deepEqual( patterns( undefined ), [] );
} );
