import { __, sprintf } from '@wordpress/i18n';
import { statusLabel } from './util';

/** What is drawn in a cell: the status is also written under it, in words. */
const SIGNS = { pass: '✓', open: '!', unknown: '?', ignored: '–', none: '·', running: '', waiting: '·', done: '✓' };

/**
 * Where each task of the scan stands, as cells of a hive. While a scan runs, the cells before the current task are done, the current one
 * pulses and the others wait: what they found is read when the scan ends.
 */
export default function Hive( { tasks, running, current } ) {
	const at = tasks.findIndex( ( task ) => task.id === current );

	const state = ( task, index ) => {
		if ( ! running ) {
			return task.status;
		}
		if ( at < 0 ) {
			return 'waiting';
		}
		if ( index < at ) {
			return 'done';
		}
		return index === at ? 'running' : 'waiting';
	};

	return (
		<>
			{ running && (
				<div
					className="hornet-progress"
					role="progressbar"
					aria-valuemin="0"
					aria-valuemax={ tasks.length }
					aria-valuenow={ Math.max( 0, at ) }
					aria-label={ sprintf(
						/* translators: 1: tasks done, 2: tasks of the scan */
						__( '%1$d of %2$d steps done', 'pollora-hornet' ),
						Math.max( 0, at ),
						tasks.length
					) }
				>
					<span style={ { width: `${ ( ( Math.max( 0, at ) + 0.5 ) / Math.max( 1, tasks.length ) ) * 100 }%` } } />
				</div>
			) }
			<ul className="hornet-hive">
				{ tasks.map( ( task, index ) => {
					const status = state( task, index );
					return (
						<li key={ task.id } className={ `hornet-cell hornet-cell--${ status }` }>
							<span className="hornet-cell__hex" aria-hidden="true">
								<span>{ SIGNS[ status ] }</span>
							</span>
							<strong>{ task.label }</strong>
							<span className="hornet-cell__status">
								{ status === 'running' && __( 'Scanning…', 'pollora-hornet' ) }
								{ status === 'waiting' && __( 'Waiting', 'pollora-hornet' ) }
								{ status === 'done' && __( 'Done', 'pollora-hornet' ) }
								{ ! [ 'running', 'waiting', 'done' ].includes( status ) && statusLabel( status ) }
							</span>
							{ ! running && <span className="hornet-cell__summary">{ task.summary }</span> }
						</li>
					);
				} ) }
			</ul>
		</>
	);
}
