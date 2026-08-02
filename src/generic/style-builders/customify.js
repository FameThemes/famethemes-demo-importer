/**
 * Customify style builder.
 *
 * Emits the CSS payload the Customify theme expects on its preview
 * iframe — the palette + typography vars the current Customify theme
 * actually reads. Legacy vars (typo per-slot, container tokens,
 * border-strong, primary-hover, accent) have been dropped: the theme
 * no longer consumes them.
 *
 * The colour-derivation math is a 1:1 port of the theme's own
 * Customizer-preview JS in
 * `themes/customify/inc/colors-palette.php::customify_color_palette_preview_js`.
 * Helpers are kept verbatim (same names, same signatures) so future
 * spec changes can be ported by copy-paste.
 *
 * Output shape (palette + font both present):
 *
 *     @import url( https://fonts.googleapis.com/css?family=... );
 *     :root {
 *         --customify-base: #...;
 *         --customify-text: #...;
 *         --customify-primary: #...;
 *         --customify-secondary: #...;
 *         --customify-surface: #...;
 *         --customify-text-muted: #...;
 *         --customify-body-text: #...;
 *         --customify-link: #...;
 *         --customify-link-hover: #...;
 *         --customify-heading: #...;
 *         --customify-widget-title: #...;
 *         --customify-border: #...;
 *         --customify-on-primary: #...;
 *         --customify-on-secondary: #...;
 *         --customify-on-accent: #...;
 *         --customify-on-surface: #...;
 *         --customify-btn-on-primary: #...;
 *         --customify-btn-on-secondary: #...;
 *         --customify-typo-heading-font-family: "...", Georgia, serif;
 *         --customify-typo-body-font-family: "...", system-ui, sans-serif;
 *     }
 */

// ── Colour math helpers (port of theme JS) ─────────────────────────────────

function _hexToRgb( value ) {
	value = ( value || '' ).toString().trim();
	const m = value.match( /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*[\d.]+)?\s*\)$/i );
	if ( m ) {
		return [
			Math.max( 0, Math.min( 255, parseInt( m[ 1 ], 10 ) ) ),
			Math.max( 0, Math.min( 255, parseInt( m[ 2 ], 10 ) ) ),
			Math.max( 0, Math.min( 255, parseInt( m[ 3 ], 10 ) ) ),
		];
	}
	let hex = value.replace( /^#/, '' );
	if ( hex.length === 3 ) hex = hex[ 0 ] + hex[ 0 ] + hex[ 1 ] + hex[ 1 ] + hex[ 2 ] + hex[ 2 ];
	if ( ! /^[0-9a-fA-F]{6}$/.test( hex ) ) return null;
	return [
		parseInt( hex.slice( 0, 2 ), 16 ),
		parseInt( hex.slice( 2, 4 ), 16 ),
		parseInt( hex.slice( 4, 6 ), 16 ),
	];
}

function _rgbToHex( rgb ) {
	const c = ( v ) => {
		v = Math.max( 0, Math.min( 255, Math.round( v ) ) );
		const h = v.toString( 16 );
		return h.length === 1 ? '0' + h : h;
	};
	return '#' + c( rgb[ 0 ] ) + c( rgb[ 1 ] ) + c( rgb[ 2 ] );
}

function _mixHex( a, b, weightA ) {
	weightA = Math.max( 0, Math.min( 1, weightA ) );
	const wb = 1 - weightA;
	const ra = _hexToRgb( a );
	const rb = _hexToRgb( b );
	if ( ! ra || ! rb ) return a;
	return _rgbToHex( [
		ra[ 0 ] * weightA + rb[ 0 ] * wb,
		ra[ 1 ] * weightA + rb[ 1 ] * wb,
		ra[ 2 ] * weightA + rb[ 2 ] * wb,
	] );
}

function _relativeLuminance( hex ) {
	const rgb = _hexToRgb( hex );
	if ( ! rgb ) return 0;
	const f = ( v ) => {
		v = v / 255;
		return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
	};
	return 0.2126 * f( rgb[ 0 ] ) + 0.7152 * f( rgb[ 1 ] ) + 0.0722 * f( rgb[ 2 ] );
}

function _wcagContrast( a, b ) {
	const la = _relativeLuminance( a );
	const lb = _relativeLuminance( b );
	const hi = Math.max( la, lb );
	const lo = Math.min( la, lb );
	return ( hi + 0.05 ) / ( lo + 0.05 );
}

function _compositeOver( value, baseHex ) {
	const v = ( value || '' ).toString().trim();
	const m = v.match( /^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*([\d.]+)\s*\)/i );
	if ( m ) {
		const r = Math.max( 0, Math.min( 255, parseInt( m[ 1 ], 10 ) ) );
		const g = Math.max( 0, Math.min( 255, parseInt( m[ 2 ], 10 ) ) );
		const b = Math.max( 0, Math.min( 255, parseInt( m[ 3 ], 10 ) ) );
		const a = Math.max( 0, Math.min( 1, parseFloat( m[ 4 ] ) ) );
		if ( a >= 1 ) return [ r, g, b ];
		const baseRgb = _hexToRgb( baseHex ) || [ 255, 255, 255 ];
		return [
			Math.round( r * a + baseRgb[ 0 ] * ( 1 - a ) ),
			Math.round( g * a + baseRgb[ 1 ] * ( 1 - a ) ),
			Math.round( b * a + baseRgb[ 2 ] * ( 1 - a ) ),
		];
	}
	return _hexToRgb( v ) || [ 0, 0, 0 ];
}

