/**
 * Generic-track admin app — top-level.
 *
 * UX matches OnePress: card click → fullscreen preview (sidebar + iframe).
 * "Import Now" button INSIDE the preview is what actually starts the
 * job; same panel hosts the progress steps. App owns the "which
 * template is previewed?" state — PreviewPanel owns the job state.
 *
 * As of the snapshot rollout the wizard opens INSTANTLY: the studio's
 * list endpoint ships `requirements`, `preview_url`, and the
 * theme-options blob (`customify_color_palettes`, `customify_active_palette`,
 * …) directly on each `items[]` entry, so there's nothing to prefetch.
 * Previous versions made two round trips here — `/templates/{id}` for
 * the detail shape and `/templates/{id}/options` for the palettes blob —
 * which forced a ~400ms blank-card window after every click.
 */

import { useCallback, useState } from '@wordpress/element';

import { TemplateGrid } from './TemplateGrid';
import { PreviewPanel } from './PreviewPanel';

export function App() {
	const [ preview, setPreview ] = useState( null );

	const requestPreview = useCallback( ( template ) => {
		if ( ! template || ! template.id ) {
			return;
		}
		setPreview( { template } );
	}, [] );

	const closePreview = useCallback( () => {
		setPreview( null );
	}, [] );

	return (
		<>
			<TemplateGrid onSelect={ requestPreview } />

			{ preview && (
				<PreviewPanel
					template={ preview.template }
					onClose={ closePreview }
				/>
			) }
		</>
	);
}
