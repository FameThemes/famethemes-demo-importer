<?php
/**
 * Customify theme adapter.
 *
 * When the active theme is Customify, this adapter takes over from
 * Default_Adapter via `Adapter_Registry::resolve()` and nests the
 * importer's dashboard under Customify's own top-level admin menu
 * (`admin.php?page=customify`) instead of the default Appearance slot,
 * so the entry matches the rest of Customify's UI conventions.
 *
 * Other overrides intentionally left at the Default_Adapter behaviour
 * for now — no required plugins, no customizer-key remap, no studio
 * URL override. Add them here as Customify's import requirements
 * evolve.
 */

namespace FT_Demo_Importer\Adapters;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-theme-adapter.php';

class Customify_Adapter extends Theme_Adapter {

	/**
	 * Customify ships under the single `customify` parent slug. Child
	 * themes (e.g. Customify Pro, Customify Lite variants) typically
	 * keep the same stylesheet root — list them here if a real-world
	 * install surfaces a different slug.
	 */
	public function supported_slugs(): array {
		return [ 'customify' ];
	}

	/**
	 * Nest the Demo Contents entry under Customify's top-level menu so
	 * the sidebar shows:
	 *
	 *   Customify
	 *     ↳ Demo Contents     ← this entry
	 *     ↳ (Customify's other submenus)
	 *
	 * Customify registers its own page via `add_menu_page( ..., 'customify', ... )`,
	 * which is what we hand to WP as the parent_slug.
	 */
	public function dashboard_parent_slug(): ?string {
		return 'customify';
	}

	/**
	 * Plugins.php row deep-link points at the same nested entry so the
	 * UX is consistent regardless of where the user arrives from.
	 */
	public function plugin_row_import_url(): ?string {
		return admin_url( 'admin.php?page=famethemes-demo-importer' );
	}
}
