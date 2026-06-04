/**
 * Grid of templates pulled from `GET /studio/templates`.
 *
 * Outer markup mirrors OnePress's `listing_themes()`:
 *
 *   <div class="theme-browser rendered demo-contents-themes-listing">
 *     <div class="themes wp-clearfix">
 *       <TemplateCard … />
 *     </div>
 *   </div>
 *
 * Combined with TemplateCard's OnePress-compatible inner markup, the
 * plugin's root `style.css` styles the React grid byte-identically to
 * the OnePress sync flow. No parallel stylesheet needed.
 *
 * Pagination is the simple "Load more" pattern — first page fetched on
 * mount, subsequent pages appended via the button (skips re-flowing the
 * grid each fetch).
 */

import { useEffect, useState } from '@wordpress/element';
import { Button, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { studio } from '../api';
import { TemplateCard } from './TemplateCard';

const PER_PAGE = 24;

export function TemplateGrid( { onSelect } ) {
	const [ items, setItems ]     = useState( [] );
	const [ total, setTotal ]     = useState( 0 );
	const [ page, setPage ]       = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ]     = useState( null );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		studio.listTemplates( { page, per_page: PER_PAGE } )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				const incoming = Array.isArray( res?.items ) ? res.items : [];
				setItems( ( prev ) => ( page === 1 ? incoming : [ ...prev, ...incoming ] ) );
				setTotal( res?.total || incoming.length );
				setError( null );
			} )
			.catch( ( e ) => {
				if ( ! cancelled ) {
					setError( e.message || __( 'Failed to load templates.', 'famethemes-demo-importer' ) );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ page ] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	if ( loading && items.length === 0 ) {
		return (
			<div className="ft-demo-importer-loading">
				<Spinner />
				<p>{ __( 'Loading templates from Studio…', 'famethemes-demo-importer' ) }</p>
			</div>
		);
	}

	const hasMore = items.length < total;

	return (
		<div className="theme-browser rendered demo-contents-themes-listing">
			<div className="themes wp-clearfix">
				{ items.length === 0 ? (
					<div className="demo-contents-no-themes">
						{ __( 'No Themes Found', 'famethemes-demo-importer' ) }
					</div>
				) : (
					items.map( ( t ) => (
						<TemplateCard
							key={ t.id }
							template={ t }
							onSelect={ onSelect }
						/>
					) )
				) }
			</div>

			{ hasMore && (
				<p style={ { clear: 'both', textAlign: 'center', padding: '16px 0' } }>
					<Button
						variant="secondary"
						onClick={ () => setPage( ( p ) => p + 1 ) }
						isBusy={ loading }
						disabled={ loading }
					>
						{ __( 'Load more', 'famethemes-demo-importer' ) }
					</Button>
				</p>
			) }
		</div>
	);
}
