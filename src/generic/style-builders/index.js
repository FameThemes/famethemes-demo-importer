/**
 * Style builder registry.
 *
 * Each theme that wants live-preview support in the Style step ships a
 * builder function that accepts `(palette, font)` and returns a CSS
 * string. The wizard looks up the right builder using the active theme
 * slug (from `window.ftDemoImporter.currentTheme`) and posts the
 * result to the iframe via the preview-bridge contract.
 *
 * Built-in builders live in this folder (`customify.js`, `default.js`).
 * Third-party themes can register their own from a companion plugin's
 * frontend or admin bundle:
 *
 *     window.fdiRegisterStyleBuilder( 'astra', ( palette, font ) => {
 *         // return CSS string for Astra's var/selector convention
 *     } );
 *
 * Resolution order:
 *   1. external register via `fdiRegisterStyleBuilder( slug, fn )`
 *   2. built-in matching slug (`customify`)
 *   3. `default` (no-op — wizard still works, iframe just doesn't react)
 *
 * The registry intentionally has no async surface — postMessage to the
 * iframe is sync per selection change, so the builder must be sync too.
 */

import customifyBuilder from './customify';
import defaultBuilder from './default';

const builtIn = {
	customify: customifyBuilder,
	default:   defaultBuilder,
};

// External registrations land on window so a separately-loaded plugin
// (or theme-shipped JS) can add a builder without modifying the
// importer bundle. Map kept on window so a hot-reloaded bundle picks
// up registrations that survived the reload.
const W = typeof window !== 'undefined' ? window : {};
W.__fdiStyleBuilders = W.__fdiStyleBuilders || {};

/**
 * Register a builder for a theme slug. Subsequent calls overwrite —
 * last-registered wins, so a child plugin can override.
 *
 * @param {string} slug
 * @param {(palette, font) => string} fn
 */
function registerStyleBuilder( slug, fn ) {
	if ( typeof slug !== 'string' || typeof fn !== 'function' ) {
		return;
	}
	W.__fdiStyleBuilders[ slug ] = fn;
}

if ( typeof window !== 'undefined' ) {
	window.fdiRegisterStyleBuilder = registerStyleBuilder;
}

/**
 * Look up the builder for a theme slug. Returns the default no-op
 * builder when no match found.
 *
 * @param {string|undefined|null} slug
 * @returns {(palette, font) => string}
 */
export function getStyleBuilder( slug ) {
	if ( slug && W.__fdiStyleBuilders[ slug ] ) {
		return W.__fdiStyleBuilders[ slug ];
	}
	if ( slug && builtIn[ slug ] ) {
		return builtIn[ slug ];
	}
	return defaultBuilder;
}

export { registerStyleBuilder };
