import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

/**
 * Why a scan that was asked for does not start, and what to do. The scan runs in the scheduled tasks of WordPress: when nothing
 * runs them it would wait for ever, and the button would look broken.
 */
export default function Waiting( { cron } ) {
	return (
		<Notice status="warning" isDismissible={ false }>
			<p>
				<strong>{ __( 'The scan is waiting for the scheduled tasks of WordPress, which have not run.', 'pollora-hornet' ) }</strong>
			</p>
			{ cron.disabled ? (
				<>
					<p>
						{ __(
							'This site does not run them when it is visited (DISABLE_WP_CRON, which Pollora sets): the server has to. Add this line to its crontab:',
							'pollora-hornet'
						) }
					</p>
					<p>
						<code>{ `* * * * * curl -s ${ cron.url }` }</code>
					</p>
				</>
			) : (
				<p>
					{ __(
						'The site could not call itself to start them (a certificate, a firewall, a password in front of the site). Check Tools > Site Health, or add a cron on the server.',
						'pollora-hornet'
					) }
				</p>
			) }
			<p>
				{ __( 'To run the scan now, from the command line:', 'pollora-hornet' ) } <code>wp hornet scan</code>
			</p>
		</Notice>
	);
}
