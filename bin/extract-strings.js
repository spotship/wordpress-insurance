/**
 * Extract translatable strings from the PHP sources.
 *
 * Shared by bin/refresh-pot.sh and by the contract test that asserts the
 * catalogue is not stale. One extractor, so the thing that regenerates the POT
 * and the thing that checks it cannot disagree about what counts as a string —
 * which is the usual way a freshness check passes over a stale file.
 *
 * THIS IS THE GENERATOR OF RECORD, not a drift detector beside `wp i18n
 * make-pot`. It used to be the latter, and this comment still said so after
 * bin/refresh-pot.sh had dropped make-pot entirely — the catalogue in
 * languages/ is the output of this file and nothing else.
 *
 * It matches PHP rather than parsing it, which make-pot does properly. That is
 * the trade accepted on purpose: the catalogue is gated by regenerating and
 * diffing, and a gate needs a generator that is present on every runner. See
 * bin/refresh-pot.sh for the failure that settled it.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const DOMAIN = 'spotship-war-insurance';
const SKIP = [ 'vendor', 'node_modules', '.git', '.ship', 'dist', 'tests' ];

/**
 * Every `__( 'text', 'domain' )` style call, for the plugin's own domain.
 */
const CALL = new RegExp(
	String.raw`(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|_e|__)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'${ DOMAIN }'`,
	'g'
);

function phpFiles( dir, found = [] ) {
	for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		if ( SKIP.includes( entry.name ) ) {
			continue;
		}

		const full = path.join( dir, entry.name );

		if ( entry.isDirectory() ) {
			phpFiles( full, found );
		} else if ( entry.name.endsWith( '.php' ) ) {
			found.push( full );
		}
	}

	return found;
}

function extract( root ) {
	const strings = new Set();

	for ( const file of phpFiles( root ) ) {
		const src = fs.readFileSync( file, 'utf8' );

		for ( const match of src.matchAll( CALL ) ) {
			strings.add( match[ 1 ] );
		}
	}

	return [ ...strings ].sort();
}

module.exports = { extract, DOMAIN };

if ( require.main === module ) {
	process.stdout.write( JSON.stringify( extract( process.argv[ 2 ] || '.' ), null, 2 ) + '\n' );
}
