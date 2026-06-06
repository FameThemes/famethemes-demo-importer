<?php
/**
 * Customify theme adapter.
 *
 * When the active theme is Customify, the importer's React UI embeds
 * into the Customify dashboard (`admin.php?page=customify`) under the
 * `#starter-templates` tab instead of running on its own admin page.
 *
 * The embed mechanics are split between the generic layer and this
 * adapter on purpose so other themes can reuse the same pattern:
 *
 *   - Generic layer (Theme_Adapter + Generic_Dashboard) knows only that
 *     "an adapter may expose `embed_host_hook()`; enqueue there too and
 *     flip `embedded` in the JS boot".
 *
 *   - Customify-specific wiring (which hook to embed into, which boot
 *     filter to inject metadata into, hide-our-own-menu, redirect direct
 *     URL hits) lives ONLY here. To support another theme, write a new
 *     adapter — no generic-layer edits needed.
 *
 * Studio credentials default to the PressMaximum design library so the
 * plugin works out of the box on a Customify install without any
 * settings UI (the plugin no longer ships a settings page).
 */

namespace FT_Demo_Importer\Adapters;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-theme-adapter.php';
require_once __DIR__ . '/customify/class-font-installer.php';

use FT_Demo_Importer\Generic_Dashboard;
use FT_Demo_Importer\Adapters\Customify\Font_Installer;

class Customify_Adapter extends Theme_Adapter {

	/**
	 * Top-level admin page slug the Customify theme registers.
	 * The host page lives at `admin.php?page=customify`.
	 */
	private const HOST_PAGE_SLUG = 'customify';

	/**
	 * Admin hook suffix WP generates for the host page. Used by
	 * Generic_Dashboard::enqueue_assets() to pick up the importer bundle
	 * on the theme dashboard alongside the theme's own bundle.
	 */
	private const HOST_HOOK = 'toplevel_page_customify';

	/**
	 * Filter the Customify dashboard exposes on its boot payload
	 * (`inc/admin/dashboard-v2.php` → `customify_dashboard_localize`).
	 * Hooking it lets us declare `boot.importer.active = true` so the
	 * theme's StarterTemplates.jsx knows to render the mount slot
	 * instead of the install-CTA fallback.
	 */
	private const HOST_BOOT_FILTER = 'customify_dashboard_localize';

	/**
	 * In-page hash route on the Customify dashboard that hosts the
	 * importer. Direct hits on the plugin's own page redirect here.
	 */
	private const HOST_HASH_ROUTE = '#starter-templates';

	public function supported_slugs(): array {
		return [ 'customify' ];
	}

	/**
	 * Nest the (hidden, by `register_integration()`) demo importer entry
	 * under the Customify top-level menu so direct URL hits resolve under
	 * the same parent as the embedded tab — keeps WP's submenu highlight
	 * coherent if the page is ever surfaced.
	 */
	public function dashboard_parent_slug(): ?string {
		return self::HOST_PAGE_SLUG;
	}

	/**
	 * Plugins.php row deep-link sends the user straight to the embedded
	 * tab. Saves a redirect hop relative to the standalone URL.
	 */
	public function plugin_row_import_url(): ?string {
		return admin_url( 'admin.php?page=' . self::HOST_PAGE_SLUG . self::HOST_HASH_ROUTE );
	}

	/**
	 * Default Studio for Customify installs — the PressMaximum design
	 * library. wp-config constants and the (now-removed) settings UI
	 * filter chain still override this when set.
	 */
	public function studio_server_url(): ?string {
		return 'https://design-library.pressmaximum.com/';
	}

	/**
	 * Most Studio read endpoints are public, so the default is empty.
	 * Customify installs that need an authenticated key should set
	 * `FT_DEMO_IMPORTER_STUDIO_KEY` in wp-config (the bootstrap filter
	 * chain already honours it) — keep this method as a hook for future
	 * theme-bound provisioning without forcing a release here.
	 */
	public function studio_api_key(): ?string {
		return null;
	}

