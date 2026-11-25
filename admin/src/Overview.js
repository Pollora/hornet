import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
} from '@wordpress/components';
import { ago, headline } from './util';
import { SeverityBadge, StatusBadge } from './Severity';

export default function Overview( { status, onScan, scanning, error } ) {
	const { counts, scan, worst } = status;
	const running = scan.running || scanning;

	return (
		<div className="hornet-overview">
			<Card>
				<CardBody>
					<h2 className="hornet-headline">{ headline( status ) }</h2>
					<ul className="hornet-counts">
						<li>
							<strong>{ counts.open }</strong>
							{ __( 'open', 'pollora-hornet' ) }
						</li>
						<li>
							<strong>{ counts.unknown }</strong>
							{ __( 'not verified', 'pollora-hornet' ) }
						</li>
						<li>
							<strong>{ counts.ignored }</strong>
							{ __( 'set aside', 'pollora-hornet' ) }
						</li>
						<li>
							<strong>{ counts.pass }</strong>
							{ __( 'pass', 'pollora-hornet' ) }
						</li>
					</ul>
					{ worst && (
						<p>
							{ __( 'Worst open finding:', 'pollora-hornet' ) }{ ' ' }
							<SeverityBadge severity={ worst } />
						</p>
					) }
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<strong>{ __( 'Scan', 'pollora-hornet' ) }</strong>
					<Button
						variant="primary"
						onClick={ onScan }
						disabled={ running }
						aria-busy={ running }
					>
						{ running
							? __( 'Scanning…', 'pollora-hornet' )
							: __( 'Scan now', 'pollora-hornet' ) }
					</Button>
				</CardHeader>
				<CardBody>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<p>
						{ sprintf(
							/* translators: %s: how long ago */
							__( 'Last scan: %s.', 'pollora-hornet' ),
							ago( scan.age )
						) }
						{ running && (
							<>
								{ ' ' }
								<Spinner />{ ' ' }
								{ scan.task
									? sprintf(
											/* translators: %s: the name of a scan task */
											__( 'Now: %s', 'pollora-hornet' ),
											scan.tasks.find( ( t ) => t.id === scan.task )?.label ?? scan.task
									  )
									: __( 'Waiting for the cron to start it…', 'pollora-hornet' ) }
							</>
						) }
					</p>
					{ scan.unfinished.length > 0 && (
						<Notice status="info" isDismissible={ false }>
							{ __( 'Part of the scan goes on in the background.', 'pollora-hornet' ) }
						</Notice>
					) }
					<table className="widefat striped hornet-tasks">
						<tbody>
							{ scan.tasks.map( ( task ) => (
								<tr key={ task.id }>
									<td>{ task.label }</td>
									<td>
										<StatusBadge status={ task.status } />
									</td>
									<td>{ task.summary }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</CardBody>
			</Card>
		</div>
	);
}
