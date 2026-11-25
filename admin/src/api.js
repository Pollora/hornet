import apiFetch from '@wordpress/api-fetch';

const ROOT = '/hornet/v1';

/** Where the site stands: the audit, the last scan, the settings. */
export const getStatus = () => apiFetch( { path: `${ ROOT }/status` } );

/** Where a scan is, without running the audit: cheap enough to ask every few seconds. */
export const getScanState = () => apiFetch( { path: `${ ROOT }/scan` } );

/** Asks for a scan. It does not wait for it: the server runs it in the background. */
export const startScan = () =>
	apiFetch( { path: `${ ROOT }/scan`, method: 'POST' } );

/** Sends a test message to a channel. */
export const testChannel = ( channel ) =>
	apiFetch( {
		path: `${ ROOT }/notify/test`,
		method: 'POST',
		data: { channel },
	} );

/** One page of the journal, the newest first. Empty values are left out of the query. */
export const getJournal = ( { type = '', source = '', page = 1, perPage = 25 } = {} ) => {
	const query = new URLSearchParams( { page: String( page ), per_page: String( perPage ) } );
	if ( type ) {
		query.set( 'type', type );
	}
	if ( source ) {
		query.set( 'source', source );
	}
	return apiFetch( { path: `${ ROOT }/journal?${ query.toString() }` } );
};

/** Sets a finding aside, on a reason that the person wrote. */
export const setAside = ( check, reason ) =>
	apiFetch( {
		path: `${ ROOT }/findings/${ encodeURIComponent( check ) }/ignore`,
		method: 'POST',
		data: { reason },
	} );

/** Brings a finding set aside from the screen back. */
export const restoreFinding = ( check ) =>
	apiFetch( {
		path: `${ ROOT }/findings/${ encodeURIComponent( check ) }/ignore`,
		method: 'DELETE',
	} );
