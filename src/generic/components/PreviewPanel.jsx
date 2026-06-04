/**
 * Fullscreen template preview — layout cloned from blocksify-design-importer's
 * TemplateDetail (sidebar on the LEFT):
 *
 *   ┌── 320px sidebar (left) ──┐ ┌── iframe pane ──────────────────────────────┐
 *   │ header (title + X)       │ │                                             │
 *   │─ scroll ─────────────────│ │             <iframe />                      │
 *   │  Site logo (wp.media)    │ │                                             │
 *   │  Recommended plugins     │ │                                             │
 *   │  Progress (when running) │ │                                             │
 *   │─ footer ─────────────────│ │                                             │
 *   │  [Cancel] [Import Now]   │ │                                             │
 *   └──────────────────────────┘ └─────────────────────────────────────────────┘
 *
 * Single-panel UX (no separate wizard modal): idle vs. importing vs. done
 * are state transitions inside the same right-side sidebar — header +
 * iframe stay put across all states.
 *
 * Detail data (recommended plugins, full preview URL) is fetched via
 * `GET /studio/templates/{id}` on mount because the list shape from
 * `GET /studio/templates` omits the heavy `requirements` block.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';

import { studio, jobs } from '../api';
import { useJob } from '../hooks/useJob';

const PHASES = [
	{ key: 'fetching',           from:  0, to: 10, label: __( 'Fetching template assets',     'famethemes-demo-importer' ) },
	{ key: 'installing_plugins', from: 10, to: 30, label: __( 'Installing required plugins',  'famethemes-demo-importer' ) },
	{ key: 'extracting',         from: 30, to: 45, label: __( 'Extracting uploads',           'famethemes-demo-importer' ) },
	{ key: 'importing_content',  from: 45, to: 90, label: __( 'Importing content',            'famethemes-demo-importer' ) },
	{ key: 'applying_options',   from: 90, to: 100, label: __( 'Applying theme options',      'famethemes-demo-importer' ) },
];

export function PreviewPanel( { template, onClose } ) {
	const [ detail, setDetail ]       = useState( null );
	const [ detailErr, setDetailErr ] = useState( null );
	const [ logo, setLogo ]           = useState( { id: 0, url: '' } );
	const [ jobId, setJobId ]         = useState( null );
	const [ starting, setStarting ]   = useState( false );
	const [ startError, setStartError ] = useState( null );

	const { job } = useJob( jobId );

	const importing = jobId !== null;
	const status    = job?.status                  || ( importing ? 'queued' : 'idle' );
	const percent   = job?.progress?.percent        | 0;
	const isDone    = status === 'completed' || status === 'failed' || status === 'cancelled';

	useEffect( () => {
		let cancelled = false;
		setDetail( null );
		setDetailErr( null );
		studio.getTemplate( template.id )
			.then( ( res ) => { if ( ! cancelled ) setDetail( res ); } )
			.catch( ( e )  => { if ( ! cancelled ) setDetailErr( e?.message || String( e ) ); } );
		return () => { cancelled = true; };
	}, [ template.id ] );

	// Lock body scroll while the preview is mounted. Stops the page from
	// scrolling behind the overlay (which would surface a phantom scrollbar
	// on the right edge when the iframe content is tall) and prevents the
	// browser from restoring scroll position to mid-page when the modal
	// closes. Cleanup runs on unmount + on a re-render to a different
	// template, so the class is always removed exactly when the panel goes.
	useEffect( () => {
		document.body.classList.add( 'ft-demo-preview-open' );
		return () => {
			document.body.classList.remove( 'ft-demo-preview-open' );
		};
	}, [] );

	// WP media picker — requires wp_enqueue_media() server-side (already
	// done in Generic_Dashboard::enqueue_assets).
	const openLogoPicker = useCallback( () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( {
			title:    __( 'Select site logo', 'famethemes-demo-importer' ),
			button:   { text: __( 'Use this image', 'famethemes-demo-importer' ) },
			library:  { type: 'image' },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			setLogo( { id: att.id || 0, url: att.url || '' } );
		} );
		frame.open();
	}, [] );

	const handleImport = ( e ) => {
		e.preventDefault();
		setStartError( null );
		setStarting( true );
		jobs.create( {
			template_id:     template.id,
			custom_logo_id:  logo.id  || 0,
			custom_logo_url: logo.url || '',
		} )
			.then( ( res ) => {
				if ( res?.job_id ) {
					setJobId( res.job_id );
				}
			} )
			.catch( ( err ) => {
				setStartError( err.message || __( 'Failed to start import job.', 'famethemes-demo-importer' ) );
			} )
			.finally( () => {
				setStarting( false );
			} );
	};

	const handleClose = () => {
		if ( importing && ! isDone ) {
			// eslint-disable-next-line no-alert
			const ok = window.confirm( __( 'Importing in the background. Are you sure you want to leave?', 'famethemes-demo-importer' ) );
			if ( ! ok ) {
				return;
			}
			jobs.cancel( jobId ).catch( () => { /* runner picks the flag at next safe boundary */ } );
		}
		onClose();
	};

	const title    = template.title || template.name || `#${ template.id }`;
	const plugins  = Array.isArray( detail?.requirements?.plugins )
		? detail.requirements.plugins
		: [];
	const iframeUrl = detail?.frame_url
		|| detail?.preview_route
		|| detail?.demo_url
		|| detail?.preview_url
		|| template.preview_url
		|| '';

	return (
		<div className="ft-demo-preview">
			<aside className="ft-demo-preview__sidebar">
				<div className="ft-demo-preview__sb-header">
					<h2 title={ title }>{ title }</h2>
					<button
						type="button"
						className="ft-demo-preview__close"
						onClick={ handleClose }
						aria-label={ __( 'Close', 'famethemes-demo-importer' ) }
					>
						×
					</button>
				</div>

				<div className="ft-demo-preview__sb-body">
					{ detailErr && (
						<div className="ft-demo-preview__section ft-demo-preview__error">
							{ detailErr }
						</div>
					) }

					{ ! importing && (
						<>
							<section className="ft-demo-preview__section">
								<h3>{ __( 'Site logo', 'famethemes-demo-importer' ) }</h3>
								<button
									type="button"
									className={ 'ft-demo-preview__logo' + ( logo.url ? ' is-set' : '' ) }
									onClick={ openLogoPicker }
								>
									{ logo.url ? (
										<img src={ logo.url } alt={ __( 'Selected logo', 'famethemes-demo-importer' ) } />
									) : (
										<>
											<svg viewBox="0 0 24 24">
												<path d="M21 19V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2z" />
												<circle cx="8.5" cy="8.5" r="1.5" />
												<polyline points="21 15 16 10 5 21" />
											</svg>
											<span>{ __( 'Upload logo', 'famethemes-demo-importer' ) }</span>
										</>
									) }
								</button>
							</section>

							{ plugins.length > 0 && (
								<section className="ft-demo-preview__section">
									<h3>{ __( 'Recommended plugins', 'famethemes-demo-importer' ) }</h3>
									<ul className="ft-demo-preview__plugins">
										{ plugins.map( ( p ) => (
											<li key={ p.slug || p.name }>
												<span className="name">{ p.name || p.slug }</span>
												{ p.source && p.source !== 'wordpress.org' && (
													<span className="source-tag">{ p.source }</span>
												) }
											</li>
										) ) }
									</ul>
								</section>
							) }

							{ startError && (
								<div className="ft-demo-preview__error">{ startError }</div>
							) }
						</>
					) }

					{ importing && (
						<section className="ft-demo-preview__section">
							<h3>{ __( 'Import progress', 'famethemes-demo-importer' ) }</h3>

							<ol className="ft-demo-preview__phases">
								{ PHASES.map( ( p ) => {
									let cls = 'is-waiting';
									if ( percent >= p.to )       cls = 'is-completed';
									else if ( percent >= p.from ) cls = ( status === 'failed' ? 'is-failed' : 'is-running' );
									return (
										<li key={ p.key } className={ `ft-demo-preview__phase ${ cls }` }>
											<span className="dot" aria-hidden="true" />
											<span className="label">{ p.label }</span>
										</li>
									);
								} ) }
							</ol>

							<div className="ft-demo-preview__bar">
								<div
									className="ft-demo-preview__bar-fill"
									style={ { width: `${ Math.max( 0, Math.min( 100, percent ) ) }%` } }
								/>
							</div>
							<p className="ft-demo-preview__msg">
								{ job?.progress?.message || '' } <strong>({ percent }%)</strong>
							</p>

							{ status === 'completed' && (
								<div className="ft-demo-preview__status is-success">
									✓ { __( 'Import complete.', 'famethemes-demo-importer' ) }
								</div>
							) }
							{ status === 'failed' && (
								<div className="ft-demo-preview__status is-error">
									✗ { job?.error || __( 'Import failed.', 'famethemes-demo-importer' ) }
								</div>
							) }
							{ status === 'cancelled' && (
								<div className="ft-demo-preview__status is-warning">
									{ __( 'Import cancelled.', 'famethemes-demo-importer' ) }
								</div>
							) }

							{ Array.isArray( job?.warnings ) && job.warnings.length > 0 && (
								<details className="ft-demo-preview__warnings">
									<summary>
										{ __( 'Warnings', 'famethemes-demo-importer' ) } ({ job.warnings.length })
									</summary>
									<ul>
										{ job.warnings.map( ( w, i ) => <li key={ i }>{ w }</li> ) }
									</ul>
								</details>
							) }
						</section>
					) }
				</div>

				<div className="ft-demo-preview__sb-footer">
					{ ! importing && (
						<>
							<Button variant="secondary" onClick={ handleClose }>
								{ __( 'Cancel', 'famethemes-demo-importer' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ handleImport }
								isBusy={ starting }
								disabled={ starting || ! detail }
							>
								{ starting
									? __( 'Starting…', 'famethemes-demo-importer' )
									: __( 'Import Now', 'famethemes-demo-importer' )
								}
							</Button>
						</>
					) }
					{ importing && ! isDone && (
						<Button variant="secondary" isDestructive onClick={ handleClose }>
							{ __( 'Cancel import', 'famethemes-demo-importer' ) }
						</Button>
					) }
					{ status === 'completed' && (
						<>
							<Button variant="secondary" onClick={ onClose }>
								{ __( 'Close', 'famethemes-demo-importer' ) }
							</Button>
							<Button variant="primary" href={ window.ftDemoImporter?.home || '/' }>
								{ __( 'View site', 'famethemes-demo-importer' ) }
							</Button>
						</>
					) }
					{ ( status === 'failed' || status === 'cancelled' ) && (
						<Button variant="primary" onClick={ onClose }>
							{ __( 'Close', 'famethemes-demo-importer' ) }
						</Button>
					) }
				</div>
			</aside>

			<div className="ft-demo-preview__main">
				{ iframeUrl ? (
					<iframe
						className="ft-demo-preview__iframe"
						src={ iframeUrl }
						title={ sprintf( /* translators: %s: template title */ __( 'Preview of %s', 'famethemes-demo-importer' ), title ) }
						loading="lazy"
					/>
				) : (
					<div className="ft-demo-preview__iframe-fallback">
						{ detail ? __( 'No preview URL available.', 'famethemes-demo-importer' ) : <Spinner /> }
					</div>
				) }
			</div>
		</div>
	);
}
