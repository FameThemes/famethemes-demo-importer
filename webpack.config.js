/**
 * Single-entry build for the Generic track's React UI.
 *
 *   src/generic/admin.js  →  inc/generic/build/admin.{js,css,asset.php}
 *
 * The OnePress track has its own JS (assets/js/importer.js — vanilla
 * jQuery + Underscore templates) and stays out of this build entirely.
 *
 * wp-scripts' default config auto-discovers `src/index.js`; we override
 * the entry map so the output lands inside `inc/generic/build/`, which
 * keeps the Generic track self-contained (everything Generic ships
 * lives under `inc/generic/`).
 */
const path          = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'src/generic/admin.js' ),
	},
};
