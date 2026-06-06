<?php
/**
 * Generic-track bootstrap.
 *
 * Loaded by the root plugin router when the active theme is NOT in the
 * OnePress family. Wires together the per-theme adapter, the background
 * job pipeline (Phase B4), the Studio REST proxy (Phase B3), and the
 * admin dashboard UI (Phase B2). This file is intentionally the only
 * entry point — adding a new sub-module is one `require_once` here +
 * its hook registrations.
 *
 * Each `B*` block guards future work that hasn't landed yet — the file
 * loads cleanly today and can be extended without restructuring.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// -- B1: skeleton -------------------------------------------------------
require_once __DIR__ . '/Adapters/class-theme-adapter.php';
require_once __DIR__ . '/Adapters/class-default-adapter.php';
require_once __DIR__ . '/class-adapter-registry.php';
require_once __DIR__ . '/class-theme-detector.php';
require_once __DIR__ . '/Settings/class-options-store.php';

// Built-in adapters self-register here. Theme-specific adapters in
// alphabetical order so the load list stays scannable. The Customify
// adapter additionally calls `register_integration()` to wire the
// embed-into-theme-dashboard hooks (boot filter, redirect, hide own
// menu) — kept on the adapter so the generic layer below stays
// theme-agnostic.
require_once __DIR__ . '/Adapters/class-customify-adapter.php';
$_ft_customify_adapter = new FT_Demo_Importer\Adapters\Customify_Adapter();
FT_Demo_Importer\Adapter_Registry::register( $_ft_customify_adapter );
$_ft_customify_adapter->register_integration();
unset( $_ft_customify_adapter );

// -- B2 + B3: dashboard, Studio client, REST proxy ---------------------
// Settings_Page intentionally not loaded — the plugin no longer ships a
// settings UI. Studio credentials are supplied per theme via the active
// adapter's `studio_server_url()` / `studio_api_key()` overrides, with
// wp-config constants still taking precedence through the existing
// filter chain below.
require_once __DIR__ . '/class-generic-dashboard.php';
require_once __DIR__ . '/Studio/class-remote-client.php';
require_once __DIR__ . '/REST/class-studio-proxy-controller.php';

$_ft_options = new FT_Demo_Importer\Settings\Options_Store();
$_ft_client  = new FT_Demo_Importer\Studio\Remote_Client( $_ft_options );
( new FT_Demo_Importer\Generic_Dashboard( $_ft_options ) )->register();
( new FT_Demo_Importer\REST\Studio_Proxy_Controller( $_ft_client ) )->register();
unset( $_ft_options, $_ft_client );

// -- B5: pipeline steps (eager-loaded so the runner can `new` them
//        without per-call require_once). Stubs today; B5 fills bodies.
require_once __DIR__ . '/Steps/class-asset-fetcher.php';
require_once __DIR__ . '/Steps/class-plugin-installer.php';
require_once __DIR__ . '/Steps/class-uploads-extractor.php';
require_once __DIR__ . '/Steps/class-content-importer.php';
require_once __DIR__ . '/Steps/class-options-importer.php';

// -- B4: job system ----------------------------------------------------
// Order: Job_Store first (referenced by both the runner + controller),
// then runner (registers cron hook), then controller (registers REST
// routes that drive the runner).
require_once __DIR__ . '/Jobs/class-job-store.php';
require_once __DIR__ . '/Jobs/class-importer-runner.php';
require_once __DIR__ . '/REST/class-job-controller.php';

// Reuse the Studio client instantiated above for the proxy controller
// — runner needs it too (Asset_Fetcher streams via the same auth).
$_ft_jobs   = new FT_Demo_Importer\Jobs\Job_Store();
$_ft_runner = new FT_Demo_Importer\Jobs\Importer_Runner(
	new FT_Demo_Importer\Studio\Remote_Client( new FT_Demo_Importer\Settings\Options_Store() ),
	$_ft_jobs
);
$_ft_runner->register();
( new FT_Demo_Importer\REST\Job_Controller( $_ft_jobs, $_ft_runner ) )->register();
unset( $_ft_jobs, $_ft_runner );

// -- Resolve adapter once per request and expose it via a filter so any
//    sub-module can fetch the same instance without re-resolving.
add_filter( 'ft_demo_importer_active_adapter', function () {
	static $cached = null;
	if ( null === $cached ) {
		$cached = FT_Demo_Importer\Theme_Detector::active_adapter();
	}
	return $cached;
}, 10, 0 );

// -- Adapter → Studio credentials bridges.
//
// The abstract Theme_Adapter exposes `studio_server_url()` and
// `studio_api_key()` so theme-bundled adapters can ship DEFAULT
// credentials without forcing the user to fill in Settings. Adapter
// values act as fallbacks — they only fill in when the local source
// (wp-config constant > saved option) is empty.
//
// Priority order, highest first:
//   1. wp-config constant   (FT_DEMO_IMPORTER_STUDIO_URL/KEY)
//   2. Saved wp_option      (ft_demo_importer_studio_url/key)
//   3. Adapter override     (e.g. Customify_Adapter::studio_server_url())
//   4. Options_Store default URL / empty key
//
// Without this ordering a non-empty adapter return would silently
// override the wp-config constant, which contradicts the constant's
// documented "lock credentials across environments" purpose.
add_filter( 'ft_demo_importer_default_studio_url', function ( $url ) {
	// wp-config constant wins — never override when it is set.
	if ( defined( 'FT_DEMO_IMPORTER_STUDIO_URL' ) && '' !== trim( (string) FT_DEMO_IMPORTER_STUDIO_URL ) ) {
		return $url;
	}
	// Saved option wins over adapter default. Options_Store has
	// already applied its baked-in DEFAULT_STUDIO_URL fallback before
	// firing this filter, so we can't tell "user saved this" from
	// "fell back to default" by inspecting $url alone — peek at the
	// option directly to disambiguate.
	$saved = (string) get_option( FT_Demo_Importer\Settings\Options_Store::OPT_STUDIO_URL, '' );
	if ( '' !== trim( $saved ) ) {
		return $url;
	}
	$adapter = apply_filters( 'ft_demo_importer_active_adapter', null );
	if ( $adapter && method_exists( $adapter, 'studio_server_url' ) ) {
		$override = $adapter->studio_server_url();
		if ( is_string( $override ) && '' !== trim( $override ) ) {
			return $override;
		}
	}
	return $url;
}, 10, 1 );

add_filter( 'ft_demo_importer_studio_key', function ( $key, $source ) {
	// `$source` is set by Options_Store: 'constant' | 'saved' | 'empty'.
	// Adapter default only fills when nothing else is configured.
	if ( 'empty' !== $source ) {
		return $key;
	}
	$adapter = apply_filters( 'ft_demo_importer_active_adapter', null );
	if ( $adapter && method_exists( $adapter, 'studio_api_key' ) ) {
		$override = $adapter->studio_api_key();
		if ( is_string( $override ) && '' !== trim( $override ) ) {
			return $override;
		}
	}
	return $key;
}, 10, 2 );

/*
 * Plugins-row "Import demo" action link.
 *
 * Destination + label come from the active adapter so each supported
 * theme can deep-link to its own setup screen instead of dumping the
 * user on a generic dashboard. Returning `null` from the adapter's
 * `plugin_row_import_url()` skips the link entirely (theme handles
 * importer surfacing some other way).
 *
 * The Legacy track registers its own equivalent filter in
 * `inc/legacy/bootstrap.php` and is never loaded together with this
 * file (router gate), so no duplicate links.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( DEMO_CONTENT_PATH . 'famethemes-demo-importer.php' ),
	static function ( array $links ): array {
		$adapter = apply_filters( 'ft_demo_importer_active_adapter', null );
		if ( ! $adapter ) {
			return $links;
		}
		$url = $adapter->plugin_row_import_url();
		if ( null === $url || '' === $url ) {
			return $links;
		}
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html( $adapter->plugin_row_import_label() )
		);
		return $links;
	}
);
