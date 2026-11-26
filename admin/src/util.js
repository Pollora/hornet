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

/** What a grade means, in a word. */
export const gradeLabel = ( grade ) =>
	( {
		A: __( 'Solid', 'pollora-hornet' ),
		B: __( 'Good', 'pollora-hornet' ),
		C: __( 'Fragile', 'pollora-hornet' ),
		D: __( 'Weak', 'pollora-hornet' ),
		E: __( 'At risk', 'pollora-hornet' ),
		F: __( 'Critical', 'pollora-hornet' ),
	} )[ grade ] ?? grade;

/** The open findings that cost the most points, with what they cost: what to fix first. */
export const fixFirst = ( findings, costs, many = 3 ) =>
	Object.entries( costs ?? {} )
		.sort( ( a, b ) => b[ 1 ] - a[ 1 ] )
		.slice( 0, many )
		.map( ( [ check, points ] ) => ( { ...findings.find( ( finding ) => finding.check === check ), check, points } ) )
		.filter( ( item ) => item.label );

/** Cuts a sentence at its backticks: what is between two of them is code. A lone backtick is a character like another. */
export const codeSegments = ( sentence ) => {
	const parts = String( sentence ?? '' ).split( '`' );
	if ( parts.length % 2 === 0 ) {
		return [ { text: String( sentence ), code: false } ];
	}
	return parts.map( ( text, index ) => ( { text, code: index % 2 === 1 } ) ).filter( ( part ) => part.text !== '' );
};
