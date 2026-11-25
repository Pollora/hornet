import { filterFindings, pageOf } from './findings-view';

const finding = ( check, status, severity, summary = '', details = [] ) => ( {
	check,
	label: check.toUpperCase(),
	status,
	severity,
	summary,
	details,
} );

const all = [
	finding( 'salts', 'open', 'critical', 'The salts are the sample ones.' ),
	finding( 'xml-rpc', 'open', 'medium', 'XML-RPC answers.', [ 'pingback.ping' ] ),
	finding( 'core', 'unknown', 'info', 'Never scanned.' ),
	finding( 'file-mods', 'ignored', 'low', 'Set aside.' ),
	finding( 'https', 'pass', 'info', 'Fine.' ),
];

describe( 'filterFindings', () => {
	it( 'shows what needs attention by default: the open and the not verified', () => {
		expect( filterFindings( all, {} ).map( ( f ) => f.check ) ).toEqual( [ 'salts', 'xml-rpc', 'core' ] );
	} );

	it( 'shows everything, or one status', () => {
		expect( filterFindings( all, { status: 'all' } ) ).toHaveLength( 5 );
		expect( filterFindings( all, { status: 'ignored' } ).map( ( f ) => f.check ) ).toEqual( [ 'file-mods' ] );
		expect( filterFindings( all, { status: 'pass' } ).map( ( f ) => f.check ) ).toEqual( [ 'https' ] );
	} );

	it( 'keeps one severity', () => {
		expect( filterFindings( all, { status: 'all', severity: 'critical' } ).map( ( f ) => f.check ) ).toEqual( [ 'salts' ] );
	} );

	it( 'searches the name, the id, the sentence and the details, whatever the case', () => {
		expect( filterFindings( all, { status: 'all', search: 'PINGBACK' } ).map( ( f ) => f.check ) ).toEqual( [ 'xml-rpc' ] );
		expect( filterFindings( all, { status: 'all', search: 'sample' } ).map( ( f ) => f.check ) ).toEqual( [ 'salts' ] );
		expect( filterFindings( all, { status: 'all', search: 'file-mods' } ).map( ( f ) => f.check ) ).toEqual( [ 'file-mods' ] );
		expect( filterFindings( all, { status: 'all', search: '  nothing like it  ' } ) ).toEqual( [] );
	} );

	it( 'combines the three', () => {
		expect( filterFindings( all, { search: 'xml', status: 'open', severity: 'medium' } ).map( ( f ) => f.check ) ).toEqual( [ 'xml-rpc' ] );
		expect( filterFindings( all, { search: 'xml', status: 'open', severity: 'high' } ) ).toEqual( [] );
	} );
} );

describe( 'pageOf', () => {
	const items = Array.from( { length: 35 }, ( _, i ) => i + 1 );

	it( 'cuts the list in pages', () => {
		expect( pageOf( items, 1, 15 ) ).toEqual( { items: items.slice( 0, 15 ), pages: 3, current: 1 } );
		expect( pageOf( items, 3, 15 ).items ).toEqual( [ 31, 32, 33, 34, 35 ] );
	} );

	it( 'brings a page that does not exist back to one that does', () => {
		expect( pageOf( items, 9, 15 ).current ).toBe( 3 );
		expect( pageOf( items, 0, 15 ).current ).toBe( 1 );
		expect( pageOf( items, -4, 15 ).current ).toBe( 1 );
	} );

	it( 'has one page, empty, when there is nothing', () => {
		expect( pageOf( [], 1, 15 ) ).toEqual( { items: [], pages: 1, current: 1 } );
	} );
} );
