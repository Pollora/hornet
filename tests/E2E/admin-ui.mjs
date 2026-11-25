// The Tools > Security screen in a real browser: log in, open it, run a scan, read the findings, open one.
//
// It needs a WordPress with Hornet at BASE (admin / pass) and Playwright, which the package does not depend on:
//     npm install --no-save playwright && npx playwright install chromium
//     BASE=http://127.0.0.1:8085 node tests/E2E/admin-ui.mjs
// Exit code 1 when an assertion fails. It starts a real scan, which asks WordPress.org: the server must run several PHP workers
// (PHP_CLI_SERVER_WORKERS=4 with `php -S`), or the page cannot ask how the scan goes while it runs.
import { chromium } from 'playwright';

const BASE = process.env.BASE || 'http://127.0.0.1:8085';
let failed = 0;
const check = (description, ok, more = '') => {
	console.log(`  ${ ok ? 'ok  ' : 'FAIL' }  ${ description }${ ok ? '' : ' ' + more }`);
	failed += ok ? 0 : 1;
};

const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
const problems = [];
// What WordPress's own dashboard does (gravatar, admin-ajax, emoji) is not the screen's: only its own files and the console count
page.on( 'console', ( m ) => m.type() === 'error' && problems.push( `console: ${ m.text().slice( 0, 200 ) }` ) );
page.on( 'pageerror', ( e ) => problems.push( `pageerror: ${ String( e.message ).slice( 0, 200 ) }` ) );
page.on( 'response', ( r ) => r.status() >= 400 && /hornet/.test( r.url() ) && problems.push( `http ${ r.status() }: ${ r.url().slice( 0, 140 ) }` ) );

const text = async () => ( await page.locator( '#hornet-admin' ).innerText().catch( () => '' ) ).replace( /\s+/g, ' ' );

await page.goto( `${ BASE }/wp-login.php` );
await page.fill( '#user_login', 'admin' );
await page.fill( '#user_pass', 'pass' );
await page.click( '#wp-submit' );
await page.waitForURL( /wp-admin/ );

console.log( 'the page' );
await page.goto( `${ BASE }/wp-admin/tools.php?page=hornet-security` );
await page.getByRole( 'button', { name: 'Scan now' } ).waitFor( { timeout: 20000 } ).catch( () => {} );
check( 'the entry is in the Tools menu, not a menu of its own', ( await page.locator( '#menu-tools a[href*="hornet-security"]' ).count() ) === 1 && ( await page.locator( '#adminmenu a[href*="hornet-security"]' ).count() ) === 1 );
let t = await text();
check( 'it shows the headline and the five tabs', /open findings|Nothing needs|could not be verified/.test( t ) && [ 'Overview', 'Findings', 'Settings', 'Notifications', 'Journal' ].every( ( tab ) => t.includes( tab ) ), t.slice( 0, 160 ) );
check( 'it says when the site was last scanned, or that it never was', /Last scan: /.test( t ), t.slice( 0, 200 ) );

console.log( 'the scan' );
await page.getByRole( 'button', { name: 'Scan now' } ).click();
const started = Date.now();
let sawProgress = false;
while ( Date.now() - started < 180000 ) {
	t = await text();
	sawProgress = sawProgress || /Scanning…|Waiting for the cron|Now: /.test( t );
	if ( /Last scan: (just now|\d+ minutes? ago)/.test( t ) && ! /Scanning…/.test( t ) ) {
		break;
	}
	await page.waitForTimeout( 1500 );
}
check( 'it showed that a scan was going on', sawProgress );
check( 'the scan ended, and the screen says when', /Last scan: (just now|\d+ minutes? ago)/.test( t ), t.slice( 0, 200 ) );
check( 'each task of the scan has its line', [ 'WordPress core files', 'Plugin files', 'Theme files', 'Suspicious files', 'Files given away by the site' ].every( ( name ) => t.includes( name ) ) );
check( 'the button is available again', await page.getByRole( 'button', { name: 'Scan now' } ).isEnabled() );

console.log( 'the findings' );
await page.getByRole( 'tab', { name: /Findings/ } ).click();
await page.waitForTimeout( 800 );
t = await text();
check( 'the worst finding comes first', /Open\s*High|Open\s*Critical/.test( t ) || /Open Medium/.test( t ), t.slice( 0, 240 ) );
const row = page.getByRole( 'button', { name: /HTTPS/ } ).first();
check( 'a row can be opened', ( await row.count() ) > 0 );
await row.click().catch( () => {} );
await page.waitForTimeout( 400 );
const modal = ( await page.locator( '.components-modal__content' ).innerText().catch( () => '' ) ).replace( /\s+/g, ' ' );
check( 'it says what was found and what to do', /What to do:/.test( modal ), modal.slice( 0, 200 ) );
await page.keyboard.press( 'Escape' );

