import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { ago, fixFirst, headline, isStuck } from './util';
import { SeverityBadge } from './Severity';
import ScoreRing from './ScoreRing';
import Hive from './Hive';
import Text from './Text';
import Waiting from './Waiting';

export default function Overview( { status, onScan, scanning, error, waitedFor } ) {
	const { counts, scan, score, findings } = status;
	const running = scan.running || scanning;
	const first = fixFirst( findings, score.costs );

	return (
		<div className="hornet-overview">
			<section className="hornet-hero">
				<ScoreRing score={ score } />
				<div className="hornet-hero__text">
					<p className="hornet-eyebrow">{ __( 'Security score', 'pollora-hornet' ) }</p>
					<h2 className="hornet-headline">{ headline( status ) }</h2>
					<ul className="hornet-counts">
						<li className="hornet-count--open">
							<strong>{ counts.open }</strong>
							{ __( 'open', 'pollora-hornet' ) }
						</li>
						<li className="hornet-count--unknown">
							<strong>{ counts.unknown }</strong>
							{ __( 'not verified', 'pollora-hornet' ) }
						</li>
						<li className="hornet-count--ignored">
							<strong>{ counts.ignored }</strong>
							{ __( 'set aside', 'pollora-hornet' ) }
						</li>
						<li className="hornet-count--pass">
							<strong>{ counts.pass }</strong>
							{ __( 'pass', 'pollora-hornet' ) }
						</li>
					</ul>
					{ score.provisional && (
						<p className="hornet-note">
							{ sprintf(
								/* translators: 1: checks that could be verified, 2: all the checks */
								__( 'Provisional: %1$d of %2$d checks could be verified.', 'pollora-hornet' ),
								score.verified,
								score.total
							) }
						</p>
					) }
					{ score.accepted > 0 && (
						<p className="hornet-note">
							{ sprintf(
								_n(
									'%d accepted risk is not counted.',
									'%d accepted risks are not counted.',
									score.accepted,
									'pollora-hornet'
								),
								score.accepted
							) }
						</p>
					) }
				</div>
			</section>

			{ first.length > 0 && (
				<section className="hornet-panel">
					<h3>{ __( 'Fix first', 'pollora-hornet' ) }</h3>
					<ol className="hornet-first">
						{ first.map( ( item ) => (
							<li key={ item.check }>
								<div>
									<SeverityBadge severity={ item.severity } />
									<strong>{ item.label }</strong>
									<span className="hornet-points">
										{ sprintf(
											_n( '%d point', '%d points', item.points, 'pollora-hornet' ),
											item.points
										) }
									</span>
								</div>
								<p>
								<Text>{ item.summary }</Text>
							</p>
								{ item.fix && (
								<p className="hornet-fix">
									<Text>{ item.fix }</Text>
								</p>
							) }
							</li>
						) ) }
					</ol>
				</section>
			) }

			<section className="hornet-panel">
				<header className="hornet-panel__head">
					<div>
						<h3>{ __( 'Scan', 'pollora-hornet' ) }</h3>
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
					</div>
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
				</header>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ scan.cron && isStuck( scan, waitedFor ) && <Waiting cron={ scan.cron } /> }
				{ scan.unfinished.length > 0 && (
					<Notice status="info" isDismissible={ false }>
						{ __( 'Part of the scan goes on in the background.', 'pollora-hornet' ) }
					</Notice>
				) }
				<Hive tasks={ scan.tasks } running={ running } current={ scan.task } />
			</section>
		</div>
	);
}
