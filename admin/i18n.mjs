// Translations of the screen, with no dependency.
//
//     node admin/i18n.mjs            extracts languages/pollora-hornet.pot from admin/src, and writes the JSON of each .po
//     node admin/i18n.mjs --check    checks, writes nothing: the .pot and the JSON are what the source and the .po files give,
//                                    and every .po has every string, with the same placeholders and the right number of plural forms
//
// The strings are those of the screen (`__`, `_n` in admin/src) and those the server writes (`Message::of`, `::plural`, `::mark` in src).
// WordPress reads, for the screen, the JSON (the JED format of wp_set_script_translations()): `pollora-hornet-<locale>-hornet-admin.json`, where
// `hornet-admin` is the handle of the script; for the server, `pollora-hornet-<locale>.l10n.php`. The .po files are what a translator edits.
import { readFileSync, writeFileSync, readdirSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const SRC = join( ROOT, 'admin/src' );
const LANG = join( ROOT, 'languages' );
const DOMAIN = 'pollora-hornet';
const HANDLE = 'hornet-admin';
const PHP = join( ROOT, 'src' );
/** The class that defines the calls: its documentation shows them, it does not make them. */
const DEFINITION = join( PHP, 'Domain/I18n/Message.php' );
const check = process.argv.includes( '--check' );

/** Every string of the screen: { msgid, plural?, comment?, refs } in the order of the files. */
function extract() {
	const found = new Map();
	const call = new RegExp(
		String.raw`(?:\/\*\s*translators:\s*([\s\S]*?)\*\/\s*)?\b(__|_n)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*(?:'((?:[^'\\]|\\.)*)'\s*,\s*[^,]+,\s*)?'${ DOMAIN }'\s*\)`,
		'g'
	);
	for ( const file of readdirSync( SRC ).filter( ( f ) => f.endsWith( '.js' ) && ! f.endsWith( '.test.js' ) ).sort() ) {
		const text = readFileSync( join( SRC, file ), 'utf8' );
		for ( const match of text.matchAll( call ) ) {
			const [ , comment, fn, single, plural ] = match;
			const msgid = single.replace( /\\'/g, "'" );
			const line = text.slice( 0, match.index ).split( '\n' ).length;
			const entry = found.get( msgid ) ?? { msgid, plural: fn === '_n' ? plural.replace( /\\'/g, "'" ) : null, comment: null, refs: [] };
			entry.refs.push( `admin/src/${ file }:${ line }` );
			if ( comment ) {
				entry.comment = comment.trim().replace( /\s+/g, ' ' );
			}
			found.set( msgid, entry );
		}
	}
	const calls = readdirSync( SRC )
		.filter( ( f ) => f.endsWith( '.js' ) && ! f.endsWith( '.test.js' ) )
		.map( ( f ) => ( readFileSync( join( SRC, f ), 'utf8' ).match( /\b(?:__|_n)\(/g ) ?? [] ).length )
		.reduce( ( a, b ) => a + b, 0 );
	const seen = [ ...found.values() ].reduce( ( n, e ) => n + e.refs.length, 0 );
	if ( calls !== seen ) {
		throw new Error( `${ calls } translation calls in the source, ${ seen } understood: a call is written in a way this script does not read.` );
	}
	return [ ...found.values() ];
}

/** The files of a folder, in a fixed order. */
const walk = ( dir ) =>
	readdirSync( dir, { withFileTypes: true } )
		.sort( ( a, b ) => a.name.localeCompare( b.name, 'en' ) )
		.flatMap( ( e ) => ( e.isDirectory() ? walk( join( dir, e.name ) ) : [ join( dir, e.name ) ] ) );

const LITERAL = String.raw`('(?:[^'\\]|\\.)*'|"(?:[^"\\$]|\\.)*")`;

const unliteral = ( text ) =>
	text[ 0 ] === "'" ? text.slice( 1, -1 ).replace( /\\(['\\])/g, '$1' ) : text.slice( 1, -1 ).replace( /\\n/g, '\n' ).replace( /\\(["\\$])/g, '$1' );

/** Every string the server writes: `Message::of`, `::plural` and `::mark`, with a literal as the template. */
function extractPhp() {
	const found = new Map();
	const call = new RegExp( String.raw`Message::(of|mark|plural)\(\s*${ LITERAL }(?:\s*,\s*${ LITERAL })?`, 'g' );
	for ( const file of walk( PHP ).filter( ( f ) => f.endsWith( '.php' ) && f !== DEFINITION ) ) {
		const text = readFileSync( file, 'utf8' );
		let understood = 0;
		for ( const match of text.matchAll( call ) ) {
			const [ , method, first, second ] = match;
			if ( method === 'plural' && ! second ) {
				continue;
			}
			understood++;
			const msgid = unliteral( first );
			const line = text.slice( 0, match.index ).split( '\n' ).length;
			const entry = found.get( msgid ) ?? { msgid, plural: method === 'plural' ? unliteral( second ) : null, comment: null, refs: [] };
			entry.refs.push( `${ file.slice( ROOT.length + 1 ) }:${ line }` );
			found.set( msgid, entry );
		}
		const calls = ( text.match( /Message::(?:of|mark|plural)\(/g ) ?? [] ).length;
		if ( calls !== understood ) {
			throw new Error( `${ file.slice( ROOT.length + 1 ) }: ${ calls } Message calls, ${ understood } understood: the template must be a literal, written in the call.` );
		}
	}
	return [ ...found.values() ];
}

/** A PHP string that holds any text, the NUL that separates plural forms included. */
const phpString = ( text ) =>
	`"${ text.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' ).replace( /\$/g, '\\$' ).replace( /\n/g, '\\n' ).replace( /\0/g, '\\0' ) }"`;

const quote = ( text ) => `"${ text.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' ).replace( /\n/g, '\\n' ) }"`;

function pot( entries ) {
	let out = `msgid ""\nmsgstr ""\n"Project-Id-Version: Pollora Hornet\\n"\n"MIME-Version: 1.0\\n"\n"Content-Type: text/plain; charset=UTF-8\\n"\n"Content-Transfer-Encoding: 8bit\\n"\n"X-Domain: ${ DOMAIN }\\n"\n\n`;
	for ( const e of entries ) {
		out += e.comment ? `#. translators: ${ e.comment }\n` : '';
		out += `#: ${ e.refs.join( ' ' ) }\n`;
		out += `msgid ${ quote( e.msgid ) }\n`;
		out += e.plural ? `msgid_plural ${ quote( e.plural ) }\nmsgstr[0] ""\nmsgstr[1] ""\n\n` : 'msgstr ""\n\n';
	}
	return out.trimEnd() + '\n';
}

/** Reads a .po: { header: { 'Plural-Forms': ... }, entries: Map(msgid => string[]) }. */
function parsePo( text ) {
	const unquote = ( s ) => s.trim().slice( 1, -1 ).replace( /\\n/g, '\n' ).replace( /\\"/g, '"' ).replace( /\\\\/g, '\\' );
	const entries = new Map();
	let current = null;
	let field = null;
	const flush = () => {
		if ( current ) {
			entries.set( current.msgid, current );
		}
		current = null;
	};
	for ( const raw of text.split( '\n' ) ) {
		const line = raw.trim();
		if ( line === '' ) {
			flush();
		} else if ( line.startsWith( '#' ) ) {
			continue;
		} else if ( /^msgid\s/.test( line ) ) {
			flush();
			current = { msgid: unquote( line.slice( 6 ) ), plural: null, str: [] };
			field = 'msgid';
		} else if ( /^msgid_plural\s/.test( line ) ) {
			current.plural = unquote( line.slice( 13 ) );
			field = 'plural';
		} else if ( /^msgstr(\[\d+\])?\s/.test( line ) ) {
			const index = Number( line.match( /^msgstr(?:\[(\d+)\])?/ )[ 1 ] ?? 0 );
			current.str[ index ] = unquote( line.slice( line.indexOf( ' ' ) + 1 ) );
			field = index;
		} else if ( line.startsWith( '"' ) ) {
			const value = unquote( line );
			if ( field === 'msgid' ) {
				current.msgid += value;
			} else if ( field === 'plural' ) {
				current.plural += value;
			} else {
				current.str[ field ] += value;
			}
		}
	}
	flush();
	const header = {};
	for ( const l of ( entries.get( '' )?.str[ 0 ] ?? '' ).split( '\n' ) ) {
		const at = l.indexOf( ':' );
		if ( at > 0 ) {
			header[ l.slice( 0, at ).trim() ] = l.slice( at + 1 ).trim();
		}
	}
	entries.delete( '' );
	return { header, entries };
}

// Which value (the first, the second...) and of what kind: a translation may reorder with numbered placeholders, not lose or invent one
const placeholders = ( s ) =>
	[ ...s.replace( /%%/g, '' ).matchAll( /%(?:(\d+)\$)?(?:\.\d+)?([sdf])/g ) ].map( ( m, i ) => `${ m[ 1 ] ?? i + 1 }:${ m[ 2 ] }` ).sort().join( ' ' );
const forms = ( header ) => Number( header[ 'Plural-Forms' ]?.match( /nplurals\s*=\s*(\d+)/ )?.[ 1 ] ?? 0 );

const screen = extract();
const server = extractPhp();
// One .po per language, with both: a text said by the screen and by the server is translated once
const entries = [ ...screen ];
for ( const e of server ) {
	const known = entries.find( ( other ) => other.msgid === e.msgid );
	if ( ! known ) {
		entries.push( e );
	} else if ( Boolean( known.plural ) !== Boolean( e.plural ) ) {
		throw new Error( `"${ e.msgid }" is plural in one place and not in another.` );
	} else {
		known.refs = [ ...known.refs, ...e.refs ];
	}
}
const problems = [];
const wanted = pot( entries );
const potPath = join( LANG, 'pollora-hornet.pot' );

if ( check ) {
	if ( ! existsSync( potPath ) || readFileSync( potPath, 'utf8' ) !== wanted ) {
		problems.push( 'languages/pollora-hornet.pot is not what the source gives: run `npm run i18n`.' );
	}
} else {
	writeFileSync( potPath, wanted );
}

for ( const file of readdirSync( LANG ).filter( ( f ) => f.endsWith( '.po' ) ).sort() ) {
	const locale = file.replace( /^pollora-hornet-/, '' ).replace( /\.po$/, '' );
	const { header, entries: translated } = parsePo( readFileSync( join( LANG, file ), 'utf8' ) );
	const nplurals = forms( header );
	const data = { '': { domain: DOMAIN, lang: locale, 'plural-forms': header[ 'Plural-Forms' ] } };
	const messages = [];

	if ( ! nplurals ) {
		problems.push( `${ file }: no Plural-Forms in the header.` );
	}
	for ( const e of entries ) {
		const t = translated.get( e.msgid );
		const need = e.plural ? nplurals : 1;
		if ( ! t ) {
			problems.push( `${ file }: missing "${ e.msgid }"` );
			continue;
		}
		const strings = t.str.slice( 0, need );
		if ( strings.length < need || strings.some( ( s ) => ! s ) ) {
			problems.push( `${ file }: untranslated or missing plural forms: "${ e.msgid }"` );
			continue;
		}
		for ( const s of strings ) {
			if ( placeholders( s ) !== placeholders( e.msgid ) && ! ( e.plural && placeholders( s ) === '' ) ) {
				problems.push( `${ file }: placeholders differ in "${ e.msgid }" -> "${ s }"` );
			}
		}
		if ( screen.some( ( other ) => other.msgid === e.msgid ) ) {
			data[ e.msgid ] = strings;
		}
		if ( server.some( ( other ) => other.msgid === e.msgid ) ) {
			// The key of a plural is both English forms, the value all the forms of the language, each separated by NUL
			messages.push( `${ phpString( e.plural ? `${ e.msgid }\0${ e.plural }` : e.msgid ) } => ${ phpString( strings.join( '\0' ) ) }` );
		}
	}
	for ( const msgid of translated.keys() ) {
		if ( ! entries.some( ( e ) => e.msgid === msgid ) ) {
			problems.push( `${ file }: "${ msgid }" is not in the source any more.` );
		}
	}

	const json = JSON.stringify( { 'translation-revision-date': header[ 'PO-Revision-Date' ] ?? '', generator: 'pollora-hornet admin/i18n.mjs', domain: DOMAIN, locale_data: { [ DOMAIN ]: data } } );
	const target = join( LANG, `${ DOMAIN }-${ locale }-${ HANDLE }.json` );
	const php = `<?php\n\n// Written by admin/i18n.mjs from ${ file }: edit the .po, not this file.\nreturn [\n\t'domain' => '${ DOMAIN }',\n\t'language' => '${ locale }',\n\t'plural-forms' => ${ phpString( header[ 'Plural-Forms' ] ?? '' ) },\n\t'messages' => [\n${ messages.map( ( m ) => `\t\t${ m },` ).join( '\n' ) }\n\t],\n];\n`;
	const outputs = [ [ target, json ], [ join( LANG, `${ DOMAIN }-${ locale }.l10n.php` ), php ] ];

	for ( const [ path, content ] of outputs ) {
		if ( ! check ) {
			writeFileSync( path, content );
		} else if ( ! existsSync( path ) || readFileSync( path, 'utf8' ) !== content ) {
			problems.push( `${ file }: ${ path.slice( ROOT.length + 1 ) } is not what the .po gives: run \`npm run i18n\`.` );
		}
	}
}

if ( problems.length ) {
	console.error( problems.join( '\n' ) );
	process.exit( 1 );
}
console.log( `${ entries.length } strings (${ screen.length } screen, ${ server.length } server), ${ readdirSync( LANG ).filter( ( f ) => f.endsWith( '.po' ) ).length } languages${ check ? ' checked' : '' }.` );