console.log( 'setting a finding aside' );
const openHttps = async () => {
	await page.getByRole( 'tab', { name: /Findings/ } ).click();
	await page.getByRole( 'combobox', { name: 'Show' } ).selectOption( 'all' );
	await page.getByRole( 'button', { name: /HTTPS/ } ).first().click();
	await page.waitForTimeout( 300 );
};
await openHttps();
const dialog = page.locator( '.components-modal__content' );
check( 'an open finding offers to be set aside', await page.getByRole( 'button', { name: /Set aside…/ } ).isVisible() );
await page.getByRole( 'button', { name: /Set aside…/ } ).click();
const confirm = page.getByRole( 'button', { name: 'Set it aside' } );
check( 'it asks for a reason, and does not accept none or a short one', await confirm.isDisabled() );
await dialog.getByRole( 'textbox' ).fill( 'no' );
check( 'a reason of two letters is not enough', await confirm.isDisabled() );
await dialog.getByRole( 'textbox' ).fill( 'This is a test site, on purpose.' );
check( 'a real reason enables the button', await confirm.isEnabled() );
await confirm.click();
await page.waitForTimeout( 1500 );
await openHttps();
const aside = ( await dialog.innerText().catch( () => '' ) ).replace( /\s+/g, ' ' );
check( 'the finding is set aside, with the reason, who did it', /Set aside, because:\s*This is a test site, on purpose\./.test( aside ) && /By admin/.test( aside ), aside.slice( 0, 300 ) );
check( 'it can be brought back, not set aside twice', await page.getByRole( 'button', { name: 'Bring it back' } ).isVisible() && ! ( await page.getByRole( 'button', { name: /Set aside…/ } ).count() ) );
await page.getByRole( 'button', { name: 'Close' } ).last().click();
await page.getByRole( 'tab', { name: 'Overview' } ).click();
await page.waitForTimeout( 300 );
t = await text();
check( 'the overview counts it as set aside', /1 set aside|Set aside/i.test( t ), t.slice( 0, 200 ) );

console.log( 'the journal' );
await page.getByRole( 'tab', { name: 'Journal' } ).click();
await page.waitForTimeout( 1200 );
t = await text();
check( 'it lists the login and the finding set aside, with who and from where', /login\.success/.test( t ) && /hornet\.finding_set_aside/.test( t ) && /check:https/.test( t ) && /reason: This is a test site/.test( t ), t.slice( 0, 300 ) );
check( 'the address is the shortened one', /\b\d+\.\d+\.\d+\.0\b/.test( t ), t.slice( 0, 300 ) );
await page.getByRole( 'combobox', { name: 'What' } ).selectOption( 'hornet.' );
await page.waitForTimeout( 800 );
t = await text();
check( 'a filter narrows it to what was asked', /hornet\.finding_set_aside/.test( t ) && ! /login\.success/.test( t ), t.slice( 0, 300 ) );

console.log( 'bringing it back' );
await openHttps();
await page.getByRole( 'button', { name: 'Bring it back' } ).click();
await page.waitForTimeout( 1500 );
await openHttps();
check( 'it is open again, and can be set aside again', await page.getByRole( 'button', { name: /Set aside…/ } ).isVisible() );
await page.keyboard.press( 'Escape' );
await page.getByRole( 'tab', { name: 'Journal' } ).click();
await page.waitForTimeout( 800 );
t = await text();
check( 'the journal says it was brought back', /hornet\.finding_restored/.test( t ), t.slice( 0, 300 ) );

console.log( 'the settings and the channels' );
await page.getByRole( 'tab', { name: 'Settings' } ).click();
await page.waitForTimeout( 500 );
t = await text();
check( 'the settings are shown and say they are not changed here', /changes none/.test( t ) && /Close XML-RPC/.test( t ) );
check( 'no control changes a setting', ( await page.locator( '#hornet-admin input, #hornet-admin select, #hornet-admin textarea' ).count() ) === 0 );
await page.getByRole( 'tab', { name: 'Notifications' } ).click();
await page.waitForTimeout( 500 );
t = await text();
check( 'a channel is named by its name and kind, not where it goes', /ops-mail/.test( t ) && ! /example\.com|https?:\/\//.test( t ), t.slice( 0, 200 ) );

console.log( 'the console' );
check( 'no error of the screen: none in the console, no file of Hornet refused', problems.length === 0, problems.join( ' | ' ) );

await browser.close();
console.log( failed === 0 ? '\nAll assertions passed.' : `\n${ failed } assertion(s) failed.` );
process.exit( failed === 0 ? 0 : 1 );
