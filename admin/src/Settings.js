import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

export default function Settings( { settings } ) {
	const groups = [ ...new Set( settings.map( ( row ) => row.group ) ) ];

	return (
		<>
			<Notice status="info" isDismissible={ false }>
				{ __(
					'These settings are written in a file (the HORNET_CONFIG constant, or config/pollora/hornet.php on Pollora). This screen shows them and changes none: a protection that a screen could switch off is one that a stolen session could switch off.',
					'pollora-hornet'
				) }
			</Notice>
			{ groups.map( ( group ) => (
				<section key={ group } className="hornet-settings-group">
					<h3>{ group }</h3>
					<table className="widefat striped">
						<tbody>
							{ settings
								.filter( ( row ) => row.group === group )
								.map( ( row ) => (
									<tr key={ row.key }>
										<td>{ row.label }</td>
										<td>
											<span className={ `hornet-badge hornet-onoff--${ row.on ? 'on' : 'off' }` }>
												{ row.on ? __( 'On', 'pollora-hornet' ) : __( 'Off', 'pollora-hornet' ) }
											</span>
										</td>
										<td>{ row.detail }</td>
										<td>
											<code>{ row.key }</code>
										</td>
									</tr>
								) ) }
						</tbody>
					</table>
				</section>
			) ) }
		</>
	);
}