	public function embed_host_hook(): ?string {
		// Only claim the embed slot if the Customify dashboard v2 is
		// actually present on this install — older Customify versions
		// without the v2 dashboard fall back to the standalone UI
		// instead of trying to embed into a page that never renders.
		if ( ! function_exists( 'customify_dashboard_v2_boot_data' ) ) {
			return null;
		}
		return self::HOST_HOOK;
	}

	public function embed_boot_filter(): ?string {
		return self::HOST_BOOT_FILTER;
	}

	/**
	 * Wire Customify-specific hooks needed for the embed UX. Called once
	 * from bootstrap.php after the adapter is registered. Split out from
	 * the constructor so adapter instantiation (and registry lookup) has
	 * no side effects.
	 */
	public function register_integration(): void {
		$boot_filter = $this->embed_boot_filter();
		if ( $boot_filter ) {
			add_filter( $boot_filter, [ $this, 'inject_boot_metadata' ], 10, 1 );
		}

		// Hide the standalone submenu — the importer is reachable via
		// the theme dashboard's Starter Templates tab. Direct URL hits
		// are caught by redirect_standalone_to_embed() below.
		add_filter( 'ft_demo_importer_show_generic_menu', '__return_false' );

		add_action( 'admin_init', [ $this, 'redirect_standalone_to_embed' ] );
	}

	/**
	 * Tell the Customify dashboard the importer is installed + active so
	 * the Starter Templates tab renders the embedded UI. Theme reads
	 * `boot.importer.active` in StarterTemplates.jsx.
	 *
	 * @param array<string, mixed> $boot
	 * @return array<string, mixed>
	 */
	public function inject_boot_metadata( array $boot ): array {
		$boot[ $this->embed_boot_key() ] = [
			'active'        => true,
			'version'       => defined( 'DEMO_CONTENT_VERSION' ) ? DEMO_CONTENT_VERSION : '1.3.0',
			'standaloneUrl' => admin_url( 'admin.php?page=' . Generic_Dashboard::PAGE_SLUG ),
		];
		return $boot;
	}

	/**
	 * Redirect direct hits on the plugin's own page to the embedded tab
	 * so there is one canonical surface. The plugin no longer ships a
	 * settings UI, so we don't need to allowlist any sub-tabs.
	 */
	/**
	 * Publish the theme's color palettes to the React tree so the
	 * wizard's "Choose a style" → Color palette grid offers the same
	 * presets + user-saved palettes the Customizer's palette switcher
	 * does. Falls back to the plugin's placeholder palettes if the
	 * theme functions aren't loaded yet (e.g. admin pageviews before
	 * `after_setup_theme`).
	 *
	 * Shape published: `palettes: [ { id, name, colors: [hex, ...] } ]`
	 * — the wizard component consumes a flat hex array per palette;
	 * we order swatches Primary → Secondary → Accent → Text → Surface
	 * → Base so the brand colors dominate the visual chip.
	 *
	 * @return array<string, mixed>
	 */
	public function boot_payload(): array {
		$out = array();

		if ( function_exists( 'customify_color_preset_palettes' ) ) {
			$palettes = array();
			foreach ( customify_color_preset_palettes() as $preset ) {
				$transformed = $this->transform_palette( $preset );
				if ( null !== $transformed ) {
					$palettes[] = $transformed;
				}
			}

			// User palettes live in a single JSON theme_mod (capped at
			// 100 by the sanitizer). decode + iterate; silently skip
			// anything that doesn't shape-match — the sanitizer should
			// have already dropped malformed entries on save, but we
			// don't trust the store blindly.
			$user_raw = get_theme_mod( 'customify_color_palettes', '[]' );
			if ( is_string( $user_raw ) ) {
				$user = json_decode( wp_unslash( $user_raw ), true );
			} else {
				$user = is_array( $user_raw ) ? $user_raw : array();
			}
			if ( is_array( $user ) ) {
				foreach ( $user as $entry ) {
					$transformed = $this->transform_palette( $entry );
					if ( null !== $transformed ) {
						$palettes[] = $transformed;
					}
				}
			}
			$out['palettes'] = $palettes;
		}

		// Typography pairs — Customify Pro doesn't ship curated pairs
		// of its own, so we hand-pick 6 here covering distinct design
		// moods. Every family is present in the theme's Google Fonts
		// catalogue (build/fonts/google-fonts.json) so the import wires
		// to a real font that the frontend can load.
		$out['fonts'] = $this->curated_font_pairs();

		return $out;
	}

