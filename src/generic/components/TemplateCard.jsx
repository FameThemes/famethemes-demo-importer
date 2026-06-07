/**
 * One template tile in the grid — 4:5 thumb, title row with Preview +
 * Import buttons. Pro badge pins to the top-right of the thumb when
 * the template is gated. Clicking the thumb (or Import) opens the
 * wizard; Preview routes to the template's demo URL in a new tab.
 *
 * Buttons use `@wordpress/components` `<Button>` so they inherit the
 * admin scheme and accessibility behavior of every other WP admin
 * surface — no custom button styling on this component.
 */

import { Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function TemplateCard( { template, onSelect, loading = false } ) {
	// Studio's preview_image is a nested attachment-shape (see
	// `docs/studio/rest-api.md` §preview_image): full / medium / thumb
	// crops, plus a flat top-level `url`. Prefer the largest crop the
	// browser will actually render at 3:4 card width — `full` typically
	// matches the source upload size; `medium` is the 300x200 thumbnail
	// fallback for templates with smaller source images.
	//
	// `template.preview_url` is the iframe demo route (e.g. `?pmbd_preview=N`),
	// NOT an image URL — used by PreviewPanel.jsx, never by the card.
	const preview = template.preview_image;
	const rawThumb = preview?.full?.url
		|| preview?.medium?.url
		|| preview?.url
		|| template.thumb_url
		|| '';

	// Cache-bust the thumbnail URL with the template's `version`
	// counter so admins see fresh screenshots immediately after a
	// re-submit (each re-submit increments the version), but the
	// browser still caches between updates — much better than
	// `Date.now()` which would defeat caching entirely.
	const thumb = rawThumb && template.version != null
		? `${ rawThumb }${ rawThumb.includes( '?' ) ? '&' : '?' }v=${ encodeURIComponent( template.version ) }`
		: rawThumb;
	const name    = template.title || template.name || `#${ template.id }`;
	const isPro   = Boolean( template.is_pro || template.pro );
	// Studio's canonical demo URL lives at `preview_url` (verified from
	// `GET /studio/templates`). `demo_url` / `frame_url` /
	// `preview_route` are kept as fallbacks for legacy Studio schemas
	// and for tests that stub the shape.
	const demoUrl =
		template.preview_url
		|| template.demo_url
		|| template.frame_url
		|| template.preview_route
		|| '';

	const openWizard = () => {
		if ( loading ) return; // a prefetch is already running for this card
		onSelect( template );
	};

	const handleThumbClick = ( e ) => {
		// Buttons inside the body row handle their own clicks via
		// `<Button onClick>`; only the thumb surface itself opens the
		// wizard, so stopPropagation on the buttons isn't needed.
		e.preventDefault();
		openWizard();
	};

	const openDemo = ( e ) => {
		e.preventDefault();
		if ( demoUrl ) {
			window.open( demoUrl, '_blank', 'noopener,noreferrer' );
		} else {
			openWizard();
		}
	};

	return (
		<article
			className={ 'fdi-card' + ( loading ? ' is-loading' : '' ) }
			data-template-id={ template.id }
			aria-busy={ loading || undefined }
		>
			{ loading && (
				<div className="fdi-card__loading" role="status" aria-label={ __( 'Loading template…', 'famethemes-demo-importer' ) }>
					<Spinner />
				</div>
			) }
			<div
				className="fdi-card__thumb"
				onClick={ handleThumbClick }
				role="button"
				tabIndex={ 0 }
				onKeyDown={ ( e ) => { if ( e.key === 'Enter' ) handleThumbClick( e ); } }
				aria-label={ name }
			>
				{ isPro && (
					<span className="fdi-card__pro-badge">{ __( 'Pro', 'famethemes-demo-importer' ) }</span>
				) }
				{ thumb && (
					<img src={ thumb } alt="" loading="lazy" />
				) }
			</div>

			<div className="fdi-card__body">
				<div className="fdi-card__title" title={ name }>{ name }</div>
				<div className="fdi-card__actions">
					<Button variant="secondary" onClick={ openDemo }>
						{ __( 'Preview', 'famethemes-demo-importer' ) }
					</Button>
					<Button variant="primary" onClick={ openWizard }>
						{ __( 'Import', 'famethemes-demo-importer' ) }
					</Button>
				</div>
			</div>
		</article>
	);
}
