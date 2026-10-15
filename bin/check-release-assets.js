/**
 * Every file bin/set-version.sh writes must be in the @semantic-release/git
 * `assets` list.
 *
 * That plugin commits ONLY the paths it is given. A file rewritten during
 * prepare and missing from that list is shipped correctly inside the zip and
 * then thrown away with the runner, leaving the tag on a tree that still
 * carries the previous number — and bin/assert-version.sh red on the next push.
 * readme.txt was added to the script without being added to the list, which is
 * exactly that, so the two lists are compared rather than trusted.
 *
 * THIS IS A FILE RATHER THAN A `run:` BLOCK BECAUSE IT HAS TO BE TESTED.
 *
 * The first version lived in ci.yaml as `for file in $touched; grep -qxF`, and
 * PASSED while readme.txt was missing from the list: newline splitting depends
 * on IFS, and `grep -F` handed the whole multi-line list reads it as one
 * pattern per line and matches on any of them. The second was `grep -vxF -f`,
 * which is correct set subtraction but compares whole lines — so the moment
 * `assets` used a glob (`languages/*.pot`) or the object form (`{ path: … }`)
 * that the plugin documents and accepts, it failed a release config that was
 * perfectly valid, and `[object Object]` failed it silently.
 *
 * A check whose own comment celebrates catching a false pass deserves a test
 * suite. tests/js/release-assets.test.js is it.
 *
 * Usage: printf '%s\n' "$touched" | node bin/check-release-assets.js
 */
'use strict';

const PLUGIN = '@semantic-release/git';

/**
 * The `assets` entries, as plain path patterns.
 *
 * The plugin accepts a string, `{ path: 'glob' }` and `{ path: [ 'a', 'b' ] }`.
 * Anything else is a config this cannot reason about, and guessing is how the
 * `[object Object]` version passed while checking nothing.
 */
function patterns( assets ) {
	const out = [];

	for ( const entry of [].concat( assets === undefined ? [] : assets ) ) {
		if ( typeof entry === 'string' ) {
			out.push( entry );
		} else if ( entry && typeof entry.path === 'string' ) {
			out.push( entry.path );
		} else if ( entry && Array.isArray( entry.path ) ) {
			out.push( ...entry.path );
		} else {
			throw new Error(
				`${ PLUGIN } assets entry is neither a string nor { path }: ${ JSON.stringify( entry ) }`
			);
		}
	}

	return out;
}

/**
 * glob -> RegExp, for the subset globby supports that anyone would write here.
 *
 * `**` crosses separators, `*` and `?` do not. Everything else is a literal,
 * which matters most for the `.` in `*.pot`: left unescaped it matches any
 * character, and the check would pass on files it was never given.
 */
function toRegExp( glob ) {
	let out = '';

	for ( let i = 0; i < glob.length; i++ ) {
		const c = glob[ i ];

		if ( c === '*' && glob[ i + 1 ] === '*' ) {
			i++;

			if ( glob[ i + 1 ] === '/' ) {
				i++;
				out += '(?:[^/]*\\/)*';
			} else {
				out += '.*';
			}
		} else if ( c === '*' ) {
			out += '[^/]*';
		} else if ( c === '?' ) {
			out += '[^/]';
		} else {
			out += c.replace( /[.+^${}()|[\]\\]/g, '\\$&' );
		}
	}

	return new RegExp( `^${ out }$` );
}

/**
 * Last match wins, so a later `!pattern` can exclude an earlier match — the
 * order globby itself applies.
 */
function covered( file, globs ) {
	let hit = false;

	for ( const glob of globs ) {
		const negated = glob.startsWith( '!' );

		if ( toRegExp( negated ? glob.slice( 1 ) : glob ).test( file ) ) {
			hit = ! negated;
		}
	}

	return hit;
}

/**
 * The paths in `files` that the release would discard.
 */
function uncovered( files, assets ) {
	const globs = patterns( assets );

	return files.filter( ( file ) => ! covered( file, globs ) );
}

/**
 * The `assets` list out of a parsed package.json, or undefined when the plugin
 * is not configured at all.
 */
function assetsOf( pkg ) {
	const plugins = ( pkg.release && pkg.release.plugins ) || [];
	const entry = plugins.find(
		( p ) => Array.isArray( p ) && p[ 0 ] === PLUGIN
	);

	return entry && entry[ 1 ] ? entry[ 1 ].assets : undefined;
}

module.exports = { assetsOf, covered, patterns, toRegExp, uncovered };

if ( require.main === module ) {
	let raw = '';

	process.stdin.setEncoding( 'utf8' );
	process.stdin.on( 'data', ( chunk ) => ( raw += chunk ) );
	process.stdin.on( 'end', () => {
		const files = raw
			.split( '\n' )
			.map( ( line ) => line.trim() )
			.filter( Boolean );

		// A rewrite that wrote nothing is a broken rewrite, not a pass.
		if ( files.length === 0 ) {
			process.stdout.write(
				'::error::bin/set-version.sh changed no tracked file\n'
			);
			process.exit( 1 );
		}

		const pkg = require( '../package.json' );
		const assets = assetsOf( pkg );

		if ( assets === undefined ) {
			process.stdout.write(
				`::error::package.json has no ${ PLUGIN } assets list — the release would commit nothing\n`
			);
			process.exit( 1 );
		}

		const missing = uncovered( files, assets );

		for ( const file of missing ) {
			process.stdout.write(
				`::error::${ file } is written by bin/set-version.sh but missing from the ${ PLUGIN } assets list in package.json — the release would discard it\n`
			);
		}

		process.exit( missing.length === 0 ? 0 : 1 );
	} );
}