	/**
	 * Curated 6-pair typography set surfaced in the importer wizard's
	 * "Choose a style → Typography" grid. Shape mirrors what the React
	 * StyleStep consumes:
	 *
	 *     { id, heading, body, weight }
	 *
	 * Notes for future maintainers:
	 *   - `heading` / `body` strings MUST match the family name in
	 *     `themes/customify/build/fonts/google-fonts.json` — the import
	 *     phase writes these verbatim into the typography theme_mods,
	 *     and Customify's font loader looks them up by exact match.
	 *   - `weight` is the visual heading weight; bodies use 400 by
	 *     convention (no need to encode that here).
	 *   - Order = how they render in the grid. Keep the
	 *     low-risk/familiar pairs first.
	 *
	 * @return array<int, array{id:string,heading:string,body:string,weight:int}>
	 */
	private function curated_font_pairs(): array {
		return array(
			array( 'id' => 'manrope-inter',        'heading' => 'Manrope',           'body' => 'Inter',         'weight' => 700 ),
			array( 'id' => 'playfair-lora',        'heading' => 'Playfair Display',  'body' => 'Lora',          'weight' => 700 ),
			array( 'id' => 'poppins-opensans',     'heading' => 'Poppins',           'body' => 'Open Sans',     'weight' => 700 ),
			array( 'id' => 'raleway-nunito',       'heading' => 'Raleway',           'body' => 'Nunito',        'weight' => 600 ),
			array( 'id' => 'montserrat-lato',      'heading' => 'Montserrat',        'body' => 'Lato',          'weight' => 600 ),
			array( 'id' => 'dmserif-dmsans',       'heading' => 'DM Serif Display',  'body' => 'DM Sans',       'weight' => 400 ),
		);
	}

	/**
	 * Reshape a Customify palette ({id,name,slots:{primary,secondary,...}})
	 * into the wizard's chip format ({id,name,colors:[hex,...]}). Returns
	 * null for malformed entries so the caller can skip them.
	 *
	 * @param mixed $palette
	 * @return array{id:string,name:string,colors:array<int,string>}|null
	 */
	private function transform_palette( $palette ): ?array {
		if ( ! is_array( $palette ) || empty( $palette['id'] ) || empty( $palette['slots'] ) || ! is_array( $palette['slots'] ) ) {
			return null;
		}
		$slots = $palette['slots'];
		$order = array( 'primary', 'secondary', 'accent', 'text', 'surface', 'base' );
		$colors = array();
		foreach ( $order as $key ) {
			if ( ! empty( $slots[ $key ] ) && is_string( $slots[ $key ] ) ) {
				$colors[] = $slots[ $key ];
			}
		}
		if ( empty( $colors ) ) {
			return null;
		}
		return array(
			'id'     => (string) $palette['id'],
			'name'   => isset( $palette['name'] ) && is_string( $palette['name'] )
				? $palette['name']
				: ucfirst( (string) $palette['id'] ),
			'colors' => $colors,
		);
	}

	/**
	 * Apply wizard Style step selections after the import job's
	 * `applying_options` phase finishes.
	 *
	 * Runs AFTER Studio's `options.json` writes template defaults, so
	 * the user's palette + font picks win the cascade. Skipped entirely
	 * when the user kept "Keep current" on both axes (palette / font
	 * stay null in the job payload).
	 *
	 * @param string                                   $phase   Phase just completed.
	 * @param array<string, mixed>                     $job     Job snapshot.
	 * @param \FT_Demo_Importer\Jobs\Importer_Runner  $runner  Runner — used for logging.
	 */
	public function after_phase( string $phase, array $job, $runner ): void {
		if ( 'applying_options' !== $phase ) {
			return;
		}
		$style = $job['config']['style'] ?? null;
		if ( ! is_array( $style ) ) {
			return;
		}
		$palette_id = isset( $style['palette'] ) && is_string( $style['palette'] ) && '' !== $style['palette']
			? $style['palette']
			: null;
		$font_id = isset( $style['font'] ) && is_string( $style['font'] ) && '' !== $style['font']
			? $style['font']
			: null;
		if ( null === $palette_id && null === $font_id ) {
			return;
		}

		if ( null !== $palette_id ) {
			$this->apply_palette( $palette_id, $runner, (string) ( $job['id'] ?? '' ) );
		}
		if ( null !== $font_id ) {
			$this->apply_typography( $font_id, $runner, (string) ( $job['id'] ?? '' ) );
		}
	}

