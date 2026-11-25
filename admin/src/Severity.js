import { severityLabel, statusLabel } from './util';

/** A coloured word: the colour is a help, the word says it. */
export function SeverityBadge( { severity } ) {
	return (
		<span className={ `hornet-badge hornet-badge--${ severity }` }>
			{ severityLabel( severity ) }
		</span>
	);
}

export function StatusBadge( { status } ) {
	return (
		<span className={ `hornet-badge hornet-status--${ status }` }>
			{ statusLabel( status ) }
		</span>
	);
}
