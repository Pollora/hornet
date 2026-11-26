import { __, sprintf } from '@wordpress/i18n';
import { gradeLabel } from './util';

const RADIUS = 74;
const LENGTH = 2 * Math.PI * RADIUS;

/**
 * The score as a ring that fills, with the grade in its middle. The number and the letter are text: the colour and the arc only help.
 */
export default function ScoreRing( { score } ) {
	const filled = ( LENGTH * Math.max( 0, Math.min( 100, score.value ) ) ) / 100;

	return (
		<div
			className={ `hornet-score hornet-score--${ score.grade }` }
			role="img"
			aria-label={ sprintf(
				/* translators: 1: a grade from A to F, 2: a score out of 100 */
				__( 'Security grade %1$s, %2$d out of 100.', 'pollora-hornet' ),
				score.grade,
				score.value
			) }
		>
			<svg viewBox="0 0 180 180" width="180" height="180" aria-hidden="true">
				<defs>
					<linearGradient id="hornet-ring" x1="0" y1="0" x2="1" y2="1">
						<stop offset="0" stopColor="#fb196d" />
						<stop offset="0.5" stopColor="#ff5334" />
						<stop offset="1" stopColor="#ff8c12" />
					</linearGradient>
				</defs>
				<circle className="hornet-score__track" cx="90" cy="90" r={ RADIUS } />
				<circle
					className="hornet-score__arc"
					cx="90"
					cy="90"
					r={ RADIUS }
					strokeDasharray={ `${ filled } ${ LENGTH }` }
					transform="rotate(-90 90 90)"
				/>
			</svg>
			<div className="hornet-score__inside" aria-hidden="true">
				<span className="hornet-score__grade">{ score.grade }</span>
				<span className="hornet-score__value">{ `${ score.value }/100` }</span>
				<span className="hornet-score__word">{ gradeLabel( score.grade ) }</span>
			</div>
		</div>
	);
}
