/**
 * A stand-in for the Spot Ship API, for the e2e suite only.
 *
 * The e2e environment points the plugin at this rather than at staging, so the
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

const PORT = Number( process.env.PORT || 9911 );

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

function hullEcho( body ) {
	return {
		userName: body.userName || '',
		userEmail: body.userEmail || '',
		userPhone: body.userPhone || '',
		vessel: body.vessel || null,
		handmValue: body.handmValue || '',
		increasedValue: body.increasedValue || '',
		fromPort: body.fromPort || 0,
		toPort: body.toPort || 0,
		notes: body.notes || '',
		dataConsent: true,
		source: 'wordpress',
		submissionId: body.submissionId || null,
		consentGivenAt: body.consentGivenAt || '',
		consentText: body.consentText || '',
		privacyNoticeUrl: body.privacyNoticeUrl || '',
		originSiteUrl: body.originSiteUrl || '',
	};
}

const server = http.createServer( ( req, res ) => {
	let raw = '';

	req.on( 'data', ( chunk ) => {
		raw += chunk;
	} );

	req.on( 'end', () => {
		const url = new URL( req.url, `http://localhost:${ PORT }` );
		const send = ( status, body ) => {
			res.writeHead( status, { 'Content-Type': 'application/json' } );
			res.end( JSON.stringify( body ) );
		};

		if ( url.pathname.startsWith( '/insurance/rates' ) ) {
			return send( 200, RATES );
		}

		if ( url.pathname.startsWith( '/insurance/vessel_search' ) ) {
			return send( 200, { results: VESSELS } );
		}

		if ( url.pathname.startsWith( '/insurance/location_search' ) ) {
			return send( 200, { results: LOCATIONS } );
		}

		if ( url.pathname.includes( 'quote_request' ) ) {
			let body = {};

			try {
				body = JSON.parse( raw || '{}' );
			} catch ( e ) {
				body = {};
			}

			return send( 201, hullEcho( body ) );
		}

		return send( 404, { detail: 'Not found' } );
	} );
} );

server.listen( PORT, () => {
	process.stdout.write( `e2e upstream listening on ${ PORT }\n` );
} );
