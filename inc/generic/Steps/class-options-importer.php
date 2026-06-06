<?php
/**
 * Step 5 — apply the settings half of the template (options + theme mods +
 * customizer + widgets + plugin options).
 *
 * Ported from `blocksify-design-importer/includes/Theme/OptionsImporter.php`
 * with one addition: the runner can pass an `adapter_customizer_keys`
 * overlay (via $opts) that gets merged on top of options.json's own
 * customizer_keys block — that's how a theme-specific adapter contributes
 * extra post/page/media remappings without forking this class.
 *
 * Layers (each try/catch — one failure doesn't kill the rest):
 *   1. Checksums (always)        — verify content.json + uploads.zip sha256.
 *   2. Theme requirement (always)— log mismatch, never auto-switch.
 *   3. Core options    (gated)   — show_on_front / page_on_front / page_for_posts.
 *   4. Theme mods       (gated)  — set_theme_mod per key, `_ref` resolved.
 *   5. Customizer       (gated)  — same shape as theme mods.
 *   6. Widgets          (gated)  — sidebars_widgets + per-widget option keys.
 *   7. Plugin options   (gated)  — whitelisted `wp_options` rows.
 *   8. Fonts            (gated)  — `theme.fonts[]` → WP 6.5+ Font Library
 *                                   (wp_font_family + wp_font_face CPTs).
 *
 * Gated layers run only when `opts.replace_settings === true`. On first
 * successful gated run, `ft_demo_importer_settings_applied=1` marker is
 * stamped so subsequent imports can show a "settings already applied —
 * really replace?" confirmation in the wizard.
 *
 * Value-rewrite rules (applied by {@see resolve_refs()} on every leaf):
 *   - `"post:N"` / `"term:N"` strings              → numeric local ID (ref_map lookup)
 *   - `{{ref:(post|term):N(:missing)?}}` markers   → numeric local ID
 *   - `{{SITE_URL}}` placeholder                   → target `home_url()`
 *   - `home_url/wp-content/uploads/sites/\d+/`     → strip multisite prefix
 *   - Array keys ending in `_ref`                  → suffix dropped on output
 *   - Anything else (scalars, nested arrays)        → recurse / verbatim
 *
 * Widget-specific extras (on top of resolve_refs):
 *   - `widget_media_*.attachment_id`               → ref_map then `attachment_url_to_postid()` fallback
 *   - `widget_media_gallery.ids[]`                 → ref_map (per element)
 *   - `widget_nav_menu.nav_menu`                   → ref_map (term ID)
 *   - `widget_block.content` block markup          → wp-image-N + `"id":N` + `"ids":[...]`
 *                                                    rewritten the same way
 *                                                    Content_Importer does
 *                                                    inside post_content.
 *
 * Theme-mod Path B custom keys with raw integer IDs (e.g. `mytheme_logo_id: 42`)
 * are intentionally NOT auto-rewritten — see site-submit.md §`theme.mods rewrite
 * rules`. Themes that need it ship a Theme_Adapter that registers the keys.
 */

namespace FT_Demo_Importer\Steps;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Options_Importer {

	/** Sticky marker — UI checks this to decide whether to show the
	 *  Confirm step on subsequent imports. Set once, never auto-cleared. */
	public const OPTION_SETTINGS_APPLIED = 'ft_demo_importer_settings_applied';

	/**
	 * Optional family-level fields the Font Library schema declares.
	 * Mirrors `WP_REST_Font_Families_Controller`'s registered schema —
	 * carrying them through the import keeps round-trip fidelity with
	 * the source CPT.
	 */
	private const FAMILY_OPTIONAL_FIELDS = array(
		'preview',
	);

	/**
	 * Optional face-level fields the Font Library schema declares.
	 * Mirrors `WP_REST_Font_Faces_Controller`'s registered schema.
	 * Dropping these on import would lose variable-font axes
	 * (`fontVariationSettings`), ligature toggles (`fontFeatureSettings`),
	 * swap behaviour (`fontDisplay`), and ascent/descent overrides
	 * relied on by some themes for vertical rhythm.
	 */
	private const FACE_OPTIONAL_FIELDS = array(
		'fontDisplay',
		'fontStretch',
		'ascentOverride',
		'descentOverride',
		'fontVariant',
		'fontFeatureSettings',
		'fontVariationSettings',
		'lineGapOverride',
		'sizeAdjust',
		'unicodeRange',
		'preview',
	);

	/**
	 * Keys that import flat-out refuses to write — both `wp_options`
	 * rows AND `theme_mod` entries. Currently scoped to:
	 *
	 *   - `site_icon` — Studio's options.json sometimes carries this as
	 *     a plugin_option / theme_mod, but the attachment ref rarely
	 *     resolves cleanly (favicon assets are uploaded by a separate
	 *     wp_handle_upload path the importer doesn't reconstruct), so
	 *     leaving it un-touched is better UX than writing a stale ID
	 *     that paints a broken `<link rel="icon">` on every page.
	 *
	 * The list is consulted by {@see apply_theme_mods()},
	 * {@see apply_customizer()}, and {@see apply_plugin_options()}.
	 * Filterable so themes/adapters can extend it without forking.
	 */
	private const DENIED_KEYS = [
		'site_icon',
	];

	/**
	 * Resolve the denylist, applying the
	 * `ft_demo_importer_denied_option_keys` filter so adapters can
	 * extend it. Cached per-call via the static. Always returns a
	 * lower-cased string array so caller `in_array()` checks don't
	 * miss casing differences.
	 *
	 * @return string[]
	 */
	private function denied_keys(): array {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		/**
		 * Filter the option / theme_mod keys the importer never writes.
		 *
		 * @param string[] $keys Lower-cased option / mod identifiers.
		 */
		$keys = (array) apply_filters( 'ft_demo_importer_denied_option_keys', self::DENIED_KEYS );
		$cached = array_map( 'strtolower', array_filter( $keys, 'is_string' ) );
		return $cached;
	}

	/** Cached target home URL — set once per `apply()` call so resolve_refs
	 *  doesn't keep re-calling `untrailingslashit( home_url() )`. */
	private string $site_url = '';

