import { codeSegments } from './util';

/** A sentence of the server, with what it writes between backticks (a command, a setting) shown as code. Text only, never markup. */
export default function Text( { children } ) {
	return codeSegments( children ).map( ( segment, index ) =>
		segment.code ? <code key={ index }>{ segment.text }</code> : segment.text
	);
}