function _pickOn( value, baseHex ) {
	baseHex = baseHex || '#FFFFFF';
	const rgb = _compositeOver( value, baseHex );
	const eff = _rgbToHex( rgb );
	return _wcagContrast( '#FFFFFF', eff ) >= _wcagContrast( '#1A1A1A', eff )
		? '#FFFFFF' : '#1A1A1A';
}

function buildPaletteVars( palette ) {
	if ( ! palette || ! Array.isArray( palette.colors ) || palette.colors.length < 6 ) {
		return [];
	}
	const [ primary, secondary, accent, text, surface, base ] = palette.colors;
	const vars = [];

	vars.push( `--customify-base: ${ base }` );
	vars.push( `--customify-text: ${ text }` );
	vars.push( `--customify-primary: ${ primary }` );
	vars.push( `--customify-secondary: ${ secondary }` );
	vars.push( `--customify-surface: ${ surface }` );

	vars.push( `--customify-text-muted: ${ _mixHex( text, base, 0.70 ) }` );
	vars.push( `--customify-body-text: ${ text }` );
	vars.push( `--customify-link: ${ primary }` );
	vars.push( `--customify-link-hover: ${ primary }` );
	vars.push( `--customify-heading: ${ text }` );
	vars.push( `--customify-widget-title: ${ text }` );
	vars.push( `--customify-border: ${ _mixHex( text, base, 0.09 ) }` );

	// On-* contrast tokens — WCAG-picked black/white against each brand
	// on the base surface. Theme reads these for `.has-{brand}-background-color`
	// text and, aliased below, for button labels.
	const onPrimary   = _pickOn( primary, base );
	const onSecondary = _pickOn( secondary, base );
	vars.push( `--customify-on-primary: ${ onPrimary }` );
	vars.push( `--customify-on-secondary: ${ onSecondary }` );
	vars.push( `--customify-on-accent: ${ _pickOn( accent, base ) }` );
	vars.push( `--customify-on-surface: ${ _pickOn( surface, base ) }` );

	// Button label tokens — theme aliases these to --customify-on-* when the
	// palette panel is opted in. Preview always opts in, so emit the resolved
	// hex directly to avoid `var(...)` chain resolution mismatches in older
	// preview iframes.
	vars.push( `--customify-btn-on-primary: ${ onPrimary }` );
	vars.push( `--customify-btn-on-secondary: ${ onSecondary }` );

	return vars;
}

// ── Typography ────────────────────────────────────────────────────────────

// Current Customify foundation tokens (per themes/customify/docs/SPEC-typography.md
// §4.2): a single shared `--customify-typo-heading-font-family` cascades to
// h1–h6 + site/widget titles, and `--customify-typo-body-font-family` drives
// body + site description.
const TYPO_VAR_HEADING = [ '--customify-typo-heading-font-family' ];
const TYPO_VAR_BODY    = [ '--customify-typo-body-font-family' ];

const ALL_FONT_VARIANTS = [
	'100','200','300','400','500','600','700','800','900',
	'100italic','200italic','300italic','400italic','500italic',
	'600italic','700italic','800italic','900italic',
].join( ',' );

function escapeForCss( value ) {
	return String( value ).replace( /["\\]/g, '' );
}

function googleFontsImportLine( families ) {
	const list = families.filter( Boolean );
	if ( ! list.length ) return '';
	const url =
		'https://fonts.googleapis.com/css?family=' +
		list
			.map( ( f ) => encodeURIComponent( f ).replace( /%20/g, '+' ) + ':' + ALL_FONT_VARIANTS )
			.join( '|' ) +
		'&display=swap';
	return `@import url('${ url }');`;
}

function buildTypographyVars( font ) {
	if ( ! font || ! font.heading || ! font.body ) {
		return [];
	}
	const headingValue = `"${ escapeForCss( font.heading ) }", Georgia, serif`;
	const bodyValue    = `"${ escapeForCss( font.body ) }", system-ui, sans-serif`;
	const vars = [];
	for ( const v of TYPO_VAR_HEADING ) vars.push( `${ v }: ${ headingValue }` );
	for ( const v of TYPO_VAR_BODY ) vars.push( `${ v }: ${ bodyValue }` );
	return vars;
}

// ── Entry ─────────────────────────────────────────────────────────────────

export default function buildCss( palette, font ) {
	const sections = [];

	if ( font && font.heading && font.body ) {
		const families = font.heading === font.body
			? [ font.heading ]
			: [ font.heading, font.body ];
		const importLine = googleFontsImportLine( families );
		if ( importLine ) sections.push( importLine );
	}

	const decls = [];
	decls.push( ...buildPaletteVars( palette ) );
	decls.push( ...buildTypographyVars( font ) );

	if ( decls.length ) {
		// Each entry produced by the helpers above is a declaration
		// WITHOUT trailing `;`. Join with `; ` + add a final `;` so
		// the `:root` block is a sequence of valid declarations.
		sections.push( `:root { ${ decls.join( '; ' ) }; }` );
	}

	return sections.join( '\n' );
}
