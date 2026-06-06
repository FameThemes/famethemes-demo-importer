/**
 * Generic-track admin app entry.
 *
 * Two modes, decided by PHP via `window.ftDemoImporter.embedded`:
 *
 *   - Standalone (default): auto-mounts `<App/>` into
 *     `#ft-demo-importer-app` rendered by `Generic_Dashboard::dashboard()`.
 *
 *   - Embedded: host page (e.g. Customify dashboard) imports the importer
 *     bundle via the adapter's `embed_host_hook()` and calls
 *     `window.ftDemoImporter.mount(el)` from its own React lifecycle.
 *     Auto-mount is skipped — the host owns the mount slot's DOM node.
 *
 * The mount/unmount API is intentionally generic — any future theme
 * adapter that opts into embedding gets the same contract for free.
 */

import './admin.scss';

import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

import { App } from './components/App';

const roots = new WeakMap();

function mount( el ) {
	if ( ! el || roots.has( el ) ) {
		return;
	}
	const root = createRoot( el );
	root.render( <App /> );
	roots.set( el, root );
}

function unmount( el ) {
	const root = roots.get( el );
	if ( root ) {
		root.unmount();
		roots.delete( el );
	}
}

// PHP `wp_localize_script` has already populated `window.ftDemoImporter`
// with REST root, nonce, etc. Merge the public mount API on top —
// `Object.assign` preserves the boot data instead of clobbering it.
window.ftDemoImporter = Object.assign( window.ftDemoImporter || {}, {
	mount,
	unmount,
} );

domReady( () => {
	if ( window.ftDemoImporter?.embedded ) {
		return;
	}
	const el = document.getElementById( 'ft-demo-importer-app' );
	if ( el ) {
		mount( el );
	}
} );
