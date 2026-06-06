<?php
/**
 * Abstract base for per-theme adapters used by the generic background-job
 * importer track.
 *
 * The OnePress track has its own dedicated code path with hardcoded
 * customizer-key remapping. The generic track does the same job
 * generically by routing to an adapter chosen at runtime from the active
 * theme. The default adapter ({@see FT_Demo_Importer\Adapters\Default_Adapter})
 * is a no-op that relies entirely on what the Studio's `options.json`
 * declares; theme-specific adapters override individual hooks to layer
 * extra behaviour on top.
 *
 * Adapter selection happens in {@see FT_Demo_Importer\Theme_Detector}.
 * Lookup order is exact-match on stylesheet (child theme) → exact-match
 * on template (parent theme) → fall back to Default_Adapter.
 */

namespace FT_Demo_Importer\Adapters;

if ( ! defined( 'ABSPATH' ) ) { exit; }

abstract class Theme_Adapter {

	/**
	 * Theme slugs (template or stylesheet) this adapter is registered for.
	 * Returning multiple slugs covers parent + common child variants.
	 *
	 * @return string[]
	 */
	abstract public function supported_slugs(): array;

	/**
	 * Recommended / required plugins for this theme — same TGM-style shape
	 * the OnePress track uses, BUT consumed by the generic track's
	 * {@see Plugin_Installer} (which calls plugins_api + Plugin_Upgrader
	 * directly, no TGM dependency).
	 *
	 * @return array<int, array{slug:string, name:string, required?:bool}>
	 */
	public function required_plugins(): array {
		return [];
	}

	/**
	 * Override / extend the customizer-keys map that {@see Options_Importer}
	 * uses to remap post/page/media IDs inside theme_mods after import.
	 *
	 * The Studio's `options.json` ships its own `customizer_keys` block —
	 * adapter values are merged on top, so an adapter only needs to declare
	 * fields the Studio export missed.
	 *
	 * Shape mirrors the existing OnePress contract:
	 *   [
	 *     'theme_mod_name' => [ ['type' => 'post', 'key' => 'page_id'], ... ],
	 *     'simple_media_mod' => 'media',
	 *   ]
	 *
	 * @return array<string, mixed>
	 */
	public function customizer_keys(): array {
		return [];
	}

	/**
	 * Run after each major import phase. Phases match {@see Importer_Runner}'s
	 * state machine:
	 *   `fetching` | `installing_plugins` | `extracting` |
	 *   `importing_content` | `applying_options` | `completed`.
	 *
	 * Adapters use this to apply theme-specific tweaks the generic
	 * pipeline doesn't know about (e.g. assigning menu locations to a
	 * specific theme_mod, setting front-page template, seeding default
	 * widget areas). Default: no-op.
	 *
	 * @param string                                  $phase    Phase name (post-transition).
	 * @param array<string, mixed>                    $job      Current job snapshot.
	 * @param \FT_Demo_Importer\Jobs\Importer_Runner $runner   Runner instance — adapter
	 *                                                          can call its log/warn helpers.
	 */
	public function after_phase( string $phase, array $job, $runner ): void {
		// no-op
	}

	/**
	 * Override the Studio base URL for THIS theme. Default `null` means
	 * use whatever the user configured in Settings → Template Importer.
	 *
	 * Useful for vendor-bundled adapters that ship with a known studio
	 * (e.g. a Customify-branded importer pointing at customify.io's
	 * Studio without forcing the user to type the URL).
	 *
	 * Bootstrap registers a callback on `ft_demo_importer_default_studio_url`
	 * that consults this method — return a non-empty string to override,
	 * `null` to leave whatever Settings / wp-config has.
	 *
	 * @return string|null Absolute base URL or null.
	 */
	public function studio_server_url(): ?string {
		return null;
	}