	/** Cached map of source attachment ID → local attachment ID. Built
	 *  from `ref_map` (filtered to attachment post types). Used by the
	 *  widget-media + block-content rewriters. */
	private array $attachment_pairs = [];

	/** Cached map of source term ID → local term ID. Built from
	 *  `ref_map`'s `term:N` entries. Used by widget-menu / widget-tag-
	 *  cloud / widget-categories handlers that store raw term IDs. */
	private array $term_pairs = [];

	/**
	 * @param string                  $options_json_path
	 * @param array<string,int>       $ref_map  From Content_Importer.
	 * @param array{
	 *     replace_settings?:        bool,
	 *     content_json_path?:       string,
	 *     uploads_zip_path?:        string,
	 *     adapter_customizer_keys?: array,
	 * } $opts
	 *
	 * @return array{applied:array<string,bool>, warnings:string[]}
	 */
	public function apply( string $options_json_path, array $ref_map, array $opts = [] ): array {
		if ( '' === $options_json_path || ! is_readable( $options_json_path ) ) {
			throw new \RuntimeException( 'Could not read options.json' );
		}
		$raw = @file_get_contents( $options_json_path );
		if ( false === $raw ) {
			throw new \RuntimeException( 'Could not read options.json' );
		}
		$parsed = json_decode( $raw, true );
		if ( ! is_array( $parsed ) ) {
			throw new \RuntimeException( 'options.json is not valid JSON.' );
		}

		// Adapter overlay → merge into the parsed customizer_keys block so
		// downstream layers see one unified map.
		$adapter_keys = (array) ( $opts['adapter_customizer_keys'] ?? [] );
		if ( ! empty( $adapter_keys ) ) {
			$existing = isset( $parsed['customizer_keys'] ) && is_array( $parsed['customizer_keys'] )
				? $parsed['customizer_keys']
				: [];
			$parsed['customizer_keys'] = array_replace( $adapter_keys, $existing );
			// options.json wins on conflict — explicit per template trumps
			// adapter's theme-wide default.
		}

		// Cache target home URL + build the attachment + term ref
		// subsets so the value-rewrite helpers don't have to re-derive
		// them per call.
		$this->site_url         = untrailingslashit( home_url() );
		$this->attachment_pairs = $this->build_attachment_pairs( $ref_map );
		$this->term_pairs       = $this->build_term_pairs( $ref_map );

		$applied         = [];
		$warnings        = [];
		$replace_settings = ! empty( $opts['replace_settings'] );

		$this->verify_checksums( $parsed, $opts, $warnings );
		$applied['checksums'] = true;

		$this->check_theme_requirement( $parsed, $warnings );
		$applied['theme_check'] = true;

		if ( $replace_settings ) {
			$applied['core']           = $this->apply_core( $parsed, $ref_map, $warnings );
			$applied['theme_mods']     = $this->apply_theme_mods( $parsed, $ref_map, $warnings );
			$applied['customizer']     = $this->apply_customizer( $parsed, $ref_map, $warnings );
			$applied['widgets']        = $this->apply_widgets( $parsed, $ref_map, $warnings );
			$applied['plugin_options'] = $this->apply_plugin_options( $parsed, $ref_map, $warnings );
			$applied['fonts']          = $this->apply_fonts( $parsed, $ref_map, $warnings );
			update_option( self::OPTION_SETTINGS_APPLIED, 1, true );
		}

		return [ 'applied' => $applied, 'warnings' => $warnings ];
	}

	// ----------------------------------------------------------------------

	private function verify_checksums( array $parsed, array $opts, array &$warnings ): void {
		$checksums = $parsed['_meta']['checksums'] ?? null;
		if ( ! is_array( $checksums ) ) {
			return;
		}
		$pairs = [
			'content.json' => $opts['content_json_path'] ?? null,
			'uploads.zip'  => $opts['uploads_zip_path']  ?? null,
		];
		foreach ( $pairs as $name => $path ) {
			$expected = (string) ( $checksums[ $name ] ?? '' );
			if ( '' === $expected || ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
				continue;
			}
			$expected = preg_replace( '/^sha256:/i', '', $expected );
			$actual   = hash_file( 'sha256', $path );
			if ( $actual !== $expected ) {
				$warnings[] = sprintf(
					/* translators: 1: file name (e.g. content.json), 2: expected sha256 hash, 3: actual sha256 hash */
					__( '%1$s checksum mismatch (expected %2$s, got %3$s).', 'famethemes-demo-importer' ),
					$name,
					$expected,
					$actual
				);
			}
		}
	}

	private function check_theme_requirement( array $parsed, array &$warnings ): void {
		$req = $parsed['requirements']['theme'] ?? null;
		if ( ! is_array( $req ) ) {
			return;
		}
		$wanted = (string) ( $req['stylesheet'] ?? '' );
		if ( '' === $wanted ) {
			return;
		}
		if ( get_stylesheet() !== $wanted ) {
			$warnings[] = sprintf(
				/* translators: 1: stylesheet slug declared by the template, 2: active stylesheet slug */
				__( 'Theme mismatch: source used "%1$s", active is "%2$s". Theme switch is out of scope; mods may not apply cleanly.', 'famethemes-demo-importer' ),
				$wanted,
				get_stylesheet()
			);
		}
	}

	private function apply_core( array $parsed, array $ref_map, array &$warnings ): bool {
		$core = $parsed['core'] ?? [];
		if ( ! is_array( $core ) || empty( $core ) ) {
			return false;
		}
		if ( array_key_exists( 'show_on_front', $core ) ) {
			update_option( 'show_on_front', (string) $core['show_on_front'] );
		}
		if ( ! empty( $core['page_on_front_ref'] ) ) {
			$id = (int) ( $ref_map[ (string) $core['page_on_front_ref'] ] ?? 0 );
			if ( $id > 0 ) {
				update_option( 'page_on_front', $id );
			} else {
				$warnings[] = __( 'core.page_on_front_ref unresolved — left untouched.', 'famethemes-demo-importer' );
			}
		}
		if ( ! empty( $core['page_for_posts_ref'] ) ) {
			$id = (int) ( $ref_map[ (string) $core['page_for_posts_ref'] ] ?? 0 );
			if ( $id > 0 ) {
				update_option( 'page_for_posts', $id );
			} else {
				$warnings[] = __( 'core.page_for_posts_ref unresolved — left untouched.', 'famethemes-demo-importer' );
			}
		}
		return true;
	}

