/**
 * Placeholder data for features that don't have a backend yet.
 *
 * Color palettes and font pairs are presented in the wizard's "Style"
 * step so the UX can be reviewed end-to-end, but the user's selection
 * isn't sent to the job runner — those fields land in the future when
 * the Studio exposes a style payload alongside templates.
 *
 * Once that backend lands, move these arrays into a `/studio/styles`
 * REST response and feed the wizard from there.
 */

export const PALETTES = [
	{ id: 'warm',   name: 'Warm Sunset', colors: [ '#1c1c1c', '#e85d04', '#faa307', '#ffd6a5' ] },
	{ id: 'cool',   name: 'Ocean Cool',  colors: [ '#0f1f3a', '#2196f3', '#87ceeb', '#f7f9fc' ] },
	{ id: 'forest', name: 'Forest',      colors: [ '#1f3a23', '#6b8e23', '#c4d8a2', '#f1f5e8' ] },
	{ id: 'mono',   name: 'Monochrome',  colors: [ '#0a0a0a', '#404040', '#b0b0b0', '#f4f4f4' ] },
	{ id: 'bold',   name: 'Bold Pop',    colors: [ '#d81b60', '#fdd835', '#1a237e', '#fafafa' ] },
];

export const FONTS = [
	{ id: 'inter-inter',     heading: 'Inter',            body: 'Inter',         weight: 600 },
	{ id: 'playfair-source', heading: 'Playfair Display', body: 'Source Sans 3', weight: 600 },
	{ id: 'lora-merri',      heading: 'Lora',             body: 'Merriweather',  weight: 400 },
	{ id: 'poppins-roboto',  heading: 'Poppins',          body: 'Roboto',        weight: 600 },
	{ id: 'mont-opensans',   heading: 'Montserrat',       body: 'Open Sans',     weight: 600 },
	{ id: 'bebas-lato',      heading: 'Bebas Neue',       body: 'Lato',          weight: 400 },
];
