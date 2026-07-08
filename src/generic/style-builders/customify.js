/**
 * Customify style builder.
 *
 * Emits the CSS payload the Customify theme expects on its preview
 * iframe — palette colours (6 base slots + ~16 derived tokens) and
 * typography pair (heading + body across 11 typography vars).
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
 *         --customify-accent: #...;
 *         --customify-surface: #...;
 *         --customify-text-muted: #...;
 *         --customify-body-text: #...;
 *         --customify-primary-hover: #...;
 *         --customify-link: #...;
 *         --customify-link-hover: #...;
 *         --customify-heading: #...;
 *         --customify-widget-title: #...;
 *         --customify-border: #...;
 *         --customify-on-primary: #...;
 *         --customify-on-secondary: #...;
 *         --customify-on-accent: #...;
 *         --customify-on-surface: #...;
 *         --customify-primary-container: #...;
 *         --customify-secondary-container: #...;
 *         --customify-accent-container: #...;
 *         --customify-on-primary-container: #...;
 *         --customify-on-secondary-container: #...;
 *         --customify-on-accent-container: #...;
 *         --customify-border-strong: #...;
 *         --customify-typo-base-heading-font-family: "...", Georgia, serif;
 *         ...8 more title-slot vars...
 *         --customify-typo-base-p-font-family: "...", system-ui, sans-serif;
 *         --customify-typo-site-tt-desc-font-family: "...", system-ui, sans-serif;
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

function _srgbToOklab( hex ) {
	const rgb = _hexToRgb( hex );
	if ( ! rgb ) return [ 0, 0, 0 ];
	const f = ( v ) => {
		v = v / 255;
		return v <= 0.04045 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
	};
	const rl = f( rgb[ 0 ] );
	const gl = f( rgb[ 1 ] );
	const bl = f( rgb[ 2 ] );
	const l = 0.4122214708 * rl + 0.5363325363 * gl + 0.0514459929 * bl;
	const m = 0.2119034982 * rl + 0.6806995451 * gl + 0.1073969566 * bl;
	const s = 0.0883024619 * rl + 0.2817188376 * gl + 0.6299787005 * bl;
	const cbrt = ( x ) => ( x < 0 ? -Math.pow( -x, 1 / 3 ) : Math.pow( x, 1 / 3 ) );
	const l_ = cbrt( l );
	const m_ = cbrt( m );
	const s_ = cbrt( s );
	return [
		0.2104542553 * l_ + 0.7936177850 * m_ - 0.0040720468 * s_,
		1.9779984951 * l_ - 2.4285922050 * m_ + 0.4505937099 * s_,
		0.0259040371 * l_ + 0.7827717662 * m_ - 0.8086757660 * s_,
	];
}

function _oklabToSrgb( L, a, b ) {
	const l_ = L + 0.3963377774 * a + 0.2158037573 * b;
	const m_ = L - 0.1055613458 * a - 0.0638541728 * b;
	const s_ = L - 0.0894841775 * a - 1.2914855480 * b;
	const l = l_ * l_ * l_;
	const m = m_ * m_ * m_;
	const s = s_ * s_ * s_;
	const rl =  4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s;
	const gl = -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s;
	const bl = -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s;
	const g = ( v ) => {
		v = Math.max( 0, Math.min( 1, v ) );
		return v <= 0.0031308 ? v * 12.92 : 1.055 * Math.pow( v, 1 / 2.4 ) - 0.055;
	};
	return _rgbToHex( [ g( rl ) * 255, g( gl ) * 255, g( bl ) * 255 ] );
}

function _oklabL( hex ) { return _srgbToOklab( hex )[ 0 ]; }

function _solveContainerP( source, base ) {
	const ls = _oklabL( source );
	const lb = _oklabL( base );
	const denom = ls - lb;
	if ( Math.abs( denom ) < 1e-6 ) return 0.5;
	const p = ( 0.93 - lb ) / denom;
	return Math.max( 0.02, Math.min( 0.98, p ) );
}

function _chromaCap( hex, maxChroma ) {
	const lab = _srgbToOklab( hex );
	const L = lab[ 0 ];
	const a = lab[ 1 ];
	const b = lab[ 2 ];
	const c = Math.sqrt( a * a + b * b );
	if ( c <= maxChroma ) return hex;
	const s = maxChroma / c;
	return _oklabToSrgb( L, a * s, b * s );
}

function _lReduceUntilContrast( source, bg, target ) {
	target = target || 4.5;
	const lab = _srgbToOklab( source );
	let L = lab[ 0 ];
	const a = lab[ 1 ];
	const b = lab[ 2 ];
	while ( L > 0 ) {
		const candidate = _oklabToSrgb( L, a, b );
		if ( _wcagContrast( candidate, bg ) >= target ) return candidate;
		L -= 0.02;
	}
	return '#1A1A1A';
}

function _solveBorderStrong( text, base ) {
	for ( let p = 6; p <= 100; p++ ) {
		const mix = _mixHex( text, base, p / 100 );
		if ( _wcagContrast( mix, base ) >= 3.0 ) return mix;
	}
	return text;
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
	vars.push( `--customify-accent: ${ accent }` );
	vars.push( `--customify-surface: ${ surface }` );

	vars.push( `--customify-text-muted: ${ _mixHex( text, base, 0.70 ) }` );
	vars.push( `--customify-body-text: ${ text }` );
	vars.push( `--customify-primary-hover: ${ _mixHex( primary, '#000000', 0.90 ) }` );
	vars.push( `--customify-link: ${ primary }` );
	vars.push( `--customify-link-hover: ${ primary }` );
	vars.push( `--customify-heading: ${ text }` );
	vars.push( `--customify-widget-title: ${ text }` );
	vars.push( `--customify-border: ${ _mixHex( text, base, 0.09 ) }` );

	vars.push( `--customify-on-primary: ${ _pickOn( primary, base ) }` );
	vars.push( `--customify-on-secondary: ${ _pickOn( secondary, base ) }` );
	vars.push( `--customify-on-accent: ${ _pickOn( accent, base ) }` );
	vars.push( `--customify-on-surface: ${ _pickOn( surface, base ) }` );

	const CHROMA_CAP = 0.04;
	const primContainerHex = _chromaCap( _mixHex( primary,   base, _solveContainerP( primary,   base ) ), CHROMA_CAP );
	const secContainerHex  = _chromaCap( _mixHex( secondary, base, _solveContainerP( secondary, base ) ), CHROMA_CAP );
	const accContainerHex  = _chromaCap( _mixHex( accent,    base, _solveContainerP( accent,    base ) ), CHROMA_CAP );
	vars.push( `--customify-primary-container: ${ primContainerHex }` );
	vars.push( `--customify-secondary-container: ${ secContainerHex }` );
	vars.push( `--customify-accent-container: ${ accContainerHex }` );

	vars.push( `--customify-on-primary-container: ${ _lReduceUntilContrast( primary,   primContainerHex ) }` );
	vars.push( `--customify-on-secondary-container: ${ _lReduceUntilContrast( secondary, secContainerHex ) }` );
	vars.push( `--customify-on-accent-container: ${ _lReduceUntilContrast( accent,    accContainerHex ) }` );

	vars.push( `--customify-border-strong: ${ _solveBorderStrong( text, base ) }` );

	return vars;
}

// ── Typography ────────────────────────────────────────────────────────────

// The Customify theme consumes exactly TWO foundation font-family tokens
// (see `themes/customify/docs/SPEC-typography.md` §4.2): every heading
// shares `--customify-typo-heading-font-family` (h1–h6 inherit it via the
// `_base.scss` shared heading rule — the per-level `h{n}` tokens carry
// only font-size/line-height; site-title + widget-title default to the
// same heading token), and body text reads
// `--customify-typo-body-font-family`. The previous
// `--customify-typo-base-*` / `--customify-typo-h{n}-font-family` names
// exist NOWHERE in the theme, so the injected CSS was a silent no-op and
// the typography preview never changed the font. Emit the real two.
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