	/**
	 * Write the 6 slot colors + active-palette marker into theme_mod.
	 * Reuses Customify's own slot map so we never invent a new mod key —
	 * critical for backward compatibility on the 30k sites already on
	 * the older slot-only key set.
	 */
	private function apply_palette( string $palette_id, $runner, string $job_id ): void {
		if ( ! function_exists( 'customify_color_palette_slot_map' ) ) {
			$this->log_runner( $runner, $job_id, 'Style: palette skipped — slot map function missing.' );
			return;
		}
		$palette = $this->find_palette( $palette_id );
		if ( null === $palette ) {
			$this->log_runner( $runner, $job_id, sprintf( 'Style: palette "%s" not found, skipped.', $palette_id ) );
			return;
		}
		$slot_map = customify_color_palette_slot_map();
		$applied  = 0;
		foreach ( $slot_map as $slot => $mod_key ) {
			if ( ! empty( $palette['slots'][ $slot ] ) && is_string( $palette['slots'][ $slot ] ) ) {
				set_theme_mod( $mod_key, $palette['slots'][ $slot ] );
				$applied++;
			}
		}
		// Tracks which preset (or user palette) is "active" — palette
		// switcher UI in the Customizer reads this to highlight the row.
		set_theme_mod( 'customify_active_palette', $palette_id );
		$this->log_runner( $runner, $job_id, sprintf( 'Style: palette "%s" applied (%d slots).', $palette_id, $applied ) );
	}

	/**
	 * Install fonts into the WP Font Library, then point every one of
	 * Customify's typography settings at the pair so the user's pick
	 * actually drives the look end-to-end (Site Title, widget titles,
	 * per-heading H1–H6 — not just the generic heading cascade).
	 *
	 * Setting → font slot mapping (see
	 * `inc/customizer/configs/typography.php` for the source of truth):
	 *
	 *   Title font (`$pair['heading']`, weight = `$pair['weight']`):
	 *     - global_typography_base_heading      (H1–H6 generic)
	 *     - global_typography_site_tt_title     (Site Title)
	 *     - global_typography_base_widget_title (Widget titles)
	 *     - global_typography_heading_h1..h6    (Per-heading specific)
	 *
	 *   Body font (`$pair['body']`, weight = 400):
	 *     - global_typography_base_p            (Body & paragraph)
	 *     - global_typography_site_tt_desc      (Tagline)
	 *
	 * `merge_typo_mod()` patches only `font` + `font_weight` — size /
	 * line_height / letter_spacing / text_transform written by the
	 * template's options.json are preserved, so the visual hierarchy
	 * the template ships stays intact.
	 */
	private function apply_typography( string $font_id, $runner, string $job_id ): void {
		$pair = $this->find_font_pair( $font_id );
		if ( null === $pair ) {
			$this->log_runner( $runner, $job_id, sprintf( 'Style: font pair "%s" not found, skipped.', $font_id ) );
			return;
		}

		$installer = new Font_Installer();
		$heading_installed = $installer->install( $pair['heading'] );
		$body_installed    = $pair['heading'] === $pair['body']
			? $heading_installed
			: $installer->install( $pair['body'] );

		$heading_weight = (string) ( $pair['weight'] ?? 600 );
		$body_weight    = '400';

		// Title slots — all 9 Customizer settings that render title-like text.
		$title_keys = [
			'global_typography_base_heading',
			'global_typography_site_tt_title',
			'global_typography_base_widget_title',
			'global_typography_heading_h1',
			'global_typography_heading_h2',
			'global_typography_heading_h3',
			'global_typography_heading_h4',
			'global_typography_heading_h5',
			'global_typography_heading_h6',
		];
		foreach ( $title_keys as $key ) {
			$this->merge_typo_mod( $key, [
				'font'        => $pair['heading'],
				'font_weight' => $heading_weight,
			] );
		}

		// Body slots — paragraph + tagline. Even if Font Library install
		// failed (WP < 6.5 or network) we still write the theme_mods —
		// Customify's font resolver falls back to Google CDN at render
		// time so the chosen look survives.
		$body_keys = [
			'global_typography_base_p',
			'global_typography_site_tt_desc',
		];
		foreach ( $body_keys as $key ) {
			$this->merge_typo_mod( $key, [
				'font'        => $pair['body'],
				'font_weight' => $body_weight,
			] );
		}

		$this->log_runner( $runner, $job_id, sprintf(
			'Style: font pair "%s" applied (Library: heading=%s body=%s, mods: %d title + %d body).',
			$font_id,
			$heading_installed ? 'OK' : 'skip',
			$body_installed ? 'OK' : 'skip',
			count( $title_keys ),
			count( $body_keys )
		) );
	}

