import { __ } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { SEVERITIES, severityLabel, severityRank, statusLabel } from './util';
import { SeverityBadge, StatusBadge } from './Severity';

const STATUSES = [ 'open', 'unknown', 'ignored', 'pass' ];

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 20,
	sort: { field: 'severity', direction: 'desc' },
	filters: [ { field: 'status', operator: 'isAny', value: [ 'open', 'unknown' ] } ],
	fields: [ 'status', 'severity', 'summary' ],
	titleField: 'label',
	layout: {},
};

export default function Findings( { findings } ) {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ shown, setShown ] = useState( null );

	const fields = useMemo(
		() => [
			{
				id: 'status',
				label: __( 'Status', 'pollora-hornet' ),
				getValue: ( { item } ) => item.status,
				render: ( { item } ) => <StatusBadge status={ item.status } />,
				elements: STATUSES.map( ( value ) => ( { value, label: statusLabel( value ) } ) ),
				filterBy: { operators: [ 'isAny' ] },
				enableSorting: false,
			},
			{
				id: 'severity',
				label: __( 'Severity', 'pollora-hornet' ),
				getValue: ( { item } ) => item.severity,
				render: ( { item } ) =>
					item.outcome === 'fail' ? <SeverityBadge severity={ item.severity } /> : '—',
				elements: SEVERITIES.map( ( value ) => ( { value, label: severityLabel( value ) } ) ),
				filterBy: { operators: [ 'isAny' ] },
				sort: ( a, b, direction ) =>
					direction === 'asc'
						? severityRank( a.severity ) - severityRank( b.severity )
						: severityRank( b.severity ) - severityRank( a.severity ),
			},
			{
				id: 'label',
				label: __( 'Check', 'pollora-hornet' ),
				getValue: ( { item } ) => item.label,
				enableGlobalSearch: true,
			},
			{
				id: 'summary',
				label: __( 'What was found', 'pollora-hornet' ),
				getValue: ( { item } ) => item.summary,
				enableGlobalSearch: true,
				enableSorting: false,
			},
		],
		[]
	);

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( findings, view, fields ),
		[ findings, view, fields ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'details',
				label: __( 'Details', 'pollora-hornet' ),
				isPrimary: true,
				callback: ( items ) => setShown( items[ 0 ] ),
			},
		],
		[]
	);

	return (
		<>
			<DataViews
				data={ data }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				paginationInfo={ paginationInfo }
				actions={ actions }
				getItemId={ ( item ) => item.check }
				defaultLayouts={ { table: {} } }
				onClickItem={ ( item ) => setShown( item ) }
				isItemClickable={ () => true }
				search
			/>
			{ shown && (
				<Modal
					title={ shown.label }
					onRequestClose={ () => setShown( null ) }
					size="medium"
				>
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
		</>
	);
}
