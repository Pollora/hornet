import { createRoot } from '@wordpress/element';
import App from './App';
import './style.css';

const container = document.getElementById( 'hornet-admin' );

if ( container ) {
	createRoot( container ).render( <App /> );
}
