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
