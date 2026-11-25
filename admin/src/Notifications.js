import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { testChannel } from './api';
import { severityLabel } from './util';

export default function Notifications( { channels } ) {
	const [ results, setResults ] = useState( {} );
	const [ busy, setBusy ] = useState( null );

	if ( channels.length === 0 ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'No channel is set up. Add one in the settings file to be told when the audit finds something new (mail, Slack, Teams, Discord, Telegram, ntfy or a signed webhook).',
					'pollora-hornet'
				) }
			</Notice>
		);
	}

	const send = async ( name ) => {
		setBusy( name );
		try {
			const result = await testChannel( name );
			setResults( ( all ) => ( { ...all, [ name ]: result } ) );
		} catch ( error ) {
			setResults( ( all ) => ( {
				...all,
				[ name ]: { status: 'failed', reason: error?.message ?? '' },
			} ) );
		}
		setBusy( null );
	};

	return (
		<table className="widefat striped">
			<tbody>
				{ channels.map( ( channel ) => (
					<tr key={ channel.name }>
						<td>
							<strong>{ channel.name }</strong>
						</td>
						<td>{ channel.type }</td>
						<td>
							{ sprintf(
								/* translators: %s: a severity */
								__( 'from %s', 'pollora-hornet' ),
								severityLabel( channel.min_severity )
							) }
						</td>
						<td>
							<Button
								variant="secondary"
								onClick={ () => send( channel.name ) }
								disabled={ busy !== null }
							>
								{ __( 'Send a test', 'pollora-hornet' ) }
							</Button>
						</td>
						<td role="status">
							{ results[ channel.name ] &&
								`${ results[ channel.name ].status }${
									results[ channel.name ].reason ? ': ' + results[ channel.name ].reason : ''
								}` }
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