	private function apply_theme_mods( array $parsed, array $ref_map, array &$warnings ): bool {
		$mods = $parsed['theme']['mods'] ?? null;
		if ( ! is_array( $mods ) ) {
			return false;
		}
		$denied = $this->denied_keys();
		foreach ( $mods as $key => $value ) {
			$resolved_key = (string) $key;
			$resolved_val = $this->resolve_refs( $value, $ref_map, $warnings );
			// PHP 7.4-compat suffix check — `str_ends_with()` would be
			// cleaner but is PHP 8.0+, and the plugin baseline is 7.4.
			if ( '_ref' === substr( $resolved_key, -4 ) ) {
				$resolved_key = substr( $resolved_key, 0, -4 );
			}
			if ( in_array( strtolower( $resolved_key ), $denied, true ) ) {
				$warnings[] = sprintf(
					/* translators: %s: the theme_mod key that was skipped (e.g. site_icon) */
					__( 'Skipped denylisted theme_mod: %s', 'famethemes-demo-importer' ),
					$resolved_key
				);
				continue;
			}
			set_theme_mod( $resolved_key, $resolved_val );
		}
		return true;
	}

	private function apply_customizer( array $parsed, array $ref_map, array &$warnings ): bool {
		$cust = $parsed['customizer'] ?? null;
		if ( ! is_array( $cust ) ) {
			return false;
		}
		$denied = $this->denied_keys();
		foreach ( $cust as $key => $value ) {
			$key_str = (string) $key;
			if ( in_array( strtolower( $key_str ), $denied, true ) ) {
				$warnings[] = sprintf(
					/* translators: %s: the customizer key that was skipped (e.g. site_icon) */
					__( 'Skipped denylisted customizer key: %s', 'famethemes-demo-importer' ),
					$key_str
				);
				continue;
			}
			set_theme_mod( $key_str, $this->resolve_refs( $value, $ref_map, $warnings ) );
		}
		return true;
	}

	/**
	 * Restore `wp_options` rows the submitter exported under the prefix-
	 * based whitelist (e.g. `astra-settings`, `astra-color-palettes`,
	 * `sureforms_*`). Without this layer, the site falls back to default
	 * theme settings and looks visually nothing like the source — Astra
	 * stores layout config in `astra-settings`, not theme_mods.
	 *
	 * Defensive: anything prefixed `pmbd_` or `ft_demo_importer_` is
	 * rejected so the importer's own state can't be corrupted by a
	 * malicious manifest.
	 */
	private function apply_plugin_options( array $parsed, array $ref_map, array &$warnings ): bool {
		$plugin_options = $parsed['plugin_options'] ?? null;
		if ( ! is_array( $plugin_options ) || empty( $plugin_options ) ) {
			return false;
		}
		$denied = $this->denied_keys();
		foreach ( $plugin_options as $key => $value ) {
			$option_name = (string) $key;
			if ( '' === $option_name ) {
				continue;
			}
			if ( 0 === strpos( $option_name, 'pmbd_' ) || 0 === strpos( $option_name, 'ft_demo_importer_' ) ) {
				$warnings[] = sprintf(
					/* translators: %s: the option key that starts with an importer-reserved prefix */
					__( 'Refused to import importer-prefixed option: %s', 'famethemes-demo-importer' ),
					$option_name
				);
				continue;
			}
			if ( in_array( strtolower( $option_name ), $denied, true ) ) {
				$warnings[] = sprintf(
					/* translators: %s: the plugin option key that was skipped (e.g. site_icon) */
					__( 'Skipped denylisted plugin_option: %s', 'famethemes-demo-importer' ),
					$option_name
				);
				continue;
			}
			$resolved = $this->resolve_refs( $value, $ref_map, $warnings );
			update_option( $option_name, $resolved, false );
		}
		return true;
	}

	/**
	 * `widgets` layer — saves the sidebar map verbatim and walks every
	 * `widget_*` option key directly (per site-submit.md §`widgets shape +
	 * rewrite`).
	 *
	 * Previous shape was `widgets.options.widget_text` (a nested wrapper)
	 * which never appeared in the wire format — the bug silently dropped
	 * every widget instance. Now matches the doc: `widgets.widget_text`,
	 * `widgets.widget_media_image`, etc. at the top level.
	 */
	private function apply_widgets( array $parsed, array $ref_map, array &$warnings ): bool {
		$widgets = $parsed['widgets'] ?? null;
		if ( ! is_array( $widgets ) ) {
			return false;
		}

		// (a) Sidebars layout — verbatim except the `array_version` marker
		//     which WP's update routine ignores but some plugins choke on
		//     when present without other fields. Pass through as-is; WP
		//     handles its own normalization.
		if ( isset( $widgets['sidebars_widgets'] ) && is_array( $widgets['sidebars_widgets'] ) ) {
			update_option( 'sidebars_widgets', $widgets['sidebars_widgets'] );
		}

		// (b) Per-widget option keys (`widget_*`).
		foreach ( $widgets as $option_name => $option_value ) {
			if ( 'sidebars_widgets' === $option_name ) {
				continue;
			}
			if ( 0 !== strpos( (string) $option_name, 'widget_' ) ) {
				continue;
			}
			if ( ! is_array( $option_value ) ) {
				continue;
			}

			// (b.1) Generic URL + ref rewrite walks every string leaf.
			$resolved = $this->resolve_refs( $option_value, $ref_map, $warnings );

			// (b.2) Widget-specific attachment ID rewriting (media widgets
			//       + Block widget content). Pass `option_name` so the
			//       rewriter knows when to target Block widget content.
			if ( is_array( $resolved ) ) {
				$resolved = $this->rewrite_widget_instances( (string) $option_name, $resolved );
			}

			update_option( (string) $option_name, $resolved );
		}

		return true;
	}

