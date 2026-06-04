<?php
/**
 * Legacy track bootstrap.
 *
 * Active when the running theme is one of the FameThemes legacy themes —
 * OnePress, Screenr, Accelerate, Bizland, Oblique, Shapely, Crispmag,
 * Sparkling, Cleanblock by default, plus their `-pro` and child variants.
 * The exact list is filterable via `demo_contents_onepress_themes`
 * (defined in the root plugin file's router).
 *
 * This file is intentionally a thin loader: it requires the files that
 * used to live directly under `inc/` (now relocated to `inc/legacy/`)
 * in the same order the historical Demo_Contents constructor did, then
 * attaches the Dashboard + Progress instances to the Demo_Contents
 * singleton so every existing `Demo_Contents::get_instance()->dashboard`
 * / `->progress` callsite keeps working byte-for-byte.
 *
 * No edits were made to the moved files — Merlin's lazy `class_exists`
 * guards in class-progress.php make its relocated require paths
 * harmless because we load Merlin upfront here, so the lazy requires
 * become no-ops.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Demo_Contents singleton — moved out of the root plugin file so the
// Generic track never has to parse the ~500 LOC class for code it
// doesn't use. Boot the instance immediately so subsequent classes
// can attach themselves onto `->dashboard` / `->progress` and so the
// `?demo_contents_export=1` hook registers.
require_once __DIR__ . '/class-demo-contents.php';
Demo_Contents::get_instance();

// Same load order the root plugin file used historically.
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/class-tgm-plugin-activation.php';
require_once __DIR__ . '/theme-supports.php';

// Load Merlin upfront so the lazy `if ( ! class_exists(...) )` requires
// inside class-progress.php's ajax_import() become no-ops. Otherwise
// they would try to require from `inc/merlin-wp/…` (pre-move path) and
// fatal — touching the legacy code is what we explicitly want to avoid
// (the files are frozen as historical reference).
require_once __DIR__ . '/merlin-wp/includes/class-merlin-helper.php';
require_once __DIR__ . '/merlin-wp/includes/class-merlin-xml-parser.php';
require_once __DIR__ . '/merlin-wp/includes/class-merlin-importer.php';

require_once __DIR__ . '/class-dashboard.php';
require_once __DIR__ . '/class-progress.php';

// Attach Dashboard + Progress to the Demo_Contents singleton — both are
// accessed externally via `Demo_Contents::get_instance()->dashboard->...`
// and `->progress->...`, so they must live on the same instance.
$_demo_contents = Demo_Contents::get_instance();
if ( null === $_demo_contents->dashboard ) {
	$_demo_contents->dashboard = new Demo_Content_Dashboard();
}
if ( null === $_demo_contents->progress ) {
	$_demo_contents->progress = new Demo_Contents_Progress();
}
unset( $_demo_contents );

/**
 * Legacy-active wp-admin tweaks for cohabiting plugins + this plugin's
 * plugins.php row. Only fires inside the Legacy track bootstrap, so the
 * behaviour is automatically scoped to FameThemes-family installs.
 */

/*
 * Hide `Appearance → Template Importer` (blocksify-design-importer) while
 * the Legacy track is active. Each FameThemes theme ships its own demo
 * importer inside its theme admin page (`admin.php?page=ft_<slug>&tab=demo-data-importer`);
 * a second "Template Importer" entry next to it is just noise.
 *
 * Priority 999 so removal runs after blocksify-design-importer's own
 * `admin_menu` registration (default priority 10). `remove_submenu_page`
 * is a no-op when the entry doesn't exist, so the call is safe even when
 * blocksify-design-importer is deactivated.
 */
add_action( 'admin_menu', static function (): void {
	remove_submenu_page( 'themes.php', 'pmbd-importer' );
}, 999 );

/*
 * Plugins.php row: add an "Import demo" action link that deep-links into
 * the active theme's admin page on the demo-data-importer tab — the same
 * surface this plugin's UI hooks (`{theme_slug}_demo_import_content_tab`
 * action). URL is built dynamically from the active stylesheet because
 * each FameThemes theme registers its own `ft_<slug>` admin page; a
 * hardcoded `ft_onepress` would 404 when Accelerate / Bizland / etc.
 * uses this track.
 *
 * Only registered inside the Legacy track bootstrap so themes routed to
 * the Generic track don't see a link to a tab that doesn't exist for
 * them — those themes get their own filter in `inc/generic/bootstrap.php`
 * that points at the React dashboard instead.
 *
 * `plugin_basename( DEMO_CONTENT_PATH . 'famethemes-demo-importer.php' )`
 * gives the `folder/main.php` slug the filter hook expects, surviving any
 * future plugin-folder rename.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( DEMO_CONTENT_PATH . 'famethemes-demo-importer.php' ),
	static function ( array $links ): array {
		$template = sanitize_key( (string) get_option( 'template' ) );
		if ( '' === $template ) {
			return $links;
		}
		$url = admin_url( 'admin.php?page=ft_' . $template . '&tab=demo-data-importer' );
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Import demo', 'famethemes-demo-importer' )
		);
		return $links;
	}
);

/*
 * Allow XML + JSON uploads via the Media Library — the legacy "Upload
 * XML / Upload JSON" buttons inside the preview modal accept manually
 * downloaded demo files. Restricted to users who can already upload,
 * so the gate doesn't loosen anything on its own.
 *
 * Scoped to the Legacy track because the Generic track has no manual-
 * upload UI; widening the upload allowlist for a site that doesn't
 * need it would be unnecessary attack surface.
 */
add_filter( 'upload_mimes', static function ( array $mimes ): array {
	if ( current_user_can( 'upload_files' ) ) {
		$mimes['xml']  = 'application/xml';
		$mimes['json'] = 'application/json';
	}
	return $mimes;
} );
