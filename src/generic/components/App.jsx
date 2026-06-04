/**
 * Generic-track admin app — top-level.
 *
 * UX matches OnePress: card click → fullscreen preview (sidebar + iframe).
 * "Import Now" button INSIDE the preview is what actually starts the
 * job; same panel hosts the progress steps. App owns the "which
 * template is previewed?" state — PreviewPanel owns the job state.
 */

import { useState } from '@wordpress/element';

import { TemplateGrid } from './TemplateGrid';
import { PreviewPanel } from './PreviewPanel';

export function App() {
	const [ preview, setPreview ] = useState( null );

	return (
		<>
			<TemplateGrid onSelect={ setPreview } />

			{ preview && (
				<PreviewPanel
					template={ preview }
					onClose={ () => setPreview( null ) }
				/>
			) }
		</>
	);
}