	/**
	 * Override the Studio `read`-scope API key for THIS theme. Default
	 * `null` means use whatever the user configured (or nothing — most
	 * Studio read endpoints are public).
	 *
	 * Useful for vendor-bundled adapters that ship with a Studio
	 * provisioned for them (e.g. a Customify-branded importer where
	 * customify.io has issued a dedicated key for the entire theme
	 * userbase). Bootstrap registers a callback on the
	 * `ft_demo_importer_studio_key` filter that consults this method.
	 *
	 * Return policy:
	 *   - Non-empty string  → adapter's key is used for every request,
	 *                         overriding the saved option (matches how
	 *                         `studio_server_url()` overrides URL).
	 *   - `null` / empty    → fall through to wp-config constant /
	 *                         saved option / empty (= unauthenticated).
	 *
	 * @return string|null
	 */
	public function studio_api_key(): ?string {
		return null;
	}

	/**
	 * Human-readable label for the admin menu / dashboard heading. Override
	 * to brand the importer per theme (e.g. "Customify Sites").
	 */
	public function admin_label(): string {
		return __( 'Starter Templates', 'famethemes-demo-importer' );
	}

	/**
	 * Parent slug the Generic dashboard's sidebar entry should nest under.
	 * Default `null` → Generic_Dashboard falls back to `themes.php`
	 * (Appearance submenu) and the `ft_demo_importer_show_generic_menu`
	 * filter still applies.
	 *
	 * Theme-specific adapters override to attach the dashboard to the
	 * theme's own top-level menu — e.g. Customify uses `'customify'` so
	 * the entry appears under the Customify menu in the sidebar instead
	 * of buried under Appearance.
	 *
	 * @return string|null Parent menu slug, or null for the default.
	 */
	public function dashboard_parent_slug(): ?string {
		return null;
	}

	/**
	 * Where the "Import demo" link on the Plugins → famethemes-demo-importer
	 * row should send the user. Default: the Generic track's dashboard
	 * (`admin.php?page=famethemes-demo-importer`).
	 *
	 * Override when the theme exposes its own demo importer screen so the
	 * Plugins row deep-links there instead — e.g. a Customify adapter that
	 * returns `admin.php?page=customify-getting-started&tab=demo`. Return
	 * `null` to hide the link entirely for this theme.
	 *
	 * @return string|null Absolute admin URL, or null to skip rendering.
	 */
	public function plugin_row_import_url(): ?string {
		return admin_url( 'admin.php?page=famethemes-demo-importer' );
	}

	/**
	 * Label for the same Plugins-row link. Override only when the URL also
	 * goes to a theme-branded screen and "Import demo" reads oddly there.
	 */
	public function plugin_row_import_label(): string {
		return __( 'Import demo', 'famethemes-demo-importer' );
	}

	/**
	 * Admin hook suffix of the host page the importer UI should embed
	 * into (e.g. `'toplevel_page_customify'`). Returning `null` keeps the
	 * importer running only on its own page.
	 *
	 * When this is set, {@see Generic_Dashboard::enqueue_assets()} also
	 * enqueues the React bundle on that host page and flips the
	 * `embedded` flag so the JS entry skips auto-mount and lets the host
	 * call `window.ftDemoImporter.mount(el)` on its own.
	 */
	public function embed_host_hook(): ?string {
		return null;
	}

	/**
	 * Filter name the host page exposes for injecting boot data
	 * (e.g. `'customify_dashboard_localize'`). The adapter hooks this in
	 * its own registration phase to expose `boot[embed_boot_key()]` to
	 * the host's React tree. Returning `null` means no boot bridge.
	 */
	public function embed_boot_filter(): ?string {
		return null;
	}

	/**
	 * Key under the host's boot object where importer metadata is placed
	 * (default `'importer'` → `boot.importer.active`). Adapters override
	 * only when the host already uses that key for something else.
	 */
	public function embed_boot_key(): string {
		return 'importer';
	}

	/**
	 * Extra keys merged into `window.ftDemoImporter` for the React tree
	 * to consume (e.g. theme-provided palettes / font pairs). Default is
	 * empty so unrelated themes don't accidentally publish state.
	 *
	 * Generic_Dashboard::enqueue_assets() merges this AFTER the base
	 * localize so adapters can override only their own surface — the
	 * core fields (`restRoot`, `restNonce`, `embedded`) are written
	 * last to prevent accidental clobbering.
	 *
	 * @return array<string, mixed>
	 */
	public function boot_payload(): array {
		return [];
	}
}
