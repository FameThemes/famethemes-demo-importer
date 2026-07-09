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
		return 'https://pressmaximum.com/';
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
				// `kind: 'preset'` tags theme-bundled defaults
				// (Sunrise / Midnight) so the wizard can filter them
				// out unless the user actively wants them — keeps the
				// picker focused on the template's bundled palette +
				// the user's saved palettes.
				$transformed = $this->transform_palette( $preset, 'preset' );
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
					$transformed = $this->transform_palette( $entry, 'user' );
					if ( null !== $transformed ) {
						$palettes[] = $transformed;
					}
				}
			}
			$out['palettes'] = $palettes;
		}

		// Typography pairs — FALLBACK only. The wizard prefers the
		// template's own `theme_options.typography` list (curated per demo
		// by the studio); these 6 generic pairs render only when a
		// template predates that field. Families MUST exist in
		// `build/fonts/google-fonts.json` for the import to wire up.
		$out['fonts'] = $this->curated_font_pairs();

		return $out;
	}

	/**
	 * Fallback typography pair list. The wizard prefers the template's
	 * own `theme_options.typography` (shipped per item by the studio's
	 * list endpoint); this set is only used when a template predates
	 * that field. Empty by default — populate via the filter:
	 *
	 *     add_filter( 'ft_demo_importer_curated_font_pairs', function ( $pairs ) {
	 *         $pairs[] = array( 'id' => 'playfair-lora', 'heading' => 'Playfair Display', 'body' => 'Lora', 'weight' => 700 );
	 *         return $pairs;
	 *     } );
	 *
	 * Shape mirrors what the React StyleStep consumes:
	 *
	 *     { id, heading, body, weight }
	 *
	 * Notes for filter authors:
	 *   - `heading` / `body` strings MUST match the family name in
	 *     `themes/customify/build/fonts/google-fonts.json` — the import
	 *     phase writes these verbatim into the typography theme_mods,
	 *     and Customify's font loader looks them up by exact match.
	 *   - `weight` is the visual heading weight; bodies use 400 by
	 *     convention (no need to encode that here).
	 *   - Order = how they render in the grid.
	 *
	 * @return array<int, array{id:string,heading:string,body:string,weight:int}>
	 */
	private function curated_font_pairs(): array {
		/**
		 * Filter the fallback font pair list offered by the wizard's
		 * Style step (and resolved by legacy string-id job payloads).
		 *
		 * @param array<int, array{id:string,heading:string,body:string,weight:int}> $pairs
		 */
		return (array) apply_filters( 'ft_demo_importer_curated_font_pairs', array() );
	}

	/**
	 * Reshape a Customify palette ({id,name,slots:{primary,secondary,...}})
	 * into the wizard's chip format ({id,name,colors:[hex,...]}). Returns
	 * null for malformed entries so the caller can skip them.
	 *
	 * @param mixed  $palette
	 * @param string $kind    `'preset'` for theme-bundled defaults,
	 *                        `'user'` for user-saved / template-bundled
	 *                        palettes. Wizard filters tiles by this
	 *                        field — see PreviewPanel.jsx.
	 * @return array{id:string,name:string,colors:array<int,string>,kind:string}|null
	 */
	private function transform_palette( $palette, string $kind = 'user' ): ?array {
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
			'kind'   => $kind,
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
		// Snapshot the user's pre-import custom palette list ONCE,
		// right before the options layer overwrites the theme_mod with
		// whatever the template ships. The `importing_content` phase
		// is the last point before `applying_options`, so the snapshot
		// taken here captures what the site had before the template
		// stomped on it. Held on the adapter instance — the runner
		// keeps one instance alive across phases of a single job.
		if ( 'importing_content' === $phase ) {
			$this->snapshot_local_custom_palettes();
			return;
		}

		if ( 'applying_options' !== $phase ) {
			return;
		}

		// Merge the template's custom palettes (now in theme_mod, just
		// written by `Options_Importer::apply_theme_mods`) with the
		// snapshot. Runs BEFORE `apply_palette` so a wizard-picked
		// palette id from the template lookup table resolves cleanly
		// via `find_palette()`.
		$this->merge_custom_palettes( $runner, (string) ( $job['id'] ?? '' ) );

		$style = $job['config']['style'] ?? null;
		if ( ! is_array( $style ) ) {
			return;
		}
		$palette_id = isset( $style['palette'] ) && is_string( $style['palette'] ) && '' !== $style['palette']
			? $style['palette']
			: null;
		// `style.font` may arrive in either shape:
		//   - string id (legacy) → adapter resolves via `find_font_pair`
		//     against the curated fallback list
		//   - array {id, heading, body, weight} (current) → wizard ships
		//     the full pair when the user picks from the template's
		//     bundled `theme_options.typography`, since those ids aren't
		//     guaranteed to exist in the curated fallback
		$font_input = $style['font'] ?? null;
		if ( is_string( $font_input ) && '' === $font_input ) {
			$font_input = null;
		} elseif ( is_array( $font_input ) && empty( $font_input ) ) {
			$font_input = null;
		} elseif ( ! is_string( $font_input ) && ! is_array( $font_input ) ) {
			$font_input = null;
		}
		if ( null === $palette_id && null === $font_input ) {
			return;
		}

		if ( null !== $palette_id ) {
			$this->apply_palette( $palette_id, $runner, (string) ( $job['id'] ?? '' ) );
		}
		if ( null !== $font_input ) {
			$this->apply_typography( $font_input, $runner, (string) ( $job['id'] ?? '' ) );
		}
	}

	/**
	 * Snapshot the local `customify_color_palettes` value (= the
	 * user's saved custom palettes) so we can merge it back in after
	 * the template overwrites the theme_mod. Kept as an instance var
	 * because the runner holds a single Customify_Adapter instance
	 * across all phases of a job.
	 *
	 * @var array<int, array<string,mixed>>|null
	 */
	private ?array $pre_import_custom_palettes = null;

	private function snapshot_local_custom_palettes(): void {
		$raw  = get_theme_mod( 'customify_color_palettes', '[]' );
		$list = is_string( $raw ) ? json_decode( wp_unslash( $raw ), true ) : ( is_array( $raw ) ? $raw : array() );
		$this->pre_import_custom_palettes = is_array( $list ) ? $list : array();
	}

	/**
	 * Merge the snapshot (user's pre-import custom palettes) with the
	 * value the template just wrote. Resolution rule: TEMPLATE wins on
	 * id collision — the template's `customify_active_palette` typically
	 * points at one of its own palettes, so the imported colours must
	 * survive verbatim. Local-only palettes (ids not present in the
	 * template) are appended so the user's own work isn't lost.
	 *
	 * The Customizer's palette sanitizer (`customify_color_sanitize_palettes`)
	 * accepts the JSON shape we write here verbatim; running it through
	 * `wp_json_encode` keeps the storage layout identical to a UI save.
	 *
	 * Skip safely when the snapshot wasn't taken (older import path that
	 * doesn't fire `importing_content`) — leaves the template's list in
	 * place rather than risking a partial merge.
	 */
	private function merge_custom_palettes( $runner, string $job_id ): void {
		if ( null === $this->pre_import_custom_palettes ) {
			return;
		}

		$current_raw = get_theme_mod( 'customify_color_palettes', '[]' );
		$current = is_string( $current_raw ) ? json_decode( wp_unslash( $current_raw ), true ) : ( is_array( $current_raw ) ? $current_raw : array() );
		$current = is_array( $current ) ? $current : array();

		// Local first, then template overrides by id. Iterating in this
		// order means the final `array_values` keeps a stable visual
		// ordering: pre-existing local palettes appear first, new
		// template-shipped palettes appended after.
		$by_id = array();
		foreach ( $this->pre_import_custom_palettes as $p ) {
			if ( is_array( $p ) && ! empty( $p['id'] ) && is_string( $p['id'] ) ) {
				$by_id[ $p['id'] ] = $p;
			}
		}
		$template_only = 0;
		foreach ( $current as $p ) {
			if ( is_array( $p ) && ! empty( $p['id'] ) && is_string( $p['id'] ) ) {
				if ( ! isset( $by_id[ $p['id'] ] ) ) {
					$template_only++;
				}
				$by_id[ $p['id'] ] = $p;
			}
		}

		$merged = array_values( $by_id );
		set_theme_mod( 'customify_color_palettes', wp_json_encode( $merged ) );

		$this->log_runner( $runner, $job_id, sprintf(
			'Style: custom palettes merged (local=%d, template=%d, new from template=%d, total=%d).',
			count( $this->pre_import_custom_palettes ),
			count( $current ),
			$template_only,
			count( $merged )
		) );

		// Reset so a re-imported template in the same request gets a
		// fresh snapshot taken from its own `importing_content` phase.
		$this->pre_import_custom_palettes = null;
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
	/**
	 * @param array<string,mixed>|string $font Wizard payload — either the
	 *                                         full pair object (template-shipped
	 *                                         typography) or a legacy id
	 *                                         string (resolved against
	 *                                         {@see curated_font_pairs()}).
	 */
	private function apply_typography( $font, $runner, string $job_id ): void {
		$pair    = null;
		$font_id = '';
		if ( is_array( $font ) ) {
			$heading = isset( $font['heading'] ) && is_string( $font['heading'] ) ? trim( $font['heading'] ) : '';
			$body    = isset( $font['body'] ) && is_string( $font['body'] ) ? trim( $font['body'] ) : '';
			if ( '' !== $heading && '' !== $body ) {
				$font_id = isset( $font['id'] ) && is_string( $font['id'] ) ? $font['id'] : ( $heading . '-' . $body );
				$pair    = array(
					'id'      => $font_id,
					'heading' => $heading,
					'body'    => $body,
					'weight'  => isset( $font['weight'] ) ? (int) $font['weight'] : 600,
				);
			}
		} elseif ( is_string( $font ) && '' !== $font ) {
			$font_id = $font;
			$pair    = $this->find_font_pair( $font_id );
		}
		if ( null === $pair ) {
			$this->log_runner( $runner, $job_id, sprintf( 'Style: font pair "%s" not found, skipped.', $font_id ) );
			return;
		}

		$installer = new Font_Installer();
		$heading_installed = $installer->install( $pair['heading'] );
		$body_installed    = $pair['heading'] === $pair['body']
			? $heading_installed
			: $installer->install( $pair['body'] );

		// Snap the requested weights to ones the family ACTUALLY installed.
		// A curated pair may name a weight (e.g. 700) that a single-weight
		// display face (Aboreto ships only 400) doesn't offer; writing
		// font_weight:700 then leaves the heading with no matching @font-face
		// and it silently falls back to the system font. `$heading_installed`
		// / `$body_installed` are the wp_font_family ids Font_Installer just
		// created (every Google variant installed + activated).
		$heading_weight = $this->snap_weight_to_installed( (string) ( $pair['weight'] ?? 600 ), $heading_installed );
		$body_weight    = $this->snap_weight_to_installed( '400', $body_installed );

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
			'Style: font pair "%s" applied (Library: heading=%s body=%s, mods: %d title + %d body, weights: heading=%s body=%s).',
			$font_id,
			$heading_installed ? 'OK' : 'skip',
			$body_installed ? 'OK' : 'skip',
			count( $title_keys ),
			count( $body_keys ),
			$heading_weight,
			$body_weight
		) );
	}

	/**
	 * Snap a requested font weight to one that was ACTUALLY installed for the
	 * family. Font_Installer installs every Google variant the family offers,
	 * but a curated pair may still name a weight the family doesn't have
	 * (Aboreto ships only 400 on Google, yet a pair may ask 700). Writing
	 * `font_weight:700` then leaves the heading with no matching `@font-face`
	 * and it silently falls back to the system font. Read the installed
	 * `wp_font_face` weights and return the closest so the chosen family keeps
	 * rendering. A variable-font face whose weight is a `min max` range that
	 * spans the request keeps the exact requested weight.
	 *
	 * @param string   $wanted    Requested weight, e.g. "700".
	 * @param int|null $family_id Installed wp_font_family id (Font_Installer return), or null.
	 */
	private function snap_weight_to_installed( string $wanted, ?int $family_id ): string {
		$wanted_n = (int) $wanted;
		if ( null === $family_id || $family_id <= 0 || $wanted_n <= 0 ) {
			return $wanted;
		}
		$face_ids = get_posts( [
			'post_type'      => 'wp_font_face',
			'post_parent'    => $family_id,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		$weights = [];
		foreach ( $face_ids as $face_id ) {
			$face = get_post( $face_id );
			if ( ! $face instanceof \WP_Post ) {
				continue;
			}
			$settings = json_decode( (string) $face->post_content, true );
			if ( ! is_array( $settings ) ) {
				continue;
			}
			// Match against upright weights only — italics don't change weight.
			if ( 'normal' !== (string) ( $settings['fontStyle'] ?? 'normal' ) ) {
				continue;
			}
			$fw = trim( (string) ( $settings['fontWeight'] ?? '' ) );
			if ( preg_match( '/^(\d+)\s+(\d+)$/', $fw, $m ) ) {
				// Variable-font range face — if it spans the request, keep it.
				if ( $wanted_n >= (int) $m[1] && $wanted_n <= (int) $m[2] ) {
					return $wanted;
				}
				$weights[] = (int) $m[1];
				$weights[] = (int) $m[2];
			} elseif ( '' !== $fw && ctype_digit( $fw ) ) {
				$weights[] = (int) $fw;
			}
		}
		if ( empty( $weights ) || in_array( $wanted_n, $weights, true ) ) {
			return $wanted;
		}
		usort( $weights, static function ( $a, $b ) use ( $wanted_n ) {
			return abs( $a - $wanted_n ) <=> abs( $b - $wanted_n );
		} );
		return (string) $weights[0];
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
