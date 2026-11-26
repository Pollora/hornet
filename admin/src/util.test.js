import { getSettings, setSettings } from '@wordpress/date';
import { ago, headline, severityLabel, severityRank, statusLabel, sourceLabel, whenLabel, fixFirst, gradeLabel, codeSegments } from './util';

describe( 'severityRank', () => {
	it( 'puts the worst first when sorted from the highest', () => {
		const order = [ 'low', 'critical', 'info', 'high', 'medium' ].sort(
			( a, b ) => severityRank( b ) - severityRank( a )
		);
		expect( order ).toEqual( [ 'critical', 'high', 'medium', 'low', 'info' ] );
	} );

	it( 'ranks what it does not know as the least', () => {
		expect( severityRank( 'burning' ) ).toBe( 0 );
		expect( severityRank( undefined ) ).toBe( 0 );
	} );
} );

describe( 'labels', () => {
	it( 'says a severity and a status in words', () => {
		expect( severityLabel( 'high' ) ).toBe( 'High' );
		expect( statusLabel( 'unknown' ) ).toBe( 'Not verified' );
		expect( statusLabel( 'none' ) ).toBe( 'Not scanned' );
	} );

	it( 'gives back what it does not know, as it is', () => {
		expect( severityLabel( 'weird' ) ).toBe( 'weird' );
		expect( statusLabel( 'weird' ) ).toBe( 'weird' );
	} );
} );

describe( 'ago', () => {
	it.each( [
		[ null, 'never' ],
		[ undefined, 'never' ],
		[ 5, 'just now' ],
		[ 89, 'just now' ],
		[ 600, '10 minutes ago' ],
		[ 7200, '2 hours ago' ],
		[ 3 * 86400, '3 days ago' ],
	] )( 'says %s as "%s"', ( seconds, words ) => {
		expect( ago( seconds ) ).toBe( words );
	} );
} );

describe( 'headline', () => {
	const status = ( open, unknown ) => ( { counts: { open, unknown } } );

	it( 'says nothing needs attention only when nothing is open and nothing is unknown', () => {
		expect( headline( status( 0, 0 ) ) ).toBe( 'Nothing needs your attention.' );
	} );

	it( 'does not call a site clean while something could not be verified', () => {
		expect( headline( status( 0, 3 ) ) ).toBe( 'No open finding, but 3 could not be verified.' );
	} );

	it( 'says one open finding in the singular', () => {
		expect( headline( status( 1, 0 ) ) ).toBe( '1 open finding.' );
	} );

	it( 'counts the open findings', () => {
		expect( headline( status( 2, 1 ) ) ).toBe( '2 open findings.' );
	} );
} );

describe( 'sourceLabel and whenLabel', () => {
	it( 'names where a request came from, and keeps what it does not know', () => {
		expect( sourceLabel( 'cli' ) ).toBe( 'Command line' );
		expect( sourceLabel( 'mars' ) ).toBe( 'mars' );
	} );

	it( 'gives a dash for no moment, and a date for a timestamp', () => {
		expect( whenLabel( 0 ) ).toBe( '—' );
		expect( whenLabel( null ) ).toBe( '—' );
		expect( whenLabel( 1795600000 ) ).not.toBe( '—' );
	} );

	it( 'writes a date as the site does, in its language and time zone, not as the browser does', () => {
		const settings = getSettings();
		setSettings( {
			...settings,
			l10n: { ...settings.l10n, locale: 'fr', months: [ 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' ] },
			formats: { ...settings.formats, datetime: 'j F Y H:i' },
			timezone: { offset: '2', offsetFormatted: '2', string: '', abbr: '' },
		} );

		expect( whenLabel( Date.UTC( 2026, 10, 26, 13, 5 ) / 1000 ) ).toBe( '26 novembre 2026 15:05' );

		setSettings( settings );
	} );
} );

describe( 'fixFirst', () => {
	const findings = [ 'a', 'b', 'c', 'd' ].map( ( check ) => ( { check, label: check.toUpperCase() } ) );

	it( 'gives the most expensive findings first, with their cost, three at most', () => {
		expect( fixFirst( findings, { a: 3, b: 40, c: 8, d: 20 } ).map( ( i ) => [ i.label, i.points ] ) ).toEqual( [
			[ 'B', 40 ],
			[ 'D', 20 ],
			[ 'C', 8 ],
		] );
	} );

	it( 'gives nothing when nothing costs, and leaves out a check it does not know', () => {
		expect( fixFirst( findings, {} ) ).toEqual( [] );
		expect( fixFirst( findings, undefined ) ).toEqual( [] );
		expect( fixFirst( findings, { zz: 5 } ) ).toEqual( [] );
	} );
} );

describe( 'gradeLabel', () => {
	it( 'says what each grade means', () => {
		expect( [ 'A', 'B', 'C', 'D', 'E', 'F' ].map( gradeLabel ) ).toEqual( [ 'Solid', 'Good', 'Fragile', 'Weak', 'At risk', 'Critical' ] );
	} );
} );

describe( 'codeSegments', () => {
	it( 'takes what is between backticks as code', () => {
		expect( codeSegments( 'Run `wp hornet scan` now.' ) ).toEqual( [
			{ text: 'Run ', code: false },
			{ text: 'wp hornet scan', code: true },
			{ text: ' now.', code: false },
		] );
	} );

	it( 'keeps a lone backtick and plain or missing text as they are', () => {
		expect( codeSegments( 'a ` b' ) ).toEqual( [ { text: 'a ` b', code: false } ] );
		expect( codeSegments( 'plain' ) ).toEqual( [ { text: 'plain', code: false } ] );
		expect( codeSegments( null ) ).toEqual( [] );
	} );
} );
