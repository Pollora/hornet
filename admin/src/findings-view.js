/**
 * Which findings are shown, and which page of them: plain functions, so that they are tested without a browser.
 */

/**
 * @param {Array}  findings The findings, as the server sorted them (what needs attention first, the worst first).
 * @param {Object} filter   `search` (text), `status` (`attention`, `all` or a status), `severity` (`all` or a severity).
 */
export function filterFindings( findings, { search = '', status = 'attention', severity = 'all' } ) {
	const needle = search.trim().toLowerCase();

	return findings.filter( ( finding ) => {
		if ( status === 'attention' ? ! [ 'open', 'unknown' ].includes( finding.status ) : status !== 'all' && finding.status !== status ) {
			return false;
		}
		if ( severity !== 'all' && finding.severity !== severity ) {
			return false;
		}
		return (
			needle === '' ||
			[ finding.label, finding.check, finding.summary, ...finding.details ]
				.join( ' ' )
				.toLowerCase()
				.includes( needle )
		);
	} );
}

/**
 * @param {Array}  items   What is to be paged.
 * @param {number} page    The page asked for, from 1: one that does not exist is brought back to the last.
 * @param {number} perPage How many on a page.
 * @return {{items: Array, pages: number, current: number}} The items of the page, how many pages there are, and the one that is shown.
 */
export function pageOf( items, page, perPage ) {
	const pages = Math.max( 1, Math.ceil( items.length / perPage ) );
	const current = Math.min( Math.max( 1, page ), pages );

	return { items: items.slice( ( current - 1 ) * perPage, current * perPage ), pages, current };
}
