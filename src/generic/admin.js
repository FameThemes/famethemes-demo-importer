/**
 * Generic-track entry — mounts the React App into the `#ft-demo-importer-app`
 * div rendered by Generic_Dashboard::dashboard() when the active tab is the
 * default templates view.
 *
 * Server passes runtime config via `window.ftDemoImporter` (see
 * Generic_Dashboard::enqueue_assets) — REST root + nonce, polling
 * cadence, Studio-configured flag, settings URL, home URL.
 */

import './admin.scss';

import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

import { App } from './components/App';

domReady( () => {
	const root = document.getElementById( 'ft-demo-importer-app' );
	if ( ! root ) {
		return;
	}
	createRoot( root ).render( <App /> );
} );
