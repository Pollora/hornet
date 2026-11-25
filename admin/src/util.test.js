import { ago, headline, severityLabel, severityRank, statusLabel } from './util';

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

	it( 'counts the open findings', () => {
		expect( headline( status( 2, 1 ) ) ).toBe( '2 open findings.' );
	} );
} );