	/**
	 * Look up a palette by id across presets + user-saved palettes.
	 * Mirrors what {@see boot_payload()} publishes to the wizard, so
	 * any id the wizard offered will resolve here.
	 */
	private function find_palette( string $id ): ?array {
		if ( function_exists( 'customify_color_preset_palettes' ) ) {
			foreach ( customify_color_preset_palettes() as $p ) {
				if ( ( $p['id'] ?? '' ) === $id ) {
					return is_array( $p ) ? $p : null;
				}
			}
		}
		$raw = get_theme_mod( 'customify_color_palettes', '[]' );
		$list = is_string( $raw ) ? json_decode( wp_unslash( $raw ), true ) : ( is_array( $raw ) ? $raw : [] );
		if ( is_array( $list ) ) {
			foreach ( $list as $p ) {
				if ( is_array( $p ) && ( $p['id'] ?? '' ) === $id ) {
					return $p;
				}
			}
		}
		return null;
	}

	/**
	 * Look up a font pair by id from the curated set we publish.
	 */
	private function find_font_pair( string $id ): ?array {
		foreach ( $this->curated_font_pairs() as $pair ) {
			if ( ( $pair['id'] ?? '' ) === $id ) {
				return $pair;
			}
		}
		return null;
	}

	/**
	 * Merge into an existing typography theme_mod instead of replacing —
	 * the template's `options.json` may have set size / line_height /
	 * letter_spacing we want to preserve. We only override font family
	 * + weight.
	 *
	 * @param array<string,string> $patch
	 */
	private function merge_typo_mod( string $key, array $patch ): void {
		$current = get_theme_mod( $key, [] );
		if ( ! is_array( $current ) ) {
			$current = [];
		}
		set_theme_mod( $key, array_merge( $current, $patch ) );
	}

	/**
	 * Forward a log line via the runner's Job_Store. Importer_Runner
	 * exposes `job_store()` accessor (verified in the source); tests /
	 * mocks may not — fail soft so adapter never crashes the import.
	 */
	private function log_runner( $runner, string $job_id, string $message ): void {
		if ( '' === $job_id || ! $runner ) {
			return;
		}
		if ( method_exists( $runner, 'job_store' ) ) {
			$store = $runner->job_store();
			if ( $store && method_exists( $store, 'log' ) ) {
				$store->log( $job_id, $message );
			}
		}
	}

	public function redirect_standalone_to_embed(): void {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! isset( $_GET['page'] ) ) {
			return;
		}
		if ( Generic_Dashboard::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}
		// Don't redirect REST / AJAX — only top-level page loads.
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		wp_safe_redirect(
			admin_url( 'admin.php?page=' . self::HOST_PAGE_SLUG ) . self::HOST_HASH_ROUTE
		);
		exit;
	}
}
