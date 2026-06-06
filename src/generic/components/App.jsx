/**
 * Generic-track admin app — top-level.
 *
 * UX matches OnePress: card click → fullscreen preview (sidebar + iframe).
 * "Import Now" button INSIDE the preview is what actually starts the
 * job; same panel hosts the progress steps. App owns the "which
 * template is previewed?" state — PreviewPanel owns the job state.
 *
 * The wizard modal does NOT mount until the template's options.json is
 * loaded. While the prefetch is in flight, the corresponding card
 * shows a spinner so the click feels acknowledged; once the JSON is
 * back the modal renders fully populated (palette swatches included).
 * This avoids the flicker the user would see if the modal painted
 * blank and then snapped to the template-bundled palette a few hundred
 * milliseconds later.
 */

import { useCallback, useState } from '@wordpress/element';

import { TemplateGrid } from './TemplateGrid';
import { PreviewPanel } from './PreviewPanel';
import { studio } from '../api';

export function App() {
	// `loading` — template the user clicked, options prefetch in flight.
	// `preview` — options ready, modal can mount.
	const [ loading, setLoading ] = useState( null );
	const [ preview, setPreview ] = useState( null );

	const requestPreview = useCallback( async ( template ) => {
		if ( ! template || ! template.id ) return;
		// Track WHICH template's preview is loading so the right card
		// gets the spinner overlay; clears either way (success / fail).
		setLoading( template );
		try {
			// REST proxy is server-cached (2h transient), so repeat
			// opens of the same template are near-instant after the
			// first MISS. Failure path still proceeds — PreviewPanel
			// falls back to host palettes when `prefetchedOptions` is
			// null.
			const options = await studio.getTemplateOptions( template.id ).catch( () => null );
			setPreview( { template, options } );
		} finally {
			setLoading( null );
		}
	}, [] );

	const closePreview = useCallback( () => {
		setPreview( null );
	}, [] );

	return (
		<>
			<TemplateGrid
				onSelect={ requestPreview }
				loadingId={ loading ? loading.id : null }
			/>

			{ preview && (
				<PreviewPanel
					template={ preview.template }
					prefetchedOptions={ preview.options }
					onClose={ closePreview }
				/>
			) }
		</>
	);
}
