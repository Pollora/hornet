import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Notice, Spinner, TabPanel } from '@wordpress/components';
import { getScanState, getStatus, startScan } from './api';
import Overview from './Overview';
import Findings from './Findings';
import Settings from './Settings';
import Notifications from './Notifications';
import Journal from './Journal';
import Mark from './Mark';

/** How often a scan that is running is asked about, in milliseconds. */
const POLL = 3000;

export default function App() {
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ scanError, setScanError ] = useState( null );
	const [ scanning, setScanning ] = useState( false );
	const timer = useRef( null );
	// Since when the screen has seen the scan wait, in milliseconds; and how long that is, in seconds
	const queuedSince = useRef( null );
	const [ waitedFor, setWaitedFor ] = useState( 0 );

	const load = useCallback( async () => {
		try {
			setStatus( await getStatus() );
			setError( null );
		} catch ( failure ) {
			setError( failure?.message ?? __( 'The status could not be read.', 'pollora-hornet' ) );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	// While a scan is waiting or running, ask where it is; when it is done, read everything again
	const running = Boolean( status?.scan.running ) || scanning;
	useEffect( () => {
		if ( ! running ) {
			return undefined;
		}
		timer.current = setTimeout( async () => {
			try {
				const state = await getScanState();
				queuedSince.current = state.status === 'queued' ? queuedSince.current ?? Date.now() : null;
				setWaitedFor( queuedSince.current ? Math.round( ( Date.now() - queuedSince.current ) / 1000 ) : 0 );
				if ( state.status === 'idle' ) {
					setScanning( false );
					await load();
				} else {
					setStatus( ( current ) =>
						current
							? { ...current, scan: { ...current.scan, running: true, status: state.status, task: state.task } }
							: current
					);
					setScanning( true );
				}
			} catch ( failure ) {
				setScanning( false );
			}
		}, POLL );
		return () => clearTimeout( timer.current );
	}, [ running, status, load ] );

	const onScan = async () => {
		setScanError( null );
		setScanning( true );
		try {
			await startScan();
			await load();
		} catch ( failure ) {
			setScanning( false );
			setScanError( failure?.message ?? __( 'The scan could not be started.', 'pollora-hornet' ) );
		}
	};

	if ( error && ! status ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( ! status ) {
		return <Spinner />;
	}

	const tabs = [
		{ name: 'overview', title: __( 'Overview', 'pollora-hornet' ) },
		{
			name: 'findings',
			title: `${ __( 'Findings', 'pollora-hornet' ) } (${ status.counts.open })`,
		},
		{ name: 'settings', title: __( 'Settings', 'pollora-hornet' ) },
		{ name: 'notifications', title: __( 'Notifications', 'pollora-hornet' ) },
		{ name: 'journal', title: __( 'Journal', 'pollora-hornet' ) },
	];

	return (
		<div className="hornet-app">
			<header className="hornet-brand">
				<Mark />
				<span className="hornet-brand__name">Hornet</span>
				<span className="hornet-brand__by">by Pollora</span>
				<span className={ `hornet-pill hornet-score--${ status.score.grade }` }>
					{ `${ status.score.grade } · ${ status.score.value }/100` }
				</span>
			</header>
		<TabPanel tabs={ tabs }>
			{ ( tab ) => {
				switch ( tab.name ) {
					case 'findings':
						return <Findings findings={ status.findings } onChanged={ load } />;
					case 'settings':
						return <Settings settings={ status.settings } />;
					case 'journal':
						return <Journal enabled={ status.journal.enabled } />;
					case 'notifications':
						return <Notifications channels={ status.channels } />;
					default:
						return (
							<Overview
								status={ status }
								onScan={ onScan }
								scanning={ scanning }
								waitedFor={ waitedFor }
								error={ scanError }
							/>
						);
				}
			} }
		</TabPanel>
		</div>
	);
}
