/**
 * Single template card — mirrors OnePress's `loop_theme()` markup so the
 * shared `style.css` styles it identically. The whole card surface is
 * the click target (OnePress delegates on `.demo-contents-themes-listing
 * .theme`), so clicking ANY part — screenshot, title, "View Details"
 * span, or the action button — opens the preview panel.
 */

import { __ } from '@wordpress/i18n';

export function TemplateCard( { template, onSelect } ) {
	const thumb  = template.preview_url || template.preview || '';
	const author = template.author || '';
	const name   = template.title || template.name || `#${ template.id }`;

	const handleClick = ( e ) => {
		e.preventDefault();
		onSelect( template );
	};

	const handleKey = ( e ) => {
		if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			onSelect( template );
		}
	};

	return (
		<div
			className="theme"
			tabIndex={ 0 }
			role="button"
			data-template-id={ template.id }
			onClick={ handleClick }
			onKeyDown={ handleKey }
		>
			{ thumb ? (
				<div className="theme-screenshot">
					<img src={ thumb } alt="" />
				</div>
			) : (
				<div className="theme-screenshot blank" />
			) }

			<span className="more-details">
				{ __( 'View Details', 'famethemes-demo-importer' ) }
			</span>

			{ author && (
				<div className="theme-author">
					{ __( 'by', 'famethemes-demo-importer' ) } { author }
				</div>
			) }
			<div className="theme-name">{ name }</div>

			<div className="theme-actions">
				<a
					href="#"
					className="demo-contents--preview-theme-btn button button-primary customize"
					onClick={ handleClick }
					data-template-id={ template.id }
				>
					{ __( 'Start Import', 'famethemes-demo-importer' ) }
				</a>
			</div>
		</div>
	);
}