	/**
	 * `theme.fonts[]` layer — registers each exported font family into
	 * WP 6.5+ Font Library (wp_font_family / wp_font_face CPTs) so the
	 * Site Editor's typography picker surfaces them, matching what the
	 * source site had.
	 *
	 * Per the submitter doc §`theme.fonts shape + rewrite`:
	 *   - Pattern submit ships only used fonts; site submit ships every
	 *     active family.
	 *   - Each face's `src[]` is one of four kinds:
	 *       (a) `{{SITE_URL}}/wp-content/uploads/fonts/X.woff2`
	 *           → Font Library upload — file extracted from uploads.zip
	 *             at Step 3 of the runner.
	 *       (b) absolute URL inside `/wp-content/themes/<source>/...`
	 *           → ships with the theme — importer leaves it alone (the
	 *             URL still points at source's host, which is fine if
	 *             the theme is the same and bundled the same files).
	 *       (c) remote URL (`https://fonts.gstatic.com/…`)
	 *           → hotlink — preserve as-is.
	 *       (d) `data:font/woff2;base64,…`
	 *           → embedded — preserve verbatim.
	 *
	 * Idempotency: families are upserted by slug (`post_name`); on
	 * re-import the title + content refresh, existing children are
	 * wiped and faces re-inserted (so re-runs after the source site
	 * dropped a weight don't leave stale faces behind). Font Library
	 * uploads added by the admin on the target site are preserved as
	 * long as their slug doesn't collide with the manifest.
	 *
	 * Activation: after inserting the CPT posts, each family is also
	 * written into the user's `wp_global_styles` post under
	 * `settings.typography.fontFamilies.custom`. Without this step the
	 * Font Library row would render as "0/N active" and the editor
	 * typography picker would never list the family, even though the
	 * CPT posts and files are present on disk. `_wp_font_face_file`
	 * post_meta is also seeded (relative path inside `wp_get_font_dir()`)
	 * so Font Library's GC + Manage UI both work the same as a UI-driven
	 * install. Theme.json + global-styles caches are cleaned once at the
	 * end so the editor reads the updated registry on next request.
	 *
	 * Gated by Font Library availability (`post_type_exists('wp_font_family')`)
	 * — WP < 6.5 has no API for this, so we log a warning and skip.
	 */
	private function apply_fonts( array $parsed, array $ref_map, array &$warnings ): bool {
		$fonts = $parsed['theme']['fonts'] ?? null;
		if ( ! is_array( $fonts ) || empty( $fonts ) ) {
			return false;
		}
		if ( ! post_type_exists( 'wp_font_family' ) || ! post_type_exists( 'wp_font_face' ) ) {
			$warnings[] = sprintf(
				/* translators: 1: current WordPress version (e.g. 6.4.3), 2: number of skipped font families */
				_n(
					'Font Library not available (WP %1$s — requires 6.5+). Skipped %2$d font family.',
					'Font Library not available (WP %1$s — requires 6.5+). Skipped %2$d font families.',
					(int) count( $fonts ),
					'famethemes-demo-importer'
				),
				get_bloginfo( 'version' ),
				(int) count( $fonts )
			);
			return false;
		}

		$upload  = wp_get_upload_dir();
		$basedir = (string) ( $upload['basedir'] ?? '' );

		// Resolve font dir once — used to derive the `_wp_font_face_file`
		// meta value (path relative to wp_get_font_dir()['basedir']).
		// On stock WP 6.5+, this is `wp-content/uploads/fonts/`; on
		// hosts that filter the dir it may sit elsewhere. Either way the
		// meta value should be the segment AFTER that basedir.
		$font_dir = function_exists( 'wp_get_font_dir' ) ? wp_get_font_dir() : array();
		$font_basedir = isset( $font_dir['basedir'] ) ? (string) $font_dir['basedir'] : '';

		$activated_any = false;
		foreach ( $fonts as $font ) {
			if ( ! is_array( $font ) || empty( $font['slug'] ) ) {
				continue;
			}
			$slug = sanitize_title( (string) $font['slug'] );
			if ( '' === $slug ) {
				continue;
			}
			$name        = (string) ( $font['name']       ?? $slug );
			$font_family = (string) ( $font['fontFamily'] ?? $name );

			// Family-level payload — Font Library reads `post_content` as
			// JSON to surface the family in `wp_get_global_settings()`.
			$family_payload = array(
				'slug'       => $slug,
				'name'       => $name,
				'fontFamily' => $font_family,
			);
			foreach ( self::FAMILY_OPTIONAL_FIELDS as $field ) {
				if ( array_key_exists( $field, $font ) ) {
					$family_payload[ $field ] = $font[ $field ];
				}
			}

			$family_id = $this->upsert_font_family( $slug, $name, $family_payload );
			if ( $family_id <= 0 ) {
				$warnings[] = sprintf(
					/* translators: %s: the font family slug (e.g. inter) */
					__( "Font family '%s' could not be saved.", 'famethemes-demo-importer' ),
					$slug
				);
				continue;
			}

			// Wipe + reinsert faces. wp_delete_post(force=true) clears
			// children so the re-imported set is authoritative.
			//
			// IMPORTANT: `_wp_before_delete_font_face` (core pre-delete
			// hook) reads `_wp_font_face_file` meta and unlinks the
			// referenced files. On a re-import, uploads.zip was just
			// extracted at step 3 of the runner — wiping the meta first
			// keeps those files on disk so the freshly inserted face can
			// reuse them. Without this, every re-import would delete the
			// font file then point the new face at a missing path.
			$existing_faces = get_posts( array(
				'post_type'      => 'wp_font_face',
				'post_parent'    => $family_id,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			) );
			foreach ( $existing_faces as $face_id ) {
				delete_post_meta( (int) $face_id, '_wp_font_face_file' );
				wp_delete_post( (int) $face_id, true );
			}

			$installed_faces = array();
			$faces = isset( $font['fontFace'] ) && is_array( $font['fontFace'] ) ? $font['fontFace'] : array();
			foreach ( $faces as $face ) {
				if ( ! is_array( $face ) ) {
					continue;
				}
				$face_payload = $this->normalize_font_face( $face, $font_family, $ref_map, $warnings, $basedir );
				if ( null === $face_payload ) {
					continue;
				}
				$face_id = wp_insert_post( wp_slash( array(
					'post_type'    => 'wp_font_face',
					'post_parent'  => $family_id,
					'post_status'  => 'publish',
					'post_title'   => sprintf(
						'%s %s %s',
						$face_payload['fontFamily'],
						$face_payload['fontStyle']  ?: 'normal',
						$face_payload['fontWeight'] ?: '400'
					),
					'post_content' => wp_json_encode( $face_payload ),
				) ), true );
				if ( is_wp_error( $face_id ) || (int) $face_id <= 0 ) {
					continue;
				}
				$face_id = (int) $face_id;
				update_post_meta( $face_id, '_ft_source_ref', 'font:' . $slug );

				// Seed `_wp_font_face_file` for every src that lands inside
				// the canonical font dir. Without this row, Font Library's
				// pre-delete hook can't GC the file and the Manage UI
				// shows the face as "no file".
				foreach ( $this->derive_font_face_file_basenames( $face_payload['src'], $font_basedir ) as $basename ) {
					add_post_meta( $face_id, '_wp_font_face_file', $basename );
				}

				$installed_faces[] = $face_payload;
			}

			// Activation — write into the user's wp_global_styles post so
			// the family appears as "active" in /wp-admin/site-editor.php
			// → Styles → Typography → Fonts AND in the editor's typography
			// picker. Skipped if no usable face survived normalization
			// (an inactive family with no faces would be misleading UX).
			if ( ! empty( $installed_faces ) ) {
				if ( $this->activate_font_in_global_styles( $family_payload, $installed_faces, $warnings ) ) {
					$activated_any = true;
				}
			}
		}

		// Clear theme.json + global-styles caches once after all writes
		// so the editor reads the new font registry on next request. If
		// no family was actually activated, no read path would change —
		// skip the flush.
		if ( $activated_any ) {
			if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
				wp_clean_theme_json_cache();
			}
			if ( class_exists( '\\WP_Theme_JSON_Resolver' )
				&& method_exists( '\\WP_Theme_JSON_Resolver', 'clean_cached_data' ) ) {
				\WP_Theme_JSON_Resolver::clean_cached_data();
			}
		}

