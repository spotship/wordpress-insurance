/**
 * A stand-in for the Spot Ship API, for the e2e suite only.
 *
 * The e2e environment points the plugin at this rather than at a real
 * deployment, so the
 * specs are deterministic and a browser test never depends on a live service or
 * a real credential.
 *
 * The 201 body here is the REAL shape the backend emits — sixteen camelCase
 * keys on hull, twenty on cargo, containing neither `id` nor `status`. That is
 * not incidental: a previous implementation's fixture invented a shape the API
 * never returns, and the anti-bot decoy was then built to imitate the fixture.
 * If this drifts, it drifts toward that failure again.
 */
'use strict';

const http = require( 'http' );

/**
 * 8080, and it is not arbitrary.
 *
 * wp_safe_remote_get() runs wp_http_validate_url(), which rejects any port
 * outside { 80, 443, 8080 } outright — before the host checks, and with no
 * filter to override it. On 9911 the plugin never made a request at all; it got
 * "A valid URL was not provided" and the browser specs saw empty searches.
 */
const PORT = Number( process.env.PORT || 8080 );

const LOCATIONS = [
	{ id: 11, name: 'Rotterdam', country: 'NL', locode: 'NLRTM', latitude: 51.9, sedna_region_id: 4 },
	{ id: 22, name: 'Rotterdam Europoort', country: 'NL', locode: 'NLEUR', latitude: 51.9, sedna_region_id: 4 },
];

const VESSELS = [
	{ id: 7, name: 'Ever Given', imo: '9811000', type: 'Container', mmsi: '353136000', flag: 'PA', dwt: 199629, manager: 'A manager' },
];

const RATES = {
	updatedAt: '2026-09-11',
	columns: [ 'hmRate', 'ncbRate', 'spotshipRate' ],
	hull: [ { zone: 'Hormuz Passage', hmRate: '8.500%', ncbRate: '8.500%', spotshipRate: '6.800%' } ],
	cargo: [ { zone: 'Hormuz Passage', grossRate: '0.500%', netRate: null } ],
	disclaimers: [ 'Indicative only. Not an offer or a quote, and subject to underwriting.' ],
};

/**
 * The provenance half, identical on both products.
 */
function provenance( body ) {
	return {
		dataConsent: true,
		source: 'wordpress',
		submissionId: body.submissionId || null,
		consentGivenAt: body.consentGivenAt || '',
		consentText: body.consentText || '',
		privacyNoticeUrl: body.privacyNoticeUrl || '',
		originSiteUrl: body.originSiteUrl || '',
	};
}

/**
 * CARGO IS NOT HULL. Twenty keys, not sixteen, and a different set — echoing
 * the hull shape here would have the e2e suite assert a response the API never
 * emits for this product, which is the exact failure this fixture's comment
 * warns about.
 */
function cargoEcho( body ) {
	return Object.assign( {
		userName: body.userName || '',
		userEmail: body.userEmail || '',
		userPhone: body.userPhone || '',
		vessel: body.vessel || null,
		cargoDescription: body.cargoDescription || '',
		cargoWeight: body.cargoWeight || '',
		cargoValue: body.cargoValue ? Number( body.cargoValue ) : 0,
		cargoCurrency: body.cargoCurrency || '',
		riskZone: body.riskZone || '',
		insuredParty: body.insuredParty || '',
		fromPort: body.fromPort || 0,
		destinations: body.destinations || [],
		notes: body.notes || '',
	}, provenance( body ) );
}

function hullEcho( body ) {
	return Object.assign( {
		userName: body.userName || '',
		userEmail: body.userEmail || '',
		userPhone: body.userPhone || '',
		vessel: body.vessel || null,
		handmValue: body.handmValue || '',
		increasedValue: body.increasedValue || '',
		fromPort: body.fromPort || 0,
		toPort: body.toPort || 0,
		notes: body.notes || '',
	}, provenance( body ) );
}

/**
 * Route a request. A PURE FUNCTION, exported, so the routing can be tested
 * without binding a socket — which matters because the routing is where the bug
 * was: `cargo_quote_request` CONTAINS `quote_request`, so a generic substring
 * check matches both and silently serves the hull shape for cargo.
 *
 * @param {string} pathname Request path.
 * @param {object} body     Parsed request body.
 * @returns {{status: number, body: object}}
 */
function route( pathname, body, fault ) {
	/*
	 * FAULT INJECTION, so the browser suite can evidence the failure modes the
	 * spec requires: the backend being down, and the API key being revoked.
	 *
	 * Both must be driven from the upstream rather than from Playwright's
	 * network interception, because the proxy is SERVER-side: the browser never
	 * talks to the API, so there is no request in the page for Playwright to
	 * intercept. Faking it in the browser would prove nothing about the PHP
	 * path that actually handles the failure.
	 */
	if ( 'revoked' === fault ) {
		// What DRF returns for a key that no longer resolves. The plugin must
		// turn this into the neutral sentence and never echo the word "key".
		return { status: 401, body: { detail: 'Invalid token.' } };
	}

	if ( 'down' === fault ) {
		return { status: 502, body: { detail: 'Bad gateway' } };
	}

	if ( pathname.startsWith( '/insurance/rates' ) ) {
		return { status: 200, body: RATES };
	}

	if ( pathname.startsWith( '/insurance/vessel_search' ) ) {
		return { status: 200, body: { results: VESSELS } };
	}

	if ( pathname.startsWith( '/insurance/location_search' ) ) {
		return { status: 200, body: { results: LOCATIONS } };
	}

	if ( pathname.includes( 'quote_request' ) ) {
		// Cargo FIRST: the cargo path contains the hull path as a substring.
		const isCargo = pathname.includes( 'cargo_quote_request' );

		return { status: 201, body: isCargo ? cargoEcho( body ) : hullEcho( body ) };
	}

	return { status: 404, body: { detail: 'Not found' } };
}

module.exports = { route, hullEcho, cargoEcho, provenance, RATES, LOCATIONS, VESSELS };

/* istanbul ignore next — only reached when run as a server, not when required. */
if ( require.main === module ) {
	let fault = '';

	const server = http.createServer( ( req, res ) => {
		let raw = '';

		req.on( 'data', ( chunk ) => {
			raw += chunk;
		} );

		req.on( 'end', () => {
			const url = new URL( req.url, `http://localhost:${ PORT }` );

			let body = {};

			try {
				body = JSON.parse( raw || '{}' );
			} catch ( e ) {
				body = {};
			}

			/*
			 * A control route rather than a restart: the plugin caches rates and
			 * binds to a site URL, and tearing the server down and back up
			 * between specs loses that state and makes the failure specs pass
			 * for the wrong reason.
			 */
			if ( '/__fault' === url.pathname ) {
				fault = url.searchParams.get( 'mode' ) || '';
				res.writeHead( 200, { 'Content-Type': 'application/json' } );
				res.end( JSON.stringify( { fault: fault } ) );
				return;
			}

			const answer = route( url.pathname, body, fault );

			res.writeHead( answer.status, { 'Content-Type': 'application/json' } );
			res.end( JSON.stringify( answer.body ) );
		} );
	} );

	server.listen( PORT, () => {
		process.stdout.write( `e2e upstream listening on ${ PORT }\n` );
	} );
}
