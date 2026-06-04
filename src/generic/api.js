/**
 * REST client for the Generic track. Wraps `@wordpress/api-fetch` so the
 * nonce + namespace handling is one place — components just call
 * `studio.listTemplates({...})` / `jobs.create(...)` etc.
 *
 * All routes under `ft-demo-importer/v1/`. Auth: cookie + REST nonce
 * (apiFetch attaches automatically when `wp.apiFetch.createNonceMiddleware`
 * is registered — the Generic_Dashboard's enqueue path passes the nonce
 * via `ftDemoImporter.restNonce`).
 */

import apiFetch from '@wordpress/api-fetch';

const NS = '/ft-demo-importer/v1';

// Boot the nonce middleware once per page load. The localized
// `ftDemoImporter.restNonce` is fresh per request.
if ( window.ftDemoImporter && window.ftDemoImporter.restNonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( window.ftDemoImporter.restNonce ) );
}

// ---------------------------------------------------------------- Studio

export const studio = {
	me() {
		return apiFetch( { path: `${ NS }/studio/me` } );
	},

	/**
	 * @param {{ search?: string, category?: string, page?: number, per_page?: number }} params
	 */
	listTemplates( params = {} ) {
		const qs = new URLSearchParams();
		Object.entries( params ).forEach( ( [ k, v ] ) => {
			if ( v !== undefined && v !== null && v !== '' ) {
				qs.append( k, v );
			}
		} );
		const suffix = qs.toString() ? `?${ qs }` : '';
		return apiFetch( { path: `${ NS }/studio/templates${ suffix }` } );
	},

	getTemplate( id ) {
		return apiFetch( { path: `${ NS }/studio/templates/${ id }` } );
	},

	listCategories() {
		return apiFetch( { path: `${ NS }/studio/categories` } );
	},
};

// ---------------------------------------------------------------- Jobs

export const jobs = {
	/**
	 * @param {{ template_id: number, import_content?: boolean, import_uploads?: boolean,
	 *           overwrite_existing?: boolean, replace_settings?: boolean,
	 *           plugins_skip?: string[] }} config
	 */
	create( config ) {
		return apiFetch( {
			path:   `${ NS }/theme/jobs`,
			method: 'POST',
			data:   config,
		} );
	},

	get( id ) {
		return apiFetch( { path: `${ NS }/theme/jobs/${ id }` } );
	},

	latest() {
		return apiFetch( { path: `${ NS }/theme/jobs/latest` } );
	},

	cancel( id ) {
		return apiFetch( {
			path:   `${ NS }/theme/jobs/${ id }/cancel`,
			method: 'POST',
		} );
	},
};