		return true;
	}

	/**
	 * Derive `_wp_font_face_file` meta values for the given resolved
	 * src list. Returns the path relative to `wp_get_font_dir()['basedir']`
	 * (e.g. `inter_400.woff2`) for every src that points at a file on
	 * disk inside that dir. Off-site srcs (Google CDN, data: URIs,
	 * theme-bundled paths) yield nothing — Font Library only tracks
	 * files it owns.
	 *
	 * @param array<int,string> $srcs
	 * @return array<int,string>
	 */
	private function derive_font_face_file_basenames( array $srcs, string $font_basedir ): array {
		if ( '' === $font_basedir ) {
			return array();
		}
		$upload = wp_get_upload_dir();
		$uploads_baseurl = isset( $upload['baseurl'] ) ? (string) $upload['baseurl'] : '';
		$uploads_basedir = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
		if ( '' === $uploads_baseurl || '' === $uploads_basedir ) {
			return array();
		}

		$basenames = array();
		foreach ( $srcs as $src ) {
			if ( ! is_string( $src ) || '' === $src ) {
				continue;
			}
			// Skip data URIs + non-http srcs outright.
			if ( 0 === stripos( $src, 'data:' ) ) {
				continue;
			}

			// Strip scheme so http/https/protocol-relative compare equal.
			$bare_src     = (string) preg_replace( '#^https?:#i', '', $src );
			$bare_baseurl = (string) preg_replace( '#^https?:#i', '', $uploads_baseurl );
			if ( 0 !== strpos( $bare_src, $bare_baseurl ) ) {
				continue;
			}
			$relative_to_uploads = ltrim( substr( $bare_src, strlen( $bare_baseurl ) ), '/' );
			$relative_to_uploads = (string) preg_replace( '/[?#].*$/', '', $relative_to_uploads );
			if ( '' === $relative_to_uploads ) {
				continue;
			}
			$absolute = trailingslashit( $uploads_basedir ) . $relative_to_uploads;

			if ( 0 !== strpos( $absolute, $font_basedir ) ) {
				continue; // Not inside the font dir — Font Library doesn't track it.
			}
			$rel_to_font_dir = ltrim( substr( $absolute, strlen( $font_basedir ) ), '/' );
			if ( '' === $rel_to_font_dir ) {
				continue;
			}
			$basenames[] = $rel_to_font_dir;
		}
		return array_values( array_unique( $basenames ) );
	}

	/**
	 * Write a family into the user's `wp_global_styles` post under
	 * `settings.typography.fontFamilies.custom`. Idempotent: an entry
	 * with a matching slug is replaced; otherwise appended.
	 *
	 * Activation is what flips the Font Library row from "0/N active"
	 * to "N/N active" and what surfaces the family in the editor's
	 * typography picker. Without it, the CPT posts exist but the
	 * editor never sees them.
	 *
	 * @param array<string,mixed>            $family_payload  Family record (slug, name, fontFamily, + optional fields).
	 * @param array<int,array<string,mixed>> $face_settings_list
	 * @return bool True if the global-styles post was written.
	 */
	private function activate_font_in_global_styles( array $family_payload, array $face_settings_list, array &$warnings ): bool {
		$slug = (string) ( $family_payload['slug'] ?? '' );
		if ( '' === $slug ) {
			return false;
		}
		$post = $this->get_user_global_styles_post();
		if ( null === $post ) {
			$warnings[] = sprintf(
				/* translators: %s: the font family slug (e.g. inter) */
				__( "Font '%s' was imported but could not be activated — no user wp_global_styles post.", 'famethemes-demo-importer' ),
				$slug
			);
			return false;
		}

		$content = json_decode( (string) $post->post_content, true );
		if ( ! is_array( $content ) ) {
			$content = array();
		}
		if ( ! isset( $content['version'] ) ) {
			$content['version'] = class_exists( '\\WP_Theme_JSON' ) && defined( '\\WP_Theme_JSON::LATEST_SCHEMA' )
				? \WP_Theme_JSON::LATEST_SCHEMA
				: 3;
		}
		$content['isGlobalStylesUserThemeJSON'] = true;

		if ( ! isset( $content['settings']['typography']['fontFamilies']['custom'] )
			|| ! is_array( $content['settings']['typography']['fontFamilies']['custom'] ) ) {
			$content['settings']['typography']['fontFamilies']['custom'] = array();
		}

		$entry = $family_payload;
		$entry['fontFace'] = array_values( $face_settings_list );

		$found = false;
		foreach ( $content['settings']['typography']['fontFamilies']['custom'] as $i => $existing ) {
			if ( is_array( $existing ) && ( $existing['slug'] ?? '' ) === $slug ) {
				$content['settings']['typography']['fontFamilies']['custom'][ $i ] = $entry;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			$content['settings']['typography']['fontFamilies']['custom'][] = $entry;
		}

		$updated = wp_update_post( array(
			'ID'           => $post->ID,
			'post_content' => wp_slash( wp_json_encode( $content ) ),
		), true );
		if ( is_wp_error( $updated ) || (int) $updated <= 0 ) {
			$warnings[] = sprintf(
				/* translators: %s: the font family slug (e.g. inter) */
				__( "Font '%s' activation write to wp_global_styles failed.", 'famethemes-demo-importer' ),
				$slug
			);
			return false;
		}
		return true;
	}

	/**
	 * Resolve the current user's `wp_global_styles` post for the active
	 * theme. Returns null when the resolver isn't available (no Site
	 * Editor) or no user-level post exists yet (default state on a
	 * fresh install).
	 */
	private function get_user_global_styles_post() {
		if ( ! class_exists( '\\WP_Theme_JSON_Resolver' )
			|| ! method_exists( '\\WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id' ) ) {
			return null;
		}
		$post_id = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( $post_id <= 0 ) {
			return null;
		}
		$post = get_post( $post_id );
		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Idempotent insert/update for `wp_font_family`. Looks up by slug
	 * (`post_name`) — the canonical Font Library identity — so re-imports
	 * refresh content instead of duplicating.
	 *
	 * @param array<string,mixed> $payload  Family JSON (slug, name, fontFamily).
	 * @return int Local post ID, 0 on failure.
	 */
	private function upsert_font_family( string $slug, string $name, array $payload ): int {
		$existing = get_posts( array(
			'post_type'      => 'wp_font_family',
			'name'           => $slug,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		$args = array(
			'post_type'    => 'wp_font_family',
			'post_status'  => 'publish',
			'post_title'   => $name,
			'post_name'    => $slug,
			'post_content' => wp_json_encode( $payload ),
		);

		if ( ! empty( $existing ) ) {
			$args['ID'] = (int) $existing[0];
			$id         = wp_update_post( wp_slash( $args ), true );
		} else {
			$id = wp_insert_post( wp_slash( $args ), true );
		}

		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		$id = (int) $id;
		update_post_meta( $id, '_ft_source_ref', 'font:' . $slug );
		return $id;
	}

	/**
	 * Normalize a face entry from options.json into the shape Font
	 * Library stores in `wp_font_face.post_content`:
	 *
	 *   { fontFamily, fontStyle, fontWeight, src: [absolute URLs] }
	 *
	 * Each `src` is resolved through {@see resolve_refs} (so `{{SITE_URL}}`
	 * + multisite-prefix strip already ran upstream), then checked for
	 * disk presence when it points under our uploads basedir — broken
	 * uploads paths get a warning but stay in the payload (the Editor
	 * shows the family with a missing-file marker instead of dropping it
	 * silently).
	 *
	 * Returns null when no usable src remains AND the face had any src
	 * at all (system-stack families with no faces are emitted by the
	 * caller with no fontFace[], so they never reach this helper).
	 *
	 * @return array<string,mixed>|null
	 */
	private function normalize_font_face( array $face, string $family_fallback, array $ref_map, array &$warnings, string $basedir ) {
		$srcs       = isset( $face['src'] ) ? (array) $face['src'] : array();
		$resolved   = array();
		$site_host  = '' !== $this->site_url ? wp_parse_url( $this->site_url, PHP_URL_HOST ) : '';
		foreach ( $srcs as $src ) {
			if ( ! is_string( $src ) || '' === $src ) {
				continue;
			}
			// Resolve {{SITE_URL}} + multisite strip + any embedded refs
			// (refs unlikely inside font src, but cheap to share the helper).
			$src_resolved = $this->resolve_refs( $src, $ref_map, $warnings );
			if ( ! is_string( $src_resolved ) || '' === $src_resolved ) {
				continue;
			}

			// If the URL points at our uploads dir, verify the file is
			// on disk (extracted from uploads.zip). Missing file → warn
			// but still emit the entry so admins can see the broken
			// reference in Site Editor's typography panel.
			if ( '' !== $site_host && '' !== $basedir ) {
				$src_host = wp_parse_url( $src_resolved, PHP_URL_HOST );
				$path     = wp_parse_url( $src_resolved, PHP_URL_PATH );
				if ( $src_host === $site_host && is_string( $path ) && false !== strpos( $path, '/wp-content/uploads/' ) ) {
					$rel = substr( $path, strpos( $path, '/wp-content/uploads/' ) + strlen( '/wp-content/uploads/' ) );
					if ( false !== $rel && ! file_exists( trailingslashit( $basedir ) . $rel ) ) {
						$warnings[] = sprintf(
							/* translators: %s: relative path inside wp-content/uploads (e.g. 2025/01/inter.woff2) */
							__( 'Font file missing on disk: %s', 'famethemes-demo-importer' ),
							$rel
						);
					}
				}
			}

			$resolved[] = $src_resolved;
		}

		// If the source had `src` entries but ALL of them failed to
		// resolve to a usable string, drop the face entirely — there's
		// no point creating a wp_font_face row with an empty src.
		if ( ! empty( $srcs ) && empty( $resolved ) ) {
			return null;
		}

		$out = array(
			'fontFamily' => (string) ( $face['fontFamily'] ?? $family_fallback ),
			'fontStyle'  => (string) ( $face['fontStyle']  ?? 'normal' ),
			'fontWeight' => (string) ( $face['fontWeight'] ?? '400' ),
			'src'        => $resolved,
		);
		// Carry optional face fields verbatim. Variable-font axes,
		// ligature toggles, font-display, ascent/descent overrides —
		// dropping any of these silently degrades typography fidelity.
		foreach ( self::FACE_OPTIONAL_FIELDS as $field ) {
			if ( array_key_exists( $field, $face ) ) {
				$out[ $field ] = $face[ $field ];
			}
		}
		return $out;
	}

	/**
	 * Per-widget-option attachment / term / block-content rewriter.
	 *
	 * The generic resolve_refs() walk handles URLs + ref strings but NOT
	 * the raw integers that core widgets store under known keys per the
	 * submitter doc §`widgets shape + rewrite`:
	 *
	 *   - `widget_media_image / _video / _audio`.attachment_id  →  attachment ref
	 *   - `widget_media_gallery.ids[]`                          →  attachment ref (per element)
	 *   - `widget_nav_menu.nav_menu`                            →  term ref (menu term_id)
	 *   - `widget_block.content`                                →  block markup
	 *                                                              (wp-image-N + "id":N
	 *                                                               handled like
	 *                                                               post_content)
	 *
	 * Attachment IDs use {@see resolve_attachment_id()} which falls back
	 * to `attachment_url_to_postid()` against the instance's companion
	 * `url` field when the ref_map doesn't have a hit (covers uploads-
	 * only attachments that never got a wp_posts row through content.json).
	 *
	 * Term IDs are ref_map-only — there's no URL equivalent of
	 * `attachment_url_to_postid` for terms, and slug-based fallback risks
	 * matching wrong terms on hosts with overlapping menu slugs.
	 */
	private function rewrite_widget_instances( string $option_name, array $option_value ): array {
		$is_block_widget    = 'widget_block'    === $option_name;
		$is_nav_menu_widget = 'widget_nav_menu' === $option_name;

		foreach ( $option_value as $iid => &$instance ) {
			if ( ! is_array( $instance ) ) {
				continue;
			}

			// `widget_media_image / widget_media_video / widget_media_audio`
			// keep the attachment under `attachment_id` (singular).
			if ( isset( $instance['attachment_id'] ) && is_numeric( $instance['attachment_id'] ) ) {
				$local = $this->resolve_attachment_id(
					(int) $instance['attachment_id'],
					isset( $instance['url'] ) && is_string( $instance['url'] ) ? $instance['url'] : ''
				);
				if ( $local > 0 ) {
					$instance['attachment_id'] = $local;
				}
			}

			// `widget_media_gallery` keeps a list under `ids`. URL fallback
			// isn't applicable per-id, so this path is ref_map-only.
			if ( isset( $instance['ids'] ) && is_array( $instance['ids'] ) ) {
				$instance['ids'] = array_map(
					function ( $src ) {
						if ( ! is_numeric( $src ) ) {
							return $src;
						}
						$src = (int) $src;
						return $this->attachment_pairs[ $src ] ?? $src;
					},
					$instance['ids']
				);
			}

			// `widget_nav_menu` keeps the menu under `nav_menu` (term_id).
			// Without rewriting, the widget renders empty because the
			// source-site term ID points at no menu locally — even when
			// Content_Importer recreated the menu under a different ID.
			if ( $is_nav_menu_widget && isset( $instance['nav_menu'] ) && is_numeric( $instance['nav_menu'] ) ) {
				$src = (int) $instance['nav_menu'];
				if ( isset( $this->term_pairs[ $src ] ) ) {
					$instance['nav_menu'] = $this->term_pairs[ $src ];
				}
			}

			// Block widget content — full block markup string. Rewrite
			// the same `wp-image-N` + `"id":N` + `"ids":[…]` patterns
			// Content_Importer handles inside post_content.
			if ( $is_block_widget && isset( $instance['content'] ) && is_string( $instance['content'] ) ) {
				$instance['content'] = $this->rewrite_block_markup( $instance['content'] );
			}
		}
		unset( $instance );

		return $option_value;
	}

	/**
	 * Resolve a single attachment ID:
	 *   1. Cached ref_map lookup (source ID → local ID, attachment posts only).
	 *   2. URL fallback — `attachment_url_to_postid()` against the widget's
	 *      companion `url` field, after `{{SITE_URL}}` + multisite strip
	 *      have already been applied (the URL passed in here has already
	 *      been resolved by resolve_refs at step (b.1)).
	 *
	 * Returns 0 when neither path resolves — caller leaves the raw value
	 * in place so editors can still see "broken image" UX instead of a
	 * silent zero.
	 */
	private function resolve_attachment_id( int $source_id, string $resolved_url ): int {
		if ( $source_id > 0 && isset( $this->attachment_pairs[ $source_id ] ) ) {
			return (int) $this->attachment_pairs[ $source_id ];
		}
		if ( '' !== $resolved_url ) {
			$by_url = (int) attachment_url_to_postid( $resolved_url );
			if ( $by_url > 0 ) {
				return $by_url;
			}
		}
		return 0;
	}

	/**
	 * Sibling of `Content_Importer::rewrite_refs_and_urls` for block
	 * markup strings stored OUTSIDE post_content (currently only
	 * `widget_block.content`). Applies the three attachment-aware
	 * rewrites that the generic resolve_refs walk doesn't cover:
	 *
	 *   wp-image-{source} → wp-image-{local}
	 *   "id":{source}     → "id":{local}        (gated on attachment refs)
	 *   "ids":[…]         → resolved IDs       (gated likewise)
	 *
	 * Does NOT do `{{ref:post:N}}` or `{{SITE_URL}}` substitution — those
	 * already ran via resolve_refs at the leaf-walk stage.
	 */
	private function rewrite_block_markup( string $value ): string {
		if ( '' === $value || empty( $this->attachment_pairs ) ) {
			return $value;
		}

		// wp-image-{source_id} → wp-image-{new_id}
		foreach ( $this->attachment_pairs as $old_id => $new_id ) {
			$value = (string) preg_replace(
				'/\bwp-image-' . $old_id . '\b/',
				'wp-image-' . $new_id,
				$value
			);
		}

		// "id":{N} — attachment refs only.
		$pairs = $this->attachment_pairs;
		$value = (string) preg_replace_callback(
			'/"id"\s*:\s*(\d+)/',
			static function ( array $m ) use ( $pairs ): string {
				$src = (int) $m[1];
				if ( isset( $pairs[ $src ] ) ) {
					return '"id":' . $pairs[ $src ];
				}
				return $m[0];
			},
			$value
		);

		// "ids":[1,2,3] — gallery block.
		$value = (string) preg_replace_callback(
			'/"ids"\s*:\s*\[([^\]]*)\]/',
			static function ( array $m ) use ( $pairs ): string {
				$rewritten = preg_replace_callback(
					'/\d+/',
					static function ( array $n ) use ( $pairs ): string {
						$src = (int) $n[0];
						return isset( $pairs[ $src ] ) ? (string) $pairs[ $src ] : $n[0];
					},
					$m[1]
				);
				return '"ids":[' . $rewritten . ']';
			},
			$value
		);

		return $value;
	}

	/**
	 * Filter the full ref_map down to entries whose local post type is
	 * `attachment`. The map's keys remain `post:N` and the local
	 * attachment ID becomes the value — same flat shape Content_Importer
	 * builds when it does the wp-image / "id":N rewrite on post_content.
	 *
	 * @param array<string,int> $ref_map
	 * @return array<int,int> source_id => local_id
	 */
	private function build_attachment_pairs( array $ref_map ): array {
		$pairs = [];
		foreach ( $ref_map as $ref => $new_id ) {
			if ( 0 !== strpos( (string) $ref, 'post:' ) ) {
				continue;
			}
			$old_id = (int) substr( (string) $ref, 5 );
			$new_id = (int) $new_id;
			if ( $old_id <= 0 || $new_id <= 0 || $old_id === $new_id ) {
				continue;
			}
			$local = get_post( $new_id );
			if ( ! $local || 'attachment' !== $local->post_type ) {
				continue;
			}
			$pairs[ $old_id ] = $new_id;
		}
		return $pairs;
	}

	/**
	 * Flatten ref_map's `term:N` entries down to source_id → local_id.
	 * Unlike attachment_pairs we don't filter by taxonomy here — the
	 * widget handlers that consume this (widget_nav_menu, etc.) already
	 * have enough context to skip wrong-taxonomy hits at use time, and
	 * narrowing here would require an extra get_term() lookup per ref
	 * for no payoff. Identity pairs (old === new) are dropped so the
	 * widget handler can `?? $src` cheaply.
	 *
	 * @param array<string,int> $ref_map
	 * @return array<int,int> source_id => local_id
	 */
	private function build_term_pairs( array $ref_map ): array {
		$pairs = [];
		foreach ( $ref_map as $ref => $new_id ) {
			if ( 0 !== strpos( (string) $ref, 'term:' ) ) {
				continue;
			}
			$old_id = (int) substr( (string) $ref, 5 );
			$new_id = (int) $new_id;
			if ( $old_id <= 0 || $new_id <= 0 || $old_id === $new_id ) {
				continue;
			}
			$pairs[ $old_id ] = $new_id;
		}
		return $pairs;
	}

	/**
	 * Walk arbitrary values, replacing synthetic refs (`{{ref:post:N}}`
	 * markers + bare `post:N`/`term:N` strings), substituting
	 * `{{SITE_URL}}` with the target home URL, stripping any leftover
	 * multisite `/sites/N/` uploads prefix, and dropping `_ref` suffixes
	 * from array keys.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private function resolve_refs( $value, array $ref_map, array &$warnings ) {
		if ( is_string( $value ) ) {
			// (a) bare `"post:N"` / `"term:N"` — used by submitter's Path A
			//     theme_mods (e.g. `custom_logo_ref: "post:99"`).
			if ( preg_match( '/^post:\d+$/', $value ) || preg_match( '/^term:\d+$/', $value ) ) {
				return (int) ( $ref_map[ $value ] ?? 0 );
			}

			// (b) `{{ref:(post|term):N(:missing)?}}` embedded markers.
			$value = (string) preg_replace_callback(
				'/\{\{ref:(post|term):(\d+)(:missing)?\}\}/',
				static function ( array $m ) use ( $ref_map ): string {
					$ref = "{$m[1]}:{$m[2]}";
					return (string) (int) ( $ref_map[ $ref ] ?? 0 );
				},
				$value
			);

			// (c) `{{SITE_URL}}` → target home URL. Submitter uses this
			//     placeholder for URLs anywhere in theme.mods / widgets /
			//     plugin_options.
			if ( '' !== $this->site_url ) {
				$value = str_replace( '{{SITE_URL}}', $this->site_url, $value );
			}

			// (d) Strip multisite `/wp-content/uploads/sites/N/` prefix
			//     left over from a source that was a multisite child.
			//     Uploads.zip extracts files WITHOUT the prefix, so URLs
			//     carrying it would 404. Restricted to our own home host
			//     so external URLs aren't touched.
			if ( '' !== $this->site_url ) {
				$value = (string) preg_replace(
					'#(' . preg_quote( $this->site_url, '#' ) . '/wp-content/uploads/)sites/\d+/#',
					'$1',
					$value
				);
			}

			return $value;
		}
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $k => $v ) {
				$resolved_key = (string) $k;
				// PHP 7.4-compat suffix check (see apply_theme_mods()).
				if ( '_ref' === substr( $resolved_key, -4 ) && is_string( $v ) ) {
					$resolved_key = substr( $resolved_key, 0, -4 );
				}
				$out[ $resolved_key ] = $this->resolve_refs( $v, $ref_map, $warnings );
			}
			return $out;
		}
		return $value;
	}
}
