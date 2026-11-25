import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { Button, Modal, SearchControl, SelectControl } from '@wordpress/components';
import { statusLabel, severityLabel, SEVERITIES } from './util';
import { filterFindings, pageOf } from './findings-view';
import { SeverityBadge, StatusBadge } from './Severity';

const PER_PAGE = 15;

/**
 * The findings as a table of plain components. Only the public parts of @wordpress/components are used (never a private API of the
 * block editor), so that the screen does not break when WordPress is updated, and runs from WordPress 6.9.
 */
export default function Findings( { findings } ) {
	const [ search, setSearch ] = useState( '' );
	const [ status, setStatus ] = useState( 'attention' );
	const [ severity, setSeverity ] = useState( 'all' );
	const [ page, setPage ] = useState( 1 );
	const [ shown, setShown ] = useState( null );

	const filtered = useMemo(
		() => filterFindings( findings, { search, status, severity } ),
		[ findings, search, status, severity ]
	);
	const { items, pages, current } = pageOf( filtered, page, PER_PAGE );

	// A filter that changes starts from the first page
	const change = ( setter ) => ( value ) => {
		setter( value );
		setPage( 1 );
	};

	return (
		<div className="hornet-findings">
			<div className="hornet-filters">
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search the findings', 'pollora-hornet' ) }
					value={ search }
					onChange={ change( setSearch ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Show', 'pollora-hornet' ) }
					value={ status }
					onChange={ change( setStatus ) }
					options={ [
						{ value: 'attention', label: __( 'What needs attention', 'pollora-hornet' ) },
						{ value: 'all', label: __( 'Everything', 'pollora-hornet' ) },
						...[ 'open', 'unknown', 'ignored', 'pass' ].map( ( value ) => ( { value, label: statusLabel( value ) } ) ),
					] }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Severity', 'pollora-hornet' ) }
					value={ severity }
					onChange={ change( setSeverity ) }
					options={ [
						{ value: 'all', label: __( 'Any severity', 'pollora-hornet' ) },
						...SEVERITIES.map( ( value ) => ( { value, label: severityLabel( value ) } ) ),
					] }
				/>
			</div>

			{ filtered.length === 0 ? (
				<p className="hornet-empty">{ __( 'No finding matches.', 'pollora-hornet' ) }</p>
			) : (
				<table className="widefat striped hornet-table">
					<thead>
						<tr>
							<th>{ __( 'Check', 'pollora-hornet' ) }</th>
							<th>{ __( 'Status', 'pollora-hornet' ) }</th>
							<th>{ __( 'Severity', 'pollora-hornet' ) }</th>
							<th>{ __( 'What was found', 'pollora-hornet' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ items.map( ( item ) => (
							<tr key={ item.check }>
								<td>
									<Button variant="link" onClick={ () => setShown( item ) }>
										{ item.label }
									</Button>
								</td>
								<td>
									<StatusBadge status={ item.status } />
								</td>
								<td>{ item.outcome === 'fail' ? <SeverityBadge severity={ item.severity } /> : '—' }</td>
								<td>{ item.summary }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ pages > 1 && (
				<div className="hornet-pager">
					<Button variant="secondary" disabled={ current <= 1 } onClick={ () => setPage( current - 1 ) }>
						{ __( 'Previous', 'pollora-hornet' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: number of pages */
							__( 'Page %1$d of %2$d', 'pollora-hornet' ),
							current,
							pages
						) }
					</span>
					<Button variant="secondary" disabled={ current >= pages } onClick={ () => setPage( current + 1 ) }>
						{ __( 'Next', 'pollora-hornet' ) }
					</Button>
				</div>
			) }

			{ shown && (
				<Modal title={ shown.label } onRequestClose={ () => setShown( null ) }>
					<p>
						<StatusBadge status={ shown.status } />{ ' ' }
						{ shown.outcome === 'fail' && <SeverityBadge severity={ shown.severity } /> }
					</p>
					<p>{ shown.summary }</p>
					{ shown.details.length > 0 && (
						<ul className="hornet-details">
							{ shown.details.map( ( line, index ) => (
								<li key={ index }>{ line }</li>
							) ) }
						</ul>
					) }
					{ shown.fix && (
						<p>
							<strong>{ __( 'What to do:', 'pollora-hornet' ) }</strong> { shown.fix }
						</p>
					) }
					{ shown.ignored && (
						<p>
							<strong>{ __( 'Set aside, because:', 'pollora-hornet' ) }</strong> { shown.ignored }
						</p>
					) }
					<Button variant="secondary" onClick={ () => setShown( null ) }>
						{ __( 'Close', 'pollora-hornet' ) }
					</Button>
				</Modal>
			) }
		</div>
	);
}
