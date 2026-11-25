import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { getJournal } from './api';
import { sourceLabel, whenLabel } from './util';

const SOURCES = [ 'web', 'admin', 'rest', 'ajax', 'xmlrpc', 'cli', 'cron' ];

/**
 * The journal: who did what, from where, newest first. It is off unless the owner turned it on in the settings file, and says so.
 * Only public parts of @wordpress/components, like the rest of the screen.
 */
export default function Journal( { enabled, groups } ) {
	const [ type, setType ] = useState( '' );
	const [ source, setSource ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	const load = useCallback( async () => {
		try {
			setData( await getJournal( { type, source, page } ) );
			setError( null );
		} catch ( failure ) {
			setError( failure?.message ?? __( 'The journal could not be read.', 'pollora-hornet' ) );
		}
	}, [ type, source, page ] );

	useEffect( () => {
		if ( enabled ) {
			load();
		}
	}, [ enabled, load ] );

	if ( ! enabled ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'The journal is off. It records who logged in, which accounts and roles changed, what was installed or updated, and which settings were touched. It writes personal data (a shortened network address, an account name), so it is turned on in the settings file: "journal" => [ "enabled" => true ].',
					'pollora-hornet'
				) }
			</Notice>
		);
	}

	// A filter that changes starts from the first page
	const change = ( setter ) => ( value ) => {
		setter( value );
		setPage( 1 );
	};

	return (
		<div className="hornet-journal">
			<div className="hornet-filters">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'What', 'pollora-hornet' ) }
					value={ type }
					onChange={ change( setType ) }
					options={ [
						{ value: '', label: __( 'Everything', 'pollora-hornet' ) },
						...( groups ?? data?.types ?? [] ).map( ( group ) => ( { value: `${ group }.`, label: group } ) ),
					] }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'From', 'pollora-hornet' ) }
					value={ source }
					onChange={ change( setSource ) }
					options={ [
						{ value: '', label: __( 'Anywhere', 'pollora-hornet' ) },
						...SOURCES.map( ( value ) => ( { value, label: sourceLabel( value ) } ) ),
					] }
				/>
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! data && ! error && <Spinner /> }
			{ data && data.events.length === 0 && (
				<p className="hornet-empty">{ __( 'Nothing recorded yet.', 'pollora-hornet' ) }</p>
			) }
			{ data && data.events.length > 0 && (
				<table className="widefat striped hornet-table">
					<thead>
						<tr>
							<th>{ __( 'When', 'pollora-hornet' ) }</th>
							<th>{ __( 'What', 'pollora-hornet' ) }</th>
							<th>{ __( 'Who', 'pollora-hornet' ) }</th>
							<th>{ __( 'About', 'pollora-hornet' ) }</th>
							<th>{ __( 'From', 'pollora-hornet' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.events.map( ( event, index ) => (
							<tr key={ `${ event.at }-${ index }` }>
								<td>{ whenLabel( event.at ) }</td>
								<td>
									<code>{ event.type }</code>
									{ Object.keys( event.details ?? {} ).length > 0 && (
										<div className="hornet-details-inline">
											{ Object.entries( event.details )
												.map( ( [ key, value ] ) => `${ key }: ${ String( value ) }` )
												.join( ', ' ) }
										</div>
									) }
								</td>
								<td>{ event.actor || '—' }</td>
								<td>{ event.subject || '—' }</td>
								<td>
									{ sourceLabel( event.source ) }
									{ event.ip ? ` · ${ event.ip }` : '' }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ data && data.pages > 1 && (
				<div className="hornet-pager">
					<Button variant="secondary" disabled={ data.page <= 1 } onClick={ () => setPage( data.page - 1 ) }>
						{ __( 'Previous', 'pollora-hornet' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: number of pages */
							__( 'Page %1$d of %2$d', 'pollora-hornet' ),
							data.page,
							data.pages
						) }
					</span>
					<Button variant="secondary" disabled={ data.page >= data.pages } onClick={ () => setPage( data.page + 1 ) }>
						{ __( 'Next', 'pollora-hornet' ) }
					</Button>
				</div>
			) }
			{ data && (
				<p className="description">
					{ sprintf(
						/* translators: %d: number of days */
						__( 'Kept %d days.', 'pollora-hornet' ),
						data.retention_days ?? 0
					) }
				</p>
			) }
		</div>
	);
}
