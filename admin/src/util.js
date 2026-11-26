import { __, _n, sprintf } from '@wordpress/i18n';

export const SEVERITIES = [ 'critical', 'high', 'medium', 'low', 'info' ];

/** The order of a severity: the worst first when sorted from the highest. */
export const severityRank = ( severity ) =>
	( { critical: 4, high: 3, medium: 2, low: 1 } )[ severity ] ?? 0;

export const severityLabel = ( severity ) =>
	( {
		critical: __( 'Critical', 'pollora-hornet' ),
		high: __( 'High', 'pollora-hornet' ),
		medium: __( 'Medium', 'pollora-hornet' ),
		low: __( 'Low', 'pollora-hornet' ),
		info: __( 'Info', 'pollora-hornet' ),
	} )[ severity ] ?? severity;

export const statusLabel = ( status ) =>
	( {
		open: __( 'Open', 'pollora-hornet' ),
		unknown: __( 'Not verified', 'pollora-hornet' ),
		ignored: __( 'Set aside', 'pollora-hornet' ),
		pass: __( 'Passes', 'pollora-hornet' ),
		none: __( 'Not scanned', 'pollora-hornet' ),
	} )[ status ] ?? status;

/** "3 hours ago", from an age in seconds. */
export const ago = ( seconds ) => {
	if ( seconds === null || seconds === undefined ) {
		return __( 'never', 'pollora-hornet' );
	}
	if ( seconds < 90 ) {
		return __( 'just now', 'pollora-hornet' );
	}
	const minutes = Math.round( seconds / 60 );
	if ( minutes < 90 ) {
		return sprintf( _n( '%d minute ago', '%d minutes ago', minutes, 'pollora-hornet' ), minutes );
	}
	const hours = Math.round( minutes / 60 );
	if ( hours < 48 ) {
		return sprintf( _n( '%d hour ago', '%d hours ago', hours, 'pollora-hornet' ), hours );
	}
	const days = Math.round( hours / 24 );
	return sprintf( _n( '%d day ago', '%d days ago', days, 'pollora-hornet' ), days );
};

/** The sentence at the top of the overview. */
export const headline = ( status ) => {
	const { open, unknown } = status.counts;
	if ( open === 0 && unknown === 0 ) {
		return __( 'Nothing needs your attention.', 'pollora-hornet' );
	}
	if ( open === 0 ) {
		return sprintf(
			/* translators: %d: number of checks that could not be verified */
			_n(
				'No open finding, but %d could not be verified.',
				'No open finding, but %d could not be verified.',
				unknown,
				'pollora-hornet'
			),
			unknown
		);
	}
	return sprintf(
		/* translators: %d: number of open findings */
		_n( '%d open finding.', '%d open findings.', open, 'pollora-hornet' ),
		open
	);
};

export const sourceLabel = ( source ) =>
	( {
		web: __( 'Website', 'pollora-hornet' ),
		admin: __( 'Administration', 'pollora-hornet' ),
		rest: __( 'REST API', 'pollora-hornet' ),
		ajax: __( 'Ajax', 'pollora-hornet' ),
		xmlrpc: __( 'XML-RPC', 'pollora-hornet' ),
		cli: __( 'Command line', 'pollora-hornet' ),
		cron: __( 'Scheduled task', 'pollora-hornet' ),
	} )[ source ] ?? source;

/** A moment given as a timestamp in seconds, in the language and time zone of the browser. */
export const whenLabel = ( seconds ) =>
	seconds ? new Date( seconds * 1000 ).toLocaleString() : '—';
